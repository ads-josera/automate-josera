<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai_whatsapp_automation\Application\Webhook\ProviderMessageSenderService;
use Psr\Log\LoggerInterface;

/**
 * Delivers AdminOps event alerts through configured existing channels.
 */
final class NotificationBridge {

  /**
   * Creates a NotificationBridge instance.
   */
  public function __construct(
    private readonly ProviderMessageSenderService $messageSender,
    private readonly MailManagerInterface $mailManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Delivers an alert for an eligible operational event.
   *
   * Delivery failures are represented in the returned result. They must not
   * prevent the operational event itself from being stored.
   *
   * @return array{status: string, email: array<int, array<string, mixed>>, whatsapp: array<int, array<string, mixed>>}
   *   Channel-level delivery results.
   */
  public function notifyEvent(ContentEntityInterface $event): array {
    $result = [
      'status' => 'skipped',
      'email' => [],
      'whatsapp' => [],
    ];
    $config = $this->configFactory->get('ai_adminops.settings');
    if (!(bool) $config->get('notifications.enabled')) {
      $result['status'] = 'disabled';
      return $result;
    }

    if (!$this->isEligible($event, (string) $config->get('notifications.minimum_severity'))) {
      $result['status'] = 'not_eligible';
      return $result;
    }

    $cooldown = max(0, (int) $config->get('notifications.cooldown_minutes')) * 60;
    // The fingerprint represents the same operational condition across event
    // records, so it is the right key for suppressing repeated alerts.
    $fingerprint = trim((string) $event->get('fingerprint')->value);
    $state_key = 'ai_adminops.notification.last_sent.' . hash('sha256', $fingerprint ?: (string) $event->id());
    $last_sent = (int) $this->state->get($state_key, 0);
    $now = $this->time->getRequestTime();
    if ($cooldown > 0 && $last_sent > 0 && ($last_sent + $cooldown) > $now) {
      $result['status'] = 'cooldown';
      return $result;
    }

    $message = $this->message($event);
    $result['email'] = $this->sendEmail($config->get('notifications.email') ?: [], $message);
    $result['whatsapp'] = $this->sendWhatsApp($config->get('notifications.whatsapp') ?: [], $message);

    if ($result['email'] !== [] || $result['whatsapp'] !== []) {
      $this->state->set($state_key, $now);
      $result['status'] = 'attempted';
      $this->logger->notice('AdminOps notification attempted for event @event.', ['@event' => (string) $event->id()]);
    }

    return $result;
  }

  /**
   * Determines whether an event should generate an alert.
   */
  private function isEligible(ContentEntityInterface $event, string $minimum_severity): bool {
    if ((string) $event->get('status')->value !== 'open') {
      return FALSE;
    }

    $levels = ['info' => 0, 'warning' => 1, 'critical' => 2];
    $event_level = $levels[(string) $event->get('severity')->value] ?? -1;
    $minimum_level = $levels[$minimum_severity] ?? $levels['warning'];
    return $event_level >= $minimum_level;
  }

  /**
   * Sends alerts through Drupal's configured mail transport.
   *
   * @param array<string, mixed> $settings
   *   Email channel configuration.
   * @param array{subject: string, body: string} $message
   *   Safe, normalized alert content.
   *
   * @return array<int, array<string, mixed>>
   *   Per-recipient results.
   */
  private function sendEmail(array $settings, array $message): array {
    if (!(bool) ($settings['enabled'] ?? FALSE)) {
      return [];
    }

    $results = [];
    foreach ($this->emails($settings['recipients'] ?? []) as $recipient) {
      $delivery = $this->mailManager->mail('ai_adminops', 'event_alert', $recipient, 'es', $message);
      $results[] = [
        'recipient' => $recipient,
        'status' => !empty($delivery['result']) ? 'sent' : 'failed',
      ];
    }
    return $results;
  }

  /**
   * Sends alerts through the existing WhatsApp provider sender.
   *
   * @param array<string, mixed> $settings
   *   WhatsApp channel configuration.
   * @param array{subject: string, body: string} $message
   *   Safe, normalized alert content.
   *
   * @return array<int, array<string, mixed>>
   *   Per-recipient results.
   */
  private function sendWhatsApp(array $settings, array $message): array {
    if (!(bool) ($settings['enabled'] ?? FALSE)) {
      return [];
    }

    $account_id = trim((string) ($settings['account_id'] ?? ''));
    $account = $account_id === '' ? NULL : $this->entityTypeManager->getStorage('ai_whatsapp_account')->load($account_id);
    if (!$account instanceof ContentEntityInterface || (string) $account->get('status')->value !== 'active') {
      $this->logger->warning('AdminOps WhatsApp notifications are enabled but no active WhatsApp account is configured.');
      return [];
    }

    $provider = (string) $account->get('provider')->value;
    $account_phone = (string) $account->get('phone_number')->value;
    $template_sid = trim((string) ($settings['template_sid'] ?? ''));
    $results = [];

    foreach ($this->phones($settings['recipients'] ?? []) as $recipient) {
      $payload = [
        'phone' => $recipient,
        'account_phone' => $account_phone,
        'whatsapp_account_id' => $account->id(),
      ];
      $delivery = $provider === 'twilio' && $template_sid !== ''
        ? $this->messageSender->sendTemplate($provider, $payload, $template_sid, ['1' => $this->templateText($message)])
        : $this->messageSender->sendText($provider, $payload, $message['body']);
      $results[] = [
        'recipient' => $recipient,
        'status' => (string) ($delivery['status'] ?? 'unknown'),
        'provider' => $provider,
      ];
    }
    return $results;
  }

  /**
   * Builds safe alert content without raw evidence or credentials.
   *
   * @return array{subject: string, body: string}
   *   Safe notification content.
   */
  private function message(ContentEntityInterface $event): array {
    $server = $event->get('server')->entity;
    $server_label = $server ? $server->label() : 'Unknown server';
    $severity = strtoupper((string) $event->get('severity')->value);
    $summary = trim((string) $event->get('summary')->value);
    $event_type = trim((string) $event->get('event_type')->value);
    $occurred_at = (int) $event->get('occurred_at')->value;
    $time = $occurred_at > 0 ? date('Y-m-d H:i:s T', $occurred_at) : 'Unknown';

    return [
      'subject' => sprintf('[AdminOps][%s] %s', $severity, $server_label),
      'body' => implode("\n", [
        'AI AdminOps alert',
        'Server: ' . $server_label,
        'Severity: ' . $severity,
        'Type: ' . $event_type,
        'Summary: ' . $summary,
        'Occurred: ' . $time,
        'Event ID: ' . $event->id(),
      ]),
    ];
  }

  /**
   * Reduces alert text to a safe size for a WhatsApp template variable.
   */
  private function templateText(array $message): string {
    return substr(str_replace("\n", ' | ', $message['body']), 0, 900);
  }

  /**
   * Returns unique validated email recipients.
   *
   * @param mixed $recipients
   *   Configured recipient values.
   *
   * @return string[]
   *   Valid recipients.
   */
  private function emails(mixed $recipients): array {
    $recipients = is_array($recipients) ? $recipients : [];
    $valid = [];
    foreach ($recipients as $recipient) {
      $recipient = trim((string) $recipient);
      if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $valid[$recipient] = $recipient;
      }
    }
    return array_values($valid);
  }

  /**
   * Returns unique normalized WhatsApp recipients.
   *
   * @param mixed $recipients
   *   Configured recipient values.
   *
   * @return string[]
   *   Valid recipients in E.164-compatible notation.
   */
  private function phones(mixed $recipients): array {
    $recipients = is_array($recipients) ? $recipients : [];
    $valid = [];
    foreach ($recipients as $recipient) {
      $digits = preg_replace('/\D+/', '', (string) $recipient) ?? '';
      if (strlen($digits) >= 8 && strlen($digits) <= 15) {
        $valid['+' . $digits] = '+' . $digits;
      }
    }
    return array_values($valid);
  }

}
