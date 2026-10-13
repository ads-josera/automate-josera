<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads every cPanel account on a scheduled run and stores the history.
 *
 * Phase C2 (2026-10-13). Runs inside the monitoring job, after the server
 * checks. Each of the three reads is in the AdminOps log, with a short
 * summary instead of WHM's full answer (about 30 KB a reading): the full
 * data lives in the usage history, which keeps 30 days.
 */
final class AccountUsageCollector {

  /**
   * Creates an AccountUsageCollector instance.
   */
  public function __construct(
    private readonly MonitoringCollectorInterface $collector,
    private readonly AdminOpsExecutionAudit $executionAudit,
    private readonly AccountUsageReport $report,
    private readonly AccountUsageHistory $history,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reads the accounts of one server and stores one row per account.
   *
   * @return array{cuentas: int, sin_dato: string[]}
   *   How many accounts were stored, and which reads brought no data.
   */
  public function collect(ConfigEntityInterface $server, int $read_at): array {
    $server_id = (string) $server->id();
    $readings = [];
    foreach (AccountUsageReport::CHECKS as $check => $label) {
      $execution = $this->executionAudit->begin($server_id, $check, $label, ['source' => 'scheduled_monitoring']);
      $this->executionAudit->markRunning((int) $execution->id());
      try {
        $readings[$check] = $this->collector->collect($server, $check);
        $summary = ['status' => (string) ($readings[$check]['status'] ?? 'unknown')];
        ($readings[$check]['status'] ?? '') === 'ok'
          ? $this->executionAudit->markSucceeded((int) $execution->id(), $summary + ['detalle' => 'Guardado en el historial por cuenta.'])
          : $this->executionAudit->markNoData((int) $execution->id(), $summary);
      }
      catch (\Throwable) {
        $readings[$check] = ['status' => 'failed'];
        $this->executionAudit->markFailed((int) $execution->id(), ['status' => 'failed']);
      }
    }

    $missing = array_keys(array_filter($readings, static fn (array $reading) => ($reading['status'] ?? '') !== 'ok'));
    $rows = $this->report->accountRows($readings['get_accounts'], $readings['get_mail_by_account'], $readings['get_account_processes']);
    $stored = $this->history->record($server_id, $rows, $read_at);
    $this->logger->info('AdminOps stored usage of @count cPanel account(s) for server @server.', [
      '@count' => (string) $stored,
      '@server' => $server_id,
    ]);

    return ['cuentas' => $stored, 'sin_dato' => $missing];
  }

}
