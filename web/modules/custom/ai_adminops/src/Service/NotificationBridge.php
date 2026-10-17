<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\ai_whatsapp_automation\Application\Webhook\ProviderMessageSenderService;
use Psr\Log\LoggerInterface;

/**
 * Delivers AdminOps event alerts through configured existing channels.
 */
final class NotificationBridge {

  /**
   * Severity as the alert template says it.
   */
  private const LEVELS = [
    'critical' => 'Crítica',
    'warning' => 'Advertencia',
    'info' => 'Información',
  ];

  /**
   * Short titles for the server-wide event types.
   */
  private const PROBLEMS = [
    'server_load_high' => 'Carga del servidor alta',
    'cpu_usage_high' => 'Uso de CPU alto',
    'memory_usage_high' => 'Uso de memoria alto',
    'disk_usage_high' => 'Disco del servidor casi lleno',
    'exim_queue_high' => 'Cola de correo alta',
    'server_unreachable' => 'Servidor sin respuesta',
  ];

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
   * Sends a test message through the given channel settings.
   *
   * Uses the same delivery code as real alerts, so a test that arrives proves
   * alerts will. It ignores the master switch, the minimum severity and the
   * cooldown on purpose: the point is to check delivery before relying on it.
   *
   * @param array<string, mixed> $email
   *   Email channel settings: enabled, recipients.
   * @param array<string, mixed> $whatsapp
   *   WhatsApp channel settings: enabled, account_id, recipients,
   *   template_sid.
   *
   * @return array{email: array<int, array<string, mixed>>, whatsapp: array<int, array<string, mixed>>}
   *   Per-recipient results.
   */
  public function sendTest(array $email, array $whatsapp): array {
    $message = [
      'subject' => '[AdminOps][TEST] Notification check',
      'body' => implode("\n", [
        'AI AdminOps test notification',
        'If you are reading this, alerts from this site reach you through this channel.',
        'Sent: ' . date('Y-m-d H:i:s T', $this->time->getRequestTime()),
      ]),
      'variables' => $this->templateValues([
        '1' => 'Prueba',
        '2' => 'AdminOps',
        '3' => 'todo el servidor',
        '4' => 'Prueba de aviso de AdminOps',
        '5' => 'Si lees esto, las alertas reales te llegan por este canal.',
        '6' => date('Y-m-d H:i', $this->time->getRequestTime()),
        '7' => Url::fromRoute('ai_adminops.events', [], ['absolute' => TRUE])->toString(),
      ]),
    ];
    $results = [
      'email' => $this->sendEmail($email, $message),
      'whatsapp' => $this->sendWhatsApp($whatsapp, $message),
    ];
    $this->logger->notice('AdminOps test notification attempted.');

    return $results;
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
   * @param array{subject: string, body: string, variables: array<string, string>} $message
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
   * @param array{subject: string, body: string, variables: array<string, string>} $message
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
        ? $this->messageSender->sendTemplate($provider, $payload, $template_sid, $message['variables'])
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
      'variables' => $this->eventVariables($event, $server_label),
    ];
  }

  /**
   * The seven values of the approved WhatsApp alert template.
   *
   * Template "josera_alerta_servidor" (HX836b…, 2026-10-13): Nivel, Servidor,
   * Afectado, Problema, Detalle, Desde, Ver alerta. José asked that an alert
   * name the cPanel account when it is about one (mail flood, quota at 95 %),
   * so an event's evidence may carry "account", "domain", "problem" and
   * "detail"; server-wide events say "todo el servidor". Meta rejects empty
   * values, so every one has a fallback.
   *
   * @return array<string, string>
   *   Values keyed "1" to "7".
   */
  private function eventVariables(ContentEntityInterface $event, string $server_label): array {
    $evidence = json_decode((string) $event->get('evidence_json')->value, TRUE);
    $evidence = is_array($evidence) ? $evidence : [];
    $summary = trim((string) $event->get('summary')->value);
    $problem = self::PROBLEMS[(string) $event->get('event_type')->value] ?? NULL;

    $account = trim((string) ($evidence['account'] ?? ''));
    $domain = trim((string) ($evidence['domain'] ?? ''));
    $affected = match (TRUE) {
      $account !== '' => 'cuenta ' . $account . ($domain !== '' ? ' (' . $domain . ')' : ''),
      // A certificate is about a domain, not an account.
      $domain !== '' => 'dominio ' . $domain,
      default => 'todo el servidor',
    };
    $occurred_at = (int) $event->get('occurred_at')->value;

    return $this->templateValues([
      '1' => self::LEVELS[(string) $event->get('severity')->value] ?? 'Aviso',
      '2' => $server_label,
      '3' => $affected,
      // Known server-wide types get a short title and keep the measured
      // summary as the detail; anything else uses its summary as the title.
      '4' => (string) ($evidence['problem'] ?? $problem ?? $summary),
      '5' => (string) ($evidence['detail'] ?? ($problem !== NULL ? $summary : trim((string) $event->get('details')->value))),
      '6' => $occurred_at > 0 ? date('Y-m-d H:i', $occurred_at) : 'Sin fecha',
      '7' => Url::fromRoute('ai_adminops.event_status', ['ai_adminops_event' => $event->id()], ['absolute' => TRUE])->toString(),
    ]);
  }

  /**
   * Makes template values safe: one line, bounded, never empty.
   *
   * @param array<string, string> $values
   *   Raw values.
   *
   * @return array<string, string>
   *   Cleaned values.
   */
  private function templateValues(array $values): array {
    foreach ($values as $key => $value) {
      $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
      $values[$key] = $value === '' ? 'Sin dato' : mb_substr($value, 0, 300);
    }

    return $values;
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
