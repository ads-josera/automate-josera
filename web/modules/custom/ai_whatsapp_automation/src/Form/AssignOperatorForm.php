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

    $form['operator'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Operator'),
      '#target_type' => 'user',
      '#required' => TRUE,
    ];

    return $this->addNoteField($form);
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
    $this->messenger()->addStatus($this->t('The operator has been assigned.'));
    $this->redirectToCollection($form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSubmitLabel(): string|\Stringable {
    return $this->t('Assign operator');
  }

}
