<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\AI;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how the engine answers messages that carry no readable request.
 *
 * Both WhatsApp and the web widget go through the conversation engine, so a
 * guard here covers both channels.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class UnintelligibleLadderTest extends KernelTestBase {

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
   * The bot under test.
   */
  private ContentEntityInterface $bot;

  /**
   * The conversation under test.
   */
  private ContentEntityInterface $conversation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    foreach ([
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
    ] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')->set('openai.api_key', 'sk-test')->save();

    $stack = HandlerStack::create(static fn () => Create::promiseFor(new Response(200, [], json_encode([
      'id' => 'resp_test',
      'output_text' => 'Respuesta del modelo',
      'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
    ]))));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $storage = $this->container->get('entity_type.manager');
    $this->bot = $storage->getStorage('ai_whatsapp_bot')->create([
      'name' => 'Bot',
      'status' => 'active',
      'model' => 'gpt-5-mini',
      'system_prompt' => 'Asistente.',
    ]);
    $this->bot->save();
    $this->conversation = $storage->getStorage('ai_whatsapp_conversation')->create([
      'phone' => '+525500000001',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
      'bot' => $this->bot->id(),
    ]);
    $this->conversation->save();
  }

  /**
   * Noise is answered from configuration, never by the model.
   */
  public function testNoiseIsAnsweredWithoutCallingTheModel(): void {
    $result = $this->send('Vfvfffbrbrhr');

    $this->assertCount(0, $this->history, 'The model is not called for noise');
    $this->assertTrue($result['unintelligible']);
    $this->assertSame(
      $this->config('ai_whatsapp_automation.settings')->get('options.unintelligible_reply_text'),
      $result['response_text'],
    );
    $outgoing = $this->container->get('entity_type.manager')
      ->getStorage('ai_whatsapp_message')
      ->load($result['outgoing_message_id']);
    $this->assertInstanceOf(ContentEntityInterface::class, $outgoing, 'The reply is stored in the transcript');
    $this->assertSame(0, (int) $outgoing->get('tokens')->value, 'A canned reply costs no tokens');
  }

  /**
   * The second one in a row offers a way out, the third one says nothing.
   */
  public function testTheLadderGoesQuietAfterTwoReplies(): void {
    $settings = $this->config('ai_whatsapp_automation.settings');

    $first = $this->send('Vfvfffbrbrhr');
    $this->assertSame($settings->get('options.unintelligible_reply_text'), $first['response_text']);

    $second = $this->send('Zxcvb bnmk');
    $this->assertSame($settings->get('options.unintelligible_second_reply_text'), $second['response_text']);

    $third = $this->send('Prprpr mmkk');
    $this->assertSame('', $third['response_text'], 'The bot falls silent instead of repeating itself');
    $this->assertNull($third['outgoing_message_id'], 'Silence leaves no message behind');

    $this->assertCount(0, $this->history, 'None of the three reached the model');
  }

  /**
   * One readable message resets the ladder and reaches the model.
   */
  public function testOneReadableMessageResetsTheLadder(): void {
    $this->send('Vfvfffbrbrhr');
    $this->send('Zxcvb bnmk');

    $real = $this->send('Hola, quiero cotizar impermeabilizante');
    $this->assertCount(1, $this->history, 'A real message is answered by the model');
    $this->assertSame('Respuesta del modelo', $real['response_text']);
    $this->assertArrayNotHasKey('unintelligible', $real);

    // Back to the first rung: someone misread once is not punished for it.
    $again = $this->send('Vfvfffbrbrhr');
    $this->assertSame(
      $this->config('ai_whatsapp_automation.settings')->get('options.unintelligible_reply_text'),
      $again['response_text'],
    );
  }

  /**
   * A bot's own wording wins over the global fallback.
   */
  public function testTheBotWordingWinsOverTheGlobalOne(): void {
    $this->bot->set('unintelligible_reply_text', '¿Me repites, porfa? No te leí bien.');
    $this->bot->save();
    // The conversation reached its bot through an entity reference that was
    // resolved before the edit. Every webhook request loads it again, so the
    // test does the same rather than reading a stale copy.
    $this->conversation = $this->container->get('entity_type.manager')
      ->getStorage('ai_whatsapp_conversation')
      ->loadUnchanged($this->conversation->id());

    $this->assertSame('¿Me repites, porfa? No te leí bien.', $this->send('Vfvfffbrbrhr')['response_text']);
  }

  /**
   * With no wording configured anywhere, the bot stays quiet.
   */
  public function testAnEmptySettingMeansSilence(): void {
    $this->config('ai_whatsapp_automation.settings')
      ->set('options.unintelligible_reply_text', '')
      ->save();

    $result = $this->send('Vfvfffbrbrhr');

    $this->assertSame('', $result['response_text']);
    $this->assertNull($result['outgoing_message_id']);
    $this->assertCount(0, $this->history);
  }

  /**
   * Sends a contact message through the engine.
   *
   * @return array<string, mixed>
   *   The engine result.
   */
  private function send(string $text): array {
    return $this->container->get('ai_whatsapp_automation.conversation_engine')
      ->processIncomingMessage($this->conversation, $text, ['sender' => 'contact']);
  }

}
