<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Webhook;

use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that WhatsApp messages leave with WhatsApp formatting.
 *
 * The HTTP client is replaced by a mock: no request reaches Twilio.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ProviderMessageFormattingTest extends KernelTestBase {

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
    foreach (['ai_whatsapp_knowledge_base', 'ai_whatsapp_bot', 'ai_whatsapp_account'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')
      ->set('twilio.account_sid', 'ACtest')
      ->set('twilio.auth_token', 'test-token')
      ->set('twilio.whatsapp_number', '+14155238886')
      ->save();

    $stack = HandlerStack::create(new MockHandler([new Response(201, [], '{"sid":"SMtest"}')]));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * Markdown bold from the assistant is sent as WhatsApp bold.
   */
  public function testTwilioBodyUsesWhatsAppBold(): void {
    $result = $this->container->get('ai_whatsapp_automation.provider_message_sender')
      ->sendText('twilio', ['phone' => '+525512309140'], "Datos recibidos\n\n🏢 **Empresa:** Jerotracker");

    $this->assertSame('sent', $result['status']);
    $this->assertCount(1, $this->history);
    parse_str((string) $this->history[0]['request']->getBody(), $form);
    $this->assertSame("Datos recibidos\n\n🏢 *Empresa:* Jerotracker", $form['Body']);
  }

}
