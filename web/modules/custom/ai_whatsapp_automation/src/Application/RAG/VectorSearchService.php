<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\RAG;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Performs vector similarity search over stored chunks.
 */
final class VectorSearchService {

  /**
   * Constructs a VectorSearchService object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Searches chunks by cosine similarity.
   *
   * @param float[] $query_embedding
   *   Query embedding.
   *
   * @return array<int, array<string, mixed>>
   *   Ranked results.
   */
  public function search(int|string $knowledge_base_id, array $query_embedding, int $limit = 5): array {
    $ids = $this->entityTypeManager
      ->getStorage('ai_whatsapp_knowledge_chunk')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('knowledge_base', $knowledge_base_id)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $chunks = $this->entityTypeManager
      ->getStorage('ai_whatsapp_knowledge_chunk')
      ->loadMultiple($ids);

    $results = [];
    foreach ($chunks as $chunk) {
      if (!$chunk instanceof ContentEntityInterface) {
        continue;
      }

      $embedding = json_decode((string) $chunk->get('embedding')->value, TRUE);
      if (!is_array($embedding)) {
        continue;
      }

      $results[] = [
        'chunk' => $chunk,
        'score' => $this->cosineSimilarity($query_embedding, array_map('floatval', $embedding)),
        'content' => (string) $chunk->get('content')->value,
      ];
    }

    usort($results, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    return array_slice($results, 0, $limit);
  }

  /**
   * Calculates cosine similarity.
   *
   * @param float[] $a
   *   First vector.
   * @param float[] $b
   *   Second vector.
   */
  private function cosineSimilarity(array $a, array $b): float {
    $count = min(count($a), count($b));
    if ($count === 0) {
      return 0.0;
    }

    $dot = 0.0;
    $norm_a = 0.0;
    $norm_b = 0.0;
    for ($i = 0; $i < $count; $i++) {
      $dot += $a[$i] * $b[$i];
      $norm_a += $a[$i] ** 2;
      $norm_b += $b[$i] ** 2;
    }

    if ($norm_a == 0.0 || $norm_b == 0.0) {
      return 0.0;
    }

    return $dot / (sqrt($norm_a) * sqrt($norm_b));
  }

}
