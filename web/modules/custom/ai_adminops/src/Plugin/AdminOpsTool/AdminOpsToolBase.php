<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\Core\Plugin\PluginBase;

/**
 * Base implementation for declarative AdminOps tools.
 */
abstract class AdminOpsToolBase extends PluginBase implements AdminOpsToolInterface {

  /**
   * {@inheritdoc}
   */
  public function getRisk(): string {
    return (string) ($this->pluginDefinition['risk'] ?? 'read_only');
  }

  /**
   * {@inheritdoc}
   */
  public function getParameters(): array {
    return $this->pluginDefinition['parameters'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $parameters): void {
    foreach ($this->getParameters() as $name => $definition) {
      if (($definition['required'] ?? FALSE) && (!array_key_exists($name, $parameters) || $parameters[$name] === '')) {
        throw new \InvalidArgumentException(sprintf('The "%s" parameter is required.', $name));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $parameters): array {
    throw new \LogicException(sprintf('The "%s" tool has no connector enabled yet.', $this->getPluginId()));
  }

}
