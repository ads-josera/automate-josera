<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Webhook;

use Drupal\ai_whatsapp_automation\Application\AI\BotManagerService;
use Drupal\ai_whatsapp_automation\Application\AI\ConversationEngineService;
use Drupal\ai_whatsapp_automation\Application\Lead\LeadHandoffService;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes normalized webhook messages.
 */
final class WebhookProcessorService {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a WebhookProcessorService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BotManagerService $botManager,
    private readonly ConversationEngineService $conversationEngine,
    private readonly ProviderMessageSenderService $messageSender,
    private readonly LeadHandoffService $leadHandoff,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Processes a queue item.
   *
   * @param array<string, mixed> $item
   *   Queue item data.
   *
   * @return array<string, mixed>
   *   Processing result.
   */
  public function process(array $item): array {
    $provider = (string) ($item['provider'] ?? '');
    $message = is_array($item['message'] ?? NULL) ? $item['message'] : [];
    $lock_name = $this->processingLockName($provider, (string) ($message['provider_message_id'] ?? ''));
    if ($lock_name !== '' && !$this->lock->acquire($lock_name, 90.0)) {
      $this->logger->notice('Webhook message @message is already being processed.', [
        '@message' => (string) ($message['provider_message_id'] ?? ''),
      ]);

      return ['status' => 'ignored_in_progress'];
    }

    try {
      return $this->processLocked($provider, $message);
    }
    finally {
      if ($lock_name !== '') {
        $this->lock->release($lock_name);
      }
    }
  }

  /**
   * Processes a webhook message while its provider ID lock is held.
   *
   * @param array<string, mixed> $message
   *   Normalized message data.
   *
   * @return array<string, mixed>
   *   Processing result.
   */
  private function processLocked(string $provider, array $message): array {
    $duplicate = $this->loadInboundProviderMessage((string) ($message['provider_message_id'] ?? ''));
    if ($duplicate instanceof ContentEntityInterface) {
      $resumed = $this->resumeDuplicateProviderMessage($provider, $message, $duplicate);
      if ($resumed !== NULL) {
        return $resumed;
      }
    }

    $is_new_conversation = FALSE;
    $conversation = $this->loadOrCreateConversation($provider, $message, $is_new_conversation);
    $this->touchConversation($conversation);

    if ($this->isNotificationRecipient($conversation, $message)) {
      return $this->blockNotificationRecipient($conversation, $provider, $message);
    }

    if ($conversation->hasField('status') && $conversation->get('status')->value !== 'AI_ACTIVE') {
      $incoming = $this->saveIncomingMessage($conversation, $message);
      $this->logger->notice('Webhook message @message was saved without AI processing because conversation @conversation is @status.', [
        '@message' => (string) $incoming->id(),
        '@conversation' => (string) $conversation->id(),
        '@status' => (string) $conversation->get('status')->value,
      ]);

      return [
        'status' => 'saved_without_ai',
        'conversation_id' => $conversation->id(),
        'incoming_message_id' => $incoming->id(),
      ];
    }

    $bot = $this->botManager->getBotForConversation($conversation);
    if (!$bot instanceof ContentEntityInterface) {
      $incoming = $this->saveIncomingMessage($conversation, $message);
      $this->logger->warning('Webhook message @message was saved without AI processing because no active bot is associated. Conversation: @conversation. Account: @account. Provider: @provider. Phone: @phone. Account phone: @account_phone.', [
        '@message' => (string) $incoming->id(),
        '@conversation' => (string) $conversation->id(),
        '@account' => $conversation->hasField('whatsapp_account') && !$conversation->get('whatsapp_account')->isEmpty()
          ? (string) $conversation->get('whatsapp_account')->target_id
          : 'none',
        '@provider' => $provider,
        '@phone' => (string) ($message['phone'] ?? ''),
        '@account_phone' => (string) ($message['account_phone'] ?? ''),
      ]);

      return [
        'status' => 'saved_without_bot',
        'conversation_id' => $conversation->id(),
        'incoming_message_id' => $incoming->id(),
      ];
    }

    $limit_reason = $this->whatsAppLimitReason($bot, $conversation, $is_new_conversation);
    if ($limit_reason !== '') {
      $incoming = $this->saveIncomingMessage($conversation, $message);
      $this->logger->warning('Skipped AI processing for WhatsApp conversation @conversation because @reason.', [
        '@conversation' => (string) $conversation->id(),
        '@reason' => $limit_reason,
      ]);

      return [
        'status' => 'saved_usage_limited',
        'reason' => $limit_reason,
        'conversation_id' => $conversation->id(),
        'incoming_message_id' => $incoming->id(),
      ];
    }

    $this->markAiProcessingStarted($provider, (string) ($message['provider_message_id'] ?? ''));
    $engine_result = $this->conversationEngine->processIncomingMessage($conversation, (string) $message['body'], [
      'provider_message_id' => (string) ($message['provider_message_id'] ?? ''),
    ]);
    $this->clearAiProcessingStarted($provider, (string) ($message['provider_message_id'] ?? ''));

    return $this->deliverEngineResult($provider, $message, $conversation, $engine_result);
  }

  /**
   * Blocks incoming messages from an administrator notification recipient.
   *
   * These numbers receive lead alerts only and must never enter an AI flow.
   *
   * @param array<string, mixed> $message
   *   Normalized incoming message.
   *
   * @return array<string, mixed>
   *   Processing result.
   */
  private function blockNotificationRecipient(ContentEntityInterface $conversation, string $provider, array $message): array {
    $incoming = $this->saveIncomingMessage($conversation, $message);
    if ($conversation->hasField('status') && $conversation->get('status')->value !== 'HUMAN_ASSIGNED') {
      $conversation->set('status', 'HUMAN_ASSIGNED');
      $conversation->save();
    }

    $delivery = ['status' => 'skipped_rate_limited'];
    $reply = $this->notificationRecipientReply($conversation);
    if ($reply !== '' && $this->canSendNotificationRecipientReply($conversation, $message)) {
      $outbound_message = $message + [
        'whatsapp_account_id' => $conversation->hasField('whatsapp_account') ? $conversation->get('whatsapp_account')->target_id : NULL,
      ];
      $delivery = $this->messageSender->sendText($provider, $outbound_message, $reply);
      if (($delivery['status'] ?? '') === 'sent') {
        $this->markNotificationRecipientReplySent($conversation, $message);
      }
    }

    $this->logger->notice('Blocked AI processing for notification recipient @phone in conversation @conversation.', [
      '@phone' => (string) ($message['phone'] ?? ''),
      '@conversation' => (string) $conversation->id(),
    ]);

    return [
      'status' => 'blocked_notification_recipient',
      'conversation_id' => $conversation->id(),
      'incoming_message_id' => $incoming->id(),
      'delivery' => $delivery,
    ];
  }

  /**
   * Returns whether the sender is configured to receive lead notifications.
   *
   * @param array<string, mixed> $message
   *   Normalized incoming message.
   */
  private function isNotificationRecipient(ContentEntityInterface $conversation, array $message): bool {
    $account = $this->botManager->getAccountForConversation($conversation);
    if (!$account instanceof ContentEntityInterface) {
      return FALSE;
    }

    $sender = $this->normalizePhone((string) ($message['phone'] ?? ''));
    if ($sender === '') {
      return FALSE;
    }

    foreach ($this->notificationNumbers($account) as $number) {
      if ($sender === $this->normalizePhone($number)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns whether the 24-hour notice cooldown has elapsed.
   *
   * @param array<string, mixed> $message
   *   Normalized incoming message.
   */
  private function canSendNotificationRecipientReply(ContentEntityInterface $conversation, array $message): bool {
    return (int) $this->state->get($this->notificationRecipientReplyStateKey($conversation, $message), 0) <= time() - 86400;
  }

  /**
   * Records a successfully delivered system-only reply.
   *
   * @param array<string, mixed> $message
   *   Normalized incoming message.
   */
  private function markNotificationRecipientReplySent(ContentEntityInterface $conversation, array $message): void {
    $this->state->set($this->notificationRecipientReplyStateKey($conversation, $message), time());
  }

  /**
   * Builds a non-identifying state key for the recipient reply cooldown.
   *
   * @param array<string, mixed> $message
   *   Normalized incoming message.
   */
  private function notificationRecipientReplyStateKey(ContentEntityInterface $conversation, array $message): string {
    return 'ai_whatsapp_automation.notification_recipient_reply.' . hash('sha256', implode(':', [
      (string) $conversation->id(),
      $this->normalizePhone((string) ($message['phone'] ?? '')),
    ]));
  }

  /**
   * Returns account-specific notification numbers or the global fallback.
   *
   * @return string[]
   *   Configured WhatsApp numbers.
   */
  private function notificationNumbers(ContentEntityInterface $account): array {
    $raw = $this->fieldValue($account, 'lead_notification_numbers');
    if ($raw === '') {
      $raw = (string) $this->setting('options.lead_notification_numbers');
    }

    return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: [])));
  }

  /**
   * Returns the bot-specific notification-recipient reply or global fallback.
   */
  private function notificationRecipientReply(ContentEntityInterface $conversation): string {
    $bot = $this->botManager->getBotForConversation($conversation);
    if ($bot instanceof ContentEntityInterface) {
      $reply = trim($this->fieldValue($bot, 'notification_recipient_reply_text'));
      if ($reply !== '') {
        return $reply;
      }
    }

    return trim((string) $this->setting('options.notification_recipient_reply_text'));
  }

  /**
   * Reads an option from the module settings.
   */
  private function setting(string $key): mixed {
    return \Drupal::config('ai_whatsapp_automation.settings')->get($key);
  }

  /**
   * Reads a scalar entity field value.
   */
  private function fieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    return (string) $entity->get($field_name)->value;
  }

  /**
   * Normalizes Mexican WhatsApp variants for equality checks.
   */
  private function normalizePhone(string $phone): string {
    $phone = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($phone, '521') && strlen($phone) === 13) {
      $phone = '52' . substr($phone, 3);
    }

    return $phone;
  }

  /**
   * Loads or creates a conversation for the incoming message.
   *
   * @param array<string, mixed> $message
   *   Normalized message data.
   */
  private function loadOrCreateConversation(string $provider, array $message, bool &$is_new_conversation): ContentEntityInterface {
    $account = $this->botManager->getAccountForProviderMessage($provider, $message);
    $conversation_storage = $this->entityTypeManager->getStorage('ai_whatsapp_conversation');

    $query = $conversation_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('phone', (string) $message['phone'])
      ->condition('provider', $provider)
      ->condition('status', 'CLOSED', '<>')
      ->sort('changed', 'DESC')
      ->range(0, 1);

    if ($account instanceof ContentEntityInterface) {
      $query->condition('whatsapp_account', $account->id());
    }

    $ids = $query->execute();
    if ($ids !== []) {
      $conversation = $conversation_storage->load(reset($ids));
      if ($conversation instanceof ContentEntityInterface) {
        if (
          $account instanceof ContentEntityInterface
          && $conversation->hasField('whatsapp_account')
          && $conversation->get('whatsapp_account')->isEmpty()
        ) {
          $conversation->set('whatsapp_account', $account->id());
          $conversation->save();
        }
        if (
          $account instanceof ContentEntityInterface
          && $conversation->hasField('bot')
          && $conversation->get('bot')->isEmpty()
          && $account->hasField('bot')
          && !$account->get('bot')->isEmpty()
        ) {
          $conversation->set('bot', $account->get('bot')->target_id);
          $conversation->save();
        }
        return $conversation;
      }
    }

    // A conversation closed by the inactivity timer has no operator close
    // audit. Resume it so a returning contact keeps the collected context.
    $closed_conversation = $this->findReopenableClosedConversation($provider, $message, $account);
    if ($closed_conversation instanceof ContentEntityInterface) {
      $closed_conversation->set('status', 'AI_ACTIVE');
      $closed_conversation->save();
      $this->logger->notice('Resumed inactive conversation @conversation for returning contact.', [
        '@conversation' => (string) $closed_conversation->id(),
      ]);

      return $closed_conversation;
    }

    $conversation = $conversation_storage->create([
      'phone' => (string) $message['phone'],
      'channel' => 'whatsapp',
      'provider' => $provider,
      'status' => 'AI_ACTIVE',
      'whatsapp_account' => $account instanceof ContentEntityInterface ? $account->id() : NULL,
      'bot' => $account instanceof ContentEntityInterface && $account->hasField('bot') ? $account->get('bot')->target_id : NULL,
    ]);
    $conversation->save();
    $is_new_conversation = TRUE;

    return $conversation;
  }

  /**
   * Returns a limit reason when WhatsApp AI processing must be skipped.
   */
  private function whatsAppLimitReason(ContentEntityInterface $bot, ContentEntityInterface $conversation, bool $is_new_conversation): string {
    $now = time();
    $message_limit = $this->integerField($bot, 'whatsapp_message_limit', 20);
    $window_minutes = $this->integerField($bot, 'whatsapp_message_window_minutes', 15);
    if ($message_limit > 0 && $window_minutes > 0) {
      $count = $this->entityTypeManager->getStorage('ai_whatsapp_message')->getQuery()
        ->accessCheck(FALSE)
        ->condition('conversation', $conversation->id())
        ->condition('sender', 'contact')
        ->condition('created', $now - ($window_minutes * 60), '>=')
        ->count()
        ->execute();
      if ((int) $count >= $message_limit) {
        return 'the contact message limit has been reached';
      }
    }

    $day_start = (new \DateTimeImmutable('today'))->getTimestamp();
    $daily_conversation_limit = $this->integerField($bot, 'whatsapp_daily_conversation_limit', 100);
    if ($is_new_conversation && $daily_conversation_limit > 0) {
      $count = $this->entityTypeManager->getStorage('ai_whatsapp_conversation')->getQuery()
        ->accessCheck(FALSE)
        ->condition('bot', $bot->id())
        ->condition('channel', 'whatsapp')
        ->condition('created', $day_start, '>=')
        ->count()
        ->execute();
      if ((int) $count > $daily_conversation_limit) {
        return 'the daily WhatsApp conversation limit has been reached';
      }
    }

    $daily_budget = $this->decimalField($bot, 'whatsapp_daily_budget', 3.00);
    if ($daily_budget > 0 && $this->dailyEstimatedCost((int) $bot->id(), $day_start) >= $daily_budget) {
      return 'the daily WhatsApp budget has been reached';
    }

    return '';
  }

  /**
   * Returns estimated OpenAI cost accrued by a bot's WhatsApp chats today.
   */
  private function dailyEstimatedCost(int $bot_id, int $day_start): float {
    $query = $this->database->select('ai_whatsapp_message', 'message');
    $query->join('ai_whatsapp_conversation', 'conversation', 'conversation.id = message.conversation');
    $query->leftJoin('ai_whatsapp_account', 'account', 'account.id = conversation.whatsapp_account');
    $query->addExpression('COALESCE(SUM(message.cost), 0)', 'total_cost');
    $bot_group = $query->orConditionGroup()
      ->condition('conversation.bot', $bot_id)
      ->condition('account.bot', $bot_id);
    $query->condition($bot_group);
    $query->condition('conversation.provider', ['twilio', 'cloud_api', 'evolution'], 'IN');
    $query->condition('message.sender', 'ai');
    $query->condition('message.created', $day_start, '>=');

    return (float) $query->execute()->fetchField();
  }

  /**
   * Reads an integer bot field with a safe fallback default.
   */
  private function integerField(ContentEntityInterface $bot, string $field_name, int $default): int {
    $value = $this->fieldValue($bot, $field_name);

    return $value === '' ? $default : max(0, (int) $value);
  }

  /**
   * Reads a decimal bot field with a safe fallback default.
   */
  private function decimalField(ContentEntityInterface $bot, string $field_name, float $default): float {
    $value = $this->fieldValue($bot, $field_name);

    return $value === '' ? $default : max(0, (float) $value);
  }

  /**
   * Finds a conversation closed automatically, never one closed by an agent.
   */
  private function findReopenableClosedConversation(string $provider, array $message, ?ContentEntityInterface $account): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_conversation');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('phone', (string) $message['phone'])
      ->condition('provider', $provider)
      ->condition('status', 'CLOSED')
      ->sort('changed', 'DESC')
      ->range(0, 5);

    if ($account instanceof ContentEntityInterface) {
      $query->condition('whatsapp_account', $account->id());
    }

    foreach ($storage->loadMultiple($query->execute()) as $conversation) {
      if (!$conversation instanceof ContentEntityInterface || $this->wasClosedByOperator($conversation)) {
        continue;
      }

      return $conversation;
    }

    return NULL;
  }

  /**
   * Returns whether an operator intentionally closed the conversation.
   */
  private function wasClosedByOperator(ContentEntityInterface $conversation): bool {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_operator_action')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->condition('action', 'CONVERSATION_CLOSED')
      ->range(0, 1)
      ->execute();

    return $ids !== [];
  }

  /**
   * Marks a conversation as recently active.
   */
  private function touchConversation(ContentEntityInterface $conversation): void {
    if (!$conversation->hasField('changed')) {
      return;
    }

    $conversation->set('changed', time());
    $conversation->save();
  }

  /**
   * Resumes a webhook message that was stored before a transient failure.
   *
   * @param array<string, mixed> $message
   *   Normalized message data.
   *
   * @return array<string, mixed>|null
   *   A resumed result, or NULL when normal processing should continue.
   */
  private function resumeDuplicateProviderMessage(string $provider, array $message, ContentEntityInterface $incoming): ?array {
    $conversation = $this->entityTypeManager
      ->getStorage('ai_whatsapp_conversation')
      ->load($incoming->get('conversation')->target_id);
    if (!$conversation instanceof ContentEntityInterface) {
      return NULL;
    }

    $outgoing = $this->findOutgoingResponse($incoming);
    if (!$outgoing instanceof ContentEntityInterface) {
      if (!$this->isAiProcessingStarted($provider, (string) ($message['provider_message_id'] ?? ''))) {
        $this->logger->notice('Skipped duplicate @provider webhook message @message with no pending AI work.', [
          '@provider' => $provider,
          '@message' => (string) $message['provider_message_id'],
        ]);

        return ['status' => 'ignored_duplicate'];
      }
      $this->logger->notice('Resuming saved @provider webhook message @message before AI response.', [
        '@provider' => $provider,
        '@message' => (string) $message['provider_message_id'],
      ]);
      $engine_result = $this->conversationEngine->processSavedIncomingMessage($conversation, $incoming);
      $this->clearAiProcessingStarted($provider, (string) ($message['provider_message_id'] ?? ''));

      return $this->deliverEngineResult($provider, $message, $conversation, $engine_result);
    }

    if ($this->wasProviderDeliverySuccessful($provider, (string) ($message['provider_message_id'] ?? ''))) {
      $this->logger->notice('Skipped duplicate @provider webhook message @message.', [
        '@provider' => $provider,
        '@message' => (string) $message['provider_message_id'],
      ]);

      return ['status' => 'ignored_duplicate'];
    }

    $this->logger->notice('Retrying provider delivery for duplicate @provider webhook message @message.', [
      '@provider' => $provider,
      '@message' => (string) $message['provider_message_id'],
    ]);

    return $this->deliverEngineResult($provider, $message, $conversation, [
      'conversation_id' => $conversation->id(),
      'incoming_message_id' => $incoming->id(),
      'outgoing_message_id' => $outgoing->id(),
      'response_text' => (string) $outgoing->get('content')->value,
      'resumed_delivery' => TRUE,
    ]);
  }

  /**
   * Delivers a generated response and records a successful provider send.
   *
   * @param array<string, mixed> $message
   *   Normalized message data.
   * @param array<string, mixed> $engine_result
   *   Generated or resumed response details.
   *
   * @return array<string, mixed>
   *   Processing result.
   */
  private function deliverEngineResult(string $provider, array $message, ContentEntityInterface $conversation, array $engine_result): array {
    $outbound_message = $message + [
      'whatsapp_account_id' => $conversation->hasField('whatsapp_account') ? $conversation->get('whatsapp_account')->target_id : NULL,
    ];
    $delivery = $this->messageSender->sendText($provider, $outbound_message, (string) $engine_result['response_text']);
    if (($delivery['status'] ?? '') !== 'sent') {
      throw new \RuntimeException('Provider delivery failed: ' . (string) ($delivery['error'] ?? $delivery['status'] ?? 'unknown error'));
    }

    $this->markProviderDeliverySuccessful($provider, (string) ($message['provider_message_id'] ?? ''));
    $handoff = $this->leadHandoff->handle($conversation, (string) $engine_result['response_text']);
    $this->logger->notice('Lead handoff check for conversation @conversation finished with status @status.', [
      '@conversation' => (string) $conversation->id(),
      '@status' => (string) ($handoff['status'] ?? 'unknown'),
    ]);

    return $engine_result + [
      'delivery' => $delivery,
      'handoff' => $handoff,
    ];
  }

  /**
   * Saves an incoming message without AI processing.
   *
   * @param array<string, mixed> $message
   *   Normalized message data.
   */
  private function saveIncomingMessage(ContentEntityInterface $conversation, array $message): ContentEntityInterface {
    $incoming = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->create([
        'conversation' => $conversation->id(),
        'sender' => 'contact',
        'content' => (string) ($message['body'] ?? ''),
        'tokens' => 0,
        'cost' => '0.000000',
        'provider_message_id' => (string) ($message['provider_message_id'] ?? ''),
      ]);
    $incoming->save();

    return $incoming;
  }

  /**
   * Loads a previously stored inbound provider message, when present.
   */
  private function loadInboundProviderMessage(string $provider_message_id): ?ContentEntityInterface {
    $provider_message_id = trim($provider_message_id);
    if ($provider_message_id === '') {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_message');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider_message_id', $provider_message_id)
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $incoming = $storage->load(reset($ids));
    if (!$incoming instanceof ContentEntityInterface || $incoming->get('sender')->value !== 'contact') {
      return NULL;
    }

    return $incoming;
  }

  /**
   * Finds the AI response associated with a stored inbound message.
   */
  private function findOutgoingResponse(ContentEntityInterface $incoming): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_message');
    $response_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $incoming->get('conversation')->target_id)
      ->condition('sender', 'ai')
      ->condition('created', $incoming->get('created')->value, '>=')
      ->sort('created', 'ASC')
      ->range(0, 1)
      ->execute();

    if ($response_ids === []) {
      return NULL;
    }

    $outgoing = $storage->load(reset($response_ids));

    return $outgoing instanceof ContentEntityInterface ? $outgoing : NULL;
  }

  /**
   * Builds the per-message lock name used by the HTTP endpoint and queue.
   */
  private function processingLockName(string $provider, string $provider_message_id): string {
    $provider_message_id = trim($provider_message_id);
    if ($provider_message_id === '') {
      return '';
    }

    return 'ai_whatsapp_automation.webhook.' . hash('sha256', $provider . ':' . $provider_message_id);
  }

  /**
   * Returns whether the generated response was accepted by its provider.
   */
  private function wasProviderDeliverySuccessful(string $provider, string $provider_message_id): bool {
    $provider_message_id = trim($provider_message_id);
    if ($provider_message_id === '') {
      return FALSE;
    }

    return (bool) $this->state->get('ai_whatsapp_automation.webhook_delivery.' . hash('sha256', $provider . ':' . $provider_message_id), FALSE);
  }

  /**
   * Marks an inbound provider message as having a successful response send.
   */
  private function markProviderDeliverySuccessful(string $provider, string $provider_message_id): void {
    $provider_message_id = trim($provider_message_id);
    if ($provider_message_id === '') {
      return;
    }

    $this->state->set('ai_whatsapp_automation.webhook_delivery.' . hash('sha256', $provider . ':' . $provider_message_id), time());
  }

  /**
   * Marks a saved inbound message as awaiting an AI response.
   */
  private function markAiProcessingStarted(string $provider, string $provider_message_id): void {
    $key = $this->aiProcessingStateKey($provider, $provider_message_id);
    if ($key !== '') {
      $this->state->set($key, time());
    }
  }

  /**
   * Returns whether the saved inbound message can safely resume AI work.
   */
  private function isAiProcessingStarted(string $provider, string $provider_message_id): bool {
    $key = $this->aiProcessingStateKey($provider, $provider_message_id);

    return $key !== '' && (int) $this->state->get($key, 0) > 0;
  }

  /**
   * Clears the pending AI marker after a response has been generated.
   */
  private function clearAiProcessingStarted(string $provider, string $provider_message_id): void {
    $key = $this->aiProcessingStateKey($provider, $provider_message_id);
    if ($key !== '') {
      $this->state->delete($key);
    }
  }

  /**
   * Builds the state key used to resume a failed AI request.
   */
  private function aiProcessingStateKey(string $provider, string $provider_message_id): string {
    $provider_message_id = trim($provider_message_id);
    if ($provider_message_id === '') {
      return '';
    }

    return 'ai_whatsapp_automation.webhook_ai_pending.' . hash('sha256', $provider . ':' . $provider_message_id);
  }

}
