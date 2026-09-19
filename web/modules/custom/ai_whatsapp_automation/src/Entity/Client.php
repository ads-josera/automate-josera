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
 * Defines a client: the company that owns bots, accounts and knowledge.
 *
 * Bots, WhatsApp accounts and knowledge bases reference their client.
 * Conversations and leads store it too (set on save, see
 * ClientAssignmentService) so history keeps its owner even if a bot is later
 * moved to another client, and lists can be filtered without joins.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_client',
  label: new TranslatableMarkup('Client'),
  label_collection: new TranslatableMarkup('Clients'),
  label_singular: new TranslatableMarkup('client'),
  label_plural: new TranslatableMarkup('clients'),
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
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
    'route_provider' => ['html' => DefaultHtmlRouteProvider::class],
    'views_data' => EntityViewsData::class,
  ],
  links: [
    'canonical' => '/admin/content/ai-whatsapp/clients/{ai_whatsapp_client}',
    'collection' => '/admin/content/ai-whatsapp/clients',
    'add-form' => '/admin/content/ai-whatsapp/clients/add',
    'edit-form' => '/admin/content/ai-whatsapp/clients/{ai_whatsapp_client}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/clients/{ai_whatsapp_client}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation entities',
  base_table: 'ai_whatsapp_client',
  label_count: [
    'singular' => '@count client',
    'plural' => '@count clients',
  ],
)]
final class Client extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayOptions('view', ['label' => 'hidden', 'type' => 'string', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('active')
      ->setSettings(['allowed_values' => ['active' => 'Active', 'inactive' => 'Inactive']])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 10])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Internal notes'))
      ->setDescription(t('Only visible to administrators: plan, agreements, dates.'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 20])
      ->setDisplayOptions('view', ['type' => 'basic_string', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

  /**
   * Base field definition for the client reference on owned entities.
   *
   * Shared by Bot, WhatsAppAccount, KnowledgeBase, Conversation and Lead so
   * the five fields stay identical.
   */
  public static function referenceField(string|\Stringable $description, int $weight = -50): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Client'))
      ->setDescription($description)
      ->setSetting('target_type', 'ai_whatsapp_client')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => $weight])
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => $weight])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

}
