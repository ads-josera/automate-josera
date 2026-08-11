<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores an auditable execution record for an AdminOps tool.
 */
#[ContentEntityType(
  id: 'ai_adminops_tool_execution',
  label: new TranslatableMarkup('AdminOps tool execution'),
  label_collection: new TranslatableMarkup('AdminOps tool executions'),
  label_singular: new TranslatableMarkup('AdminOps tool execution'),
  label_plural: new TranslatableMarkup('AdminOps tool executions'),
  label_count: [
    'singular' => '@count AdminOps tool execution',
    'plural' => '@count AdminOps tool executions',
  ],
  handlers: ['access' => EntityAccessControlHandler::class],
  base_table: 'ai_adminops_tool_execution',
  admin_permission: 'administer ai adminops',
  entity_keys: ['id' => 'id', 'label' => 'tool_label', 'uuid' => 'uuid'],
)]
final class AdminOpsToolExecution extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['server'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Server'))->setSetting('target_type', 'ai_adminops_server')->setRequired(TRUE);
    $fields['action_request'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Action request'))->setSetting('target_type', 'ai_adminops_action_request');
    $fields['tool_id'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Tool ID'))->setSetting('max_length', 128)->setRequired(TRUE);
    $fields['tool_label'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Tool label'))->setSetting('max_length', 255)->setRequired(TRUE);
    $fields['parameters_json'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Parameters'))->setDescription(new TranslatableMarkup('Execution parameters stored as sanitized JSON.'));
    $fields['result'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Sanitized result'));
    $fields['status'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Status'))->setRequired(TRUE)->setDefaultValue('queued')->setSetting('allowed_values', ['queued' => new TranslatableMarkup('Queued'), 'running' => new TranslatableMarkup('Running'), 'succeeded' => new TranslatableMarkup('Succeeded'), 'failed' => new TranslatableMarkup('Failed'), 'denied' => new TranslatableMarkup('Denied')]);
    $fields['risk'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Risk level'))->setRequired(TRUE)->setDefaultValue('read_only')->setSetting('allowed_values', ['read_only' => new TranslatableMarkup('Read only'), 'controlled' => new TranslatableMarkup('Controlled'), 'critical' => new TranslatableMarkup('Critical')]);
    $fields['initiated_by'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Initiated by'))->setSetting('target_type', 'user');
    $fields['started_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Started at'));
    $fields['completed_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Completed at'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
