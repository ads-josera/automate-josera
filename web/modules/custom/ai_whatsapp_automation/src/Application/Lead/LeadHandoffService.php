<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Lead;

use Drupal\ai_whatsapp_automation\Application\Webhook\ProviderMessageSenderService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects qualified WhatsApp leads and notifies administrators.
 */
final class LeadHandoffService {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a LeadHandoffService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ProviderMessageSenderService $messageSender,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Handles a possible handoff after an AI response.
   *
   * @return array<string, mixed>
   *   Handoff status.
   */
  public function handle(ContentEntityInterface $conversation, string $ai_response): array {
    $config = $this->configFactory->get('ai_whatsapp_automation.settings');
    if (!$config->get('options.enable_lead_notifications')) {
      return ['status' => 'disabled'];
    }

    if (!$this->isLeadReady($conversation, $ai_response)) {
      return ['status' => 'not_ready'];
    }

    if ($this->hasHandoffAction($conversation)) {
      return ['status' => 'already_notified'];
    }

    $lead = $this->createLead($conversation, $ai_response);
    $conversation->set('status', 'HUMAN_ASSIGNED');
    $conversation->save();
    $this->auditHandoff($conversation, $lead);

    $notifications = $this->notifyAdministrators($conversation, $lead, $ai_response);
    if ($notifications === []) {
      $this->logger->warning('Lead handoff created for conversation @conversation, but no administrator notification numbers were configured.', [
        '@conversation' => (string) $conversation->id(),
      ]);

      return [
        'status' => 'created_without_recipients',
        'lead_id' => $lead->id(),
        'notifications' => [],
      ];
    }

    return [
      'status' => 'notified',
      'lead_id' => $lead->id(),
      'notifications' => $notifications,
    ];
  }

  /**
   * Checks whether the recent conversation looks ready for human follow-up.
   */
  private function isLeadReady(ContentEntityInterface $conversation, string $ai_response): bool {
    $text = mb_strtolower($this->recentConversationText($conversation) . "\n" . $ai_response);

    $has_contact = str_contains($text, '@')
      || preg_match('/\b(?:tel[eé]fono|celular|whatsapp|contacto|correo|email)\b/u', $text)
      || preg_match('/(?:\+?52\s?1?\s?)?\d{10,}/', $text);
    $has_handoff_signal = preg_match('/\b(?:asesor|especialista|propuesta personalizada|se pondr[aá] en contacto|elaborar[aá] una propuesta)\b/u', $text);
    $has_summary_signal = preg_match('/\b(?:datos recibidos|resumo|resumen|gracias por la informaci[oó]n|informaci[oó]n inicial)\b/u', $text);
    $quote_field_count = $this->countQuoteFields($text);

    return (bool) ($has_contact && $quote_field_count >= 5 && ($has_handoff_signal || $has_summary_signal));
  }

  /**
   * Counts quote-related fields detected in conversation text.
   */
  private function countQuoteFields(string $text): int {
    $patterns = [
      '/\bempresa\b/u',
      '/\b(?:mercanc[ií]a|producto|carga)\b/u',
      '/\borigen\b/u',
      '/\bdestino\b/u',
      '/\b(?:medio|transporte|multimodal|mar[ií]timo|terrestre|a[eé]reo)\b/u',
      '/\b(?:valor|monto|usd|mxn|\$)\b/u',
      '/\b(?:frecuencia|embarques?|mensual|semanal|ocasional)\b/u',
      '/\b(?:cobertura|nacional|internacional)\b/u',
    ];

    $count = 0;
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text)) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Loads a compact transcript from recent messages.
   */
  private function recentConversationText(ContentEntityInterface $conversation): string {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->sort('id', 'DESC')
      ->range(0, 12)
      ->execute();

    $messages = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->loadMultiple($ids);

    $lines = [];
    foreach (array_reverse($messages) as $message) {
      if (!$message instanceof ContentEntityInterface) {
        continue;
      }
      $lines[] = (string) $message->get('sender')->value . ': ' . (string) $message->get('content')->value;
    }

    return implode("\n", $lines);
  }

  /**
   * Checks whether this conversation already had a lead handoff.
   */
  private function hasHandoffAction(ContentEntityInterface $conversation): bool {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_operator_action')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->condition('action', 'LEAD_HANDOFF')
      ->range(0, 1)
      ->execute();

    return $ids !== [];
  }

  /**
   * Creates a lead from a conversation.
   */
  private function createLead(ContentEntityInterface $conversation, string $ai_response): ContentEntityInterface {
    $text = $this->recentConversationText($conversation) . "\n" . $ai_response;
    $email = $this->extractEmail($text);
    $name = $this->extractValue($text, ['contacto', 'nombre']) ?: 'WhatsApp lead ' . $conversation->id();

    $lead = $this->entityTypeManager
      ->getStorage('ai_whatsapp_lead')
      ->create([
        'name' => $name,
        'phone' => (string) $conversation->get('phone')->value,
        'email' => $email,
        'source' => 'whatsapp',
        'status' => 'qualified',
        'tags' => ['ai-handoff', 'quote-request'],
      ]);
    $lead->save();

    return $lead;
  }

  /**
   * Creates a handoff audit record.
   */
  private function auditHandoff(ContentEntityInterface $conversation, ContentEntityInterface $lead): void {
    $this->entityTypeManager
      ->getStorage('ai_whatsapp_operator_action')
      ->create([
        'conversation' => $conversation->id(),
        'user' => 1,
        'action' => 'LEAD_HANDOFF',
        'note' => 'Lead ID: ' . $lead->id(),
      ])
      ->save();
  }

  /**
   * Sends WhatsApp notifications to configured administrator numbers.
   *
   * @return array<int, array<string, mixed>>
   *   Delivery results.
   */
  private function notifyAdministrators(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response): array {
    $numbers = $this->notificationNumbers($conversation);
    if ($numbers === []) {
      return [];
    }

    $message = $this->buildNotificationMessage($conversation, $lead, $ai_response);
    $account_phone = $this->getAccountPhone($conversation);
    $provider = (string) $conversation->get('provider')->value;
    $results = [];

    foreach ($numbers as $number) {
      $results[] = $this->messageSender->sendText($provider, [
        'phone' => $number,
        'account_phone' => $account_phone,
      ], $message);
    }

    $this->logger->notice('Lead handoff notification sent for conversation @conversation to @count administrators.', [
      '@conversation' => (string) $conversation->id(),
      '@count' => (string) count($numbers),
    ]);

    return $results;
  }

  /**
   * Builds the notification message sent to administrators.
   */
  private function buildNotificationMessage(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response): string {
    $base_url = rtrim((string) ($GLOBALS['base_url'] ?? ''), '/');
    $conversation_url = $base_url . '/admin/content/ai-whatsapp/conversations/' . $conversation->id();

    return trim(implode("\n", [
      'Nuevo lead de WhatsApp',
      '',
      'Lead ID: ' . $lead->id(),
      'Contacto: ' . $lead->label(),
      'Teléfono: ' . (string) $conversation->get('phone')->value,
      'Correo: ' . ((string) $lead->get('email')->value ?: 'No capturado'),
      '',
      'Resumen del bot:',
      mb_substr(trim($ai_response), 0, 900),
      '',
      'Conversación:',
      $conversation_url,
    ]));
  }

  /**
   * Returns configured administrator WhatsApp numbers.
   *
   * @return string[]
   *   Phone numbers.
   */
  private function notificationNumbers(ContentEntityInterface $conversation): array {
    $raw = $this->accountNotificationNumbers($conversation);
    if ($raw === '') {
      $raw = (string) $this->configFactory
        ->get('ai_whatsapp_automation.settings')
        ->get('options.lead_notification_numbers');
    }

    $numbers = preg_split('/[\r\n,]+/', $raw) ?: [];

    return array_values(array_filter(array_map('trim', $numbers)));
  }

  /**
   * Returns account-specific notification numbers, if configured.
   */
  private function accountNotificationNumbers(ContentEntityInterface $conversation): string {
    if (!$conversation->hasField('whatsapp_account') || $conversation->get('whatsapp_account')->isEmpty()) {
      return '';
    }

    $account = $conversation->get('whatsapp_account')->entity;
    if (!$account instanceof ContentEntityInterface || !$account->hasField('lead_notification_numbers')) {
      return '';
    }

    return trim((string) $account->get('lead_notification_numbers')->value);
  }

  /**
   * Returns the WhatsApp account phone associated with the conversation.
   */
  private function getAccountPhone(ContentEntityInterface $conversation): string {
    if (!$conversation->hasField('whatsapp_account') || $conversation->get('whatsapp_account')->isEmpty()) {
      return '';
    }

    $account = $conversation->get('whatsapp_account')->entity;
    if (!$account instanceof ContentEntityInterface || !$account->hasField('phone_number')) {
      return '';
    }

    return (string) $account->get('phone_number')->value;
  }

  /**
   * Extracts the first email from text.
   */
  private function extractEmail(string $text): string {
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches)) {
      return $matches[0];
    }

    return '';
  }

  /**
   * Extracts a simple labeled value from recent text.
   *
   * @param string[] $labels
   *   Labels to match.
   */
  private function extractValue(string $text, array $labels): string {
    foreach ($labels as $label) {
      if (preg_match('/' . preg_quote($label, '/') . '\s*:\s*([^\n\r]+)/iu', $text, $matches)) {
        return trim($matches[1]);
      }
    }

    return '';
  }

}
