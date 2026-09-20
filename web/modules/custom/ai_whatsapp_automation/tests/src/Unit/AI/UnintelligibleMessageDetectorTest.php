<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Unit\AI;

use Drupal\ai_whatsapp_automation\Application\AI\UnintelligibleMessageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests which incoming messages are treated as noise.
 */
#[Group('ai_whatsapp_automation')]
final class UnintelligibleMessageDetectorTest extends TestCase {

  /**
   * Noise is recognised.
   */
  #[DataProvider('unreadableMessages')]
  public function testNoiseIsRecognised(string $message): void {
    $this->assertTrue(
      (new UnintelligibleMessageDetector())->isUnintelligible($message),
      sprintf('"%s" carries no readable request', $message),
    );
  }

  /**
   * A real message is never mistaken for noise.
   *
   * This is the half of the detector that must not fail: answering a customer
   * with "no te entendí" because the filter misread them is the expensive
   * mistake, so the list is deliberately awkward.
   */
  #[DataProvider('readableMessages')]
  public function testRealMessagesArePassedThrough(string $message): void {
    $this->assertFalse(
      (new UnintelligibleMessageDetector())->isUnintelligible($message),
      sprintf('"%s" is a real message', $message),
    );
  }

  /**
   * Messages that carry no readable request.
   *
   * @return array<string, array{string}>
   *   Test cases.
   */
  public static function unreadableMessages(): array {
    return [
      'the ones José reported' => ['Bbv. Zds. M. FisjjD no jlkjj. B'],
      'keyboard mashing' => ['Vfvfffbrbrhr'],
      'a pocket dial' => ['asdfghjkl'],
      'consonants only' => ['zxcvb bnmk'],
      'a long unpronounceable run' => ['Hjklmnbvcxz qwrtpsdfg'],
      'dictation noise with a stray word' => ['Ksks. Ttt no. Prprpr mmkk vbvb'],
    ];
  }

  /**
   * Messages a real contact sends.
   *
   * @return array<string, array{string}>
   *   Test cases.
   */
  public static function readableMessages(): array {
    return [
      'a greeting' => ['Hola'],
      'a request' => ['Hola, quiero cotizar impermeabilizante para 120 m2'],
      'one word naming a product' => ['Impermeabilizante'],
      'an unusual single word' => ['Presupuesto'],
      'a brand name' => ['Comex'],
      'a short answer' => ['ok'],
      'a bare yes' => ['si'],
      'an accented yes' => ['sí'],
      'laughter' => ['jajaja'],
      'a chat abbreviation' => ['xd'],
      'an emoji only' => ['👍'],
      'a phone number' => ['5551234567'],
      'an e-mail address' => ['ads@josera.com.mx'],
      'a link' => ['https://josera.com.mx/servicios'],
      'a hesitant human' => ['mmm no se'],
      'an abbreviated question' => ['q precio tiene'],
      'a word with a long consonant run' => ['Necesito un constructor'],
      'a product code with letters' => ['SKU 4421 disponible?'],
      'an acronym inside a sentence' => ['Me pasas la factura con mi RFC'],
      'half noise and half words' => ['hola asdfgh'],
      'shouting' => ['HOLA NECESITO AYUDA'],
      'an address' => ['Av. Vallarta 1234, Guadalajara'],
    ];
  }

}
