<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Dashboard;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Provides optimized dashboard metrics.
 */
final class DashboardMetricsService {

  /**
   * Constructs a DashboardMetricsService object.
   */
  public function __construct(
    private readonly Connection $database,
  ) {
  }

  /**
   * Returns all dashboard metrics.
   *
   * @return array<string, mixed>
   *   Dashboard metrics.
   */
  public function getMetrics(?array $range = NULL, int $client_id = 0): array {
    return [
      'summary' => [
        'active_conversations' => $this->countActiveConversations($range, $client_id),
        'closed_conversations' => $this->countByValue('ai_whatsapp_conversation', 'status', 'CLOSED', $range, 'changed', $client_id),
        'sent_messages' => $this->countMessagesBySenders(['ai', 'operator'], $range, $client_id),
        'received_messages' => $this->countMessagesBySenders(['contact'], $range, $client_id),
        'generated_leads' => $this->countRows('ai_whatsapp_lead', $range, $client_id),
        'tokens_consumed' => $this->sumMessageColumn('tokens', $range, $client_id),
        'openai_cost' => $this->sumMessageColumn('cost', $range, $client_id),
      ],
      'cost_by_bot' => $this->getCostByBot($range, $client_id),
      'cost_by_channel' => $this->getCostByChannel($range, $client_id),
      'activity' => $this->getActivitySeries($range, $client_id),
    ];
  }

  /**
   * Returns messages per day, split between what came in and what went out.
   *
   * Without a range (period "all time") the last 30 days are returned: a
   * chart of every day since the first message would be unreadable.
   *
   * @return array<int, array{day: string, received: int, sent: int}>
   *   One row per day, oldest first, with empty days filled in.
   */
  public function getActivitySeries(?array $range, int $client_id, int $max_days = 30): array {
    $end = $range === NULL ? time() : min($range['end'], time());
    $start = $range === NULL
      ? strtotime('-' . ($max_days - 1) . ' days', (int) strtotime('today', $end))
      : $range['start'];
    // Long periods (a year) would give unreadable daily bars.
    $days = (int) floor(($end - $start) / 86400) + 1;
    if ($days > $max_days) {
      $start = strtotime('-' . ($max_days - 1) . ' days', (int) strtotime('today', $end));
      $days = $max_days;
    }

    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->fields('m', ['created', 'sender']);
    $this->applyMessageClient($query, $client_id);
    $query->condition('m.created', $start, '>=');
    $query->condition('m.created', $end + 1, '<');
    // Counted in PHP: the window is at most 30 days, and grouping by day in
    // SQL would tie the dashboard to one database's date functions.
    $counted = [];
    foreach ($query->execute() as $row) {
      $day = date('Y-m-d', (int) $row->created);
      $counted[$day] ??= ['received' => 0, 'sent' => 0];
      $counted[$day][$row->sender === 'contact' ? 'received' : 'sent']++;
    }

    $series = [];
    for ($i = 0; $i < $days; $i++) {
      $day = date('Y-m-d', strtotime('+' . $i . ' days', (int) strtotime('today', $start)));
      $series[] = [
        'day' => $day,
        'received' => $counted[$day]['received'] ?? 0,
        'sent' => $counted[$day]['sent'] ?? 0,
      ];
    }

    return $series;
  }

  /**
   * Counts active conversations.
   */
  private function countActiveConversations(?array $range, int $client_id): int {
    $query = $this->database->select('ai_whatsapp_conversation', 'c');
    $query->condition('c.status', ['AI_ACTIVE', 'HUMAN_ASSIGNED'], 'IN');
    $this->applyClient($query, 'c.client', $client_id);
    $this->applyRange($query, 'c.changed', $range);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts rows in a table.
   */
  private function countRows(string $table, ?array $range, int $client_id): int {
    $query = $this->database->select($table, 't');
    $this->applyClient($query, 't.client', $client_id);
    $this->applyRange($query, 't.created', $range);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts rows by a field value.
   */
  private function countByValue(string $table, string $field, string $value, ?array $range, string $range_field, int $client_id): int {
    $query = $this->database->select($table, 't');
    $this->applyClient($query, 't.client', $client_id);
    $query->condition('t.' . $field, $value);
    $this->applyRange($query, 't.' . $range_field, $range);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts messages by sender values.
   *
   * @param string[] $senders
   *   Sender values.
   */
  private function countMessagesBySenders(array $senders, ?array $range, int $client_id): int {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $this->applyMessageClient($query, $client_id);
    $query->condition('m.sender', $senders, 'IN');
    $this->applyRange($query, 'm.created', $range);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Sums a numeric column.
   */
  private function sumMessageColumn(string $column, ?array $range, int $client_id): float {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $this->applyMessageClient($query, $client_id);
    $this->applyRange($query, 'm.created', $range);
    $query->addExpression('COALESCE(SUM(m.' . $column . '), 0)');

    return (float) $query->execute()->fetchField();
  }

  /**
   * Returns OpenAI cost grouped by bot.
   *
   * @return array<int, array<string, mixed>>
   *   Cost rows.
   */
  private function getCostByBot(?array $range, int $client_id): array {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->join('ai_whatsapp_conversation', 'c', 'm.conversation = c.id');
    $this->applyClient($query, 'c.client', $client_id);
    $query->leftJoin('ai_whatsapp_account', 'a', 'c.whatsapp_account = a.id');
    $query->leftJoin('ai_whatsapp_bot', 'direct_bot', 'c.bot = direct_bot.id');
    $query->leftJoin('ai_whatsapp_bot', 'account_bot', 'a.bot = account_bot.id');
    $query->addExpression('COALESCE(direct_bot.id, account_bot.id)', 'id');
    $query->addExpression("COALESCE(direct_bot.name, account_bot.name, 'Unassigned')", 'name');
    $query->addExpression('COALESCE(SUM(m.cost), 0)', 'total_cost');
    $query->addExpression('COALESCE(SUM(m.tokens), 0)', 'total_tokens');
    $this->applyRange($query, 'm.created', $range);
    $query->groupBy('direct_bot.id');
    $query->groupBy('direct_bot.name');
    $query->groupBy('account_bot.id');
    $query->groupBy('account_bot.name');
    $query->orderBy('total_cost', 'DESC');
    $query->range(0, 10);

    $grouped = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $name = (string) ($row->name ?? 'Unassigned');
      if (!isset($grouped[$name])) {
        $grouped[$name] = [
          'id' => $row->id === NULL ? NULL : (int) $row->id,
          'name' => $name,
          'total_cost' => 0.0,
          'total_tokens' => 0,
        ];
      }
      $grouped[$name]['total_cost'] += (float) $row->total_cost;
      $grouped[$name]['total_tokens'] += (int) $row->total_tokens;
    }

    usort($grouped, static fn (array $left, array $right): int => $right['total_cost'] <=> $left['total_cost']);

    return array_values($grouped);
  }

  /**
   * Returns OpenAI cost grouped by channel and resolved bot.
   *
   * @return array<int, array<string, mixed>>
   *   Cost rows.
   */
  private function getCostByChannel(?array $range, int $client_id): array {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->join('ai_whatsapp_conversation', 'c', 'm.conversation = c.id');
    $this->applyClient($query, 'c.client', $client_id);
    $query->leftJoin('ai_whatsapp_account', 'a', 'c.whatsapp_account = a.id');
    $query->leftJoin('ai_whatsapp_bot', 'direct_bot', 'c.bot = direct_bot.id');
    $query->leftJoin('ai_whatsapp_bot', 'account_bot', 'a.bot = account_bot.id');
    $query->fields('c', ['provider']);
    $query->addExpression('COALESCE(direct_bot.id, account_bot.id)', 'bot_id');
    $query->addExpression("COALESCE(direct_bot.name, account_bot.name, 'Unassigned')", 'bot_name');
    $query->addExpression('COALESCE(SUM(m.cost), 0)', 'total_cost');
    $query->addExpression('COALESCE(SUM(m.tokens), 0)', 'total_tokens');
    $query->addExpression('COUNT(m.id)', 'message_count');
    $query->addExpression('COUNT(DISTINCT c.id)', 'conversation_count');
    $this->applyRange($query, 'm.created', $range);
    $query->groupBy('c.provider');
    $query->groupBy('direct_bot.id');
    $query->groupBy('direct_bot.name');
    $query->groupBy('account_bot.id');
    $query->groupBy('account_bot.name');
    $query->orderBy('total_cost', 'DESC');
    $query->range(0, 10);

    return array_map(static function (object $row): array {
      return [
        'provider' => (string) $row->provider,
        'bot_id' => $row->bot_id === NULL ? NULL : (int) $row->bot_id,
        'bot_name' => (string) $row->bot_name,
        'total_cost' => (float) $row->total_cost,
        'total_tokens' => (int) $row->total_tokens,
        'message_count' => (int) $row->message_count,
        'conversation_count' => (int) $row->conversation_count,
      ];
    }, $query->execute()->fetchAll());
  }

  /**
   * Applies an inclusive/exclusive timestamp range to a database query.
   */
  /**
   * Restricts a query to one client; 0 means all clients, -1 none.
   */
  private function applyClient(SelectInterface $query, string $column, int $client_id): void {
    if ($client_id !== 0) {
      $query->condition($column, $client_id);
    }
  }

  /**
   * Restricts a message query to one client through its conversation.
   */
  private function applyMessageClient(SelectInterface $query, int $client_id): void {
    if ($client_id !== 0) {
      $query->join('ai_whatsapp_conversation', 'mc', 'm.conversation = mc.id');
      $query->condition('mc.client', $client_id);
    }
  }

  private function applyRange(SelectInterface $query, string $column, ?array $range): void {
    if ($range === NULL) {
      return;
    }
    $query->condition($column, $range['start'], '>=');
    $query->condition($column, $range['end'], '<');
  }

}
