<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Assigns an operator to a conversation.
 */
final class AssignOperatorForm extends ConversationOperationFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_assign_operator_form';
  }

  /**
   * Builds the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, mixed $ai_whatsapp_conversation = NULL): array {
    $this->conversation = $ai_whatsapp_conversation;

    $client_access = \Drupal::service('ai_whatsapp_automation.client_access');
    if ($client_access->isAdmin($this->currentUser())) {
      $form['operator'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Operador'),
        '#target_type' => 'user',
        '#required' => TRUE,
      ];
    }
    else {
      // Client users may only hand a conversation to someone of their client.
      $form['operator'] = [
        '#type' => 'select',
        '#title' => $this->t('Operador'),
        '#options' => $client_access->assignableOperators($this->currentUser()),
        '#required' => TRUE,
      ];
    }

    return $this->addNoteField($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $client_access = \Drupal::service('ai_whatsapp_automation.client_access');
    if (!$client_access->isAdmin($this->currentUser())
      && !isset($client_access->assignableOperators($this->currentUser())[(int) $form_state->getValue('operator')])) {
      $form_state->setErrorByName('operator', $this->t('Elige un operador de tu empresa.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->humanOperator->assignOperator(
      $this->conversation,
      (string) $form_state->getValue('operator'),
      (string) $form_state->getValue('note')
    );
    $this->messenger()->addStatus($this->t('Operador asignado.'));
    $this->redirectToCollection($form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSubmitLabel(): string|\Stringable {
    return $this->t('Asignar operador');
  }

}
