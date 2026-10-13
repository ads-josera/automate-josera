<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * Tests the seven values sent to the approved WhatsApp alert template.
 *
 * Template "josera_alerta_servidor" (2026-10-13): Nivel, Servidor, Afectado,
 * Problema, Detalle, Desde, Ver alerta. Twilio is intercepted: the test
 * reads the ContentVariables that would have been sent.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class WhatsAppAlertTemplateTest extends AdminOpsKernelTestBase {

  /**
   * Outbound requests, recorded by the mocked HTTP client.
   */
  private array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['ai_whatsapp_bot', 'ai_whatsapp_account'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();

    $stack = HandlerStack::create(fn (RequestInterface $request) => Create::promiseFor(new Response(201, [], '{"sid":"SMtest","status":"queued"}')));
    $stack->push(Middleware::history($this->history));
    $this->container->set('http_client', new Client(['handler' => $stack]));

    $account = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_account')->create([
      'name' => 'Josera MKT',
      'provider' => 'twilio',
      'phone_number' => '+5215654905390',
      'twilio_account_sid' => 'ACtest',
      'twilio_auth_token' => 'test-token',
      'status' => 'active',
    ]);
    $account->save();

    $this->config('ai_adminops.settings')
      ->set('notifications.enabled', TRUE)
      ->set('notifications.minimum_severity', 'warning')
      ->set('notifications.cooldown_minutes', 0)
      ->set('notifications.email.enabled', FALSE)
      ->set('notifications.whatsapp', [
        'enabled' => TRUE,
        'account_id' => (string) $account->id(),
        'recipients' => ['+5215530050231'],
        'template_sid' => 'HX836b39336b31a26e3c971d66454f40b2',
      ])
      ->save();
  }

  /**
   * A server-wide alert: short title, the measured summary as the detail.
   */
  public function testAServerWideAlert(): void {
    $this->record('cpu_usage_high', 'warning', 'Uso de CPU alta: 94% (umbral 90%).', 'La medicion supero el umbral configurado.', ['value' => 94, 'threshold' => 90]);

    $variables = $this->sentVariables();

    $this->assertSame('Advertencia', $variables['1']);
    $this->assertSame('Servidor de prueba', $variables['2']);
    $this->assertSame('todo el servidor', $variables['3']);
    $this->assertSame('Uso de CPU alto', $variables['4']);
    $this->assertSame('Uso de CPU alta: 94% (umbral 90%).', $variables['5']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $variables['6']);
    $this->assertStringContainsString('/admin/reports/ai-adminops/events/', $variables['7']);
  }

  /**
   * An alert about one cPanel account names it, with its domain.
   */
  public function testAnAccountAlertNamesTheAccount(): void {
    $this->record('account_mail_flood', 'critical', 'Correo masivo en intec.', 'Revisar la cuenta.', [
      'account' => 'intec',
      'domain' => 'indestec.net',
      'problem' => 'Posible correo masivo',
      'detail' => "480 correos en la última hora,\n210 fallidos",
    ]);

    $variables = $this->sentVariables();

    $this->assertSame('Crítica', $variables['1']);
    $this->assertSame('cuenta intec (indestec.net)', $variables['3']);
    $this->assertSame('Posible correo masivo', $variables['4']);
    $this->assertSame('480 correos en la última hora, 210 fallidos', $variables['5'], 'Meta rejects line breaks in a value.');
  }

  /**
   * An unknown type uses its summary as the title; no value is ever empty.
   */
  public function testNoValueIsEverEmpty(): void {
    $this->record('something_new', 'warning', 'Algo nuevo pasó.', '', []);

    $variables = $this->sentVariables();

    $this->assertSame('Algo nuevo pasó.', $variables['4']);
    $this->assertSame('Sin dato', $variables['5'], 'Meta rejects empty values.');
    $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($variables), 'PHP keys; Twilio receives {"1": …, "7": …}.');
    $this->assertNotContains('', $variables);
  }

  /**
   * The test button fills the same seven values.
   */
  public function testTheTestButtonFillsTheTemplate(): void {
    $settings = $this->config('ai_adminops.settings')->get('notifications.whatsapp');

    $this->container->get('ai_adminops.notification_bridge')->sendTest(['enabled' => FALSE], $settings);

    $variables = $this->sentVariables();
    $this->assertSame('Prueba', $variables['1']);
    $this->assertSame('Prueba de aviso de AdminOps', $variables['4']);
    $this->assertCount(7, $variables);
  }

  /**
   * Records an event through the event manager, which notifies.
   */
  private function record(string $type, string $severity, string $summary, string $details, array $evidence): void {
    $this->container->get('ai_adminops.event_manager')->record('produccion', $type, $severity, $summary, $details, $evidence);
  }

  /**
   * Returns the ContentVariables of the one request sent to Twilio.
   *
   * @return array<string, string>
   *   Template values.
   */
  private function sentVariables(): array {
    $twilio = array_values(array_filter($this->history, static fn (array $t) => $t['request']->getUri()->getHost() === 'api.twilio.com'));
    $this->assertCount(1, $twilio, 'One WhatsApp message to the one recipient.');
    parse_str((string) $twilio[0]['request']->getBody(), $form);
    $this->assertSame('HX836b39336b31a26e3c971d66454f40b2', $form['ContentSid']);

    return json_decode($form['ContentVariables'], TRUE);
  }

}
