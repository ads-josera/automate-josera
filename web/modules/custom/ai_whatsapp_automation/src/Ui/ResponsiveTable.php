<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Ui;

/**
 * Makes admin tables scroll inside their own container on small screens.
 *
 * Without it wide tables pushed the whole admin page sideways on phones and
 * Claro squeezed headers until they wrapped one letter per line. The
 * stylesheet is attached here so every caller gets it.
 */
final class ResponsiveTable {

  /**
   * Wraps a table render array in a horizontally scrollable container.
   *
   * @param array<string, mixed> $table
   *   A render array of #type table.
   *
   * @return array<string, mixed>
   *   The wrapped render array.
   */
  public static function wrap(array $table): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['aiwa-table-scroll']],
      '#attached' => ['library' => ['ai_whatsapp_automation/responsive_tables']],
      'table' => $table,
    ];
  }

}
