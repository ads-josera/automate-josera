<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity;

use Drupal\ai_whatsapp_automation\Entity\Handler\AutomationEntityAccessControlHandler;
use Drupal\ai_whatsapp_automation\Entity\Handler\AutomationEntityListBuilder;
use Drupal\ai_whatsapp_automation\Entity\Storage\AutomationEntityStorage;
use Drupal\ai_whatsapp_automation\Form\AutomationEntityForm;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * Defines the human operator audit entity.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_operator_action',
  label: new TranslatableMarkup('Operator action'),
  label_collection: new TranslatableMarkup('Operator actions'),
  label_singular: new TranslatableMarkup('Operator action'),
  label_plural: new TranslatableMarkup('Operator actions'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'action',
  ],
  handlers: [
    'storage' => AutomationEntityStorage::class,
    'list_builder' => AutomationEntityListBuilder::class,
    'view_builder' => EntityViewBuilder::class,
    'access' => AutomationEntityAccessControlHandler::class,
    'form' => [
      'default' => AutomationEntityForm::class,
      'add' => AutomationEntityForm::class,
      'edit' => AutomationEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => DefaultHtmlRouteProvider::class,
    ],
    'views_data' => EntityViewsData::class,
  ],
  links: [
    'canonical' => '/admin/content/ai-whatsapp/operator-actions/{ai_whatsapp_operator_action}',
    'collection' => '/admin/content/ai-whatsapp/operator-actions',
    'add-form' => '/admin/content/ai-whatsapp/operator-actions/add',
    'edit-form' => '/admin/content/ai-whatsapp/operator-actions/{ai_whatsapp_operator_action}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/operator-actions/{ai_whatsapp_operator_action}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation entities',
  base_table: 'ai_whatsapp_operator_action',
  label_count: [
    'singular' => '@count operator action',
    'plural' => '@count operator actions',
  ],
)]
final class OperatorAction extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['conversation'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Conversation'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ai_whatsapp_conversation')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['action'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Action'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'AI_STOPPED' => 'AI stopped',
          'OPERATOR_ASSIGNED' => 'Operator assigned',
          'MANUAL_REPLY_SENT' => 'Manual reply sent',
          'AI_REACTIVATED' => 'AI reactivated',
          'CONVERSATION_CLOSED' => 'Conversation closed',
          'LEAD_HANDOFF' => 'Lead handoff',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['note'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Note'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 30,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
