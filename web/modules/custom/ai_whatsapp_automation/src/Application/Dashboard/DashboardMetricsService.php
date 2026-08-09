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
  public function getMetrics(?array $range = NULL): array {
    return [
      'summary' => [
        'active_conversations' => $this->countActiveConversations($range),
        'closed_conversations' => $this->countByValue('ai_whatsapp_conversation', 'status', 'CLOSED', $range, 'changed'),
        'sent_messages' => $this->countMessagesBySenders(['ai', 'operator'], $range),
        'received_messages' => $this->countMessagesBySenders(['contact'], $range),
        'generated_leads' => $this->countRows('ai_whatsapp_lead', $range),
        'tokens_consumed' => $this->sumColumn('ai_whatsapp_message', 'tokens', $range),
        'openai_cost' => $this->sumColumn('ai_whatsapp_message', 'cost', $range),
      ],
      'cost_by_bot' => $this->getCostByBot($range),
      'cost_by_channel' => $this->getCostByChannel($range),
    ];
  }

  /**
   * Counts active conversations.
   */
  private function countActiveConversations(?array $range): int {
    $query = $this->database->select('ai_whatsapp_conversation', 'c');
    $query->condition('c.status', ['AI_ACTIVE', 'HUMAN_ASSIGNED'], 'IN');
    $this->applyRange($query, 'c.changed', $range);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts rows in a table.
   */
  private function countRows(string $table, ?array $range = NULL): int {
    $query = $this->database->select($table, 't');
    $this->applyRange($query, 't.created', $range);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts rows by a field value.
   */
  private function countByValue(string $table, string $field, string $value, ?array $range = NULL, string $range_field = 'created'): int {
    $query = $this->database->select($table, 't');
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
  private function countMessagesBySenders(array $senders, ?array $range): int {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->condition('m.sender', $senders, 'IN');
    $this->applyRange($query, 'm.created', $range);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Sums a numeric column.
   */
  private function sumColumn(string $table, string $column, ?array $range): float {
    $query = $this->database->select($table, 't');
    $this->applyRange($query, 't.created', $range);
    $query->addExpression('COALESCE(SUM(t.' . $column . '), 0)');

    return (float) $query->execute()->fetchField();
  }

  /**
   * Returns OpenAI cost grouped by bot.
   *
   * @return array<int, array<string, mixed>>
   *   Cost rows.
   */
  private function getCostByBot(?array $range): array {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->join('ai_whatsapp_conversation', 'c', 'm.conversation = c.id');
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
  private function getCostByChannel(?array $range): array {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->join('ai_whatsapp_conversation', 'c', 'm.conversation = c.id');
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
  private function applyRange(SelectInterface $query, string $column, ?array $range): void {
    if ($range === NULL) {
      return;
    }
    $query->condition($column, $range['start'], '>=');
    $query->condition($column, $range['end'], '<');
  }

}
