<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Ui;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Renders every list of the panel.
 *
 * A list that throws only shows up as a white screen in the browser, and the
 * access tests load rows without ever rendering a header. This renders each
 * one, with data, the way an administrator sees it.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ListRenderTest extends KernelTestBase {

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
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    foreach ($this->entityTypeIds() as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installEntitySchema('ai_whatsapp_knowledge_chunk');
    $this->installConfig(['system', 'ai_whatsapp_automation']);
    $this->container->get('router.builder')->rebuild();

    $this->createUser();
    $this->setCurrentUser($this->createUser(['administer ai whatsapp automation entities']));

    $client = $this->create('ai_whatsapp_client', ['name' => 'JG Mylard']);
    $bot = $this->create('ai_whatsapp_bot', ['name' => 'Bot', 'status' => 'active', 'client' => $client->id()]);
    $account = $this->create('ai_whatsapp_account', [
      'name' => 'Cuenta',
      'provider' => 'twilio',
      'status' => 'active',
      'bot' => $bot->id(),
    ]);
    // A web chat conversation: its name is the "Web visitor" placeholder.
    $web = $this->create('ai_whatsapp_conversation', [
      'name' => 'Web visitor',
      'channel' => 'web',
      'provider' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
      'client' => $client->id(),
    ]);
    $conversation = $this->create('ai_whatsapp_conversation', [
      'phone' => '+525500000001',
      'name' => 'Ana',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
      'whatsapp_account' => $account->id(),
      'bot' => $bot->id(),
      'client' => $client->id(),
    ]);
    $this->create('ai_whatsapp_message', [
      'conversation' => $conversation->id(),
      'sender' => 'contact',
      'content' => 'Hola',
    ]);
    $this->create('ai_whatsapp_lead', [
      'name' => 'Ana',
      'status' => 'new',
      'conversation' => $conversation->id(),
    ]);
    $this->create('ai_whatsapp_operator_action', [
      'conversation' => $web->id(),
      'action' => 'stop_ai',
      'note' => 'Prueba',
    ]);
    $this->create('ai_whatsapp_knowledge_base', ['name' => 'Base', 'client' => $client->id()]);
  }

  /**
   * Every list renders, in Spanish, with its operations column.
   */
  public function testEveryListRenders(): void {
    $manager = $this->container->get('entity_type.manager');
    $renderer = $this->container->get('renderer');
    foreach ($this->entityTypeIds() as $entity_type_id) {
      $build = $manager->getListBuilder($entity_type_id)->render();
      $rendered = (string) $renderer->renderInIsolation($build);

      $this->assertNotSame('', trim($rendered), "$entity_type_id renders");
      $this->assertStringNotContainsString('Operations', $rendered, "$entity_type_id has no English operations column");
    }
  }

  /**
   * The stored "Web visitor" placeholder never reaches the screen.
   */
  public function testTheWebVisitorPlaceholderIsTranslated(): void {
    $renderer = $this->container->get('renderer');
    foreach (['ai_whatsapp_conversation', 'ai_whatsapp_operator_action'] as $entity_type_id) {
      $build = $this->container->get('entity_type.manager')->getListBuilder($entity_type_id)->render();
      $rendered = (string) $renderer->renderInIsolation($build);

      $this->assertStringContainsString('Visitante web', $rendered, "$entity_type_id shows the Spanish label");
      $this->assertStringNotContainsString('Web visitor', $rendered, "$entity_type_id hides the stored placeholder");
    }
  }

  /**
   * Entity types with a list in the panel.
   *
   * @return string[]
   *   Entity type IDs.
   */
  private function entityTypeIds(): array {
    return [
      'ai_whatsapp_client',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_knowledge_document',
    ];
  }

  /**
   * Creates and saves an entity.
   *
   * @param array<string, mixed> $values
   *   Field values.
   */
  private function create(string $entity_type_id, array $values): ContentEntityInterface {
    $entity = $this->container->get('entity_type.manager')->getStorage($entity_type_id)->create($values);
    $entity->save();

    return $entity;
  }

}
