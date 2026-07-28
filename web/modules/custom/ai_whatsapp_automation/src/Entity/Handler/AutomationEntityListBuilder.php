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
    if ($this->entityTypeId === 'ai_whatsapp_lead') {
      return [
        'contact' => $this->t('Contacto'),
        'source' => $this->t('Origen'),
        'status' => $this->t('Estado'),
        'created' => $this->t('Creado'),
      ] + parent::buildHeader();
    }
    if ($this->entityTypeId === 'ai_whatsapp_operator_action') {
      return [
        'conversation' => $this->t('Conversación'),
        'action' => $this->t('Acción'),
        'user' => $this->t('Responsable'),
        'note' => $this->t('Detalle'),
        'created' => $this->t('Fecha'),
      ] + parent::buildHeader();
    }
    if ($this->entityTypeId === 'ai_whatsapp_knowledge_chunk') {
      return [
        'document' => $this->t('Documento'),
        'chunk' => $this->t('Fragmento'),
        'content' => $this->t('Vista previa'),
        'model' => $this->t('Modelo'),
        'created' => $this->t('Creado'),
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
    if ($this->entityTypeId === 'ai_whatsapp_lead') {
      return $this->buildLeadRow($entity) + parent::buildRow($entity);
    }
    if ($this->entityTypeId === 'ai_whatsapp_operator_action') {
      return $this->buildOperatorActionRow($entity) + parent::buildRow($entity);
    }
    if ($this->entityTypeId === 'ai_whatsapp_knowledge_chunk') {
      return $this->buildKnowledgeChunkRow($entity) + parent::buildRow($entity);
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
    if ($this->entityTypeId === 'ai_whatsapp_lead') {
      $build['table']['#attributes']['class'][] = 'aiwa-lead-list';
      return [
        '#attached' => [
          'library' => ['ai_whatsapp_automation/lead_list'],
        ],
        'leads' => $build,
      ];
    }
    if ($this->entityTypeId === 'ai_whatsapp_operator_action') {
      $build['table']['#attributes']['class'][] = 'aiwa-operator-action-list';
      return [
        '#attached' => [
          'library' => ['ai_whatsapp_automation/operator_action_list'],
        ],
        'actions' => $build,
      ];
    }
    if ($this->entityTypeId === 'ai_whatsapp_knowledge_chunk') {
      $build['table']['#attributes']['class'][] = 'aiwa-knowledge-chunk-list';
      return [
        '#attached' => [
          'library' => ['ai_whatsapp_automation/knowledge_chunk_list'],
        ],
        'chunks' => $build,
      ];
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    if ($this->entityTypeId === 'ai_whatsapp_lead') {
      $query = $this->getStorage()->getQuery()
        ->accessCheck(TRUE)
        ->sort('created', 'DESC');
      if ($this->limit) {
        $query->pager($this->limit);
      }

      return $query;
    }
    if ($this->entityTypeId === 'ai_whatsapp_operator_action') {
      $query = $this->getStorage()->getQuery()
        ->accessCheck(TRUE)
        ->sort('created', 'DESC');
      if ($this->limit) {
        $query->pager($this->limit);
      }

      return $query;
    }
    if ($this->entityTypeId === 'ai_whatsapp_knowledge_chunk') {
      $query = $this->getStorage()->getQuery()
        ->accessCheck(TRUE)
        ->sort('created', 'DESC');
      if ($this->limit) {
        $query->pager($this->limit);
      }

      return $query;
    }
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

    if ($entity->getEntityTypeId() === 'ai_whatsapp_operator_action') {
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

    if ($entity->getEntityTypeId() === 'ai_whatsapp_lead') {
      $phone = $this->getFieldValue($entity, 'phone');
      if ($phone !== '') {
        $conversation_ids = \Drupal::entityTypeManager()
          ->getStorage('ai_whatsapp_conversation')
          ->getQuery()
          ->accessCheck(TRUE)
          ->condition('phone', $phone)
          ->sort('changed', 'DESC')
          ->range(0, 1)
          ->execute();
        if ($conversation_ids !== []) {
          $conversation = \Drupal::entityTypeManager()
            ->getStorage('ai_whatsapp_conversation')
            ->load(reset($conversation_ids));
          if ($conversation instanceof EntityInterface) {
            $operations['conversation'] = [
              'title' => $this->t('Ver conversación'),
              'weight' => 5,
              'url' => $conversation->toUrl('canonical'),
            ];
          }
        }
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

  /**
   * Builds a concise and useful row for the lead inbox.
   */
  private function buildLeadRow(EntityInterface $entity): array {
    $phone = $this->getFieldValue($entity, 'phone');
    $email = $this->getFieldValue($entity, 'email');
    $name = trim(preg_replace('/[*_`]+/u', '', $this->getFieldValue($entity, 'name')) ?? '');
    if ($name === '' || !preg_match('/[\\p{L}\\p{N}]/u', $name)) {
      $name = $email ?: ($phone ?: (string) $this->t('Contacto pendiente'));
    }
    $source = $this->getFieldValue($entity, 'source');
    $status = $this->getFieldValue($entity, 'status');
    $source_labels = [
      'whatsapp' => $this->t('WhatsApp'),
      'web' => $this->t('Chat web'),
    ];
    $status_labels = [
      'new' => $this->t('Nuevo'),
      'contacted' => $this->t('Contactado'),
      'qualified' => $this->t('Calificado'),
      'disqualified' => $this->t('Descartado'),
      'converted' => $this->t('Convertido'),
    ];

    $row['contact'] = [
      'data' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-lead-list__contact']],
        'link' => Link::fromTextAndUrl($name, $entity->toUrl('canonical'))->toRenderable(),
      ],
    ];
    if ($phone !== '') {
      $row['contact']['data']['phone'] = [
        '#markup' => '<div class="aiwa-lead-list__meta">' . Html::escape($phone) . '</div>',
      ];
    }
    if ($email !== '') {
      $row['contact']['data']['email'] = [
        '#markup' => '<div class="aiwa-lead-list__meta">' . Html::escape($email) . '</div>',
      ];
    }
    $row['source'] = [
      'data' => [
        '#markup' => '<span class="aiwa-lead-list__source">' . Html::escape((string) ($source_labels[$source] ?? $source ?: $this->t('No definido'))) . '</span>',
      ],
    ];
    $row['status'] = [
      'data' => [
        '#markup' => '<span class="aiwa-lead-list__status aiwa-lead-list__status--' . Html::getClass($status) . '">' . Html::escape((string) ($status_labels[$status] ?? $status)) . '</span>',
      ],
    ];
    $row['created'] = [
      'data' => [
        '#markup' => '<span class="aiwa-lead-list__date">' . Html::escape(\Drupal::service('date.formatter')->format((int) $this->getFieldValue($entity, 'created'), 'short')) . '</span>',
      ],
    ];

    return $row;
  }

  /**
   * Builds a readable audit row for an operator action.
   */
  private function buildOperatorActionRow(EntityInterface $entity): array {
    $conversation = $entity->hasField('conversation') ? $entity->get('conversation')->entity : NULL;
    $contact = $conversation instanceof EntityInterface
      ? ($this->getFieldValue($conversation, 'name') ?: $this->getFieldValue($conversation, 'phone'))
      : $this->t('Conversación eliminada');
    $operator = $entity->hasField('user') ? $entity->get('user')->entity : NULL;
    $action = $this->getFieldValue($entity, 'action');
    $note = preg_replace('/\s+/u', ' ', $this->getFieldValue($entity, 'note')) ?? '';
    $note = $note !== '' ? mb_strimwidth($note, 0, 140, '...') : (string) $this->t('Sin detalle adicional');
    $action_labels = [
      'AI_STOPPED' => $this->t('IA pausada'),
      'OPERATOR_ASSIGNED' => $this->t('Operador asignado'),
      'MANUAL_REPLY_SENT' => $this->t('Respuesta manual enviada'),
      'AI_REACTIVATED' => $this->t('IA reactivada'),
      'CONVERSATION_CLOSED' => $this->t('Conversación cerrada'),
      'LEAD_HANDOFF' => $this->t('Lead enviado a atención humana'),
    ];

    $row['conversation'] = [
      'data' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-operator-action-list__conversation']],
        'link' => $conversation instanceof EntityInterface
          ? Link::fromTextAndUrl($contact, $conversation->toUrl('canonical'))->toRenderable()
          : ['#plain_text' => (string) $contact],
      ],
    ];
    if ($conversation instanceof EntityInterface) {
      $row['conversation']['data']['id'] = [
        '#markup' => '<div class="aiwa-operator-action-list__meta">#' . $conversation->id() . '</div>',
      ];
    }
    $row['action'] = [
      'data' => [
        '#markup' => '<span class="aiwa-operator-action-list__action aiwa-operator-action-list__action--' . Html::getClass($action) . '">' . Html::escape((string) ($action_labels[$action] ?? $action)) . '</span>',
      ],
    ];
    $row['user'] = [
      'data' => [
        '#markup' => '<span class="aiwa-operator-action-list__user">' . Html::escape($operator instanceof EntityInterface ? $operator->label() : (string) $this->t('Sistema')) . '</span>',
      ],
    ];
    $row['note'] = [
      'data' => [
        '#markup' => '<div class="aiwa-operator-action-list__note">' . Html::escape($note) . '</div>',
      ],
    ];
    $row['created'] = [
      'data' => [
        '#markup' => '<span class="aiwa-operator-action-list__date">' . Html::escape(\Drupal::service('date.formatter')->format((int) $this->getFieldValue($entity, 'created'), 'short')) . '</span>',
      ],
    ];

    return $row;
  }

  /**
   * Builds a readable row for an indexed knowledge fragment.
   */
  private function buildKnowledgeChunkRow(EntityInterface $entity): array {
    $document = $entity->hasField('document') ? $entity->get('document')->entity : NULL;
    $knowledge_base = $entity->hasField('knowledge_base') ? $entity->get('knowledge_base')->entity : NULL;
    $content = preg_replace('/\s+/u', ' ', $this->getFieldValue($entity, 'content')) ?? '';
    $content = mb_strimwidth($content, 0, 230, '...');
    $chunk_index = $this->getFieldValue($entity, 'chunk_index');
    $document_label = $document instanceof EntityInterface ? $document->label() : $this->t('Documento no disponible');

    $row['document'] = [
      'data' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-knowledge-chunk-list__document']],
        'link' => $document instanceof EntityInterface
          ? Link::fromTextAndUrl($document_label, $document->toUrl('canonical'))->toRenderable()
          : ['#plain_text' => (string) $document_label],
      ],
    ];
    if ($knowledge_base instanceof EntityInterface) {
      $row['document']['data']['base'] = [
        '#markup' => '<div class="aiwa-knowledge-chunk-list__meta">' . Html::escape($knowledge_base->label()) . '</div>',
      ];
    }
    $row['chunk'] = [
      'data' => [
        '#markup' => '<span class="aiwa-knowledge-chunk-list__index">#' . Html::escape($chunk_index) . '</span>',
      ],
    ];
    $row['content'] = [
      'data' => [
        '#markup' => '<div class="aiwa-knowledge-chunk-list__preview">' . Html::escape($content) . '</div>',
      ],
    ];
    $row['model'] = [
      'data' => [
        '#markup' => '<span class="aiwa-knowledge-chunk-list__model">' . Html::escape($this->getFieldValue($entity, 'embedding_model') ?: (string) $this->t('No definido')) . '</span>',
      ],
    ];
    $row['created'] = [
      'data' => [
        '#markup' => '<span class="aiwa-knowledge-chunk-list__date">' . Html::escape(\Drupal::service('date.formatter')->format((int) $this->getFieldValue($entity, 'created'), 'short')) . '</span>',
      ],
    ];

    return $row;
  }

}
