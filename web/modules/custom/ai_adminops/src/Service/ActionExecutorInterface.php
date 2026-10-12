<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Runs one approved action from a fixed list on a server.
 *
 * ActionSshConnector is the production implementation. The interface lets
 * the approval flow be tested without touching a real server.
 */
interface ActionExecutorInterface {

  /**
   * Actions that exist, by ID, with their label.
   */
  public const ACTIONS = [
    'restart_apache' => 'Reinicio graceful de Apache',
    'restart_php_fpm' => 'Reinicio de PHP-FPM',
  ];

  /**
   * Checks whether actions can run on this server at all.
   */
  public function isConfigured(ConfigEntityInterface $server): bool;

  /**
   * Runs one action and returns what the server reported.
   *
   * @return array<string, mixed>
   *   'status' is 'ok' when the action finished well.
   *
   * @throws \Throwable
   *   When the server could not be reached or refused the action.
   */
  public function run(ConfigEntityInterface $server, string $action): array;

}
