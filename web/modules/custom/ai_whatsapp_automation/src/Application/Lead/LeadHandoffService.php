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

    $bot = $this->getBotForConversation($conversation);
    if ($bot instanceof ContentEntityInterface && $bot->hasField('handoff_enabled') && !(bool) $bot->get('handoff_enabled')->value) {
      return ['status' => 'disabled_for_bot'];
    }

    if (!$this->isLeadReady($conversation, $ai_response, $bot)) {
      return ['status' => 'not_ready'];
    }

    if ($this->hasHandoffAction($conversation)) {
      return ['status' => 'already_notified'];
    }

    $lead = $this->createLead($conversation, $ai_response);
    $conversation->set('status', 'HUMAN_ASSIGNED');
    $conversation->save();
    $this->auditHandoff($conversation, $lead);

    $notifications = $this->notifyAdministrators($conversation, $lead, $ai_response, $bot);
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

    $successful_notifications = array_filter($notifications, static fn (array $notification): bool => ($notification['status'] ?? '') === 'sent');
    if ($successful_notifications === []) {
      $this->logger->error('Lead handoff created for conversation @conversation, but every administrator notification failed.', [
        '@conversation' => (string) $conversation->id(),
      ]);

      return [
        'status' => 'created_notification_failed',
        'lead_id' => $lead->id(),
        'notifications' => $notifications,
      ];
    }

    return [
      'status' => count($successful_notifications) === count($notifications) ? 'notified' : 'partially_notified',
      'lead_id' => $lead->id(),
      'notifications' => $notifications,
    ];
  }

  /**
   * Checks whether the recent conversation looks ready for human follow-up.
   */
  private function isLeadReady(ContentEntityInterface $conversation, string $ai_response, ?ContentEntityInterface $bot): bool {
    $contact_text = mb_strtolower($this->recentContactText($conversation));
    $text = mb_strtolower($this->recentConversationText($conversation) . "\n" . $ai_response);
    $conversation_phone = $conversation->hasField('phone') ? trim((string) $conversation->get('phone')->value) : '';
    $is_web_conversation = $conversation->hasField('provider') && $conversation->get('provider')->value === 'web';

    $has_contact = (!$is_web_conversation && $conversation_phone !== '')
      || str_contains($contact_text, '@')
      || preg_match('/\b(?:tel[eé]fono|celular|whatsapp|contacto|correo|email)\b/u', $contact_text)
      || preg_match('/(?:\+?52\s?1?\s?)?\d{10,}/', $contact_text);
    $has_handoff_signal = $this->containsAnySignal(mb_strtolower($ai_response), $this->handoffTriggerPhrases($bot));
    // These are natural closing phrases used by the assistant once it has
    // captured the service data. They are intentionally independent from a
    // bot's optional custom trigger list.
    $has_summary_signal = preg_match('/\b(?:datos recibidos|confirmo los datos|datos capturados|resumo|resumen|gracias por la informaci[oó]n|informaci[oó]n inicial|pr[oó]ximo paso|solicitud lista|preparar[aá] una propuesta)\b/u', mb_strtolower($ai_response));
    // Before the assistant confirms a summary, count only information the
    // contact supplied. This prevents a bot's intake checklist from creating
    // a lead merely because it listed the fields it still needs.
    $signal_text = $has_summary_signal ? $text : $contact_text;
    $signal_count = $this->countSignalGroups($signal_text, $this->handoffRequiredSignals($bot));
    $minimum_signals = $this->minimumSignals($bot);

    return (bool) ($has_contact && $signal_count >= $minimum_signals && ($has_handoff_signal || $has_summary_signal));
  }

  /**
   * Counts configured signal groups detected in conversation text.
   */
  private function countSignalGroups(string $text, array $signal_groups): int {
    $count = 0;
    foreach ($signal_groups as $signals) {
      if ($this->containsAnySignal($text, $signals)) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Checks whether text contains any of the provided literal signals.
   *
   * @param string[] $signals
   *   Literal signals to match.
   */
  private function containsAnySignal(string $text, array $signals): bool {
    foreach ($signals as $signal) {
      $signal = trim(mb_strtolower($signal));
      if ($signal === '') {
        continue;
      }
      if (str_contains($text, $signal)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns the required handoff signal groups for a bot.
   *
   * @return array<int, string[]>
   *   Signal groups.
   */
  private function handoffRequiredSignals(?ContentEntityInterface $bot): array {
    $configured = $bot instanceof ContentEntityInterface ? $this->getFieldValue($bot, 'handoff_required_fields') : '';
    if ($configured !== '') {
      return $this->parseSignalGroups($configured);
    }

    return [
      ['empresa', 'negocio', 'cliente', 'nombre'],
      ['mercancía', 'mercancia', 'producto', 'servicio', 'interés', 'interes', 'necesidad'],
      ['origen', 'ubicación', 'ubicacion', 'ciudad'],
      ['destino', 'entrega', 'cobertura'],
      ['medio', 'transporte', 'modalidad', 'multimodal', 'terrestre', 'marítimo', 'maritimo', 'aéreo', 'aereo'],
      ['valor', 'monto', 'presupuesto', 'usd', 'mxn', '$'],
      ['frecuencia', 'cantidad', 'volumen', 'embarque', 'embarques', 'mensual', 'semanal', 'ocasional'],
      ['contacto', 'teléfono', 'telefono', 'whatsapp', 'correo', 'email', '@'],
    ];
  }

  /**
   * Returns handoff trigger phrases for a bot.
   *
   * @return string[]
   *   Trigger phrases.
   */
  private function handoffTriggerPhrases(?ContentEntityInterface $bot): array {
    $configured = $bot instanceof ContentEntityInterface ? $this->getFieldValue($bot, 'handoff_trigger_phrases') : '';
    $default_phrases = [
      'asesor',
      'especialista',
      'ejecutivo',
      'representante',
      'humano',
      'propuesta personalizada',
      'se pondrá en contacto',
      'se pondra en contacto',
      'elaborará una propuesta',
      'elaborara una propuesta',
      'seguimiento',
      'contactar',
      'cotización',
      'cotizacion',
      'solicitud',
      'agendar',
      'reservar',
      'contratar',
      'próximo paso',
      'proximo paso',
      'confirmo los datos',
      'datos capturados',
      'solicitud lista',
      'revisará la operación',
      'revisara la operacion',
      'preparará una propuesta',
      'preparara una propuesta',
    ];

    // Custom phrases expand the reliable defaults instead of replacing them.
    // This prevents a bot-specific setup from accidentally disabling normal
    // lead handoff wording such as "Próximo paso" or "asesor".
    return array_values(array_unique(array_merge(
      $default_phrases,
      $configured === '' ? [] : $this->parseSignalList($configured),
    )));
  }

  /**
   * Returns the minimum configured signal count.
   */
  private function minimumSignals(?ContentEntityInterface $bot): int {
    if (!$bot instanceof ContentEntityInterface || !$bot->hasField('handoff_minimum_fields') || $bot->get('handoff_minimum_fields')->isEmpty()) {
      return 5;
    }

    return max(1, (int) $bot->get('handoff_minimum_fields')->value);
  }

  /**
   * Parses one signal group per line with comma alternatives.
   *
   * @return array<int, string[]>
   *   Signal groups.
   */
  private function parseSignalGroups(string $value): array {
    $groups = [];
    foreach (preg_split('/\R+/', $value) ?: [] as $line) {
      $signals = $this->parseSignalList($line);
      if ($signals !== []) {
        $groups[] = $signals;
      }
    }

    return $groups;
  }

  /**
   * Parses signals separated by commas, pipes, or new lines.
   *
   * @return string[]
   *   Signals.
   */
  private function parseSignalList(string $value): array {
    $signals = preg_split('/[\r\n,|]+/', $value) ?: [];

    return array_values(array_filter(array_map('trim', $signals), static fn (string $signal): bool => $signal !== ''));
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
   * Loads only recent messages supplied by the contact.
   */
  private function recentContactText(ContentEntityInterface $conversation): string {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->condition('sender', 'contact')
      ->sort('id', 'DESC')
      ->range(0, 12)
      ->execute();

    $messages = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->loadMultiple($ids);
    $lines = [];
    foreach (array_reverse($messages) as $message) {
      if ($message instanceof ContentEntityInterface) {
        $lines[] = (string) $message->get('content')->value;
      }
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
    $is_web_conversation = $conversation->hasField('provider') && $conversation->get('provider')->value === 'web';
    $phone = (string) $conversation->get('phone')->value;
    if ($is_web_conversation) {
      $phone = $this->extractPhone($text);
    }

    $lead = $this->entityTypeManager
      ->getStorage('ai_whatsapp_lead')
      ->create([
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'source' => $is_web_conversation ? 'web' : 'whatsapp',
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
  private function notifyAdministrators(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, ?ContentEntityInterface $bot): array {
    $account = $this->notificationAccount($conversation, $bot);
    $numbers = $this->notificationNumbers($account);
    if ($numbers === []) {
      return [];
    }

    $message = $this->buildNotificationMessage($conversation, $lead, $ai_response);
    if (!$account instanceof ContentEntityInterface) {
      $this->logger->warning('Lead handoff for conversation @conversation has recipients but no WhatsApp account for delivery.', [
        '@conversation' => (string) $conversation->id(),
      ]);
      return [];
    }

    $account_phone = $this->getFieldValue($account, 'phone_number');
    $provider = $this->getFieldValue($account, 'provider');
    $template = $this->notificationTemplate($account, $bot);
    $results = [];

    foreach ($numbers as $number) {
      $recipient = [
        'phone' => $number,
        'account_phone' => $account_phone,
        'whatsapp_account_id' => $account->id(),
      ];
      $results[] = $provider === 'twilio' && $template['sid'] !== ''
        ? $this->messageSender->sendTemplate($provider, $recipient, $template['sid'], $this->notificationTemplateVariables($conversation, $lead, $ai_response, $bot, $template['variables']))
        : $this->messageSender->sendText($provider, $recipient, $message);
    }

    $this->logger->notice('Lead handoff notification attempted for conversation @conversation to @count administrators.', [
      '@conversation' => (string) $conversation->id(),
      '@count' => (string) count($numbers),
    ]);

    return $results;
  }

  /**
   * Resolves the lead template and its mapping by account, bot, then global.
   *
   * @return array{sid: string, variables: string}
   *   Template content SID and its optional variable mapping.
   */
  private function notificationTemplate(?ContentEntityInterface $account, ?ContentEntityInterface $bot): array {
    $account_template = $this->getFieldValue($account, 'lead_notification_template_sid');
    if ($account_template !== '') {
      return [
        'sid' => $account_template,
        'variables' => $this->getFieldValue($account, 'lead_notification_template_variables'),
      ];
    }

    $bot_template = $this->getFieldValue($bot, 'lead_notification_template_sid');
    if ($bot_template !== '') {
      return [
        'sid' => $bot_template,
        'variables' => $this->getFieldValue($bot, 'lead_notification_template_variables'),
      ];
    }

    return [
      'sid' => trim((string) $this->configFactory
        ->get('ai_whatsapp_automation.settings')
        ->get('twilio.content_template_sid')),
      'variables' => '',
    ];
  }

  /**
   * Builds variables for the approved lead-notification template.
   *
   * @return array<string, string>
   *   Values keyed by the placeholders configured for the selected template.
   */
  private function notificationTemplateVariables(ContentEntityInterface $conversation, ContentEntityInterface $lead, string $ai_response, ?ContentEntityInterface $bot, string $mapping): array {
    $base_url = rtrim((string) ($GLOBALS['base_url'] ?? ''), '/');
    $values = [
      'lead_id' => $this->templateVariable((string) $lead->id(), 'Sin folio'),
      'contact' => $this->templateVariable((string) $lead->label(), 'Contacto no capturado'),
      'phone' => $this->templateVariable((string) ($lead->get('phone')->value ?: ''), 'No capturado'),
      'email' => $this->templateVariable((string) ($lead->get('email')->value ?: ''), 'No capturado'),
      'summary' => $this->templateSummary($ai_response),
      'bot_name' => $this->templateVariable($bot?->label() ?? '', 'Bot no identificado'),
      'source' => $this->templateVariable($this->getFieldValue($conversation, 'provider'), 'whatsapp'),
      'conversation_url' => $this->templateVariable($base_url . '/admin/content/ai-whatsapp/conversations/' . $conversation->id(), 'No disponible'),
    ];

    $variables = [];
    foreach ($this->templateVariableMapping($mapping) as $placeholder => $value_key) {
      $variables[$placeholder] = $values[$value_key];
    }

    return $variables;
  }

  /**
   * Parses a template variable map or returns the standard lead map.
   *
   * @return array<string, string>
   *   Placeholder numbers keyed to available lead values.
   */
  private function templateVariableMapping(string $mapping): array {
    $default = [
      '1' => 'lead_id',
      '2' => 'contact',
      '3' => 'phone',
      '4' => 'email',
      '5' => 'summary',
    ];
    $mapping = trim($mapping);
    if ($mapping === '') {
      return $default;
    }

    $allowed = array_fill_keys([
      'lead_id',
      'contact',
      'phone',
      'email',
      'summary',
      'bot_name',
      'source',
      'conversation_url',
    ], TRUE);
    $parsed = [];
    foreach (preg_split('/\R/u', $mapping) ?: [] as $line) {
      if (!preg_match('/^\s*(\d+)\s*=\s*([a-z_]+)\s*$/', $line, $matches)) {
        continue;
      }
      if (isset($allowed[$matches[2]])) {
        $parsed[$matches[1]] = $matches[2];
      }
    }

    return $parsed !== [] ? $parsed : $default;
  }

  /**
   * Normalizes a Twilio ContentVariables value to a single safe text line.
   */
  private function templateVariable(string $value, string $fallback): string {
    $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    $value = mb_substr($value, 0, 500);

    return $value !== '' ? $value : $fallback;
  }

  /**
   * Creates a plain-text, operator-friendly summary for a Twilio template.
   */
  private function templateSummary(string $summary): string {
    $summary = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $summary) ?? '';
    $summary = str_replace(['**', '__', '`'], '', $summary);
    $summary = str_replace(['•', '▪', '◦'], '|', $summary);
    $summary = preg_replace('/\R+/u', ' | ', $summary) ?? '';
    $summary = preg_replace('/\s*\|\s*/u', ' | ', $summary) ?? '';

    return $this->templateVariable($summary, 'Solicitud de seguimiento generada.');
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
      'Teléfono: ' . ((string) $lead->get('phone')->value ?: 'No capturado'),
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
  private function notificationNumbers(?ContentEntityInterface $account): array {
    $raw = $this->getFieldValue($account, 'lead_notification_numbers');
    if ($raw === '') {
      $raw = (string) $this->configFactory
        ->get('ai_whatsapp_automation.settings')
        ->get('options.lead_notification_numbers');
    }

    $numbers = preg_split('/[\r\n,]+/', $raw) ?: [];

    return array_values(array_filter(array_map('trim', $numbers)));
  }

  /**
   * Loads the WhatsApp account associated with a conversation.
   */
  private function getAccountForConversation(ContentEntityInterface $conversation): ?ContentEntityInterface {
    if (!$conversation->hasField('whatsapp_account') || $conversation->get('whatsapp_account')->isEmpty()) {
      return NULL;
    }

    $account = $conversation->get('whatsapp_account')->entity;

    return $account instanceof ContentEntityInterface ? $account : NULL;
  }

  /**
   * Returns the bot associated with the conversation account.
   */
  private function getBotForConversation(ContentEntityInterface $conversation): ?ContentEntityInterface {
    if ($conversation->hasField('bot') && !$conversation->get('bot')->isEmpty()) {
      $bot = $conversation->get('bot')->entity;
      if ($bot instanceof ContentEntityInterface) {
        return $bot;
      }
    }

    $account = $this->getAccountForConversation($conversation);
    if (!$account instanceof ContentEntityInterface || !$account->hasField('bot') || $account->get('bot')->isEmpty()) {
      return NULL;
    }

    $bot = $account->get('bot')->entity;

    return $bot instanceof ContentEntityInterface ? $bot : NULL;
  }

  /**
   * Resolves the WhatsApp account used to notify administrators.
   */
  private function notificationAccount(ContentEntityInterface $conversation, ?ContentEntityInterface $bot): ?ContentEntityInterface {
    $conversation_account = $this->getAccountForConversation($conversation);
    if ($conversation_account instanceof ContentEntityInterface) {
      return $conversation_account;
    }

    if ($bot instanceof ContentEntityInterface && $bot->hasField('lead_notification_account') && !$bot->get('lead_notification_account')->isEmpty()) {
      $account = $bot->get('lead_notification_account')->entity;
      if ($account instanceof ContentEntityInterface) {
        return $account;
      }
    }

    if (!$bot instanceof ContentEntityInterface) {
      return NULL;
    }

    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_account')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('bot', $bot->id())
      ->condition('status', 'active')
      ->range(0, 2)
      ->execute();
    if (count($ids) !== 1) {
      return NULL;
    }

    $account = $this->entityTypeManager
      ->getStorage('ai_whatsapp_account')
      ->load(reset($ids));

    return $account instanceof ContentEntityInterface ? $account : NULL;
  }

  /**
   * Reads a scalar field value from an entity.
   */
  private function getFieldValue(?ContentEntityInterface $entity, string $field_name): string {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Extracts the first likely phone number from text.
   */
  private function extractPhone(string $text): string {
    if (!preg_match('/(?:\+?\d[\d\s().-]{8,}\d)/u', $text, $matches)) {
      return '';
    }

    $digits = preg_replace('/\D+/', '', $matches[0]) ?? '';

    return $digits === '' ? '' : '+' . $digits;
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
