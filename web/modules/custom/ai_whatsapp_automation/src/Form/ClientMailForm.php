<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\ai_whatsapp_automation\Mail\ClientAgentMail;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an administrator write the account e-mails clients receive.
 *
 * Only the text and the logo are editable: the layout, the brand colours and
 * the one-time login link are built by the module, so a saved change can
 * never produce an e-mail nobody can log in with.
 */
final class ClientMailForm extends ConfigFormBase {

  /**
   * Titles of each e-mail, in the order they are shown.
   */
  private const TITLES = [
    'register_admin_created' => 'Bienvenida: cuenta creada',
    'password_reset' => 'Restablecer contraseña',
    'status_activated' => 'Cuenta reactivada',
  ];

  /**
   * Constructs a ClientMailForm object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ClientAgentMail $clientAgentMail,
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
      $container->get('ai_whatsapp_automation.client_agent_mail'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_client_mail_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ClientAgentMail::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(ClientAgentMail::CONFIG);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Estos son los correos que reciben los usuarios de tus clientes (rol «Atención a clientes»). Nadie más los recibe: el personal interno sigue recibiendo los correos normales de Drupal.') . '</p>',
    ];

    $form['branding'] = [
      '#type' => 'details',
      '#title' => $this->t('Imagen de marca'),
      '#open' => TRUE,
    ];
    $form['branding']['logo_url'] = [
      '#type' => 'url',
      '#title' => $this->t('URL del logotipo'),
      '#default_value' => $config->get('logo_url') ?? '',
      '#description' => $this->t('Se muestra sobre el encabezado morado. Déjalo vacío para usar el mismo logotipo de la pantalla de acceso (@url). Recomendación: un PNG, porque Gmail y Outlook no muestran SVG en los correos.', [
        '@url' => $this->clientAgentMail->loginLogoUrl() ?: (string) $this->t('todavía no hay uno'),
      ]),
    ];
    $form['branding']['footer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Línea de pie de página'),
      '#default_value' => $config->get('footer') ?? '',
      '#maxlength' => 255,
      '#description' => $this->t('Vacío: «@default»', ['@default' => ClientAgentMail::DEFAULT_FOOTER]),
    ];

    $form['messages'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Textos de cada correo'),
    ];
    $labels = [
      'subject' => $this->t('Asunto'),
      'heading' => $this->t('Título dentro del correo'),
      'intro' => $this->t('Párrafo de entrada'),
      'items' => $this->t('Lista de viñetas (una por línea)'),
      'action' => $this->t('Texto del botón'),
      'note' => $this->t('Letra pequeña bajo el botón'),
    ];
    foreach (self::TITLES as $key => $title) {
      $form[$key] = [
        '#type' => 'details',
        '#title' => $title,
        '#group' => 'messages',
        '#tree' => TRUE,
      ];
      $form[$key]['preview'] = [
        '#type' => 'link',
        '#title' => $this->t('Ver una vista previa de este correo'),
        '#url' => Url::fromRoute('ai_whatsapp_automation.client_mail_preview', ['key' => $key]),
        '#attributes' => ['class' => ['button'], 'target' => '_blank'],
      ];
      foreach ($labels as $name => $label) {
        $default = ClientAgentMail::DEFAULTS[$key][$name];
        $form[$key][$name] = [
          '#type' => in_array($name, ['intro', 'items', 'note'], TRUE) ? 'textarea' : 'textfield',
          '#title' => $label,
          '#rows' => 3,
          '#default_value' => $config->get('messages.' . $key . '.' . $name) ?? '',
          '#description' => $default === ''
            ? $this->t('Vacío: no se muestra.')
            : $this->t('Vacío: se usa el texto de fábrica «@default»', ['@default' => $default]),
        ];
      }
    }

    $form['placeholders'] = [
      '#markup' => '<p>' . $this->t('En cualquier texto puedes escribir <strong>[cliente]</strong> (nombre del cliente) y <strong>[nombre]</strong> (usuario de la cuenta). El enlace de acceso y el botón los pone el sistema.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ClientAgentMail::CONFIG);
    $config->set('logo_url', trim((string) $form_state->getValue('logo_url')));
    $config->set('footer', trim((string) $form_state->getValue('footer')));

    $messages = [];
    foreach (array_keys(self::TITLES) as $key) {
      $values = (array) $form_state->getValue($key, []);
      foreach (array_keys(ClientAgentMail::DEFAULTS[$key]) as $name) {
        $messages[$key][$name] = trim((string) ($values[$name] ?? ''));
      }
    }
    $config->set('messages', $messages)->save();

    parent::submitForm($form, $form_state);
  }

}
