<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Closes a conversation.
 */
final class CloseConversationForm extends ConversationOperationFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_close_conversation_form';
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
    $this->humanOperator->closeConversation($this->conversation, (string) $form_state->getValue('note'));
    $this->messenger()->addStatus($this->t('The conversation has been closed.'));
    $this->redirectToCollection($form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSubmitLabel(): string|\Stringable {
    return $this->t('Close conversation');
  }

}
