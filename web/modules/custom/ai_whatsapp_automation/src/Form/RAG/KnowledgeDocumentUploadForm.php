<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form\RAG;

use Drupal\ai_whatsapp_automation\Application\RAG\KnowledgeBaseService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ai_whatsapp_automation.knowledge_base'),
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
      '#title' => $this->t('Knowledge base'),
      '#target_type' => 'ai_whatsapp_knowledge_base',
      '#required' => TRUE,
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
    $knowledge_base = $this->entityTypeManager
      ->getStorage('ai_whatsapp_knowledge_base')
      ->load($form_state->getValue('knowledge_base'));

    if (!$file instanceof FileInterface || !$knowledge_base) {
      $this->messenger()->addError($this->t('The document could not be indexed.'));
      return;
    }

    $file->setPermanent();
    $file->save();

    try {
      $document = $this->knowledgeBaseService->indexFile($knowledge_base, $file, (string) $form_state->getValue('title'));
      $this->messenger()->addStatus($this->t('Indexed @chunks chunks.', [
        '@chunks' => (string) $document->get('chunk_count')->value,
      ]));
      $form_state->setRedirect('entity.ai_whatsapp_knowledge_document.collection');
    }
    catch (\Throwable $exception) {
      $this->messenger()->addError($this->t('Indexing failed: @message', [
        '@message' => $exception->getMessage(),
      ]));
    }
  }

}
