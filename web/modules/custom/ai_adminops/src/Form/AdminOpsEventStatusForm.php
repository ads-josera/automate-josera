<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_adminops\Service\AdminOpsEventManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates the lifecycle of a normalized operational event.
 */
final class AdminOpsEventStatusForm extends FormBase {

  /**
   * Creates an AdminOpsEventStatusForm instance.
   */
  public function __construct(private readonly AdminOpsEventManager $eventManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('ai_adminops.event_manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_adminops_event_status_form';
  }

  /**
   * Builds the event lifecycle form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $ai_adminops_event = NULL): array {
    if ($ai_adminops_event === NULL) {
      throw new \InvalidArgumentException('An AdminOps event is required.');
    }
    $form['#attached']['library'][] = 'ai_adminops/admin';
    $form['#attributes']['class'][] = 'ai-adminops-review-form';
    $form['event_id'] = ['#type' => 'value', '#value' => $ai_adminops_event->id()];
    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Event'),
      '#markup' => '<strong>' . htmlspecialchars((string) $ai_adminops_event->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><br><span class="description">' . htmlspecialchars((string) $ai_adminops_event->get('event_type')->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
    ];

    $status = (string) $ai_adminops_event->get('status')->value;
    if ($status === 'resolved') {
      $form['notice'] = [
        '#markup' => '<p class="messages messages--status">' . $this->t('This event is already resolved.') . '</p>',
      ];
      return $form;
    }
    $form['operation'] = [
      '#type' => 'radios',
      '#title' => $this->t('Update status'),
      '#options' => [
        'acknowledge' => $this->t('Acknowledge: an operator is reviewing this event.'),
        'resolve' => $this->t('Resolve: the condition has been addressed.'),
      ],
      '#default_value' => $status === 'acknowledged' ? 'resolve' : 'acknowledge',
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save status'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('ai_adminops.events'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $event_id = (int) $form_state->getValue('event_id');
    try {
      if ($form_state->getValue('operation') === 'resolve') {
        $this->eventManager->resolve($event_id);
        $this->messenger()->addStatus($this->t('Event resolved.'));
      }
      else {
        $this->eventManager->acknowledge($event_id);
        $this->messenger()->addStatus($this->t('Event acknowledged.'));
      }
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('The event could not be updated.'));
      $this->getLogger('ai_adminops')->error('Unable to update AdminOps event @event: @message', ['@event' => $event_id, '@message' => $exception->getMessage()]);
    }
    $form_state->setRedirect('ai_adminops.events');
  }

}
