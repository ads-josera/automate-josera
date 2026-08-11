<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Records scheduled monitoring work without enabling server connectors.
 */
final class MonitoringJobProcessor {

  /**
   * Creates a MonitoringJobProcessor instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly ReadOnlySshConnector $sshConnector,
    private readonly AdminOpsExecutionAudit $executionAudit,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Processes a single declarative monitoring job.
   *
   * @param array<string, mixed> $item
   *   Queue payload created by MonitoringScheduler.
   */
  public function process(array $item): void {
    $server_id = trim((string) ($item['server_id'] ?? ''));
    $server = $server_id === '' ? NULL : $this->entityTypeManager->getStorage('ai_adminops_server')->load($server_id);
    if ($server === NULL || !(bool) $server->get('active')) {
      $this->logger->warning('AdminOps monitoring job skipped because server @server is unavailable or inactive.', ['@server' => $server_id]);
      return;
    }

    $declared_tools = is_array($item['tools'] ?? NULL) ? $item['tools'] : [];
    $tools = array_values(array_filter($declared_tools, 'is_string'));
    if (!$this->sshConnector->isConfigured($server)) {
      $result = [
        'status' => 'connection_not_configured',
        'scheduled_at' => (int) ($item['scheduled_at'] ?? 0),
        'processed_at' => $this->time->getRequestTime(),
        'tools' => $tools,
      ];
      $this->state->set('ai_adminops.monitoring.server.' . $server_id, $result);
      $this->logger->info('AdminOps monitoring job for server @server skipped because its secure SSH profile is not configured.', ['@server' => $server_id]);
      return;
    }

    $checks = [];
    foreach ($tools as $tool_id) {
      $execution = $this->executionAudit->begin($server_id, $tool_id, $this->toolLabel($tool_id), ['source' => 'scheduled_monitoring']);
      $this->executionAudit->markRunning((int) $execution->id());
      try {
        $checks[$tool_id] = $this->sshConnector->collect($server, $tool_id);
        $this->executionAudit->markSucceeded((int) $execution->id(), $checks[$tool_id]);
      }
      catch (\Throwable $exception) {
        $checks[$tool_id] = ['status' => 'failed'];
        $this->executionAudit->markFailed((int) $execution->id(), ['status' => 'failed']);
        $this->logger->warning('AdminOps read-only monitoring check @tool failed for server @server.', [
          '@tool' => $tool_id,
          '@server' => $server_id,
        ]);
      }
    }

    $result = [
      'status' => 'completed',
      'scheduled_at' => (int) ($item['scheduled_at'] ?? 0),
      'processed_at' => $this->time->getRequestTime(),
      'checks' => $checks,
    ];
    $this->state->set('ai_adminops.monitoring.server.' . $server_id, $result);
    $this->logger->info('AdminOps monitoring job completed for server @server.', ['@server' => $server_id]);
  }

  /**
   * Gives scheduled audit records a readable tool name.
   */
  private function toolLabel(string $tool_id): string {
    return match ($tool_id) {
      'get_server_load' => 'Get server load',
      'get_cpu_usage' => 'Get CPU usage',
      'get_memory_usage' => 'Get memory usage',
      'get_disk_usage' => 'Get disk usage',
      'get_exim_queue' => 'Get Exim queue',
      'get_ssl_status' => 'Get SSL status',
      default => 'Read-only monitoring check',
    };
  }

}
