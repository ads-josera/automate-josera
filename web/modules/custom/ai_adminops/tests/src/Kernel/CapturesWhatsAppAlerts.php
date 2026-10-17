<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Sends alerts to a WhatsApp account whose Twilio calls are intercepted.
 *
 * Nothing leaves the test: each request is answered locally and kept, so a
 * test reads the template values that would have been sent.
 */
trait CapturesWhatsAppAlerts {

  /**
   * Outbound requests, recorded by the mocked HTTP client.
   */
  private array $history = [];

  /**
   * Switches WhatsApp alerts on, with the approved template.
   */
  protected function captureWhatsAppAlerts(): void {
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
   * The template values of every WhatsApp alert sent so far, in order.
   *
   * @return list<array<string, string>>
   *   Template values, keyed "1" to "7".
   */
  protected function sentAlerts(): array {
    $alerts = [];
    foreach ($this->history as $transaction) {
      if ($transaction['request']->getUri()->getHost() !== 'api.twilio.com') {
        continue;
      }
      parse_str((string) $transaction['request']->getBody(), $form);
      $this->assertSame('HX836b39336b31a26e3c971d66454f40b2', $form['ContentSid']);
      $alerts[] = json_decode($form['ContentVariables'], TRUE);
    }

    return $alerts;
  }

}
