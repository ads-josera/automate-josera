<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Plugin\QueueWorker;

use Drupal\ai_whatsapp_automation\Application\Webhook\WebhookProcessorService;
use Drupal\ai_whatsapp_automation\Application\Webhook\WebhookQueueService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes incoming WhatsApp webhook messages asynchronously.
 */
#[QueueWorker(
  id: WebhookQueueService::QUEUE_NAME,
  title: new TranslatableMarkup('AI WhatsApp webhook processor'),
  cron: ['time' => 30]
)]
final class WebhookQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum processing attempts before allowing the item to fail.
   */
  private const MAX_ATTEMPTS = 3;

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a WebhookQueueWorker object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly WebhookProcessorService $processor,
    private readonly QueueFactory $queueFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_whatsapp_automation.webhook_processor'),
      $container->get('queue'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $item = is_array($data) ? $data : [];
    $attempts = (int) ($item['attempts'] ?? 0);

    try {
      $this->processor->process($item);
    }
    catch (\Throwable $exception) {
      if ($attempts + 1 < self::MAX_ATTEMPTS) {
        $item['attempts'] = $attempts + 1;
        $item['last_error'] = $exception->getMessage();
        $this->queueFactory->get(WebhookQueueService::QUEUE_NAME)->createItem($item);
        $this->logger->warning('Webhook queue item requeued after failure: @message', [
          '@message' => $exception->getMessage(),
        ]);
        return;
      }

      $this->logger->error('Webhook queue item failed after @attempts attempts: @message', [
        '@attempts' => (string) self::MAX_ATTEMPTS,
        '@message' => $exception->getMessage(),
      ]);

      throw $exception;
    }
  }

}
