<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Webhook;

use Drupal\Core\Queue\QueueFactory;

/**
 * Enqueues incoming webhook messages for asynchronous processing.
 */
final class WebhookQueueService {

  public const QUEUE_NAME = 'ai_whatsapp_automation_webhook';

  /**
   * Constructs a WebhookQueueService object.
   */
  public function __construct(
    private readonly QueueFactory $queueFactory,
  ) {
  }

  /**
   * Enqueues a normalized webhook message.
   *
   * @param array<string, mixed> $message
   *   The normalized message payload.
   */
  public function enqueue(string $provider, array $message): void {
    $this->queueFactory
      ->get(self::QUEUE_NAME)
      ->createItem([
        'provider' => $provider,
        'message' => $message,
        'attempts' => 0,
        'created' => time(),
      ]);
  }

}
