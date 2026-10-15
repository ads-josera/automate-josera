<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Keeps and summarizes 30 days of usage per cPanel account (phase C2).
 *
 * One row per account and scheduled reading, in ai_adminops_account_usage.
 * The mail counts of a row are those of the hour before it was read, so
 * readings 15 minutes apart overlap: the summary reports peaks and hourly
 * averages, never a sum.
 */
final class AccountUsageHistory {

  /**
   * Days of history kept.
   */
  public const RETENTION_DAYS = 30;

  /**
   * Accounts listed at most when no single account is asked for.
   */
  public const MAX_ACCOUNTS = 10;

  /**
   * Ways to rank accounts over a period.
   */
  public const ORDERS = ['correo', 'correo_fallido', 'cpu', 'memoria'];

  /**
   * Longest period with an hour-by-hour breakdown: 48 rows at most.
   */
  public const MAX_HOURLY_HOURS = 48;

  private const TABLE = 'ai_adminops_account_usage';

  /**
   * Creates an AccountUsageHistory instance.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Stores one reading of every account, and drops what is too old.
   *
   * @param array<string, array<string, mixed>> $rows
   *   AccountUsageReport::accountRows().
   */
  public function record(string $server_id, array $rows, int $read_at): int {
    $insert = $this->database->insert(self::TABLE)->fields([
      'server', 'account', 'read_at', 'suspended', 'disk_mb', 'quota_mb',
      'mail_sent', 'mail_failed', 'mail_deferred', 'mail_limit_reached', 'mail_hour_limit',
      'cpu_percent', 'memory_mb', 'processes',
    ]);
    foreach ($rows as $row) {
      $mail = $row['correo_ultima_hora'] ?? NULL;
      $insert->values([
        'server' => $server_id,
        'account' => mb_substr((string) $row['cuenta'], 0, 64),
        'read_at' => $read_at,
        'suspended' => (int) (bool) ($row['suspendida'] ?? FALSE),
        // WHM's exact megabytes; the GB in the row are rounded for the model.
        'disk_mb' => $row['disco_mb'] ?? NULL,
        'quota_mb' => $row['cuota_mb'] ?? NULL,
        'mail_sent' => is_array($mail) ? (int) $mail['enviados'] : NULL,
        'mail_failed' => is_array($mail) ? (int) $mail['fallidos'] : NULL,
        'mail_deferred' => is_array($mail) ? (int) $mail['diferidos'] : NULL,
        // WHM's REACHED_MAXEMAILS flag, as WHM gives it (see
        // AccountUsageReport::mailCounts(): it does not mean mail was held).
        'mail_limit_reached' => (int) (is_array($mail) && !empty($mail['_indicador_whm'])),
        'mail_hour_limit' => $row['limite_correos_hora'] ?? NULL,
        'cpu_percent' => $row['cpu_porcentaje'] ?? NULL,
        'memory_mb' => $row['memoria_mb'] ?? NULL,
        'processes' => $row['procesos'] ?? NULL,
      ]);
    }
    if ($rows !== []) {
      $insert->execute();
    }

    $this->database->delete(self::TABLE)
      ->condition('read_at', $this->time->getRequestTime() - self::RETENTION_DAYS * 86400, '<')
      ->execute();

    return count($rows);
  }

  /**
   * Summarizes a period: one account in detail, or the busiest accounts.
   *
   * @return array<string, mixed>
   *   What the model receives.
   */
  public function summarize(string $server_id, string $account, int $hours, string $order = 'correo', bool $by_hour = FALSE): array {
    $hours = max(1, min(self::RETENTION_DAYS * 24, $hours));
    $since = $this->time->getRequestTime() - $hours * 3600;
    $account = mb_strtolower(trim($account));

    $query = $this->database->select(self::TABLE, 'u')
      ->condition('u.server', $server_id)
      ->condition('u.read_at', $since, '>=');
    $query->addField('u', 'account');
    $query->addExpression('COUNT(*)', 'lecturas');
    $query->addExpression('MIN(u.read_at)', 'desde');
    $query->addExpression('MAX(u.read_at)', 'hasta');
    $query->addExpression('MAX(u.cpu_percent)', 'cpu_max');
    $query->addExpression('AVG(u.cpu_percent)', 'cpu_promedio');
    $query->addExpression('SUM(CASE WHEN u.cpu_percent >= 50 THEN 1 ELSE 0 END)', 'lecturas_cpu_50');
    $query->addExpression('MAX(u.memory_mb)', 'memoria_max');
    $query->addExpression('MAX(u.mail_sent)', 'correo_max_hora');
    $query->addExpression('AVG(u.mail_sent)', 'correo_promedio_hora');
    $query->addExpression('MAX(u.mail_failed)', 'fallidos_max_hora');
    $query->addExpression('MAX(u.mail_hour_limit)', 'limite_hora');
    $query->groupBy('u.account');
    if ($account !== '') {
      $query->condition('u.account', $account);
    }

    $accounts = [];
    foreach ($query->execute()->fetchAllAssoc('account', FetchAs::Associative) as $user => $stats) {
      $accounts[$user] = $this->format($stats);
    }

    $period = ['horas' => $hours, 'conservado_dias' => self::RETENTION_DAYS];
    if ($accounts === []) {
      return $period + ['error' => $account !== ''
        ? 'No hay historial de esa cuenta en ese periodo (usa el usuario de cPanel, no el dominio).'
        : 'Todavía no hay historial en ese periodo: se guarda en cada lectura programada.'];
    }

    if ($account !== '') {
      $detail = $period + ['cuenta' => $account] + $accounts[$account] + $this->disk($server_id, $account, $since) + $this->peaks($server_id, $account, $since);
      if ($by_hour) {
        $detail['por_hora'] = $hours <= self::MAX_HOURLY_HOURS
          ? $this->hourly($server_id, $account, $since)
          : 'Solo para periodos de hasta ' . self::MAX_HOURLY_HOURS . ' horas.';
      }

      return $detail;
    }

    $order = in_array($order, self::ORDERS, TRUE) ? $order : 'correo';
    // The keys of format(), which is what $accounts holds by now.
    $key = ['correo' => 'correo_max_en_una_hora', 'correo_fallido' => 'fallidos_max_en_una_hora', 'cpu' => 'cpu_max', 'memoria' => 'memoria_max_mb'][$order];
    uasort($accounts, static fn (array $a, array $b) => ($b[$key] ?? -1) <=> ($a[$key] ?? -1));

    return $period + [
      'orden' => $order,
      'cuentas' => array_slice(array_map(static fn (string $user, array $stats) => ['cuenta' => $user] + $stats, array_keys($accounts), $accounts), 0, self::MAX_ACCOUNTS),
      'cuentas_omitidas' => max(0, count($accounts) - self::MAX_ACCOUNTS),
    ];
  }

  /**
   * Rounds one account's aggregates for the model.
   *
   * @return array<string, mixed>
   *   Aggregates; NULL where nothing was read.
   */
  private function format(array $stats): array {
    $number = static fn (mixed $value, int $precision = 1) => $value === NULL ? NULL : round((float) $value, $precision);

    return [
      'lecturas' => (int) $stats['lecturas'],
      'desde' => $this->at((int) $stats['desde']),
      'hasta' => $this->at((int) $stats['hasta']),
      'cpu_max' => $number($stats['cpu_max']),
      'cpu_promedio' => $number($stats['cpu_promedio']),
      'lecturas_cpu_50_o_mas' => (int) $stats['lecturas_cpu_50'],
      'memoria_max_mb' => $stats['memoria_max'] === NULL ? NULL : (int) $stats['memoria_max'],
      'correo_max_en_una_hora' => $stats['correo_max_hora'] === NULL ? NULL : (int) $stats['correo_max_hora'],
      'correo_promedio_por_hora' => $number($stats['correo_promedio_hora']),
      'fallidos_max_en_una_hora' => $stats['fallidos_max_hora'] === NULL ? NULL : (int) $stats['fallidos_max_hora'],
      'limite_correos_hora_del_paquete' => $stats['limite_hora'] === NULL ? NULL : (int) $stats['limite_hora'],
      'pico_como_porcentaje_del_limite' => $stats['limite_hora'] && $stats['correo_max_hora'] !== NULL ? (int) round($stats['correo_max_hora'] * 100 / $stats['limite_hora']) : NULL,
    ];
  }

  /**
   * The disk at the start and end of the period, to show the trend.
   *
   * @return array<string, mixed>
   *   First and last reading, in GB.
   */
  private function disk(string $server_id, string $account, int $since): array {
    $values = [];
    foreach (['ASC' => 'disco_gb_al_inicio', 'DESC' => 'disco_gb_al_final'] as $direction => $label) {
      $disk = $this->database->select(self::TABLE, 'u')
        ->fields('u', ['disk_mb'])
        ->condition('u.server', $server_id)
        ->condition('u.account', $account)
        ->condition('u.read_at', $since, '>=')
        ->isNotNull('u.disk_mb')
        ->orderBy('u.read_at', $direction)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      $values[$label] = $disk === FALSE ? NULL : round((float) $disk / 1024, 2);
    }

    return $values;
  }

  /**
   * When the account sent the most mail and used the most CPU.
   *
   * @return array<string, mixed>
   *   The time of each peak.
   */
  private function peaks(string $server_id, string $account, int $since): array {
    $peaks = [];
    foreach (['mail_sent' => 'hora_de_mas_correo', 'cpu_percent' => 'hora_de_mas_cpu'] as $column => $label) {
      $read_at = $this->database->select(self::TABLE, 'u')
        ->fields('u', ['read_at'])
        ->condition('u.server', $server_id)
        ->condition('u.account', $account)
        ->condition('u.read_at', $since, '>=')
        ->condition('u.' . $column, 0, '>')
        ->orderBy('u.' . $column, 'DESC')
        ->orderBy('u.read_at', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchField();
      $peaks[$label] = $read_at === FALSE ? NULL : $this->at((int) $read_at);
    }

    return $peaks;
  }

  /**
   * One account hour by hour: the most mail, CPU and memory of each hour.
   *
   * Sentinel offered an hourly breakdown on 2026-10-15 that its tool could
   * not give. Each row's mail is that of the hour before the reading, so the
   * hour's figure is the highest of its readings, never their sum.
   *
   * @return list<array<string, mixed>>
   *   Hours in order, in the site's time zone.
   */
  private function hourly(string $server_id, string $account, int $since): array {
    $rows = $this->database->select(self::TABLE, 'u')
      ->fields('u', ['read_at', 'mail_sent', 'mail_failed', 'cpu_percent', 'memory_mb'])
      ->condition('u.server', $server_id)
      ->condition('u.account', $account)
      ->condition('u.read_at', $since, '>=')
      ->orderBy('u.read_at')
      ->execute()
      ->fetchAll();

    $hours = [];
    foreach ($rows as $row) {
      $hour = $this->dateFormatter->format((int) $row->read_at, 'custom', 'Y-m-d H:00');
      $current = $hours[$hour] ?? ['hora' => $hour, 'correo_max' => NULL, 'fallidos_max' => NULL, 'cpu_max' => NULL, 'memoria_max_mb' => NULL];
      foreach (['correo_max' => $row->mail_sent, 'fallidos_max' => $row->mail_failed, 'memoria_max_mb' => $row->memory_mb] as $key => $value) {
        if ($value !== NULL) {
          $current[$key] = max((int) $current[$key], (int) $value);
        }
      }
      if ($row->cpu_percent !== NULL) {
        $current['cpu_max'] = max((float) $current['cpu_max'], round((float) $row->cpu_percent, 1));
      }
      $hours[$hour] = $current;
    }

    return array_values($hours);
  }

  /**
   * A timestamp in the site's time zone.
   */
  private function at(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i');
  }

}
