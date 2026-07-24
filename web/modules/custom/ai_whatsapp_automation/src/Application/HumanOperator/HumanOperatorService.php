<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\HumanOperator;

use Drupal\ai_whatsapp_automation\Application\Webhook\ProviderMessageSenderService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles transfer between AI and human operators.
 */
final class HumanOperatorService {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a HumanOperatorService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ProviderMessageSenderService $messageSender,
    private readonly AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Stops AI and marks the conversation for human handling.
   */
  public function stopAi(ContentEntityInterface $conversation, string $note = ''): ContentEntityInterface {
    $conversation->set('status', 'HUMAN_ASSIGNED');
    $conversation->save();
    $this->audit($conversation, 'AI_STOPPED', $note);

    return $conversation;
  }

  /**
   * Assigns a human operator and stops AI.
   */
  public function assignOperator(ContentEntityInterface $conversation, int|string $operator_id, string $note = ''): ContentEntityInterface {
    $conversation->set('assigned_operator', $operator_id);
    $conversation->set('status', 'HUMAN_ASSIGNED');
    $conversation->save();
    $this->audit($conversation, 'OPERATOR_ASSIGNED', $note);

    return $conversation;
  }

  /**
   * Sends and stores a manual operator reply.
   *
   * @return array<string, mixed>
   *   The message and delivery result.
   */
  public function replyManually(ContentEntityInterface $conversation, string $reply, string $note = ''): array {
    $reply = trim($reply);
    if ($reply === '') {
      throw new \InvalidArgumentException('Manual reply cannot be empty.');
    }

    $conversation->set('status', 'HUMAN_ASSIGNED');
    if ($conversation->hasField('assigned_operator') && $conversation->get('assigned_operator')->isEmpty()) {
      $conversation->set('assigned_operator', $this->currentUser->id());
    }
    $conversation->save();

    $message = $this->entityTypeManager
      ->getStorage('ai_whatsapp_message')
      ->create([
        'conversation' => $conversation->id(),
        'sender' => 'operator',
        'content' => $reply,
        'tokens' => 0,
        'cost' => '0.000000',
        'provider_message_id' => '',
      ]);
    $message->save();

    $delivery = $this->messageSender->sendText(
      (string) $conversation->get('provider')->value,
      [
        'phone' => (string) $conversation->get('phone')->value,
        'account_phone' => $this->getAccountPhone($conversation),
        'whatsapp_account_id' => $conversation->hasField('whatsapp_account') ? $conversation->get('whatsapp_account')->target_id : NULL,
      ],
      $reply
    );

    $this->audit($conversation, 'MANUAL_REPLY_SENT', $note !== '' ? $note : 'Message ID: ' . $message->id());

    return [
      'message' => $message,
      'delivery' => $delivery,
    ];
  }

  /**
   * Reactivates AI and clears the assigned operator.
   */
  public function reactivateAi(ContentEntityInterface $conversation, string $note = ''): ContentEntityInterface {
    $conversation->set('status', 'AI_ACTIVE');
    if ($conversation->hasField('assigned_operator')) {
      $conversation->set('assigned_operator', NULL);
    }
    $conversation->save();
    $this->audit($conversation, 'AI_REACTIVATED', $note);

    return $conversation;
  }

  /**
   * Closes a conversation.
   */
  public function closeConversation(ContentEntityInterface $conversation, string $note = ''): ContentEntityInterface {
    $conversation->set('status', 'CLOSED');
    $conversation->save();
    $this->audit($conversation, 'CONVERSATION_CLOSED', $note);

    return $conversation;
  }

  /**
   * Creates an audit record.
   */
  private function audit(ContentEntityInterface $conversation, string $action, string $note = ''): ContentEntityInterface {
    $audit = $this->entityTypeManager
      ->getStorage('ai_whatsapp_operator_action')
      ->create([
        'conversation' => $conversation->id(),
        'user' => $this->currentUser->id(),
        'action' => $action,
        'note' => $note,
      ]);
    $audit->save();

    $this->logger->info('Operator action @action registered for conversation @conversation by user @user.', [
      '@action' => $action,
      '@conversation' => (string) $conversation->id(),
      '@user' => (string) $this->currentUser->id(),
    ]);

    return $audit;
  }

  /**
   * Returns the account phone for provider sending.
   */
  private function getAccountPhone(ContentEntityInterface $conversation): string {
    if (!$conversation->hasField('whatsapp_account') || $conversation->get('whatsapp_account')->isEmpty()) {
      return '';
    }

    $account = $conversation->get('whatsapp_account')->entity;
    if (!$account instanceof ContentEntityInterface || !$account->hasField('phone_number')) {
      return '';
    }

    return (string) $account->get('phone_number')->value;
  }

}
