<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\QueueWorker;

use Drupal\ai_adminops\Service\MonitoringJobProcessor;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes scheduled, read-only AdminOps monitoring jobs.
 */
#[QueueWorker(
  id: 'ai_adminops_monitoring',
  title: new TranslatableMarkup('AI AdminOps monitoring'),
  cron: ['time' => 45],
)]
final class MonitoringQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Creates a MonitoringQueueWorker instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly MonitoringJobProcessor $jobProcessor,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_adminops.monitoring_job_processor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->jobProcessor->process(is_array($data) ? $data : []);
  }

}
