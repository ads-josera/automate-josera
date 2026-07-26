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
 * Defines the AI bot entity.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_bot',
  label: new TranslatableMarkup('AI bot'),
  label_collection: new TranslatableMarkup('AI bots'),
  label_singular: new TranslatableMarkup('AI bot'),
  label_plural: new TranslatableMarkup('AI bots'),
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
    'canonical' => '/admin/content/ai-whatsapp/bots/{ai_whatsapp_bot}',
    'collection' => '/admin/content/ai-whatsapp/bots',
    'add-form' => '/admin/content/ai-whatsapp/bots/add',
    'edit-form' => '/admin/content/ai-whatsapp/bots/{ai_whatsapp_bot}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/bots/{ai_whatsapp_bot}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation entities',
  base_table: 'ai_whatsapp_bot',
  label_count: [
    'singular' => '@count AI bot',
    'plural' => '@count AI bots',
  ],
)]
final class Bot extends ContentEntityBase {

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

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['system_prompt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('System prompt'))
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

    $fields['model'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Model'))
      ->setRequired(TRUE)
      ->setDefaultValue('gpt-5-mini')
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
        'weight' => 30,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['temperature'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Temperature'))
      ->setDefaultValue('0.70')
      ->setSettings([
        'precision' => 3,
        'scale' => 2,
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

    $fields['knowledge_base'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Default knowledge base'))
      ->setSetting('target_type', 'ai_whatsapp_knowledge_base')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 45,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 45,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['handoff_enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Enable lead handoff'))
      ->setDescription(t('Allow this bot to create a lead, assign the conversation to human handling, and notify administrators when the configured criteria are met.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 46,
      ])
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'weight' => 46,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['handoff_required_fields'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Lead handoff required signals'))
      ->setDescription(t('One signal group per line. Use commas for alternatives, for example: company, business, client. The handoff fires when enough groups are detected. Leave empty to use generic lead signals.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 47,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 47,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['handoff_minimum_fields'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Minimum signals for lead handoff'))
      ->setDescription(t('How many configured signal groups must be present before notifying an administrator.'))
      ->setDefaultValue(5)
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 48,
      ])
      ->setDisplayOptions('view', [
        'type' => 'number_integer',
        'weight' => 48,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['handoff_trigger_phrases'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Lead handoff trigger phrases'))
      ->setDescription(t('Words or phrases that indicate the conversation is ready for human follow-up. One phrase per line. Leave empty to use generic phrases.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 49,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 49,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['handoff_prompt_rules'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Lead handoff prompt rules'))
      ->setDescription(t('Optional bot-specific rules appended to the AI instructions, such as what to say when enough data has been collected.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 50,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 50,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['lead_notification_template_sid'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Lead notification Template SID'))
      ->setDescription(t('Optional approved Twilio WhatsApp Content SID (HX...) used for this bot\'s qualified-lead notifications. When empty, the WhatsApp account or global template is used.'))
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

    $fields['lead_notification_template_variables'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Lead notification template variables'))
      ->setDescription(t('Optional mapping for this template, one per line. Example: 1=lead_id, 2=contact, 3=phone, 4=email, 5=summary. Available values: lead_id, contact, phone, email, summary, bot_name, source, conversation_url. Leave empty for the standard mapping.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 51,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 51,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['lead_notification_account'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Lead notification WhatsApp account'))
      ->setDescription(t('Optional WhatsApp account used to notify administrators about this bot\'s leads, including leads from the web widget. When empty, the system uses the only active account assigned to this bot.'))
      ->setSetting('target_type', 'ai_whatsapp_account')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 51,
      ])
      ->setDisplayOptions('view', [
        'type' => 'entity_reference_label',
        'weight' => 51,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_enabled'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Enable web widget'))
      ->setDescription(t('Allow this bot to be embedded as a public web chat widget.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 51,
      ])
      ->setDisplayOptions('view', [
        'type' => 'boolean',
        'weight' => 51,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_token'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Web widget token'))
      ->setDescription(t('Public UUID used in iframe and JavaScript embed URLs.'))
      ->setSetting('max_length', 64)
      ->setDefaultValueCallback(static::class . '::generateWidgetToken')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 52,
        'settings' => [
          'size' => 64,
        ],
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 52,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_primary_color'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Web widget primary color'))
      ->setSetting('max_length', 16)
      ->setDefaultValue('#155EEF')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 53,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 53,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_secondary_color'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Web widget secondary color'))
      ->setSetting('max_length', 16)
      ->setDefaultValue('#111827')
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 54,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 54,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_logo_url'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Web widget logo URL'))
      ->setDescription(t('Optional absolute URL for the assistant logo.'))
      ->setSetting('max_length', 512)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 55,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 55,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_logo_file'] = BaseFieldDefinition::create('file')
      ->setLabel(t('Web widget logo'))
      ->setDescription(t('Upload a JPG, PNG, or SVG logo. A square 512 x 512 px image is recommended.'))
      ->setSetting('file_directory', 'ai-whatsapp-widget-logos')
      ->setSetting('file_extensions', 'jpg jpeg png svg')
      ->setSetting('max_filesize', '2 MB')
      ->setDisplayOptions('form', [
        'type' => 'file_generic',
        'weight' => 55,
      ])
      ->setDisplayOptions('view', [
        'type' => 'file_default',
        'weight' => 55,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_assistant_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Web widget assistant name'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 56,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 56,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_welcome_message'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Web widget welcome message'))
      ->setDefaultValue('Hola, ¿en qué puedo ayudarte?')
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 57,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 57,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_position'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Web widget button position'))
      ->setDefaultValue('right')
      ->setSettings([
        'allowed_values' => [
          'right' => 'Right',
          'left' => 'Left',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 58,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 58,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_icon'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Web widget button icon'))
      ->setDefaultValue('chat')
      ->setSettings([
        'allowed_values' => [
          'chat' => 'Chat',
          'sparkles' => 'Sparkles',
          'help' => 'Help',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 59,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 59,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_size'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Web widget size'))
      ->setDefaultValue('medium')
      ->setSettings([
        'allowed_values' => [
          'small' => 'Small',
          'medium' => 'Medium',
          'large' => 'Large',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 60,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 60,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_language'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Web widget language'))
      ->setDefaultValue('es')
      ->setSettings([
        'allowed_values' => [
          'es' => 'Spanish',
          'en' => 'English',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 61,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 61,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_allowed_domains'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Web widget allowed domains'))
      ->setDescription(t('Optional list of authorized domains, one per line. Leave empty to allow any domain.'))
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 62,
      ])
      ->setDisplayOptions('view', [
        'type' => 'basic_string',
        'weight' => 62,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['web_widget_api_key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Web widget API key'))
      ->setDescription(t('Optional API key required by JavaScript and API requests. Leave empty for public domain-restricted access.'))
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 63,
      ])
      ->setDisplayOptions('view', [
        'type' => 'string',
        'weight' => 63,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('active')
      ->setSettings([
        'allowed_values' => [
          'active' => 'Active',
          'inactive' => 'Inactive',
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 70,
      ])
      ->setDisplayOptions('view', [
        'type' => 'list_default',
        'weight' => 70,
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

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDisplayOptions('view', [
        'type' => 'timestamp',
        'weight' => 70,
      ])
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

  /**
   * Generates a public widget token for new bots.
   *
   * @return string[]
   *   Default field value.
   */
  public static function generateWidgetToken(): array {
    return [\Drupal::service('uuid')->generate()];
  }

}
