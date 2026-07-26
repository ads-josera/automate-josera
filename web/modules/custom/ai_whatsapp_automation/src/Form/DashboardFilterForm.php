<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides period filters for the automation dashboard.
 */
final class DashboardFilterForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_dashboard_filters';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute('ai_whatsapp_automation.dashboard')->toString();
    $form['#attributes']['class'][] = 'ai-whatsapp-dashboard__filters';

    $form['period'] = [
      '#type' => 'select',
      '#title' => $this->t('View'),
      '#options' => [
        'day' => $this->t('Day'),
        'month' => $this->t('Month'),
        'year' => $this->t('Year'),
        'all' => $this->t('All time'),
      ],
      '#default_value' => (string) $request->query->get('period', 'month'),
    ];
    $form['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Reference date'),
      '#default_value' => (string) $request->query->get('date', date('Y-m-d')),
      '#description' => $this->t('The selected day, its month, or its year is used according to the view.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Current month'),
      '#url' => Url::fromRoute('ai_whatsapp_automation.dashboard'),
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
