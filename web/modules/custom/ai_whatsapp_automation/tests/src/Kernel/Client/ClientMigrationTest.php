<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Client;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests update 11032, which creates one client per bot and assigns records.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ClientMigrationTest extends KernelTestBase {

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
    $this->container->get('module_handler')->loadInclude('ai_whatsapp_automation', 'install');
  }

  /**
   * Replays the production shape: two bots, their accounts and history.
   */
  public function testUpdateAssignsClientsFromBots(): void {
    $kb_jg = $this->create('ai_whatsapp_knowledge_base', ['name' => 'Brochure JG Mylard', 'status' => 'active']);
    $kb_shared = $this->create('ai_whatsapp_knowledge_base', ['name' => 'Shared', 'status' => 'active']);
    $kb_orphan = $this->create('ai_whatsapp_knowledge_base', ['name' => 'Unused', 'status' => 'active']);
    $jg = $this->create('ai_whatsapp_bot', ['name' => 'JG Mylard', 'status' => 'active', 'knowledge_base' => $kb_jg->id()]);
    $lab = $this->create('ai_whatsapp_bot', ['name' => 'Laboratorio JVC', 'status' => 'active', 'knowledge_base' => $kb_shared->id()]);
    $account_jg = $this->create('ai_whatsapp_account', ['name' => 'JG WhatsApp', 'provider' => 'twilio', 'status' => 'active', 'bot' => $jg->id(), 'knowledge_base' => $kb_shared->id()]);
    $account_lab = $this->create('ai_whatsapp_account', ['name' => 'JVC WhatsApp', 'provider' => 'twilio', 'status' => 'active', 'bot' => $lab->id()]);
    $web = $this->create('ai_whatsapp_conversation', ['phone' => 'web:1:s', 'channel' => 'web', 'provider' => 'web', 'status' => 'CLOSED', 'bot' => $jg->id(), 'changed' => 1754000000]);
    $whatsapp = $this->create('ai_whatsapp_conversation', ['phone' => '+525512309140', 'channel' => 'whatsapp', 'provider' => 'twilio', 'status' => 'CLOSED', 'whatsapp_account' => $account_lab->id(), 'changed' => 1754000500]);
    $lead = $this->create('ai_whatsapp_lead', ['name' => 'Lead', 'status' => 'qualified', 'conversation' => $web->id(), 'bot' => $jg->id()]);

    $message = (string) ai_whatsapp_automation_update_11032();

    $clients = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_client')->loadMultiple();
    $names = array_map(static fn (ContentEntityInterface $client): string => (string) $client->label(), $clients);
    sort($names);
    $this->assertSame(['JG Mylard', 'Laboratorio JVC'], $names);
    $jg_client = $this->reload($jg)->get('client')->target_id;
    $lab_client = $this->reload($lab)->get('client')->target_id;
    $this->assertNotSame($jg_client, $lab_client);

    $this->assertSame($jg_client, $this->reload($account_jg)->get('client')->target_id);
    $this->assertSame($lab_client, $this->reload($account_lab)->get('client')->target_id);
    $this->assertSame($jg_client, $this->reload($kb_jg)->get('client')->target_id, 'Knowledge base used by one client');
    $this->assertNull($this->reload($kb_shared)->get('client')->target_id, 'A knowledge base used by two clients is not guessed');
    $this->assertNull($this->reload($kb_orphan)->get('client')->target_id, 'An unused knowledge base stays unassigned');
    $this->assertSame($jg_client, $this->reload($web)->get('client')->target_id, 'Web conversation from its bot');
    $this->assertSame($lab_client, $this->reload($whatsapp)->get('client')->target_id, 'WhatsApp conversation from its account');
    $this->assertSame($jg_client, $this->reload($lead)->get('client')->target_id);

    // The migration must not touch activity dates: they drive the inbox order
    // and the automatic closing of inactive conversations.
    $this->assertSame('1754000000', (string) $this->reload($web)->get('changed')->value);
    $this->assertSame('1754000500', (string) $this->reload($whatsapp)->get('changed')->value);

    $this->assertStringContainsString('Shared', $message, 'Ambiguous records are reported');

    // Running it again changes nothing and creates no duplicate clients.
    ai_whatsapp_automation_update_11032();
    $this->assertCount(2, $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_client')->loadMultiple());
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
   * Reloads an entity bypassing the static cache.
   */
  private function reload(ContentEntityInterface $entity): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId());
    $storage->resetCache([$entity->id()]);

    return $storage->load($entity->id());
  }

}
