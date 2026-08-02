<?php

declare(strict_types=1);

namespace Drupal\ses_api_mailer\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
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
    private readonly StateInterface $state,
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
      $container->get('state'),
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
    $secure_settings = $this->secureSettings();
    $configured = $this->hasSecureSettings($secure_settings);
    $uses_reusable_mailer = $current_mailer === 'ses_api_mailer';
    $uses_legacy_mailer = $current_mailer === 'amazon_ses_api';

    $form['#attached']['library'][] = 'ses_api_mailer/settings';
    $form['#attributes']['class'][] = 'ses-api-mailer-form';

    $form['status'] = [
      '#type' => 'details',
      '#title' => $this->t('Estado del envío'),
      '#open' => TRUE,
    ];
    if ($uses_reusable_mailer) {
      $status_title = $this->t('Amazon SES API está activo');
      $status_message = $this->t('Este módulo controla los correos salientes de Drupal.');
      $status_class = '';
    }
    elseif ($uses_legacy_mailer) {
      $status_title = $this->t('Amazon SES API está activo mediante la integración anterior');
      $status_message = $this->t('Los correos ya salen por SES. Completa la migración solo si deseas administrar la activación desde este módulo.');
      $status_class = ' ses-api-mailer-status__headline--legacy';
    }
    else {
      $status_title = $this->t('Amazon SES API está preparado, pero no activo');
      $status_message = $this->t('Puedes enviar una prueba y activar este módulo como mailer predeterminado.');
      $status_class = ' ses-api-mailer-status__headline--inactive';
    }

    $credentials_badge = $configured
      ? '<span class="ses-api-mailer-status__badge ses-api-mailer-status__badge--ready">' . $this->t('Configuradas') . '</span>'
      : '<span class="ses-api-mailer-status__badge ses-api-mailer-status__badge--warning">' . $this->t('Pendientes') . '</span>';
    $form['status']['summary'] = [
      '#markup' => '<div class="ses-api-mailer-status">'
        . '<div class="ses-api-mailer-status__headline' . $status_class . '">'
        . '<span class="ses-api-mailer-status__icon">✓</span><div><strong class="ses-api-mailer-status__title">' . $status_title . '</strong>'
        . '<span class="ses-api-mailer-status__message">' . $status_message . '</span></div></div>'
        . '<div class="ses-api-mailer-status__facts">'
        . '<div class="ses-api-mailer-status__fact"><span class="ses-api-mailer-status__label">' . $this->t('Mailer actual') . '</span><span class="ses-api-mailer-status__value">' . Html::escape($current_mailer) . '</span></div>'
        . '<div class="ses-api-mailer-status__fact"><span class="ses-api-mailer-status__label">' . $this->t('Credenciales en settings.php') . '</span><span class="ses-api-mailer-status__value">' . $credentials_badge . '</span></div>'
        . '</div></div>',
    ];
    $form['status']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Usar este módulo para los correos de Drupal'),
      '#default_value' => $uses_reusable_mailer,
      '#description' => $this->t('Al activarlo, este módulo se convierte en el mailer predeterminado. Al desactivarlo se restaura el mailer anterior.'),
      '#disabled' => $uses_legacy_mailer,
    ];
    if ($uses_legacy_mailer) {
      $form['status']['migration'] = [
        '#markup' => '<div class="ses-api-mailer-migration"><strong>' . $this->t('Control pendiente de migración.') . '</strong> '
          . $this->t('La prueba confirma que SES funciona. Para usar el interruptor de esta pantalla, agrega la configuración `ses_api_mailer` a settings.php y retira el override fijo `amazon_ses_api`; la guía del proyecto incluye los pasos.') . '</div>',
      ];
    }

    $daily_limit = max(0, (int) ($this->config('ses_api_mailer.settings')->get('daily_send_limit') ?? 50));
    $sent_today = $this->dailySentCount();
    $remaining = $daily_limit === 0 ? $this->t('Sin límite') : max(0, $daily_limit - $sent_today);
    $form['limit'] = [
      '#type' => 'details',
      '#title' => $this->t('Límite diario de envío'),
      '#open' => TRUE,
    ];
    $form['limit']['summary'] = [
      '#markup' => '<div class="ses-api-mailer-limit-summary"><div><span>' . $this->t('Enviados hoy') . '</span><strong>' . $sent_today . '</strong></div><div><span>' . $this->t('Disponibles hoy') . '</span><strong>' . $remaining . '</strong></div></div>',
    ];
    $form['limit']['daily_send_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Máximo de destinatarios por día'),
      '#default_value' => $daily_limit,
      '#min' => 0,
      '#step' => 1,
      '#description' => $this->t('El valor inicial es 50. El límite se aplica a todos los correos de Drupal enviados por SES. Usa 0 para desactivarlo. Un correo a varios destinatarios cuenta una vez por cada destinatario.'),
    ];

    $form['credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Credenciales seguras'),
      '#open' => TRUE,
    ];
    $form['credentials']['instructions'] = [
      '#markup' => '<p>' . $this->t('Las credenciales no se guardan en la base de datos. Deben permanecer en settings.php y fuera de Git.') . '</p>'
        . '<pre class="ses-api-mailer-code"><code>$settings[\'ses_api_mailer\'] = [' . "\n"
        . '  \'region\' => \'us-east-1\',' . "\n"
        . '  \'access_key_id\' => \'ACCESS_KEY_ID\',' . "\n"
        . '  \'secret_access_key\' => \'SECRET_ACCESS_KEY\',' . "\n"
        . '  \'from_address\' => \'noreply@example.com\',' . "\n"
        . '  \'from_name\' => \'Notificaciones del sitio\',' . "\n"
        . '];</code></pre>',
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Enviar correo de prueba'),
      '#open' => TRUE,
    ];
    $form['test']['help'] = [
      '#markup' => '<p class="ses-api-mailer-test-help">' . $this->t('La prueba usa SES directamente y no modifica el mailer activo.') . '</p>',
    ];
    $form['test']['test_recipient'] = [
      '#type' => 'email',
      '#title' => $this->t('Destinatario'),
    ];
    $form['test']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enviar prueba por SES'),
      '#submit' => ['::submitTest'],
      '#limit_validation_errors' => [['test_recipient']],
      '#disabled' => !$configured,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Guardar estado de entrega'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = $this->configFactory->getEditable('ses_api_mailer.settings');
    $settings->set('daily_send_limit', max(0, (int) $form_state->getValue('daily_send_limit')));
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
    $settings->save();
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
  private function hasSecureSettings(array $settings): bool {
    return is_array($settings)
      && !empty($settings['access_key_id'])
      && !empty($settings['secret_access_key'])
      && !empty($settings['from_address']);
  }

  /**
   * Returns generic settings or the legacy project settings during migration.
   */
  private function secureSettings(): array {
    $settings = Settings::get('ses_api_mailer', []);
    if (is_array($settings) && $settings !== []) {
      return $settings;
    }

    $legacy = Settings::get('ai_whatsapp_automation_ses', []);
    return is_array($legacy) ? $legacy : [];
  }

  /**
   * Gets today's number of SES recipients accepted by this module.
   */
  private function dailySentCount(): int {
    $timezone = (string) ($this->config('system.date')->get('timezone.default') ?: date_default_timezone_get());
    $day = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
    return (int) $this->state->get('ses_api_mailer.daily_send_count.' . $day, 0);
  }

}
