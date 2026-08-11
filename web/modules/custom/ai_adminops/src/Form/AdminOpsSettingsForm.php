<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the initial AI AdminOps module settings.
 */
final class AdminOpsSettingsForm extends ConfigFormBase {

  /**
   * Creates an AdminOpsSettingsForm instance.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_adminops_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ai_adminops.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_adminops.settings');
    $form['#attached']['library'][] = 'ai_adminops/admin';
    $form['#attributes']['class'][] = 'ai-adminops-settings-form';

    $form['monitoring'] = [
      '#type' => 'details',
      '#title' => $this->t('Monitoring'),
      '#open' => TRUE,
      '#description' => $this->t('Schedule read-only monitoring work for active servers. Connector execution remains disabled until a future integration phase.'),
    ];
    $form['monitoring']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable monitoring'),
      '#default_value' => (bool) $config->get('monitoring.enabled'),
      '#description' => $this->t('When enabled, Drupal cron queues monitoring work for active servers.'),
    ];
    $form['monitoring']['interval_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Monitoring interval in minutes'),
      '#default_value' => (int) $config->get('monitoring.interval_minutes'),
      '#min' => 1,
      '#max' => 1440,
      '#description' => $this->t('Minimum time between monitoring jobs for each active server.'),
    ];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications'),
      '#open' => TRUE,
      '#description' => $this->t('Future AdminOps notifications will reuse the existing WhatsApp and Drupal mail infrastructure.'),
    ];
    $form['notifications']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable notifications'),
      '#default_value' => (bool) $config->get('notifications.enabled'),
      '#disabled' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('ai_adminops.settings')
      ->set('monitoring.enabled', (bool) $form_state->getValue(['monitoring', 'enabled']))
      ->set('monitoring.interval_minutes', (int) $form_state->getValue(['monitoring', 'interval_minutes']))
      ->set('notifications.enabled', (bool) $form_state->getValue(['notifications', 'enabled']))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $interval = (int) $form_state->getValue(['monitoring', 'interval_minutes']);
    if ($interval < 1 || $interval > 1440) {
      $form_state->setErrorByName('monitoring][interval_minutes', $this->t('The monitoring interval must be between 1 and 1440 minutes.'));
    }
    parent::validateForm($form, $form_state);
  }

}
