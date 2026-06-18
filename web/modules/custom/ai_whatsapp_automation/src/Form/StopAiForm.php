<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Stops AI for a conversation.
 */
final class StopAiForm extends ConversationOperationFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_stop_ai_form';
  }

  /**
   * Builds the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, mixed $ai_whatsapp_conversation = NULL): array {
    $this->conversation = $ai_whatsapp_conversation;

    return $this->addNoteField($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->humanOperator->stopAi($this->conversation, (string) $form_state->getValue('note'));
    $this->messenger()->addStatus($this->t('AI has been stopped for the conversation.'));
    $this->redirectToCollection($form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSubmitLabel(): string|\Stringable {
    return $this->t('Stop AI');
  }

}
