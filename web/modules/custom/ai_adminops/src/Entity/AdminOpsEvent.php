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
 * Stores a normalized operational event for an AdminOps server.
 */
#[ContentEntityType(
  id: 'ai_adminops_event',
  label: new TranslatableMarkup('AdminOps event'),
  label_collection: new TranslatableMarkup('AdminOps events'),
  label_singular: new TranslatableMarkup('AdminOps event'),
  label_plural: new TranslatableMarkup('AdminOps events'),
  label_count: [
    'singular' => '@count AdminOps event',
    'plural' => '@count AdminOps events',
  ],
  handlers: ['access' => EntityAccessControlHandler::class],
  base_table: 'ai_adminops_event',
  admin_permission: 'administer ai adminops',
  entity_keys: ['id' => 'id', 'label' => 'summary', 'uuid' => 'uuid'],
)]
final class AdminOpsEvent extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['server'] = BaseFieldDefinition::create('entity_reference')->setLabel(new TranslatableMarkup('Server'))->setSetting('target_type', 'ai_adminops_server')->setRequired(TRUE);
    $fields['event_type'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Event type'))->setSetting('max_length', 128)->setRequired(TRUE);
    $fields['severity'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Severity'))->setRequired(TRUE)->setDefaultValue('info')->setSetting('allowed_values', ['info' => new TranslatableMarkup('Info'), 'warning' => new TranslatableMarkup('Warning'), 'critical' => new TranslatableMarkup('Critical')]);
    $fields['summary'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Summary'))->setSetting('max_length', 255)->setRequired(TRUE);
    $fields['details'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Details'));
    $fields['evidence_json'] = BaseFieldDefinition::create('string_long')->setLabel(new TranslatableMarkup('Sanitized evidence'))->setDescription(new TranslatableMarkup('Structured evidence stored as sanitized JSON.'));
    $fields['fingerprint'] = BaseFieldDefinition::create('string')->setLabel(new TranslatableMarkup('Fingerprint'))->setDescription(new TranslatableMarkup('Stable key used to identify duplicate events.'))->setSetting('max_length', 128);
    $fields['status'] = BaseFieldDefinition::create('list_string')->setLabel(new TranslatableMarkup('Status'))->setRequired(TRUE)->setDefaultValue('open')->setSetting('allowed_values', ['open' => new TranslatableMarkup('Open'), 'acknowledged' => new TranslatableMarkup('Acknowledged'), 'resolved' => new TranslatableMarkup('Resolved'), 'suppressed' => new TranslatableMarkup('Suppressed')]);
    $fields['occurred_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Occurred at'))->setRequired(TRUE);
    $fields['resolved_at'] = BaseFieldDefinition::create('timestamp')->setLabel(new TranslatableMarkup('Resolved at'));
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(new TranslatableMarkup('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
