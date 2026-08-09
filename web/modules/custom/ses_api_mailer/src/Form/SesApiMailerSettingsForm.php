<?php

declare(strict_types=1);

namespace Drupal\ses_api_mailer\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly Connection $database,
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
      $container->get('module_handler'),
      $container->get('database'),
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
    $current_mailer = $this->currentMailer();
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
    $form['status']['health'] = $this->deliveryHealth();
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
    $form['limit']['alert_recipients'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Destinatarios de alertas preventivas'),
      '#default_value' => (string) $this->config('ses_api_mailer.settings')->get('alert_recipients'),
      '#rows' => 2,
      '#description' => $this->t('Recibirán un aviso una vez al alcanzar el 80% y el 100% del límite diario. Escribe un correo por línea o sepáralos por coma. Las alertas operativas no consumen el límite de correos de Drupal para asegurar que el aviso de 100% pueda enviarse.'),
    ];

    $month_options = $this->monthOptions();
    $selected_month = (string) ($form_state->getValue('statistics_month') ?: array_key_first($month_options));
    $form['statistics'] = [
      '#type' => 'details',
      '#title' => $this->t('Historial mensual de envíos'),
      '#open' => TRUE,
    ];
    $form['statistics']['statistics_month'] = [
      '#type' => 'select',
      '#title' => $this->t('Mes'),
      '#options' => $month_options,
      '#default_value' => $selected_month,
      '#ajax' => [
        'callback' => '::refreshMonthlyStatistics',
        'wrapper' => 'ses-api-mailer-monthly-report',
      ],
    ];
    $form['statistics']['report'] = $this->monthlyReport($selected_month);

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
   * Rebuilds the monthly report after selecting a different month.
   */
  public function refreshMonthlyStatistics(array &$form, FormStateInterface $form_state): array {
    return $form['statistics']['report'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $settings = $this->configFactory->getEditable('ses_api_mailer.settings');
    $settings->set('daily_send_limit', max(0, (int) $form_state->getValue('daily_send_limit')));
    $settings->set('alert_recipients', trim((string) $form_state->getValue('alert_recipients')));
    $system_mail = $this->configFactory->getEditable('system.mail');
    $current = $this->currentMailer();
    $system_default = (string) ($system_mail->get('interface.default') ?: 'php_mail');
    $enable = (bool) $form_state->getValue('enabled');

    if ($enable && $current !== 'ses_api_mailer') {
      $settings->set('previous_mailer', $system_default);
      $system_mail->set('interface.default', 'ses_api_mailer')->save();
      $this->configureMailsystem($settings, TRUE);
      $this->messenger()->addStatus($this->t('Amazon SES API is now the default Drupal mailer.'));
    }
    elseif (!$enable && $current === 'ses_api_mailer') {
      $previous = (string) ($settings->get('previous_mailer') ?: 'php_mail');
      $system_mail->set('interface.default', $previous)->save();
      $this->configureMailsystem($settings, FALSE);
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
   * Gets the mail backend that Drupal will use after Mailsystem overrides.
   */
  private function currentMailer(): string {
    if ($this->moduleHandler->moduleExists('mailsystem')) {
      $mailer = (string) $this->config('mailsystem.settings')->get('defaults.sender');
      if ($mailer !== '') {
        return $mailer;
      }
    }

    return (string) ($this->config('system.mail')->get('interface.default') ?: 'php_mail');
  }

  /**
   * Updates Mailsystem's default sender and formatter when it is enabled.
   */
  private function configureMailsystem(Config $settings, bool $enable): void {
    if (!$this->moduleHandler->moduleExists('mailsystem')) {
      return;
    }

    $mailsystem = $this->configFactory->getEditable('mailsystem.settings');
    if ($enable) {
      $settings->set('previous_mailsystem_sender', (string) $mailsystem->get('defaults.sender'));
      $settings->set('previous_mailsystem_formatter', (string) $mailsystem->get('defaults.formatter'));
      $mailsystem->set('defaults.sender', 'ses_api_mailer');
      $mailsystem->set('defaults.formatter', 'ses_api_mailer');
    }
    else {
      $previous_sender = (string) $settings->get('previous_mailsystem_sender');
      $previous_formatter = (string) $settings->get('previous_mailsystem_formatter');
      if ($previous_sender !== '') {
        $mailsystem->set('defaults.sender', $previous_sender);
      }
      if ($previous_formatter !== '') {
        $mailsystem->set('defaults.formatter', $previous_formatter);
      }
    }
    $mailsystem->save();
  }

  /**
   * Gets today's number of SES recipients accepted by this module.
   */
  private function dailySentCount(): int {
    $timezone = (string) ($this->config('system.date')->get('timezone.default') ?: date_default_timezone_get());
    $day = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
    return (int) $this->state->get('ses_api_mailer.daily_send_count.' . $day, 0);
  }

  /**
   * Builds a local operational health summary without contacting AWS.
   */
  private function deliveryHealth(): array {
    $limit = max(0, (int) ($this->config('ses_api_mailer.settings')->get('daily_send_limit') ?? 50));
    $sent = $this->dailySentCount();
    $percentage = $limit === 0 ? 0 : (int) ceil(($sent / $limit) * 100);
    $level = $limit > 0 && $sent >= $limit ? 'limit' : ($limit > 0 && $percentage >= 80 ? 'warning' : 'ready');
    $message = match ($level) {
      'limit' => $this->t('El límite local diario ya se alcanzó. Drupal bloqueará nuevos envíos por SES hasta el siguiente día.'),
      'warning' => $this->t('Se ha utilizado el @percentage% del límite local diario. Considera ajustar el límite antes de que se bloqueen envíos.', ['@percentage' => $percentage]),
      default => $limit === 0
        ? $this->t('No hay límite local configurado. El contador se mantiene para consulta operativa.')
        : $this->t('El consumo local se encuentra dentro del límite diario configurado.'),
    };

    $success = $this->state->get('ses_api_mailer.last_success', []);
    $failure = $this->state->get('ses_api_mailer.last_failure', []);
    $last_success = is_array($success) ? $this->formatEvent($success, FALSE) : $this->t('Aún no hay envíos registrados.');
    $last_failure = is_array($failure) ? $this->formatEvent($failure, TRUE) : $this->t('No hay errores registrados desde que se activó este seguimiento.');

    return [
      '#markup' => '<div class="ses-api-mailer-health ses-api-mailer-health--' . $level . '">'
        . '<div class="ses-api-mailer-health__headline"><strong>' . $this->t('Salud de entrega local') . '</strong><span>' . $message . '</span></div>'
        . '<div class="ses-api-mailer-health__facts">'
        . '<div><span>' . $this->t('Uso hoy') . '</span><strong>' . ($limit === 0 ? $sent : $sent . ' / ' . $limit) . '</strong></div>'
        . '<div><span>' . $this->t('Último envío aceptado') . '</span><strong>' . $last_success . '</strong></div>'
        . '<div><span>' . $this->t('Último error registrado') . '</span><strong>' . $last_failure . '</strong></div>'
        . '</div></div>',
    ];
  }

  /**
   * Formats a private local delivery event for administrators.
   */
  private function formatEvent(array $event, bool $failure): string {
    $timestamp = (int) ($event['timestamp'] ?? 0);
    if ($timestamp <= 0) {
      return (string) $this->t('No disponible');
    }

    $timezone = new \DateTimeZone((string) ($this->config('system.date')->get('timezone.default') ?: date_default_timezone_get()));
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('d/m/Y H:i');
    if ($failure) {
      $message = trim((string) ($event['message'] ?? ''));
      return Html::escape($date . ($message !== '' ? ' - ' . $message : ''));
    }

    $recipients = max(0, (int) ($event['recipients'] ?? 0));
    return Html::escape($date . ($recipients > 0 ? ' - ' . $this->formatPlural($recipients, '1 destinatario', '@count destinatarios') : ''));
  }

  /**
   * Returns available months based on the private daily SES counters.
   *
   * @return array<string, string>
   *   Month keys and human-readable labels.
   */
  private function monthOptions(): array {
    $months = [];
    foreach (array_keys($this->dailyCounts()) as $day) {
      $months[substr($day, 0, 7)] = TRUE;
    }

    $timezone = (string) ($this->config('system.date')->get('timezone.default') ?: date_default_timezone_get());
    $months[(new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m')] = TRUE;
    ksort($months);

    $options = [];
    foreach (array_reverse(array_keys($months)) as $month) {
      $options[$month] = (new \DateTimeImmutable($month . '-01'))->format('m/Y');
    }

    return $options;
  }

  /**
   * Builds the selected month's private aggregate report.
   */
  private function monthlyReport(string $month): array {
    $counts = array_filter(
      $this->dailyCounts(),
      static fn (string $day): bool => str_starts_with($day, $month . '-'),
      ARRAY_FILTER_USE_KEY,
    );
    $total = array_sum($counts);

    $rows = [];
    foreach ($counts as $day => $count) {
      $rows[] = [
        'data' => [
          ['data' => (new \DateTimeImmutable($day))->format('d/m/Y')],
          ['data' => (string) $count],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'ses-api-mailer-monthly-report'],
      'summary' => [
        '#markup' => '<div class="ses-api-mailer-monthly-summary"><div><span>' . $this->t('Correos enviados por SES') . '</span><strong>' . $total . '</strong></div><p>' . $this->t('Cuenta destinatarios aceptados por SES. No se almacenan direcciones, asuntos ni contenido; un correo a varios destinatarios cuenta una vez por cada destinatario.') . '</p></div>',
      ],
      'daily' => [
        '#type' => 'table',
        '#header' => [$this->t('Fecha'), $this->t('Correos enviados')],
        '#rows' => $rows,
        '#empty' => $this->t('No hay envíos registrados para este mes.'),
      ],
    ];
  }

  /**
   * Gets daily recipient counts without exposing any message data.
   *
   * @return array<string, int>
   *   Counts keyed by ISO date.
   */
  private function dailyCounts(): array {
    $prefix = 'ses_api_mailer.daily_send_count.';
    $result = $this->database->select('key_value', 'kv')
      ->fields('kv', ['name', 'value'])
      ->condition('collection', 'state')
      ->condition('name', $prefix . '%', 'LIKE')
      ->execute();

    $counts = [];
    foreach ($result as $record) {
      $day = substr((string) $record->name, strlen($prefix));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        continue;
      }
      $value = unserialize($record->value, ['allowed_classes' => FALSE]);
      if (is_int($value) || is_float($value) || is_string($value) && is_numeric($value)) {
        $counts[$day] = max(0, (int) $value);
      }
    }
    ksort($counts);

    return $counts;
  }

}
