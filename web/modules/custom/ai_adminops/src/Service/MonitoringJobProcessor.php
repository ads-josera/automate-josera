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
    $result = [
      'status' => 'pending_connector',
      'scheduled_at' => (int) ($item['scheduled_at'] ?? 0),
      'processed_at' => $this->time->getRequestTime(),
      'tools' => $tools,
    ];
    $this->state->set('ai_adminops.monitoring.server.' . $server_id, $result);
    $this->logger->info('AdminOps monitoring job for server @server is pending a secure connector.', ['@server' => $server_id]);
  }

}
