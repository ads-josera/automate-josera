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
 * Stores an approval request for a controlled AdminOps action.
 */
#[ContentEntityType(
  id: 'ai_adminops_action_request',
  label: new TranslatableMarkup('AdminOps action request'),
  label_collection: new TranslatableMarkup('AdminOps action requests'),
  label_singular: new TranslatableMarkup('AdminOps action request'),
  label_plural: new TranslatableMarkup('AdminOps action requests'),
  label_count: [
    'singular' => '@count AdminOps action request',
    'plural' => '@count AdminOps action requests',
  ],
  handlers: ['access' => EntityAccessControlHandler::class],
  base_table: 'ai_adminops_action_request',
  admin_permission: 'administer ai adminops',
  entity_keys: ['id' => 'id', 'label' => 'title', 'uuid' => 'uuid'],
)]
final class AdminOpsActionRequest extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['server'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Server'))->setSetting('target_type', 'ai_adminops_server')->setRequired(TRUE);
    $fields['tool_id'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Tool ID'))->setSetting('max_length', 128)->setRequired(TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Title'))->setSetting('max_length', 255)->setRequired(TRUE);
    $fields['parameters_json'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Parameters'))->setDescription(new TranslatableMarkup('Action parameters stored as sanitized JSON.'));
    $fields['risk'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Risk level'))->setRequired(TRUE)->setDefaultValue('controlled')->setSetting('allowed_values', ['controlled' => new TranslatableMarkup('Controlled'), 'critical' => new TranslatableMarkup('Critical')]);
    $fields['status'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Status'))->setRequired(TRUE)->setDefaultValue('pending')->setSetting('allowed_values', ['pending' => new TranslatableMarkup('Pending approval'), 'approved' => new TranslatableMarkup('Approved'), 'rejected' => new TranslatableMarkup('Rejected'), 'executed' => new TranslatableMarkup('Executed'), 'failed' => new TranslatableMarkup('Failed'), 'expired' => new TranslatableMarkup('Expired')]);
    $fields['requested_by'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Requested by'))->setSetting('target_type', 'user');
    $fields['approved_by'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Approved by'))->setSetting('target_type', 'user');
    $fields['note'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Operator note'));
    $fields['requested_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Requested at'))->setRequired(TRUE);
    $fields['approved_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Approved at'));
    $fields['executed_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Executed at'));
    $fields['expires_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Expires at'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
