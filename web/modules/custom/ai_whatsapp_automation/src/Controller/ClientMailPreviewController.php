<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\ai_whatsapp_automation\Mail\ClientAgentMail;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows how an account e-mail looks, without sending it.
 *
 * The preview is always built for the administrator looking at it, never for
 * somebody else's account: the e-mail carries a one-time login link, and that
 * link must never be readable by another person.
 */
final class ClientMailPreviewController extends ControllerBase {

  /**
   * Constructs a ClientMailPreviewController object.
   */
  public function __construct(
    private readonly ClientAgentMail $clientAgentMail,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('ai_whatsapp_automation.client_agent_mail'));
  }

  /**
   * Renders one of the e-mails as the recipient would see it.
   */
  public function preview(string $key): Response {
    $account = $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
    if (!$account instanceof UserInterface || !isset(ClientAgentMail::DEFAULTS[$key])) {
      return new Response('', 404);
    }

    return new Response(
      $this->clientAgentMail->preview($key, $account, $this->sampleClientName()),
      200,
      ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex']
    );
  }

  /**
   * Returns a real client name so the preview reads like the real e-mail.
   */
  private function sampleClientName(): string {
    $storage = $this->entityTypeManager()->getStorage('ai_whatsapp_client');
    $ids = $storage->getQuery()->accessCheck(TRUE)->sort('id')->range(0, 1)->execute();
    $client = $ids === [] ? NULL : $storage->load(reset($ids));

    return $client === NULL ? '' : (string) $client->label();
  }

}
