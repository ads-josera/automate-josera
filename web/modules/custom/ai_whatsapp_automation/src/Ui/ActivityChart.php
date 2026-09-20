<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Ui;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Draws the daily activity of a period as an inline SVG line chart.
 *
 * Inline on purpose: a charting library would add JavaScript, a download and
 * a dependency to maintain for two series of at most thirty points.
 *
 * Two lines, one for what clients write and one for what the panel answers,
 * over a grid with the scale written on the left. Bars were tried first and
 * read badly: most days are empty, so the page showed thin spikes floating
 * in white space with nothing to measure them against.
 */
final class ActivityChart {

  use StringTranslationTrait;

  /**
   * Geometry of the drawing, in viewBox units.
   */
  private const WIDTH = 760;
  private const HEIGHT = 240;
  private const PLOT_LEFT = 44;
  private const PLOT_RIGHT = 748;
  private const PLOT_TOP = 14;
  private const PLOT_BOTTOM = 196;

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
    return (new self())->render($series);
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
    $series = array_values($series);
    $peak = 0;
    $totals = ['received' => 0, 'sent' => 0];
    foreach ($series as $point) {
      $peak = max($peak, $point['received'], $point['sent']);
      $totals['received'] += $point['received'];
      $totals['sent'] += $point['sent'];
    }
    if ($series === [] || $peak === 0) {
      return [
        '#markup' => '<p class="aiwa-chart__empty">' . $this->t('Todavía no hay mensajes en este periodo.') . '</p>',
      ];
    }

    $top = $this->roundedCeiling($peak);
    $svg = '<svg class="aiwa-chart__svg" viewBox="0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '" role="img" aria-label="'
      . Html::escape((string) $this->t('Mensajes por día')) . '">'
      . $this->grid($top)
      . $this->line($series, 'received', $top)
      . $this->line($series, 'sent', $top)
      . $this->points($series, $top)
      . $this->axis($series)
      . '</svg>';

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['aiwa-chart']],
      // Markup::create: #markup would strip the SVG. Every value that comes
      // from the data is escaped where it is written.
      'svg' => ['#markup' => Markup::create($svg)],
      'legend' => [
        '#markup' => '<div class="aiwa-chart__legend">'
        . '<span class="aiwa-chart__key aiwa-chart__key--received">' . $this->t('Recibidos: @total', ['@total' => $totals['received']]) . '</span>'
        . '<span class="aiwa-chart__key aiwa-chart__key--sent">' . $this->t('Enviados: @total', ['@total' => $totals['sent']]) . '</span>'
        . '<span class="aiwa-chart__peak">' . $this->t('Día más movido: @peak mensajes', ['@peak' => $peak]) . '</span>'
        . '</div>',
      ],
    ];
  }

  /**
   * Draws the horizontal grid and the scale on the left.
   */
  private function grid(int $top): string {
    $svg = '';
    foreach ([0, 0.5, 1] as $fraction) {
      $value = (int) round($top * $fraction);
      $y = self::PLOT_BOTTOM - ($fraction * (self::PLOT_BOTTOM - self::PLOT_TOP));
      $svg .= sprintf(
        '<line class="aiwa-chart__grid" x1="%d" y1="%.1f" x2="%d" y2="%.1f"></line>',
        self::PLOT_LEFT, $y, self::PLOT_RIGHT, $y
      );
      $svg .= sprintf(
        '<text class="aiwa-chart__scale" x="%d" y="%.1f" text-anchor="end">%d</text>',
        self::PLOT_LEFT - 8, $y + 4, $value
      );
    }

    return $svg;
  }

  /**
   * Draws one series as a line with a soft area under it.
   *
   * @param array<int, array{day: string, received: int, sent: int}> $series
   *   Daily counts.
   */
  private function line(array $series, string $key, int $top): string {
    $count = count($series);
    $points = [];
    foreach ($series as $index => $point) {
      $points[] = sprintf('%.1f,%.1f', $this->x($index, $count), $this->y($point[$key], $top));
    }
    $path = implode(' ', $points);
    $area = sprintf('%.1f,%.1f ', $this->x(0, $count), self::PLOT_BOTTOM) . $path
      . sprintf(' %.1f,%.1f', $this->x($count - 1, $count), self::PLOT_BOTTOM);

    return '<polygon class="aiwa-chart__area aiwa-chart__area--' . $key . '" points="' . $area . '"></polygon>'
      . '<polyline class="aiwa-chart__line aiwa-chart__line--' . $key . '" points="' . $path . '"></polyline>';
  }

  /**
   * Draws a hover target per day, with the numbers of that day.
   *
   * @param array<int, array{day: string, received: int, sent: int}> $series
   *   Daily counts.
   */
  private function points(array $series, int $top): string {
    $count = count($series);
    $band = (self::PLOT_RIGHT - self::PLOT_LEFT) / max(1, $count);
    $svg = '';
    foreach ($series as $index => $point) {
      $x = $this->x($index, $count);
      $title = $this->t('@day · @received recibidos, @sent enviados', [
        '@day' => $this->dayLabel($point['day']),
        '@received' => $point['received'],
        '@sent' => $point['sent'],
      ]);
      $svg .= '<g class="aiwa-chart__point"><title>' . Html::escape((string) $title) . '</title>';
      // A wide, invisible band makes every day reachable with the pointer.
      $svg .= sprintf(
        '<rect class="aiwa-chart__hit" x="%.1f" y="%d" width="%.1f" height="%d"></rect>',
        $x - ($band / 2), self::PLOT_TOP, $band, self::PLOT_BOTTOM - self::PLOT_TOP
      );
      foreach (['received', 'sent'] as $key) {
        if ($point[$key] > 0) {
          $svg .= sprintf(
            '<circle class="aiwa-chart__dot aiwa-chart__dot--%s" cx="%.1f" cy="%.1f" r="3"></circle>',
            $key, $x, $this->y($point[$key], $top)
          );
        }
      }
      $svg .= '</g>';
    }

    return $svg;
  }

  /**
   * Writes the first, middle and last day under the plot.
   *
   * @param array<int, array{day: string, received: int, sent: int}> $series
   *   Daily counts.
   */
  private function axis(array $series): string {
    $count = count($series);
    $svg = '';
    foreach ([0 => 'start', intdiv($count, 2) => 'middle', $count - 1 => 'end'] as $index => $anchor) {
      if (!isset($series[$index])) {
        continue;
      }
      $svg .= sprintf(
        '<text class="aiwa-chart__axis" x="%.1f" y="%d" text-anchor="%s">%s</text>',
        $this->x($index, $count), self::PLOT_BOTTOM + 22, $anchor, Html::escape($this->dayLabel($series[$index]['day']))
      );
    }

    return $svg;
  }

  /**
   * Horizontal position of a day.
   */
  private function x(int $index, int $count): float {
    if ($count <= 1) {
      return (self::PLOT_LEFT + self::PLOT_RIGHT) / 2;
    }

    return self::PLOT_LEFT + ($index / ($count - 1)) * (self::PLOT_RIGHT - self::PLOT_LEFT);
  }

  /**
   * Vertical position of a value.
   */
  private function y(int $value, int $top): float {
    $ratio = $top === 0 ? 0 : $value / $top;

    return self::PLOT_BOTTOM - ($ratio * (self::PLOT_BOTTOM - self::PLOT_TOP));
  }

  /**
   * Rounds the top of the scale up to a readable number.
   */
  private function roundedCeiling(int $peak): int {
    if ($peak <= 5) {
      return max(1, $peak);
    }
    $step = $peak <= 20 ? 5 : ($peak <= 100 ? 10 : 50);

    return (int) (ceil($peak / $step) * $step);
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
