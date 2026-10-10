<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Reads one fixed monitoring check from a server.
 *
 * ReadOnlySshConnector is the production implementation. The interface lets
 * the monitoring pipeline be tested without reaching a real server.
 */
interface MonitoringCollectorInterface {

  /**
   * Checks whether the server can be read at all.
   */
  public function isConfigured(ConfigEntityInterface $server): bool;

  /**
   * Returns the normalised output of one check.
   *
   * @return array<string, mixed>
   *   The check's output. 'status' is 'ok' when it carries measurements.
   *
   * @throws \Throwable
   *   When the server could not be read.
   */
  public function collect(ConfigEntityInterface $server, string $tool_id): array;

}
