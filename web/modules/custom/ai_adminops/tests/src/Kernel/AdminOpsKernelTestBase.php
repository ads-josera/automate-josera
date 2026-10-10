<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use Drupal\ai_adminops\Service\MonitoringCollectorInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Shared setup: the module, one server, and a scripted stand-in for SSH.
 *
 * No test reaches a real server: the collector returns whatever readings a
 * test scripts, and records nothing outside the test database.
 */
abstract class AdminOpsKernelTestBase extends KernelTestBase {

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
    'ai_adminops',
  ];

  /**
   * Readings the stand-in collector returns, by tool ID.
   *
   * A value that is a \Throwable is thrown instead, as an unreachable server
   * would.
   *
   * @var array<string, array<string, mixed>|\Throwable>
   */
  protected array $readings = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_adminops_tool_execution');
    $this->installEntitySchema('ai_adminops_event');
    $this->installEntitySchema('ai_adminops_action_request');
    $this->installConfig(['ai_adminops']);

    $this->container->get('entity_type.manager')->getStorage('ai_adminops_server')->create([
      'id' => 'produccion',
      'label' => 'Servidor de prueba',
      'hostname' => 'servidor.example.com',
      'port' => 22,
      'connection_type' => 'ssh',
      'credential_reference' => 'observador',
      'active' => TRUE,
    ])->save();

    $test = $this;
    $this->container->set('ai_adminops.read_only_ssh_connector', new class($test) implements MonitoringCollectorInterface {

      public function __construct(private readonly AdminOpsKernelTestBase $test) {}

      public function isConfigured(ConfigEntityInterface $server): bool {
        return TRUE;
      }

      public function collect(ConfigEntityInterface $server, string $tool_id): array {
        $reading = $this->test->reading($tool_id);
        if ($reading instanceof \Throwable) {
          throw $reading;
        }
        return $reading;
      }

    });
  }

  /**
   * Returns the scripted reading for a tool.
   *
   * @return array<string, mixed>|\Throwable
   *   The reading, or the error to throw.
   */
  public function reading(string $tool_id): array|\Throwable {
    return $this->readings[$tool_id] ?? ['status' => 'unavailable'];
  }

  /**
   * Runs one monitoring job for the test server.
   *
   * @param string[] $tools
   *   Tool IDs to read.
   */
  protected function runMonitoring(array $tools): void {
    $this->container->get('ai_adminops.monitoring_job_processor')->process([
      'server_id' => 'produccion',
      'tools' => $tools,
      'scheduled_at' => 0,
    ]);
  }

  /**
   * Returns the audit statuses recorded for a tool, oldest first.
   *
   * @return string[]
   *   Statuses.
   */
  protected function auditStatuses(string $tool_id): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_adminops_tool_execution');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('tool_id', $tool_id)->sort('id')->execute();

    return array_values(array_map(static fn ($execution) => (string) $execution->get('status')->value, $storage->loadMultiple($ids)));
  }

}
