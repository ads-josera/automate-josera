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
 * Defines a RAG knowledge base.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_knowledge_base',
  label: new TranslatableMarkup('Knowledge base'),
  label_collection: new TranslatableMarkup('Knowledge bases'),
  label_singular: new TranslatableMarkup('Knowledge base'),
  label_plural: new TranslatableMarkup('Knowledge bases'),
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
    'canonical' => '/admin/content/ai-whatsapp/knowledge-bases/{ai_whatsapp_knowledge_base}',
    'collection' => '/admin/content/ai-whatsapp/knowledge-bases',
    'add-form' => '/admin/content/ai-whatsapp/knowledge-bases/add',
    'edit-form' => '/admin/content/ai-whatsapp/knowledge-bases/{ai_whatsapp_knowledge_base}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/knowledge-bases/{ai_whatsapp_knowledge_base}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation rag',
  base_table: 'ai_whatsapp_knowledge_base',
)]
final class KnowledgeBase extends ContentEntityBase {

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
      ->setDisplayOptions('view', ['type' => 'string', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Description'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 10])
      ->setDisplayOptions('view', ['type' => 'basic_string', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['embedding_model'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Embedding model'))
      ->setRequired(TRUE)
      ->setDefaultValue('text-embedding-3-small')
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 20])
      ->setDisplayOptions('view', ['type' => 'string', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('active')
      ->setSettings(['allowed_values' => ['active' => 'Active', 'inactive' => 'Inactive']])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 30])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 30])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

}
