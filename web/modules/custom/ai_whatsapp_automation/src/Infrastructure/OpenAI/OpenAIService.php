<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Infrastructure\OpenAI;

use Drupal\ai_whatsapp_automation\Application\OpenAI\OpenAIServiceInterface;
use Drupal\ai_whatsapp_automation\Exception\OpenAIServiceException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Provides OpenAI Responses API integration.
 */
final class OpenAIService implements OpenAIServiceInterface {

  /**
   * The OpenAI Responses API endpoint.
   */
  private const RESPONSES_ENDPOINT = 'https://api.openai.com/v1/responses';

  /**
   * The module settings config name.
   */
  private const SETTINGS = 'ai_whatsapp_automation.settings';

  /**
   * Constructs an OpenAIService object.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public function sendPrompt(string $prompt, ?string $model = NULL, array $options = []): array {
    $prompt = trim($prompt);
    if ($prompt === '') {
      throw new OpenAIServiceException('The prompt cannot be empty.');
    }

    $config = $this->configFactory->get(self::SETTINGS);
    if (!$config->get('options.enable_ai')) {
      throw new OpenAIServiceException('AI automation is disabled.');
    }

    $api_key = trim((string) $config->get('openai.api_key'));
    if ($api_key === '') {
      throw new OpenAIServiceException('The OpenAI API key is not configured.');
    }

    $selected_model = $this->selectModel($model);
    $payload = $this->buildRequestPayload($prompt, $selected_model, $options);
    $timeout = (int) ($config->get('openai.timeout') ?: 30);

    try {
      $response = $this->httpClient->request('POST', self::RESPONSES_ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => $timeout,
      ]);

      $response_data = json_decode(
        (string) $response->getBody(),
        TRUE,
        512,
        JSON_THROW_ON_ERROR
      );
    }
    catch (GuzzleException $exception) {
      $this->logger->error('OpenAI request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new OpenAIServiceException('The OpenAI request failed.', 0, $exception);
    }
    catch (\JsonException $exception) {
      $this->logger->error('OpenAI returned an invalid JSON response: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new OpenAIServiceException('OpenAI returned an invalid JSON response.', 0, $exception);
    }

    if (!is_array($response_data)) {
      throw new OpenAIServiceException('OpenAI returned an unexpected response.');
    }

    $usage = is_array($response_data['usage'] ?? NULL) ? $response_data['usage'] : [];
    $context = [
      'model' => $selected_model,
      'response_id' => (string) ($response_data['id'] ?? ''),
    ];
    $tokens = $this->registerTokens($usage, $context);
    $cost = $this->registerCost(
      $selected_model,
      $tokens,
      is_array($options['cost_rates'] ?? NULL) ? $options['cost_rates'] : [],
      $context,
    );

    return [
      'id' => $response_data['id'] ?? NULL,
      'model' => $selected_model,
      'text' => $this->extractText($response_data),
      'usage' => $tokens,
      'cost' => $cost,
      'raw' => $response_data,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function selectModel(?string $requested_model = NULL): string {
    $requested_model = trim((string) $requested_model);
    if ($requested_model !== '') {
      return $requested_model;
    }

    $configured_model = trim((string) $this->configFactory
      ->get(self::SETTINGS)
      ->get('openai.default_model'));

    return $configured_model !== '' ? $configured_model : 'gpt-5-mini';
  }

  /**
   * {@inheritdoc}
   */
  public function registerTokens(array $usage, array $context = []): array {
    $input_tokens = $this->readInteger($usage, 'input_tokens')
      ?? $this->readInteger($usage, 'prompt_tokens')
      ?? 0;
    $output_tokens = $this->readInteger($usage, 'output_tokens')
      ?? $this->readInteger($usage, 'completion_tokens')
      ?? 0;
    $total_tokens = $this->readInteger($usage, 'total_tokens')
      ?? ($input_tokens + $output_tokens);

    $input_details = is_array($usage['input_tokens_details'] ?? NULL)
      ? $usage['input_tokens_details']
      : [];
    $output_details = is_array($usage['output_tokens_details'] ?? NULL)
      ? $usage['output_tokens_details']
      : [];

    $tokens = [
      'input_tokens' => $input_tokens,
      'output_tokens' => $output_tokens,
      'total_tokens' => $total_tokens,
      'cached_input_tokens' => $this->readInteger($input_details, 'cached_tokens') ?? 0,
      'reasoning_output_tokens' => $this->readInteger($output_details, 'reasoning_tokens') ?? 0,
    ];

    if ($this->shouldLogMetrics()) {
      $this->logger->info('OpenAI token usage registered for @model: @total total tokens.', [
        '@model' => (string) ($context['model'] ?? 'unknown'),
        '@total' => (string) $tokens['total_tokens'],
      ]);
    }

    return $tokens;
  }

  /**
   * {@inheritdoc}
   */
  public function registerCost(
    string $model,
    array $tokens,
    array $rates_per_million = [],
    array $context = [],
  ): array {
    $input_rate = $this->readFloat($rates_per_million, 'input');
    $output_rate = $this->readFloat($rates_per_million, 'output');
    $estimated_cost = NULL;

    if ($input_rate !== NULL && $output_rate !== NULL) {
      $estimated_cost = round(
        (($tokens['input_tokens'] ?? 0) / 1_000_000 * $input_rate)
        + (($tokens['output_tokens'] ?? 0) / 1_000_000 * $output_rate),
        8
      );
    }

    $cost = [
      'model' => $model,
      'currency' => 'USD',
      'estimated_cost' => $estimated_cost,
      'input_rate_per_million' => $input_rate,
      'output_rate_per_million' => $output_rate,
      'source' => $estimated_cost === NULL ? 'not_configured' : 'provided_rates',
    ];

    if ($this->shouldLogMetrics()) {
      $this->logger->info('OpenAI cost registered for @model: @cost @currency.', [
        '@model' => $model,
        '@cost' => $estimated_cost === NULL ? 'not configured' : (string) $estimated_cost,
        '@currency' => $cost['currency'],
      ]);
    }

    return $cost;
  }

  /**
   * Builds the Responses API request payload.
   *
   * @param array<string, mixed> $options
   *   Optional supported request parameters.
   *
   * @return array<string, mixed>
   *   The request payload.
   */
  private function buildRequestPayload(string $prompt, string $model, array $options): array {
    $payload = [
      'model' => $model,
      'input' => $prompt,
    ];

    foreach (['instructions', 'metadata', 'text', 'reasoning', 'max_output_tokens'] as $key) {
      if (array_key_exists($key, $options)) {
        $payload[$key] = $options[$key];
      }
    }

    return $payload;
  }

  /**
   * Extracts text from a Responses API payload.
   *
   * @param array<string, mixed> $response_data
   *   The decoded response data.
   */
  private function extractText(array $response_data): string {
    if (is_string($response_data['output_text'] ?? NULL)) {
      return $response_data['output_text'];
    }

    $text_parts = [];
    $output_items = is_array($response_data['output'] ?? NULL)
      ? $response_data['output']
      : [];

    foreach ($output_items as $item) {
      if (!is_array($item) || !is_array($item['content'] ?? NULL)) {
        continue;
      }

      foreach ($item['content'] as $content) {
        if (!is_array($content)) {
          continue;
        }

        $type = (string) ($content['type'] ?? '');
        if (($type === 'output_text' || $type === 'text') && is_string($content['text'] ?? NULL)) {
          $text_parts[] = $content['text'];
        }
      }
    }

    return trim(implode("\n", $text_parts));
  }

  /**
   * Reads an integer from an array.
   *
   * @param array<string, mixed> $values
   *   The source values.
   */
  private function readInteger(array $values, string $key): ?int {
    if (!isset($values[$key]) || !is_numeric($values[$key])) {
      return NULL;
    }

    return (int) $values[$key];
  }

  /**
   * Reads a float from an array.
   *
   * @param array<string, mixed> $values
   *   The source values.
   */
  private function readFloat(array $values, string $key): ?float {
    if (!isset($values[$key]) || !is_numeric($values[$key])) {
      return NULL;
    }

    return (float) $values[$key];
  }

  /**
   * Determines whether token and cost metrics should be logged.
   */
  private function shouldLogMetrics(): bool {
    $config = $this->configFactory->get(self::SETTINGS);

    return (bool) $config->get('options.enable_logs')
      && (bool) $config->get('options.enable_metrics');
  }

}
