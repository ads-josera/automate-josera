<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity;

use Drupal\ai_whatsapp_automation\Entity\Handler\AutomationEntityAccessControlHandler;
use Drupal\ai_whatsapp_automation\Entity\Handler\AutomationEntityListBuilder;
use Drupal\ai_whatsapp_automation\Entity\Storage\AutomationEntityStorage;
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
 * Defines an indexed RAG chunk.
 */
#[ContentEntityType(
  id: 'ai_whatsapp_knowledge_chunk',
  label: new TranslatableMarkup('Knowledge chunk'),
  label_collection: new TranslatableMarkup('Knowledge chunks'),
  label_singular: new TranslatableMarkup('Knowledge chunk'),
  label_plural: new TranslatableMarkup('Knowledge chunks'),
  entity_keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
  handlers: [
    'storage' => AutomationEntityStorage::class,
    'list_builder' => AutomationEntityListBuilder::class,
    'view_builder' => EntityViewBuilder::class,
    'access' => AutomationEntityAccessControlHandler::class,
    'form' => ['delete' => ContentEntityDeleteForm::class],
    'route_provider' => ['html' => DefaultHtmlRouteProvider::class],
    'views_data' => EntityViewsData::class,
  ],
  links: [
    'canonical' => '/admin/content/ai-whatsapp/knowledge-chunks/{ai_whatsapp_knowledge_chunk}',
    'collection' => '/admin/content/ai-whatsapp/knowledge-chunks',
    'delete-form' => '/admin/content/ai-whatsapp/knowledge-chunks/{ai_whatsapp_knowledge_chunk}/delete',
  ],
  admin_permission: 'administer ai whatsapp automation rag',
  base_table: 'ai_whatsapp_knowledge_chunk',
)]
final class KnowledgeChunk extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['knowledge_base'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Knowledge base'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ai_whatsapp_knowledge_base')
      ->setSetting('handler', 'default');

    $fields['document'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Document'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'ai_whatsapp_knowledge_document')
      ->setSetting('handler', 'default');

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setSetting('max_length', 255);

    $fields['chunk_index'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Chunk index'))
      ->setRequired(TRUE)
      ->setSetting('unsigned', TRUE);

    $fields['content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Content'))
      ->setRequired(TRUE);

    $fields['embedding'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Embedding JSON'))
      ->setRequired(TRUE);

    $fields['embedding_model'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Embedding model'))
      ->setSetting('max_length', 128);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    return $fields;
  }

}
