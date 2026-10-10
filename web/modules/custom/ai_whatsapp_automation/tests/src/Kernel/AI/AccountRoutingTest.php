<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\AI;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which WhatsApp account, and so which bot, answers a number.
 *
 * Reproduces production on 2026-10-10: a test account ("Laboratorio JVC")
 * and a new one ("Josera MKT") shared +5215654905390, and the test account
 * kept answering even after it was deactivated.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class AccountRoutingTest extends KernelTestBase {

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
    $this->installEntitySchema('ai_whatsapp_knowledge_base');
    $this->installEntitySchema('ai_whatsapp_bot');
    $this->installEntitySchema('ai_whatsapp_account');
  }

  /**
   * A deactivated account stops answering, whatever its connection reads.
   */
  public function testADeactivatedAccountStopsAnswering(): void {
    $this->account('Laboratorio JVC', '+5215654905390', 'inactive', 'CONNECTED');
    $sentinel = $this->account('Josera MKT', '+5215654905390', 'active', 'DISCONNECTED');

    $this->assertSame($sentinel->id(), $this->answering('+5215654905390')?->id());
  }

  /**
   * A switched-off number is not handed to whichever account is left.
   *
   * Without this, deactivating a client's number while one other account is
   * active would send that client's customers to the other client's bot.
   */
  public function testASwitchedOffNumberIsNotReroutedToAnotherClient(): void {
    $this->account('JG Mylard WhatsApp', '+5213342701566', 'active', 'CONNECTED');
    $this->account('Cliente dado de baja', '+5215500001234', 'inactive', 'CONNECTED');

    $this->assertNull($this->answering('+5215500001234'));
  }

  /**
   * An identifier no account knows still reaches the only active account.
   *
   * That fallback exists for providers that send the destination in another
   * format, and must keep working.
   */
  public function testAnUnknownIdentifierStillUsesTheOnlyActiveAccount(): void {
    $only = $this->account('JG Mylard WhatsApp', '+5213342701566', 'active', 'CONNECTED');

    $this->assertSame($only->id(), $this->answering('phone-number-id-123')?->id());
  }

  /**
   * Duplicates are found across the formats a number can be written in.
   */
  public function testDuplicateNumbersAreFound(): void {
    $jvc = $this->account('Laboratorio JVC', '+5215654905390', 'active', 'CONNECTED');
    $this->account('Inactiva', '+5215654905390', 'inactive', 'DISCONNECTED');
    $manager = $this->container->get('ai_whatsapp_automation.bot_manager');

    $this->assertSame([(int) $jvc->id()], array_keys($manager->otherActiveAccountsWithNumber('twilio', 'whatsapp:+52 1 56 5490 5390')), 'Another format of the same number, and only the active account.');
    $this->assertSame([], $manager->otherActiveAccountsWithNumber('twilio', '+5215654905390', $jvc->id()), 'An account does not conflict with itself.');
    $this->assertSame([], $manager->otherActiveAccountsWithNumber('evolution', '+5215654905390'), 'Another provider is another number space.');
  }

  /**
   * Creates a Twilio account.
   */
  private function account(string $name, string $number, string $status, string $connection): ContentEntityInterface {
    $account = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_account')->create([
      'name' => $name,
      'provider' => 'twilio',
      'phone_number' => $number,
      'status' => $status,
      'connection_status' => $connection,
    ]);
    $account->save();

    return $account;
  }

  /**
   * Returns the account a Twilio message to a number is routed to.
   */
  private function answering(string $number): ?ContentEntityInterface {
    return $this->container->get('ai_whatsapp_automation.bot_manager')->getAccountForProviderMessage('twilio', ['account_phone' => $number]);
  }

}
