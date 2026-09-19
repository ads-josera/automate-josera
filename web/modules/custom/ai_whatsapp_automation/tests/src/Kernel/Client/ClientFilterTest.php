<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Client;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests the "Cliente" filter of admin lists and the dashboard.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ClientFilterTest extends KernelTestBase {

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
   * Client A, whose records the filter must return.
   */
  private ContentEntityInterface $clientA;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $entity_type_ids = [
      'ai_whatsapp_client',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_knowledge_document',
      'ai_whatsapp_knowledge_chunk',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }

    // Two clients with one of everything; A's records must never mix with B's.
    $this->clientA = $this->create('ai_whatsapp_client', ['name' => 'JG Mylard']);
    $client_b = $this->create('ai_whatsapp_client', ['name' => 'Laboratorio JVC']);
    foreach ([$this->clientA, $client_b] as $index => $client) {
      $kb = $this->create('ai_whatsapp_knowledge_base', ['name' => 'KB ' . $index, 'status' => 'active', 'client' => $client->id()]);
      $this->create('ai_whatsapp_knowledge_document', ['title' => 'Doc ' . $index, 'status' => 'indexed', 'knowledge_base' => $kb->id()]);
      $bot = $this->create('ai_whatsapp_bot', ['name' => 'Bot ' . $index, 'status' => 'active', 'client' => $client->id()]);
      $this->create('ai_whatsapp_account', ['name' => 'Account ' . $index, 'provider' => 'twilio', 'status' => 'active', 'bot' => $bot->id()]);
      $conversation = $this->create('ai_whatsapp_conversation', ['phone' => 'web:' . $index, 'channel' => 'web', 'provider' => 'web', 'status' => 'AI_ACTIVE', 'bot' => $bot->id()]);
      $this->create('ai_whatsapp_message', ['conversation' => $conversation->id(), 'sender' => 'contact', 'content' => 'Hola']);
      // Client B's reply costs more so a mixed total would be visible.
      $this->create('ai_whatsapp_message', ['conversation' => $conversation->id(), 'sender' => 'ai', 'content' => 'Hola', 'tokens' => 100 * ($index + 1), 'cost' => (string) (0.01 * ($index + 1))]);
      $this->create('ai_whatsapp_lead', ['name' => 'Lead ' . $index, 'status' => 'qualified', 'conversation' => $conversation->id()]);
      $this->create('ai_whatsapp_operator_action', ['conversation' => $conversation->id(), 'action' => 'AI_STOPPED']);
    }
  }

  /**
   * Every filterable list returns only the selected client's records.
   */
  public function testListsFilterByClient(): void {
    // The client filter is an administrator tool: client users are always
    // limited to their own client (see ClientUserAccessTest).
    $this->createUser();
    $this->setCurrentUser($this->createUser(['administer ai whatsapp automation entities', 'administer ai whatsapp automation rag']));
    $this->selectClient($this->clientA);
    $types = [
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_knowledge_document',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ];
    foreach ($types as $entity_type_id) {
      $total = count($this->container->get('entity_type.manager')->getStorage($entity_type_id)->loadMultiple());
      $listed = $this->container->get('entity_type.manager')->getListBuilder($entity_type_id)->load();
      $this->assertCount(intdiv($total, 2), $listed, $entity_type_id . ' lists only client A');
      foreach ($listed as $entity) {
        $this->assertStringNotContainsString('1', (string) $entity->label(), $entity_type_id . ' leaked a record of client B');
      }
    }
  }

  /**
   * The dashboard metrics count only the selected client.
   */
  public function testDashboardFiltersByClient(): void {
    $metrics = $this->container->get('ai_whatsapp_automation.dashboard_metrics');
    $all = $metrics->getMetrics(NULL)['summary'];
    $client_a = $metrics->getMetrics(NULL, (int) $this->clientA->id());

    $this->assertSame(2, $all['active_conversations']);
    $this->assertSame(1, $client_a['summary']['active_conversations']);
    $this->assertSame(1, $client_a['summary']['sent_messages']);
    $this->assertSame(1, $client_a['summary']['received_messages']);
    $this->assertSame(1, $client_a['summary']['generated_leads']);
    $this->assertEqualsWithDelta(0.01, $client_a['summary']['openai_cost'], 0.000001);
    $this->assertSame(100.0, (float) $client_a['summary']['tokens_consumed']);
    $this->assertSame(['Bot 0'], array_column($client_a['cost_by_bot'], 'name'));
    $this->assertSame(['Bot 0'], array_column($client_a['cost_by_channel'], 'bot_name'));
  }

  /**
   * Puts ?client=<id> on the current request, as the filter form does.
   */
  private function selectClient(ContentEntityInterface $client): void {
    $request = Request::create('/admin/content/ai-whatsapp', 'GET', ['client' => $client->id()]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
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
