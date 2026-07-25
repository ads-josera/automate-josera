<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\StorableConfigBase;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the administration form for AI WhatsApp Automation.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The settings config name.
   */
  private const SETTINGS = 'ai_whatsapp_automation.settings';

  /**
   * Maximum length for provider secrets.
   */
  private const SECRET_MAX_LENGTH = 2048;

  /**
   * Constructs a SettingsForm object.
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
      $container->get('config.typed')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['#attached']['library'][] = 'ai_whatsapp_automation/settings';
    $form['#attributes']['class'][] = 'ai-whatsapp-settings';

    $form['overview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__overview'],
      ],
    ];
    $form['overview']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Provider configuration'),
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__title'],
      ],
    ];
    $form['overview']['status'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__status-grid'],
      ],
    ];
    foreach ($this->buildStatusItems($config) as $key => $item) {
      $form['overview']['status'][$key] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'ai-whatsapp-settings__status-card',
            'ai-whatsapp-settings__status-card--' . $item['state'],
          ],
        ],
      ];
      $form['overview']['status'][$key]['label'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $item['label'],
        '#attributes' => [
          'class' => ['ai-whatsapp-settings__status-label'],
        ],
      ];
      $form['overview']['status'][$key]['value'] = [
        '#type' => 'html_tag',
        '#tag' => 'strong',
        '#value' => $item['value'],
        '#attributes' => [
          'class' => ['ai-whatsapp-settings__status-value'],
        ],
      ];
    }

    $form['openai'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenAI'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__section', 'ai-whatsapp-settings__section--openai'],
      ],
    ];
    $form['openai']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#maxlength' => self::SECRET_MAX_LENGTH,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'spellcheck' => 'false',
      ],
      '#description' => $config->get('openai.api_key') ? $this->t('An API key is already configured. Leave empty to keep the current value.') : $this->t('Enter the OpenAI API key.'),
    ];
    $form['openai']['default_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default model'),
      '#options' => [
        'gpt-5-mini' => $this->t('GPT-5 mini'),
        'gpt-5.1' => $this->t('GPT-5.1'),
        'gpt-5' => $this->t('GPT-5'),
        'gpt-5-nano' => $this->t('GPT-5 nano'),
        'gpt-4.1-mini' => $this->t('GPT-4.1 mini'),
      ],
      '#default_value' => $config->get('openai.default_model') ?: 'gpt-5-mini',
      '#required' => TRUE,
    ];
    $form['openai']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#default_value' => $config->get('openai.timeout') ?: 30,
      '#min' => 1,
      '#max' => 120,
      '#field_suffix' => $this->t('seconds'),
      '#required' => TRUE,
    ];
    $stored_cost_rates = $this->costRatesByModel($config->get('openai.cost_rates'));
    $cost_models = array_values(array_unique(array_merge([
      'gpt-5-mini',
      'gpt-5.1',
      'gpt-5',
      'gpt-5-nano',
      'gpt-4.1-mini',
    ], array_keys($stored_cost_rates))));
    $form['openai']['cost_rates'] = [
      '#type' => 'table',
      '#title' => $this->t('Estimated cost rates (USD per 1 million tokens)'),
      '#header' => [
        'model' => $this->t('Model'),
        'input' => $this->t('Input'),
        'output' => $this->t('Output'),
      ],
      '#description' => $this->t('Enter the current OpenAI prices for each model you use. Leave both values empty to exclude a model from cost estimates.'),
    ];
    foreach ($cost_models as $model) {
      $key = 'model_' . substr(hash('sha256', $model), 0, 12);
      $rate = is_array($stored_cost_rates[$model] ?? NULL) ? $stored_cost_rates[$model] : [];
      $form['openai']['cost_rates'][$key]['model'] = [
        '#type' => 'textfield',
        '#default_value' => $model,
        '#attributes' => [
          'readonly' => 'readonly',
        ],
      ];
      foreach (['input', 'output'] as $type) {
        $form['openai']['cost_rates'][$key][$type] = [
          '#type' => 'number',
          '#default_value' => $rate[$type] ?? '',
          '#min' => 0,
          '#step' => '0.000001',
          '#field_suffix' => 'USD',
        ];
      }
    }
    $form['openai']['custom_cost_rate'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Additional model'),
    ];
    $form['openai']['custom_cost_rate']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model ID'),
      '#maxlength' => 128,
    ];
    foreach (['input', 'output'] as $type) {
      $form['openai']['custom_cost_rate'][$type] = [
        '#type' => 'number',
        '#title' => $type === 'input' ? $this->t('Input USD / 1M') : $this->t('Output USD / 1M'),
        '#min' => 0,
        '#step' => '0.000001',
      ];
    }

    $form['twilio'] = [
      '#type' => 'details',
      '#title' => $this->t('Twilio'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__section', 'ai-whatsapp-settings__section--twilio'],
      ],
    ];
    $form['twilio']['account_sid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Account SID'),
      '#default_value' => $config->get('twilio.account_sid') ?: '',
      '#maxlength' => 128,
    ];
    $form['twilio']['auth_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Auth Token'),
      '#maxlength' => self::SECRET_MAX_LENGTH,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'spellcheck' => 'false',
      ],
      '#description' => $config->get('twilio.auth_token') ? $this->t('An auth token is already configured. Leave empty to keep the current value.') : $this->t('Enter the Twilio auth token.'),
    ];
    $form['twilio']['whatsapp_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WhatsApp Number'),
      '#default_value' => $config->get('twilio.whatsapp_number') ?: '',
      '#maxlength' => 64,
    ];
    $form['twilio']['content_template_sid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Lead notification Content Template SID'),
      '#default_value' => $config->get('twilio.content_template_sid') ?: '',
      '#maxlength' => 64,
      '#description' => $this->t('Optional approved Twilio WhatsApp template (HX...). It is used for lead notifications outside the 24-hour messaging window. Use variables {{1}} lead ID, {{2}} contact, {{3}} phone, {{4}} email, and {{5}} bot summary.'),
    ];
    $form['twilio']['messaging_service_sid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Messaging Service SID'),
      '#default_value' => $config->get('twilio.messaging_service_sid') ?: '',
      '#maxlength' => 64,
      '#description' => $this->t('Optional Twilio Messaging Service SID for sending through a sender pool.'),
    ];

    $form['whatsapp_cloud'] = [
      '#type' => 'details',
      '#title' => $this->t('WhatsApp Cloud API'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__section', 'ai-whatsapp-settings__section--cloud'],
      ],
    ];
    $form['whatsapp_cloud']['access_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Access Token'),
      '#maxlength' => self::SECRET_MAX_LENGTH,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'spellcheck' => 'false',
      ],
      '#description' => $config->get('whatsapp_cloud.access_token') ? $this->t('An access token is already configured. Leave empty to keep the current value.') : $this->t('Enter the WhatsApp Cloud API access token.'),
    ];
    $form['whatsapp_cloud']['phone_number_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phone Number ID'),
      '#default_value' => $config->get('whatsapp_cloud.phone_number_id') ?: '',
      '#maxlength' => 128,
    ];
    $form['whatsapp_cloud']['business_account_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Business Account ID'),
      '#default_value' => $config->get('whatsapp_cloud.business_account_id') ?: '',
      '#maxlength' => 128,
    ];
    $form['whatsapp_cloud']['verify_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Verify Token'),
      '#maxlength' => self::SECRET_MAX_LENGTH,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'spellcheck' => 'false',
      ],
      '#description' => $config->get('whatsapp_cloud.verify_token') ? $this->t('A verify token is already configured. Leave empty to keep the current value.') : $this->t('Enter the webhook verify token.'),
    ];

    $form['evolution'] = [
      '#type' => 'details',
      '#title' => $this->t('Evolution API'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__section', 'ai-whatsapp-settings__section--evolution'],
      ],
    ];
    $form['evolution']['server_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Server URL'),
      '#default_value' => $config->get('evolution.server_url') ?: '',
      '#maxlength' => 255,
    ];
    $form['evolution']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API Key'),
      '#maxlength' => self::SECRET_MAX_LENGTH,
      '#attributes' => [
        'autocomplete' => 'new-password',
        'spellcheck' => 'false',
      ],
      '#description' => $config->get('evolution.api_key') ? $this->t('An API key is already configured. Leave empty to keep the current value.') : $this->t('Enter the Evolution API key.'),
    ];
    $form['evolution']['instance_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Instance name'),
      '#default_value' => $config->get('evolution.instance_name') ?: '',
      '#maxlength' => 128,
    ];

    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Options'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => [
        'class' => ['ai-whatsapp-settings__section', 'ai-whatsapp-settings__section--options'],
      ],
    ];
    $form['options']['enable_ai'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI'),
      '#default_value' => (bool) $config->get('options.enable_ai'),
    ];
    $form['options']['enable_logs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable logs'),
      '#default_value' => (bool) $config->get('options.enable_logs'),
    ];
    $form['options']['enable_storage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable storage'),
      '#default_value' => (bool) $config->get('options.enable_storage'),
    ];
    $form['options']['enable_metrics'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable metrics'),
      '#default_value' => (bool) $config->get('options.enable_metrics'),
    ];
    $form['options']['auto_close_ai_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-close inactive AI conversations'),
      '#default_value' => $config->get('options.auto_close_ai_hours') ?? 24,
      '#min' => 0,
      '#max' => 8760,
      '#field_suffix' => $this->t('hours'),
      '#description' => $this->t('Conversations in AI active status are closed by cron after this many inactive hours. Use 0 to disable automatic closing.'),
      '#required' => TRUE,
    ];
    $form['options']['enable_lead_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable lead handoff notifications'),
      '#default_value' => (bool) $config->get('options.enable_lead_notifications'),
      '#description' => $this->t('When a conversation looks ready for human follow-up, create a lead, assign it to human handling, and notify administrators.'),
    ];
    $form['options']['lead_notification_numbers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Lead notification WhatsApp numbers'),
      '#default_value' => $config->get('options.lead_notification_numbers') ?: '',
      '#rows' => 3,
      '#description' => $this->t('One WhatsApp number per line, including country code. Example: +5215512345678.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds status items for the configuration overview.
   *
   * @return array<string, array<string, string|\Stringable>>
   *   Status card render data.
   */
  private function buildStatusItems(StorableConfigBase $config): array {
    return [
      'openai' => $this->statusItem(
        $this->t('OpenAI'),
        (string) $config->get('openai.api_key') !== '',
        $this->t('Ready'),
        $this->t('Missing key')
      ),
      'twilio' => $this->statusItem(
        $this->t('Twilio'),
        (string) $config->get('twilio.account_sid') !== ''
        && (string) $config->get('twilio.auth_token') !== ''
        && (string) $config->get('twilio.whatsapp_number') !== '',
        $this->t('Ready'),
        $this->t('Incomplete')
      ),
      'cloud_api' => $this->statusItem(
        $this->t('Cloud API'),
        (string) $config->get('whatsapp_cloud.access_token') !== ''
        && (string) $config->get('whatsapp_cloud.phone_number_id') !== ''
        && (string) $config->get('whatsapp_cloud.verify_token') !== '',
        $this->t('Ready'),
        $this->t('Incomplete')
      ),
      'evolution' => $this->statusItem(
        $this->t('Evolution'),
        (string) $config->get('evolution.server_url') !== ''
        && (string) $config->get('evolution.api_key') !== '',
        $this->t('Ready'),
        $this->t('Incomplete')
      ),
      'automation' => $this->statusItem(
        $this->t('Automation'),
        (bool) $config->get('options.enable_ai'),
        $this->t('Enabled'),
        $this->t('Disabled')
      ),
    ];
  }

  /**
   * Creates a normalized status item.
   *
   * @return array<string, string|\Stringable>
   *   Status card data.
   */
  private function statusItem(
    string|\Stringable $label,
    bool $ready,
    string|\Stringable $ready_label,
    string|\Stringable $missing_label,
  ): array {
    return [
      'label' => $label,
      'value' => $ready ? $ready_label : $missing_label,
      'state' => $ready ? 'ready' : 'missing',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $timeout = (int) $form_state->getValue(['openai', 'timeout']);
    if ($timeout < 1 || $timeout > 120) {
      $form_state->setErrorByName('openai][timeout', $this->t('Timeout must be between 1 and 120 seconds.'));
    }

    foreach ($form_state->getValue(['openai', 'cost_rates']) ?: [] as $row) {
      $this->validateCostRate($form_state, $row, 'openai][cost_rates');
    }
    $this->validateCostRate($form_state, $form_state->getValue(['openai', 'custom_cost_rate']) ?: [], 'openai][custom_cost_rate');

    $auto_close_ai_hours = (int) $form_state->getValue(['options', 'auto_close_ai_hours']);
    if ($auto_close_ai_hours < 0 || $auto_close_ai_hours > 8760) {
      $form_state->setErrorByName('options][auto_close_ai_hours', $this->t('Auto-close hours must be between 0 and 8760.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable(self::SETTINGS);

    $openai = $form_state->getValue('openai');
    $twilio = $form_state->getValue('twilio');
    $whatsapp_cloud = $form_state->getValue('whatsapp_cloud');
    $evolution = $form_state->getValue('evolution');
    $options = $form_state->getValue('options');
    $cost_rates = $this->submittedCostRates($openai);

    $config
      ->set('openai.api_key', $this->resolveSecretValue($openai['api_key'], $config->get('openai.api_key')))
      ->set('openai.default_model', $openai['default_model'])
      ->set('openai.timeout', (int) $openai['timeout'])
      ->set('openai.cost_rates', $cost_rates)
      ->set('twilio.account_sid', $twilio['account_sid'])
      ->set('twilio.auth_token', $this->resolveSecretValue($twilio['auth_token'], $config->get('twilio.auth_token')))
      ->set('twilio.whatsapp_number', $twilio['whatsapp_number'])
      ->set('twilio.content_template_sid', $twilio['content_template_sid'])
      ->set('twilio.messaging_service_sid', $twilio['messaging_service_sid'])
      ->set('whatsapp_cloud.access_token', $this->resolveSecretValue($whatsapp_cloud['access_token'], $config->get('whatsapp_cloud.access_token')))
      ->set('whatsapp_cloud.phone_number_id', $whatsapp_cloud['phone_number_id'])
      ->set('whatsapp_cloud.business_account_id', $whatsapp_cloud['business_account_id'])
      ->set('whatsapp_cloud.verify_token', $this->resolveSecretValue($whatsapp_cloud['verify_token'], $config->get('whatsapp_cloud.verify_token')))
      ->set('evolution.server_url', $evolution['server_url'])
      ->set('evolution.api_key', $this->resolveSecretValue($evolution['api_key'], $config->get('evolution.api_key')))
      ->set('evolution.instance_name', $evolution['instance_name'])
      ->set('options.enable_ai', (bool) $options['enable_ai'])
      ->set('options.enable_logs', (bool) $options['enable_logs'])
      ->set('options.enable_storage', (bool) $options['enable_storage'])
      ->set('options.enable_metrics', (bool) $options['enable_metrics'])
      ->set('options.auto_close_ai_hours', (int) $options['auto_close_ai_hours'])
      ->set('options.enable_lead_notifications', (bool) $options['enable_lead_notifications'])
      ->set('options.lead_notification_numbers', $options['lead_notification_numbers'])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Keeps the current secret when the password field is left empty.
   */
  private function resolveSecretValue(?string $submitted_value, mixed $current_value): string {
    $submitted_value = trim((string) $submitted_value);

    if ($submitted_value === '') {
      return (string) $current_value;
    }

    return $submitted_value;
  }

  /**
   * Validates a submitted model cost-rate row.
   */
  private function validateCostRate(FormStateInterface $form_state, array $row, string $element_name): void {
    $model = trim((string) ($row['model'] ?? ''));
    $input = $row['input'] ?? '';
    $output = $row['output'] ?? '';
    // Predefined model rows always carry a model ID. Empty prices mean that
    // the model is intentionally excluded from cost estimates.
    if ($input === '' && $output === '') {
      return;
    }
    if ($model === '' || $input === '' || $output === '') {
      $form_state->setErrorByName($element_name, $this->t('Each cost rate requires a model ID, input rate, and output rate.'));
      return;
    }
    if ((float) $input < 0 || (float) $output < 0) {
      $form_state->setErrorByName($element_name, $this->t('Cost rates cannot be negative.'));
    }
  }

  /**
   * Normalizes submitted rates for configuration storage.
   *
   * @return array<int, array{model: string, input: float, output: float}>
   *   Rates as configuration-safe rows.
   */
  private function submittedCostRates(array $openai): array {
    $rates = [];
    $rows = is_array($openai['cost_rates'] ?? NULL) ? $openai['cost_rates'] : [];
    $rows[] = is_array($openai['custom_cost_rate'] ?? NULL) ? $openai['custom_cost_rate'] : [];
    foreach ($rows as $row) {
      $model = trim((string) ($row['model'] ?? ''));
      if ($model === '' || ($row['input'] ?? '') === '' || ($row['output'] ?? '') === '') {
        continue;
      }
      $rates[] = [
        'model' => $model,
        'input' => (float) $row['input'],
        'output' => (float) $row['output'],
      ];
    }

    return $rates;
  }

  /**
   * Indexes saved cost-rate rows by model for form display.
   *
   * Supports the original keyed storage format for sites that stored model
   * names without dots before cost rates became a configuration-safe list.
   *
   * @return array<string, array{input: float|int|string, output: float|int|string}>
   *   Rates keyed by model ID.
   */
  private function costRatesByModel(mixed $stored_rates): array {
    if (!is_array($stored_rates)) {
      return [];
    }

    $rates = [];
    foreach ($stored_rates as $key => $rate) {
      if (!is_array($rate)) {
        continue;
      }
      $model = trim((string) ($rate['model'] ?? (is_string($key) ? $key : '')));
      if ($model === '') {
        continue;
      }
      $rates[$model] = [
        'input' => $rate['input'] ?? '',
        'output' => $rate['output'] ?? '',
      ];
    }

    return $rates;
  }

}
