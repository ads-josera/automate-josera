<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Webhook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * Tests that only the owner's numbers reach the owner bot.
 *
 * The owner bot (Sentinel) will be able to read the server, so a stranger
 * reaching it is the failure that matters. Each test sends a real webhook
 * message and reads which prompt went to OpenAI. No real request leaves.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class OwnerRoutingTest extends KernelTestBase {

  private const OWNER = '+5215530050231';

  private const STRANGER = '+5215512345678';

  private const NUMBER = '+5215654905390';

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
   * Outbound requests, recorded by the mocked HTTP client.
   */
  private array $history = [];

  private ContentEntityInterface $publicBot;

  private ContentEntityInterface $ownerBot;

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
        'api.openai.com' => Create::promiseFor(new Response(200, [], json_encode([
          'id' => 'resp_test',
          'output_text' => 'Respuesta.',
          'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ]))),
        'api.twilio.com' => Create::promiseFor(new Response(201, [], '{"sid":"SMtest"}')),
        default => Create::rejectionFor(new \RuntimeException('Unexpected outbound request.')),
      };
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $bots = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot');
    $this->publicBot = $bots->create(['name' => 'Atención', 'status' => 'active', 'system_prompt' => 'PUBLIC-PROMPT']);
    $this->publicBot->save();
    $this->ownerBot = $bots->create(['name' => 'Sentinel', 'status' => 'active', 'system_prompt' => 'OWNER-PROMPT']);
    $this->ownerBot->save();
    $this->account = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_account')->create([
      'name' => 'Josera MKT',
      'provider' => 'twilio',
      'phone_number' => self::NUMBER,
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $this->publicBot->id(),
      'owner_bot' => $this->ownerBot->id(),
      'owner_numbers' => "+52 55 3005 0231\n",
    ]);
    $this->account->save();
  }

  /**
   * The owner reaches the owner bot; anyone else the public bot.
   */
  public function testOnlyTheOwnerReachesTheOwnerBot(): void {
    $this->send(self::OWNER, 'SM1');
    $this->assertSame(['OWNER-PROMPT'], $this->promptsSent(), 'Written as +52 55…, still the owner.');

    $this->history = [];
    $this->send(self::STRANGER, 'SM2');
    $this->assertSame(['PUBLIC-PROMPT'], $this->promptsSent());

    $this->assertSame($this->ownerBot->id(), $this->conversationOf(self::OWNER)->get('bot')->target_id, 'Lists show the owner bot for the owner.');
    $this->assertSame($this->publicBot->id(), $this->conversationOf(self::STRANGER)->get('bot')->target_id);
  }

  /**
   * A conversation stored with the owner bot does not let a stranger in.
   *
   * On 2026-10-10 the account's public bot was Sentinel itself, so any
   * stranger who wrote that day has a conversation that stored Sentinel.
   */
  public function testAStrangerStoredWithTheOwnerBotGetsThePublicBot(): void {
    $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation')->create([
      'phone' => self::STRANGER,
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
      'whatsapp_account' => $this->account->id(),
      'bot' => $this->ownerBot->id(),
    ])->save();

    $this->send(self::STRANGER, 'SM1');

    $this->assertSame(['PUBLIC-PROMPT'], $this->promptsSent());
  }

  /**
   * If the owner bot is also the public bot, a stranger gets no answer.
   *
   * The form refuses this; saved some other way, the rule fails closed.
   */
  public function testMisconfiguredAccountFailsClosed(): void {
    $this->account->set('bot', $this->ownerBot->id())->save();

    $this->send(self::STRANGER, 'SM1');

    $this->assertSame([], $this->promptsSent(), 'No model call at all.');
  }

  /**
   * A number written without its country code is nobody's.
   */
  public function testANumberWithoutCountryCodeMatchesNobody(): void {
    $this->account->set('owner_numbers', '5530050231')->save();

    $this->send(self::OWNER, 'SM1');

    $this->assertSame(['PUBLIC-PROMPT'], $this->promptsSent());
  }

  /**
   * An account without owner routing is untouched, owner number or not.
   *
   * José writes to his clients' numbers to check them; he must keep
   * reaching the client's bot there.
   */
  public function testAClientAccountIgnoresTheOwnerNumber(): void {
    $client_bot = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create(['name' => 'JG Mylard', 'status' => 'active', 'system_prompt' => 'CLIENT-PROMPT']);
    $client_bot->save();
    $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_account')->create([
      'name' => 'JG Mylard WhatsApp',
      'provider' => 'twilio',
      'phone_number' => '+5213342701566',
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $client_bot->id(),
    ])->save();

    $this->send(self::OWNER, 'SM1', '+5213342701566');

    $this->assertSame(['CLIENT-PROMPT'], $this->promptsSent());
  }

  /**
   * The account's prompt and model are the public side's, not the owner's.
   *
   * On 2026-10-10 the Josera MKT account carried a GPT-5 model override set
   * for Sentinel; with owner routing it would have applied to the public bot,
   * and an account prompt would have replaced Sentinel's.
   */
  public function testAccountSettingsStayOnThePublicSide(): void {
    $this->ownerBot->set('model', 'gpt-5')->save();
    $this->account->set('prompt_override', 'ACCOUNT-PROMPT')->set('model_override', 'gpt-5-mini')->save();

    $this->send(self::OWNER, 'SM1');
    $this->assertSame(['OWNER-PROMPT'], $this->promptsSent());
    $this->assertSame(['gpt-5'], $this->modelsSent());

    $this->history = [];
    $this->send(self::STRANGER, 'SM2');
    $this->assertSame(['ACCOUNT-PROMPT'], $this->promptsSent());
    $this->assertSame(['gpt-5-mini'], $this->modelsSent());
  }

  /**
   * Returns the model of every request sent to OpenAI.
   *
   * @return string[]
   *   Model IDs.
   */
  private function modelsSent(): array {
    $models = [];
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() === 'api.openai.com') {
        $models[] = (string) (json_decode((string) $transaction['request']->getBody(), TRUE)['model'] ?? '');
      }
    }

    return $models;
  }

  /**
   * Sends one WhatsApp message through the webhook processor.
   */
  private function send(string $from, string $sid, string $to = self::NUMBER): void {
    $this->container->get('ai_whatsapp_automation.webhook_processor')->process([
      'provider' => 'twilio',
      'message' => [
        'phone' => $from,
        'account_phone' => $to,
        'body' => '¿Cómo está el servidor?',
        'provider_message_id' => $sid,
      ],
      'attempts' => 0,
      'created' => time(),
    ]);
  }

  /**
   * Returns the bot prompt of every request sent to OpenAI.
   *
   * @return string[]
   *   One of the test prompts per request, in order.
   */
  private function promptsSent(): array {
    $prompts = [];
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() !== 'api.openai.com') {
        continue;
      }
      $body = (string) $transaction['request']->getBody();
      foreach (['OWNER-PROMPT', 'PUBLIC-PROMPT', 'CLIENT-PROMPT', 'ACCOUNT-PROMPT'] as $marker) {
        if (str_contains($body, $marker)) {
          $prompts[] = $marker;
        }
      }
    }

    return $prompts;
  }

  /**
   * Loads the conversation of a sender.
   */
  private function conversationOf(string $phone): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('phone', $phone)->execute();

    return $storage->load(reset($ids));
  }

}
