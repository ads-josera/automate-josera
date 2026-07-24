<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\EventSubscriber;

use Drupal\ai_whatsapp_automation\Application\WebChat\WebChatService;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Allows the public chat route to be embedded by authorized sites only.
 */
final class WebChatFrameResponseSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a WebChatFrameResponseSubscriber object.
   */
  public function __construct(
    private readonly WebChatService $webChat,
  ) {
  }

  /**
   * Removes Drupal's same-origin frame header for the public chat page.
   */
  public function onResponse(ResponseEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'ai_whatsapp_automation.web_chat_page') {
      return;
    }

    $token = (string) $request->attributes->get('token', '');
    $bot = $this->webChat->loadBot($token);
    if (!$bot instanceof ContentEntityInterface) {
      return;
    }

    $response = $event->getResponse();
    $response->headers->remove('X-Frame-Options');
    $response->headers->set('Content-Security-Policy', 'frame-ancestors ' . implode(' ', $this->webChat->frameAncestors($bot)));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after Drupal's response hardening subscriber.
    return [KernelEvents::RESPONSE => ['onResponse', -1000]];
  }

}
