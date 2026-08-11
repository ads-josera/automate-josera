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

}

