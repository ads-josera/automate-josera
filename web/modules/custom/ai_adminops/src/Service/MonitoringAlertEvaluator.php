<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Converts read-only monitoring results into deduplicated operational events.
 *
 * A problem must persist for alerts.consecutive_readings readings in a row
 * before it opens an event, so a one-off spike (the nightly backups push the
 * load past 4 for a single reading) does not wake anybody. An event closes
 * on the first reading back to normal.
 */
final class MonitoringAlertEvaluator {

  /**
   * Creates a MonitoringAlertEvaluator instance.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AdminOpsEventManager $eventManager,
    private readonly AdminOpsExecutionAudit $executionAudit,
    private readonly StateInterface $state,
  ) {}

  /**
   * Evaluates one successful monitoring result against its configured rule.
   *
   * @param array<string, mixed> $result
   *   Normalized output from the read-only SSH connector.
   */
  public function evaluate(string $server_id, string $tool_id, array $result): void {
    $rules = $this->rules();
    if (!(bool) $rules['enabled'] || ($result['status'] ?? '') !== 'ok') {
      return;
    }
    if ($tool_id === 'get_ssl_status') {
      $this->evaluateCertificates($server_id, $result, (int) $rules['ssl_days_warning']);
      return;
    }

    $definition = match ($tool_id) {
      'get_server_load' => [
        'event_type' => 'server_load_high',
        'rule' => 'load_1m_warning',
        'keys' => ['load_1m', 'load'],
        'unit' => '',
        'label' => 'Carga a 1 minuto',
      ],
      'get_cpu_usage' => [
        'event_type' => 'cpu_usage_high',
        'rule' => 'cpu_percent_warning',
        'keys' => ['cpu_percent', 'usage_percent', 'used_percent'],
        'unit' => '%',
        'label' => 'Uso de CPU',
      ],
      'get_memory_usage' => [
        'event_type' => 'memory_usage_high',
        'rule' => 'memory_percent_warning',
        'keys' => ['memory_percent', 'usage_percent', 'used_percent'],
        'unit' => '%',
        'label' => 'Uso de memoria',
      ],
      'get_disk_usage' => [
        'event_type' => 'disk_usage_high',
        'rule' => 'disk_percent_warning',
        'keys' => ['used_percent', 'disk_percent', 'usage_percent'],
        'unit' => '%',
        'label' => 'Uso de disco',
      ],
      'get_exim_queue' => [
        'event_type' => 'exim_queue_high',
        'rule' => 'exim_queue_warning',
        'keys' => ['queue_count', 'queue_size', 'count'],
        'unit' => ' mensajes',
        'label' => 'Cola de Exim',
      ],
      default => NULL,
    };

    if ($definition === NULL) {
      return;
    }
    $definition['value'] = $this->number($result, $definition['keys']);
    if ($definition['value'] === NULL) {
      return;
    }

    $threshold = (float) $rules[$definition['rule']];
    if ($threshold <= 0) {
      return;
    }

    if ($definition['value'] >= $threshold) {
      if (!$this->isSustained($server_id, $tool_id, $definition['keys'], $threshold, (int) $rules['consecutive_readings'])) {
        // Above the threshold, but not for long enough yet. An event that is
        // already open stays open: the problem has not gone away.
        return;
      }
      $value = $this->format($definition['value']);
      $threshold_text = $this->format($threshold);
      $this->eventManager->record(
        $server_id,
        $definition['event_type'],
        'warning',
        $definition['label'] . ' alta: ' . $value . $definition['unit'] . ' (umbral ' . $threshold_text . $definition['unit'] . ').',
        'La medicion supero el umbral configurado. Revise el servidor antes de que afecte a los servicios alojados.',
        [
          'tool' => $tool_id,
          'value' => $definition['value'],
          'threshold' => $threshold,
        ],
      );
      return;
    }

    $this->eventManager->resolveCondition($server_id, $definition['event_type']);
  }

  /**
   * One alert per domain whose certificate is about to expire.
   *
   * AutoSSL renews certificates on its own about 30 days before they
   * expire; one still under 20 days means the renewal is failing (added on
   * 2026-10-14, when the shortest certificate had 32 days). A certificate
   * days away does not change from one reading to the next, so one reading
   * is enough. A certificate that could not be read, or that belongs to
   * another name, neither opens nor closes its domain's alert.
   *
   * @param array<string, mixed> $result
   *   Reading of get_ssl_status.
   */
  private function evaluateCertificates(string $server_id, array $result, int $threshold): void {
    if ($threshold <= 0) {
      return;
    }

    $expiring = [];
    $unknown = [];
    foreach (is_array($result['certificates'] ?? NULL) ? $result['certificates'] : [] as $certificate) {
      $domain = mb_strtolower(trim((string) ($certificate['domain'] ?? '')));
      if ($domain === '') {
        continue;
      }
      if (!is_numeric($certificate['days_left'] ?? NULL) || empty($certificate['matches_domain'])) {
        $unknown[$domain] = TRUE;
        continue;
      }
      $days = (int) $certificate['days_left'];
      if ($days >= $threshold) {
        continue;
      }
      $expiring[$domain] = TRUE;
      $when = $days <= 0 ? 'ya venció' : sprintf('vence en %d %s', $days, $days === 1 ? 'día' : 'días');
      $this->eventManager->record(
        $server_id,
        'ssl_expiring',
        $days <= 7 ? 'critical' : 'warning',
        $domain . ': el certificado SSL ' . $when . '.',
        sprintf('El certificado SSL de %s %s (aviso con menos de %d días). AutoSSL debió renovarlo: revisa en WHM → Manage AutoSSL por qué no lo hizo.', $domain, $when, $threshold),
        [
          'domain' => $domain,
          'problem' => 'Certificado SSL por vencer',
          'detail' => sprintf('El certificado %s; AutoSSL no lo ha renovado. Revisa WHM → Manage AutoSSL.', $when),
          'value' => $days,
          'threshold' => $threshold,
        ],
        NULL,
        $domain,
      );
    }

    foreach ($this->eventManager->openEvents($server_id, 'ssl_expiring') as $event) {
      $evidence = json_decode((string) $event->get('evidence_json')->value, TRUE);
      $domain = is_array($evidence) ? (string) ($evidence['domain'] ?? '') : '';
      if (!isset($expiring[$domain]) && !isset($unknown[$domain])) {
        $this->eventManager->resolveCondition($server_id, 'ssl_expiring', $domain);
      }
    }
  }

  /**
   * Records a critical event when the server cannot be reached by SSH.
   */
  public function recordServerUnreachable(string $server_id): void {
    $rules = $this->rules();
    if (!(bool) $rules['enabled'] || !(bool) $rules['unreachable_enabled']) {
      return;
    }

    // One dropped connection is not an outage: count jobs in a row.
    $streak_key = 'ai_adminops.monitoring.unreachable_streak.' . $server_id;
    $streak = (int) $this->state->get($streak_key, 0) + 1;
    $this->state->set($streak_key, $streak);
    if ($streak < (int) $rules['consecutive_readings']) {
      return;
    }

    $this->eventManager->record(
      $server_id,
      'server_unreachable',
      'critical',
      'Servidor sin respuesta por SSH.',
      'No fue posible completar una lectura de monitoreo con la cuenta observadora. Revise conectividad, huella SSH, llave y wrapper remoto.',
    );
  }

  /**
   * Resolves the reachability condition after a successful SSH response.
   */
  public function recordServerReachable(string $server_id): void {
    $this->state->delete('ai_adminops.monitoring.unreachable_streak.' . $server_id);
    $this->eventManager->resolveCondition($server_id, 'server_unreachable');
  }

  /**
   * Returns normalized rules with safe defaults for existing installations.
   *
   * @return array<string, int|float|bool>
   *   Alert rule values.
   */
  private function rules(): array {
    $configured = $this->configFactory->get('ai_adminops.settings')->get('alerts');
    $configured = is_array($configured) ? $configured : [];

    return [
      'enabled' => (bool) ($configured['enabled'] ?? TRUE),
      'unreachable_enabled' => (bool) ($configured['unreachable_enabled'] ?? TRUE),
      'load_1m_warning' => (float) ($configured['load_1m_warning'] ?? 4),
      'cpu_percent_warning' => (float) ($configured['cpu_percent_warning'] ?? 90),
      'memory_percent_warning' => (float) ($configured['memory_percent_warning'] ?? 90),
      'disk_percent_warning' => (float) ($configured['disk_percent_warning'] ?? 85),
      'exim_queue_warning' => (float) ($configured['exim_queue_warning'] ?? 100),
      'consecutive_readings' => max(1, (int) ($configured['consecutive_readings'] ?? 2)),
      'ssl_days_warning' => (int) ($configured['ssl_days_warning'] ?? 20),
    ];
  }

  /**
   * Checks that the last N readings of a check all stayed at the threshold.
   *
   * The current reading is already in the audit log when this runs, so it is
   * one of the N. A reading that failed or carried no data breaks the run:
   * nothing is known about that moment.
   *
   * @param string[] $keys
   *   Result keys that may hold the measured value.
   */
  private function isSustained(string $server_id, string $tool_id, array $keys, float $threshold, int $required): bool {
    if ($required <= 1) {
      return TRUE;
    }

    $readings = $this->executionAudit->recentReadings($server_id, $tool_id, $required);
    if (count($readings) < $required) {
      return FALSE;
    }
    foreach ($readings as $reading) {
      $value = $reading['status'] === 'succeeded' ? $this->number($reading['result'], $keys) : NULL;
      if ($value === NULL || $value < $threshold) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Returns the first numeric value from a list of supported collector keys.
   *
   * @param string[] $keys
   *   Expected keys in collector output.
   */
  private function number(array $result, array $keys): ?float {
    foreach ($keys as $key) {
      if (isset($result[$key]) && is_numeric($result[$key])) {
        return (float) $result[$key];
      }
    }
    return NULL;
  }

  /**
   * Formats a metric value without unnecessary decimal places.
   */
  private function format(float $value): string {
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
  }

}
