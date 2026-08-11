<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Plugin\AdminOpsTool;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Discovers AdminOps tool plugins.
 */
final class AdminOpsToolManager extends DefaultPluginManager {

  /**
   * Creates the AdminOps tool plugin manager.
   */
  public function __construct(\Traversable $namespaces, ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend) {
    parent::__construct('Plugin/AdminOpsTool', $namespaces, $module_handler, AdminOpsToolInterface::class, 'Drupal\\ai_adminops\\Attribute\\AdminOpsTool');
    $this->setCacheBackend($cache_backend, 'ai_adminops_tools');
  }

}
