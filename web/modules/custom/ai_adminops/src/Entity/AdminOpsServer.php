<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an AdminOps server configuration entity.
 */
#[ConfigEntityType(
  id: 'ai_adminops_server',
  label: new TranslatableMarkup('AdminOps server'),
  label_collection: new TranslatableMarkup('AdminOps servers'),
  label_singular: new TranslatableMarkup('AdminOps server'),
  label_plural: new TranslatableMarkup('AdminOps servers'),
  label_count: [
    'singular' => '@count AdminOps server',
    'plural' => '@count AdminOps servers',
  ],
  config_prefix: 'server',
  admin_permission: 'administer ai adminops',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  config_export: [
    'id',
    'label',
    'hostname',
    'port',
    'operating_system',
    'provider',
    'connection_type',
    'credential_reference',
    'server_status',
    'description',
    'active',
    'tags',
  ],
)]
final class AdminOpsServer extends ConfigEntityBase {

  protected string $id;
  protected string $label;
  protected string $hostname = '';
  protected int $port = 22;
  protected string $operating_system = '';
  protected string $provider = '';
  protected string $connection_type = 'ssh';
  protected string $credential_reference = '';
  protected string $server_status = 'unknown';
  protected string $description = '';
  protected bool $active = TRUE;

  /**
   * Free-form categorization tags.
   *
   * @var string[]
   */
  protected array $tags = [];

}
