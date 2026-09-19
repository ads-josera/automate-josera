<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\AI;

use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the reasoning effort sent to OpenAI for each bot.
 *
 * Measured in production on 2026-09-18 with the JG Mylard prompt: the default
 * (medium) effort took 18.3 s, over Twilio's 15 s webhook timeout; "low" took
 * 3.8 s. Bots therefore default to "low".
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ReasoningEffortTest extends KernelTestBase {

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
   * Requests captured by the mock HTTP client.
   *
   * @var array<int, array<string, mixed>>
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $entity_type_ids = [
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')->set('openai.api_key', 'sk-test')->save();

    $stack = HandlerStack::create(static fn () => Create::promiseFor(new Response(200, [], json_encode([
      'id' => 'resp_test',
      'output_text' => 'Hola',
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
    ]))));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * The bot's effort is sent only to models that support reasoning.
   *
   * @param array<string, mixed> $bot_values
   *   Bot field values.
   * @param array<string, string>|null $expected
   *   Expected "reasoning" payload, or NULL when it must be absent.
   */
  #[DataProvider('botProvider')]
  public function testReasoningEffortPayload(array $bot_values, ?array $expected): void {
    $etm = $this->container->get('entity_type.manager');
    $bot = $etm->getStorage('ai_whatsapp_bot')->create($bot_values + [
      'name' => 'Bot',
      'status' => 'active',
      'system_prompt' => 'Asistente.',
    ]);
    $bot->save();
    $conversation = $etm->getStorage('ai_whatsapp_conversation')->create([
      'phone' => 'web:1:s',
      'channel' => 'web',
      'provider' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
    ]);
    $conversation->save();

    $this->container->get('ai_whatsapp_automation.conversation_engine')
      ->processIncomingMessage($conversation, 'Hola', ['sender' => 'contact']);

    $this->assertCount(1, $this->history);
    $payload = json_decode((string) $this->history[0]['request']->getBody(), TRUE);
    $this->assertSame($expected, $payload['reasoning'] ?? NULL);
  }

  /**
   * Provides bots and the reasoning payload they must produce.
   *
   * @return array<string, array{array<string, mixed>, array<string, string>|null}>
   *   Bot values and expected payload.
   */
  public static function botProvider(): array {
    return [
      'new bot defaults to low' => [['model' => 'gpt-5-mini'], ['effort' => 'low']],
      'configured medium' => [['model' => 'gpt-5-mini', 'reasoning_effort' => 'medium'], ['effort' => 'medium']],
      'gpt-5.1 also reasons' => [['model' => 'gpt-5.1', 'reasoning_effort' => 'high'], ['effort' => 'high']],
      'non-reasoning model gets no parameter' => [['model' => 'gpt-4.1-mini', 'reasoning_effort' => 'low'], NULL],
    ];
  }

}
