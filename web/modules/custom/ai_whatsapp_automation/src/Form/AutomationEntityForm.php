<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides the default form for AI WhatsApp Automation content entities.
 */
final class AutomationEntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $entity = $this->getEntity();

    if ($entity->getEntityTypeId() === 'ai_whatsapp_bot' && !$entity->isNew()) {
      $form['#attached']['library'][] = 'ai_whatsapp_automation/integration_admin';
      $form['web_integration_action'] = [
        '#type' => 'link',
        '#title' => $this->t('Web integration'),
        '#url' => Url::fromRoute('ai_whatsapp_automation.bot_web_integration', [
          'ai_whatsapp_bot' => $entity->id(),
        ]),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'aiwa-web-integration-form-link'],
        ],
        '#weight' => -100,
      ];
    }

    return $form;
  }

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
