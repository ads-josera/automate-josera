<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Access;

use Drupal\ai_whatsapp_automation\Application\HumanOperator\HumanOperatorService;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Decides what a client's user may see and do.
 *
 * Administrators ("administer ai whatsapp automation entities") keep full
 * access. A client user has the "access own client" permissions and a client
 * set on their account (field ai_whatsapp_client); they only reach
 * conversations, messages and leads of that client. Enforced in three layers
 * that all use this service: entity access, access-checked queries
 * (hook_query_TAG_alter) and route access for actions.
 */
final class ClientAccess {

  /**
   * Permission that grants full access.
   */
  public const ADMIN_PERMISSION = 'administer ai whatsapp automation entities';

  /**
   * Permission to view the own client's records.
   */
  public const VIEW_PERMISSION = 'access own client ai whatsapp records';

  /**
   * Permission to act on the own client's conversations and leads.
   */
  public const OPERATE_PERMISSION = 'operate own client ai whatsapp conversations';

  /**
   * Records a client user may view, with the path to their client.
   */
  public const CLIENT_PATHS = [
    'ai_whatsapp_conversation' => 'client',
    'ai_whatsapp_lead' => 'client',
    'ai_whatsapp_message' => 'conversation',
  ];

  /**
   * Constructs a ClientAccess object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly HumanOperatorService $humanOperator,
  ) {
  }

  /**
   * Whether the account has full access.
   */
  public function isAdmin(AccountInterface $account): bool {
    return $account->hasPermission(self::ADMIN_PERMISSION);
  }

  /**
   * Returns the client ID set on a user account, or NULL.
   */
  public function clientId(AccountInterface $account): ?int {
    if ($account->isAnonymous()) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($account->id());
    if ($user === NULL || !$user->hasField('ai_whatsapp_client') || $user->get('ai_whatsapp_client')->isEmpty()) {
      return NULL;
    }

    return (int) $user->get('ai_whatsapp_client')->target_id;
  }

  /**
   * Returns the client ID a record belongs to, or NULL.
   */
  public function recordClientId(EntityInterface $entity): ?int {
    if (!$entity instanceof ContentEntityInterface) {
      return NULL;
    }
    if ($entity->getEntityTypeId() === 'ai_whatsapp_message') {
      $conversation = $entity->get('conversation')->entity;
      return $conversation instanceof ContentEntityInterface ? $this->recordClientId($conversation) : NULL;
    }
    if (!$entity->hasField('client') || $entity->get('client')->isEmpty()) {
      return NULL;
    }

    return (int) $entity->get('client')->target_id;
  }

  /**
   * Whether a client user may reach a record with the given permission.
   */
  public function ownsRecord(AccountInterface $account, EntityInterface $entity, string $permission): bool {
    $client_id = $this->clientId($account);

    return $account->hasPermission($permission)
      && $client_id !== NULL
      && $this->recordClientId($entity) === $client_id;
  }

  /**
   * Route access for conversation actions (pause, reactivate, close, assign).
   */
  public function operateConversation(AccountInterface $account, ContentEntityInterface $ai_whatsapp_conversation): AccessResultInterface {
    return $this->operate($account, $ai_whatsapp_conversation);
  }

  /**
   * Route access for manual replies: operable and deliverable channel.
   */
  public function manualReply(AccountInterface $account, ContentEntityInterface $ai_whatsapp_conversation): AccessResultInterface {
    $deliverable = $this->humanOperator->supportsManualReply($ai_whatsapp_conversation);

    return $this->operate($account, $ai_whatsapp_conversation)
      ->andIf(AccessResult::allowedIf($deliverable)->addCacheableDependency($ai_whatsapp_conversation));
  }

  /**
   * Route access for changing a lead's status.
   */
  public function operateLead(AccountInterface $account, ContentEntityInterface $ai_whatsapp_lead): AccessResultInterface {
    return $this->operate($account, $ai_whatsapp_lead);
  }

  /**
   * Users an operator may assign a conversation to.
   *
   * Administrators may pick any user (autocomplete); client users only users
   * of their own client.
   *
   * @return array<int, string>
   *   User labels keyed by user ID.
   */
  public function assignableOperators(AccountInterface $account): array {
    $client_id = $this->clientId($account);
    if ($client_id === NULL) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('user');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('ai_whatsapp_client', $client_id)
      ->sort('name')
      ->execute();
    $options = [];
    foreach ($storage->loadMultiple($ids) as $user) {
      $options[(int) $user->id()] = (string) $user->getDisplayName();
    }

    return $options;
  }

  /**
   * Admin, or operate permission on an own record.
   */
  private function operate(AccountInterface $account, ContentEntityInterface $entity): AccessResultInterface {
    if ($this->isAdmin($account)) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return AccessResult::allowedIf($this->ownsRecord($account, $entity, self::OPERATE_PERMISSION))
      ->cachePerUser()
      ->addCacheableDependency($entity);
  }

}
