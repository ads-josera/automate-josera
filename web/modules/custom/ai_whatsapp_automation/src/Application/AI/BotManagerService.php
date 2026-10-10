<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Resolves bots for WhatsApp accounts and conversations.
 */
final class BotManagerService {

  /**
   * Constructs a BotManagerService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Loads the active bot associated with a conversation.
   */
  public function getBotForConversation(ContentEntityInterface $conversation): ?ContentEntityInterface {
    if ($conversation->hasField('bot') && !$conversation->get('bot')->isEmpty()) {
      $bot = $conversation->get('bot')->entity;
      if ($bot instanceof ContentEntityInterface && $this->isActive($bot)) {
        return $bot;
      }
    }

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

    // Only active accounts answer (WhatsAppAccount::answers()). Two are
    // fetched so a duplicate number is reported instead of silently routed
    // to whichever the database returns first.
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider', $provider)
      ->condition('status', 'active')
      ->sort('id')
      ->range(0, 2);

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
    if (count($ids) > 1) {
      $this->loggerFactory->get('ai_whatsapp_automation')->warning('Two active @provider accounts answer @number (IDs @ids); the oldest one received the message. Deactivate one of them.', [
        '@provider' => $provider,
        '@number' => $account_identifier,
        '@ids' => implode(', ', $ids),
      ]);
    }
    if ($ids === [] && $account_identifier !== '') {
      // A number that belongs to a deactivated account was switched off on
      // purpose: it must not fall through to whichever account is left.
      if ($this->isKnownNumber($provider, $account_identifier, $normalized_identifier)) {
        return NULL;
      }
      $ids = $this->loadActiveAccountIdsForProvider($provider);
    }

    if ($ids === []) {
      return NULL;
    }

    $account = $storage->load(reset($ids));

    return $account instanceof ContentEntityInterface ? $account : NULL;
  }

  /**
   * Checks whether any account of a provider, active or not, has a number.
   */
  private function isKnownNumber(string $provider, string $identifier, string $normalized_identifier): bool {
    $query = $this->entityTypeManager->getStorage('ai_whatsapp_account')->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider', $provider)
      ->range(0, 1);
    $numbers = $query->orConditionGroup()
      ->condition('phone_number', $identifier)
      ->condition('phone_number', $normalized_identifier)
      ->condition('phone_number', '+' . $normalized_identifier);
    $query->condition($numbers);

    return $query->execute() !== [];
  }

  /**
   * Returns other active accounts of a provider that answer the same number.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Conflicting accounts, keyed by ID.
   */
  public function otherActiveAccountsWithNumber(string $provider, string $number, int|string|null $exclude_id = NULL): array {
    $number = $this->normalizeIdentifier($number);
    if ($number === '') {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_account');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider', $provider)
      ->condition('status', 'active')
      ->execute();
    unset($ids[$exclude_id ?? '']);

    $conflicts = [];
    foreach ($storage->loadMultiple($ids) as $id => $account) {
      if ($this->normalizeIdentifier((string) $account->get('phone_number')->value) === $number) {
        $conflicts[$id] = $account;
      }
    }

    return $conflicts;
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
      ->condition('status', 'active')
      ->range(0, 2);

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
    if ($override !== '') {
      return $this->normalizeModel($override);
    }

    $model = $this->getFieldValue($bot, 'model');

    return $model !== '' ? $this->normalizeModel($model) : NULL;
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
   * Maps removed model IDs to currently available equivalents.
   */
  private function normalizeModel(string $model): string {
    return match ($model) {
      'gpt-5.5-mini' => 'gpt-5-mini',
      'gpt-5.5' => 'gpt-5.1',
      default => $model,
    };
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
