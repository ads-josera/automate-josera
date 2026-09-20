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
 * Tests what happens when a contact sends a voice note or an attachment.
 *
 * These used to be dropped before reaching a conversation: the contact was
 * left waiting and the operator saw no trace of it.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class MediaMessageTest extends KernelTestBase {

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
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')->set('openai.api_key', 'sk-test')->save();

    $handler = static function (RequestInterface $request) {
      if ($request->getUri()->getHost() === 'api.twilio.com') {
        return Create::promiseFor(new Response(201, [], '{"sid":"SMtest"}'));
      }
      return Create::rejectionFor(new \RuntimeException('Unexpected request to ' . $request->getUri()->getHost()));
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $etm = $this->container->get('entity_type.manager');
    $this->bot = $etm->getStorage('ai_whatsapp_bot')->create([
      'name' => 'JG Mylard',
      'status' => 'active',
      'system_prompt' => 'Asistente.',
    ]);
    $this->bot->save();
    $etm->getStorage('ai_whatsapp_account')->create([
      'name' => 'JG Mylard WhatsApp',
      'provider' => 'twilio',
      'phone_number' => '+5213342701566',
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $this->bot->id(),
    ])->save();
  }

  /**
   * A voice note is recorded and answered, without reaching the model.
   */
  public function testAVoiceNoteIsRecordedAndAnswered(): void {
    $result = $this->send('SM1', 'audio');

    $this->assertSame('saved_media', $result['status']);
    $this->assertSame('sent', $result['delivery']['status'] ?? NULL);

    $messages = $this->transcript();
    $this->assertSame('🎤 Nota de voz', $messages[0]['content'], 'The operator sees what arrived');
    $this->assertSame('contact', $messages[0]['sender']);
    $this->assertSame(
      $this->config('ai_whatsapp_automation.settings')->get('options.media_reply_text'),
      $messages[1]['content'],
    );
    $this->assertSame(0, $messages[1]['tokens'], 'The notice costs nothing');
    $this->assertNotContains('api.openai.com', $this->hosts(), 'Audio never reaches the model');
  }

  /**
   * A burst of attachments is answered once, not once per file.
   */
  public function testABurstIsAnsweredOnlyOnce(): void {
    $this->send('SM1', 'image');
    $this->send('SM2', 'image');
    $this->send('SM3', 'audio');

    $transcript = $this->transcript();
    $by_sender = array_count_values(array_column($transcript, 'sender'));
    $this->assertSame(1, $by_sender['ai'] ?? 0, 'The contact is told once, not three times');
    $this->assertSame(3, $by_sender['contact'] ?? 0, 'Every attachment is still recorded');
  }

  /**
   * Each kind of attachment is named in the transcript.
   */
  public function testEachKindIsNamed(): void {
    $kinds = ['image', 'video', 'application'];
    foreach ($kinds as $position => $kind) {
      $this->send('SM' . $position, $kind);
    }

    $contents = array_column(array_filter(
      $this->transcript(),
      static fn (array $message): bool => $message['sender'] === 'contact',
    ), 'content');
    $this->assertSame(['🖼️ Imagen', '🎬 Video', '📎 Archivo adjunto'], $contents);
  }

  /**
   * Once a person is handling the conversation, the bot keeps quiet.
   */
  public function testTheBotKeepsQuietWhileAHumanIsHandlingIt(): void {
    $this->send('SM1', 'audio');
    $conversation = $this->conversation();
    $conversation->set('status', 'HUMAN_ASSIGNED');
    $conversation->save();

    $result = $this->send('SM2', 'audio');

    $this->assertSame('saved_media', $result['status']);
    $this->assertSame('skipped_no_reply', $result['delivery']['status'] ?? NULL);
    // end() takes its argument by reference, so the transcript is read into
    // a variable first.
    $transcript = $this->transcript();
    $this->assertSame('🎤 Nota de voz', end($transcript)['content'], 'It is still recorded for the operator');
  }

  /**
   * With no wording configured, the attachment is recorded in silence.
   */
  public function testAnEmptySettingRecordsWithoutReplying(): void {
    $this->config('ai_whatsapp_automation.settings')->set('options.media_reply_text', '')->save();

    $result = $this->send('SM1', 'audio');

    $this->assertSame('skipped_no_reply', $result['delivery']['status'] ?? NULL);
    $this->assertSame([], $this->hosts(), 'Nothing is sent anywhere');
    $this->assertCount(1, $this->transcript());
  }

  /**
   * A bot's own wording wins over the global one.
   */
  public function testTheBotWordingWinsOverTheGlobalOne(): void {
    $this->bot->set('media_reply_text', 'No puedo escuchar audios, ¿me lo escribes?');
    $this->bot->save();

    $this->send('SM1', 'audio');

    $this->assertSame('No puedo escuchar audios, ¿me lo escribes?', $this->transcript()[1]['content']);
  }

  /**
   * Sends an attachment through the webhook processor.
   *
   * @return array<string, mixed>
   *   The processing result.
   */
  private function send(string $sid, string $media): array {
    return $this->container->get('ai_whatsapp_automation.webhook_processor')->process([
      'provider' => 'twilio',
      'message' => [
        'phone' => '+5215512345678',
        'account_phone' => '+5213342701566',
        'body' => '',
        'media' => $media,
        'provider_message_id' => $sid,
      ],
      'attempts' => 0,
      'created' => time(),
    ]);
  }

  /**
   * Returns the only conversation.
   */
  private function conversation(): ContentEntityInterface {
    $conversations = $this->container->get('entity_type.manager')
      ->getStorage('ai_whatsapp_conversation')
      ->loadMultiple();

    return reset($conversations);
  }

  /**
   * Returns the stored messages, oldest first.
   *
   * @return array<int, array{sender: string, content: string, tokens: int}>
   *   The transcript.
   */
  private function transcript(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_message');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id')->execute();

    return array_values(array_map(static fn (ContentEntityInterface $message): array => [
      'sender' => (string) $message->get('sender')->value,
      'content' => (string) $message->get('content')->value,
      'tokens' => (int) $message->get('tokens')->value,
    ], $storage->loadMultiple($ids)));
  }

  /**
   * Returns the hosts the module talked to.
   *
   * @return string[]
   *   Request hosts.
   */
  private function hosts(): array {
    return array_map(
      static fn (array $transaction): string => $transaction['request']->getUri()->getHost(),
      $this->history,
    );
  }

}
