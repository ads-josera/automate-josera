<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Dashboard;

use Drupal\Core\Database\Connection;

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
  public function getMetrics(): array {
    return [
      'summary' => [
        'active_conversations' => $this->countActiveConversations(),
        'closed_conversations' => $this->countByValue('ai_whatsapp_conversation', 'status', 'CLOSED'),
        'sent_messages' => $this->countMessagesBySenders(['ai', 'operator']),
        'received_messages' => $this->countMessagesBySenders(['contact']),
        'generated_leads' => $this->countRows('ai_whatsapp_lead'),
        'tokens_consumed' => $this->sumColumn('ai_whatsapp_message', 'tokens'),
        'openai_cost' => $this->sumColumn('ai_whatsapp_message', 'cost'),
      ],
      'cost_by_bot' => $this->getCostByBot(),
      'cost_by_conversation' => $this->getCostByConversation(),
    ];
  }

  /**
   * Counts active conversations.
   */
  private function countActiveConversations(): int {
    $query = $this->database->select('ai_whatsapp_conversation', 'c');
    $query->condition('c.status', ['AI_ACTIVE', 'HUMAN_ASSIGNED'], 'IN');
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts rows in a table.
   */
  private function countRows(string $table): int {
    $query = $this->database->select($table, 't');
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts rows by a field value.
   */
  private function countByValue(string $table, string $field, string $value): int {
    $query = $this->database->select($table, 't');
    $query->condition('t.' . $field, $value);
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Counts messages by sender values.
   *
   * @param string[] $senders
   *   Sender values.
   */
  private function countMessagesBySenders(array $senders): int {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->condition('m.sender', $senders, 'IN');
    $query->addExpression('COUNT(*)');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Sums a numeric column.
   */
  private function sumColumn(string $table, string $column): float {
    $query = $this->database->select($table, 't');
    $query->addExpression('COALESCE(SUM(t.' . $column . '), 0)');

    return (float) $query->execute()->fetchField();
  }

  /**
   * Returns OpenAI cost grouped by bot.
   *
   * @return array<int, array<string, mixed>>
   *   Cost rows.
   */
  private function getCostByBot(): array {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->join('ai_whatsapp_conversation', 'c', 'm.conversation = c.id');
    $query->leftJoin('ai_whatsapp_account', 'a', 'c.whatsapp_account = a.id');
    $query->leftJoin('ai_whatsapp_bot', 'b', 'a.bot = b.id');
    $query->fields('b', ['id', 'name']);
    $query->addExpression('COALESCE(SUM(m.cost), 0)', 'total_cost');
    $query->addExpression('COALESCE(SUM(m.tokens), 0)', 'total_tokens');
    $query->groupBy('b.id');
    $query->groupBy('b.name');
    $query->orderBy('total_cost', 'DESC');
    $query->range(0, 10);

    return array_map(static function (object $row): array {
      return [
        'id' => $row->id === NULL ? NULL : (int) $row->id,
        'name' => (string) ($row->name ?? 'Unassigned'),
        'total_cost' => (float) $row->total_cost,
        'total_tokens' => (int) $row->total_tokens,
      ];
    }, $query->execute()->fetchAll());
  }

  /**
   * Returns OpenAI cost grouped by conversation.
   *
   * @return array<int, array<string, mixed>>
   *   Cost rows.
   */
  private function getCostByConversation(): array {
    $query = $this->database->select('ai_whatsapp_message', 'm');
    $query->join('ai_whatsapp_conversation', 'c', 'm.conversation = c.id');
    $query->fields('c', ['id', 'phone']);
    $query->addExpression('COALESCE(SUM(m.cost), 0)', 'total_cost');
    $query->addExpression('COALESCE(SUM(m.tokens), 0)', 'total_tokens');
    $query->addExpression('COUNT(m.id)', 'message_count');
    $query->groupBy('c.id');
    $query->groupBy('c.phone');
    $query->orderBy('total_cost', 'DESC');
    $query->range(0, 10);

    return array_map(static function (object $row): array {
      return [
        'id' => (int) $row->id,
        'phone' => (string) $row->phone,
        'total_cost' => (float) $row->total_cost,
        'total_tokens' => (int) $row->total_tokens,
        'message_count' => (int) $row->message_count,
      ];
    }, $query->execute()->fetchAll());
  }

}
