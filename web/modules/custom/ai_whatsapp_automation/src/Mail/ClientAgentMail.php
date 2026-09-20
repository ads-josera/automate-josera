<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\file\FileInterface;
use Drupal\user\OneTimeAuthentication;
use Drupal\user\UserInterface;

/**
 * Rewrites the account e-mails sent to a client's users.
 *
 * Drupal's own account e-mails are in English and speak about "a site
 * administrator at ...", which says nothing to the person receiving it. These
 * users only ever use the panel of their own chatbot, so they get Spanish,
 * branded e-mails that explain exactly that. Anybody else keeps Drupal's text:
 * the rewrite only applies to accounts with the client agent role.
 *
 * Every piece of copy can be changed at
 * /admin/config/services/ai-whatsapp-automation/correos; what is left empty
 * falls back to the defaults below.
 */
final class ClientAgentMail {

  /**
   * Role whose users get these e-mails.
   */
  public const ROLE = 'ai_whatsapp_client_agent';

  /**
   * Editable configuration.
   */
  public const CONFIG = 'ai_whatsapp_automation.client_mail';

  /**
   * Default footer line.
   */
  public const DEFAULT_FOOTER = 'Recibes este correo porque [cliente] tiene un chatbot automatizado con Josera Marketing Digital.';

  /**
   * Copy shipped with the module, per user module mail key.
   *
   * [nombre] is the user name of the account, [cliente] the client it belongs
   * to. Every one of these e-mails carries a one-time login link.
   */
  public const DEFAULTS = [
    // An administrator created the account and asked to notify the user.
    'register_admin_created' => [
      'subject' => 'Tu acceso al panel del chatbot de [cliente]',
      'heading' => 'Tu panel del chatbot ya está listo',
      'intro' => 'Hola: creamos tu acceso al panel de [cliente], donde puedes seguir en un solo lugar todo lo que tu chatbot conversa con tus clientes por WhatsApp.',
      'items' => "Ver cada conversación completa, en el momento en que ocurre.\nTomar el control cuando quieras: pausas la inteligencia artificial y respondes tú por WhatsApp.\nDar seguimiento a los prospectos que el chatbot detecta y marcar cómo va cada uno.",
      'action' => 'Activar mi acceso',
      'note' => 'Tu usuario es [nombre]. El enlace funciona una sola vez: al abrirlo defines tu contraseña y entras directo al panel.',
    ],
    // The account was blocked and an administrator activated it again.
    'status_activated' => [
      'subject' => 'Tu acceso al panel del chatbot está activo de nuevo',
      'heading' => 'Tu acceso ya está activo',
      'intro' => 'Hola: la cuenta [nombre] volvió a quedar activa. Puedes entrar al panel del chatbot de [cliente] cuando quieras.',
      'items' => '',
      'action' => 'Entrar al panel',
      'note' => 'Este enlace de acceso funciona una sola vez; después entra con tu usuario y contraseña de siempre.',
    ],
    'password_reset' => [
      'subject' => 'Restablece la contraseña del panel de tu chatbot',
      'heading' => 'Restablece tu contraseña',
      'intro' => 'Hola: recibimos una solicitud para cambiar la contraseña de la cuenta [nombre], del panel del chatbot de [cliente].',
      'items' => '',
      'action' => 'Crear una contraseña nueva',
      'note' => 'El enlace funciona una sola vez y caduca en 24 horas. Si no pediste el cambio, ignora este correo: tu contraseña sigue igual.',
    ],
  ];

  /**
   * Constructs a ClientAgentMail object.
   */
  public function __construct(
    private readonly OneTimeAuthentication $oneTimeAuthentication,
    private readonly RendererInterface $renderer,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Replaces a user module e-mail when it goes to a client's user.
   *
   * @param array<string, mixed> $message
   *   The message being sent, altered in place.
   */
  public function alter(array &$message): void {
    if (($message['module'] ?? '') !== 'user') {
      return;
    }
    $account = $message['params']['account'] ?? NULL;
    $key = (string) ($message['key'] ?? '');
    if (!$account instanceof UserInterface || !isset(self::DEFAULTS[$key])) {
      return;
    }
    if (!in_array(self::ROLE, $account->getRoles(), TRUE)) {
      return;
    }

    $copy = $this->copy($key, $account);
    $message['subject'] = $copy['subject'];
    $message['body'] = [Markup::create($this->render($copy, $account))];
    // Read by the Amazon SES mailer (ses_api_mailer) to send HTML.
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
  }

  /**
   * Renders one of the e-mails without sending it, for the preview page.
   */
  public function preview(string $key, UserInterface $account, string $client = ''): string {
    return $this->render($this->copy($key, $account, $client), $account, $client);
  }

  /**
   * Returns the copy of a mail key with the placeholders replaced.
   *
   * @return array<string, mixed>
   *   Subject, heading, intro, items, action, note and footer.
   */
  private function copy(string $key, UserInterface $account, string $client = ''): array {
    $saved = $this->configFactory->get(self::CONFIG)->get('messages.' . $key) ?? [];
    $copy = [];
    foreach (self::DEFAULTS[$key] as $name => $default) {
      $value = trim((string) ($saved[$name] ?? ''));
      $copy[$name] = $value !== '' ? $value : $default;
    }
    $footer = trim((string) ($this->configFactory->get(self::CONFIG)->get('footer') ?? ''));
    $copy['footer'] = $footer !== '' ? $footer : self::DEFAULT_FOOTER;

    $replacements = [
      '[nombre]' => $account->getAccountName(),
      '[cliente]' => $this->clientName($account, $client),
    ];
    foreach ($copy as $name => $value) {
      $copy[$name] = strtr((string) $value, $replacements);
    }
    $copy['items'] = array_values(array_filter(array_map('trim', explode("\n", (string) $copy['items']))));

    return $copy;
  }

  /**
   * Renders the HTML body.
   *
   * @param array<string, mixed> $copy
   *   The copy returned by ::copy().
   */
  private function render(array $copy, UserInterface $account, string $client = ''): string {
    $build = [
      '#theme' => 'ai_whatsapp_client_agent_mail',
      '#heading' => $copy['heading'],
      '#intro' => $copy['intro'],
      '#items' => $copy['items'],
      '#action' => $copy['action'],
      // The one-time login link, the same one Drupal puts in its own text: it
      // is what lets the person in the first time. Twig escapes the href.
      '#url' => $this->oneTimeAuthentication->generateOneTimeLoginUrl($account)->toString(),
      '#note' => $copy['note'],
      '#footer' => $copy['footer'],
      '#client' => $this->clientName($account, $client),
      '#logo_url' => $this->logoUrl(),
    ];

    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Returns the logo to show in the header, or an empty string.
   *
   * Defaults to the logo of the login screen, so the e-mail and the panel
   * carry the same brand without having to upload it twice.
   */
  private function logoUrl(): string {
    $configured = trim((string) ($this->configFactory->get(self::CONFIG)->get('logo_url') ?? ''));
    if ($configured !== '') {
      return $configured;
    }

    return $this->loginLogoUrl();
  }

  /**
   * Returns the absolute URL of the login screen logo, or an empty string.
   */
  public function loginLogoUrl(): string {
    // Read from the theme's own configuration instead of theme_get_setting():
    // the e-mail is built outside any theme, and the setting is plain config.
    $file_ids = $this->configFactory->get('josera_access.settings')->get('login_logo') ?: [];
    $file_id = is_array($file_ids) ? reset($file_ids) : $file_ids;
    if (empty($file_id)) {
      return '';
    }
    $file = $this->entityTypeManager->getStorage('file')->load($file_id);

    return $file instanceof FileInterface
      ? $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri())
      : '';
  }

  /**
   * Returns the client name of the account, or a neutral fallback.
   *
   * The preview page passes a sample name: an administrator has no client.
   */
  private function clientName(UserInterface $account, string $override = ''): string {
    if ($override !== '') {
      return $override;
    }
    if ($account->hasField('ai_whatsapp_client') && !$account->get('ai_whatsapp_client')->isEmpty()) {
      $client = $account->get('ai_whatsapp_client')->entity;
      if ($client !== NULL) {
        return (string) $client->label();
      }
    }

    return 'tu negocio';
  }

}
