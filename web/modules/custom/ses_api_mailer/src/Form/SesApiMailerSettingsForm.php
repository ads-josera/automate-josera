<?php

declare(strict_types=1);

namespace Drupal\ses_api_mailer\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures the generic Amazon SES API mail backend.
 */
final class SesApiMailerSettingsForm extends ConfigFormBase {

  /**
   * Creates the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly MailManagerInterface $mailManager,
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
      $container->get('plugin.manager.mail'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ses_api_mailer_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ses_api_mailer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $system_mail = $this->config('system.mail');
    $current_mailer = (string) ($system_mail->get('interface.default') ?: 'php_mail');
    $configured = $this->hasSecureSettings();

    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Delivery status'),
      '#open' => TRUE,
    ];
    $form['status']['summary'] = [
      '#markup' => '<p><strong>' . $this->t('Current default mailer:') . '</strong> ' . Html::escape($current_mailer) . '</p>'
        . '<p><strong>' . $this->t('SES credentials in settings.php:') . '</strong> '
        . ($configured ? $this->t('Configured') : $this->t('Missing')) . '</p>',
    ];
    $form['status']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Amazon SES API for Drupal email'),
      '#default_value' => $current_mailer === 'ses_api_mailer',
      '#description' => $this->t('When enabled, this module becomes Drupal\'s default mailer. Disabling restores the mailer that was active before it was enabled.'),
    ];

    $form['credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Secure credentials'),
      '#open' => TRUE,
    ];
    $form['credentials']['instructions'] = [
      '#markup' => '<p>' . $this->t('Credentials are intentionally not stored here. Add this block to the environment\'s settings.php, replacing the placeholders with an IAM access key that can send through SES:') . '</p>'
        . '<pre><code>$settings[\'ses_api_mailer\'] = [\n'
        . '  \'region\' => \'us-east-1\',\n'
        . '  \'access_key_id\' => \'ACCESS_KEY_ID\',\n'
        . '  \'secret_access_key\' => \'SECRET_ACCESS_KEY\',\n'
        . '  \'from_address\' => \'noreply@example.com\',\n'
        . '  \'from_name\' => \'Site notifications\',\n'
        . '];</code></pre><p>'
        . $this->t('Keep settings.php outside Git and use different credentials per environment.') . '</p>',
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Send a test email'),
      '#open' => TRUE,
    ];
    $form['test']['test_recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient'),
      '#description' => $this->t('Sends a direct SES API test without changing the active default mailer.'),
    ];
    $form['test']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send test email'),
      '#submit' => ['::submitTest'],
      '#limit_validation_errors' => [['test_recipient']],
      '#disabled' => !$configured,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save delivery settings'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = $this->configFactory->getEditable('ses_api_mailer.settings');
    $system_mail = $this->configFactory->getEditable('system.mail');
    $current = (string) ($system_mail->get('interface.default') ?: 'php_mail');
    $enable = (bool) $form_state->getValue('enabled');

    if ($enable && $current !== 'ses_api_mailer') {
      $settings->set('previous_mailer', $current)->save();
      $system_mail->set('interface.default', 'ses_api_mailer')->save();
      $this->messenger()->addStatus($this->t('Amazon SES API is now the default Drupal mailer.'));
    }
    elseif (!$enable && $current === 'ses_api_mailer') {
      $previous = (string) ($settings->get('previous_mailer') ?: 'php_mail');
      $system_mail->set('interface.default', $previous)->save();
      $this->messenger()->addStatus($this->t('The previous default mailer (@mailer) was restored.', ['@mailer' => $previous]));
    }
  }

  /**
   * Sends a direct SES API test message.
   */
  public function submitTest(array &$form, FormStateInterface $form_state): void {
    $recipient = (string) $form_state->getValue('test_recipient');
    if ($recipient === '') {
      $form_state->setErrorByName('test_recipient', $this->t('Enter a recipient for the test email.'));
      return;
    }

    $mailer = $this->mailManager->createInstance('ses_api_mailer');
    $message = $mailer->format([
      'to' => $recipient,
      'subject' => $this->t('Amazon SES API test from @site', ['@site' => $this->config('system.site')->get('name')]),
      'body' => [$this->t('This email confirms that Drupal can send through the Amazon SES HTTPS API.')],
      'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
      'params' => [],
    ]);

    if ($mailer->mail($message)) {
      $this->messenger()->addStatus($this->t('SES accepted the test email for delivery to @recipient.', ['@recipient' => $recipient]));
    }
    else {
      $this->messenger()->addError($this->t('SES could not accept the test email. Review the recent mail logs and the secure settings.'));
    }
  }

  /**
   * Checks whether secure SES settings are present.
   */
  private function hasSecureSettings(): bool {
    $settings = Settings::get('ses_api_mailer', []);
    if (!is_array($settings) || $settings === []) {
      $settings = Settings::get('ai_whatsapp_automation_ses', []);
    }
    return is_array($settings)
      && !empty($settings['access_key_id'])
      && !empty($settings['secret_access_key'])
      && !empty($settings['from_address']);
  }

}
