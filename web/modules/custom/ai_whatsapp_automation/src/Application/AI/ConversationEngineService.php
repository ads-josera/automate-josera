<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

use Drupal\ai_whatsapp_automation\Application\OpenAI\OpenAIServiceInterface;
use Drupal\ai_whatsapp_automation\Exception\OpenAIServiceException;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates AI responses for WhatsApp conversations.
 */
final class ConversationEngineService {

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
