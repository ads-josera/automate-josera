<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form\RAG;

use Drupal\ai_whatsapp_automation\Application\RAG\KnowledgeBaseService;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uploads and indexes knowledge documents.
 */
final class KnowledgeDocumentUploadForm extends FormBase {

  /**
   * Constructs a KnowledgeDocumentUploadForm object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KnowledgeBaseService $knowledgeBaseService,
    private readonly QueueFactory $queueFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ai_whatsapp_automation.knowledge_base'),
      $container->get('queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_knowledge_document_upload_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['knowledge_base'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Existing knowledge base'),
      '#target_type' => 'ai_whatsapp_knowledge_base',
      '#description' => $this->t('Select an existing knowledge base or leave empty to create one from the title.'),
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['document'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Document'),
      '#upload_location' => 'public://ai-whatsapp-knowledge',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'txt docx pdf'],
      ],
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload and index'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $file_ids = $form_state->getValue('document');
    $file_id = is_array($file_ids) ? reset($file_ids) : NULL;
    $file = $file_id ? $this->entityTypeManager->getStorage('file')->load($file_id) : NULL;
    $knowledge_base = $this->resolveKnowledgeBase((string) $form_state->getValue('title'), $form_state->getValue('knowledge_base'));

    if (!$file instanceof FileInterface || !$knowledge_base) {
      $this->messenger()->addError($this->t('The document could not be indexed.'));
      return;
    }

    $file->setPermanent();
    $file->save();

    try {
      $document = $this->knowledgeBaseService->createDocument($knowledge_base, $file, (string) $form_state->getValue('title'));
      $this->queueFactory
        ->get('ai_whatsapp_automation_knowledge_index')
        ->createItem([
          'document_id' => $document->id(),
          'attempts' => 0,
          'created' => time(),
        ]);

      $this->messenger()->addStatus($this->t('Document uploaded and queued for indexing. Document ID: @id.', [
        '@id' => (string) $document->id(),
      ]));
      $form_state->setRedirect('entity.ai_whatsapp_knowledge_document.collection');
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Indexing failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }
  }

  /**
   * Loads the selected knowledge base or creates one from the document title.
   */
  private function resolveKnowledgeBase(string $title, mixed $knowledge_base_id): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_knowledge_base');
    if ($knowledge_base_id) {
      $knowledge_base = $storage->load($knowledge_base_id);

      return $knowledge_base instanceof ContentEntityInterface ? $knowledge_base : NULL;
    }

    $knowledge_base = $storage->create([
      'name' => $title,
      'description' => (string) $this->t('Created while uploading @title.', ['@title' => $title]),
      'embedding_model' => 'text-embedding-3-small',
      'status' => 'active',
    ]);
    $knowledge_base->save();

    return $knowledge_base;
  }

}
