<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

use Drupal\ai_whatsapp_automation\Application\RAG\RAGService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds prompts for conversation automation.
 */
final class PromptBuilderService {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a PromptBuilderService object.
   */
  public function __construct(
    private readonly BotManagerService $botManager,
    private readonly RAGService $ragService,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Builds the OpenAI request data for a conversation message.
   *
   * @return array<string, mixed>
   *   Prompt data with prompt, model, and OpenAI options.
   */
  public function build(
    ContentEntityInterface $bot,
    ContentEntityInterface $conversation,
    string $incoming_message,
  ): array {
    $account = $this->botManager->getAccountForConversation($conversation);
    $knowledge_base = $this->botManager->getEffectiveKnowledgeBase($bot, $account);
    $knowledge_context = '';
    $metadata = [
      'conversation_id' => (string) $conversation->id(),
      'bot_id' => (string) $bot->id(),
      'account_id' => $account instanceof ContentEntityInterface ? (string) $account->id() : '',
    ];

    if ($knowledge_base instanceof ContentEntityInterface) {
      try {
        $rag_context = $this->ragService->buildContext($knowledge_base, $incoming_message);
        $knowledge_context = trim((string) ($rag_context['context'] ?? ''));
        $metadata['knowledge_base_id'] = (string) $knowledge_base->id();
        $metadata['rag_results'] = (string) count(is_array($rag_context['results'] ?? NULL) ? $rag_context['results'] : []);
      }
      catch (\Throwable $exception) {
        $this->logger->warning('RAG context generation failed for conversation @conversation: @message', [
          '@conversation' => (string) $conversation->id(),
          '@message' => $exception->getMessage(),
        ]);
        $metadata['rag_error'] = $exception->getMessage();
      }
    }

    $context_lines = [
      'Conversation context:',
      'Phone: ' . $this->getFieldValue($conversation, 'phone'),
      'Name: ' . $this->getFieldValue($conversation, 'name'),
      'Provider: ' . $this->getFieldValue($conversation, 'provider'),
      'Status: ' . $this->getFieldValue($conversation, 'status'),
    ];

    if ($account instanceof ContentEntityInterface) {
      $context_lines[] = 'WhatsApp account: ' . (string) $account->label();
    }

    if ($knowledge_context !== '') {
      $context_lines[] = '';
      $context_lines[] = 'Knowledge base context:';
      $context_lines[] = $knowledge_context;
    }

    $context_lines = array_merge($context_lines, [
      '',
      'Incoming WhatsApp message:',
      trim($incoming_message),
    ]);

    return [
      'prompt' => implode("\n", $context_lines),
      'model' => $this->botManager->getEffectiveModel($bot, $account),
      'options' => [
        'instructions' => $this->botManager->getEffectivePrompt($bot, $account),
        'metadata' => $this->stringifyMetadata($metadata),
      ],
    ];
  }

  /**
   * Ensures OpenAI metadata values are strings.
   *
   * @param array<string, mixed> $metadata
   *   Metadata values.
   *
   * @return array<string, string>
   *   String metadata values.
   */
  private function stringifyMetadata(array $metadata): array {
    $string_metadata = [];
    foreach ($metadata as $key => $value) {
      if (is_scalar($value) || $value === NULL) {
        $string_metadata[$key] = (string) $value;
        continue;
      }

      $string_metadata[$key] = json_encode($value, JSON_THROW_ON_ERROR);
    }

    return $string_metadata;
  }

  /**
   * Reads a scalar field value from an entity.
   */
  private function getFieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

}
