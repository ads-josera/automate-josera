<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\RAG;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages knowledge document indexing.
 */
final class KnowledgeBaseService {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a KnowledgeBaseService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DocumentParserService $documentParser,
    private readonly EmbeddingService $embeddingService,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Indexes an uploaded file into a knowledge base.
   */
  public function indexFile(ContentEntityInterface $knowledge_base, FileInterface $file, string $title): ContentEntityInterface {
    $document_storage = $this->entityTypeManager->getStorage('ai_whatsapp_knowledge_document');
    $document = $document_storage->create([
      'knowledge_base' => $knowledge_base->id(),
      'title' => $title,
      'file' => $file->id(),
      'mime_type' => $file->getMimeType(),
      'status' => 'indexed',
      'chunk_count' => 0,
    ]);
    $document->save();

    try {
      $text = $this->documentParser->parse($file);
      $chunks = $this->chunkText($text);
      $model = (string) $knowledge_base->get('embedding_model')->value ?: 'text-embedding-3-small';

      foreach ($chunks as $index => $chunk_text) {
        $embedding = $this->embeddingService->embed($chunk_text, $model);
        $this->entityTypeManager->getStorage('ai_whatsapp_knowledge_chunk')->create([
          'knowledge_base' => $knowledge_base->id(),
          'document' => $document->id(),
          'title' => $title . ' #' . ($index + 1),
          'chunk_index' => $index,
          'content' => $chunk_text,
          'embedding' => json_encode($embedding, JSON_THROW_ON_ERROR),
          'embedding_model' => $model,
        ])->save();
      }

      $document->set('chunk_count', count($chunks));
      $document->save();
    }
    catch (\Throwable $exception) {
      $document->set('status', 'failed');
      $document->save();
      $this->logger->error('Knowledge document indexing failed: @message', ['@message' => $exception->getMessage()]);
      throw $exception;
    }

    return $document;
  }

  /**
   * Splits text into overlapping chunks.
   *
   * @return string[]
   *   Chunks.
   */
  public function chunkText(string $text, int $chunk_size = 1200, int $overlap = 150): array {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($text === '') {
      return [];
    }

    $chunks = [];
    $length = mb_strlen($text);
    $offset = 0;
    while ($offset < $length) {
      $chunks[] = mb_substr($text, $offset, $chunk_size);
      $offset += max(1, $chunk_size - $overlap);
    }

    return array_values(array_filter(array_map('trim', $chunks)));
  }

}
