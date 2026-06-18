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
 * Defines a RAG source document.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_knowledge_document',
  label: new TranslatableMarkup('Knowledge document'),
  label_collection: new TranslatableMarkup('Knowledge documents'),
  label_singular: new TranslatableMarkup('Knowledge document'),
  label_plural: new TranslatableMarkup('Knowledge documents'),
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
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
    'canonical' => '/admin/content/ai-whatsapp/knowledge-documents/{ai_whatsapp_knowledge_document}',
    'collection' => '/admin/content/ai-whatsapp/knowledge-documents',
    'add-form' => '/admin/content/ai-whatsapp/knowledge-documents/add',
    'edit-form' => '/admin/content/ai-whatsapp/knowledge-documents/{ai_whatsapp_knowledge_document}/edit',
    'delete-form' => '/admin/content/ai-whatsapp/knowledge-documents/{ai_whatsapp_knowledge_document}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation rag',
  base_table: 'ai_whatsapp_knowledge_document',
)]
final class KnowledgeDocument extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['knowledge_base'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Knowledge base'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ai_whatsapp_knowledge_base')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 0])
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 10])
      ->setDisplayOptions('view', ['type' => 'string', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['file'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('File'))
      ->setSetting('target_type', 'file')
      ->setSetting('handler', 'default')
      ->setDisplayOptions('view', ['type' => 'entity_reference_label', 'weight' => 20])
      ->setDisplayConfigurable('view', TRUE);

    $fields['mime_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('MIME type'))
      ->setSetting('max_length', 128);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Status'))
      ->setRequired(TRUE)
      ->setDefaultValue('indexed')
      ->setSettings(['allowed_values' => ['indexed' => 'Indexed', 'failed' => 'Failed']])
      ->setDisplayOptions('view', ['type' => 'list_default', 'weight' => 30])
      ->setDisplayConfigurable('view', TRUE);

    $fields['chunk_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Chunk count'))
      ->setDefaultValue(0)
      ->setSetting('unsigned', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    return $fields;
  }

}
