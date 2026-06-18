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
 * Defines the WhatsApp lead entity.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_lead',
  label: new TranslatableMarkup('WhatsApp lead'),
  label_collection: new TranslatableMarkup('WhatsApp leads'),
  label_singular: new TranslatableMarkup('WhatsApp lead'),
  label_plural: new TranslatableMarkup('WhatsApp leads'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
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
    'canonical' => '/admin/content/ai-whatsapp/leads/{ai_whatsapp_lead}',
    'collection' => '/admin/content/ai-whatsapp/leads',
    'add-form' => '/admin/content/ai-whatsapp/leads/add',
    'edit-form' => '/admin/content/ai-whatsapp/leads/{ai_whatsapp_lead}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/leads/{ai_whatsapp_lead}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation entities',
  base_table: 'ai_whatsapp_lead',
  label_count: [
    'singular' => '@count WhatsApp lead',
    'plural' => '@count WhatsApp leads',
  ],
)]
final class Lead extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['phone'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Phone'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['email'] = BaseFieldDefinition::create('email')
      ->setLabel(t('Email'))
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'type' => 'email_mailto',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['source'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 30,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('new')
      ->setSettings([
        'allowed_values' => [
          'new' => 'New',
          'contacted' => 'Contacted',
          'qualified' => 'Qualified',
          'disqualified' => 'Disqualified',
          'converted' => 'Converted',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 40,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['tags'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tags'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('max_length', 64)
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
