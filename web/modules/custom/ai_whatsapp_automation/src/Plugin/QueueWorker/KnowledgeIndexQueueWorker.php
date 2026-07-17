<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Plugin\QueueWorker;

use Drupal\ai_whatsapp_automation\Application\RAG\KnowledgeBaseService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Indexes uploaded knowledge documents asynchronously.
 */
#[QueueWorker(
  id: 'ai_whatsapp_automation_knowledge_index',
  title: new TranslatableMarkup('AI WhatsApp knowledge document indexer'),
  cron: ['time' => 45]
)]
final class KnowledgeIndexQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum processing attempts before allowing the item to fail.
   */
  private const MAX_ATTEMPTS = 3;

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a KnowledgeIndexQueueWorker object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KnowledgeBaseService $knowledgeBaseService,
    private readonly QueueFactory $queueFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('ai_whatsapp_automation.knowledge_base'),
      $container->get('queue'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $item = is_array($data) ? $data : [];
    $document_id = $item['document_id'] ?? NULL;
    $document = $document_id
      ? $this->entityTypeManager->getStorage('ai_whatsapp_knowledge_document')->load($document_id)
      : NULL;

    if (!$document instanceof ContentEntityInterface) {
      $this->logger->warning('Knowledge index queue item skipped because document @document does not exist.', [
        '@document' => (string) $document_id,
      ]);
      return;
    }

    try {
      $this->knowledgeBaseService->indexDocument($document);
      $this->logger->info('Knowledge document @document indexed with @chunks chunks.', [
        '@document' => (string) $document->id(),
        '@chunks' => (string) $document->get('chunk_count')->value,
      ]);
    }
    catch (\Throwable $exception) {
      $attempts = (int) ($item['attempts'] ?? 0);
      if ($attempts + 1 < self::MAX_ATTEMPTS) {
        $item['attempts'] = $attempts + 1;
        $item['last_error'] = $exception->getMessage();
        $this->queueFactory->get('ai_whatsapp_automation_knowledge_index')->createItem($item);
        $this->logger->warning('Knowledge document @document requeued after indexing failure: @message', [
          '@document' => (string) $document->id(),
          '@message' => $exception->getMessage(),
        ]);
        return;
      }

      $this->logger->error('Knowledge document @document failed after @attempts attempts: @message', [
        '@document' => (string) $document->id(),
        '@attempts' => (string) self::MAX_ATTEMPTS,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

}
