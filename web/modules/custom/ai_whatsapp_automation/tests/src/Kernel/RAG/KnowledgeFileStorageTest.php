<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\RAG;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that knowledge documents are private and admin-only downloads.
 *
 * Before, documents were stored in public:// and anyone with the URL could
 * download a client's files.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class KnowledgeFileStorageTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'options',
    'text',
    'views',
    'ai_whatsapp_automation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // Production configures this in settings.php.
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    foreach (['ai_whatsapp_client', 'ai_whatsapp_knowledge_base', 'ai_whatsapp_knowledge_document'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
  }

  /**
   * Existing public documents move to private storage; downloads need admin.
   */
  public function testDocumentsMoveToPrivateAndDownloadsAreRestricted(): void {
    $storage = $this->container->get('ai_whatsapp_automation.knowledge_file_storage');
    $this->assertTrue($storage->isPrivateAvailable(), 'Kernel tests provide a private file path');
    $this->assertSame('private://ai-whatsapp-knowledge', $storage->uploadLocation());

    // A document uploaded before this change, in public://.
    $file_system = $this->container->get('file_system');
    $directory = 'public://ai-whatsapp-knowledge';
    $file_system->prepareDirectory($directory, 1);
    file_put_contents($directory . '/Brochure JG.pdf', 'brochure');
    $file = $this->container->get('entity_type.manager')->getStorage('file')->create([
      'uri' => $directory . '/Brochure JG.pdf',
      'filename' => 'Brochure JG.pdf',
      'status' => 1,
    ]);
    $file->save();
    // A widget logo must stay public: visitors see it.
    file_put_contents('public://logo.png', 'logo');
    $entity_type_manager = $this->container->get('entity_type.manager');
    $logo = $entity_type_manager->getStorage('file')->create(['uri' => 'public://logo.png', 'status' => 1]);
    $logo->save();
    $kb = $entity_type_manager->getStorage('ai_whatsapp_knowledge_base')->create(['name' => 'KB', 'status' => 'active']);
    $kb->save();
    $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_knowledge_document')->create([
      'title' => 'Brochure',
      'status' => 'indexed',
      'knowledge_base' => $kb->id(),
      'file' => $file->id(),
    ])->save();

    $this->assertSame(1, $storage->countPublicDocuments());
    $result = $storage->moveDocumentsToPrivate();
    $this->assertSame(1, $result['moved']);
    $this->assertSame([], $result['failed']);
    $this->assertSame(0, $storage->countPublicDocuments());

    $moved = $this->container->get('entity_type.manager')->getStorage('file')->loadUnchanged($file->id());
    $this->assertStringStartsWith('private://ai-whatsapp-knowledge/', $moved->getFileUri());
    $this->assertFileExists($moved->getFileUri());
    $this->assertFileDoesNotExist($directory . '/Brochure JG.pdf', 'The public copy is gone');
    $this->assertSame('public://logo.png', $this->container->get('entity_type.manager')->getStorage('file')->loadUnchanged($logo->id())->getFileUri());

    // Downloads: denied to users without the knowledge permission.
    $module_handler = $this->container->get('module_handler');
    $this->createUser();
    $this->setCurrentUser($this->createUser([]));
    $this->assertContains(-1, $module_handler->invokeAll('file_download', [$moved->getFileUri()]));
    $this->setCurrentUser($this->createUser(['administer ai whatsapp automation rag']));
    $headers = $module_handler->invokeAll('file_download', [$moved->getFileUri()]);
    $this->assertNotContains(-1, $headers);
    $this->assertArrayHasKey('Content-Disposition', $headers);
  }

}
