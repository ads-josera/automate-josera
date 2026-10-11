<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\AI;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * Tests the three locks on owner tools, and the tool round trip.
 *
 * José's requirement (2026-10-11): client bots (JG's, Hermes, any future
 * client's) never get the tools Sentinel will have, and client roles can
 * neither see nor switch them on. A fake provider stands in for AdminOps;
 * every OpenAI answer is scripted, so no real request leaves.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class OwnerToolsTest extends KernelTestBase {

  use UserCreationTrait;

  private const OWNER = '+5215530050231';

  private const STRANGER = '+5215512345678';

  private const NUMBER = '+5215654905390';

  private const CLIENT_NUMBER = '+5213342701566';

  /**
   * Request keys OpenAIService could send before owner tools existed.
   */
  private const FORMER_KEYS = ['model', 'input', 'instructions', 'metadata', 'text', 'reasoning', 'max_output_tokens'];

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
    'owner_tools_test',
  ];

  /**
   * Outbound requests, recorded by the mocked HTTP client.
   */
  private array $history = [];

  /**
   * Scripted OpenAI answers, used in order; then a plain text answer.
   */
  private array $answers = [];

  private ContentEntityInterface $publicBot;

  private ContentEntityInterface $ownerBot;

  private ContentEntityInterface $clientBot;

  private ContentEntityInterface $account;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    foreach (['ai_whatsapp_knowledge_base', 'ai_whatsapp_knowledge_document', 'ai_whatsapp_knowledge_chunk', 'ai_whatsapp_bot', 'ai_whatsapp_account', 'ai_whatsapp_conversation', 'ai_whatsapp_message', 'ai_whatsapp_lead', 'ai_whatsapp_operator_action'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')
      ->set('openai.api_key', 'sk-test')
      ->set('options.enable_ai', TRUE)
      ->save();

    $handler = function (RequestInterface $request) {
      return match ($request->getUri()->getHost()) {
        'api.openai.com' => Create::promiseFor(new Response(200, [], json_encode(array_shift($this->answers) ?? $this->textAnswer('resp_text', 'Respuesta.')))),
        'api.twilio.com' => Create::promiseFor(new Response(201, [], '{"sid":"SMtest"}')),
        default => Create::rejectionFor(new \RuntimeException('Unexpected outbound request.')),
      };
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $entity_type_manager = $this->container->get('entity_type.manager');
    $bots = $entity_type_manager->getStorage('ai_whatsapp_bot');
    // Every bot has the switch on: the switch alone must never be enough.
    $this->publicBot = $bots->create(['name' => 'Hermes', 'status' => 'active', 'system_prompt' => 'PUBLIC-PROMPT', 'owner_tools_enabled' => TRUE]);
    $this->publicBot->save();
    $this->ownerBot = $bots->create(['name' => 'Sentinel', 'status' => 'active', 'system_prompt' => 'OWNER-PROMPT', 'owner_tools_enabled' => TRUE]);
    $this->ownerBot->save();
    $this->clientBot = $bots->create(['name' => 'JG Mylard', 'status' => 'active', 'system_prompt' => 'CLIENT-PROMPT', 'owner_tools_enabled' => TRUE]);
    $this->clientBot->save();

    $accounts = $entity_type_manager->getStorage('ai_whatsapp_account');
    $this->account = $accounts->create([
      'name' => 'Josera MKT',
      'provider' => 'twilio',
      'phone_number' => self::NUMBER,
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $this->publicBot->id(),
      'owner_bot' => $this->ownerBot->id(),
      'owner_numbers' => self::OWNER,
    ]);
    $this->account->save();
    $accounts->create([
      'name' => 'JG Mylard WhatsApp',
      'provider' => 'twilio',
      'phone_number' => self::CLIENT_NUMBER,
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $this->clientBot->id(),
    ])->save();
  }

  /**
   * A client line sends what it always sent, even to the owner's number.
   *
   * José writes to JG's number to check it; that must not give JG's bot
   * tools, even with its switch on and a provider installed.
   */
  public function testAClientLineNeverGetsTools(): void {
    $this->send(self::OWNER, 'SM1', self::CLIENT_NUMBER);
    $this->send(self::STRANGER, 'SM2', self::CLIENT_NUMBER);

    $this->assertPlainRequests(2, 'CLIENT-PROMPT');
  }

  /**
   * The public bot of the owner's own line (Hermes) never gets tools.
   */
  public function testThePublicBotNeverGetsTools(): void {
    $this->send(self::STRANGER, 'SM1');

    $this->assertPlainRequests(1, 'PUBLIC-PROMPT');
  }

  /**
   * Without its switch, the owner bot answers the owner without tools.
   */
  public function testTheOwnerBotNeedsItsSwitch(): void {
    $this->ownerBot->set('owner_tools_enabled', FALSE)->save();

    $this->send(self::OWNER, 'SM1');

    $this->assertPlainRequests(1, 'OWNER-PROMPT');
  }

  /**
   * The runner checks the sender and the bot itself, not only the routing.
   *
   * The routing already keeps strangers off the owner bot and the owner off
   * the public bot; asking the runner directly proves each lock holds alone.
   */
  public function testTheRunnerChecksTheSenderItself(): void {
    $conversation = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation')->create([
      'phone' => self::STRANGER,
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
      'whatsapp_account' => $this->account->id(),
      'bot' => $this->ownerBot->id(),
    ]);
    $conversation->save();
    $runner = $this->container->get('ai_whatsapp_automation.owner_tool_runner');

    $this->assertFalse($runner->isAvailable($conversation, $this->ownerBot));

    $conversation->set('phone', self::OWNER)->save();
    $this->assertTrue($runner->isAvailable($conversation, $this->ownerBot), 'Same conversation, owner number: the locks open.');
    $this->assertFalse($runner->isAvailable($conversation, $this->publicBot), 'The owner talking to the public bot: no tools.');
  }

  /**
   * The owner asks, the model calls a tool, gets its result and answers.
   */
  public function testTheOwnerGetsAToolRoundTrip(): void {
    $this->answers = [
      $this->callAnswer('resp_1', 'call_1', 'estado_servidor', '{"servidor":"47"}'),
      $this->textAnswer('resp_2', 'La carga es 0.42.'),
    ];

    $result = $this->send(self::OWNER, 'SM1');

    $bodies = $this->openAIBodies();
    $this->assertCount(2, $bodies);
    $this->assertSame(['estado_servidor'], array_column($bodies[0]['tools'], 'name'), 'The first request offers the tools.');
    $this->assertStringContainsString('OWNER-PROMPT', json_encode($bodies[0]));
    $this->assertSame('resp_1', $bodies[1]['previous_response_id']);
    $this->assertSame('function_call_output', $bodies[1]['input'][0]['type']);
    $this->assertSame('call_1', $bodies[1]['input'][0]['call_id']);
    $this->assertSame(['servidor' => '47', 'carga' => '0.42'], json_decode($bodies[1]['input'][0]['output'], TRUE));
    $this->assertSame($bodies[0]['instructions'] ?? NULL, $bodies[1]['instructions'] ?? NULL, 'Instructions are not inherited through previous_response_id: they are sent again.');

    $provider = $this->container->get('owner_tools_test.provider');
    $this->assertCount(1, $provider->calls);
    $this->assertSame(['estado_servidor', ['servidor' => '47']], array_slice($provider->calls[0], 0, 2));

    $reply = $this->lastAiMessage();
    $this->assertSame('La carga es 0.42.', $reply->get('content')->value);
    $this->assertSame(30, (int) $reply->get('tokens')->value, 'Tokens of both rounds are recorded.');
    $this->assertNotSame('blocked_notification_recipient', $result['status'] ?? NULL);
  }

  /**
   * A model that keeps calling tools is cut off and made to answer.
   */
  public function testRoundsAreCapped(): void {
    for ($i = 1; $i <= 10; $i++) {
      $this->answers[] = $this->callAnswer('resp_' . $i, 'call_' . $i, 'estado_servidor', '{"servidor":"47"}');
    }

    $this->send(self::OWNER, 'SM1');

    $bodies = $this->openAIBodies();
    $max = $this->container->get('ai_whatsapp_automation.owner_tool_runner')::MAX_ROUNDS;
    $this->assertCount($max + 2, $bodies, 'The first request, one per allowed round, and a last one without tools.');
    $this->assertCount($max, $this->container->get('owner_tools_test.provider')->calls);
    $this->assertSame('none', end($bodies)['tool_choice']);
    $this->assertStringContainsString('Límite', end($bodies)['input'][0]['output']);
  }

  /**
   * A tool the module did not offer is refused, not run.
   */
  public function testAnUnknownToolIsRefused(): void {
    $this->answers = [
      $this->callAnswer('resp_1', 'call_1', 'borrar_todo', '{}'),
      $this->textAnswer('resp_2', 'No puedo.'),
    ];

    $this->send(self::OWNER, 'SM1');

    $bodies = $this->openAIBodies();
    $this->assertSame([], $this->container->get('owner_tools_test.provider')->calls);
    $this->assertSame(['error' => 'Esa herramienta no existe.'], json_decode($bodies[1]['input'][0]['output'], TRUE));
  }

  /**
   * A failing tool is reported to the model without its internals.
   */
  public function testAFailingToolIsReportedPlainly(): void {
    $this->answers = [
      $this->callAnswer('resp_1', 'call_1', 'estado_servidor', '{"servidor":"roto"}'),
      $this->textAnswer('resp_2', 'No pude leerlo.'),
    ];

    $this->send(self::OWNER, 'SM1');

    $output = $this->openAIBodies()[1]['input'][0]['output'];
    $this->assertStringNotContainsString('SSH timeout', $output);
    $this->assertStringContainsString('no inventes', $output);
    $this->assertSame('No pude leerlo.', $this->lastAiMessage()->get('content')->value);
  }

  /**
   * Only the dedicated permission sees or changes the switch.
   */
  public function testOnlyTheOwnerToolsPermissionSeesTheSwitch(): void {
    // User 1 bypasses every access check; take it so it is not tested.
    $this->createUser();
    $client_admin = $this->createUser(['administer ai whatsapp automation']);
    $owner_admin = $this->createUser(['administer ai whatsapp automation', 'administer ai whatsapp owner tools']);
    $field = $this->ownerBot->get('owner_tools_enabled');

    $this->assertFalse($field->access('view', $client_admin), 'A client administrator does not even see it.');
    $this->assertFalse($field->access('edit', $client_admin));
    $this->assertTrue($field->access('view', $owner_admin));
    $this->assertTrue($field->access('edit', $owner_admin));

    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertTrue($permissions['administer ai whatsapp owner tools']['restrict access'], 'Shown as a security risk on the permissions page.');
  }

  /**
   * Asserts every OpenAI request was a plain one, as before owner tools.
   */
  private function assertPlainRequests(int $count, string $marker): void {
    $bodies = $this->openAIBodies();
    $this->assertCount($count, $bodies);
    foreach ($bodies as $body) {
      $this->assertSame([], array_diff(array_keys($body), self::FORMER_KEYS), 'Only keys that existed before owner tools.');
      $this->assertStringContainsString($marker, json_encode($body));
    }
    $this->assertSame([], $this->container->get('owner_tools_test.provider')->calls);
  }

  /**
   * Returns a scripted answer that calls one tool.
   */
  private function callAnswer(string $id, string $call_id, string $name, string $arguments): array {
    return [
      'id' => $id,
      'output' => [
        ['type' => 'function_call', 'call_id' => $call_id, 'name' => $name, 'arguments' => $arguments],
      ],
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
    ];
  }

  /**
   * Returns a scripted text answer.
   */
  private function textAnswer(string $id, string $text): array {
    return [
      'id' => $id,
      'output_text' => $text,
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
    ];
  }

  /**
   * Returns the decoded body of every request sent to OpenAI.
   */
  private function openAIBodies(): array {
    $bodies = [];
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() === 'api.openai.com') {
        $bodies[] = json_decode((string) $transaction['request']->getBody(), TRUE);
      }
    }

    return $bodies;
  }

  /**
   * Sends one WhatsApp message through the webhook processor.
   */
  private function send(string $from, string $sid, string $to = self::NUMBER): array {
    return $this->container->get('ai_whatsapp_automation.webhook_processor')->process([
      'provider' => 'twilio',
      'message' => [
        'phone' => $from,
        'account_phone' => $to,
        'body' => '¿Cómo está el servidor 47?',
        'provider_message_id' => $sid,
      ],
      'attempts' => 0,
      'created' => time(),
    ]);
  }

  /**
   * Loads the newest message the AI saved.
   */
  private function lastAiMessage(): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_message');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('sender', 'ai')->sort('id', 'DESC')->range(0, 1)->execute();

    return $storage->load(reset($ids));
  }

}
