<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the audit log tells what each reading actually brought back.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class MonitoringAuditTest extends AdminOpsKernelTestBase {

  /**
   * A reading without measurements is "no data", not a success.
   *
   * For two months the Exim and SSL checks answered "unavailable" and
   * "not_configured" 3,416 times each, and the log called every one of them
   * a success.
   */
  public function testReadingsWithoutMeasurementsAreNotSuccesses(): void {
    $this->readings = [
      'get_server_load' => ['status' => 'ok', 'load_1m' => 1.09],
      'get_exim_queue' => ['status' => 'unavailable'],
      'get_ssl_status' => ['status' => 'not_configured', 'message' => 'No SSL endpoint configured for this server.'],
      'get_disk_usage' => new \RuntimeException('Connection timed out.'),
    ];

    $this->runMonitoring(['get_server_load', 'get_exim_queue', 'get_ssl_status', 'get_disk_usage']);

    $this->assertSame(['succeeded'], $this->auditStatuses('get_server_load'));
    $this->assertSame(['no_data'], $this->auditStatuses('get_exim_queue'));
    $this->assertSame(['no_data'], $this->auditStatuses('get_ssl_status'));
    $this->assertSame(['failed'], $this->auditStatuses('get_disk_usage'));
  }

  /**
   * A server that answers without measurements is still reachable.
   *
   * "No data" is the remote check's answer, so it must not raise a
   * "server unreachable" alarm.
   */
  public function testAnswerWithoutDataMeansTheServerIsReachable(): void {
    $this->config('ai_adminops.settings')->set('alerts.enabled', TRUE)->set('alerts.unreachable_enabled', TRUE)->save();
    $this->readings = ['get_exim_queue' => ['status' => 'unavailable']];

    $this->runMonitoring(['get_exim_queue']);

    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('ai_adminops_event')->loadMultiple());
  }

  /**
   * Update 10005 relabels only past readings that had no measurements.
   */
  public function testUpdateRelabelsOnlyReadingsWithoutData(): void {
    $audit = $this->container->get('ai_adminops.execution_audit');
    $record = function (string $tool_id, array $result) use ($audit): int {
      $execution = $audit->begin('produccion', $tool_id, $tool_id, []);
      $audit->markSucceeded((int) $execution->id(), $result);
      return (int) $execution->id();
    };
    $load = $record('get_server_load', ['status' => 'ok', 'load_1m' => 0.16]);
    $exim = $record('get_exim_queue', ['status' => 'unavailable']);
    $ssl = $record('get_ssl_status', ['status' => 'not_configured']);

    \Drupal::moduleHandler()->loadInclude('ai_adminops', 'install');
    ai_adminops_update_10005();

    $storage = $this->container->get('entity_type.manager')->getStorage('ai_adminops_tool_execution');
    $this->assertSame('succeeded', $storage->loadUnchanged($load)->get('status')->value, 'A reading with values stays a success.');
    $this->assertSame('no_data', $storage->loadUnchanged($exim)->get('status')->value);
    $this->assertSame('no_data', $storage->loadUnchanged($ssl)->get('status')->value);
    $this->assertStringContainsString('unavailable', (string) $storage->loadUnchanged($exim)->get('result')->value, 'The stored result is untouched.');
  }

}
