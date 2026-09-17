<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;

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
   * Route access: only channels that can deliver an operator reply.
   */
  public static function access(AccountInterface $account, ContentEntityInterface $ai_whatsapp_conversation): AccessResultInterface {
    return AccessResult::allowedIf(\Drupal::service('ai_whatsapp_automation.human_operator')->supportsManualReply($ai_whatsapp_conversation))
      ->addCacheableDependency($ai_whatsapp_conversation);
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
    $status = (string) ($delivery['status'] ?? '');
    if ($status === 'sent') {
      $this->messenger()->addStatus($this->t('Respuesta enviada por WhatsApp.'));
    }
    else {
      // The reply is already stored in the history, so the operator must not
      // read a success message when the contact did not receive it.
      $reasons = [
        'skipped_missing_configuration' => $this->t('la cuenta de WhatsApp de esta conversación no tiene credenciales configuradas'),
        'failed' => $this->t('el proveedor rechazó el envío'),
        'unsupported_provider' => $this->t('este canal no permite respuestas manuales'),
      ];
      $this->messenger()->addWarning($this->t('La respuesta quedó en el historial, pero no se envió al contacto: @reason. Revisa la cuenta en «WhatsApp accounts» o contacta a la persona por otro medio.', [
        '@reason' => $reasons[$status] ?? $this->t('el envío no se completó'),
      ]));
    }
    $this->redirectToCollection($form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getSubmitLabel(): string|\Stringable {
    return $this->t('Send reply');
  }

}
