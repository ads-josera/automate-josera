<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Sends a manual operator reply.
 */
final class ManualReplyForm extends ConversationOperationFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_manual_reply_form';
  }

  /**
   * Builds the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, mixed $ai_whatsapp_conversation = NULL): array {
    $this->conversation = $ai_whatsapp_conversation;

    $form['reply'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reply'),
      '#rows' => 5,
      '#required' => TRUE,
    ];

    return $this->addNoteField($form);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->humanOperator->replyManually(
      $this->conversation,
      (string) $form_state->getValue('reply'),
      (string) $form_state->getValue('note')
    );

    $delivery = is_array($result['delivery'] ?? NULL) ? $result['delivery'] : [];
    $this->messenger()->addStatus($this->t('Manual reply saved. Delivery status: @status.', [
      '@status' => (string) ($delivery['status'] ?? 'unknown'),
    ]));
    $this->redirectToCollection($form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSubmitLabel(): string|\Stringable {
    return $this->t('Send reply');
  }

}
