<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Client;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that records inherit their client when saved.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ClientAssignmentTest extends KernelTestBase {

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
    $entity_type_ids = [
      'ai_whatsapp_client',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_lead',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
  }

  /**
   * Conversations, leads and accounts inherit the client of their bot.
   */
  public function testRecordsInheritClient(): void {
    $client_a = $this->create('ai_whatsapp_client', ['name' => 'JG Mylard']);
    $client_b = $this->create('ai_whatsapp_client', ['name' => 'Laboratorio JVC']);
    $bot = $this->create('ai_whatsapp_bot', ['name' => 'JG bot', 'status' => 'active', 'client' => $client_a->id()]);

    $account = $this->create('ai_whatsapp_account', ['name' => 'JG WhatsApp', 'provider' => 'twilio', 'status' => 'active', 'bot' => $bot->id()]);
    $this->assertClient($client_a, $account, 'An account without client inherits it from its bot');

    $web = $this->create('ai_whatsapp_conversation', ['phone' => 'web:1:s', 'channel' => 'web', 'provider' => 'web', 'status' => 'AI_ACTIVE', 'bot' => $bot->id()]);
    $this->assertClient($client_a, $web, 'A web conversation inherits from its bot');

    $whatsapp = $this->create('ai_whatsapp_conversation', ['phone' => '+525512309140', 'channel' => 'whatsapp', 'provider' => 'twilio', 'status' => 'AI_ACTIVE', 'whatsapp_account' => $account->id()]);
    $this->assertClient($client_a, $whatsapp, 'A WhatsApp conversation with only an account inherits from the account');

    $lead = $this->create('ai_whatsapp_lead', ['name' => 'Mariana', 'status' => 'qualified', 'conversation' => $whatsapp->id()]);
    $this->assertClient($client_a, $lead, 'A lead inherits from its conversation');

    $explicit = $this->create('ai_whatsapp_conversation', ['phone' => '+525500000000', 'channel' => 'whatsapp', 'provider' => 'twilio', 'status' => 'AI_ACTIVE', 'bot' => $bot->id(), 'client' => $client_b->id()]);
    $this->assertClient($client_b, $explicit, 'An explicit client is never overwritten');

    // History keeps its owner when the bot later moves to another client.
    $bot->set('client', $client_b->id())->save();
    $web->save();
    $this->assertClient($client_a, $web, 'Existing conversations keep their client after the bot moves');
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

  /**
   * Asserts the stored client of an entity, reloading it from storage.
   */
  private function assertClient(ContentEntityInterface $expected, ContentEntityInterface $entity, string $message): void {
    $storage = $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId());
    $storage->resetCache([$entity->id()]);
    $this->assertSame((string) $expected->id(), (string) $storage->load($entity->id())->get('client')->target_id, $message);
  }

}
