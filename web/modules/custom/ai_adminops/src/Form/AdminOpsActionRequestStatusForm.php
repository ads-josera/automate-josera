<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_adminops\Service\AdminOpsActionRequestManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reviews a pending controlled AdminOps action request.
 */
final class AdminOpsActionRequestStatusForm extends FormBase {

  /**
   * Creates an AdminOpsActionRequestStatusForm instance.
   */
  public function __construct(private readonly AdminOpsActionRequestManager $actionRequestManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('ai_adminops.action_request_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_adminops_action_request_status_form';
  }

  /**
   * Builds the action review form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $ai_adminops_action_request = NULL): array {
    if ($ai_adminops_action_request === NULL) {
      throw new \InvalidArgumentException('An AdminOps action request is required.');
    }
    $form['#attached']['library'][] = 'ai_adminops/admin';
    $form['#attributes']['class'][] = 'ai-adminops-review-form';
    $form['request_id'] = ['#type' => 'value', '#value' => $ai_adminops_action_request->id()];
    $form['warning'] = [
      '#markup' => '<p class="messages messages--warning">' . $this->t('Approval records an operator decision only. It does not execute a remote command or change a server.') . '</p>',
    ];
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Request'),
      '#markup' => '<strong>' . htmlspecialchars((string) $ai_adminops_action_request->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><br><span class="description">' . $this->t('Tool: @tool | Risk: @risk', ['@tool' => $ai_adminops_action_request->get('tool_id')->value, '@risk' => $ai_adminops_action_request->get('risk')->value]) . '</span>',
    ];

    if ((string) $ai_adminops_action_request->get('status')->value !== 'pending') {
      $form['notice'] = [
        '#markup' => '<p class="messages messages--status">' . $this->t('This request has already been reviewed.') . '</p>',
      ];
      return $form;
    }
    $form['decision'] = [
      '#type' => 'radios',
      '#title' => $this->t('Decision'),
      '#options' => [
        'approve' => $this->t('Approve request'),
        'reject' => $this->t('Reject request'),
      ],
      '#required' => TRUE,
    ];
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Operator note'),
      '#rows' => 3,
      '#description' => $this->t('Required when rejecting a request. Do not include passwords, tokens, or other secrets.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save decision'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('ai_adminops.action_requests'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('decision') === 'reject' && trim((string) $form_state->getValue('note')) === '') {
      $form_state->setErrorByName('note', $this->t('Add an operator note when rejecting a request.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $request_id = (int) $form_state->getValue('request_id');
    $user_id = (int) $this->currentUser()->id();
    try {
      if ($form_state->getValue('decision') === 'approve') {
        $this->actionRequestManager->approve($request_id, $user_id);
        $this->messenger()->addStatus($this->t('Action request approved. No remote operation has been executed.'));
      }
      else {
        $this->actionRequestManager->reject($request_id, $user_id, (string) $form_state->getValue('note'));
        $this->messenger()->addStatus($this->t('Action request rejected.'));
      }
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('The request could not be reviewed.'));
      $this->getLogger('ai_adminops')->error('Unable to review AdminOps request @request: @message', ['@request' => $request_id, '@message' => $exception->getMessage()]);
    }
    $form_state->setRedirect('ai_adminops.action_requests');
  }

}
