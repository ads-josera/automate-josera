<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Webhook;

use Drupal\ai_whatsapp_automation\Application\AI\BotManagerService;
use Drupal\ai_whatsapp_automation\Application\AI\ConversationEngineService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
    $conversation = $this->loadOrCreateConversation($provider, $message);
    $this->touchConversation($conversation);

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

    $engine_result = $this->conversationEngine->processIncomingMessage($conversation, (string) $message['body'], [
      'provider_message_id' => (string) ($message['provider_message_id'] ?? ''),
    ]);

    $delivery = $this->messageSender->sendText($provider, $message, (string) $engine_result['response_text']);

    return $engine_result + [
      'delivery' => $delivery,
    ];
  }

  /**
   * Loads or creates a conversation for the incoming message.
   *
   * @param array<string, mixed> $message
   *   Normalized message data.
   */
  private function loadOrCreateConversation(string $provider, array $message): ContentEntityInterface {
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
        return $conversation;
      }
    }

    $conversation = $conversation_storage->create([
      'phone' => (string) $message['phone'],
      'channel' => 'whatsapp',
      'provider' => $provider,
      'status' => 'AI_ACTIVE',
      'whatsapp_account' => $account instanceof ContentEntityInterface ? $account->id() : NULL,
    ]);
    $conversation->save();

    return $conversation;
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

}
