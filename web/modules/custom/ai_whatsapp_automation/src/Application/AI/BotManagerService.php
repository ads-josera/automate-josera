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
    // An account with owner routing decides from the sender on every
    // message, ignoring the bot the conversation stored: the owner bot must
    // never answer anyone else, whatever a conversation was created with.
    $account = $this->getAccountForConversation($conversation);
    if ($account instanceof ContentEntityInterface && $this->hasOwnerRouting($account)) {
      $phone = $conversation->hasField('phone') ? (string) $conversation->get('phone')->value : '';
      return $this->botForSender($account, $phone);
    }

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
   * Returns the bot that answers a sender on an account.
   *
   * Without owner routing it is the account's bot. With it, an owner number
   * gets the owner bot and anybody else the account's bot, and the owner bot
   * never answers a non-owner: if it is also the account's bot, the
   * stranger gets no answer rather than the private bot.
   */
  public function botForSender(ContentEntityInterface $account, string $phone): ?ContentEntityInterface {
    $account_bot = $this->getBotForAccount($account);
    if (!$this->hasOwnerRouting($account)) {
      return $account_bot;
    }

    $owner_bot = $account->get('owner_bot')->entity;
    if ($this->isOwner($account, $phone)) {
      return $owner_bot instanceof ContentEntityInterface && $this->isActive($owner_bot) ? $owner_bot : $account_bot;
    }

    if ($account_bot instanceof ContentEntityInterface && $account_bot->id() === $owner_bot?->id()) {
      $this->loggerFactory->get('ai_whatsapp_automation')->warning('Account @account uses its owner bot as its public bot too; a message from a non-owner number was left unanswered.', [
        '@account' => (string) $account->id(),
      ]);
      return NULL;
    }

    return $account_bot;
  }

  /**
   * Whether an account's prompt, model and knowledge base apply to a bot.
   *
   * They do, except to the owner bot of an owner-routed account: those
   * account settings belong to the public side, and the owner bot keeps its
   * own, so the public prompt or model never replaces the owner bot's. The
   * owner's private knowledge base must therefore live on the owner bot,
   * never on the account, where the public bot would use it; the account
   * form refuses the owner bot's knowledge base on the account.
   */
  private function accountOverridesApply(ContentEntityInterface $bot, ?ContentEntityInterface $account): bool {
    if (!$account instanceof ContentEntityInterface) {
      return FALSE;
    }
    if (!$this->hasOwnerRouting($account)) {
      return TRUE;
    }

    return (string) $account->get('owner_bot')->target_id !== (string) $bot->id();
  }

  /**
   * Whether an account sends its owner numbers to a separate bot.
   */
  public function hasOwnerRouting(ContentEntityInterface $account): bool {
    return $account->hasField('owner_bot') && !$account->get('owner_bot')->isEmpty();
  }

  /**
   * Whether a sender is one of the account's owner numbers.
   */
  public function isOwner(ContentEntityInterface $account, string $phone): bool {
    $phone = $this->comparablePhone($phone);
    if ($phone === '' || !$account->hasField('owner_numbers')) {
      return FALSE;
    }

    foreach (preg_split('/\R+/', (string) $account->get('owner_numbers')->value) ?: [] as $line) {
      if ($this->comparablePhone($line) === $phone) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Reduces a WhatsApp number to digits that compare reliably.
   *
   * Mexican mobiles arrive as +521… from WhatsApp and are often written
   * +52… by people; both mean the same phone. Numbers without a country code
   * do not compare to anything: an owner list must be unambiguous.
   */
  public function comparablePhone(string $phone): string {
    $digits = $this->normalizeIdentifier($phone);
    if (strlen($digits) < 11) {
      return '';
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
      $digits = '521' . substr($digits, 2);
    }

    return $digits;
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
    $override = $this->accountOverridesApply($bot, $account) ? $this->getFieldValue($account, 'prompt_override') : '';

    return $override !== '' ? $override : $this->getFieldValue($bot, 'system_prompt');
  }

  /**
   * Returns the effective OpenAI model for a bot in an account.
   */
  public function getEffectiveModel(ContentEntityInterface $bot, ?ContentEntityInterface $account = NULL): ?string {
    $override = $this->accountOverridesApply($bot, $account) ? $this->getFieldValue($account, 'model_override') : '';
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
    if ($this->accountOverridesApply($bot, $account) && $account->hasField('knowledge_base') && !$account->get('knowledge_base')->isEmpty()) {
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
