<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Webhook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Validates a provider webhook request.
   */
  public function validate(string $provider, Request $request): bool {
    return match ($provider) {
      'twilio' => $this->validateTwilio($request),
      'cloud_api' => $this->validateCloudApi($request),
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
    $signature = (string) $request->headers->get('X-Twilio-Signature', '');

    if ($signature === '') {
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

    foreach ($this->twilioWebhookAuthTokens($request) as $auth_token) {
      $expected = base64_encode(hash_hmac('sha1', $data, $auth_token, TRUE));
      if (hash_equals($expected, $signature)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Validates Meta's X-Hub-Signature-256 webhook signature.
   */
  private function validateCloudApi(Request $request): bool {
    $app_secret = (string) $this->configFactory
      ->get('ai_whatsapp_automation.settings')
      ->get('whatsapp_cloud.app_secret');
    $signature = (string) $request->headers->get('X-Hub-Signature-256', '');
    if ($app_secret === '' || !str_starts_with($signature, 'sha256=')) {
      return FALSE;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $app_secret);

    return hash_equals($expected, $signature);
  }

  /**
   * Returns the global and matching account-specific tokens for validation.
   *
   * @return string[]
   *   Candidate Twilio auth tokens.
   */
  private function twilioWebhookAuthTokens(Request $request): array {
    $tokens = [(string) $this->configFactory
      ->get('ai_whatsapp_automation.settings')
      ->get('twilio.auth_token')];
    $account_sid = trim((string) $request->request->get('AccountSid', ''));
    if ($account_sid === '') {
      return array_values(array_filter($tokens));
    }

    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_account')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider', 'twilio')
      ->condition('twilio_account_sid', $account_sid)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return array_values(array_filter($tokens));
    }

    $account = $this->entityTypeManager
      ->getStorage('ai_whatsapp_account')
      ->load(reset($ids));
    if ($account instanceof ContentEntityInterface) {
      $tokens[] = (string) $account->get('twilio_auth_token')->value;
    }

    return array_values(array_unique(array_filter($tokens)));
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
    // A voice note or a photo arrives with no text. Delivery receipts also
    // arrive with no text, but they carry no media either, so they keep
    // being ignored.
    $media = (int) $request->request->get('NumMedia', 0) > 0
      ? $this->mediaKindFromMimeType((string) $request->request->get('MediaContentType0', ''))
      : '';

    if ($from === '' || ($body === '' && $media === '')) {
      return [];
    }

    return [
      'phone' => $from,
      'account_phone' => $this->normalizePhone((string) $request->request->get('To', '')),
      'body' => $body,
      'media' => $body === '' ? $media : '',
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
    // Cloud API names the kind directly. A "statuses" payload carries no
    // message at all, so it never reaches this point with a sender.
    $media = $body === '' ? $this->mediaKind((string) ($message['type'] ?? '')) : '';

    if ($from === '' || ($body === '' && $media === '')) {
      return [];
    }

    return [
      'phone' => $from,
      'account_phone' => $this->normalizePhone((string) ($value['metadata']['display_phone_number'] ?? $value['metadata']['phone_number_id'] ?? '')),
      'body' => $body,
      'media' => $media,
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
    // Evolution names the kind in the key that holds the message, such as
    // "audioMessage" or "imageMessage".
    $media = $body === '' && is_array($message) ? $this->evolutionMediaKind($message) : '';

    if ($from === '' || ($body === '' && $media === '')) {
      return [];
    }

    return [
      'phone' => $from,
      'account_phone' => (string) ($payload['instance'] ?? $data['instance'] ?? ''),
      'body' => $body,
      'media' => $media,
      'provider_message_id' => (string) ($data['key']['id'] ?? $data['id'] ?? ''),
      'raw' => $payload,
    ];
  }

  /**
   * Returns the media kind of an Evolution message, or an empty string.
   *
   * @param array<string, mixed> $message
   *   The provider message.
   */
  private function evolutionMediaKind(array $message): string {
    foreach (array_keys($message) as $key) {
      if (!is_string($key) || !str_ends_with($key, 'Message')) {
        continue;
      }
      $kind = $this->mediaKind(substr($key, 0, -strlen('Message')));
      if ($kind !== '') {
        return $kind;
      }
    }

    return '';
  }

  /**
   * Maps a provider's own name for a message kind to ours.
   *
   * Anything not listed here is either text or something we have no reply
   * for, and returning an empty string leaves it ignored as before.
   */
  private function mediaKind(string $type): string {
    $type = strtolower(trim($type));

    return match ($type) {
      'audio', 'ptt', 'voice' => 'audio',
      'image' => 'image',
      'video' => 'video',
      'sticker' => 'sticker',
      'document' => 'document',
      'location', 'livelocation' => 'location',
      'contacts', 'contact', 'contactsarray' => 'contact',
      default => '',
    };
  }

  /**
   * Returns the media kind of a MIME type, such as "audio/ogg".
   */
  private function mediaKindFromMimeType(string $mime_type): string {
    $top_level = strtok(strtolower(trim($mime_type)), '/');
    if ($top_level === FALSE || $top_level === '') {
      return '';
    }

    // An unknown attachment is still an attachment worth acknowledging.
    return $this->mediaKind($top_level) ?: 'document';
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
