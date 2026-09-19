<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\RAG;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * Keeps knowledge documents in private file storage.
 *
 * Client documents (price lists, internal procedures) were stored in
 * public:// and could be downloaded by anyone with the URL. They now go to
 * private:// and only knowledge administrators can download them (see
 * ai_whatsapp_automation_file_download()). Widget logos stay public.
 *
 * Private storage needs $settings['file_private_path'] in settings.php. Until
 * it is configured, uploads fall back to public:// so the site keeps working,
 * and the status report flags it.
 */
final class KnowledgeFileStorage {

  /**
   * Directory inside the file scheme.
   */
  public const DIRECTORY = 'ai-whatsapp-knowledge';

  /**
   * Constructs a KnowledgeFileStorage object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
  ) {
  }

  /**
   * Whether private file storage is configured.
   */
  public function isPrivateAvailable(): bool {
    return $this->streamWrapperManager->isValidScheme('private');
  }

  /**
   * Returns where new knowledge documents are uploaded.
   */
  public function uploadLocation(): string {
    return ($this->isPrivateAvailable() ? 'private' : 'public') . '://' . self::DIRECTORY;
  }

  /**
   * Counts knowledge documents whose file is still public.
   */
  public function countPublicDocuments(): int {
    return count($this->publicDocumentFiles());
  }

  /**
   * Moves the files of knowledge documents from public to private storage.
   *
   * Only files referenced by knowledge documents are moved. Safe to run more
   * than once.
   *
   * @return array{moved: int, failed: string[]}
   *   Number of moved files and the names of those that could not be moved.
   */
  public function moveDocumentsToPrivate(): array {
    $result = ['moved' => 0, 'failed' => []];
    if (!$this->isPrivateAvailable()) {
      return $result;
    }

    $destination = 'private://' . self::DIRECTORY;
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    foreach ($this->publicDocumentFiles() as $file) {
      try {
        $this->fileRepository->move($file, $destination . '/' . $file->getFilename(), FileExists::Rename);
        $result['moved']++;
      }
      catch (\Throwable) {
        $result['failed'][] = $file->getFilename();
      }
    }

    return $result;
  }

  /**
   * Returns public files referenced by knowledge documents.
   *
   * @return \Drupal\file\FileInterface[]
   *   File entities keyed by ID.
   */
  private function publicDocumentFiles(): array {
    $document_storage = $this->entityTypeManager->getStorage('ai_whatsapp_knowledge_document');
    $files = [];
    foreach ($document_storage->loadMultiple() as $document) {
      $file = $document->get('file')->entity;
      if ($file instanceof FileInterface && str_starts_with($file->getFileUri(), 'public://')) {
        $files[$file->id()] = $file;
      }
    }

    return $files;
  }

}
