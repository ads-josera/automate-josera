<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Changes only the status of a lead.
 *
 * Client users follow up their leads here; the generic edit form, which also
 * exposes contact data and references, stays for administrators.
 */
final class LeadStatusForm extends FormBase {

  /**
   * The lead being updated.
   */
  private ContentEntityInterface $lead;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_lead_status_form';
  }

  /**
   * Builds the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $ai_whatsapp_lead = NULL): array {
    $this->lead = $ai_whatsapp_lead;
    $form['summary'] = [
      '#markup' => '<p>' . $this->t('Lead: %name · %phone', [
        '%name' => $this->lead->label(),
        '%phone' => (string) ($this->lead->get('phone')->value ?: $this->t('sin teléfono')),
      ]) . '</p>',
    ];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Estado'),
      '#options' => self::statusOptions(),
      '#default_value' => (string) $this->lead->get('status')->value,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Guardar estado'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!isset(self::statusOptions()[(string) $form_state->getValue('status')])) {
      $form_state->setErrorByName('status', $this->t('Elige un estado válido.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->lead->set('status', (string) $form_state->getValue('status'))->save();
    $this->messenger()->addStatus($this->t('Estado del lead actualizado: %status.', [
      '%status' => self::statusOptions()[(string) $form_state->getValue('status')],
    ]));
    $form_state->setRedirect('entity.ai_whatsapp_lead.collection');
  }

  /**
   * Lead statuses with their labels.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by status value.
   */
  public static function statusOptions(): array {
    return [
      'new' => t('Nuevo'),
      'contacted' => t('Contactado'),
      'qualified' => t('Calificado'),
      'disqualified' => t('Descartado'),
      'converted' => t('Convertido'),
    ];
  }

}
