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
 * Defines the WhatsApp account entity.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_account',
  label: new TranslatableMarkup('WhatsApp account'),
  label_collection: new TranslatableMarkup('WhatsApp accounts'),
  label_singular: new TranslatableMarkup('WhatsApp account'),
  label_plural: new TranslatableMarkup('WhatsApp accounts'),
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
    'canonical' => '/admin/content/ai-whatsapp/accounts/{ai_whatsapp_account}',
    'collection' => '/admin/content/ai-whatsapp/accounts',
    'add-form' => '/admin/content/ai-whatsapp/accounts/add',
    'edit-form' => '/admin/content/ai-whatsapp/accounts/{ai_whatsapp_account}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/accounts/{ai_whatsapp_account}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation entities',
  base_table: 'ai_whatsapp_account',
  label_count: [
    'singular' => '@count WhatsApp account',
    'plural' => '@count WhatsApp accounts',
  ],
)]
final class WhatsAppAccount extends ContentEntityBase {

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

    $fields['provider'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Provider'))
      ->setRequired(TRUE)
      ->setSettings([
        'allowed_values' => [
          'twilio' => 'Twilio',
          'cloud_api' => 'WhatsApp Cloud API',
          'evolution' => 'Evolution API',
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

    $fields['phone_number'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Phone number'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('inactive')
      ->setSettings([
        'allowed_values' => [
          'active' => 'Active',
          'inactive' => 'Inactive',
          'disconnected' => 'Disconnected',
          'error' => 'Error',
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

    $fields['evolution_instance_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Evolution instance name'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 35,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 35,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['connection_status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Connection status'))
      ->setRequired(TRUE)
      ->setDefaultValue('DISCONNECTED')
      ->setSettings([
        'allowed_values' => [
          'DISCONNECTED' => 'Disconnected',
          'WAITING_QR' => 'Waiting for QR scan',
          'CONNECTING' => 'Connecting',
          'CONNECTED' => 'Connected',
          'ERROR' => 'Error',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 36,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 36,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['connected_phone_number'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Connected phone number'))
      ->setSetting('max_length', 64)
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 37,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['connected_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Connected at'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 38,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['last_qr'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Last QR code'))
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 39,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['last_qr_generated'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last QR generated'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['last_status_check'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Last status check'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 41,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['last_error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Last error'))
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 42,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['bot'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Bot'))
      ->setSetting('target_type', 'ai_whatsapp_bot')
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

    $fields['prompt_override'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Prompt override'))
      ->setDescription(t('Optional account-specific instructions. When empty, the selected bot prompt is used.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 55,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 55,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['model_override'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Model override'))
      ->setSettings([
        'allowed_values' => [
          'gpt-5-mini' => 'GPT-5 mini',
          'gpt-5.1' => 'GPT-5.1',
          'gpt-5' => 'GPT-5',
          'gpt-5-nano' => 'GPT-5 nano',
          'gpt-4.1-mini' => 'GPT-4.1 mini',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 56,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 56,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['knowledge_base'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Knowledge base override'))
      ->setDescription(t('Optional account-specific knowledge base. When empty, the selected bot knowledge base is used.'))
      ->setSetting('target_type', 'ai_whatsapp_knowledge_base')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 57,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 57,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['configuration'] = BaseFieldDefinition::create('map')
      ->setLabel(t('Configuration'))
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 60,
      ])
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
