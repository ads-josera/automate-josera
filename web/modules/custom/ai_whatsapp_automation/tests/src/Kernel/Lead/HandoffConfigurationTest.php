<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Lead;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which lead handoff settings can ever create a lead.
 *
 * The bot form refuses settings that cannot; these tests pin down that it
 * keeps accepting every configuration that works today, starting with JG's.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class HandoffConfigurationTest extends KernelTestBase {

  /**
   * JG Mylard's signal groups, copied from production on 2026-10-10.
   */
  private const JG_GROUPS = "empresa, negocio, cliente, razón social\n"
    . "mercancía, mercancia, producto, carga, bienes\n"
    . "origen, salida, país origen, ciudad origen\n"
    . "destino, entrega, país destino, ciudad destino\n"
    . "medio, transporte, terrestre, marítimo, maritimo, aéreo, aereo, multimodal\n"
    . "valor, monto, suma asegurada, usd, mxn, dólares, pesos\n"
    . "frecuencia, embarques, mensual, semanal, ocasional, volumen\n"
    . "contacto, teléfono, telefono, whatsapp, correo, email, @";

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
    $this->installEntitySchema('ai_whatsapp_account');
    $this->installEntitySchema('ai_whatsapp_bot');
  }

  /**
   * JG's configuration stays acceptable: 8 groups, minimum 6.
   *
   * JG Mylard is in production and collects every one of these details for
   * its insurance quotes; the validation must never get in its way.
   */
  public function testJgMylardConfigurationIsAccepted(): void {
    $jg = $this->bot(self::JG_GROUPS, 6);

    $this->assertSame(8, $this->handoff()->signalGroupCount($jg));
    $this->assertTrue($this->handoff()->canEverCreateLead($jg));
  }

  /**
   * Every other working configuration of 2026-10-10 stays acceptable.
   */
  public function testOtherWorkingConfigurationsAreAccepted(): void {
    $this->assertTrue($this->handoff()->canEverCreateLead($this->bot("nombre\nmotivo, detalle", 2)), 'Hermes.');
    $this->assertTrue($this->handoff()->canEverCreateLead($this->bot('', NULL)), 'No settings: the built-in groups and minimum.');
    $this->assertTrue($this->handoff()->canEverCreateLead($this->bot("uno\ndos\ntres", 3)), 'A minimum equal to the groups.');
  }

  /**
   * A minimum above the groups is refused: that bot never creates a lead.
   */
  public function testImpossibleConfigurationsAreRefused(): void {
    $jvc = $this->bot('paciente, médico, estudio, biopsia, muestra, costo, teléfono, correo, @', 5);
    $this->assertSame(1, $this->handoff()->signalGroupCount($jvc), 'A list on one line is one group.');
    $this->assertFalse($this->handoff()->canEverCreateLead($jvc), 'Laboratorio JVC, 2026-09-24.');

    $this->assertFalse($this->handoff()->canEverCreateLead($this->bot("nombre\nmotivo, detalle", 5)), 'Hermes as first saved, 2026-10-10.');
  }

  /**
   * Creates an unsaved bot with the given handoff settings.
   */
  private function bot(string $groups, ?int $minimum): ContentEntityInterface {
    $values = ['name' => 'Bot', 'status' => 'active', 'handoff_enabled' => TRUE, 'handoff_required_fields' => $groups];
    if ($minimum !== NULL) {
      $values['handoff_minimum_fields'] = $minimum;
    }

    return $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create($values);
  }

  /**
   * Returns the lead handoff service.
   */
  private function handoff(): object {
    return $this->container->get('ai_whatsapp_automation.lead_handoff');
  }

}
