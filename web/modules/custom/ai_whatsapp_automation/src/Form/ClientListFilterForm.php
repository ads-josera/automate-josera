<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\ai_whatsapp_automation\Ui\ClientFilter;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * GET filter by client for lists that have no other filters.
 */
final class ClientListFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_client_list_filters';
  }

  /**
   * Builds the form.
   *
   * The form posts back to the list it is rendered on, so one form serves
   * leads, audit, bots, accounts and knowledge lists.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $route_name = (string) \Drupal::routeMatch()->getRouteName();
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute($route_name)->toString();
    $form['#attributes']['class'][] = 'aiwa-list-filters';
    $form['#attributes']['class'][] = 'aiwa-filters';
    $form['#attached']['library'][] = 'ai_whatsapp_automation/list_filters';

    $form['client'] = ClientFilter::element($request);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filtrar'),
      '#button_type' => 'primary',
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Limpiar'),
      '#url' => Url::fromRoute($route_name),
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
