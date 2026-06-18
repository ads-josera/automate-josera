<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Evolution;

/**
 * Provider facade for Evolution QR connections and messaging.
 */
final class QRProvider {

  /**
   * Constructs a QRProvider object.
   */
  public function __construct(
    private readonly EvolutionApiClient $client,
  ) {
  }

  /**
   * Gets a QR code for an instance.
   *
   * @return array<string, mixed>
   *   The QR response.
   */
  public function getQr(string $instance_name): array {
    return $this->client->connectInstance($instance_name);
  }

  /**
   * Validates and returns the current connection status.
   *
   * @return array<string, mixed>
   *   The connection state response.
   */
  public function validateConnection(string $instance_name): array {
    return $this->client->connectionState($instance_name);
  }

  /**
   * Sends a text message through Evolution.
   *
   * @return array<string, mixed>
   *   The delivery response.
   */
  public function sendMessage(string $instance_name, string $phone, string $text): array {
    return $this->client->sendText($instance_name, $phone, $text);
  }

  /**
   * Normalizes an incoming Evolution webhook payload.
   *
   * @param array<string, mixed> $payload
   *   Raw webhook payload.
   *
   * @return array<string, mixed>
   *   The normalized payload.
   */
  public function receiveMessage(array $payload): array {
    return $payload;
  }

}
