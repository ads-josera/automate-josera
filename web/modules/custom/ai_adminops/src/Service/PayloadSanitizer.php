<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Serialization\Json;

/**
 * Sanitizes structured operational data before it is persisted in Drupal.
 */
final class PayloadSanitizer {

  /**
   * Keys whose values must never be retained in audit entities.
   */
  private const SENSITIVE_KEYS = [
    'api_key',
    'apikey',
    'authorization',
    'cookie',
    'credential',
    'credentials',
    'password',
    'private_key',
    'secret',
    'token',
  ];

  /**
   * Returns a sanitized JSON representation of structured data.
   *
   * @param array<string, mixed> $payload
   *   The source data.
   */
  public function encode(array $payload): string {
    return Json::encode($this->sanitize($payload));
  }

  /**
   * Removes secret-looking values and normalizes unsupported values.
   *
   * @param array<string, mixed> $payload
   *   The source data.
   *
   * @return array<string, mixed>
   *   Safe data for audit storage.
   */
  public function sanitize(array $payload): array {
    $sanitized = [];
    foreach ($payload as $key => $value) {
      $normalized_key = mb_strtolower((string) $key);
      if ($this->isSensitiveKey($normalized_key)) {
        $sanitized[(string) $key] = '[redacted]';
        continue;
      }

      $sanitized[(string) $key] = $this->sanitizeValue($value);
    }

    return $sanitized;
  }

  /**
   * Sanitizes a single nested value.
   */
  private function sanitizeValue(mixed $value): mixed {
    if (is_array($value)) {
      return $this->sanitize($value);
    }

    if (is_scalar($value) || $value === NULL) {
      return $value;
    }

    return '[unsupported value]';
  }

  /**
   * Checks whether a key could contain a secret.
   */
  private function isSensitiveKey(string $key): bool {
    foreach (self::SENSITIVE_KEYS as $sensitive_key) {
      if ($key === $sensitive_key || str_contains($key, $sensitive_key)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
