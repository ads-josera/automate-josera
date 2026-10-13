<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Sentinel's "cuentas" tool: usage per cPanel account (phase C).
 *
 * The scripted readings have the shape WHM returned on the production
 * server on 2026-10-13 (listaccts, emailtrack_user_stats).
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class SentinelAccountsTest extends AdminOpsKernelTestBase {

  private ContentEntityInterface $conversation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('ai_whatsapp_conversation');
    $this->conversation = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation')->create([
      'phone' => '+5215530050231',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
    ]);
    $this->conversation->save();

    $this->readings['get_accounts'] = ['status' => 'ok', 'whm' => [
      'metadata' => ['result' => 1, 'command' => 'listaccts'],
      'data' => ['acct' => [
        ['user' => 'humantz', 'domain' => 'humantz.com.mx', 'diskused' => '92M', 'disklimit' => '500M', 'suspended' => 0],
        ['user' => 'koemx', 'domain' => 'koe.com.mx', 'diskused' => '14630M', 'disklimit' => '21000M', 'suspended' => 0],
        ['user' => 'esim', 'domain' => 'esimbron.org', 'diskused' => '479M', 'disklimit' => '4500M', 'suspended' => 1],
        ['user' => 'intec', 'domain' => 'tsd.lat', 'diskused' => '1.5G', 'disklimit' => 'unlimited', 'suspended' => 0],
      ]],
    ]];
    $this->readings['get_mail_by_account'] = ['status' => 'ok',
      'last_hour' => ['metadata' => ['result' => 1], 'data' => ['records' => [
        ['USER' => 'intec', 'SENDCOUNT' => 2, 'SUCCESSCOUNT' => 2, 'FAILCOUNT' => 0, 'DEFERCOUNT' => 0, 'REACHED_MAXEMAILS' => 0],
      ]]],
      'last_day' => ['metadata' => ['result' => 1], 'data' => ['records' => [
        ['USER' => '-remote-', 'SENDCOUNT' => 284, 'SUCCESSCOUNT' => 247, 'FAILCOUNT' => 74, 'DEFERCOUNT' => 1, 'REACHED_MAXEMAILS' => 0],
        ['USER' => 'root', 'SENDCOUNT' => 224, 'SUCCESSCOUNT' => 0, 'FAILCOUNT' => 224, 'DEFERCOUNT' => 0, 'REACHED_MAXEMAILS' => 0],
        ['USER' => 'intec', 'SENDCOUNT' => 22, 'SUCCESSCOUNT' => 58, 'FAILCOUNT' => 0, 'DEFERCOUNT' => 0, 'REACHED_MAXEMAILS' => 0],
        ['USER' => 'koemx', 'SENDCOUNT' => 3, 'SUCCESSCOUNT' => 4, 'FAILCOUNT' => 9, 'DEFERCOUNT' => 0, 'REACHED_MAXEMAILS' => 1],
      ]]],
    ];
    $this->readings['get_account_processes'] = ['status' => 'ok', 'users' => [
      ['user' => 'koemx', 'cpu_percent' => 7.4, 'memory_mb' => 114, 'processes' => 6],
      ['user' => 'intec', 'cpu_percent' => 0.3, 'memory_mb' => 276, 'processes' => 4],
      ['user' => 'root', 'cpu_percent' => 9.2, 'memory_mb' => 1965, 'processes' => 120],
      ['user' => 'mysql', 'cpu_percent' => 2.0, 'memory_mb' => 789, 'processes' => 1],
    ]];
  }

  /**
   * One row per account joins disk, mail and processes; the server's own
   * senders and users are reported apart.
   */
  public function testTheReadingsAreJoinedPerAccount(): void {
    $report = $this->ask(['orden' => 'disco']);

    $this->assertSame(4, $report['total_cuentas']);
    $this->assertSame(1, $report['suspendidas']);
    $this->assertSame([], $report['lecturas_sin_dato']);
    $koemx = $this->row($report, 'koemx');
    $this->assertSame('koe.com.mx', $koemx['dominio'], 'José chose user and domain.');
    $this->assertSame(69.7, $koemx['disco_porcentaje']);
    $this->assertSame(7.4, $koemx['cpu_porcentaje']);
    $this->assertSame(['enviados' => 3, 'entregados' => 4, 'fallidos' => 9, 'diferidos' => 0, 'limite_alcanzado' => TRUE], $koemx['correo_ultimo_dia']);
    $this->assertNull($koemx['correo_ultima_hora'], 'No mail in the last hour is not zero: it is no record.');
    $this->assertTrue($this->row($report, 'esim')['suspendida']);
    $this->assertSame(1536.0, $this->row($report, 'intec')['disco_mb'], '"1.5G" is read as megabytes.');
    $this->assertNull($this->row($report, 'intec')['disco_porcentaje'], 'An unlimited quota has no percentage.');

    $this->assertSame(['-remote-', 'root'], array_keys($report['sistema']['correo_ultimo_dia']));
    $this->assertSame(['root', 'mysql'], array_keys($report['sistema']['procesos']));
    $this->assertArrayNotHasKey('root', array_column($report['cuentas'], NULL, 'cuenta'));
  }

  /**
   * Each order puts a different account first.
   */
  public function testOrders(): void {
    $this->assertSame('koemx', $this->ask(['orden' => 'disco'])['cuentas'][0]['cuenta']);
    $this->assertSame('intec', $this->ask(['orden' => 'correo'])['cuentas'][0]['cuenta']);
    $this->assertSame('koemx', $this->ask(['orden' => 'correo_fallido'])['cuentas'][0]['cuenta']);
    $this->assertSame('koemx', $this->ask(['orden' => 'cpu'])['cuentas'][0]['cuenta']);
    $this->assertSame('intec', $this->ask(['orden' => 'memoria'])['cuentas'][0]['cuenta']);
    $this->assertSame('intec', end($this->ask(['orden' => 'disco'])['cuentas'])['cuenta'], 'Without a percentage, last.');
  }

  /**
   * One account can be asked for by user or by domain.
   */
  public function testOneAccountByUserOrDomain(): void {
    $this->assertSame(['koemx'], array_column($this->ask(['cuenta' => 'koemx'])['cuentas'], 'cuenta'));
    $this->assertSame(['koemx'], array_column($this->ask(['cuenta' => 'KOE.com.mx'])['cuentas'], 'cuenta'));
    $this->assertArrayHasKey('error', $this->ask(['cuenta' => 'root']), 'root is not a cPanel account.');
  }

  /**
   * At most fifteen accounts, and the model is told how many were left out.
   */
  public function testTheListIsCapped(): void {
    for ($i = 1; $i <= 20; $i++) {
      $this->readings['get_accounts']['whm']['data']['acct'][] = ['user' => 'extra' . $i, 'domain' => "extra$i.mx", 'diskused' => "{$i}M", 'disklimit' => '1000M', 'suspended' => 0];
    }

    $report = $this->ask([]);

    $this->assertCount(15, $report['cuentas']);
    $this->assertSame(9, $report['cuentas_omitidas']);
  }

  /**
   * A reading that failed is named, so the model does not invent it.
   */
  public function testMissingReadingsAreNamed(): void {
    $this->readings['get_mail_by_account'] = ['status' => 'unavailable'];
    $this->readings['get_account_processes'] = new \RuntimeException('timeout');

    $report = $this->ask([]);

    $this->assertSame(['correo', 'procesos'], $report['lecturas_sin_dato']);
    $this->assertSame(4, $report['total_cuentas']);
  }

  /**
   * The three checks are audited as Sentinel's, at most once a minute.
   */
  public function testReadingsAreAuditedAndThrottled(): void {
    $this->ask(['orden' => 'disco']);
    $again = $this->ask(['orden' => 'cpu']);

    $this->assertSame('koemx', $again['cuentas'][0]['cuenta'], 'Another order within the minute is still answered.');
    foreach (['get_accounts', 'get_mail_by_account', 'get_account_processes'] as $check) {
      $this->assertSame(['succeeded'], $this->auditStatuses($check), "$check read once.");
    }
    $execution = current($this->container->get('entity_type.manager')->getStorage('ai_adminops_tool_execution')->loadByProperties(['tool_id' => 'get_accounts']));
    $this->assertSame('sentinel', json_decode($execution->get('parameters_json')->value, TRUE)['source']);
  }

  /**
   * The tool is offered, with its orders.
   */
  public function testTheToolIsOffered(): void {
    $definitions = array_column($this->container->get('ai_adminops.sentinel_server_tools')->definitions(), NULL, 'name');

    $this->assertArrayHasKey('cuentas', $definitions);
    $this->assertSame(['servidor'], $definitions['cuentas']['parameters']['required']);
    $this->assertSame(['disco', 'correo', 'correo_fallido', 'cpu', 'memoria'], $definitions['cuentas']['parameters']['properties']['orden']['enum']);
  }

  /**
   * Calls "cuentas" as the model would.
   */
  private function ask(array $arguments): array {
    // Each call reads fresh scripted data unless a test checks the cache.
    if ($this->name() !== 'testReadingsAreAuditedAndThrottled') {
      $this->container->get('state')->delete('ai_adminops.sentinel.accounts.produccion');
    }

    return $this->container->get('ai_adminops.sentinel_server_tools')->execute('cuentas', ['servidor' => 'produccion'] + $arguments, $this->conversation);
  }

  /**
   * Returns one account's row from a report.
   */
  private function row(array $report, string $user): array {
    return array_column($report['cuentas'], NULL, 'cuenta')[$user];
  }

}
