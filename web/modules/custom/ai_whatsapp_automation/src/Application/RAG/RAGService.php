<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\RAG;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Retrieves knowledge context for prompt injection.
 */
final class RAGService {

  /**
   * Constructs a RAGService object.
   */
  public function __construct(
    private readonly EmbeddingService $embeddingService,
    private readonly VectorSearchService $vectorSearch,
  ) {
  }

  /**
   * Builds context snippets for a query.
   *
   * @return array<string, mixed>
   *   Context data.
   */
  public function buildContext(ContentEntityInterface $knowledge_base, string $query, int $limit = 5): array {
    $model = (string) $knowledge_base->get('embedding_model')->value ?: 'text-embedding-3-small';
    $embedding = $this->embeddingService->embed($query, $model);
    $results = $this->vectorSearch->search((string) $knowledge_base->id(), $embedding, $limit);
    $snippets = array_map(static fn(array $result): string => (string) $result['content'], $results);

    return [
      'knowledge_base_id' => $knowledge_base->id(),
      'query' => $query,
      'context' => implode("\n\n", $snippets),
      'results' => $results,
    ];
  }

}
