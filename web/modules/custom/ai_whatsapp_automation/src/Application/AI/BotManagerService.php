<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves bots for WhatsApp accounts and conversations.
 */
final class BotManagerService {

  /**
   * Constructs a BotManagerService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Loads the active bot associated with a conversation.
   */
  public function getBotForConversation(ContentEntityInterface $conversation): ?ContentEntityInterface {
    $account = $this->getAccountForConversation($conversation);
    if (!$account instanceof ContentEntityInterface) {
      return NULL;
    }

    return $this->getBotForAccount($account);
  }

  /**
   * Loads the WhatsApp account associated with a conversation.
   */
  public function getAccountForConversation(ContentEntityInterface $conversation): ?ContentEntityInterface {
    if (!$conversation->hasField('whatsapp_account') || $conversation->get('whatsapp_account')->isEmpty()) {
      return NULL;
    }

    $account = $conversation->get('whatsapp_account')->entity;

    return $account instanceof ContentEntityInterface ? $account : NULL;
  }

  /**
   * Loads the active bot associated with a WhatsApp account.
   */
  public function getBotForAccount(ContentEntityInterface $account): ?ContentEntityInterface {
    if (!$account->hasField('bot') || $account->get('bot')->isEmpty()) {
      return NULL;
    }

    $bot = $account->get('bot')->entity;
    if (!$bot instanceof ContentEntityInterface) {
      return NULL;
    }

    if ($bot->hasField('status') && $bot->get('status')->value !== 'active') {
      return NULL;
    }

    return $bot;
  }

  /**
   * Loads an active bot by ID.
   */
  public function loadActiveBot(int|string $bot_id): ?ContentEntityInterface {
    $bot = $this->entityTypeManager
      ->getStorage('ai_whatsapp_bot')
      ->load($bot_id);

    if (!$bot instanceof ContentEntityInterface) {
      return NULL;
    }

    if ($bot->hasField('status') && $bot->get('status')->value !== 'active') {
      return NULL;
    }

    return $bot;
  }

  /**
   * Loads an account matching a provider webhook message.
   *
   * @param array<string, mixed> $message
   *   Normalized provider message.
   */
  public function getAccountForProviderMessage(string $provider, array $message): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_account');
    $account_identifier = (string) ($message['account_phone'] ?? '');
    $normalized_identifier = $this->normalizeIdentifier($account_identifier);

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider', $provider)
      ->range(0, 1);

    $active_group = $query->orConditionGroup()
      ->condition('status', 'active')
      ->condition('connection_status', 'CONNECTED');
    $query->condition($active_group);

    if ($account_identifier !== '') {
      $identifier_group = $query->orConditionGroup()
        ->condition('phone_number', $account_identifier)
        ->condition('phone_number', $normalized_identifier)
        ->condition('name', $account_identifier)
        ->condition('evolution_instance_name', $account_identifier)
        ->condition('connected_phone_number', $normalized_identifier);
      $query->condition($identifier_group);
    }

    $ids = $query->execute();
    if ($ids === [] && $account_identifier !== '') {
      $ids = $this->loadActiveAccountIdsForProvider($provider);
    }

    if ($ids === []) {
      return NULL;
    }

    $account = $storage->load(reset($ids));

    return $account instanceof ContentEntityInterface ? $account : NULL;
  }

  /**
   * Loads active account IDs for a provider.
   *
   * This is a fallback for providers that omit or alter the destination
   * identifier in webhook payloads. It is only safe when one account matches.
   *
   * @return array<int|string, int|string>
   *   Matching account IDs.
   */
  private function loadActiveAccountIdsForProvider(string $provider): array {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_account');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider', $provider)
      ->range(0, 2);

    $active_group = $query->orConditionGroup()
      ->condition('status', 'active')
      ->condition('connection_status', 'CONNECTED');
    $query->condition($active_group);

    $ids = $query->execute();

    return count($ids) === 1 ? $ids : [];
  }

  /**
   * Returns the effective system prompt for a bot in an account.
   */
  public function getEffectivePrompt(ContentEntityInterface $bot, ?ContentEntityInterface $account = NULL): string {
    $override = $account instanceof ContentEntityInterface ? $this->getFieldValue($account, 'prompt_override') : '';

    return $override !== '' ? $override : $this->getFieldValue($bot, 'system_prompt');
  }

  /**
   * Returns the effective OpenAI model for a bot in an account.
   */
  public function getEffectiveModel(ContentEntityInterface $bot, ?ContentEntityInterface $account = NULL): ?string {
    $override = $account instanceof ContentEntityInterface ? $this->getFieldValue($account, 'model_override') : '';
    $model = $override !== '' ? $override : $this->getFieldValue($bot, 'model');

    return $model !== '' ? $model : NULL;
  }

  /**
   * Returns the effective knowledge base for a bot in an account.
   */
  public function getEffectiveKnowledgeBase(ContentEntityInterface $bot, ?ContentEntityInterface $account = NULL): ?ContentEntityInterface {
    if ($account instanceof ContentEntityInterface && $account->hasField('knowledge_base') && !$account->get('knowledge_base')->isEmpty()) {
      $knowledge_base = $account->get('knowledge_base')->entity;
      if ($knowledge_base instanceof ContentEntityInterface && $this->isActive($knowledge_base)) {
        return $knowledge_base;
      }
    }

    if ($bot->hasField('knowledge_base') && !$bot->get('knowledge_base')->isEmpty()) {
      $knowledge_base = $bot->get('knowledge_base')->entity;
      if ($knowledge_base instanceof ContentEntityInterface && $this->isActive($knowledge_base)) {
        return $knowledge_base;
      }
    }

    return NULL;
  }

  /**
   * Checks whether an entity with a status field is active.
   */
  private function isActive(ContentEntityInterface $entity): bool {
    return !$entity->hasField('status') || $entity->get('status')->value === 'active';
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

  /**
   * Normalizes provider account identifiers for matching.
   */
  private function normalizeIdentifier(string $identifier): string {
    $identifier = preg_replace('/^whatsapp:/', '', trim($identifier)) ?? '';
    $identifier = preg_replace('/@.+$/', '', $identifier) ?? '';

    return preg_replace('/\D+/', '', $identifier) ?? '';
  }

}
