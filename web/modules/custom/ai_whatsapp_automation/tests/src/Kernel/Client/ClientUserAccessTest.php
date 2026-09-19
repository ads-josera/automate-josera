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
 * Tests that a client's users only see and operate their own client's data.
 *
 * Two clients, one agent each. Every check is made with the agent's account,
 * never with the administrator's: an administrator sees everything, so a
 * leak is invisible from that account.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ClientUserAccessTest extends KernelTestBase {

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
   * Records of client A and client B, keyed by type.
   *
   * @var array<string, array<string, \Drupal\Core\Entity\ContentEntityInterface>>
   */
  private array $records = [];

  /**
   * Agents of client A and client B, and an agent without client.
   *
   * @var array<string, \Drupal\Core\Session\AccountInterface>
   */
  private array $agents = [];

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
    $this->installConfig(['ai_whatsapp_automation']);
    $this->container->get('router.builder')->rebuild();

    // The first user (uid 1) bypasses access checks: agents come after it.
    $this->createUser();
    $permissions = [
      'access own client ai whatsapp records',
      'operate own client ai whatsapp conversations',
      'view ai whatsapp automation dashboard',
    ];
    foreach (['a' => 'JG Mylard', 'b' => 'Laboratorio JVC'] as $key => $name) {
      $client = $this->create('ai_whatsapp_client', ['name' => $name]);
      $bot = $this->create('ai_whatsapp_bot', ['name' => 'Bot ' . $key, 'status' => 'active', 'client' => $client->id()]);
      $account = $this->create('ai_whatsapp_account', [
        'name' => 'WhatsApp ' . $key,
        'provider' => 'twilio',
        'status' => 'active',
        'bot' => $bot->id(),
      ]);
      $conversation = $this->create('ai_whatsapp_conversation', [
        'phone' => '+52550000000' . ($key === 'a' ? '1' : '2'),
        'channel' => 'whatsapp',
        'provider' => 'twilio',
        'status' => 'AI_ACTIVE',
        'whatsapp_account' => $account->id(),
        'bot' => $bot->id(),
      ]);
      $this->records[$key] = [
        'client' => $client,
        'bot' => $bot,
        'conversation' => $conversation,
        'message' => $this->create('ai_whatsapp_message', [
          'conversation' => $conversation->id(),
          'sender' => 'contact',
          'content' => 'Hola ' . $key,
        ]),
        'lead' => $this->create('ai_whatsapp_lead', [
          'name' => 'Lead ' . $key,
          'status' => 'new',
          'conversation' => $conversation->id(),
        ]),
      ];
      $this->agents[$key] = $this->createUser($permissions, 'agent_' . $key, FALSE, ['ai_whatsapp_client' => $client->id()]);
    }
    $this->agents['none'] = $this->createUser($permissions, 'agent_without_client');
  }

  /**
   * Agents can view their own records and nothing of the other client.
   */
  public function testEntityAccess(): void {
    $agent = $this->agents['a'];
    foreach (['conversation', 'message', 'lead'] as $type) {
      $this->assertTrue($this->records['a'][$type]->access('view', $agent), "Own $type is visible");
      $this->assertFalse($this->records['b'][$type]->access('view', $agent), "Other client's $type is not visible");
      $this->assertFalse($this->records['a'][$type]->access('update', $agent), "Own $type cannot be edited through the admin form");
      $this->assertFalse($this->records['a'][$type]->access('delete', $agent), "Own $type cannot be deleted");
    }
    $this->assertFalse($this->records['a']['bot']->access('view', $agent), 'Bots are configuration: not visible to agents');
    $this->assertFalse($this->records['a']['conversation']->access('view', $this->agents['none']), 'An agent without client sees nothing');
  }

  /**
   * Admin lists and every access-checked query return only own records.
   */
  public function testListsOnlyReturnOwnRecords(): void {
    $this->setCurrentUser($this->agents['a']);
    // Even when the agent asks for the other client in the URL.
    $this->pushRequest(['client' => $this->records['b']['client']->id()]);
    foreach (['conversation', 'message', 'lead'] as $type) {
      $listed = $this->container->get('entity_type.manager')->getListBuilder('ai_whatsapp_' . $type)->load();
      $this->assertSame([(int) $this->records['a'][$type]->id()], array_map('intval', array_keys($listed)), "Only own $type listed");
    }

    $this->setCurrentUser($this->agents['none']);
    $this->assertSame([], $this->container->get('entity_type.manager')->getListBuilder('ai_whatsapp_conversation')->load());
  }

  /**
   * Route access: own lists and actions yes; configuration and others no.
   */
  public function testRouteAccess(): void {
    $own = ['ai_whatsapp_conversation' => $this->records['a']['conversation']->id()];
    $other = ['ai_whatsapp_conversation' => $this->records['b']['conversation']->id()];
    $allowed = [
      ['entity.ai_whatsapp_conversation.collection', []],
      ['entity.ai_whatsapp_message.collection', []],
      ['entity.ai_whatsapp_lead.collection', []],
      ['ai_whatsapp_automation.dashboard', []],
      ['entity.ai_whatsapp_conversation.canonical', $own],
      ['ai_whatsapp_automation.conversation_stop_ai', $own],
      ['ai_whatsapp_automation.conversation_manual_reply', $own],
      ['ai_whatsapp_automation.conversation_close', $own],
      ['ai_whatsapp_automation.conversation_assign_operator', $own],
      ['ai_whatsapp_automation.lead_status', ['ai_whatsapp_lead' => $this->records['a']['lead']->id()]],
    ];
    $denied = [
      ['entity.ai_whatsapp_conversation.canonical', $other],
      ['ai_whatsapp_automation.conversation_stop_ai', $other],
      ['ai_whatsapp_automation.conversation_manual_reply', $other],
      ['ai_whatsapp_automation.lead_status', ['ai_whatsapp_lead' => $this->records['b']['lead']->id()]],
      ['entity.ai_whatsapp_conversation.edit_form', $own],
      ['entity.ai_whatsapp_bot.collection', []],
      ['entity.ai_whatsapp_account.collection', []],
      ['entity.ai_whatsapp_client.collection', []],
      ['entity.ai_whatsapp_knowledge_base.collection', []],
      ['entity.ai_whatsapp_operator_action.collection', []],
      ['ai_whatsapp_automation.multibot_routing', []],
      ['ai_whatsapp_automation.rag_upload', []],
      ['ai_whatsapp_automation.settings', []],
    ];
    $access_manager = $this->container->get('access_manager');
    foreach ($allowed as [$route, $parameters]) {
      $this->assertTrue($access_manager->checkNamedRoute($route, $parameters, $this->agents['a']), "Agent may use $route");
    }
    foreach ($denied as [$route, $parameters]) {
      $this->assertFalse($access_manager->checkNamedRoute($route, $parameters, $this->agents['a']), "Agent may not use $route");
    }
  }

  /**
   * The dashboard shows only the agent's client and never costs.
   */
  public function testDashboardIsScopedAndHidesCosts(): void {
    $this->create('ai_whatsapp_message', [
      'conversation' => $this->records['b']['conversation']->id(),
      'sender' => 'ai',
      'content' => 'Respuesta',
      'tokens' => 500,
      'cost' => '0.02',
    ]);
    $this->setCurrentUser($this->agents['a']);
    $this->pushRequest(['client' => $this->records['b']['client']->id(), 'period' => 'all']);
    $build = $this->container->get('class_resolver')
      ->getInstanceFromDefinition('Drupal\ai_whatsapp_automation\Controller\DashboardController')
      ->dashboard();

    $this->assertArrayNotHasKey('openai_cost', $build['summary']);
    $this->assertArrayNotHasKey('tokens_consumed', $build['summary']);
    $this->assertArrayNotHasKey('rankings', $build, 'Cost tables are hidden');
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($build['summary']['received_messages']);
    $this->assertStringContainsString('>1<', $rendered, 'Only own received messages are counted');
  }

  /**
   * The operator selector only offers users of the same client.
   */
  public function testAssignOperatorOffersOnlyOwnClientUsers(): void {
    $this->setCurrentUser($this->agents['a']);
    $options = $this->container->get('ai_whatsapp_automation.client_access')->assignableOperators($this->agents['a']);
    $this->assertSame([(int) $this->agents['a']->id()], array_map('intval', array_keys($options)));
  }

  /**
   * Pushes a request with query parameters, as the filter forms do.
   *
   * @param array<string, mixed> $query
   *   Query parameters.
   */
  private function pushRequest(array $query): void {
    $request = Request::create('/admin/content/ai-whatsapp', 'GET', $query);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);
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
