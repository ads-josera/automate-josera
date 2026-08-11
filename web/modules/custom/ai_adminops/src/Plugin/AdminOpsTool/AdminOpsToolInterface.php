<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Common contract for every AdminOps operation.
 */
interface AdminOpsToolInterface extends PluginInspectionInterface {

  /**
   * Returns the declared risk level.
   */
  public function getRisk(): string;

  /**
   * Returns the parameter schema.
   *
   * @return array<string, mixed>
   *   Parameter definitions.
   */
  public function getParameters(): array;

  /**
   * Validates a normalized parameter payload.
   *
   * @param array<string, mixed> $parameters
   *   Submitted parameters.
   */
  public function validate(array $parameters): void;

  /**
   * Executes the tool when a future connector explicitly enables it.
   *
   * @param array<string, mixed> $parameters
   *   Validated parameters.
   *
   * @return array<string, mixed>
   *   Sanitizable result data.
   */
  public function execute(array $parameters): array;

}
