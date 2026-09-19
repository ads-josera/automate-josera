<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Routing;

use Drupal\ai_whatsapp_automation\Access\ClientAccess;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Opens the conversation, message and lead lists to client users.
 *
 * Entity collection routes require the admin permission by default. Client
 * users may also open these three lists; what they see is limited to their
 * own client by the query alters in ai_whatsapp_automation.module.
 */
final class ClientAccessRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (array_keys(ClientAccess::CLIENT_PATHS) as $entity_type_id) {
      $route = $collection->get('entity.' . $entity_type_id . '.collection');
      if ($route !== NULL) {
        // "+" means OR in Drupal permission requirements.
        $route->setRequirement('_permission', ClientAccess::ADMIN_PERMISSION . '+' . ClientAccess::VIEW_PERMISSION);
      }
    }
  }

}
