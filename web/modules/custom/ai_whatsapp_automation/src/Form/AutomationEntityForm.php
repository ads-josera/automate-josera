<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides the default form for AI WhatsApp Automation content entities.
 */
final class AutomationEntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $entity = $this->getEntity();

    $this->messenger()->addStatus($this->t('Saved %label.', [
      '%label' => $entity->label(),
    ]));

    $form_state->setRedirectUrl($entity->toUrl('collection'));

    return $result;
  }

}
