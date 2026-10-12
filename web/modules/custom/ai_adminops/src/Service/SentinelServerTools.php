<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\ai_whatsapp_automation\Application\AI\Tools\OwnerToolProviderInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * The server tools Sentinel uses: AdminOps data, read only.
 *
 * Who may call them is decided by OwnerToolRunner in ai_whatsapp_automation
 * (owner bot, owner number, owner tools switched on). Nothing here changes a
 * server: four tools read what AdminOps already stored, and "leer_ahora"
 * runs the eight fixed read-only checks, each recorded in the AdminOps log.
 * A live reading does not count towards alerts: those follow the schedule,
 * so asking Sentinel twice cannot open or close an event.
 */
final class SentinelServerTools implements OwnerToolProviderInterface {

  /**
   * The eight fixed checks, with their labels in the AdminOps log.
   */
  private const CHECKS = [
    'get_server_load' => 'Get server load',
    'get_cpu_usage' => 'Get CPU usage',
    'get_memory_usage' => 'Get memory usage',
    'get_disk_usage' => 'Get disk usage',
    'get_exim_queue' => 'Get Exim queue',
    'get_ssl_status' => 'Get SSL status',
    'get_reboot_status' => 'Get reboot status',
    'get_last_action' => 'Get last action',
  ];

  /**
   * Seconds between two live readings of the same server.
   */
  private const LIVE_READ_INTERVAL = 60;

  /**
   * Most readings "historial" returns.
   */
  private const MAX_HISTORY = 12;

  /**
   * Creates a SentinelServerTools instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly MonitoringCollectorInterface $collector,
    private readonly AdminOpsExecutionAudit $executionAudit,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function definitions(): array {
    // The model can only name servers that exist: they are an enum. OpenAI
    // rejects the whole request for an empty enum, so none without servers
    // (execute() refuses any ID then).
    $server = ['type' => 'string', 'description' => 'ID del servidor, como lo da "servidores".'];
    $server_ids = array_map('strval', array_keys($this->servers()));
    if ($server_ids !== []) {
      $server['enum'] = $server_ids;
    }

    return [
      [
        'name' => 'servidores',
        'description' => 'Lista los servidores que AdminOps vigila, con su estado y la hora de la última lectura. Úsala primero si no sabes de qué servidor se habla.',
        'parameters' => $this->schema([]),
      ],
      [
        'name' => 'estado_servidor',
        'description' => 'Última lectura programada de un servidor (carga, CPU, memoria, disco, cola de correo, SSL, si pide reinicio, resultado de la última acción) y los umbrales de alerta. No se conecta al servidor.',
        'parameters' => $this->schema(['servidor' => $server]),
      ],
      [
        'name' => 'eventos_abiertos',
        'description' => 'Alertas abiertas o reconocidas (aún no resueltas), las más recientes primero.',
        'parameters' => $this->schema(['servidor' => ['description' => 'Opcional: solo las de este servidor.'] + $server], []),
      ],
      [
        'name' => 'historial',
        'description' => 'Lecturas anteriores de una revisión de un servidor, para ver la tendencia.',
        'parameters' => $this->schema([
          'servidor' => $server,
          'revision' => ['type' => 'string', 'enum' => array_keys(self::CHECKS)],
          'lecturas' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_HISTORY],
        ]),
      ],
      [
        'name' => 'leer_ahora',
        'description' => 'Se conecta al servidor y hace las ocho revisiones de solo lectura en este momento. Úsala solo si José pide el dato actual o la última lectura es vieja.',
        'parameters' => $this->schema(['servidor' => $server]),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(string $name, array $arguments, ContentEntityInterface $conversation): array {
    if ($name === 'servidores') {
      return ['servidores' => array_values(array_map($this->describeServer(...), $this->servers()))];
    }
    if ($name === 'eventos_abiertos') {
      $server_id = (string) ($arguments['servidor'] ?? '');
      return ['eventos' => $this->openEvents($server_id === '' ? NULL : $this->server($server_id)->id())];
    }

    $server = $this->server((string) ($arguments['servidor'] ?? ''));

    return match ($name) {
      'estado_servidor' => $this->status($server),
      'historial' => $this->history($server, (string) ($arguments['revision'] ?? ''), (int) ($arguments['lecturas'] ?? 5)),
      'leer_ahora' => $this->readNow($server, $conversation),
      default => throw new \InvalidArgumentException('Unknown tool.'),
    };
  }

  /**
   * Returns the active servers by ID.
   *
   * @return array<string, \Drupal\Core\Config\Entity\ConfigEntityInterface>
   *   Active servers.
   */
  private function servers(): array {
    $servers = $this->entityTypeManager->getStorage('ai_adminops_server')->loadByProperties(['active' => TRUE]);
    ksort($servers);

    return $servers;
  }

  /**
   * Loads one active server, refusing any other ID.
   */
  private function server(string $server_id): ConfigEntityInterface {
    $servers = $this->servers();
    if (!isset($servers[$server_id])) {
      throw new \InvalidArgumentException('Unknown or inactive server.');
    }

    return $servers[$server_id];
  }

  /**
   * Describes a server without anything secret (no credential reference).
   *
   * @return array<string, mixed>
   *   What the model may know.
   */
  private function describeServer(ConfigEntityInterface $server): array {
    $last = $this->lastReading($server);

    return [
      'id' => $server->id(),
      'nombre' => $server->label(),
      'hostname' => (string) $server->get('hostname'),
      'sistema' => (string) $server->get('operating_system'),
      'proveedor' => (string) $server->get('provider'),
      'estado' => (string) $server->get('server_status'),
      'lectura_en_vivo_disponible' => $this->collector->isConfigured($server),
      'ultima_lectura' => $this->when((int) ($last['processed_at'] ?? 0)),
    ];
  }

  /**
   * Returns the last scheduled reading of a server.
   *
   * @return array<string, mixed>
   *   What MonitoringJobProcessor stored.
   */
  private function status(ConfigEntityInterface $server): array {
    $last = $this->lastReading($server);
    $alerts = $this->configFactory->get('ai_adminops.settings')->get('alerts') ?? [];

    return [
      'servidor' => $server->id(),
      'estado' => (string) $server->get('server_status'),
      'leido' => $this->when((int) ($last['processed_at'] ?? 0)),
      'resultado' => (string) ($last['status'] ?? 'sin_lecturas'),
      'revisiones' => $last['checks'] ?? [],
      'umbrales_de_alerta' => array_intersect_key($alerts, array_flip(['load_1m_warning', 'cpu_percent_warning', 'memory_percent_warning', 'disk_percent_warning', 'exim_queue_warning', 'consecutive_readings'])),
      'monitoreo_programado_encendido' => (bool) $this->configFactory->get('ai_adminops.settings')->get('monitoring.enabled'),
    ];
  }

  /**
   * Lists unresolved events, newest first.
   *
   * @return list<array<string, mixed>>
   *   Up to ten events.
   */
  private function openEvents(?string $server_id): array {
    $storage = $this->entityTypeManager->getStorage('ai_adminops_event');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', ['open', 'acknowledged'], 'IN')
      ->sort('occurred_at', 'DESC')
      ->range(0, 10);
    if ($server_id !== NULL) {
      $query->condition('server', $server_id);
    }

    $events = [];
    foreach ($storage->loadMultiple($query->execute()) as $event) {
      $events[] = [
        'servidor' => (string) $event->get('server')->target_id,
        'severidad' => (string) $event->get('severity')->value,
        'tipo' => (string) $event->get('event_type')->value,
        'resumen' => (string) $event->get('summary')->value,
        'estado' => (string) $event->get('status')->value,
        'desde' => $this->when((int) $event->get('occurred_at')->value),
      ];
    }

    return $events;
  }

  /**
   * Returns the latest finished readings of one check, newest first.
   *
   * @return array<string, mixed>
   *   The readings with their time.
   */
  private function history(ConfigEntityInterface $server, string $check, int $count): array {
    if (!isset(self::CHECKS[$check])) {
      throw new \InvalidArgumentException('Unknown check.');
    }

    $storage = $this->entityTypeManager->getStorage('ai_adminops_tool_execution');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('server', $server->id())
      ->condition('tool_id', $check)
      ->condition('status', ['succeeded', 'no_data', 'failed'], 'IN')
      ->sort('id', 'DESC')
      ->range(0, max(1, min(self::MAX_HISTORY, $count)))
      ->execute();

    $readings = [];
    foreach ($storage->loadMultiple($ids) as $execution) {
      $result = json_decode((string) $execution->get('result')->value, TRUE);
      $readings[] = [
        'leido' => $this->when((int) $execution->get('completed_at')->value),
        'estado' => (string) $execution->get('status')->value,
        'resultado' => is_array($result) ? $result : [],
      ];
    }

    return ['servidor' => $server->id(), 'revision' => $check, 'lecturas' => $readings];
  }

  /**
   * Runs the eight checks now, each one in the AdminOps log.
   *
   * @return array<string, mixed>
   *   The readings, or the previous ones when asked again within a minute.
   */
  private function readNow(ConfigEntityInterface $server, ContentEntityInterface $conversation): array {
    if (!$this->collector->isConfigured($server)) {
      return ['servidor' => $server->id(), 'error' => 'Este servidor no tiene lectura en vivo configurada en Drupal.'];
    }

    $key = 'ai_adminops.sentinel.live.' . $server->id();
    $previous = $this->state->get($key);
    $now = $this->time->getRequestTime();
    if (is_array($previous) && $now - (int) ($previous['at'] ?? 0) < self::LIVE_READ_INTERVAL) {
      // One SSH round per minute at most, however often the model asks.
      return $previous['result'] + ['aviso' => 'Lectura de hace menos de un minuto; no se volvió a conectar.'];
    }

    $checks = [];
    foreach (self::CHECKS as $check => $label) {
      $execution = $this->executionAudit->begin((string) $server->id(), $check, $label, [
        'source' => 'sentinel',
        'conversation' => (string) $conversation->id(),
      ]);
      $this->executionAudit->markRunning((int) $execution->id());
      try {
        $checks[$check] = $this->collector->collect($server, $check);
        ($checks[$check]['status'] ?? '') === 'ok'
          ? $this->executionAudit->markSucceeded((int) $execution->id(), $checks[$check])
          : $this->executionAudit->markNoData((int) $execution->id(), $checks[$check]);
      }
      catch (\Throwable) {
        $checks[$check] = ['status' => 'failed'];
        $this->executionAudit->markFailed((int) $execution->id(), ['status' => 'failed']);
      }
    }

    $result = ['servidor' => $server->id(), 'leido' => $this->when($now), 'revisiones' => $checks];
    $this->state->set($key, ['at' => $now, 'result' => $result]);

    return $result;
  }

  /**
   * Returns the last scheduled reading stored for a server.
   *
   * @return array<string, mixed>
   *   The stored reading, or an empty array.
   */
  private function lastReading(ConfigEntityInterface $server): array {
    $last = $this->state->get('ai_adminops.monitoring.server.' . $server->id());

    return is_array($last) ? $last : [];
  }

  /**
   * Gives a timestamp as local time and age, or NULL when there is none.
   *
   * @return array{fecha: string, hace_minutos: int}|null
   *   Site-time date and minutes ago.
   */
  private function when(int $timestamp): ?array {
    if ($timestamp <= 0) {
      return NULL;
    }

    return [
      'fecha' => $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i'),
      'hace_minutos' => intdiv(max(0, $this->time->getRequestTime() - $timestamp), 60),
    ];
  }

  /**
   * Builds a strict JSON schema object.
   *
   * @param array<string, array<string, mixed>> $properties
   *   Property schemas.
   * @param string[]|null $required
   *   Required names; all of them when NULL.
   *
   * @return array<string, mixed>
   *   The schema.
   */
  private function schema(array $properties, ?array $required = NULL): array {
    return [
      'type' => 'object',
      'properties' => $properties === [] ? new \stdClass() : $properties,
      'required' => $required ?? array_keys($properties),
      'additionalProperties' => FALSE,
    ];
  }

}
