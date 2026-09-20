<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

/**
 * Recognises incoming messages that carry no readable request.
 *
 * Keyboard mashing ("Vfvfffbrbrhr") and dictated noise ("Bbv. Zds. M.") reach
 * both WhatsApp and the web widget, and each one otherwise costs a full model
 * call with the bot instructions, the knowledge base context and the recent
 * transcript attached.
 *
 * The cost of a wrong answer is not symmetric: ignoring noise saves a fraction
 * of a cent, while brushing off a real contact can lose a customer. So every
 * rule here errs towards letting a message through, and a message is only
 * called unintelligible when most of its words are unpronounceable AND none of
 * them is a word we recognise.
 */
final class UnintelligibleMessageDetector {

  /**
   * Letters that count as vowels, including the accented Spanish ones.
   */
  private const VOWELS = 'aeiouáéíóúüàèìòùâêîôûäëïöÿ';

  /**
   * The longest run of consonants a real Spanish word reaches.
   *
   * "obstrucción" and "constructor" both carry four ("bstr", "nstr"), so only
   * a fifth one marks a token as unpronounceable.
   */
  private const MAX_CONSONANT_RUN = 4;

  /**
   * Share of readable tokens below which a message carries no request.
   */
  private const READABLE_RATIO = 0.5;

  /**
   * Words that make a message readable on their own.
   *
   * Short function words fail the shape test that longer words pass, so they
   * are listed instead: "no" and "ok" are messages, not noise. Chat
   * interjections are here for the same reason — "jaja" answers something.
   *
   * @var string[]
   */
  private const KNOWN_WORDS = [
    // Greetings and courtesy.
    'hola', 'holi', 'buenas', 'buenos', 'buen', 'dias', 'tardes', 'noches',
    'gracias', 'favor', 'porfa', 'porfavor', 'saludos', 'adios', 'bye', 'hi',
    'hello', 'disculpa', 'disculpe', 'perdon', 'permiso',
    // Answers and interjections.
    'si', 'no', 'ok', 'oka', 'okay', 'vale', 'claro', 'listo', 'va', 'sale',
    'aja', 'ajam', 'mmm', 'hmm', 'eh', 'ah', 'oh', 'uy', 'jaja', 'jeje',
    'jiji', 'jajaja', 'jejeje', 'xd', 'lol',
    // Function words that hold a sentence together.
    'que', 'qué', 'de', 'del', 'la', 'las', 'el', 'los', 'un', 'una', 'unos',
    'unas', 'al', 'lo', 'le', 'me', 'mi', 'te', 'tu', 'se', 'su', 'sus', 'es',
    'en', 'con', 'sin', 'por', 'para', 'pero', 'como', 'cómo', 'donde',
    'dónde', 'cuando', 'cuándo', 'cuanto', 'cuánto', 'cuanta', 'cuánta',
    'cual', 'cuál', 'quien', 'quién', 'porque', 'ya', 'yo', 'ti',
    'nos', 'ni', 'hay', 'esta', 'este', 'esto', 'ese',
    'eso', 'esa', 'son', 'ser', 'soy', 'fue', 'era', 'muy', 'mas', 'más',
    'todo', 'toda', 'todos', 'nada', 'algo', 'bien', 'mal', 'aqui', 'aquí',
    'alla', 'allá', 'ahi', 'ahí', 'hoy', 'ayer', 'manana', 'mañana', 'ahora',
    'luego', 'antes', 'despues', 'después',
    // The words this panel's contacts actually type.
    'precio', 'precios', 'costo', 'costos', 'cuesta', 'cotizar', 'cotizacion',
    'cotización', 'info', 'informacion', 'información', 'informes', 'quiero',
    'necesito', 'busco', 'ocupo', 'puedo', 'puede', 'pueden', 'tienen',
    'tiene', 'venden', 'hacen', 'manejan', 'servicio', 'servicios',
    'producto', 'productos', 'ayuda', 'ayudar', 'pregunta', 'duda', 'dudas',
    'horario', 'horarios', 'direccion', 'dirección', 'ubicacion', 'ubicación',
    'telefono', 'teléfono', 'correo', 'mail', 'whatsapp', 'numero', 'número',
    'pago', 'pagos', 'envio', 'envío', 'factura', 'cita', 'agendar',
    'disponible', 'disponibilidad', 'asesor', 'humano', 'persona', 'hablar',
    'contacto', 'contactar', 'empresa', 'nombre',
  ];

  /**
   * Returns whether a message carries no readable request.
   */
  public function isUnintelligible(string $message): bool {
    $message = trim($message);
    if ($message === '') {
      return FALSE;
    }

    // A link, an address or a long number is a deliberate answer whatever it
    // is wrapped in, and none of them survives the shape test below.
    if ($this->carriesStructuredData($message)) {
      return FALSE;
    }

    $tokens = $this->tokenize($message);
    if ($tokens === []) {
      // Emoji, digits or punctuation only: "👍" and "5551234567" are answers.
      return FALSE;
    }

    $readable = 0;
    foreach ($tokens as $token) {
      if ($this->isReadable($token)) {
        $readable++;
      }
    }

    return ($readable / count($tokens)) < self::READABLE_RATIO;
  }

  /**
   * Returns whether the message holds a link, an e-mail or a long number.
   */
  private function carriesStructuredData(string $message): bool {
    return (bool) preg_match('/(https?:\/\/|www\.|[^\s@]+@[^\s@]+\.[a-z]{2,}|\d{4,})/iu', $message);
  }

  /**
   * Splits a message into the letter groups worth judging.
   *
   * Single letters are dropped rather than judged: an initial, a size or the
   * "q" of "q tal" says nothing about whether the message is readable.
   *
   * @return string[]
   *   Lowercase letter-only tokens of at least two characters.
   */
  private function tokenize(string $message): array {
    $message = mb_strtolower($message);
    preg_match_all('/\p{L}+/u', $message, $matches);

    return array_values(array_filter(
      $matches[0] ?? [],
      static fn (string $token): bool => mb_strlen($token) >= 2,
    ));
  }

  /**
   * Returns whether a single token reads like a word.
   */
  private function isReadable(string $token): bool {
    if (in_array($token, self::KNOWN_WORDS, TRUE)) {
      return TRUE;
    }

    $length = mb_strlen($token);
    $vowels = $this->vowelCount($token);
    if ($vowels === 0) {
      // "Zds", "jlkjj": nothing to pronounce.
      return FALSE;
    }

    // Short unknown tokens get the benefit of the doubt; an acronym or a
    // brand name is only judged once it is long enough to show its shape.
    if ($length < 4) {
      return TRUE;
    }

    // Real words keep roughly a third of their letters as vowels. "whatsapp"
    // sits at the low end with a quarter, "fisjjd" falls below it.
    if (($vowels / $length) < 0.25) {
      return FALSE;
    }

    return $this->longestConsonantRun($token) <= self::MAX_CONSONANT_RUN;
  }

  /**
   * Counts the vowels in a token.
   */
  private function vowelCount(string $token): int {
    $count = 0;
    foreach ($this->letters($token) as $letter) {
      if (mb_strpos(self::VOWELS, $letter) !== FALSE) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * Returns the longest run of consecutive consonants in a token.
   */
  private function longestConsonantRun(string $token): int {
    $longest = 0;
    $current = 0;
    foreach ($this->letters($token) as $letter) {
      if (mb_strpos(self::VOWELS, $letter) !== FALSE) {
        $current = 0;
        continue;
      }
      $current++;
      $longest = max($longest, $current);
    }

    return $longest;
  }

  /**
   * Splits a token into single characters.
   *
   * @return string[]
   *   The characters of the token.
   */
  private function letters(string $token): array {
    return mb_str_split($token);
  }

}
