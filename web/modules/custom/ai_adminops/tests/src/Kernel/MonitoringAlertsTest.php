<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that alerts follow real problems and ignore one-off spikes.
 *
 * The readings mirror production data from 2026-08-11 to 2026-09-16: load
 * spikes during the 02:00 backups lasted one reading, and the one sustained
 * episode (2026-09-03, 15:23 and 15:38) lasted two.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class MonitoringAlertsTest extends AdminOpsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('ai_adminops.settings')
      ->set('alerts.enabled', TRUE)
      ->set('alerts.unreachable_enabled', TRUE)
      ->set('alerts.consecutive_readings', 2)
      ->save();
  }

  /**
   * A single backup spike opens nothing; a sustained load does, then closes.
   */
  public function testOnlyASustainedLoadOpensAnEvent(): void {
    $this->load(20.53);
    $this->assertSame([], $this->events(), 'One reading above the threshold is a spike, not a problem.');

    $this->load(1.09);
    $this->load(5.72);
    $this->assertSame([], $this->events(), 'A spike after a normal reading is still a spike.');

    $this->load(4.29);
    $this->assertSame(['server_load_high:open'], $this->events(), 'Two readings in a row above 4 are a problem.');

    $this->load(6.0);
    $this->assertSame(['server_load_high:open'], $this->events(), 'Still one event, not a new one per reading.');

    $this->load(0.5);
    $this->assertSame(['server_load_high:resolved'], $this->events(), 'It closes on the first normal reading.');
  }

  /**
   * A reading with no data between two high ones breaks the run.
   */
  public function testAGapInTheReadingsBreaksTheRun(): void {
    $this->load(9.0);
    $this->readings['get_server_load'] = ['status' => 'unavailable'];
    $this->runMonitoring(['get_server_load']);
    $this->load(9.0);

    $this->assertSame([], $this->events(), 'Nothing is known about the middle reading, so it is not a run.');
  }

  /**
   * One reading is enough when the setting asks for one.
   */
  public function testOneReadingAlertsWhenConfiguredSo(): void {
    $this->config('ai_adminops.settings')->set('alerts.consecutive_readings', 1)->save();

    $this->load(20.53);

    $this->assertSame(['server_load_high:open'], $this->events());
  }

  /**
   * One dropped connection is not an outage; two in a row are.
   */
  public function testUnreachableNeedsJobsInARow(): void {
    $this->readings['get_server_load'] = new \RuntimeException('Connection timed out.');

    $this->runMonitoring(['get_server_load']);
    $this->assertSame([], $this->events(), 'A single failed job.');

    $this->runMonitoring(['get_server_load']);
    $this->assertSame(['server_unreachable:open'], $this->events());

    $this->load(1.0);
    $this->assertSame(['server_unreachable:resolved'], $this->events(), 'It closes when the server answers.');

    $this->readings['get_server_load'] = new \RuntimeException('Connection timed out.');
    $this->runMonitoring(['get_server_load']);
    $this->assertSame(['server_unreachable:resolved'], $this->events(), 'The count started again after the server answered.');
  }

  /**
   * Runs one load reading.
   */
  private function load(float $value): void {
    $this->readings['get_server_load'] = ['status' => 'ok', 'load_1m' => $value];
    $this->runMonitoring(['get_server_load']);
  }

  /**
   * Returns every event as "type:status", oldest first.
   *
   * @return string[]
   *   Events.
   */
  private function events(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_adminops_event');
    $storage->resetCache();
    $events = [];
    foreach ($storage->loadMultiple() as $event) {
      $events[] = $event->get('event_type')->value . ':' . $event->get('status')->value;
    }

    return $events;
  }

}
