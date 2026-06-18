<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity\Handler;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides access checks for AI WhatsApp Automation content entities.
 */
final class AutomationEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer ai whatsapp automation entities')
      ->cachePerPermissions();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'administer ai whatsapp automation entities')
      ->cachePerPermissions();
  }

}
