<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

use Drupal\ai_whatsapp_automation\Application\OpenAI\OpenAIServiceInterface;
use Drupal\ai_whatsapp_automation\Exception\OpenAIServiceException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates AI responses for WhatsApp conversations.
 */
final class ConversationEngineService {

  /**
   * Unreadable messages in a row after which the bot stops replying.
   *
   * Someone who cannot be understood twice is either an automated sender or a
   * person who will not be helped by a third identical answer. Staying silent
   * costs nothing and does not burn the 24-hour WhatsApp session window.
   */
  private const UNINTELLIGIBLE_REPLY_LIMIT = 2;

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a ConversationEngineService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BotManagerService $botManager,
    private readonly PromptBuilderService $promptBuilder,
    private readonly OpenAIServiceInterface $openAIService,
    private readonly UnintelligibleMessageDetector $unintelligibleDetector,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Processes an incoming WhatsApp message.
   *
   * @return array<string, mixed>
   *   Engine result with generated text and saved message IDs.
   */
  public function processIncomingMessage(
    ContentEntityInterface $conversation,
    string $incoming_message,
    array $context = [],
  ): array {
    $incoming_message = trim($incoming_message);
    if ($incoming_message === '') {
      throw new OpenAIServiceException('The incoming message cannot be empty.');
    }

    $bot = $this->botManager->getBotForConversation($conversation);
    if (!$bot instanceof ContentEntityInterface) {
      throw new OpenAIServiceException('No active bot is associated with this conversation.');
    }

    $incoming = $this->saveMessage($conversation, [
      'sender' => (string) ($context['sender'] ?? 'contact'),
      'content' => $incoming_message,
      'provider_message_id' => (string) ($context['provider_message_id'] ?? ''),
    ]);

    return $this->processSavedIncomingMessage($conversation, $incoming);
  }

  /**
   * Generates a response for an incoming message that is already stored.
   *
   * This is used to resume a webhook after a transient failure without
   * persisting the provider message twice.
   *
   * @return array<string, mixed>
   *   Engine result with generated text and saved message IDs.
   */
  public function processSavedIncomingMessage(
    ContentEntityInterface $conversation,
    ContentEntityInterface $incoming,
  ): array {
    $incoming_message = trim((string) $incoming->get('content')->value);
    if ($incoming_message === '') {
      throw new OpenAIServiceException('The incoming message cannot be empty.');
    }

    $bot = $this->botManager->getBotForConversation($conversation);
    if (!$bot instanceof ContentEntityInterface) {
      throw new OpenAIServiceException('No active bot is associated with this conversation.');
    }

    if ($this->unintelligibleDetector->isUnintelligible($incoming_message)) {
      return $this->answerUnintelligible($conversation, $bot, $incoming);
    }

    $prompt_data = $this->promptBuilder->build($bot, $conversation, $incoming_message);
    $response = $this->openAIService->sendPrompt(
      (string) $prompt_data['prompt'],
      $prompt_data['model'] ?? NULL,
      is_array($prompt_data['options'] ?? NULL) ? $prompt_data['options'] : [],
    );

    $outgoing = $this->saveMessage($conversation, [
      'sender' => 'ai',
      'content' => (string) ($response['text'] ?? ''),
      'tokens' => (int) ($response['usage']['total_tokens'] ?? 0),
      'cost' => (string) ($response['cost']['estimated_cost'] ?? '0.000000'),
      'provider_message_id' => (string) ($response['id'] ?? ''),
    ]);

    $this->logger->info('Conversation @conversation processed with bot @bot.', [
      '@conversation' => (string) $conversation->id(),
      '@bot' => (string) $bot->id(),
    ]);

    return [
      'conversation_id' => $conversation->id(),
      'bot_id' => $bot->id(),
      'incoming_message_id' => $incoming->id(),
      'outgoing_message_id' => $outgoing->id(),
      'response_text' => (string) ($response['text'] ?? ''),
      'delivery_status' => 'pending_provider_delivery',
      'openai' => $response,
    ];
  }

  /**
   * Answers a message that carries no readable request, without the model.
   *
   * The first one gets a short invitation to write again, the second one an
   * invitation with a way out, and from the third the bot says nothing at
   * all. Whatever is stored here is a normal message, so the operator reads
   * the same transcript the contact sees.
   *
   * @return array<string, mixed>
   *   Engine result. The response text is empty once the bot falls silent.
   */
  private function answerUnintelligible(
    ContentEntityInterface $conversation,
    ContentEntityInterface $bot,
    ContentEntityInterface $incoming,
  ): array {
    $preceding = $this->precedingUnintelligibleCount($conversation, $incoming);
    $reply = $preceding < self::UNINTELLIGIBLE_REPLY_LIMIT
      ? $this->unintelligibleReply($bot, $preceding)
      : '';

    $outgoing = NULL;
    if ($reply !== '') {
      $outgoing = $this->saveMessage($conversation, [
        'sender' => 'ai',
        'content' => $reply,
      ]);
    }

    $this->logger->info('Message @message in conversation @conversation carried no readable request; it was answered without the model (@count in a row).', [
      '@message' => (string) $incoming->id(),
      '@conversation' => (string) $conversation->id(),
      '@count' => (string) ($preceding + 1),
    ]);

    return [
      'conversation_id' => $conversation->id(),
      'bot_id' => $bot->id(),
      'incoming_message_id' => $incoming->id(),
      'outgoing_message_id' => $outgoing?->id(),
      'response_text' => $reply,
      'unintelligible' => TRUE,
      'delivery_status' => $reply === '' ? 'no_reply' : 'pending_provider_delivery',
    ];
  }

  /**
   * Counts the unreadable contact messages immediately before this one.
   *
   * Only the unbroken run matters: one readable message resets the ladder, so
   * a contact who was misread once starts over with a full answer.
   */
  private function precedingUnintelligibleCount(
    ContentEntityInterface $conversation,
    ContentEntityInterface $incoming,
  ): int {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('conversation', $conversation->id())
      ->condition('sender', 'contact')
      ->condition('id', $incoming->id(), '<')
      ->sort('id', 'DESC')
      ->range(0, self::UNINTELLIGIBLE_REPLY_LIMIT)
      ->execute();

    if ($ids === []) {
      return 0;
    }

    $messages = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->loadMultiple($ids);
    // loadMultiple() returns entities keyed by ID in ascending order, while
    // the walk has to start at the newest one.
    krsort($messages);

    $count = 0;
    foreach ($messages as $message) {
      if (!$message instanceof ContentEntityInterface) {
        break;
      }
      if (!$this->unintelligibleDetector->isUnintelligible((string) $message->get('content')->value)) {
        break;
      }
      $count++;
    }

    return $count;
  }

  /**
   * Returns the bot's reply for this step of the ladder, or the global one.
   */
  private function unintelligibleReply(ContentEntityInterface $bot, int $preceding): string {
    $key = $preceding === 0 ? 'unintelligible_reply_text' : 'unintelligible_second_reply_text';
    if ($bot->hasField($key) && !$bot->get($key)->isEmpty()) {
      $reply = trim((string) $bot->get($key)->value);
      if ($reply !== '') {
        return $reply;
      }
    }

    return trim((string) $this->configFactory
      ->get('ai_whatsapp_automation.settings')
      ->get('options.' . $key));
  }

  /**
   * Saves a conversation message.
   *
   * @param array<string, mixed> $values
   *   Message values.
   */
  private function saveMessage(ContentEntityInterface $conversation, array $values): ContentEntityInterface {
    $message = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->create([
        'conversation' => $conversation->id(),
        'sender' => $values['sender'],
        'content' => $values['content'],
        'tokens' => $values['tokens'] ?? 0,
        'cost' => $values['cost'] ?? '0.000000',
        'provider_message_id' => $values['provider_message_id'] ?? '',
      ]);
    $message->save();

    return $message;
  }

}
