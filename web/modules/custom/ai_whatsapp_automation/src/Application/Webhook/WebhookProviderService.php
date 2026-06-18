<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Webhook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Validates and normalizes provider webhook requests.
 */
final class WebhookProviderService {

  /**
   * Constructs a WebhookProviderService object.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * Validates a provider webhook request.
   */
  public function validate(string $provider, Request $request): bool {
    return match ($provider) {
      'twilio' => $this->validateTwilio($request),
      'cloud_api' => TRUE,
      'evolution' => $this->validateEvolution($request),
      default => FALSE,
    };
  }

  /**
   * Validates WhatsApp Cloud API verification requests.
   */
  public function validateCloudVerification(Request $request): bool {
    $config = $this->configFactory->get('ai_whatsapp_automation.settings');
    $verify_token = (string) $config->get('whatsapp_cloud.verify_token');
    $mode = (string) $request->query->get('hub_mode', $request->query->get('hub.mode', ''));
    $token = (string) $request->query->get('hub_verify_token', $request->query->get('hub.verify_token', ''));
    $challenge = (string) $request->query->get('hub_challenge', $request->query->get('hub.challenge', ''));

    return $verify_token !== ''
      && $mode === 'subscribe'
      && hash_equals($verify_token, $token)
      && $challenge !== '';
  }

  /**
   * Normalizes a provider webhook request.
   *
   * @return array<string, mixed>
   *   The normalized message data.
   */
  public function normalize(string $provider, Request $request): array {
    return match ($provider) {
      'twilio' => $this->normalizeTwilio($request),
      'cloud_api' => $this->normalizeCloudApi($request),
      'evolution' => $this->normalizeEvolution($request),
      default => [],
    };
  }

  /**
   * Validates Twilio signatures.
   */
  private function validateTwilio(Request $request): bool {
    $auth_token = (string) $this->configFactory
      ->get('ai_whatsapp_automation.settings')
      ->get('twilio.auth_token');
    $signature = (string) $request->headers->get('X-Twilio-Signature', '');

    if ($auth_token === '' || $signature === '') {
      return FALSE;
    }

    $url = $this->getExternalUrl($request);
    $params = $request->request->all();
    ksort($params, SORT_STRING);

    $data = $url;
    foreach ($params as $key => $value) {
      if (is_scalar($value)) {
        $data .= $key . $value;
      }
    }

    $expected = base64_encode(hash_hmac('sha1', $data, $auth_token, TRUE));

    return hash_equals($expected, $signature);
  }

  /**
   * Validates Evolution API webhook requests.
   */
  private function validateEvolution(Request $request): bool {
    $api_key = (string) $this->configFactory
      ->get('ai_whatsapp_automation.settings')
      ->get('evolution.api_key');

    if ($api_key === '') {
      return FALSE;
    }

    foreach (['apikey', 'x-api-key', 'authorization'] as $header) {
      $value = (string) $request->headers->get($header, '');
      $value = preg_replace('/^Bearer\s+/i', '', $value) ?? '';
      if ($value !== '' && hash_equals($api_key, $value)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Normalizes Twilio webhook payloads.
   */
  private function normalizeTwilio(Request $request): array {
    $body = trim((string) $request->request->get('Body', ''));
    $from = $this->normalizePhone((string) $request->request->get('From', ''));

    if ($body === '' || $from === '') {
      return [];
    }

    return [
      'phone' => $from,
      'account_phone' => $this->normalizePhone((string) $request->request->get('To', '')),
      'body' => $body,
      'provider_message_id' => (string) $request->request->get('MessageSid', $request->request->get('SmsMessageSid', '')),
      'raw' => $request->request->all(),
    ];
  }

  /**
   * Normalizes WhatsApp Cloud API webhook payloads.
   */
  private function normalizeCloudApi(Request $request): array {
    $payload = $this->decodeJsonBody($request);
    $value = $payload['entry'][0]['changes'][0]['value'] ?? [];
    $message = $value['messages'][0] ?? [];
    $body = trim((string) ($message['text']['body'] ?? ''));
    $from = $this->normalizePhone((string) ($message['from'] ?? ''));

    if ($body === '' || $from === '') {
      return [];
    }

    return [
      'phone' => $from,
      'account_phone' => $this->normalizePhone((string) ($value['metadata']['display_phone_number'] ?? $value['metadata']['phone_number_id'] ?? '')),
      'body' => $body,
      'provider_message_id' => (string) ($message['id'] ?? ''),
      'raw' => $payload,
    ];
  }

  /**
   * Normalizes Evolution API webhook payloads.
   */
  private function normalizeEvolution(Request $request): array {
    $payload = $this->decodeJsonBody($request);
    $data = is_array($payload['data'] ?? NULL) ? $payload['data'] : $payload;
    $message = $data['message'] ?? [];
    $body = trim((string) ($message['conversation'] ?? $message['extendedTextMessage']['text'] ?? $data['text'] ?? ''));
    $remote_jid = (string) ($data['key']['remoteJid'] ?? $data['remoteJid'] ?? '');
    $from = $this->normalizePhone($remote_jid);

    if ($body === '' || $from === '') {
      return [];
    }

    return [
      'phone' => $from,
      'account_phone' => (string) ($payload['instance'] ?? $data['instance'] ?? ''),
      'body' => $body,
      'provider_message_id' => (string) ($data['key']['id'] ?? $data['id'] ?? ''),
      'raw' => $payload,
    ];
  }

  /**
   * Decodes a JSON request body.
   *
   * @return array<string, mixed>
   *   The decoded payload.
   */
  private function decodeJsonBody(Request $request): array {
    try {
      $payload = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }

    return is_array($payload) ? $payload : [];
  }

  /**
   * Returns the external URL seen by the provider.
   */
  private function getExternalUrl(Request $request): string {
    $current = $this->requestStack->getCurrentRequest() ?? $request;

    return $current->getSchemeAndHttpHost() . $request->getRequestUri();
  }

  /**
   * Normalizes provider phone identifiers.
   */
  private function normalizePhone(string $phone): string {
    $phone = preg_replace('/^whatsapp:/', '', trim($phone)) ?? '';
    $phone = preg_replace('/@.+$/', '', $phone) ?? '';

    return $phone;
  }

}
