<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Evolution;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Low-level client for Evolution API requests.
 */
final class EvolutionApiClient {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs an EvolutionApiClient object.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Creates an Evolution instance.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function createInstance(string $instance_name): array {
    return $this->request('POST', '/instance/create', [
      'json' => [
        'instanceName' => $instance_name,
        'qrcode' => TRUE,
        'integration' => 'WHATSAPP-BAILEYS',
      ],
    ]);
  }

  /**
   * Deletes an Evolution instance.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function deleteInstance(string $instance_name): array {
    return $this->request('DELETE', '/instance/delete/' . rawurlencode($instance_name));
  }

  /**
   * Restarts an Evolution instance.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function restartInstance(string $instance_name): array {
    return $this->request('PUT', '/instance/restart/' . rawurlencode($instance_name));
  }

  /**
   * Logs out a connected Evolution instance.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function logoutInstance(string $instance_name): array {
    return $this->request('DELETE', '/instance/logout/' . rawurlencode($instance_name));
  }

  /**
   * Requests a QR code for an Evolution instance.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function connectInstance(string $instance_name): array {
    return $this->request('GET', '/instance/connect/' . rawurlencode($instance_name));
  }

  /**
   * Fetches the current connection state.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function connectionState(string $instance_name): array {
    return $this->request('GET', '/instance/connectionState/' . rawurlencode($instance_name));
  }

  /**
   * Sends a text message through an Evolution instance.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function sendText(string $instance_name, string $phone, string $text): array {
    return $this->request('POST', '/message/sendText/' . rawurlencode($instance_name), [
      'json' => [
        'number' => ltrim($phone, '+'),
        'text' => $text,
      ],
    ]);
  }

  /**
   * Performs an authenticated Evolution API request.
   *
   * @param array<string, mixed> $options
   *   Guzzle request options.
   *
   * @return array<string, mixed>
   *   The normalized response.
   */
  public function request(string $method, string $path, array $options = []): array {
    $config = $this->configFactory->get('ai_whatsapp_automation.settings');
    $server_url = rtrim((string) $config->get('evolution.server_url'), '/');
    $api_key = (string) $config->get('evolution.api_key');

    if ($server_url === '' || $api_key === '') {
      return [
        'success' => FALSE,
        'status' => 'missing_configuration',
        'error' => 'Evolution API server URL or API key is not configured.',
      ];
    }

    try {
      $response = $this->httpClient->request($method, $server_url . $path, $options + [
        'timeout' => 20,
        'headers' => [
          'apikey' => $api_key,
          'Content-Type' => 'application/json',
        ],
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->error('Evolution API request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'status' => 'request_failed',
        'error' => $exception->getMessage(),
      ];
    }

    $body = (string) $response->getBody();
    $decoded = [];
    if ($body !== '') {
      try {
        $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        $decoded = ['raw' => $body];
      }
    }

    return [
      'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
      'status' => 'ok',
      'status_code' => $response->getStatusCode(),
      'data' => is_array($decoded) ? $decoded : [],
    ];
  }

}
