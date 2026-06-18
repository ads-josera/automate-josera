<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\RAG;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Creates embeddings through the configured embedding provider.
 */
final class EmbeddingService {

  private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs an EmbeddingService object.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Creates a single embedding vector.
   *
   * @return float[]
   *   Embedding vector.
   */
  public function embed(string $text, string $model = 'text-embedding-3-small'): array {
    $text = trim($text);
    if ($text === '') {
      throw new \InvalidArgumentException('Cannot embed empty text.');
    }

    $api_key = trim((string) $this->configFactory
      ->get('ai_whatsapp_automation.settings')
      ->get('openai.api_key'));
    if ($api_key === '') {
      throw new \RuntimeException('The OpenAI API key is not configured.');
    }

    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'input' => $text,
          'model' => $model,
          'encoding_format' => 'float',
        ],
        'timeout' => 60,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (GuzzleException | \JsonException $exception) {
      $this->logger->error('Embedding request failed: @message', ['@message' => $exception->getMessage()]);
      throw new \RuntimeException('Embedding request failed.', 0, $exception);
    }

    $embedding = $data['data'][0]['embedding'] ?? NULL;
    if (!is_array($embedding)) {
      throw new \RuntimeException('Embedding response did not include a vector.');
    }

    return array_map('floatval', $embedding);
  }

}
