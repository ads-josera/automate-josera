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
      '#description' => $this->t('Monitoring execution will be introduced after servers and monitoring tools are available.'),
    ];
    $form['monitoring']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable monitoring'),
      '#default_value' => (bool) $config->get('monitoring.enabled'),
      '#disabled' => TRUE,
    ];
    $form['monitoring']['interval_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Monitoring interval in minutes'),
      '#default_value' => (int) $config->get('monitoring.interval_minutes'),
      '#min' => 1,
      '#max' => 1440,
      '#disabled' => TRUE,
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

}

