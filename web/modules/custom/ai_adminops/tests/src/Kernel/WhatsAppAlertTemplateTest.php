<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the seven values sent to the approved WhatsApp alert template.
 *
 * Template "josera_alerta_servidor" (2026-10-13): Nivel, Servidor, Afectado,
 * Problema, Detalle, Desde, Ver alerta. Twilio is intercepted: the test
 * reads the ContentVariables that would have been sent.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class WhatsAppAlertTemplateTest extends AdminOpsKernelTestBase {

  use CapturesWhatsAppAlerts;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->captureWhatsAppAlerts();
  }

  /**
   * A server-wide alert: short title, the measured summary as the detail.
   */
  public function testAServerWideAlert(): void {
    $this->record('cpu_usage_high', 'warning', 'Uso de CPU alta: 94% (umbral 90%).', 'La medicion supero el umbral configurado.', ['value' => 94, 'threshold' => 90]);

    $variables = $this->sentVariables();

    $this->assertSame('Advertencia', $variables['1']);
    $this->assertSame('Servidor de prueba', $variables['2']);
    $this->assertSame('todo el servidor', $variables['3']);
    $this->assertSame('Uso de CPU alto', $variables['4']);
    $this->assertSame('Uso de CPU alta: 94% (umbral 90%).', $variables['5']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $variables['6']);
    $this->assertStringContainsString('/admin/reports/ai-adminops/events/', $variables['7']);
  }

  /**
   * An alert about one cPanel account names it, with its domain.
   */
  public function testAnAccountAlertNamesTheAccount(): void {
    $this->record('account_mail_flood', 'critical', 'Correo masivo en intec.', 'Revisar la cuenta.', [
      'account' => 'intec',
      'domain' => 'indestec.net',
      'problem' => 'Posible correo masivo',
      'detail' => "480 correos en la última hora,\n210 fallidos",
    ]);

    $variables = $this->sentVariables();

    $this->assertSame('Crítica', $variables['1']);
    $this->assertSame('cuenta intec (indestec.net)', $variables['3']);
    $this->assertSame('Posible correo masivo', $variables['4']);
    $this->assertSame('480 correos en la última hora, 210 fallidos', $variables['5'], 'Meta rejects line breaks in a value.');
  }

  /**
   * An unknown type uses its summary as the title; no value is ever empty.
   */
  public function testNoValueIsEverEmpty(): void {
    $this->record('something_new', 'warning', 'Algo nuevo pasó.', '', []);

    $variables = $this->sentVariables();

    $this->assertSame('Algo nuevo pasó.', $variables['4']);
    $this->assertSame('Sin dato', $variables['5'], 'Meta rejects empty values.');
    $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($variables), 'PHP keys; Twilio receives {"1": …, "7": …}.');
    $this->assertNotContains('', $variables);
  }

  /**
   * The test button fills the same seven values.
   */
  public function testTheTestButtonFillsTheTemplate(): void {
    $settings = $this->config('ai_adminops.settings')->get('notifications.whatsapp');

    $this->container->get('ai_adminops.notification_bridge')->sendTest(['enabled' => FALSE], $settings);

    $variables = $this->sentVariables();
    $this->assertSame('Prueba', $variables['1']);
    $this->assertSame('Prueba de aviso de AdminOps', $variables['4']);
    $this->assertCount(7, $variables);
  }

  /**
   * Records an event through the event manager, which notifies.
   */
  private function record(string $type, string $severity, string $summary, string $details, array $evidence): void {
    $this->container->get('ai_adminops.event_manager')->record('produccion', $type, $severity, $summary, $details, $evidence);
  }

  /**
   * Returns the values of the one alert sent.
   *
   * @return array<string, string>
   *   Template values.
   */
  private function sentVariables(): array {
    $alerts = $this->sentAlerts();
    $this->assertCount(1, $alerts, 'One WhatsApp message to the one recipient.');

    return $alerts[0];
  }

}
