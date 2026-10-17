<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the alerts about single cPanel accounts and certificates (C2b).
 *
 * Each scheduled reading is scripted with the accounts as WHM gives them;
 * the WhatsApp alerts are intercepted, so the test reads what José would
 * have received.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class AccountAlertsTest extends AdminOpsKernelTestBase {

  use CapturesWhatsAppAlerts;

  /**
   * Time of the next scripted reading; 15 minutes apart.
   */
  private int $readAt;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ai_adminops', ['ai_adminops_account_usage']);
    $this->captureWhatsAppAlerts();
    $this->readAt = $this->container->get('datetime.time')->getRequestTime() - 86400;
  }

  /**
   * 80 % of the package limit warns, the limit is critical, then it closes.
   */
  public function testMailIsMeasuredAgainstThePackageLimit(): void {
    $this->accountsRead(['intec' => ['sent' => 15]]);
    $this->assertSame([], $this->events(), '15 of 20 is 75 %: nothing.');

    $this->accountsRead(['intec' => ['sent' => 17]]);
    $this->assertSame(['account_mail_near_limit intec open'], $this->events());
    $alert = $this->sentAlerts()[0];
    $this->assertSame('Advertencia', $alert['1']);
    $this->assertSame('cuenta intec (tsd.lat)', $alert['3']);
    $this->assertSame('Correo cerca del límite del paquete', $alert['4']);
    $this->assertStringContainsString('17 de 20 correos permitidos por hora (85 %)', $alert['5']);

    $this->accountsRead(['intec' => ['sent' => 20]]);
    $this->assertSame(['account_mail_near_limit intec resolved', 'account_mail_limit intec open'], $this->events());
    $this->assertSame('Crítica', $this->sentAlerts()[1]['1']);
    $this->assertSame('Correo en el límite del paquete', $this->sentAlerts()[1]['4']);

    $this->accountsRead(['intec' => ['sent' => 18]]);
    $this->assertSame(['account_mail_near_limit intec resolved', 'account_mail_limit intec open'], $this->events(), 'Falling from the limit to 90 % sends no second, milder alert.');
    $this->assertCount(2, $this->sentAlerts());

    $this->accountsRead(['intec' => ['sent' => 4]]);
    $this->assertSame(['account_mail_near_limit intec resolved', 'account_mail_limit intec resolved'], $this->events());
    $this->assertCount(2, $this->sentAlerts(), 'Closing sends nothing.');
  }

  /**
   * Straight to the limit: one critical alert, never a warning first.
   */
  public function testJumpingToTheLimitSendsOneAlert(): void {
    $this->accountsRead(['intec' => ['sent' => 300]]);

    $this->assertSame(['account_mail_limit intec open'], $this->events());
    $this->assertCount(1, $this->sentAlerts());
  }

  /**
   * Each account is its own alert; a package without a limit never alerts.
   */
  public function testEachAccountIsItsOwnAlert(): void {
    $this->accountsRead([
      'intec' => ['sent' => 19],
      'koemx' => ['sent' => 95],
      'humantz' => ['sent' => 5000, 'limit' => NULL],
    ]);

    $this->assertSame(['account_mail_near_limit intec open', 'account_mail_near_limit koemx open'], $this->events());
    $this->assertSame(['cuenta intec (tsd.lat)', 'cuenta koemx (koe.com.mx)'], array_column($this->sentAlerts(), '3'));
  }

  /**
   * Failed mail needs two readings in a row.
   */
  public function testFailedMailMustLast(): void {
    $this->accountsRead(['koemx' => ['sent' => 30, 'failed' => 25]]);
    $this->assertSame([], $this->events(), 'One burst of bounces is not a problem yet.');

    $this->accountsRead(['koemx' => ['sent' => 30, 'failed' => 12]]);
    $this->assertSame(['account_mail_failed koemx open'], $this->events());
    $this->assertSame('Muchos correos fallidos', $this->sentAlerts()[0]['4']);

    $this->accountsRead(['koemx' => ['sent' => 30, 'failed' => 2]]);
    $this->assertSame(['account_mail_failed koemx resolved'], $this->events());
  }

  /**
   * The disk quota alert closes only well below its threshold.
   */
  public function testDiskQuotaWithMargin(): void {
    $this->accountsRead(['koemx' => ['disk' => 19000]]);
    $this->assertSame(['account_disk_high koemx open'], $this->events(), '19000 of 21000 MB is 90.5 %.');
    $this->assertStringContainsString('90.5 %', $this->sentAlerts()[0]['5']);

    $this->accountsRead(['koemx' => ['disk' => 18000]]);
    $this->assertSame(['account_disk_high koemx open'], $this->events(), '85.7 %: still within the 5-point margin.');

    $this->accountsRead(['koemx' => ['disk' => 17000]]);
    $this->assertSame(['account_disk_high koemx resolved'], $this->events(), '81 %: closed.');
    $this->assertCount(1, $this->sentAlerts());
  }

  /**
   * CPU alerts after an hour above the threshold; a gap breaks the run.
   */
  public function testCpuMustLastAnHour(): void {
    foreach ([1, 2, 3] as $i) {
      $this->accountsRead(['koemx' => ['cpu' => 70]]);
    }
    $this->assertSame([], $this->events(), 'Three readings are 30 minutes: a cron job.');

    $this->accountsRead(['koemx' => ['cpu' => 62.5]]);
    $this->assertSame(['account_cpu_sustained koemx open'], $this->events());
    $this->assertSame('CPU alta sostenida', $this->sentAlerts()[0]['4']);

    $this->accountsRead(['koemx' => ['cpu' => 3]]);
    $this->assertSame(['account_cpu_sustained koemx resolved'], $this->events());

    // Four high readings, but monitoring stopped for two hours in between.
    foreach ([1, 2] as $i) {
      $this->accountsRead(['koemx' => ['cpu' => 80]]);
    }
    $this->readAt += 7200;
    foreach ([1, 2] as $i) {
      $this->accountsRead(['koemx' => ['cpu' => 80]]);
    }
    $this->assertSame(['account_cpu_sustained koemx resolved'], $this->events(), 'Readings with a gap are not an hour.');
  }

  /**
   * A reading without data closes nothing; a suspended or gone account does.
   */
  public function testUnknownKeepsSuspendedAndGoneClose(): void {
    $this->accountsRead(['intec' => ['sent' => 25], 'koemx' => ['sent' => 100], 'humantz' => ['disk' => 490]]);
    $this->assertSame(['account_mail_limit intec open', 'account_mail_limit koemx open', 'account_disk_high humantz open'], $this->events());

    $this->readings['get_mail_by_account'] = ['status' => 'failed'];
    $this->collect();
    $this->assertSame(['account_mail_limit intec open', 'account_mail_limit koemx open', 'account_disk_high humantz open'], $this->events(), 'Mail not read: its alerts stay as they were.');

    $this->readings['get_accounts'] = ['status' => 'failed'];
    $this->collect();
    $this->assertSame(['account_mail_limit intec open', 'account_mail_limit koemx open', 'account_disk_high humantz open'], $this->events(), 'No account list: nothing is known.');

    $this->accountsRead(['intec' => ['sent' => 25, 'suspended' => TRUE], 'humantz' => ['disk' => 490]]);
    $this->assertSame(['account_mail_limit intec resolved', 'account_mail_limit koemx resolved', 'account_disk_high humantz open'], $this->events(), 'intec was suspended and koemx no longer exists.');
  }

  /**
   * Switched off, nothing opens.
   */
  public function testAccountAlertsCanBeSwitchedOff(): void {
    $this->config('ai_adminops.settings')->set('alerts.accounts.enabled', FALSE)->save();

    $this->accountsRead(['intec' => ['sent' => 300], 'koemx' => ['disk' => 20900]]);

    $this->assertSame([], $this->events());
  }

  /**
   * One alert per domain whose certificate is not being renewed.
   */
  public function testCertificatesAboutToExpire(): void {
    $this->certificates(['app.josera.com.mx' => 25, 'tsd.lat' => 15, 'koe.com.mx' => NULL]);
    $this->assertSame(['ssl_expiring tsd.lat open'], $this->events());
    $alert = $this->sentAlerts()[0];
    $this->assertSame('Advertencia', $alert['1']);
    $this->assertSame('dominio tsd.lat', $alert['3']);
    $this->assertSame('Certificado SSL por vencer', $alert['4']);
    $this->assertStringContainsString('vence en 15 días', $alert['5']);

    $this->certificates(['tsd.lat' => 14]);
    $this->assertCount(1, $this->sentAlerts(), 'A day closer is not news.');

    $this->certificates(['tsd.lat' => 6]);
    $this->assertSame(['ssl_expiring tsd.lat open'], $this->events());
    $this->assertSame('Crítica', $this->sentAlerts()[1]['1'], 'The last week is news: it alerts again, as critical.');

    $this->certificates(['tsd.lat' => NULL]);
    $this->assertSame(['ssl_expiring tsd.lat open'], $this->events(), 'An unreadable certificate closes nothing.');

    $this->certificates(['tsd.lat' => 89]);
    $this->assertSame(['ssl_expiring tsd.lat resolved'], $this->events(), 'Renewed.');
  }

  /**
   * Scripts one scheduled reading of the accounts and stores it.
   *
   * Accounts not listed do not exist. Defaults: nothing sent or failed, low
   * disk and CPU, the packages of production on 2026-10-15.
   *
   * @param array<string, array<string, mixed>> $accounts
   *   Per user: sent, failed, limit, disk (MB), cpu, suspended.
   */
  private function accountsRead(array $accounts): void {
    $defaults = [
      'intec' => ['domain' => 'tsd.lat', 'limit' => 20, 'quota' => 'unlimited'],
      'koemx' => ['domain' => 'koe.com.mx', 'limit' => 100, 'quota' => '21000M'],
      'humantz' => ['domain' => 'humantz.com.mx', 'limit' => 50, 'quota' => '500M'],
    ];
    $acct = $records = $users = [];
    foreach ($accounts as $user => $values) {
      $values += $defaults[$user] + ['sent' => 0, 'failed' => 0, 'disk' => 100, 'cpu' => 0.5, 'suspended' => FALSE];
      $acct[] = [
        'user' => $user,
        'domain' => $values['domain'],
        'diskused' => $values['disk'] . 'M',
        'disklimit' => $values['quota'],
        'suspended' => (int) $values['suspended'],
        'plan' => 'Paquete_' . $user,
        'max_email_per_hour' => $values['limit'] === NULL ? 'unlimited' : (string) $values['limit'],
      ];
      if ($values['sent'] > 0) {
        $records[] = ['USER' => $user, 'SENDCOUNT' => $values['sent'], 'SUCCESSCOUNT' => $values['sent'] - $values['failed'], 'FAILCOUNT' => $values['failed'], 'DEFERCOUNT' => 0, 'REACHED_MAXEMAILS' => 0];
      }
      $users[] = ['user' => $user, 'cpu_percent' => $values['cpu'], 'memory_mb' => 100, 'processes' => 3];
    }
    $this->readings['get_accounts'] = ['status' => 'ok', 'whm' => ['metadata' => ['result' => 1], 'data' => ['acct' => $acct]]];
    $whm_mail = ['metadata' => ['result' => 1], 'data' => ['records' => $records]];
    $this->readings['get_mail_by_account'] = ['status' => 'ok', 'last_hour' => $whm_mail, 'last_day' => $whm_mail];
    $this->readings['get_account_processes'] = ['status' => 'ok', 'users' => $users];
    $this->collect();
  }

  /**
   * Stores the scripted reading as the scheduled job would.
   */
  private function collect(): void {
    $server = $this->container->get('entity_type.manager')->getStorage('ai_adminops_server')->load('produccion');
    $this->container->get('ai_adminops.account_usage_collector')->collect($server, $this->readAt);
    $this->readAt += 900;
  }

  /**
   * Scripts and evaluates one get_ssl_status reading.
   *
   * @param array<string, int|null> $days
   *   Days left per domain; NULL when the certificate could not be read.
   */
  private function certificates(array $days): void {
    $certificates = [];
    foreach ($days as $domain => $left) {
      $certificates[] = $left === NULL
        ? ['domain' => $domain, 'status' => 'unavailable']
        : ['domain' => $domain, 'days_left' => $left, 'matches_domain' => TRUE];
    }
    $readable = array_filter($days, 'is_int');
    $this->readings['get_ssl_status'] = $readable === []
      ? ['status' => 'unavailable', 'certificates' => $certificates]
      : ['status' => 'ok', 'min_days_left' => min($readable), 'certificates' => $certificates];
    $this->runMonitoring(['get_ssl_status']);
  }

  /**
   * Every event, oldest first, as "type subject status".
   *
   * @return string[]
   *   Events.
   */
  private function events(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_adminops_event');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id')->execute();

    return array_values(array_map(static function ($event): string {
      $evidence = json_decode((string) $event->get('evidence_json')->value, TRUE);
      return $event->get('event_type')->value . ' ' . ($evidence['account'] ?? $evidence['domain'] ?? '') . ' ' . $event->get('status')->value;
    }, $storage->loadMultiple($ids)));
  }

}
