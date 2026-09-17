<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\WebChat;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the allowed-domains check of the public web chat API.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class WebChatRequestOriginTest extends KernelTestBase {

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
   * A bot restricted to its client's domains rejects unidentified callers.
   *
   * Browsers always send Origin on these POST requests, so a request without
   * Origin or Referer comes from a script and used to skip the domain list.
   */
  public function testAllowedDomainsRequireIdentifiedOrigin(): void {
    $this->installEntitySchema('ai_whatsapp_knowledge_base');
    $this->installEntitySchema('ai_whatsapp_account');
    $this->installEntitySchema('ai_whatsapp_bot');
    $bot = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create([
      'name' => 'JG Mylard',
      'status' => 'active',
      'web_widget_allowed_domains' => 'https://jgmylard.com',
    ]);
    $bot->save();
    $web_chat = $this->container->get('ai_whatsapp_automation.web_chat');
    $request = static function (array $headers): Request {
      $request = Request::create('https://app.josera.com.mx/ai-whatsapp-automation/api/web-chat/token', 'POST');
      $request->headers->add($headers);
      return $request;
    };

    // Message API (POST): the caller must identify an allowed origin.
    $this->assertTrue($web_chat->isRequestAllowed($bot, $request(['Origin' => 'https://www.jgmylard.com']), TRUE), 'Client domain');
    $this->assertTrue($web_chat->isRequestAllowed($bot, $request(['Origin' => 'https://app.josera.com.mx']), TRUE), 'Widget iframe served by this site');
    $this->assertFalse($web_chat->isRequestAllowed($bot, $request(['Origin' => 'https://evil.example']), TRUE), 'Foreign domain');
    $this->assertFalse($web_chat->isRequestAllowed($bot, $request([]), TRUE), 'API call without Origin or Referer');

    // Public chat page and embed script (GET): a shared link or QR code opens
    // them with no Referer, so an unidentified visitor must still get the
    // page. Its own API calls then carry this site's Origin.
    $this->assertTrue($web_chat->isRequestAllowed($bot, $request([])), 'Chat page opened from a shared link');
    $this->assertFalse($web_chat->isRequestAllowed($bot, $request(['Referer' => 'https://evil.example/page'])), 'Chat page embedded on a foreign site');

    // Without a domain list the widget stays open, as before.
    $bot->set('web_widget_allowed_domains', '')->save();
    $this->assertTrue($web_chat->isRequestAllowed($bot, $request([]), TRUE), 'Unrestricted bot');
  }

}
