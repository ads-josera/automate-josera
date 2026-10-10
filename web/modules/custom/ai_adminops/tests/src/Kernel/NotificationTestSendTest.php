<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use Drupal\ai_adminops\Form\AdminOpsSettingsForm;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "send test notification" path. No mail leaves the test.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class NotificationTestSendTest extends AdminOpsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Drupal's collector stores mail in State instead of sending it.
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
  }

  /**
   * A test goes out even while alerts are switched off.
   *
   * Checking delivery before switching alerts on is the whole point, so the
   * master switch, the severity floor and the cooldown do not apply.
   */
  public function testSendsWhileNotificationsAreOff(): void {
    // As in production on 2026-10-09: notifications off.
    $this->config('ai_adminops.settings')->set('notifications.enabled', FALSE)->save();

    $results = $this->container->get('ai_adminops.notification_bridge')->sendTest(
      ['enabled' => TRUE, 'recipients' => ['ops@example.com', 'not an address']],
      ['enabled' => FALSE],
    );

    $this->assertSame([['recipient' => 'ops@example.com', 'status' => 'sent']], $results['email'], 'Invalid addresses are dropped.');
    $this->assertSame([], $results['whatsapp']);
    $mails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $mails);
    $this->assertSame('ops@example.com', $mails[0]['to']);
    $this->assertStringContainsString('[AdminOps][TEST]', $mails[0]['subject']);
  }

  /**
   * With no channel ticked, nothing is sent and nothing is reported as sent.
   */
  public function testNothingIsSentWithoutAChannel(): void {
    $results = $this->container->get('ai_adminops.notification_bridge')->sendTest(
      ['enabled' => FALSE, 'recipients' => ['ops@example.com']],
      ['enabled' => FALSE],
    );

    $this->assertSame(['email' => [], 'whatsapp' => []], $results);
    $this->assertSame([], $this->container->get('state')->get('system.test_mail_collector', []));
  }

  /**
   * The button still works after Drupal restores the form from its cache.
   *
   * Pressing it rebuilds the form, so the second press runs on an object
   * that was serialised and restored. With readonly service properties the
   * restore left them uninitialised and the press failed with "must not be
   * accessed before initialization"; the first press always worked, which
   * is why only a real second click found it.
   */
  public function testButtonWorksOnARestoredForm(): void {
    $form_object = unserialize(serialize(AdminOpsSettingsForm::create($this->container)));
    $form_state = (new FormState())->setValues([
      'notifications' => [
        'email' => ['enabled' => 1, 'recipients' => "ops@example.com"],
        'whatsapp' => ['enabled' => 0, 'account_id' => '', 'recipients' => '', 'template_sid' => ''],
      ],
    ]);
    $form = [];

    $form_object->sendTestNotification($form, $form_state);

    $mails = $this->container->get('state')->get('system.test_mail_collector', []);
    $this->assertCount(1, $mails);
    $this->assertSame('ops@example.com', $mails[0]['to']);
    $this->assertTrue($form_state->isRebuilding(), 'The typed values stay in the form.');
  }

}
