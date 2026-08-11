<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;

/**
 * Declares an AdminOps tool plugin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AdminOpsTool extends Plugin {

  /**
   * Creates an AdminOps tool definition.
   *
   * @param array<string, mixed> $parameters
   *   Parameter schema used for local validation.
   * @param string[] $permissions
   *   Permissions required to request the tool.
   */
  public function __construct(
    string $id,
    public readonly string $label,
    public readonly string $description,
    public readonly string $risk = 'read_only',
    public readonly array $parameters = [],
    public readonly array $permissions = ['administer ai adminops'],
  ) {
    parent::__construct($id);
  }

}
