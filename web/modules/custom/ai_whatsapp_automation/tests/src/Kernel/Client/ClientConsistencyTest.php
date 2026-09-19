<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Client;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that bots and accounts cannot reference another client's records.
 *
 * A bot of client A using client B's knowledge base would answer A's
 * customers with B's information.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ClientConsistencyTest extends KernelTestBase {

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
   * Checks bot and account references against their client.
   */
  public function testCrossClientReferencesAreConflicts(): void {
    foreach (['ai_whatsapp_client', 'ai_whatsapp_knowledge_base', 'ai_whatsapp_bot', 'ai_whatsapp_account'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $a = $this->create('ai_whatsapp_client', ['name' => 'JG Mylard']);
    $b = $this->create('ai_whatsapp_client', ['name' => 'Laboratorio JVC']);
    $kb_a = $this->create('ai_whatsapp_knowledge_base', ['name' => 'Brochure JG', 'status' => 'active', 'client' => $a->id()]);
    $kb_b = $this->create('ai_whatsapp_knowledge_base', ['name' => 'Catálogo JVC', 'status' => 'active', 'client' => $b->id()]);
    $kb_none = $this->create('ai_whatsapp_knowledge_base', ['name' => 'Sin cliente', 'status' => 'active']);
    $bot_a = $this->create('ai_whatsapp_bot', ['name' => 'Bot JG', 'status' => 'active', 'client' => $a->id()]);
    $bot_b = $this->create('ai_whatsapp_bot', ['name' => 'Bot JVC', 'status' => 'active', 'client' => $b->id()]);
    $account_b = $this->create('ai_whatsapp_account', ['name' => 'WhatsApp JVC', 'provider' => 'twilio', 'status' => 'active', 'bot' => $bot_b->id()]);
    $checker = $this->container->get('ai_whatsapp_automation.client_consistency');
    $storage = fn (string $type) => $this->container->get('entity_type.manager')->getStorage($type);

    // Bots.
    $this->assertSame([], $checker->conflicts($storage('ai_whatsapp_bot')->create(['client' => $a->id(), 'knowledge_base' => $kb_a->id()])));
    $this->assertSame([], $checker->conflicts($storage('ai_whatsapp_bot')->create(['client' => $a->id(), 'knowledge_base' => $kb_none->id()])), 'A shared, client-less base is allowed');
    $conflicts = $checker->conflicts($storage('ai_whatsapp_bot')->create(['client' => $a->id(), 'knowledge_base' => $kb_b->id()]));
    $this->assertSame(['knowledge_base'], array_keys($conflicts));
    $this->assertStringContainsString('Laboratorio JVC', (string) $conflicts['knowledge_base']);
    $this->assertSame(['lead_notification_account'], array_keys($checker->conflicts($storage('ai_whatsapp_bot')->create(['client' => $a->id(), 'lead_notification_account' => $account_b->id()]))));

    // Accounts: their client is their own, or their bot's when empty.
    $this->assertSame([], $checker->conflicts($storage('ai_whatsapp_account')->create(['bot' => $bot_a->id(), 'knowledge_base' => $kb_a->id()])));
    $this->assertSame(['bot'], array_keys($checker->conflicts($storage('ai_whatsapp_account')->create(['client' => $a->id(), 'bot' => $bot_b->id()]))));
    $this->assertSame(['knowledge_base'], array_keys($checker->conflicts($storage('ai_whatsapp_account')->create(['bot' => $bot_a->id(), 'knowledge_base' => $kb_b->id()]))), 'Inherited client is enforced too');
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
