<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\ai_whatsapp_automation\Application\AI\Tools\OwnerToolProviderInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Sentinel's action tools (phase B): ask for an action, confirm its code.
 *
 * Only offered for servers where actions are configured (an action SSH
 * profile and an approval address in settings.php). Nothing runs without the
 * emailed code: see SentinelActionApproval.
 */
final class SentinelActionTools implements OwnerToolProviderInterface {

  /**
   * Creates a SentinelActionTools instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SentinelActionApproval $approval,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function definitions(): array {
    $servers = array_keys($this->servers());
    if ($servers === []) {
      // Without a configured server Sentinel is not told actions exist.
      return [];
    }

    return [
      [
        'name' => 'solicitar_accion',
        'description' => 'Pide permiso para una acción en un servidor. Le llega a José un código de 6 dígitos a su correo; nada se ejecuta todavía. Úsala solo cuando José pida la acción.',
        'parameters' => [
          'type' => 'object',
          'properties' => [
            'servidor' => ['type' => 'string', 'enum' => array_map('strval', $servers)],
            'accion' => ['type' => 'string', 'enum' => array_keys(ActionExecutorInterface::ACTIONS), 'description' => 'restart_apache: reinicio graceful de Apache. restart_php_fpm: reinicio de PHP-FPM.'],
            'motivo' => ['type' => 'string', 'description' => 'Por qué, en una línea.'],
          ],
          'required' => ['servidor', 'accion', 'motivo'],
          'additionalProperties' => FALSE,
        ],
      ],
      [
        'name' => 'confirmar_accion',
        'description' => 'Ejecuta una acción pedida con solicitar_accion, con el código de 6 dígitos que José escribió en este chat. Nunca inventes ni adivines el código.',
        'parameters' => [
          'type' => 'object',
          'properties' => [
            'solicitud' => ['type' => 'integer', 'description' => 'Número que devolvió solicitar_accion.'],
            'codigo' => ['type' => 'string', 'pattern' => '^[0-9]{6}$'],
          ],
          'required' => ['solicitud', 'codigo'],
          'additionalProperties' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(string $name, array $arguments, ContentEntityInterface $conversation): array {
    if ($name === 'confirmar_accion') {
      return $this->approval->confirm((int) ($arguments['solicitud'] ?? 0), (string) ($arguments['codigo'] ?? ''), $conversation);
    }
    if ($name !== 'solicitar_accion') {
      throw new \InvalidArgumentException('Unknown tool.');
    }

    $server = $this->servers()[(string) ($arguments['servidor'] ?? '')] ?? NULL;
    if ($server === NULL) {
      throw new \InvalidArgumentException('Unknown server, or actions are not configured on it.');
    }

    return $this->approval->request($server, (string) ($arguments['accion'] ?? ''), (string) ($arguments['motivo'] ?? ''), $conversation);
  }

  /**
   * Active servers where actions are available, by ID.
   *
   * @return array<string, \Drupal\Core\Config\Entity\ConfigEntityInterface>
   *   Servers.
   */
  private function servers(): array {
    $servers = $this->entityTypeManager->getStorage('ai_adminops_server')->loadByProperties(['active' => TRUE]);
    ksort($servers);

    return array_filter($servers, $this->approval->isAvailable(...));
  }

}
