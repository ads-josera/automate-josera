<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides filters for the WhatsApp message inbox.
 */
final class MessageListFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_message_list_filters';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('entity.ai_whatsapp_message.collection')->toString();
    $form['#attributes']['class'][] = 'aiwa-message-filters';

    $form['q'] = [
      '#type' => 'search',
      '#title' => $this->t('Buscar mensajes'),
      '#default_value' => (string) $request->query->get('q', ''),
      '#size' => 36,
      '#placeholder' => $this->t('Texto o ID del proveedor'),
    ];
    $form['sender'] = [
      '#type' => 'select',
      '#title' => $this->t('Dirección'),
      '#options' => [
        '' => $this->t('Todas'),
        'contact' => $this->t('Contacto'),
        'ai' => $this->t('IA'),
        'operator' => $this->t('Operador'),
        'system' => $this->t('Sistema'),
      ],
      '#default_value' => (string) $request->query->get('sender', ''),
    ];
    $form['conversation'] = [
      '#type' => 'number',
      '#title' => $this->t('ID de conversación'),
      '#default_value' => (string) $request->query->get('conversation', ''),
      '#min' => 1,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filtrar'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Limpiar'),
      '#url' => Url::fromRoute('entity.ai_whatsapp_message.collection'),
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
