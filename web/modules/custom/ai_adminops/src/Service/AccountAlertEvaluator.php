<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Opens and closes alerts about single cPanel accounts (phase C2b).
 *
 * José asked (2026-10-13) to be told when one account misbehaves, for
 * instance mailing in bulk. Each problem of each account is its own event,
 * so the alert template names the account; the server-wide checks stay in
 * MonitoringAlertEvaluator.
 *
 * Mail is measured against the account's own package limit (José,
 * 2026-10-15: "debe tomar los límites de cada paquete"), never a server-wide
 * figure. An account without a limit gets no mail alert.
 *
 * An event stays open while its problem lasts and closes on its own when
 * the account is back to normal, is suspended or no longer exists. Nothing
 * is closed on a reading that brought no data: nothing is known then.
 */
final class AccountAlertEvaluator {

  /**
   * Event types this evaluator owns, with the short title of each alert.
   */
  public const PROBLEMS = [
    'account_mail_near_limit' => 'Correo cerca del límite del paquete',
    'account_mail_limit' => 'Correo en el límite del paquete',
    'account_mail_failed' => 'Muchos correos fallidos',
    'account_disk_high' => 'Cuota de disco casi llena',
    'account_cpu_sustained' => 'CPU alta sostenida',
  ];

  /**
   * Points the disk must drop below its threshold before its alert closes.
   *
   * Disk use near the threshold goes up and down a little with logs and
   * mail; without a margin the alert would close and reopen every hour.
   */
  private const DISK_RELEASE_MARGIN = 5;

  /**
   * Creates an AccountAlertEvaluator instance.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AdminOpsEventManager $eventManager,
    private readonly AccountUsageHistory $history,
  ) {}

  /**
   * Evaluates one scheduled reading of every account of a server.
   *
   * Runs after the reading is stored, so the history already holds it.
   *
   * @param array<string, array<string, mixed>> $rows
   *   AccountUsageReport::accountRows(), keyed by cPanel user.
   * @param string[] $missing
   *   The account checks that brought no data in this reading.
   */
  public function evaluate(string $server_id, array $rows, array $missing, int $read_at): void {
    $rules = $this->rules();
    // Without the account list nothing is known about any account.
    if (!$rules['enabled'] || $rows === [] || in_array('get_accounts', $missing, TRUE)) {
      return;
    }

    // Subjects whose problem lasts, by event type: their events stay open.
    $keep = array_fill_keys(array_keys(self::PROBLEMS), []);
    foreach ($rows as $user => $row) {
      $user = (string) $user;
      if (!empty($row['suspendida'])) {
        continue;
      }
      foreach ($this->mail($server_id, $user, $row, $rules, $read_at) as $type) {
        $keep[$type][$user] = TRUE;
      }
      if ($this->disk($server_id, $user, $row, $rules, $read_at)) {
        $keep['account_disk_high'][$user] = TRUE;
      }
      if (in_array('get_account_processes', $missing, TRUE)) {
        // CPU not read: an open CPU alert is neither confirmed nor cleared.
        $keep['account_cpu_sustained'][$user] = TRUE;
      }
      elseif ($this->cpu($server_id, $user, $row, $rules, $read_at)) {
        $keep['account_cpu_sustained'][$user] = TRUE;
      }
    }

    foreach ($keep as $type => $subjects) {
      foreach ($this->eventManager->openEvents($server_id, $type) as $event) {
        $evidence = json_decode((string) $event->get('evidence_json')->value, TRUE);
        $account = is_array($evidence) ? (string) ($evidence['account'] ?? '') : '';
        if (!isset($subjects[$account])) {
          $this->eventManager->resolveCondition($server_id, $type, $account);
        }
      }
    }
  }

  /**
   * Mail sent in the last hour against the package's hourly limit.
   *
   * Reaching the limit is critical (the account may be sending spam and
   * cPanel starts holding its mail); 80 % is a warning. Once at the limit,
   * the alert stays at the limit until the account drops below 80 %, so a
   * falling count does not send a second, milder alert.
   *
   * @return string[]
   *   Event types that stay open for this account.
   */
  private function mail(string $server_id, string $user, array $row, array $rules, int $read_at): array {
    $mail = $row['correo_ultima_hora'] ?? NULL;
    $limit = (int) ($row['limite_correos_hora'] ?? 0);
    if (!is_array($mail)) {
      // The mail was not read: keep whatever is open.
      return ['account_mail_near_limit', 'account_mail_limit', 'account_mail_failed'];
    }

    $keep = [];
    $sent = (int) $mail['enviados'];
    $warning = (float) $rules['mail_percent_warning'];
    if ($limit > 0 && $warning > 0) {
      $percent = $sent * 100 / $limit;
      $text = sprintf('%d de %d correos permitidos por hora (%d %%)', $sent, $limit, (int) round($percent));
      $package = (string) ($row['paquete'] ?? '') !== '' ? ' del paquete ' . $row['paquete'] : ' de su paquete';
      if ($percent >= 100) {
        $this->record($server_id, 'account_mail_limit', 'critical', $row, $read_at,
          'Llegó al límite de correo' . $package . ': ' . $text . '.',
          'Llegó al límite' . $package . ': ' . $text . ' en la última hora. Revisa quién está enviando desde esta cuenta.',
          ['value' => $sent, 'threshold' => $limit]);
        $this->eventManager->resolveCondition($server_id, 'account_mail_near_limit', $user);
        $keep[] = 'account_mail_limit';
      }
      elseif ($percent >= $warning) {
        if ($this->hasOpen($server_id, 'account_mail_limit', $user)) {
          $keep[] = 'account_mail_limit';
        }
        else {
          $this->record($server_id, 'account_mail_near_limit', 'warning', $row, $read_at,
            'Cerca del límite de correo' . $package . ': ' . $text . '.',
            'Envió ' . $text . ' en la última hora. Si la cuenta no suele enviar tanto, revisa quién está enviando.',
            ['value' => $sent, 'threshold' => $limit]);
          $keep[] = 'account_mail_near_limit';
        }
      }
    }

    $failed = (int) $mail['fallidos'];
    $failed_warning = (int) $rules['failed_mail_warning'];
    if ($failed_warning > 0 && $failed >= $failed_warning) {
      $keep[] = 'account_mail_failed';
      if ($this->sustained($server_id, $user, 'mail_failed', $failed_warning, (int) $rules['consecutive_readings'], $read_at)) {
        $this->record($server_id, 'account_mail_failed', 'warning', $row, $read_at,
          sprintf('%d correos fallidos en la última hora.', $failed),
          sprintf('%d de %d correos enviados en la última hora fallaron (umbral %d). Suele ser una lista con direcciones que ya no existen o un remitente rechazado.', $failed, $sent, $failed_warning),
          ['value' => $failed, 'threshold' => $failed_warning]);
      }
    }

    return $keep;
  }

  /**
   * Disk used against the account's quota; accounts without quota skip it.
   *
   * @return bool
   *   Whether the account's disk alert stays open.
   */
  private function disk(string $server_id, string $user, array $row, array $rules, int $read_at): bool {
    $percent = $row['disco_porcentaje'] ?? NULL;
    $threshold = (float) $rules['disk_percent_warning'];
    if (!is_numeric($percent) || $threshold <= 0) {
      return FALSE;
    }
    $percent = (float) $percent;

    if ($percent >= $threshold) {
      $this->record($server_id, 'account_disk_high', 'warning', $row, $read_at,
        sprintf('Cuota de disco al %s %%.', $this->format($percent)),
        sprintf('Usa %s GB de %s GB de su cuota (%s %%, umbral %s %%). Al llenarse deja de recibir correo.', $this->format((float) $row['disco_gb'], 2), $this->format((float) $row['cuota_gb'], 2), $this->format($percent), $this->format($threshold)),
        ['value' => $percent, 'threshold' => $threshold]);
      return TRUE;
    }

    return $percent >= $threshold - self::DISK_RELEASE_MARGIN && $this->hasOpen($server_id, 'account_disk_high', $user);
  }

  /**
   * CPU over the threshold on several readings in a row.
   *
   * The account's share of the whole server, sampled over 3 seconds at each
   * reading. One busy reading is a cron job or a backup; an hour is not.
   *
   * @return bool
   *   Whether the account's CPU alert stays open.
   */
  private function cpu(string $server_id, string $user, array $row, array $rules, int $read_at): bool {
    $cpu = (float) ($row['cpu_porcentaje'] ?? 0);
    $threshold = (float) $rules['cpu_percent_warning'];
    if ($threshold <= 0 || $cpu < $threshold) {
      return FALSE;
    }

    $required = (int) $rules['cpu_consecutive_readings'];
    if ($this->sustained($server_id, $user, 'cpu_percent', $threshold, $required, $read_at)) {
      $minutes = ($required - 1) * $rules['interval_minutes'];
      $this->record($server_id, 'account_cpu_sustained', 'warning', $row, $read_at,
        sprintf('CPU al %s %% del servidor durante %d lecturas seguidas.', $this->format($cpu), $required),
        sprintf('Usa el %s %% de la CPU de todo el servidor y lleva al menos %d minutos por encima del %s %%.', $this->format($cpu), $minutes, $this->format($threshold)),
        ['value' => $cpu, 'threshold' => $threshold]);
    }

    return TRUE;
  }

  /**
   * Whether the account's last N stored readings all reached the threshold.
   *
   * The readings must be the last N scheduled ones: a gap (a failed read, or
   * monitoring stopped for a while) breaks the run.
   */
  private function sustained(string $server_id, string $user, string $column, float $threshold, int $required, int $read_at): bool {
    if ($required <= 1) {
      return TRUE;
    }
    $interval = $this->rules()['interval_minutes'] * 60;
    // N readings span N - 1 intervals; half an interval of slack for cron.
    $since = $read_at - ($required - 1) * $interval - (int) ($interval / 2);
    $readings = $this->history->recent($server_id, $user, $required, $since);
    if (count($readings) < $required) {
      return FALSE;
    }
    foreach ($readings as $reading) {
      if ($reading[$column] === NULL || $reading[$column] < $threshold) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Opens the account's event, or refreshes it while it lasts.
   *
   * @param array<string, mixed> $values
   *   Measured value and threshold, kept as evidence.
   */
  private function record(string $server_id, string $type, string $severity, array $row, int $read_at, string $summary, string $detail, array $values): void {
    $user = (string) $row['cuenta'];
    $this->eventManager->record(
      $server_id,
      $type,
      $severity,
      $user . ': ' . $summary,
      $detail,
      [
        'account' => $user,
        'domain' => (string) ($row['dominio'] ?? ''),
        'problem' => self::PROBLEMS[$type],
        'detail' => $detail,
      ] + $values,
      $read_at,
      $user,
    );
  }

  /**
   * Whether this account has an open event of this type.
   */
  private function hasOpen(string $server_id, string $type, string $user): bool {
    foreach ($this->eventManager->openEvents($server_id, $type) as $event) {
      $evidence = json_decode((string) $event->get('evidence_json')->value, TRUE);
      if (is_array($evidence) && ($evidence['account'] ?? NULL) === $user) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The account alert rules, with defaults for sites not yet updated.
   *
   * @return array<string, mixed>
   *   Rule values.
   */
  private function rules(): array {
    $config = $this->configFactory->get('ai_adminops.settings');
    $alerts = $config->get('alerts');
    $alerts = is_array($alerts) ? $alerts : [];
    $accounts = is_array($alerts['accounts'] ?? NULL) ? $alerts['accounts'] : [];

    return [
      'enabled' => (bool) ($alerts['enabled'] ?? TRUE) && (bool) ($accounts['enabled'] ?? TRUE),
      'mail_percent_warning' => (float) ($accounts['mail_percent_warning'] ?? 80),
      'failed_mail_warning' => (int) ($accounts['failed_mail_warning'] ?? 10),
      'disk_percent_warning' => (float) ($accounts['disk_percent_warning'] ?? 90),
      'cpu_percent_warning' => (float) ($accounts['cpu_percent_warning'] ?? 50),
      'cpu_consecutive_readings' => max(1, (int) ($accounts['cpu_consecutive_readings'] ?? 4)),
      'consecutive_readings' => max(1, (int) ($alerts['consecutive_readings'] ?? 2)),
      'interval_minutes' => max(1, (int) ($config->get('monitoring.interval_minutes') ?: 15)),
    ];
  }

  /**
   * A number without needless decimals.
   */
  private function format(float $value, int $decimals = 1): string {
    return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
  }

}
