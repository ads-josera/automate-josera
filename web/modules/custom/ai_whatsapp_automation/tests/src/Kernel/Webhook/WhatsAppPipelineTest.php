<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Webhook;

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
 * Tests the full WhatsApp flow: webhook, AI reply, delivery and lead handoff.
 *
 * OpenAI and Twilio are replaced by a mock HTTP handler: nothing leaves the
 * test process.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class WhatsAppPipelineTest extends KernelTestBase {

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
   * Replies the mocked assistant returns, in order.
   *
   * @var string[]
   */
  private array $assistantReplies = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $entity_type_ids = [
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
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')
      ->set('openai.api_key', 'sk-test')
      ->set('options.enable_ai', TRUE)
      ->set('options.enable_lead_notifications', TRUE)
      ->save();

    $handler = function (RequestInterface $request) {
      $host = $request->getUri()->getHost();
      if ($host === 'api.openai.com') {
        return Create::promiseFor(new Response(200, [], json_encode([
          'id' => 'resp_test',
          'output_text' => array_shift($this->assistantReplies) ?? 'OK',
          'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
        ])));
      }
      if ($host === 'api.twilio.com') {
        return Create::promiseFor(new Response(201, [], '{"sid":"SMtest"}'));
      }
      return Create::rejectionFor(new \RuntimeException('Unexpected outbound request to ' . $host));
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * A qualified WhatsApp conversation replies, creates a lead and notifies.
   */
  public function testWhatsAppConversationToLead(): void {
    $etm = $this->container->get('entity_type.manager');
    $bot = $etm->getStorage('ai_whatsapp_bot')->create([
      'name' => 'JG Mylard',
      'status' => 'active',
      'system_prompt' => 'Asistente de seguros.',
      'handoff_enabled' => TRUE,
      'handoff_minimum_fields' => 6,
      'handoff_required_fields' => "empresa\nmercancía, mercancia\norigen\ndestino\ntransporte, terrestre\nvalor, usd",
      'handoff_trigger_phrases' => 'asesor',
    ]);
    $bot->save();
    $account = $etm->getStorage('ai_whatsapp_account')->create([
      'name' => 'JG Mylard WhatsApp',
      'provider' => 'twilio',
      'phone_number' => '+5213342701566',
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $bot->id(),
      'lead_notification_numbers' => '+5215599999999',
    ]);
    $account->save();

    $processor = $this->container->get('ai_whatsapp_automation.webhook_processor');
    $message = static fn (string $sid, string $body): array => [
      'provider' => 'twilio',
      'message' => [
        'phone' => '+5215512345678',
        'account_phone' => '+5213342701566',
        'body' => $body,
        'provider_message_id' => $sid,
      ],
      'attempts' => 0,
      'created' => time(),
    ];

    $this->assistantReplies = [
      "**Cotización**\nIndícame empresa, mercancía, origen, destino, transporte y valor.",
      "Datos recibidos\n\n🏢 **Empresa:** Transportes MLZ\n👤 **Nombre del contacto:** Mariana López\n\nUn asesor especializado te contactará.",
    ];
    $first = $processor->process($message('SM1', 'Hola, quiero cotizar'));
    $second = $processor->process($message('SM2', 'Empresa Transportes MLZ, mercancía electrónicos, origen Monterrey, destino CDMX, transporte terrestre, valor 50000 usd'));

    $this->assertNotContains($first['status'] ?? '', ['saved_without_bot', 'saved_without_ai', 'saved_usage_limited']);
    $this->assertSame('sent', $second['delivery']['status'] ?? NULL, 'AI reply delivered through Twilio');

    // Every WhatsApp body left with WhatsApp formatting.
    $twilio_bodies = [];
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() === 'api.twilio.com') {
        parse_str((string) $transaction['request']->getBody(), $form);
        $twilio_bodies[] = $form;
      }
    }
    $this->assertNotEmpty($twilio_bodies);
    foreach ($twilio_bodies as $form) {
      $this->assertStringNotContainsString('**', (string) ($form['Body'] ?? ''));
    }
    $this->assertStringContainsString('*Empresa:* Transportes MLZ', (string) $twilio_bodies[1]['Body']);

    // The lead stores its origin and the contact data from the chat.
    $leads = $etm->getStorage('ai_whatsapp_lead')->loadMultiple();
    $this->assertCount(1, $leads);
    $lead = reset($leads);
    $conversation = $lead->get('conversation')->entity;
    $this->assertNotNull($conversation);
    $this->assertSame((string) $bot->id(), (string) $lead->get('bot')->target_id);
    $this->assertSame('Mariana López', $lead->get('name')->value);
    $this->assertSame('+5215512345678', $lead->get('phone')->value);
    $this->assertSame('HUMAN_ASSIGNED', $etm->getStorage('ai_whatsapp_conversation')->load($conversation->id())->get('status')->value);

    // The person in charge was notified.
    $recipients = array_map(static fn (array $form): string => (string) ($form['To'] ?? ''), $twilio_bodies);
    $this->assertContains('whatsapp:+5215599999999', $recipients);
  }

  /**
   * Noise is answered from configuration and silence never reaches Twilio.
   *
   * The third message matters most: an empty body would be rejected by the
   * provider, the processor would throw, and the queue would retry the same
   * noise until it gave up.
   */
  public function testNoiseNeverReachesOpenAiOrTwilio(): void {
    $etm = $this->container->get('entity_type.manager');
    $bot = $etm->getStorage('ai_whatsapp_bot')->create([
      'name' => 'JG Mylard',
      'status' => 'active',
      'system_prompt' => 'Asistente de seguros.',
    ]);
    $bot->save();
    $account = $etm->getStorage('ai_whatsapp_account')->create([
      'name' => 'JG Mylard WhatsApp',
      'provider' => 'twilio',
      'phone_number' => '+5213342701566',
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
      'bot' => $bot->id(),
    ]);
    $account->save();

    $processor = $this->container->get('ai_whatsapp_automation.webhook_processor');
    $message = static fn (string $sid, string $body): array => [
      'provider' => 'twilio',
      'message' => [
        'phone' => '+5215512345678',
        'account_phone' => '+5213342701566',
        'body' => $body,
        'provider_message_id' => $sid,
      ],
      'attempts' => 0,
      'created' => time(),
    ];

    $first = $processor->process($message('SM1', 'Vfvfffbrbrhr'));
    $second = $processor->process($message('SM2', 'Zxcvb bnmk'));
    $third = $processor->process($message('SM3', 'Prprpr mmkk vbvb'));

    $settings = $this->config('ai_whatsapp_automation.settings');
    $this->assertSame('sent', $first['delivery']['status'] ?? NULL);
    $this->assertSame($settings->get('options.unintelligible_reply_text'), $first['response_text']);
    $this->assertSame($settings->get('options.unintelligible_second_reply_text'), $second['response_text']);
    $this->assertSame('skipped_no_reply', $third['delivery']['status'] ?? NULL, 'Silence is not handed to the provider');
    $this->assertSame('', $third['response_text']);

    $hosts = array_map(
      static fn (array $transaction): string => $transaction['request']->getUri()->getHost(),
      $this->history,
    );
    $this->assertNotContains('api.openai.com', $hosts, 'Noise never costs a model call');
    $this->assertSame(2, count(array_filter($hosts, static fn (string $host): bool => $host === 'api.twilio.com')), 'Only the two ladder replies were sent');
    $this->assertSame([], $etm->getStorage('ai_whatsapp_lead')->loadMultiple(), 'Noise never becomes a lead');
  }

}
