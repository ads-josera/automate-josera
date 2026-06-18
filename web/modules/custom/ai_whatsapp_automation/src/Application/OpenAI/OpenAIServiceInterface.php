<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\OpenAI;

/**
 * Defines the contract for OpenAI text generation services.
 */
interface OpenAIServiceInterface {

  /**
   * Sends a prompt to OpenAI and returns normalized response data.
   *
   * @param string $prompt
   *   The user prompt to send.
   * @param string|null $model
   *   Optional model override. When empty, the configured default is used.
   * @param array<string, mixed> $options
   *   Optional Responses API parameters supported by this module.
   *
   * @return array<string, mixed>
   *   Normalized response data, including text, usage, cost, and raw response.
   */
  public function sendPrompt(string $prompt, ?string $model = NULL, array $options = []): array;

  /**
   * Selects the model that should be used for a request.
   */
  public function selectModel(?string $requested_model = NULL): string;

  /**
   * Normalizes and registers token usage.
   *
   * @param array<string, mixed> $usage
   *   The usage payload returned by OpenAI.
   * @param array<string, mixed> $context
   *   Additional context for logs or future persistence.
   *
   * @return array<string, int>
   *   Normalized token usage.
   */
  public function registerTokens(array $usage, array $context = []): array;

  /**
   * Registers an estimated request cost.
   *
   * @param string $model
   *   The OpenAI model used.
   * @param array<string, int> $tokens
   *   Normalized token usage.
   * @param array<string, float> $rates_per_million
   *   Optional input/output rates per one million tokens.
   * @param array<string, mixed> $context
   *   Additional context for logs or future persistence.
   *
   * @return array<string, mixed>
   *   Normalized cost data.
   */
  public function registerCost(
    string $model,
    array $tokens,
    array $rates_per_million = [],
    array $context = [],
  ): array;

}
