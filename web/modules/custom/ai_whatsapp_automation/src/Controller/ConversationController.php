<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a conversation as an operator-friendly inbox.
 */
final class ConversationController extends ControllerBase {

  /**
   * Constructs a ConversationController object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Returns the page title.
   */
  public function title(ContentEntityInterface $ai_whatsapp_conversation): TranslatableMarkup {
    return $this->t('Conversation with @contact', [
      '@contact' => $this->contactLabel($ai_whatsapp_conversation),
    ]);
  }

  /**
   * Builds the operator inbox for a conversation.
   */
  public function view(ContentEntityInterface $ai_whatsapp_conversation): array {
    $conversation = $ai_whatsapp_conversation;
    $status = $this->fieldValue($conversation, 'status');
    $provider = $this->fieldValue($conversation, 'provider');
    $messages = $this->messages($conversation);

    return [
      '#attached' => [
        'library' => ['ai_whatsapp_automation/conversation_detail'],
      ],
      'header' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-conversation-detail__header']],
        'contact' => [
          '#markup' => '<div class="aiwa-conversation-detail__eyebrow">' . $this->t('Contacto') . '</div><h2>' . Html::escape($this->contactLabel($conversation)) . '</h2><div class="aiwa-conversation-detail__metadata">' . Html::escape($this->channelLabel($provider)) . ' · ' . Html::escape($this->fieldValue($conversation, 'phone')) . '</div>',
        ],
        'status' => [
          '#markup' => '<span class="aiwa-conversation-detail__status aiwa-conversation-detail__status--' . $this->statusClass($status) . '">' . $this->statusLabel($status) . '</span>',
        ],
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-conversation-detail__actions']],
      ] + $this->actionLinks($conversation, $status),
      'history' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-conversation-detail__history']],
        'heading' => ['#markup' => '<h2>' . $this->t('Historial') . '</h2>'],
        'messages' => $this->messageItems($messages),
      ],
    ];
  }

  /**
   * Loads conversation messages in chronological order.
   *
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   *   Message entities.
   */
  private function messages(ContentEntityInterface $conversation): array {
    $storage = $this->entityManager->getStorage('ai_whatsapp_message');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('conversation', $conversation->id())
      ->sort('created', 'ASC')
      ->execute();

    return $storage->loadMultiple($ids);
  }

  /**
   * Builds message bubbles.
   *
   * @param array<int, \Drupal\Core\Entity\EntityInterface> $messages
   *   Message entities.
   */
  private function messageItems(array $messages): array {
    if ($messages === []) {
      return [
        'empty' => ['#markup' => '<p class="aiwa-conversation-detail__empty">' . $this->t('No hay mensajes registrados todavía.') . '</p>'],
      ];
    }

    $items = [];
    foreach ($messages as $message) {
      $sender = $this->fieldValue($message, 'sender');
      $content = nl2br(htmlspecialchars($this->fieldValue($message, 'content'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
      $items[] = [
        '#markup' => '<article class="aiwa-conversation-detail__message aiwa-conversation-detail__message--' . $this->statusClass($sender) . '"><div class="aiwa-conversation-detail__message-meta">' . $this->senderLabel($sender) . ' · ' . $this->dateFormatter()->format((int) $this->fieldValue($message, 'created'), 'short') . '</div><div class="aiwa-conversation-detail__message-body">' . $content . '</div></article>',
      ];
    }

    return $items;
  }

  /**
   * Builds available operator actions.
   */
  private function actionLinks(ContentEntityInterface $conversation, string $status): array {
    $params = ['ai_whatsapp_conversation' => $conversation->id()];
    $links = [
      'reply' => $this->actionLink($this->t('Responder'), 'ai_whatsapp_automation.conversation_manual_reply', $params, TRUE),
      'assign' => $this->actionLink($this->t('Asignar operador'), 'ai_whatsapp_automation.conversation_assign_operator', $params),
    ];
    if ($status === 'AI_ACTIVE') {
      $links['stop'] = $this->actionLink($this->t('Pausar IA'), 'ai_whatsapp_automation.conversation_stop_ai', $params);
    }
    else {
      $links['reactivate'] = $this->actionLink($this->t('Reactivar IA'), 'ai_whatsapp_automation.conversation_reactivate_ai', $params);
    }
    $links['close'] = $this->actionLink($this->t('Cerrar conversación'), 'ai_whatsapp_automation.conversation_close', $params);

    return $links;
  }

  /**
   * Creates an action link render array.
   */
  private function actionLink(TranslatableMarkup $title, string $route, array $params, bool $primary = FALSE): array {
    return Link::fromTextAndUrl($title, Url::fromRoute($route, $params))->toRenderable() + [
      '#attributes' => ['class' => $primary ? ['button', 'button--primary'] : ['button']],
    ];
  }

  /**
   * Returns the contact label.
   */
  private function contactLabel(ContentEntityInterface $conversation): string {
    $name = $this->fieldValue($conversation, 'name');
    $phone = $this->fieldValue($conversation, 'phone');

    return $name !== '' ? $name : ($phone !== '' ? $phone : (string) $this->t('Contacto'));
  }

  /**
   * Returns a scalar field value.
   */
  private function fieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    return (string) $entity->get($field_name)->value;
  }

  /**
   * Returns a CSS-safe class suffix.
   */
  private function statusClass(string $value): string {
    return strtolower(str_replace('_', '-', $value));
  }

  /**
   * Returns a human-friendly channel label.
   */
  private function channelLabel(string $provider): string {
    return match ($provider) {
      'web' => (string) $this->t('Chat web'),
      'twilio' => (string) $this->t('WhatsApp Twilio'),
      'cloud_api' => (string) $this->t('WhatsApp Cloud API'),
      'evolution' => (string) $this->t('WhatsApp Evolution'),
      default => (string) $this->t('WhatsApp'),
    };
  }

  /**
   * Returns a human-friendly status label.
   */
  private function statusLabel(string $status): string {
    return match ($status) {
      'AI_ACTIVE' => (string) $this->t('IA activa'),
      'HUMAN_ASSIGNED' => (string) $this->t('Atención humana'),
      'CLOSED' => (string) $this->t('Cerrada'),
      default => $status,
    };
  }

  /**
   * Returns a human-friendly sender label.
   */
  private function senderLabel(string $sender): string {
    return match ($sender) {
      'contact' => (string) $this->t('Contacto'),
      'ai' => (string) $this->t('IA'),
      'operator' => (string) $this->t('Operador'),
      default => (string) $this->t('Sistema'),
    };
  }

}
