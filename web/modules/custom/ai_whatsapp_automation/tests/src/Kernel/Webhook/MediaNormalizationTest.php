<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Webhook;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that each provider's attachments are recognised, and nothing else.
 *
 * The risk here is not missing a voice note: it is turning a delivery
 * receipt into a conversation. Those arrive with no text either, and until
 * now everything without text was ignored.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class MediaNormalizationTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_whatsapp_account');
    $this->installConfig(['ai_whatsapp_automation']);
  }

  /**
   * Twilio sends the file count and its MIME type.
   */
  public function testTwilioAttachments(): void {
    $voice_note = $this->twilio(['From' => '+5215512345678', 'To' => '+5213342701566', 'Body' => '', 'NumMedia' => '1', 'MediaContentType0' => 'audio/ogg', 'MessageSid' => 'SM1']);
    $this->assertSame('audio', $voice_note['media'] ?? NULL);
    $this->assertSame('', $voice_note['body']);

    $photo = $this->twilio(['From' => '+5215512345678', 'Body' => '', 'NumMedia' => '1', 'MediaContentType0' => 'image/jpeg']);
    $this->assertSame('image', $photo['media'] ?? NULL);

    $pdf = $this->twilio(['From' => '+5215512345678', 'Body' => '', 'NumMedia' => '1', 'MediaContentType0' => 'application/pdf']);
    $this->assertSame('document', $pdf['media'] ?? NULL, 'An unknown attachment is still an attachment');

    // A photo with a caption is a readable message, not an attachment.
    $with_caption = $this->twilio(['From' => '+5215512345678', 'Body' => '¿Cuánto cuesta esto?', 'NumMedia' => '1', 'MediaContentType0' => 'image/jpeg']);
    $this->assertSame('¿Cuánto cuesta esto?', $with_caption['body']);
    $this->assertSame('', $with_caption['media'] ?? NULL);

    // A delivery receipt has no text and no media: still ignored.
    $this->assertSame([], $this->twilio(['From' => '+5215512345678', 'Body' => '', 'MessageStatus' => 'delivered', 'MessageSid' => 'SM1']));
    $this->assertSame([], $this->twilio(['From' => '', 'Body' => '', 'NumMedia' => '1', 'MediaContentType0' => 'audio/ogg']));
  }

  /**
   * Cloud API names the kind in the message type.
   */
  public function testCloudApiAttachments(): void {
    $this->assertSame('audio', $this->cloud(['from' => '5215512345678', 'type' => 'audio', 'id' => 'wamid.1'])['media'] ?? NULL);
    $this->assertSame('sticker', $this->cloud(['from' => '5215512345678', 'type' => 'sticker'])['media'] ?? NULL);
    $this->assertSame('location', $this->cloud(['from' => '5215512345678', 'type' => 'location'])['media'] ?? NULL);

    $text = $this->cloud(['from' => '5215512345678', 'type' => 'text', 'text' => ['body' => 'Hola']]);
    $this->assertSame('Hola', $text['body']);
    $this->assertSame('', $text['media'] ?? NULL);

    // A status payload carries no message at all.
    $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
      'entry' => [['changes' => [['value' => ['statuses' => [['status' => 'delivered']]]]]]],
    ]));
    $this->assertSame([], $this->service()->normalize('cloud_api', $request));
  }

  /**
   * Evolution names the kind in the key holding the message.
   */
  public function testEvolutionAttachments(): void {
    $this->assertSame('audio', $this->evolution(['audioMessage' => ['seconds' => 3]])['media'] ?? NULL);
    $this->assertSame('image', $this->evolution(['imageMessage' => ['mimetype' => 'image/jpeg']])['media'] ?? NULL);
    $this->assertSame('document', $this->evolution(['documentMessage' => ['fileName' => 'cotizacion.pdf']])['media'] ?? NULL);

    $text = $this->evolution(['conversation' => 'Hola']);
    $this->assertSame('Hola', $text['body']);
    $this->assertSame('', $text['media'] ?? NULL);

    // Something we have no name for stays ignored rather than becoming a
    // conversation with an empty body.
    $this->assertSame([], $this->evolution(['protocolMessage' => ['type' => 'REVOKE']]));
  }

  /**
   * Normalizes a Twilio form payload.
   *
   * @param array<string, string> $parameters
   *   Form parameters.
   *
   * @return array<string, mixed>
   *   The normalized message.
   */
  private function twilio(array $parameters): array {
    return $this->service()->normalize('twilio', Request::create('/webhook', 'POST', $parameters));
  }

  /**
   * Normalizes a Cloud API payload built around one message.
   *
   * @param array<string, mixed> $message
   *   The provider message.
   *
   * @return array<string, mixed>
   *   The normalized message.
   */
  private function cloud(array $message): array {
    $payload = json_encode([
      'entry' => [
        [
          'changes' => [
            [
              'value' => [
                'metadata' => ['display_phone_number' => '+5213342701566'],
                'messages' => [$message],
              ],
            ],
          ],
        ],
      ],
    ]);

    return $this->service()->normalize('cloud_api', Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload));
  }

  /**
   * Normalizes an Evolution payload around one message body.
   *
   * @param array<string, mixed> $message
   *   The provider message.
   *
   * @return array<string, mixed>
   *   The normalized message.
   */
  private function evolution(array $message): array {
    $payload = json_encode([
      'instance' => 'jg',
      'data' => [
        'key' => ['remoteJid' => '5215512345678@s.whatsapp.net', 'id' => 'EV1'],
        'message' => $message,
      ],
    ]);

    return $this->service()->normalize('evolution', Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload));
  }

  /**
   * Returns the provider service.
   */
  private function service(): \Drupal\ai_whatsapp_automation\Application\Webhook\WebhookProviderService {
    return $this->container->get('ai_whatsapp_automation.webhook_provider');
  }

}
