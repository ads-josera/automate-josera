<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity\Handler;

use Drupal\ai_whatsapp_automation\Form\MessageListFilterForm;
use Drupal\ai_whatsapp_automation\Form\ConversationListFilterForm;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Provides list tables for AI WhatsApp Automation content entities.
 */
final class AutomationEntityListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    if ($this->entityTypeId === 'ai_whatsapp_message') {
      return [
        'conversation' => $this->t('Conversación'),
        'sender' => $this->t('Dirección'),
        'content' => $this->t('Mensaje'),
        'created' => $this->t('Fecha'),
      ] + parent::buildHeader();
    }
    if ($this->entityTypeId === 'ai_whatsapp_conversation') {
      return [
        'contact' => $this->t('Contacto'),
        'provider' => $this->t('Origen'),
        'status' => $this->t('Estado'),
        'changed' => $this->t('Última actividad'),
      ] + parent::buildHeader();
    }

    $header['label'] = $this->t('Label');
    $header['status'] = $this->t('Status');
    $header['changed'] = $this->t('Updated');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    if ($this->entityTypeId === 'ai_whatsapp_message') {
      return $this->buildMessageRow($entity) + parent::buildRow($entity);
    }
    if ($this->entityTypeId === 'ai_whatsapp_conversation') {
      return $this->buildConversationRow($entity) + parent::buildRow($entity);
    }

    $row['label'] = $entity->toLink();
    $row['status'] = $this->getFieldValue($entity, 'status');
    $row['changed'] = $this->getFieldValue($entity, 'changed');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $build = parent::render();
    if ($this->entityTypeId === 'ai_whatsapp_message') {
      $build['table']['#attributes']['class'][] = 'aiwa-message-list';
      return [
        '#attached' => [
          'library' => ['ai_whatsapp_automation/message_list'],
        ],
        'filters' => \Drupal::formBuilder()->getForm(MessageListFilterForm::class),
        'messages' => $build,
      ];
    }
    if ($this->entityTypeId === 'ai_whatsapp_conversation') {
      $build['table']['#attributes']['class'][] = 'aiwa-conversation-list';
      return [
        '#attached' => [
          'library' => ['ai_whatsapp_automation/conversation_list'],
        ],
        'filters' => \Drupal::formBuilder()->getForm(ConversationListFilterForm::class),
        'conversations' => $build,
      ];
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    if ($this->entityTypeId !== 'ai_whatsapp_message') {
      if ($this->entityTypeId !== 'ai_whatsapp_conversation') {
        return parent::getEntityListQuery();
      }

      $query = $this->getStorage()->getQuery()
        ->accessCheck(TRUE)
        ->sort('changed', 'DESC');
      $request = \Drupal::request();
      $search = trim((string) $request->query->get('q', ''));
      $provider = trim((string) $request->query->get('provider', ''));
      $status = trim((string) $request->query->get('status', ''));
      if ($search !== '') {
        $search_group = $query->orConditionGroup()
          ->condition('name', '%' . $search . '%', 'LIKE')
          ->condition('phone', '%' . $search . '%', 'LIKE');
        $query->condition($search_group);
      }
      if (in_array($provider, ['twilio', 'cloud_api', 'evolution', 'web'], TRUE)) {
        $query->condition('provider', $provider);
      }
      if (in_array($status, ['AI_ACTIVE', 'HUMAN_ASSIGNED', 'CLOSED'], TRUE)) {
        $query->condition('status', $status);
      }
      if ($this->limit) {
        $query->pager($this->limit);
      }

      return $query;
    }

    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC');
    $request = \Drupal::request();
    $search = trim((string) $request->query->get('q', ''));
    $sender = trim((string) $request->query->get('sender', ''));
    $conversation = (int) $request->query->get('conversation', 0);

    if ($search !== '') {
      $search_group = $query->orConditionGroup()
        ->condition('content', '%' . $search . '%', 'LIKE')
        ->condition('provider_message_id', '%' . $search . '%', 'LIKE');
      $query->condition($search_group);
    }
    if (in_array($sender, ['contact', 'ai', 'operator', 'system'], TRUE)) {
      $query->condition('sender', $sender);
    }
    if ($conversation > 0) {
      $query->condition('conversation', $conversation);
    }
    if ($this->limit) {
      $query->pager($this->limit);
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);

    if ($entity->getEntityTypeId() === 'ai_whatsapp_account' && $this->getFieldValue($entity, 'provider') === 'evolution') {
      $operations['manage_qr'] = [
        'title' => $this->t('Manage QR'),
        'weight' => 20,
        'url' => Url::fromRoute('ai_whatsapp_automation.evolution_account_qr', [
          'ai_whatsapp_account' => $entity->id(),
        ]),
      ];
    }

    if ($entity->getEntityTypeId() === 'ai_whatsapp_bot') {
      $operations['web_integration'] = [
        'title' => $this->t('Web integration'),
        'weight' => 20,
        'url' => Url::fromRoute('ai_whatsapp_automation.bot_web_integration', [
          'ai_whatsapp_bot' => $entity->id(),
        ]),
      ];
    }

    if ($entity->getEntityTypeId() === 'ai_whatsapp_message') {
      $conversation = $entity->hasField('conversation') ? $entity->get('conversation')->entity : NULL;
      if ($conversation instanceof EntityInterface) {
        $operations['conversation'] = [
          'title' => $this->t('Ver conversación'),
          'weight' => 5,
          'url' => $conversation->toUrl('canonical'),
        ];
      }
      return $operations;
    }

    if ($entity->getEntityTypeId() !== 'ai_whatsapp_conversation') {
      return $operations;
    }

    $route_params = ['ai_whatsapp_conversation' => $entity->id()];
    $operations['stop_ai'] = [
      'title' => $this->t('Stop AI'),
      'weight' => 20,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_stop_ai', $route_params),
    ];
    $operations['assign_operator'] = [
      'title' => $this->t('Assign operator'),
      'weight' => 21,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_assign_operator', $route_params),
    ];
    $operations['manual_reply'] = [
      'title' => $this->t('Manual reply'),
      'weight' => 22,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_manual_reply', $route_params),
    ];
    $operations['reactivate_ai'] = [
      'title' => $this->t('Reactivate AI'),
      'weight' => 23,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_reactivate_ai', $route_params),
    ];
    $operations['close'] = [
      'title' => $this->t('Close'),
      'weight' => 24,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_close', $route_params),
    ];

    return $operations;
  }

  /**
   * Returns a scalar field value for list display.
   */
  private function getFieldValue(EntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Builds a readable row for the message inbox.
   */
  private function buildMessageRow(EntityInterface $entity): array {
    $conversation = $entity->hasField('conversation') ? $entity->get('conversation')->entity : NULL;
    $contact = $conversation instanceof EntityInterface
      ? ($this->getFieldValue($conversation, 'name') ?: $this->getFieldValue($conversation, 'phone'))
      : $this->t('Conversation unavailable');
    $conversation_id = $conversation instanceof EntityInterface ? $conversation->id() : NULL;
    $preview = preg_replace('/\s+/u', ' ', $this->getFieldValue($entity, 'content')) ?? '';
    $preview = mb_strimwidth($preview, 0, 180, '...');
    $sender = $this->getFieldValue($entity, 'sender');
    $sender_labels = [
      'contact' => $this->t('Contacto'),
      'ai' => $this->t('IA'),
      'operator' => $this->t('Operador'),
      'system' => $this->t('Sistema'),
    ];

    $row['conversation'] = [
      'data' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-message-list__conversation']],
        'link' => $conversation instanceof EntityInterface
          ? Link::fromTextAndUrl($contact, $conversation->toUrl('canonical'))->toRenderable()
          : ['#plain_text' => (string) $contact],
      ],
    ];
    if ($conversation_id !== NULL) {
      $row['conversation']['data']['id'] = [
        '#markup' => '<div class="aiwa-message-list__conversation-id">#' . $conversation_id . '</div>',
      ];
    }
    $row['sender'] = [
      'data' => [
        '#markup' => '<span class="aiwa-message-list__sender aiwa-message-list__sender--' . Html::getClass($sender) . '">' . Html::escape((string) ($sender_labels[$sender] ?? $sender)) . '</span>',
      ],
    ];
    $row['content'] = [
      'data' => [
        '#markup' => '<div class="aiwa-message-list__preview">' . Html::escape($preview) . '</div>',
      ],
    ];
    $row['created'] = [
      'data' => [
        '#markup' => '<span class="aiwa-message-list__date">' . Html::escape(\Drupal::service('date.formatter')->format((int) $this->getFieldValue($entity, 'created'), 'short')) . '</span>',
      ],
    ];

    return $row;
  }

  /**
   * Builds an operational row for the conversation inbox.
   */
  private function buildConversationRow(EntityInterface $entity): array {
    $provider = $this->getFieldValue($entity, 'provider');
    $phone = $this->getFieldValue($entity, 'phone');
    $name = $this->getFieldValue($entity, 'name');
    $contact = $name ?: ($provider === 'web' ? $this->t('Visitante web') : $phone);
    $provider_labels = [
      'twilio' => $this->t('WhatsApp Twilio'),
      'cloud_api' => $this->t('WhatsApp Cloud API'),
      'evolution' => $this->t('WhatsApp Evolution'),
      'web' => $this->t('Chat web'),
    ];
    $status = $this->getFieldValue($entity, 'status');
    $status_labels = [
      'AI_ACTIVE' => $this->t('IA activa'),
      'HUMAN_ASSIGNED' => $this->t('Atención humana'),
      'CLOSED' => $this->t('Cerrada'),
    ];

    $row['contact'] = [
      'data' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-conversation-list__contact']],
        'link' => Link::fromTextAndUrl($contact, $entity->toUrl('canonical'))->toRenderable(),
      ],
    ];
    if ($provider !== 'web' && $name !== '' && $phone !== '') {
      $row['contact']['data']['phone'] = [
        '#markup' => '<div class="aiwa-conversation-list__phone">' . Html::escape($phone) . '</div>',
      ];
    }
    if ($provider === 'web') {
      $row['contact']['data']['id'] = [
        '#markup' => '<div class="aiwa-conversation-list__phone">#' . $entity->id() . '</div>',
      ];
    }
    $row['provider'] = [
      'data' => [
        '#markup' => '<span class="aiwa-conversation-list__provider">' . Html::escape((string) ($provider_labels[$provider] ?? $provider)) . '</span>',
      ],
    ];
    $row['status'] = [
      'data' => [
        '#markup' => '<span class="aiwa-conversation-list__status aiwa-conversation-list__status--' . Html::getClass($status) . '">' . Html::escape((string) ($status_labels[$status] ?? $status)) . '</span>',
      ],
    ];
    $row['changed'] = [
      'data' => [
        '#markup' => '<span class="aiwa-conversation-list__date">' . Html::escape(\Drupal::service('date.formatter')->format((int) $this->getFieldValue($entity, 'changed'), 'short')) . '</span>',
      ],
    ];

    return $row;
  }

}
