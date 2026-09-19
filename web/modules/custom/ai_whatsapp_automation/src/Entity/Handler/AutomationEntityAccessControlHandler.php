<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity\Handler;

use Drupal\ai_whatsapp_automation\Access\ClientAccess;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for AI WhatsApp Automation entities.
 *
 * Administrators manage everything. Client users may only view the
 * conversations, messages and leads of their own client; they act on them
 * through dedicated forms (reply, close, lead status), never through the
 * generic edit and delete forms.
 */
final class AutomationEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    $admin = AccessResult::allowedIfHasPermission($account, ClientAccess::ADMIN_PERMISSION)
      ->cachePerPermissions();
    if ($admin->isAllowed() || $operation !== 'view' || !isset(ClientAccess::CLIENT_PATHS[$entity->getEntityTypeId()])) {
      return $admin;
    }

    $client_access = \Drupal::service('ai_whatsapp_automation.client_access');

    return AccessResult::allowedIf($client_access->ownsRecord($account, $entity, ClientAccess::VIEW_PERMISSION))
      ->cachePerUser()
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, ClientAccess::ADMIN_PERMISSION)
      ->cachePerPermissions();
  }

}
