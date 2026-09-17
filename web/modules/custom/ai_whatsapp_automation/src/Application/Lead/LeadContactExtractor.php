<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Lead;

/**
 * Extracts lead contact data from a chat transcript.
 *
 * Assistants format their summaries as "Label: value" lines, often with
 * Markdown and icons, and list the labels they still need with empty values.
 * Every lookup therefore stays on the label's own line and rejects values that
 * are themselves labels, which is how "📧 Correo electrónico" used to end up
 * stored as a lead name.
 */
final class LeadContactExtractor {

  /**
   * Name labels in priority order: explicit name labels win over "contacto".
   */
  private const NAME_LABELS = [
    'nombre del contacto',
    'nombre completo',
    'nombre',
    'contacto',
  ];

  /**
   * Words that are field labels, never a person's name.
   */
  private const LABEL_WORDS = [
    'nombre',
    'nombre del contacto',
    'contacto',
    'correo',
    'correo electrónico',
    'correo electronico',
    'email',
    'e-mail',
    'teléfono',
    'telefono',
    'celular',
    'whatsapp',
    'empresa',
  ];

  /**
   * Returns the contact name, or an empty string when none was provided.
   */
  public function extractName(string $text): string {
    $text = $this->stripMarkdown($text);

    foreach (self::NAME_LABELS as $label) {
      // The value must be on the label's line: [^\S\r\n] is horizontal
      // whitespace only, so an empty label never borrows the next line.
      $pattern = '/(?<![\p{L}])' . preg_quote($label, '/') . '[^\S\r\n]*:[^\S\r\n]*([^\r\n]*)/iu';
      if (!preg_match_all($pattern, $text, $matches)) {
        continue;
      }
      foreach ($matches[1] as $candidate) {
        $name = $this->cleanName($candidate);
        if ($name !== '') {
          return $name;
        }
      }
    }

    return '';
  }

  /**
   * Returns the first phone number normalized to E.164, or an empty string.
   *
   * Ten-digit numbers without a country code are Mexican (+52). Numbers with
   * the legacy mobile "1" after 52 are normalized to the current format.
   */
  public function extractPhone(string $text): string {
    // Horizontal separators only, so digits on different lines never merge.
    if (!preg_match_all('/(?<![\d+])\+?\d[\d \t().-]{8,}\d(?!\d)/u', $text, $matches)) {
      return '';
    }

    foreach ($matches[0] as $candidate) {
      $has_plus = str_starts_with($candidate, '+');
      $digits = preg_replace('/\D+/', '', $candidate) ?? '';
      $length = strlen($digits);

      if (!$has_plus && $length === 10) {
        return '+52' . $digits;
      }
      if ($length === 13 && str_starts_with($digits, '521')) {
        return '+52' . substr($digits, 3);
      }
      if ($length === 12 && str_starts_with($digits, '52')) {
        return '+' . $digits;
      }
      if ($has_plus && $length >= 11 && $length <= 15) {
        return '+' . $digits;
      }
    }

    return '';
  }

  /**
   * Returns the first email address, or an empty string.
   */
  public function extractEmail(string $text): string {
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches)) {
      return $matches[0];
    }

    return '';
  }

  /**
   * Removes Markdown emphasis characters.
   */
  private function stripMarkdown(string $text): string {
    return preg_replace('/[*_`]+/u', '', $text) ?? $text;
  }

  /**
   * Returns a usable name or an empty string when the value is not a name.
   */
  private function cleanName(string $value): string {
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    // Drop leading icons and punctuation, then trailing separators.
    $value = preg_replace('/^[^\p{L}\p{N}]+/u', '', $value) ?? '';
    $value = trim($value, " \t-–—,.;");

    if ($value === ''
      // Another label on the same line, e.g. "Nombre: Teléfono:".
      || str_contains($value, ':')
      || !preg_match('/\p{L}/u', $value)
      || in_array(mb_strtolower($value), self::LABEL_WORDS, TRUE)
      || filter_var($value, FILTER_VALIDATE_EMAIL)
      // Phones are not names.
      || preg_match('/\d{3,}/', $value)
      || mb_strlen($value) > 128) {
      return '';
    }

    return $value;
  }

}
