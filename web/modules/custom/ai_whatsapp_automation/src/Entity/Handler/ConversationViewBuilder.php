<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity\Handler;

use Drupal\ai_whatsapp_automation\Controller\ConversationController;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityViewBuilder;

/**
 * Renders conversation entities as an operator inbox.
 */
final class ConversationViewBuilder extends EntityViewBuilder {

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL): array {
    if (!$entity instanceof ContentEntityInterface) {
      return parent::view($entity, $view_mode, $langcode);
    }

    $controller = \Drupal::classResolver()->getInstanceFromDefinition(ConversationController::class);
    assert($controller instanceof ConversationController);

    return $controller->view($entity);
  }

}
