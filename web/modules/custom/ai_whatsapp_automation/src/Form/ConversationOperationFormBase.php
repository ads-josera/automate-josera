<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\ai_whatsapp_automation\Application\HumanOperator\HumanOperatorService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for conversation operation forms.
 */
abstract class ConversationOperationFormBase extends FormBase {

  /**
   * The conversation being operated on.
   */
  protected ContentEntityInterface $conversation;

  /**
   * Constructs a ConversationOperationFormBase object.
   */
  public function __construct(
    protected readonly HumanOperatorService $humanOperator,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_whatsapp_automation.human_operator'),
    );
  }

  /**
   * Builds the common note field.
   */
  protected function addNoteField(array $form): array {
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note'),
      '#rows' => 3,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->getSubmitLabel(),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Returns the submit button label.
   */
  abstract protected function getSubmitLabel(): string|\Stringable;

  /**
   * Redirects back to the conversation collection.
   */
  protected function redirectToCollection(FormStateInterface $form_state): void {
    $form_state->setRedirect('entity.ai_whatsapp_conversation.collection');
  }

}
