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
 * Defines the WhatsApp message entity.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_message',
  label: new TranslatableMarkup('WhatsApp message'),
  label_collection: new TranslatableMarkup('WhatsApp messages'),
  label_singular: new TranslatableMarkup('WhatsApp message'),
  label_plural: new TranslatableMarkup('WhatsApp messages'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'provider_message_id',
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
    'canonical' => '/admin/content/ai-whatsapp/messages/{ai_whatsapp_message}',
    'collection' => '/admin/content/ai-whatsapp/messages',
    'add-form' => '/admin/content/ai-whatsapp/messages/add',
    'edit-form' => '/admin/content/ai-whatsapp/messages/{ai_whatsapp_message}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/messages/{ai_whatsapp_message}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation entities',
  base_table: 'ai_whatsapp_message',
  label_count: [
    'singular' => '@count WhatsApp message',
    'plural' => '@count WhatsApp messages',
  ],
)]
final class Message extends ContentEntityBase {

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

    $fields['sender'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Sender'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'contact' => 'Contact',
          'ai' => 'AI',
          'operator' => 'Operator',
          'system' => 'System',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Content'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['tokens'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Tokens'))
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 30,
      ])
      ->setDisplayOptions('view', [
        'type' => 'number_integer',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['cost'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Cost'))
      ->setDefaultValue('0.000000')
      ->setSettings([
        'precision' => 12,
        'scale' => 6,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 40,
      ])
      ->setDisplayOptions('view', [
        'type' => 'number_decimal',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['provider_message_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Provider message ID'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 50,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 50,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 60,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
