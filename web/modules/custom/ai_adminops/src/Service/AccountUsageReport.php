<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

/**
 * Joins the three per-account readings into one row per cPanel account.
 *
 * Phase C (2026-10-13): José, as the WHM owner, asked Sentinel which account
 * uses what. The server has no CloudLinux, so the data is WHM's account list
 * (disk and quota), WHM's mail statistics (last hour and day) and the
 * processes running now. Pure: no I/O, so it is tested without a server.
 *
 * José chose that the model sees each account's user AND domain; never
 * passwords or message content (WHM does not return them here anyway).
 */
final class AccountUsageReport {

  /**
   * Accounts returned at most, so the reply stays readable on WhatsApp.
   */
  public const MAX_ACCOUNTS = 15;

  /**
   * Ways the model may sort the accounts.
   */
  public const ORDERS = ['disco', 'correo', 'correo_fallido', 'cpu', 'memoria'];

  /**
   * Builds the report.
   *
   * @param array<string, mixed> $accounts
   *   Reading of get_accounts: {"status":"ok","whm":<listaccts JSON>}.
   * @param array<string, mixed> $mail
   *   Reading of get_mail_by_account: last_hour and last_day, each an
   *   emailtrack_user_stats JSON.
   * @param array<string, mixed> $processes
   *   Reading of get_account_processes: users with cpu_percent, memory_mb.
   * @param string $order
   *   One of ORDERS.
   * @param string $account
   *   Optional user or domain: only that account.
   *
   * @return array<string, mixed>
   *   What the model receives.
   */
  public function build(array $accounts, array $mail, array $processes, string $order = 'disco', string $account = ''): array {
    $rows = [];
    foreach ($this->whmAccounts($accounts) as $acct) {
      $user = (string) ($acct['user'] ?? '');
      if ($user === '') {
        continue;
      }
      $used = $this->megabytes($acct['diskused'] ?? NULL);
      $limit = $this->megabytes($acct['disklimit'] ?? NULL);
      $rows[$user] = [
        'cuenta' => $user,
        'dominio' => (string) ($acct['domain'] ?? ''),
        'suspendida' => (bool) ($acct['suspended'] ?? FALSE),
        'disco_mb' => $used,
        'cuota_mb' => $limit,
        'disco_porcentaje' => $used !== NULL && $limit ? round($used * 100 / $limit, 1) : NULL,
        'correo_ultima_hora' => NULL,
        'correo_ultimo_dia' => NULL,
        'cpu_porcentaje' => 0.0,
        'memoria_mb' => 0,
        'procesos' => 0,
      ];
    }

    // Senders that are not cPanel accounts (root, "-remote-") and system
    // users with processes (mysql, nobody…) are reported apart: they belong
    // to the server, not to a client.
    $system = ['correo_ultimo_dia' => [], 'procesos' => []];
    foreach (['last_hour' => 'correo_ultima_hora', 'last_day' => 'correo_ultimo_dia'] as $window => $field) {
      foreach ($this->mailRecords($mail[$window] ?? NULL) as $record) {
        $user = (string) ($record['USER'] ?? '');
        $counts = $this->mailCounts($record);
        if (isset($rows[$user])) {
          $rows[$user][$field] = $counts;
        }
        elseif ($window === 'last_day' && $user !== '') {
          $system['correo_ultimo_dia'][$user] = $counts;
        }
      }
    }
    foreach (is_array($processes['users'] ?? NULL) ? $processes['users'] : [] as $usage) {
      $user = (string) ($usage['user'] ?? '');
      $values = [
        'cpu_porcentaje' => round((float) ($usage['cpu_percent'] ?? 0), 1),
        'memoria_mb' => (int) ($usage['memory_mb'] ?? 0),
        'procesos' => (int) ($usage['processes'] ?? 0),
      ];
      if (isset($rows[$user])) {
        $rows[$user] = $values + $rows[$user];
      }
      elseif ($user !== '') {
        $system['procesos'][$user] = $values;
      }
    }
    uasort($system['procesos'], static fn (array $a, array $b) => $b['cpu_porcentaje'] <=> $a['cpu_porcentaje']);
    $system['procesos'] = array_slice($system['procesos'], 0, 5, TRUE);

    $report = [
      'total_cuentas' => count($rows),
      'suspendidas' => count(array_filter($rows, static fn (array $row) => $row['suspendida'])),
      'lecturas_sin_dato' => $this->missing($accounts, $mail, $processes),
    ];

    $account = mb_strtolower(trim($account));
    if ($account !== '') {
      $found = array_values(array_filter($rows, static fn (array $row) => $row['cuenta'] === $account || mb_strtolower($row['dominio']) === $account));
      return $report + ($found === []
        ? ['error' => 'No hay ninguna cuenta de cPanel con ese usuario o dominio.']
        : ['cuentas' => $found]);
    }

    $order = in_array($order, self::ORDERS, TRUE) ? $order : 'disco';
    uasort($rows, fn (array $a, array $b) => $this->sortValue($b, $order) <=> $this->sortValue($a, $order));

    return $report + [
      'orden' => $order,
      'cuentas' => array_values(array_slice($rows, 0, self::MAX_ACCOUNTS)),
      'cuentas_omitidas' => max(0, count($rows) - self::MAX_ACCOUNTS),
      'sistema' => $system,
    ];
  }

  /**
   * Returns the accounts in a listaccts reading.
   *
   * @return array<int, array<string, mixed>>
   *   WHM account rows.
   */
  private function whmAccounts(array $reading): array {
    $whm = $reading['whm'] ?? NULL;
    if (($reading['status'] ?? '') !== 'ok' || !is_array($whm) || (int) ($whm['metadata']['result'] ?? 0) !== 1) {
      return [];
    }

    return is_array($whm['data']['acct'] ?? NULL) ? $whm['data']['acct'] : [];
  }

  /**
   * Returns the records of an emailtrack_user_stats reading.
   *
   * @return array<int, array<string, mixed>>
   *   One record per sending user.
   */
  private function mailRecords(mixed $whm): array {
    if (!is_array($whm) || (int) ($whm['metadata']['result'] ?? 0) !== 1) {
      return [];
    }

    return is_array($whm['data']['records'] ?? NULL) ? $whm['data']['records'] : [];
  }

  /**
   * Keeps the counts that tell normal mail from a flood.
   *
   * @return array<string, int|bool>
   *   Sent, delivered, failed, deferred, and whether cPanel's hourly limit
   *   was reached.
   */
  private function mailCounts(array $record): array {
    return [
      'enviados' => (int) ($record['SENDCOUNT'] ?? 0),
      'entregados' => (int) ($record['SUCCESSCOUNT'] ?? 0),
      'fallidos' => (int) ($record['FAILCOUNT'] ?? 0),
      'diferidos' => (int) ($record['DEFERCOUNT'] ?? 0),
      'limite_alcanzado' => (bool) ($record['REACHED_MAXEMAILS'] ?? FALSE),
    ];
  }

  /**
   * Converts WHM sizes ("92M", "1.5G", "unlimited") to megabytes.
   */
  private function megabytes(mixed $size): ?float {
    if (!is_string($size) && !is_numeric($size)) {
      return NULL;
    }
    if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*([KMGT]?)/i', (string) $size, $match)) {
      // "unlimited" or anything WHM may say instead of a number.
      return NULL;
    }
    $factor = ['K' => 1 / 1024, '' => 1, 'M' => 1, 'G' => 1024, 'T' => 1024 * 1024][strtoupper($match[2])];

    return round((float) $match[1] * $factor, 1);
  }

  /**
   * The value an order sorts by; accounts without data go last.
   */
  private function sortValue(array $row, string $order): float {
    return (float) match ($order) {
      'correo' => $row['correo_ultimo_dia']['enviados'] ?? -1,
      'correo_fallido' => $row['correo_ultimo_dia']['fallidos'] ?? -1,
      'cpu' => $row['cpu_porcentaje'],
      'memoria' => $row['memoria_mb'],
      default => $row['disco_porcentaje'] ?? -1,
    };
  }

  /**
   * Names the readings that brought no data, so the model says so.
   *
   * @return string[]
   *   Which of cuentas, correo, procesos are missing.
   */
  private function missing(array $accounts, array $mail, array $processes): array {
    $missing = [];
    if ($this->whmAccounts($accounts) === []) {
      $missing[] = 'cuentas';
    }
    if (($mail['status'] ?? '') !== 'ok') {
      $missing[] = 'correo';
    }
    if (($processes['status'] ?? '') !== 'ok') {
      $missing[] = 'procesos';
    }

    return $missing;
  }

}
