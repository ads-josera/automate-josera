<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity\Handler;

use Drupal\ai_whatsapp_automation\Form\MessageListFilterForm;
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
    if ($this->entityTypeId !== 'ai_whatsapp_message') {
      return $build;
    }

    return [
      '#attached' => [
        'library' => ['ai_whatsapp_automation/message_list'],
      ],
      'filters' => \Drupal::formBuilder()->getForm(MessageListFilterForm::class),
      'messages' => $build,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    if ($this->entityTypeId !== 'ai_whatsapp_message') {
      return parent::getEntityListQuery();
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

    $row['conversation'] = $conversation instanceof EntityInterface
      ? Link::fromTextAndUrl($contact, $conversation->toUrl('canonical'))->toRenderable()
      : ['#plain_text' => (string) $contact];
    if ($conversation_id !== NULL) {
      $row['conversation']['#suffix'] = '<div class="aiwa-message-list__conversation-id">#' . $conversation_id . '</div>';
    }
    $row['sender'] = [
      '#markup' => '<span class="aiwa-message-list__sender aiwa-message-list__sender--' . Html::getClass($sender) . '">' . Html::escape((string) ($sender_labels[$sender] ?? $sender)) . '</span>',
    ];
    $row['content'] = [
      '#markup' => '<div class="aiwa-message-list__preview">' . Html::escape($preview) . '</div>',
    ];
    $row['created'] = [
      '#plain_text' => \Drupal::service('date.formatter')->format((int) $this->getFieldValue($entity, 'created'), 'short'),
    ];

    return $row;
  }

}
