<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Mail;

use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\OneTimeAuthentication;
use Drupal\user\UserInterface;

/**
 * Rewrites the account e-mails sent to a client's users.
 *
 * Drupal's own account e-mails are in English and speak about "a site
 * administrator at ...", which says nothing to the person receiving it. These
 * users only ever use the panel of their own chatbot, so they get Spanish,
 * HTML e-mails that explain exactly that. Anybody else keeps Drupal's text:
 * the rewrite only applies to accounts with the client agent role.
 */
final class ClientAgentMail {

  use StringTranslationTrait;

  /**
   * Role whose users get these e-mails.
   */
  public const ROLE = 'ai_whatsapp_client_agent';

  /**
   * Constructs a ClientAgentMail object.
   */
  public function __construct(
    private readonly OneTimeAuthentication $oneTimeAuthentication,
    private readonly RendererInterface $renderer,
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
    if (!$account instanceof UserInterface || !in_array(self::ROLE, $account->getRoles(), TRUE)) {
      return;
    }
    $copy = $this->copy((string) ($message['key'] ?? ''), $account);
    if ($copy === NULL) {
      return;
    }

    $message['subject'] = $copy['subject'];
    $message['body'] = [Markup::create($this->render($copy, $account))];
    // Read by the Amazon SES mailer (ses_api_mailer) to send HTML.
    $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
  }

  /**
   * Returns the copy for a mail key, or NULL when it is not one of ours.
   *
   * @return array<string, mixed>|null
   *   Subject, heading, paragraphs, call to action and note.
   */
  private function copy(string $key, UserInterface $account): ?array {
    $client = $this->clientName($account);
    $arguments = [
      '@name' => $account->getDisplayName(),
      '@client' => $client,
    ];

    return match ($key) {
      // An administrator created the account and asked to notify the user.
      'register_admin_created' => [
        'subject' => (string) $this->t('Tu acceso al panel del chatbot de @client', $arguments),
        'heading' => $this->t('Ya tienes acceso al panel de tu chatbot'),
        'intro' => $this->t('Hola @name: creamos tu cuenta para el panel donde vive el chatbot de @client.', $arguments),
        'items' => [
          $this->t('Consulta las conversaciones que el chatbot tiene con tus clientes por WhatsApp.'),
          $this->t('Pausa la inteligencia artificial y responde tú cuando prefieras atender personalmente.'),
          $this->t('Da seguimiento a los prospectos que el chatbot detecta y cambia su estado.'),
        ],
        'action' => $this->t('Activar mi cuenta'),
        'note' => $this->t('El enlace se usa una sola vez. Al abrirlo defines tu contraseña y entras directo al panel; después entra con tu usuario @name y esa contraseña.', $arguments),
      ],
      // The account was blocked and an administrator activated it again.
      'status_activated' => [
        'subject' => (string) $this->t('Tu cuenta del panel del chatbot ya está activa'),
        'heading' => $this->t('Tu cuenta ya está activa'),
        'intro' => $this->t('Hola @name: tu cuenta del panel del chatbot de @client quedó activada y puedes volver a entrar.', $arguments),
        'items' => [],
        'action' => $this->t('Entrar al panel'),
        'note' => $this->t('Este enlace de acceso se usa una sola vez; después entra con tu usuario y contraseña de siempre.'),
      ],
      'password_reset' => [
        'subject' => (string) $this->t('Restablece tu contraseña del panel del chatbot'),
        'heading' => $this->t('Restablece tu contraseña'),
        'intro' => $this->t('Hola @name: recibimos una solicitud para cambiar la contraseña de tu cuenta del panel del chatbot de @client.', $arguments),
        'items' => [],
        'action' => $this->t('Crear una contraseña nueva'),
        'note' => $this->t('El enlace se usa una sola vez. Si no pediste el cambio, ignora este correo: tu contraseña sigue igual.'),
      ],
      default => NULL,
    };
  }

  /**
   * Renders the HTML body.
   *
   * @param array<string, mixed> $copy
   *   The copy returned by ::copy().
   */
  private function render(array $copy, UserInterface $account): string {
    $build = [
      '#theme' => 'ai_whatsapp_client_agent_mail',
      '#heading' => $copy['heading'],
      '#intro' => $copy['intro'],
      '#items' => $copy['items'],
      '#action' => $copy['action'],
      // Every one of these e-mails carries a one-time login link, the same one
      // Drupal puts in its own text: it is what lets the person in the first
      // time. Twig escapes it when it writes the href.
      '#url' => $this->oneTimeAuthentication->generateOneTimeLoginUrl($account)->toString(),
      '#note' => $copy['note'],
      '#client' => $this->clientName($account),
    ];

    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Returns the client name of the account, or a neutral fallback.
   */
  private function clientName(UserInterface $account): string {
    if ($account->hasField('ai_whatsapp_client') && !$account->get('ai_whatsapp_client')->isEmpty()) {
      $client = $account->get('ai_whatsapp_client')->entity;
      if ($client !== NULL) {
        return (string) $client->label();
      }
    }

    return (string) $this->t('tu negocio');
  }

}
