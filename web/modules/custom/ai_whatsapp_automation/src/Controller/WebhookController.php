<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\ai_whatsapp_automation\Application\Webhook\WebhookProviderService;
use Drupal\ai_whatsapp_automation\Application\Webhook\WebhookQueueService;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives WhatsApp provider webhooks.
 */
final class WebhookController extends ControllerBase {

  /**
   * Constructs a WebhookController object.
   */
  public function __construct(
    private readonly WebhookProviderService $providerService,
    private readonly WebhookQueueService $queueService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_whatsapp_automation.webhook_provider'),
      $container->get('ai_whatsapp_automation.webhook_queue'),
    );
  }

  /**
   * Handles incoming webhook requests.
   */
  public function handle(Request $request, string $provider): Response {
    if ($request->isMethod('GET') && $provider === 'cloud_api') {
      return $this->handleCloudVerification($request);
    }

    if (!$request->isMethod('POST')) {
      return new JsonResponse(['error' => 'Method not allowed.'], Response::HTTP_METHOD_NOT_ALLOWED);
    }

    if (!$this->providerService->validate($provider, $request)) {
      return new JsonResponse(['error' => 'Invalid webhook signature.'], Response::HTTP_FORBIDDEN);
    }

    $message = $this->providerService->normalize($provider, $request);
    if ($message === []) {
      return new JsonResponse(['status' => 'ignored']);
    }

    $this->queueService->enqueue($provider, $message);

    return new JsonResponse(['status' => 'queued']);
  }

  /**
   * Handles WhatsApp Cloud API webhook verification.
   */
  private function handleCloudVerification(Request $request): Response {
    if (!$this->providerService->validateCloudVerification($request)) {
      return new Response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    return new Response((string) $request->query->get('hub_challenge', $request->query->get('hub.challenge', '')));
  }

}
