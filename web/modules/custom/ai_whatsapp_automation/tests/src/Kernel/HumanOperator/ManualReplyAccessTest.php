<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\HumanOperator;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that manual replies are only offered where they can be delivered.
 *
 * The web widget has no channel to push an operator message to the visitor,
 * so a manual reply on a web conversation was stored and reported as saved
 * while nobody ever received it.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ManualReplyAccessTest extends KernelTestBase {

  use UserCreationTrait;

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
    $this->installEntitySchema('file');
    foreach (['ai_whatsapp_knowledge_base', 'ai_whatsapp_bot', 'ai_whatsapp_account', 'ai_whatsapp_conversation'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Web conversations deny the manual reply route; WhatsApp ones allow it.
   */
  public function testManualReplyRouteAccessByProvider(): void {
    // Skip uid 1, which bypasses access checks.
    $this->createUser();
    $operator = $this->createUser(['administer ai whatsapp automation entities']);
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation');
    $access_manager = $this->container->get('access_manager');

    $web = $storage->create(['phone' => 'web:1:s', 'channel' => 'web', 'provider' => 'web', 'status' => 'AI_ACTIVE']);
    $web->save();
    $whatsapp = $storage->create([
      'phone' => '+525512309140',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
    ]);
    $whatsapp->save();

    $route = 'ai_whatsapp_automation.conversation_manual_reply';
    $this->assertFalse($access_manager->checkNamedRoute($route, ['ai_whatsapp_conversation' => $web->id()], $operator));
    $this->assertTrue($access_manager->checkNamedRoute($route, ['ai_whatsapp_conversation' => $whatsapp->id()], $operator));

    $human_operator = $this->container->get('ai_whatsapp_automation.human_operator');
    $this->assertFalse($human_operator->supportsManualReply($web));
    $this->assertTrue($human_operator->supportsManualReply($whatsapp));
  }

}
