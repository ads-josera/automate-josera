<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\ai_adminops\Plugin\AdminOpsTool\AdminOpsToolInterface;
use Drupal\ai_adminops\Plugin\AdminOpsTool\AdminOpsToolManager;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Resolves tool access and approval requirements without executing tools.
 */
final class AdminOpsToolPolicy {

  /**
   * Creates an AdminOpsToolPolicy instance.
   */
  public function __construct(
    private readonly AdminOpsToolManager $toolManager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Returns a tool after checking its declared access requirements.
   */
  public function getAllowedTool(string $tool_id): AdminOpsToolInterface {
    if (!$this->toolManager->hasDefinition($tool_id)) {
      throw new \InvalidArgumentException(sprintf('AdminOps tool "%s" does not exist.', $tool_id));
    }
    $definition = $this->toolManager->getDefinition($tool_id);
    foreach ($definition['permissions'] ?? [] as $permission) {
      if (!$this->currentUser->hasPermission($permission)) {
        throw new \LogicException('You do not have permission to request this AdminOps tool.');
      }
    }
    return $this->toolManager->createInstance($tool_id);
  }

  /**
   * Returns whether the declared tool requires a human approval request.
   */
  public function requiresApproval(AdminOpsToolInterface $tool): bool {
    return in_array($tool->getRisk(), ['controlled', 'critical'], TRUE);
  }

}
