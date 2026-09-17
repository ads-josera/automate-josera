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
   * Returns the text using WhatsApp formatting.
   */
  public function format(string $text): string {
    // "### Title" headings have no WhatsApp equivalent: keep them as bold.
    $text = preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/mu', '**$1**', $text) ?? $text;
    // Only balanced pairs on a single line are converted.
    $text = preg_replace('/\*\*(?=\S)([^*\r\n]+?)(?<=\S)\*\*/u', '*$1*', $text) ?? $text;

    return preg_replace('/__(?=\S)([^_\r\n]+?)(?<=\S)__/u', '*$1*', $text) ?? $text;
  }

}
