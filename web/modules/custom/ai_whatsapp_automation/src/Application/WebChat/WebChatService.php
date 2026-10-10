<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\WebChat;

use Drupal\ai_whatsapp_automation\Application\AI\ConversationEngineService;
use Drupal\ai_whatsapp_automation\Application\Lead\LeadHandoffService;
use Drupal\ai_whatsapp_automation\Entity\Bot;
use Drupal\ai_whatsapp_automation\Exception\WebChatLimitException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles public web chat widget conversations.
 */
final class WebChatService {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a WebChatService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConversationEngineService $conversationEngine,
    private readonly LeadHandoffService $leadHandoff,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Loads an active web widget bot by public token or UUID.
   */
  public function loadBot(string $token): ?ContentEntityInterface {
    $token = trim($token);
    if ($token === '') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_bot');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'active')
      ->condition('web_widget_enabled', 1)
      ->range(0, 1);

    $token_group = $query->orConditionGroup()
      ->condition('web_widget_token', $token)
      ->condition('uuid', $token);
    $query->condition($token_group);

    $ids = $query->execute();
    if ($ids === []) {
      return NULL;
    }

    $bot = $storage->load(reset($ids));

    return $bot instanceof ContentEntityInterface ? $bot : NULL;
  }

  /**
   * Processes a web chat message.
   *
   * @return array<string, mixed>
   *   Response payload.
   */
  public function processMessage(ContentEntityInterface $bot, string $session_id, string $message): array {
    $session_id = $this->sanitizeSessionId($session_id);
    $conversation = $this->loadConversation($bot, $session_id);
    $is_new_conversation = !$conversation instanceof ContentEntityInterface;
    $this->assertUsageAllowed($bot, $conversation, $is_new_conversation);
    if (!$conversation instanceof ContentEntityInterface) {
      $conversation = $this->createConversation($bot, $session_id);
    }
    $result = $this->conversationEngine->processIncomingMessage($conversation, trim($message), [
      'sender' => 'contact',
      'provider_message_id' => 'web-' . $session_id . '-' . time(),
    ]);
    $reply = trim((string) $result['response_text']);
    // An empty reply means the engine chose to stay quiet, which today only
    // happens after a run of unreadable messages. There is no answer to
    // qualify as a lead, and the widget renders no bubble for it.
    $handoff = $reply === ''
      ? ['status' => 'not_ready']
      : $this->leadHandoff->handle($conversation, $reply);

    return [
      'status' => 'ok',
      'session_id' => $session_id,
      'conversation_id' => $conversation->id(),
      'message' => $reply,
      'handoff' => $handoff,
    ];
  }

  /**
   * Validates domain and optional API key access.
   *
   * @param bool $require_origin
   *   TRUE for the message API. Browsers always send Origin on its POST
   *   requests, so an unidentified caller there is a script. The public chat
   *   page and embed script are GET requests that legitimately arrive without
   *   Referer (shared links, QR codes, no-referrer policies) and keep allowing
   *   unidentified visitors.
   */
  public function isRequestAllowed(ContentEntityInterface $bot, Request $request, bool $require_origin = FALSE): bool {
    $api_key = $this->getFieldValue($bot, 'web_widget_api_key');
    if ($api_key !== '') {
      $provided = (string) ($request->headers->get('X-AI-WhatsApp-Key') ?: $request->query->get('key', ''));
      if (!hash_equals($api_key, $provided)) {
        return FALSE;
      }
    }

    $allowed_domains = $this->allowedDomains($bot);
    if ($allowed_domains === []) {
      return TRUE;
    }

    $host = $this->requestHost($request);
    if ($host === '') {
      // Scripts can still forge these headers: the usage limits, not this
      // check, are what cap consumption.
      return !$require_origin;
    }

    // The embedded page is served by this Drupal site, so its fetch requests
    // legitimately originate from the application host rather than the parent
    // website that embeds the iframe.
    if ($host === $this->normalizeHost($request->getHost())) {
      return TRUE;
    }

    return in_array($host, $allowed_domains, TRUE);
  }

  /**
   * Returns the CORS origin value for an allowed request.
   */
  public function corsOrigin(ContentEntityInterface $bot, Request $request): string {
    $origin = (string) $request->headers->get('Origin', '');
    if ($origin === '') {
      return '*';
    }

    $allowed_domains = $this->allowedDomains($bot);
    if ($allowed_domains === []) {
      return $origin;
    }

    $host = parse_url($origin, PHP_URL_HOST);
    $host = is_string($host) ? $this->normalizeHost($host) : '';

    return in_array($host, $allowed_domains, TRUE) ? $origin : 'null';
  }

  /**
   * Returns CSP frame-ancestors values for the public chat page.
   *
   * @return string[]
   *   Allowed embedding origins.
   */
  public function frameAncestors(ContentEntityInterface $bot): array {
    $domains = $this->allowedDomains($bot);
    if ($domains === []) {
      return ['*'];
    }

    $origins = [];
    foreach ($domains as $domain) {
      $origins[] = 'https://' . $domain;
      $origins[] = 'http://' . $domain;
    }

    return array_values(array_unique($origins));
  }

  /**
   * Builds public widget configuration.
   *
   * @return array<string, string>
   *   Widget configuration.
   */
  public function widgetConfig(ContentEntityInterface $bot): array {
    $name = $this->getFieldValue($bot, 'web_widget_assistant_name') ?: (string) $bot->label();

    $logo_url = $this->logoUrl($bot);
    $secondary = self::safeColor($this->getFieldValue($bot, 'web_widget_secondary_color'), Bot::DEFAULT_SECONDARY_COLOR);

    return [
      'name' => $name,
      'primaryColor' => self::safeColor($this->getFieldValue($bot, 'web_widget_primary_color'), Bot::DEFAULT_PRIMARY_COLOR),
      'secondaryColor' => $secondary,
      'headerInk' => self::readableInk($secondary),
      'logoUrl' => $logo_url,
      'buttonImageUrl' => $this->fileFieldUrl($bot, 'web_widget_button_image') ?: $logo_url,
      'welcomeMessage' => $this->getFieldValue($bot, 'web_widget_welcome_message') ?: 'Hola, ¿en qué puedo ayudarte?',
      'position' => $this->getFieldValue($bot, 'web_widget_position') ?: 'right',
      'icon' => $this->getFieldValue($bot, 'web_widget_icon') ?: 'chat',
      'size' => $this->getFieldValue($bot, 'web_widget_size') ?: 'medium',
      'language' => $this->getFieldValue($bot, 'web_widget_language') ?: 'es',
    ];
  }

  /**
   * Returns a colour safe to print into CSS, or the fallback.
   *
   * The form validates what is saved, but values saved before that check
   * existed, or written by code, still reach a third-party page from here.
   */
  public static function safeColor(string $color, string $fallback): string {
    return preg_match(Bot::COLOR_PATTERN, trim($color)) ? trim($color) : $fallback;
  }

  /**
   * Returns the text colour that reads best on a background: white or ink.
   *
   * The same WCAG contrast rule as inkFor() in js/widget-launcher.js, which
   * colours the button icon; the header text is decided here so the chat
   * never paints white on a pale header, even for a moment.
   */
  public static function readableInk(string $background): string {
    $white = '#FFFFFF';
    $ink = '#111827';

    return self::contrast($background, $white) >= self::contrast($background, $ink) ? $white : $ink;
  }

  /**
   * WCAG contrast ratio between two hex colours, from 1 to 21.
   */
  public static function contrast(string $first, string $second): float {
    $a = self::relativeLuminance($first);
    $b = self::relativeLuminance($second);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
  }

  /**
   * WCAG relative luminance of a #RGB or #RRGGBB colour.
   */
  private static function relativeLuminance(string $hex): float {
    $digits = ltrim(trim($hex), '#');
    if (strlen($digits) === 3) {
      $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
    }
    $channels = array_map(static function (string $pair): float {
      $c = hexdec($pair) / 255;
      return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, str_split(str_pad(substr($digits, 0, 6), 6, '0'), 2));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
  }

  /**
   * Loads or creates a web conversation.
   */
  private function loadConversation(ContentEntityInterface $bot, string $session_id): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_conversation');
    $phone = 'web:' . $bot->id() . ':' . $session_id;

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('phone', $phone)
      ->condition('provider', 'web')
      ->condition('status', 'CLOSED', '<>')
      ->sort('changed', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $conversation = $storage->load(reset($ids));

    return $conversation instanceof ContentEntityInterface ? $conversation : NULL;
  }

  /**
   * Creates a new web conversation after usage validation.
   */
  private function createConversation(ContentEntityInterface $bot, string $session_id): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_conversation');
    $phone = 'web:' . $bot->id() . ':' . $session_id;

    $conversation = $storage->create([
      'phone' => $phone,
      'name' => 'Web visitor',
      'channel' => 'web',
      'provider' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
    ]);
    $conversation->save();

    $this->logger->notice('Web chat conversation @conversation created for bot @bot.', [
      '@conversation' => (string) $conversation->id(),
      '@bot' => (string) $bot->id(),
    ]);

    return $conversation;
  }

  /**
   * Enforces per-bot web chat consumption limits before calling OpenAI.
   */
  private function assertUsageAllowed(ContentEntityInterface $bot, ?ContentEntityInterface $conversation, bool $is_new_conversation): void {
    $now = time();
    $message_limit = $this->integerField($bot, 'web_widget_message_limit', 8);
    $window_minutes = $this->integerField($bot, 'web_widget_message_window_minutes', 15);
    if ($conversation instanceof ContentEntityInterface && $message_limit > 0 && $window_minutes > 0) {
      $count = $this->entityTypeManager->getStorage('ai_whatsapp_message')->getQuery()
        ->accessCheck(FALSE)
        ->condition('conversation', $conversation->id())
        ->condition('sender', 'contact')
        ->condition('created', $now - ($window_minutes * 60), '>=')
        ->count()
        ->execute();
      if ((int) $count >= $message_limit) {
        throw new WebChatLimitException('Has alcanzado el límite temporal de mensajes. Intenta nuevamente en unos minutos.');
      }
    }

    $day_start = (new \DateTimeImmutable('today'))->getTimestamp();
    $daily_conversation_limit = $this->integerField($bot, 'web_widget_daily_conversation_limit', 50);
    if ($is_new_conversation && $daily_conversation_limit > 0) {
      $count = $this->entityTypeManager->getStorage('ai_whatsapp_conversation')->getQuery()
        ->accessCheck(FALSE)
        ->condition('bot', $bot->id())
        ->condition('provider', 'web')
        ->condition('created', $day_start, '>=')
        ->count()
        ->execute();
      if ((int) $count >= $daily_conversation_limit) {
        throw new WebChatLimitException('El chat ha alcanzado su capacidad de atención por hoy. Intenta nuevamente mañana.');
      }
    }

    $daily_budget = $this->decimalField($bot, 'web_widget_daily_budget', 1.50);
    if ($daily_budget > 0 && $this->dailyEstimatedCost((int) $bot->id(), $day_start) >= $daily_budget) {
      throw new WebChatLimitException('El chat ha alcanzado su límite de atención por hoy. Intenta nuevamente mañana.');
    }
  }

  /**
   * Returns the estimated OpenAI cost accrued by this bot's web chat today.
   */
  private function dailyEstimatedCost(int $bot_id, int $day_start): float {
    $query = $this->database->select('ai_whatsapp_message', 'message');
    $query->join('ai_whatsapp_conversation', 'conversation', 'conversation.id = message.conversation');
    $query->addExpression('COALESCE(SUM(message.cost), 0)', 'total_cost');
    $query->condition('conversation.bot', $bot_id);
    $query->condition('conversation.provider', 'web');
    $query->condition('message.sender', 'ai');
    $query->condition('message.created', $day_start, '>=');

    return (float) $query->execute()->fetchField();
  }

  /**
   * Reads an integer bot field with a fallback default.
   */
  private function integerField(ContentEntityInterface $bot, string $field_name, int $default): int {
    $value = $this->getFieldValue($bot, $field_name);

    return $value === '' ? $default : max(0, (int) $value);
  }

  /**
   * Reads a decimal bot field with a fallback default.
   */
  private function decimalField(ContentEntityInterface $bot, string $field_name, float $default): float {
    $value = $this->getFieldValue($bot, $field_name);

    return $value === '' ? $default : max(0, (float) $value);
  }

  /**
   * Returns configured allowed domains.
   *
   * @return string[]
   *   Normalized domains.
   */
  private function allowedDomains(ContentEntityInterface $bot): array {
    $raw = $this->getFieldValue($bot, 'web_widget_allowed_domains');
    $domains = preg_split('/[\r\n,]+/', $raw) ?: [];
    $normalized = [];

    foreach ($domains as $domain) {
      $domain = $this->normalizeHost($domain);
      if ($domain !== '') {
        $normalized[] = $domain;
      }
    }

    return array_values(array_unique($normalized));
  }

  /**
   * Extracts the origin or referrer host.
   */
  private function requestHost(Request $request): string {
    foreach (['Origin', 'Referer'] as $header) {
      $value = (string) $request->headers->get($header, '');
      if ($value === '') {
        continue;
      }
      $host = parse_url($value, PHP_URL_HOST);
      if (is_string($host) && $host !== '') {
        return $this->normalizeHost($host);
      }
    }

    return '';
  }

  /**
   * Normalizes a host or URL to a lowercase host.
   */
  private function normalizeHost(string $value): string {
    $value = trim(mb_strtolower($value));
    if ($value === '') {
      return '';
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
      return preg_replace('/^www\./', '', $host) ?? $host;
    }

    return preg_replace('/^www\./', '', $value) ?? $value;
  }

  /**
   * Sanitizes the browser session identifier.
   */
  private function sanitizeSessionId(string $session_id): string {
    $session_id = preg_replace('/[^A-Za-z0-9_-]/', '', $session_id) ?? '';

    return $session_id !== '' ? mb_substr($session_id, 0, 64) : bin2hex(random_bytes(16));
  }

  /**
   * Returns the uploaded logo URL, with legacy URL support as a fallback.
   */
  private function logoUrl(ContentEntityInterface $bot): string {
    return $this->fileFieldUrl($bot, 'web_widget_logo_file') ?: $this->getFieldValue($bot, 'web_widget_logo_url');
  }

  /**
   * Returns the absolute URL of the file in a file field, or ''.
   */
  private function fileFieldUrl(ContentEntityInterface $bot, string $field_name): string {
    if (!$bot->hasField($field_name) || $bot->get($field_name)->isEmpty()) {
      return '';
    }

    $file = $bot->get($field_name)->entity;
    if ($file === NULL || !method_exists($file, 'getFileUri')) {
      return '';
    }

    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

  /**
   * Reads a scalar field value from an entity.
   */
  private function getFieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

}
