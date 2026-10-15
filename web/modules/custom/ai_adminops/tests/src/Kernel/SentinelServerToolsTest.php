<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use Drupal\ai_adminops\Service\SentinelServerTools;
use Drupal\Core\Entity\ContentEntityInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Sentinel's server tools: read only, audited, never secret.
 *
 * Who may call them is tested in ai_whatsapp_automation's OwnerToolsTest;
 * here the provider is called directly.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class SentinelServerToolsTest extends AdminOpsKernelTestBase {

  private SentinelServerTools $tools;

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
    $this->tools = $this->container->get('ai_adminops.sentinel_server_tools');
  }

  /**
   * The owner tool runner picks the provider up from its tag.
   */
  public function testTheRunnerOffersTheseTools(): void {
    $runner = $this->container->get('ai_whatsapp_automation.owner_tool_runner');
    $providers = (new \ReflectionProperty($runner, 'providers'))->getValue($runner);

    $this->assertContains($this->tools, $providers);
    $this->assertSame(['servidores', 'estado_servidor', 'eventos_abiertos', 'historial', 'cuentas', 'historial_cuenta', 'leer_ahora'], array_column($this->tools->definitions(), 'name'));
  }

  /**
   * The model can only name active servers, and learns nothing secret.
   */
  public function testOnlyActiveServersWithoutSecrets(): void {
    $this->container->get('entity_type.manager')->getStorage('ai_adminops_server')->create([
      'id' => 'apagado',
      'label' => 'Servidor apagado',
      'hostname' => 'apagado.example.com',
      'credential_reference' => 'otro',
      'active' => FALSE,
    ])->save();

    $definitions = array_column($this->tools->definitions(), NULL, 'name');
    $this->assertSame(['produccion'], $definitions['estado_servidor']['parameters']['properties']['servidor']['enum']);

    $list = $this->tools->execute('servidores', [], $this->conversation);
    $this->assertSame(['produccion'], array_column($list['servidores'], 'id'));
    $this->assertStringNotContainsString('observador', json_encode($list), 'The credential reference never reaches the model.');

    $this->expectException(\InvalidArgumentException::class);
    $this->tools->execute('estado_servidor', ['servidor' => 'apagado'], $this->conversation);
  }

  /**
   * Without any active server there is no empty enum, which OpenAI rejects.
   */
  public function testNoServersMeansNoEmptyEnum(): void {
    $this->container->get('entity_type.manager')->getStorage('ai_adminops_server')->load('produccion')->set('active', FALSE)->save();

    $checked = 0;
    foreach ($this->tools->definitions() as $definition) {
      $properties = (array) $definition['parameters']['properties'];
      if (isset($properties['servidor'])) {
        $this->assertArrayNotHasKey('enum', $properties['servidor'], $definition['name']);
        $checked++;
      }
    }
    $this->assertSame(6, $checked, 'Every tool that names a server, "cuentas" and "historial_cuenta" included.');
  }

  /**
   * The stored state is read without connecting to the server.
   */
  public function testStatusReadsTheLastScheduledReading(): void {
    $this->readings['get_disk_usage'] = ['status' => 'ok', 'used_percent' => 55];
    $this->runMonitoring(['get_disk_usage']);
    $audited = count($this->auditStatuses('get_disk_usage'));

    $status = $this->tools->execute('estado_servidor', ['servidor' => 'produccion'], $this->conversation);

    $this->assertSame(55, $status['revisiones']['get_disk_usage']['used_percent']);
    $this->assertSame(85, $status['umbrales_de_alerta']['disk_percent_warning']);
    $this->assertSame(0, $status['leido']['hace_minutos']);
    $this->assertCount($audited, $this->auditStatuses('get_disk_usage'), 'No new reading was taken.');
  }

  /**
   * A live reading is audited as Sentinel's, at most once a minute.
   */
  public function testLiveReadingIsAuditedAndThrottled(): void {
    $this->readings['get_server_load'] = ['status' => 'ok', 'load_1m' => 0.5];
    $this->readings['get_cpu_usage'] = new \RuntimeException('timeout');

    $result = $this->tools->execute('leer_ahora', ['servidor' => 'produccion'], $this->conversation);

    $this->assertSame(0.5, $result['revisiones']['get_server_load']['load_1m']);
    $this->assertSame(['status' => 'failed'], $result['revisiones']['get_cpu_usage']);
    $this->assertSame(['succeeded'], $this->auditStatuses('get_server_load'));
    $this->assertSame(['failed'], $this->auditStatuses('get_cpu_usage'));
    $this->assertSame(['no_data'], $this->auditStatuses('get_exim_queue'));
    $this->assertArrayHasKey('get_reboot_status', $result['revisiones'], 'The reboot check is part of a live reading.');
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_adminops_tool_execution');
    $execution = current($storage->loadByProperties(['tool_id' => 'get_server_load']));
    $this->assertSame(['source' => 'sentinel', 'conversation' => (string) $this->conversation->id()], json_decode($execution->get('parameters_json')->value, TRUE));

    $again = $this->tools->execute('leer_ahora', ['servidor' => 'produccion'], $this->conversation);
    $this->assertArrayHasKey('aviso', $again);
    $this->assertCount(1, $this->auditStatuses('get_server_load'), 'Asked again within a minute: no second connection.');
  }

  /**
   * Live readings never open alerts: those follow the schedule only.
   */
  public function testLiveReadingsDoNotOpenAlerts(): void {
    $this->readings['get_server_load'] = ['status' => 'ok', 'load_1m' => 50];

    $this->tools->execute('leer_ahora', ['servidor' => 'produccion'], $this->conversation);
    $this->container->get('state')->delete('ai_adminops.sentinel.live.produccion');
    $this->tools->execute('leer_ahora', ['servidor' => 'produccion'], $this->conversation);

    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('ai_adminops_event')->loadMultiple());
  }

  /**
   * Open and acknowledged events are listed; resolved ones are not.
   */
  public function testOpenEvents(): void {
    $events = $this->container->get('ai_adminops.event_manager');
    $events->record('produccion', 'disk_usage_high', 'warning', 'Disco al 90 %');
    $resolved = $events->record('produccion', 'cpu_usage_high', 'warning', 'CPU al 95 %');
    $events->resolve((int) $resolved->id());

    $list = $this->tools->execute('eventos_abiertos', [], $this->conversation)['eventos'];

    $this->assertSame(['Disco al 90 %'], array_column($list, 'resumen'));
    $this->assertSame('produccion', $list[0]['servidor']);
  }

  /**
   * History returns the newest readings, never more than twelve.
   */
  public function testHistoryIsCapped(): void {
    for ($i = 1; $i <= 15; $i++) {
      $this->readings['get_memory_usage'] = ['status' => 'ok', 'used_percent' => $i];
      $this->runMonitoring(['get_memory_usage']);
    }

    $history = $this->tools->execute('historial', ['servidor' => 'produccion', 'revision' => 'get_memory_usage', 'lecturas' => 100], $this->conversation);

    $this->assertCount(12, $history['lecturas']);
    $this->assertSame(15, $history['lecturas'][0]['resultado']['used_percent'], 'Newest first.');
  }

  /**
   * Readings up to a given local time ("how was it at 2 am?").
   */
  public function testHistoryUpToATime(): void {
    for ($i = 1; $i <= 6; $i++) {
      $this->readings['get_memory_usage'] = ['status' => 'ok', 'used_percent' => $i];
      $this->runMonitoring(['get_memory_usage']);
    }
    // Spread the six readings one hour apart, ending now.
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_adminops_tool_execution');
    $now = $this->container->get('datetime.time')->getRequestTime();
    $executions = array_values($storage->loadByProperties(['tool_id' => 'get_memory_usage']));
    foreach ($executions as $index => $execution) {
      $execution->set('completed_at', $now - (5 - $index) * 3600)->save();
    }
    $until = date('Y-m-d H:i', $now - 3 * 3600 + 60);

    $history = $this->tools->execute('historial', ['servidor' => 'produccion', 'revision' => 'get_memory_usage', 'lecturas' => 2, 'hasta' => $until], $this->conversation);

    $this->assertSame($until, $history['hasta']);
    $this->assertSame([3, 2], array_map(static fn (array $reading) => $reading['resultado']['used_percent'], $history['lecturas']), 'The two readings up to that time, newest first.');
    $this->assertArrayHasKey('error', $this->tools->execute('historial', ['servidor' => 'produccion', 'revision' => 'get_memory_usage', 'hasta' => 'ayer a las 2'], $this->conversation));
  }

}
