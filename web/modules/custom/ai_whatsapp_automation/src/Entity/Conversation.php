<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity;

use Drupal\ai_whatsapp_automation\Entity\Handler\AutomationEntityAccessControlHandler;
use Drupal\ai_whatsapp_automation\Entity\Handler\AutomationEntityListBuilder;
use Drupal\ai_whatsapp_automation\Entity\Handler\ConversationViewBuilder;
use Drupal\ai_whatsapp_automation\Entity\Storage\AutomationEntityStorage;
use Drupal\ai_whatsapp_automation\Form\AutomationEntityForm;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * Defines the WhatsApp conversation entity.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_conversation',
  label: new TranslatableMarkup('WhatsApp conversation'),
  label_collection: new TranslatableMarkup('WhatsApp conversations'),
  label_singular: new TranslatableMarkup('WhatsApp conversation'),
  label_plural: new TranslatableMarkup('WhatsApp conversations'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'phone',
  ],
  handlers: [
    'storage' => AutomationEntityStorage::class,
    'list_builder' => AutomationEntityListBuilder::class,
    'view_builder' => ConversationViewBuilder::class,
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
    'canonical' => '/admin/content/ai-whatsapp/conversations/{ai_whatsapp_conversation}',
    'collection' => '/admin/content/ai-whatsapp/conversations',
    'add-form' => '/admin/content/ai-whatsapp/conversations/add',
    'edit-form' => '/admin/content/ai-whatsapp/conversations/{ai_whatsapp_conversation}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/conversations/{ai_whatsapp_conversation}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation entities',
  base_table: 'ai_whatsapp_conversation',
  label_count: [
    'singular' => '@count WhatsApp conversation',
    'plural' => '@count WhatsApp conversations',
  ],
)]
final class Conversation extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $name = trim((string) ($this->get('name')->value ?? ''));
    $provider = (string) ($this->get('provider')->value ?? '');

    if ($provider === 'web' && ($name === '' || $name === 'Web visitor')) {
      return (string) t('Visitante web');
    }

    if ($name !== '') {
      return $name;
    }

    return (string) ($this->get('phone')->value ?? t('Contacto'));
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['phone'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Phone'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
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

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setSetting('max_length', 128)
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

    $fields['channel'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Channel'))
      ->setRequired(TRUE)
      ->setDefaultValue('whatsapp')
      ->setSettings([
        'allowed_values' => [
          'whatsapp' => 'WhatsApp',
          'web' => 'Web chat',
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

    $fields['provider'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Provider'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'twilio' => 'Twilio',
          'cloud_api' => 'WhatsApp Cloud API',
          'evolution' => 'Evolution API',
          'web' => 'Web widget',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 30,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('AI_ACTIVE')
      ->setSettings([
        'allowed_values' => [
          'AI_ACTIVE' => 'AI active',
          'HUMAN_ASSIGNED' => 'Human assigned',
          'CLOSED' => 'Closed',
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

    $fields['assigned_operator'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Assigned operator'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 50,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 50,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['whatsapp_account'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('WhatsApp account'))
      ->setSetting('target_type', 'ai_whatsapp_account')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 60,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 60,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['bot'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Bot'))
      ->setDescription(t('Direct bot reference used by web chat conversations.'))
      ->setSetting('target_type', 'ai_whatsapp_bot')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 65,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 65,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 70,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 80,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
