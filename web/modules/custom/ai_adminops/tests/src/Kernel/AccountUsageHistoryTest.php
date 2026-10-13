<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use Drupal\ai_adminops\Service\AccountUsageHistory;
use Drupal\Core\Entity\ContentEntityInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the per-account usage history (phase C2).
 *
 * The scheduled monitoring stores one row per cPanel account; Sentinel's
 * "historial_cuenta" summarizes it. No real server is read.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class AccountUsageHistoryTest extends AdminOpsKernelTestBase {

  private ContentEntityInterface $conversation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ai_adminops', ['ai_adminops_account_usage']);
    $this->installEntitySchema('ai_whatsapp_conversation');
    $this->conversation = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation')->create([
      'phone' => '+5215530050231',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
    ]);
    $this->conversation->save();
    $this->scriptAccountReadings();
  }

  /**
   * A scheduled job stores one row per account, beside the server checks.
   */
  public function testTheScheduledJobStoresEveryAccount(): void {
    $this->readings['get_disk_usage'] = ['status' => 'ok', 'used_percent' => 55];

    $this->runScheduledJob(TRUE);

    $rows = $this->rows();
    $this->assertSame(['esim', 'humantz', 'intec', 'koemx'], array_keys($rows));
    $this->assertSame('14630', (string) (float) $rows['koemx']->disk_mb, 'WHM\'s exact megabytes, not the rounded GB.');
    $this->assertSame('7.4', (string) (float) $rows['koemx']->cpu_percent);
    $this->assertSame('0', (string) $rows['koemx']->mail_sent, 'No mail in the last hour is stored as zero.');
    $this->assertSame('2', (string) $rows['intec']->mail_sent);
    $this->assertNull($rows['intec']->quota_mb, 'Unlimited quota.');
    $this->assertSame('1', (string) $rows['esim']->suspended);
    $this->assertSame(['succeeded'], $this->auditStatuses('get_disk_usage'), 'The server checks still run.');

    $execution = current($this->container->get('entity_type.manager')->getStorage('ai_adminops_tool_execution')->loadByProperties(['tool_id' => 'get_mail_by_account']));
    $result = json_decode($execution->get('result')->value, TRUE);
    $this->assertArrayNotHasKey('last_day', $result, 'The log keeps a summary, not WHM\'s full answer.');
    $this->assertSame('scheduled_monitoring', json_decode($execution->get('parameters_json')->value, TRUE)['source']);
  }

  /**
   * Jobs queued before phase C2 do not read the accounts.
   */
  public function testOldJobsDoNotReadAccounts(): void {
    $this->runScheduledJob(FALSE);

    $this->assertSame([], $this->rows());
    $this->assertSame([], $this->auditStatuses('get_accounts'));
  }

  /**
   * The scheduler asks every job to read the accounts.
   */
  public function testTheSchedulerAsksForAccounts(): void {
    $this->config('ai_adminops.settings')->set('monitoring.enabled', TRUE)->save();

    $this->container->get('ai_adminops.monitoring_scheduler')->queueDueChecks(TRUE);

    $item = $this->container->get('queue')->get('ai_adminops_monitoring')->claimItem();
    $this->assertTrue($item->data['accounts']);
  }

  /**
   * A failed account read never costs the server checks.
   */
  public function testAFailedAccountReadKeepsTheServerChecks(): void {
    $this->readings['get_disk_usage'] = ['status' => 'ok', 'used_percent' => 55];
    $this->readings['get_accounts'] = new \RuntimeException('timeout');

    $this->runScheduledJob(TRUE);

    $this->assertSame([], $this->rows(), 'Without the account list there are no rows.');
    $this->assertSame(['failed'], $this->auditStatuses('get_accounts'));
    $state = $this->container->get('state')->get('ai_adminops.monitoring.server.produccion');
    $this->assertSame('completed', $state['status']);
    $this->assertSame(55, $state['checks']['get_disk_usage']['used_percent']);
  }

  /**
   * Even if storing the history fails, the server checks are kept.
   */
  public function testAStorageFailureKeepsTheServerChecks(): void {
    $this->readings['get_disk_usage'] = ['status' => 'ok', 'used_percent' => 55];
    $this->container->get('database')->schema()->dropTable('ai_adminops_account_usage');

    $this->runScheduledJob(TRUE);

    $state = $this->container->get('state')->get('ai_adminops.monitoring.server.produccion');
    $this->assertSame('completed', $state['status']);
    $this->assertSame(55, $state['checks']['get_disk_usage']['used_percent']);
  }

  /**
   * Rows older than thirty days are dropped on the next reading.
   */
  public function testOldRowsAreDropped(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $history = $this->container->get('ai_adminops.account_usage_history');
    $history->record('produccion', [$this->row('viejo', 1.0, 0)], $now - 31 * 86400);
    $history->record('produccion', [$this->row('reciente', 1.0, 0)], $now - 29 * 86400);

    $this->assertSame(['reciente'], array_keys($this->rows()));
  }

  /**
   * One account: peaks, averages, disk trend and when the peaks were.
   */
  public function testOneAccountIsSummarized(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $history = $this->container->get('ai_adminops.account_usage_history');
    $history->record('produccion', [$this->row('koemx', 10.0, 2, 14000)], $now - 3 * 3600);
    $history->record('produccion', [$this->row('koemx', 80.0, 40, 14500)], $now - 2 * 3600);
    $history->record('produccion', [$this->row('koemx', 30.0, 6, 14600)], $now - 3600);
    $history->record('produccion', [$this->row('koemx', 99.0, 500, 9000)], $now - 3 * 86400);

    $summary = $this->ask(['cuenta' => 'koemx', 'horas' => 24]);

    $this->assertSame(3, $summary['lecturas'], 'Only the period asked for.');
    $this->assertSame(80.0, $summary['cpu_max']);
    $this->assertSame(40.0, $summary['cpu_promedio']);
    $this->assertSame(1, $summary['lecturas_cpu_50_o_mas']);
    $this->assertSame(40, $summary['correo_max_en_una_hora']);
    $this->assertSame(16.0, $summary['correo_promedio_por_hora']);
    $this->assertSame(13.67, $summary['disco_gb_al_inicio']);
    $this->assertSame(14.26, $summary['disco_gb_al_final']);
    $date = $this->container->get('date.formatter')->format($now - 2 * 3600, 'custom', 'Y-m-d H:i');
    $this->assertSame($date, $summary['hora_de_mas_correo']);
    $this->assertSame($date, $summary['hora_de_mas_cpu']);
  }

  /**
   * Without an account, the busiest ones over the period, in order.
   */
  public function testTheBusiestAccountsAreRanked(): void {
    $now = $this->container->get('datetime.time')->getRequestTime();
    $history = $this->container->get('ai_adminops.account_usage_history');
    $history->record('produccion', [
      $this->row('koemx', 70.0, 3),
      $this->row('intec', 5.0, 120),
      $this->row('mako', 20.0, 9),
    ], $now - 3600);

    $this->assertSame(['intec', 'mako', 'koemx'], array_column($this->ask(['horas' => 168])['cuentas'], 'cuenta'));
    $this->assertSame(['koemx', 'mako', 'intec'], array_column($this->ask(['horas' => 168, 'orden' => 'cpu'])['cuentas'], 'cuenta'));
  }

  /**
   * Empty history and unknown accounts are said plainly.
   */
  public function testNothingToSummarizeIsSaid(): void {
    $this->assertStringContainsString('Todavía no hay historial', $this->ask(['horas' => 24])['error']);

    $this->container->get('ai_adminops.account_usage_history')->record('produccion', [$this->row('koemx', 1.0, 0)], $this->container->get('datetime.time')->getRequestTime());
    $this->assertStringContainsString('No hay historial de esa cuenta', $this->ask(['cuenta' => 'koe.com.mx', 'horas' => 24])['error']);
  }

  /**
   * The period is kept within what is stored.
   */
  public function testThePeriodIsClamped(): void {
    $definitions = array_column($this->container->get('ai_adminops.sentinel_server_tools')->definitions(), NULL, 'name');
    $this->assertSame(['servidor', 'horas'], $definitions['historial_cuenta']['parameters']['required']);
    $this->assertSame(720, $definitions['historial_cuenta']['parameters']['properties']['horas']['maximum']);

    $this->assertSame(720, $this->ask(['horas' => 100000])['horas']);
    $this->assertSame(1, $this->ask(['horas' => -5])['horas']);
  }

  /**
   * Calls "historial_cuenta" as the model would.
   */
  private function ask(array $arguments): array {
    return $this->container->get('ai_adminops.sentinel_server_tools')->execute('historial_cuenta', ['servidor' => 'produccion'] + $arguments, $this->conversation);
  }

  /**
   * Runs one monitoring job, with or without the account reading.
   */
  private function runScheduledJob(bool $accounts): void {
    $this->container->get('ai_adminops.monitoring_job_processor')->process([
      'server_id' => 'produccion',
      'tools' => ['get_disk_usage'],
      'accounts' => $accounts,
      'scheduled_at' => 0,
    ]);
  }

  /**
   * Builds an AccountUsageReport row for an account.
   */
  private function row(string $user, float $cpu, int $mail_sent, int $disk_mb = 1000): array {
    return [
      'cuenta' => $user,
      'suspendida' => FALSE,
      'disco_mb' => (float) $disk_mb,
      'cuota_mb' => 20480.0,
      'disco_gb' => round($disk_mb / 1024, 2),
      'cuota_gb' => 20.0,
      'correo_ultima_hora' => ['enviados' => $mail_sent, 'entregados' => $mail_sent, 'fallidos' => 0, 'diferidos' => 0, 'limite_alcanzado' => FALSE],
      'cpu_porcentaje' => $cpu,
      'memoria_mb' => 100,
      'procesos' => 1,
    ];
  }

  /**
   * Stored rows by account.
   *
   * @return array<string, object>
   *   The newest row of each account.
   */
  private function rows(): array {
    return $this->container->get('database')->select('ai_adminops_account_usage', 'u')
      ->fields('u')
      ->orderBy('account')
      ->execute()
      ->fetchAllAssoc('account');
  }

}
