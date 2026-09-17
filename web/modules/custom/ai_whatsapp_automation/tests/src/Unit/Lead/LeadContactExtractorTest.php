<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Unit\Lead;

use Drupal\ai_whatsapp_automation\Application\Lead\LeadContactExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests contact data extraction from chat transcripts.
 */
#[CoversClass(LeadContactExtractor::class)]
#[Group('ai_whatsapp_automation')]
final class LeadContactExtractorTest extends TestCase {

  /**
   * Transcript of the first production lead (conversation 28).
   *
   * The assistant asked for "Nombre y teléfono de contacto:" and the next line
   * was the "Correo electrónico:" label. The old extractor crossed the line
   * break and stored that label as the lead name.
   */
  private const PRODUCTION_LEAD_TRANSCRIPT = <<<'TEXT'
contact: Necesito una cotizacion
ai: Cotización — información necesaria

📞 **Nombre y teléfono de contacto:**
📧 **Correo electrónico:**
contact: Jerotracker, guitarras, paracho Michoacán, a CDMX, terrestres, valor 50, 000 USD, tel. 5512309140, frecuencia 4 embarcaciones por mes
ai: Datos recibidos — falta información

🏢 **Empresa:** Jerotracker
📞 **Teléfono:** 55 1230 9140

👤 **Nombre del contacto:**
📧 **Correo electrónico:**
TEXT;

  /**
   * An empty label must never be captured as a name.
   */
  public function testProductionLeadHasNoName(): void {
    $this->assertSame('', (new LeadContactExtractor())->extractName(self::PRODUCTION_LEAD_TRANSCRIPT));
  }

  /**
   * A Mexican ten-digit number becomes +52, not +55 (Brazil).
   */
  public function testProductionLeadPhoneUsesMexicanPrefix(): void {
    $this->assertSame('+525512309140', (new LeadContactExtractor())->extractPhone(self::PRODUCTION_LEAD_TRANSCRIPT));
  }

  /**
   * Names are read from the same line as their label.
   */
  #[DataProvider('nameProvider')]
  public function testExtractName(string $text, string $expected): void {
    $this->assertSame($expected, (new LeadContactExtractor())->extractName($text));
  }

  /**
   * Provides name extraction cases.
   *
   * @return array<string, array{string, string}>
   *   Text and expected name.
   */
  public static function nameProvider(): array {
    return [
      'labelled name with markdown and icon' => ["ai: 👤 **Nombre del contacto:** Mariana López", 'Mariana López'],
      'plain name label' => ["contact: nombre: Juan Pérez", 'Juan Pérez'],
      'contacto label with a real name' => ["ai: **Contacto:** Ana Ruiz", 'Ana Ruiz'],
      'contacto label followed by another label' => ["ai: **Contacto:** 📧 Correo electrónico", ''],
      'value that is only another label' => ["ai: Nombre: Teléfono:", ''],
      'name preferred over contacto' => ["ai: Contacto: 5512309140\nai: Nombre: Luis Gómez", 'Luis Gómez'],
      'contact value that is a phone' => ["ai: Contacto: 55 1230 9140", ''],
      'contact value that is an email' => ["ai: Contacto: ana@example.com", ''],
      'no label at all' => ["contact: hola buenas tardes", ''],
    ];
  }

  /**
   * Phones are normalized to E.164 when the country can be inferred.
   */
  #[DataProvider('phoneProvider')]
  public function testExtractPhone(string $text, string $expected): void {
    $this->assertSame($expected, (new LeadContactExtractor())->extractPhone($text));
  }

  /**
   * Provides phone extraction cases.
   *
   * @return array<string, array{string, string}>
   *   Text and expected phone.
   */
  public static function phoneProvider(): array {
    return [
      'ten digits with spaces' => ['mi cel es 55 1230 9140', '+525512309140'],
      'with +52' => ['tel +52 55 1230 9140', '+525512309140'],
      'with legacy mobile 1' => ['+52 1 55 1230 9140', '+525512309140'],
      'with 52 and no plus' => ['525512309140', '+525512309140'],
      'foreign number keeps its prefix' => ['call me at +1 415 555 0100', '+14155550100'],
      'amounts are not phones' => ['valor 50, 000 USD y 850000 pesos', ''],
      'too short' => ['ext 12345', ''],
    ];
  }

  /**
   * Emails are extracted as written.
   */
  public function testExtractEmail(): void {
    $extractor = new LeadContactExtractor();
    $this->assertSame('ana.ruiz@example.com', $extractor->extractEmail('ai: 📧 **Correo:** ana.ruiz@example.com.'));
    $this->assertSame('', $extractor->extractEmail(self::PRODUCTION_LEAD_TRANSCRIPT));
  }

}
