<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Ui;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Draws the daily activity of a period as an inline SVG.
 *
 * Inline on purpose: a charting library would add JavaScript, a download and
 * a dependency to maintain for two series of at most thirty points. The
 * result scales with the container and needs no script to render.
 */
final class ActivityChart {

  use StringTranslationTrait;

  /**
   * Viewbox of the chart. The SVG scales, so these are only proportions.
   */
  private const WIDTH = 720;
  private const HEIGHT = 150;

  /**
   * Builds the chart.
   *
   * @param array<int, array{day: string, received: int, sent: int}> $series
   *   Daily counts, oldest first.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public static function build(array $series): array {
    $chart = new self();

    return $chart->render($series);
  }

  /**
   * Renders the chart or an empty state.
   *
   * @param array<int, array{day: string, received: int, sent: int}> $series
   *   Daily counts, oldest first.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function render(array $series): array {
    $peak = 0;
    foreach ($series as $point) {
      $peak = max($peak, $point['received'] + $point['sent']);
    }
    if ($series === [] || $peak === 0) {
      return [
        '#markup' => '<p class="aiwa-chart__empty">' . $this->t('Todavía no hay mensajes en este periodo.') . '</p>',
      ];
    }

    $count = count($series);
    $slot = self::WIDTH / $count;
    $bar = max(4.0, min(22.0, $slot * 0.55));
    $bars = '';
    $labels = '';
    foreach (array_values($series) as $index => $point) {
      $total = $point['received'] + $point['sent'];
      $centre = ($index + 0.5) * $slot;
      $x = $centre - ($bar / 2);
      $height = $total / $peak * (self::HEIGHT - 24);
      $received = $total === 0 ? 0.0 : $height * ($point['received'] / $total);
      $sent = $height - $received;
      $base = self::HEIGHT - 20;

      $title = $this->t('@day · @received recibidos, @sent enviados', [
        '@day' => $this->dayLabel($point['day']),
        '@received' => $point['received'],
        '@sent' => $point['sent'],
      ]);
      $bars .= '<g class="aiwa-chart__bar"><title>' . Html::escape((string) $title) . '</title>';
      if ($sent > 0) {
        $bars .= sprintf(
          '<rect class="aiwa-chart__bar-sent" x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="3"></rect>',
          $x, $base - $height, $bar, $sent
        );
      }
      if ($received > 0) {
        $bars .= sprintf(
          '<rect class="aiwa-chart__bar-received" x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="3"></rect>',
          $x, $base - $received, $bar, $received
        );
      }
      $bars .= '</g>';

      // Only the ends and the middle are labelled: thirty dates do not fit.
      if ($index === 0 || $index === $count - 1 || $index === intdiv($count, 2)) {
        $anchor = $index === 0 ? 'start' : ($index === $count - 1 ? 'end' : 'middle');
        $labels .= sprintf(
          '<text class="aiwa-chart__axis" x="%.2f" y="%d" text-anchor="%s">%s</text>',
          $centre, self::HEIGHT - 4, $anchor, Html::escape($this->dayLabel($point['day']))
        );
      }
    }

    $svg = sprintf(
      '<svg class="aiwa-chart__svg" viewBox="0 0 %d %d" role="img" aria-label="%s">%s%s</svg>',
      self::WIDTH,
      self::HEIGHT,
      Html::escape((string) $this->t('Mensajes por día')),
      $bars,
      $labels
    );

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['aiwa-chart']],
      // Markup::create: #markup would strip the SVG tags. Every value that
      // comes from the data is escaped above.
      'svg' => ['#markup' => Markup::create($svg)],
      'legend' => [
        '#markup' => '<div class="aiwa-chart__legend">'
        . '<span class="aiwa-chart__key aiwa-chart__key--received">' . $this->t('Recibidos') . '</span>'
        . '<span class="aiwa-chart__key aiwa-chart__key--sent">' . $this->t('Enviados') . '</span>'
        . '<span class="aiwa-chart__peak">' . $this->t('Máximo en un día: @peak', ['@peak' => $peak]) . '</span>'
        . '</div>',
      ],
    ];
  }

  /**
   * Returns a short, readable day label.
   */
  private function dayLabel(string $day): string {
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $day);
    if ($date === FALSE) {
      return $day;
    }
    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    return $date->format('j') . ' ' . $months[(int) $date->format('n') - 1];
  }

}
