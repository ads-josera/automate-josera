<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides filters for the WhatsApp conversation inbox.
 */
final class ConversationListFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_conversation_list_filters';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('entity.ai_whatsapp_conversation.collection')->toString();
    $form['#attributes']['class'][] = 'aiwa-conversation-filters';

    $form['q'] = [
      '#type' => 'search',
      '#title' => $this->t('Buscar contacto'),
      '#default_value' => (string) $request->query->get('q', ''),
      '#size' => 32,
      '#placeholder' => $this->t('Nombre o teléfono'),
    ];
    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Origen'),
      '#options' => [
        '' => $this->t('Todos'),
        'twilio' => $this->t('WhatsApp Twilio'),
        'cloud_api' => $this->t('WhatsApp Cloud API'),
        'evolution' => $this->t('WhatsApp Evolution'),
        'web' => $this->t('Chat web'),
      ],
      '#default_value' => (string) $request->query->get('provider', ''),
    ];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Estado'),
      '#options' => [
        '' => $this->t('Todos'),
        'AI_ACTIVE' => $this->t('IA activa'),
        'HUMAN_ASSIGNED' => $this->t('Atención humana'),
        'CLOSED' => $this->t('Cerrada'),
      ],
      '#default_value' => (string) $request->query->get('status', ''),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filtrar'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Limpiar'),
      '#url' => Url::fromRoute('entity.ai_whatsapp_conversation.collection'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
