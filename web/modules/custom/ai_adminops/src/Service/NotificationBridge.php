<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\ai_whatsapp_automation\Application\Webhook\ProviderMessageSenderService;
use Psr\Log\LoggerInterface;

/**
 * Reserved adapter for AdminOps operational notifications.
 *
 * It will use existing delivery services without changing their behavior.
 */
final class NotificationBridge {

  /**
   * Creates a NotificationBridge instance.
   */
  public function __construct(
    private readonly ProviderMessageSenderService $messageSender,
    private readonly MailManagerInterface $mailManager,
    private readonly LoggerInterface $logger,
  ) {}

}

