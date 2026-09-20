<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Unit\Webhook;

use Drupal\ai_whatsapp_automation\Application\Webhook\WhatsAppTextFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests conversion of assistant Markdown to WhatsApp formatting.
 */
#[CoversClass(WhatsAppTextFormatter::class)]
#[Group('ai_whatsapp_automation')]
final class WhatsAppTextFormatterTest extends TestCase {

  /**
   * WhatsApp bold is a single asterisk; Markdown bold uses two.
   */
  #[DataProvider('textProvider')]
  public function testFormat(string $markdown, string $expected): void {
    $this->assertSame($expected, (new WhatsAppTextFormatter())->format($markdown));
  }

  /**
   * Provides Markdown and the expected WhatsApp text.
   *
   * @return array<string, array{string, string}>
   *   Input and expected output.
   */
  public static function textProvider(): array {
    return [
      'production reply' => [
        "Datos recibidos\n\n🏢 **Empresa:** Jerotracker\n📦 **Mercancía:** Guitarras",
        "Datos recibidos\n\n🏢 *Empresa:* Jerotracker\n📦 *Mercancía:* Guitarras",
      ],
      'markdown headings become bold lines' => ["### Coberturas\n- Robo", "*Coberturas*\n- Robo"],
      'underscore bold' => ['__Importante__: aplica', '*Importante*: aplica'],
      'single asterisks are left alone' => ['ya es *negrita* en WhatsApp', 'ya es *negrita* en WhatsApp'],
      'unbalanced markers are left alone' => ['precio 2**3 y **abierto', 'precio 2**3 y **abierto'],
      'plain text' => ['Hola, ¿en qué te ayudo?', 'Hola, ¿en qué te ayudo?'],
      // WhatsApp only draws the first level of a list, so an indented item
      // used to arrive with its dash showing.
      'nested items get their own marker' => [
        "- Todo riesgo\n  - Caída de contenedor\n  - Rotura en tránsito",
        "- Todo riesgo\n  ◦ Caída de contenedor\n  ◦ Rotura en tránsito",
      ],
      'nested items written with an asterisk' => [
        "* Robo\n    * Sustracción en carretera",
        "* Robo\n    ◦ Sustracción en carretera",
      ],
      'the first level keeps its dash' => ["- Robo\n- Avería", "- Robo\n- Avería"],
      'nested bold still converts' => [
        "- Cobertura\n  - **Ejemplo:** caída",
        "- Cobertura\n  ◦ *Ejemplo:* caída",
      ],
      'an indented sentence is not a list' => ["  no es lista", "  no es lista"],
      'a dash inside a sentence is untouched' => ['pago 30-60 días', 'pago 30-60 días'],
    ];
  }

}
