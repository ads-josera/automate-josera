<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
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
      '#description' => $this->t('Schedule read-only monitoring work for active servers. A server is contacted only when its SSH profile is declared in settings.php; secrets are never stored in this form.'),
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

    $alerts = $config->get('alerts') ?: [];
    $form['alerts'] = [
      '#type' => 'details',
      '#title' => $this->t('Monitoring alert rules'),
      '#open' => TRUE,
      '#description' => $this->t('Creates one operational event per ongoing condition and resolves it automatically when the metric returns to normal. Use 0 to disable an individual metric threshold.'),
    ];
    $form['alerts']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable metric alerts'),
      '#default_value' => (bool) ($alerts['enabled'] ?? TRUE),
    ];
    $form['alerts']['unreachable_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Alert when a server does not respond'),
      '#default_value' => (bool) ($alerts['unreachable_enabled'] ?? TRUE),
      '#description' => $this->t('Creates a critical alert if a configured SSH monitoring check cannot be completed.'),
    ];
    $form['alerts']['load_1m_warning'] = [
      '#type' => 'number',
      '#title' => $this->t('Load average warning threshold (1 minute)'),
      '#default_value' => $alerts['load_1m_warning'] ?? 4,
      '#min' => 0,
      '#max' => 1000,
      '#step' => 0.1,
      '#description' => $this->t('An absolute load-average threshold. Adjust it for the server capacity; 0 disables this alert.'),
    ];
    $form['alerts']['cpu_percent_warning'] = $this->thresholdElement($this->t('CPU usage warning threshold (%)'), $alerts['cpu_percent_warning'] ?? 90);
    $form['alerts']['memory_percent_warning'] = $this->thresholdElement($this->t('Memory usage warning threshold (%)'), $alerts['memory_percent_warning'] ?? 90);
    $form['alerts']['disk_percent_warning'] = $this->thresholdElement($this->t('Disk usage warning threshold (%)'), $alerts['disk_percent_warning'] ?? 85);
    $form['alerts']['exim_queue_warning'] = [
      '#type' => 'number',
      '#title' => $this->t('Exim queue warning threshold'),
      '#default_value' => $alerts['exim_queue_warning'] ?? 100,
      '#min' => 0,
      '#max' => 1000000,
      '#step' => 1,
      '#description' => $this->t('Number of queued messages that generates a warning. Use 0 to disable this alert.'),
    ];

    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications'),
      '#open' => TRUE,
      '#description' => $this->t('Operational alerts use Drupal email and the existing WhatsApp delivery service. Notifications never expose event evidence or credentials.'),
    ];
    $form['notifications']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable notifications'),
      '#default_value' => (bool) $config->get('notifications.enabled'),
      '#description' => $this->t('Only open events at or above the selected severity are eligible.'),
    ];
    $form['notifications']['minimum_severity'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum severity'),
      '#options' => [
        'info' => $this->t('Info and above'),
        'warning' => $this->t('Warning and above'),
        'critical' => $this->t('Critical only'),
      ],
      '#default_value' => $config->get('notifications.minimum_severity') ?: 'warning',
    ];
    $form['notifications']['cooldown_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Cooldown in minutes'),
      '#default_value' => (int) ($config->get('notifications.cooldown_minutes') ?? 60),
      '#min' => 0,
      '#max' => 10080,
      '#description' => $this->t('Minimum interval before an open event can send another alert. Use 0 to disable this protection.'),
    ];

    $email = $config->get('notifications.email') ?: [];
    $form['notifications']['email'] = [
      '#type' => 'details',
      '#title' => $this->t('Email channel'),
      '#open' => TRUE,
    ];
    $form['notifications']['email']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send alerts by email'),
      '#default_value' => (bool) ($email['enabled'] ?? TRUE),
    ];
    $form['notifications']['email']['recipients'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email recipients'),
      '#default_value' => implode("\n", $email['recipients'] ?? []),
      '#description' => $this->t('One valid email address per line. Drupal uses the site default mailer.'),
    ];

    $whatsapp = $config->get('notifications.whatsapp') ?: [];
    $account_options = ['' => $this->t('- Select an active WhatsApp account -')];
    foreach ($this->entityTypeManager->getStorage('ai_whatsapp_account')->loadMultiple() as $account) {
      if ((string) $account->get('status')->value === 'active') {
        $account_options[(string) $account->id()] = $account->label();
      }
    }
    $form['notifications']['whatsapp'] = [
      '#type' => 'details',
      '#title' => $this->t('WhatsApp channel'),
      '#open' => FALSE,
      '#description' => $this->t('Uses an existing active WhatsApp account. A Twilio template is recommended for reliable business-initiated alerts.'),
    ];
    $form['notifications']['whatsapp']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send alerts by WhatsApp'),
      '#default_value' => (bool) ($whatsapp['enabled'] ?? FALSE),
    ];
    $form['notifications']['whatsapp']['account_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Sending WhatsApp account'),
      '#options' => $account_options,
      '#default_value' => (string) ($whatsapp['account_id'] ?? ''),
    ];
    $form['notifications']['whatsapp']['recipients'] = [
      '#type' => 'textarea',
      '#title' => $this->t('WhatsApp recipients'),
      '#default_value' => implode("\n", $whatsapp['recipients'] ?? []),
      '#description' => $this->t('One phone number per line in international format, for example +5215512345678.'),
    ];
    $form['notifications']['whatsapp']['template_sid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Twilio Content Template SID'),
      '#default_value' => (string) ($whatsapp['template_sid'] ?? ''),
      '#description' => $this->t('Optional for non-Twilio providers. For Twilio, configure a template with variable {{1}} to support alerts outside the 24-hour messaging window.'),
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
      ->set('alerts', [
        'enabled' => (bool) $form_state->getValue(['alerts', 'enabled']),
        'unreachable_enabled' => (bool) $form_state->getValue(['alerts', 'unreachable_enabled']),
        'load_1m_warning' => (float) $form_state->getValue(['alerts', 'load_1m_warning']),
        'cpu_percent_warning' => (int) $form_state->getValue(['alerts', 'cpu_percent_warning']),
        'memory_percent_warning' => (int) $form_state->getValue(['alerts', 'memory_percent_warning']),
        'disk_percent_warning' => (int) $form_state->getValue(['alerts', 'disk_percent_warning']),
        'exim_queue_warning' => (int) $form_state->getValue(['alerts', 'exim_queue_warning']),
      ])
      ->set('notifications.enabled', (bool) $form_state->getValue(['notifications', 'enabled']))
      ->set('notifications.minimum_severity', (string) $form_state->getValue(['notifications', 'minimum_severity']))
      ->set('notifications.cooldown_minutes', (int) $form_state->getValue(['notifications', 'cooldown_minutes']))
      ->set('notifications.email', [
        'enabled' => (bool) $form_state->getValue(['notifications', 'email', 'enabled']),
        'recipients' => $this->lines($form_state->getValue(['notifications', 'email', 'recipients'])),
      ])
      ->set('notifications.whatsapp', [
        'enabled' => (bool) $form_state->getValue(['notifications', 'whatsapp', 'enabled']),
        'account_id' => (string) $form_state->getValue(['notifications', 'whatsapp', 'account_id']),
        'recipients' => $this->lines($form_state->getValue(['notifications', 'whatsapp', 'recipients'])),
        'template_sid' => trim((string) $form_state->getValue(['notifications', 'whatsapp', 'template_sid'])),
      ])
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
    $cooldown = (int) $form_state->getValue(['notifications', 'cooldown_minutes']);
    if ($cooldown < 0 || $cooldown > 10080) {
      $form_state->setErrorByName('notifications][cooldown_minutes', $this->t('The notification cooldown must be between 0 and 10080 minutes.'));
    }
    $load = (float) $form_state->getValue(['alerts', 'load_1m_warning']);
    if ($load < 0 || $load > 1000) {
      $form_state->setErrorByName('alerts][load_1m_warning', $this->t('The load threshold must be between 0 and 1000.'));
    }
    foreach (['cpu_percent_warning', 'memory_percent_warning', 'disk_percent_warning'] as $name) {
      $value = (int) $form_state->getValue(['alerts', $name]);
      if ($value < 0 || $value > 100) {
        $form_state->setErrorByName('alerts][' . $name, $this->t('Percentage thresholds must be between 0 and 100.'));
      }
    }
    $queue = (int) $form_state->getValue(['alerts', 'exim_queue_warning']);
    if ($queue < 0 || $queue > 1000000) {
      $form_state->setErrorByName('alerts][exim_queue_warning', $this->t('The Exim queue threshold must be between 0 and 1000000.'));
    }
    foreach ($this->lines($form_state->getValue(['notifications', 'email', 'recipients'])) as $recipient) {
      if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName('notifications][email][recipients', $this->t('"@recipient" is not a valid email address.', ['@recipient' => $recipient]));
      }
    }
    foreach ($this->lines($form_state->getValue(['notifications', 'whatsapp', 'recipients'])) as $recipient) {
      $digits = preg_replace('/\D+/', '', $recipient) ?? '';
      if ($digits !== '' && (strlen($digits) < 8 || strlen($digits) > 15)) {
        $form_state->setErrorByName('notifications][whatsapp][recipients', $this->t('"@recipient" is not a valid international phone number.', ['@recipient' => $recipient]));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * Converts a newline-delimited field into unique non-empty values.
   *
   * @return string[]
   *   Normalized lines.
   */
  private function lines(mixed $value): array {
    $values = preg_split('/\R+/', (string) $value) ?: [];
    $values = array_map('trim', $values);
    $values = array_filter($values, static fn(string $value): bool => $value !== '');
    return array_values(array_unique($values));
  }

  /**
   * Builds a percentage alert threshold element.
   */
  private function thresholdElement(string $title, int|float $default_value): array {
    return [
      '#type' => 'number',
      '#title' => $title,
      '#default_value' => $default_value,
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
      '#description' => $this->t('Use 0 to disable this alert.'),
    ];
  }

}
