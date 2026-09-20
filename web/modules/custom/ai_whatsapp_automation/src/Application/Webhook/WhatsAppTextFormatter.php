<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Webhook;

/**
 * Converts assistant Markdown into WhatsApp text formatting.
 *
 * Bot prompts ask for bold text and the model answers in Markdown
 * ("**Empresa:**"). WhatsApp uses single markers ("*Empresa:*"), so without
 * this conversion contacts see literal double asterisks.
 */
final class WhatsAppTextFormatter {

  /**
   * Marker used for the items of a nested list.
   *
   * WhatsApp draws a bullet for "- item" but only at the first level: an
   * indented "- item" is printed as written, dash included. The model does
   * write nested lists, so the inner marker is replaced by a character that
   * reads as a sub-item on its own.
   */
  private const NESTED_BULLET = '◦';

  /**
   * Returns the text using WhatsApp formatting.
   */
  public function format(string $text): string {
    // "### Title" headings have no WhatsApp equivalent: keep them as bold.
    $text = preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/mu', '**$1**', $text) ?? $text;
    $text = $this->markNestedBullets($text);
    // Only balanced pairs on a single line are converted.
    $text = preg_replace('/\*\*(?=\S)([^*\r\n]+?)(?<=\S)\*\*/u', '*$1*', $text) ?? $text;

    return preg_replace('/__(?=\S)([^_\r\n]+?)(?<=\S)__/u', '*$1*', $text) ?? $text;
  }

  /**
   * Replaces the marker of indented list items.
   *
   * Runs before bold conversion, so an item written with "*" is still a list
   * marker at this point and not yet confused with WhatsApp's bold.
   */
  private function markNestedBullets(string $text): string {
    return preg_replace(
      '/^([ \t]+)[-*+][ \t]+(?=\S)/mu',
      '$1' . self::NESTED_BULLET . ' ',
      $text,
    ) ?? $text;
  }

}
