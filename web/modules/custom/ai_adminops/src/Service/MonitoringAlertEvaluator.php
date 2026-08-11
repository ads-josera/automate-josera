<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Converts read-only monitoring results into deduplicated operational events.
 */
final class MonitoringAlertEvaluator {

  /**
   * Creates a MonitoringAlertEvaluator instance.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AdminOpsEventManager $eventManager,
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

    $definition = match ($tool_id) {
      'get_server_load' => [
        'event_type' => 'server_load_high',
        'rule' => 'load_1m_warning',
        'value' => $this->number($result, ['load_1m', 'load']),
        'unit' => '',
        'label' => 'Carga a 1 minuto',
      ],
      'get_cpu_usage' => [
        'event_type' => 'cpu_usage_high',
        'rule' => 'cpu_percent_warning',
        'value' => $this->number($result, ['cpu_percent', 'usage_percent', 'used_percent']),
        'unit' => '%',
        'label' => 'Uso de CPU',
      ],
      'get_memory_usage' => [
        'event_type' => 'memory_usage_high',
        'rule' => 'memory_percent_warning',
        'value' => $this->number($result, ['memory_percent', 'usage_percent', 'used_percent']),
        'unit' => '%',
        'label' => 'Uso de memoria',
      ],
      'get_disk_usage' => [
        'event_type' => 'disk_usage_high',
        'rule' => 'disk_percent_warning',
        'value' => $this->number($result, ['used_percent', 'disk_percent', 'usage_percent']),
        'unit' => '%',
        'label' => 'Uso de disco',
      ],
      'get_exim_queue' => [
        'event_type' => 'exim_queue_high',
        'rule' => 'exim_queue_warning',
        'value' => $this->number($result, ['queue_count', 'queue_size', 'count']),
        'unit' => ' mensajes',
        'label' => 'Cola de Exim',
      ],
      default => NULL,
    };

    if ($definition === NULL || $definition['value'] === NULL) {
      return;
    }

    $threshold = (float) $rules[$definition['rule']];
    if ($threshold <= 0) {
      return;
    }

    if ($definition['value'] >= $threshold) {
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
   * Records a critical event when the server cannot be reached by SSH.
   */
  public function recordServerUnreachable(string $server_id): void {
    if (!(bool) $this->rules()['enabled'] || !(bool) $this->rules()['unreachable_enabled']) {
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
    ];
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
