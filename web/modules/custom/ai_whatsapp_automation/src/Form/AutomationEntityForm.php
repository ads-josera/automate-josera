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

    if ($entity->getEntityTypeId() === 'ai_whatsapp_bot' && isset($form['web_widget_logo_url'])) {
      // Kept as a rendering fallback for existing bots, but new logos upload
      // through the file field instead of requiring a public URL.
      $form['web_widget_logo_url']['#access'] = FALSE;
    }

    if ($entity->getEntityTypeId() === 'ai_whatsapp_account' && isset($form['twilio_auth_token']['widget'][0]['value'])) {
      $form['twilio_auth_token']['widget'][0]['value']['#type'] = 'password';
      $form['twilio_auth_token']['widget'][0]['value']['#default_value'] = '';
      $form['twilio_auth_token']['widget'][0]['value']['#description'] = $entity->get('twilio_auth_token')->isEmpty()
        ? $this->t('Enter the Twilio Auth Token for this account.')
        : $this->t('A token is already configured. Leave empty to keep it.');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->getEntity();
    if (
      $entity->getEntityTypeId() === 'ai_whatsapp_account'
      && !$entity->isNew()
      && $entity->hasField('twilio_auth_token')
      && trim((string) $entity->get('twilio_auth_token')->value) === ''
    ) {
      $original = \Drupal::entityTypeManager()
        ->getStorage('ai_whatsapp_account')
        ->loadUnchanged($entity->id());
      if ($original !== NULL && !$original->get('twilio_auth_token')->isEmpty()) {
        $entity->set('twilio_auth_token', $original->get('twilio_auth_token')->value);
      }
    }

    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('Saved %label.', [
      '%label' => $entity->label(),
    ]));

    $form_state->setRedirectUrl($entity->toUrl('collection'));

    return $result;
  }

}
