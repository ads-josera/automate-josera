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
    $entity_type_ids = ['ai_whatsapp_client', 'ai_whatsapp_knowledge_base', 'ai_whatsapp_bot', 'ai_whatsapp_account'];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $a = $this->create('ai_whatsapp_client', ['name' => 'JG Mylard']);
    $b = $this->create('ai_whatsapp_client', ['name' => 'Laboratorio JVC']);
    $kb_a = $this->create('ai_whatsapp_knowledge_base', [
      'name' => 'Brochure JG',
      'status' => 'active',
      'client' => $a->id(),
    ]);
    $kb_b = $this->create('ai_whatsapp_knowledge_base', [
      'name' => 'Catálogo JVC',
      'status' => 'active',
      'client' => $b->id(),
    ]);
    $kb_none = $this->create('ai_whatsapp_knowledge_base', ['name' => 'Sin cliente', 'status' => 'active']);
    $bot_a = $this->create('ai_whatsapp_bot', ['name' => 'Bot JG', 'status' => 'active', 'client' => $a->id()]);
    $bot_b = $this->create('ai_whatsapp_bot', ['name' => 'Bot JVC', 'status' => 'active', 'client' => $b->id()]);
    $account_b = $this->create('ai_whatsapp_account', [
      'name' => 'WhatsApp JVC',
      'provider' => 'twilio',
      'status' => 'active',
      'bot' => $bot_b->id(),
    ]);
    $checker = $this->container->get('ai_whatsapp_automation.client_consistency');
    $conflicts = function (string $entity_type_id, array $values) use ($checker): array {
      $entity = $this->container->get('entity_type.manager')->getStorage($entity_type_id)->create($values);
      return $checker->conflicts($entity);
    };
    $a_id = $a->id();

    // Bots.
    $this->assertSame([], $conflicts('ai_whatsapp_bot', ['client' => $a_id, 'knowledge_base' => $kb_a->id()]));
    $shared = $conflicts('ai_whatsapp_bot', ['client' => $a_id, 'knowledge_base' => $kb_none->id()]);
    $this->assertSame([], $shared, 'A shared, client-less base is allowed');
    $wrong_kb = $conflicts('ai_whatsapp_bot', ['client' => $a_id, 'knowledge_base' => $kb_b->id()]);
    $this->assertSame(['knowledge_base'], array_keys($wrong_kb));
    $this->assertStringContainsString('Laboratorio JVC', (string) $wrong_kb['knowledge_base']);
    $wrong_account = $conflicts('ai_whatsapp_bot', ['client' => $a_id, 'lead_notification_account' => $account_b->id()]);
    $this->assertSame(['lead_notification_account'], array_keys($wrong_account));

    // Accounts: their client is their own, or their bot's when empty.
    $this->assertSame([], $conflicts('ai_whatsapp_account', ['bot' => $bot_a->id(), 'knowledge_base' => $kb_a->id()]));
    $wrong_bot = $conflicts('ai_whatsapp_account', ['client' => $a_id, 'bot' => $bot_b->id()]);
    $this->assertSame(['bot'], array_keys($wrong_bot));
    $inherited = $conflicts('ai_whatsapp_account', ['bot' => $bot_a->id(), 'knowledge_base' => $kb_b->id()]);
    $this->assertSame(['knowledge_base'], array_keys($inherited), 'Inherited client is enforced too');
  }

  /**
   * Creates and saves an entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param array<string, mixed> $values
   *   Field values.
   */
  private function create(string $entity_type_id, array $values): ContentEntityInterface {
    $entity = $this->container->get('entity_type.manager')->getStorage($entity_type_id)->create($values);
    $entity->save();

    return $entity;
  }

}
