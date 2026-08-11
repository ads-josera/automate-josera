<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Schedules non-invasive monitoring jobs for configured servers.
 */
final class MonitoringScheduler {

  private const QUEUE_NAME = 'ai_adminops_monitoring';

  /**
   * Read-only tool IDs planned for each monitoring pass.
   *
   * These are declarative until a future connector phase enables collection.
   */
  private const MONITORING_TOOLS = [
    'get_server_load',
    'get_cpu_usage',
    'get_memory_usage',
    'get_disk_usage',
    'get_exim_queue',
    'get_ssl_status',
  ];

  /**
   * Creates a MonitoringScheduler instance.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly QueueFactory $queueFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Queues work for every active server when the configured interval is due.
   *
   * @return array{queued: int, status: string}
   *   Scheduling result for logs, tests, and future dashboard use.
   */
  public function queueDueChecks(bool $force = FALSE): array {
    $config = $this->configFactory->get('ai_adminops.settings');
    if (!(bool) $config->get('monitoring.enabled')) {
      return ['queued' => 0, 'status' => 'disabled'];
    }

    $now = $this->time->getRequestTime();
    $interval = max(1, (int) $config->get('monitoring.interval_minutes')) * 60;
    $last_scheduled = (int) $this->state->get('ai_adminops.monitoring.last_scheduled', 0);
    if (!$force && $last_scheduled > 0 && ($last_scheduled + $interval) > $now) {
      return ['queued' => 0, 'status' => 'not_due'];
    }

    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    $queued = 0;
    foreach ($this->entityTypeManager->getStorage('ai_adminops_server')->loadMultiple() as $server) {
      if (!(bool) $server->get('active')) {
        continue;
      }
      $queue->createItem([
        'server_id' => $server->id(),
        'tools' => self::MONITORING_TOOLS,
        'scheduled_at' => $now,
      ]);
      $queued++;
    }

    $this->state->set('ai_adminops.monitoring.last_scheduled', $now);
    $this->logger->notice('AdminOps monitoring scheduled @count server job(s).', ['@count' => (string) $queued]);
    return ['queued' => $queued, 'status' => 'scheduled'];
  }

}
