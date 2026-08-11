<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\ai_whatsapp_automation\Application\OpenAI\OpenAIServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Reserved adapter for structured AdminOps AI analysis.
 *
 * This adapter keeps AdminOps decoupled from the WhatsApp module internals.
 */
final class AdminOpsAiClient {

  /**
   * Creates an AdminOpsAiClient instance.
   */
  public function __construct(
    private readonly OpenAIServiceInterface $openAi,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Delegates a structured AdminOps analysis request to the shared AI service.
   *
   * This method deliberately does not execute tools or commands. Tool selection
   * and execution remain separate, explicitly approved phases of AdminOps.
   *
   * @param array<string, mixed> $options
   *   Supported OpenAI request options.
   *
   * @return array<string, mixed>
   *   The normalized response from the shared public service.
   */
  public function analyze(string $prompt, ?string $model = NULL, array $options = []): array {
    try {
      return $this->openAi->sendPrompt($prompt, $model, $options);
    }
    catch (\Throwable $exception) {
      $this->logger->error('AdminOps AI analysis failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw $exception;
    }
  }

}
