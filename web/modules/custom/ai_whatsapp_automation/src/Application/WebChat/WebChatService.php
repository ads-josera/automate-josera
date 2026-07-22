<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\WebChat;

use Drupal\ai_whatsapp_automation\Application\AI\ConversationEngineService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
    $conversation = $this->loadOrCreateConversation($bot, $session_id);
    $result = $this->conversationEngine->processIncomingMessage($conversation, trim($message), [
      'sender' => 'contact',
      'provider_message_id' => 'web-' . $session_id . '-' . time(),
    ]);

    return [
      'status' => 'ok',
      'session_id' => $session_id,
      'conversation_id' => $conversation->id(),
      'message' => (string) $result['response_text'],
    ];
  }

  /**
   * Validates domain and optional API key access.
   */
  public function isRequestAllowed(ContentEntityInterface $bot, Request $request): bool {
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
   * Builds public widget configuration.
   *
   * @return array<string, string>
   *   Widget configuration.
   */
  public function widgetConfig(ContentEntityInterface $bot): array {
    $name = $this->getFieldValue($bot, 'web_widget_assistant_name') ?: (string) $bot->label();

    return [
      'name' => $name,
      'primaryColor' => $this->getFieldValue($bot, 'web_widget_primary_color') ?: '#155EEF',
      'secondaryColor' => $this->getFieldValue($bot, 'web_widget_secondary_color') ?: '#111827',
      'logoUrl' => $this->getFieldValue($bot, 'web_widget_logo_url'),
      'welcomeMessage' => $this->getFieldValue($bot, 'web_widget_welcome_message') ?: 'Hola, ¿en qué puedo ayudarte?',
      'position' => $this->getFieldValue($bot, 'web_widget_position') ?: 'right',
      'icon' => $this->getFieldValue($bot, 'web_widget_icon') ?: 'chat',
      'size' => $this->getFieldValue($bot, 'web_widget_size') ?: 'medium',
      'language' => $this->getFieldValue($bot, 'web_widget_language') ?: 'es',
    ];
  }

  /**
   * Loads or creates a web conversation.
   */
  private function loadOrCreateConversation(ContentEntityInterface $bot, string $session_id): ContentEntityInterface {
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

    if ($ids !== []) {
      $conversation = $storage->load(reset($ids));
      if ($conversation instanceof ContentEntityInterface) {
        return $conversation;
      }
    }

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
