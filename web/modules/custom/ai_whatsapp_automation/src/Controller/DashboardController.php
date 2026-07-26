<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\ai_whatsapp_automation\Application\Dashboard\DashboardMetricsService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the AI WhatsApp metrics dashboard.
 */
final class DashboardController extends ControllerBase {

  /**
   * Constructs a DashboardController object.
   */
  public function __construct(
    private readonly DashboardMetricsService $metricsService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_whatsapp_automation.dashboard_metrics'),
    );
  }

  /**
   * Builds the dashboard page.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function dashboard(): array {
    $metrics = $this->metricsService->getMetrics();
    $summary = $metrics['summary'];

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-dashboard']],
      '#attached' => [
        'library' => [
          'ai_whatsapp_automation/dashboard',
        ],
      ],
      'overview' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__overview']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__eyebrow']],
          '#value' => $this->t('Operations overview'),
        ],
        'lead' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__lead']],
          '#value' => $this->t('Live totals from conversations, messages, leads, tokens, and estimated OpenAI cost.'),
        ],
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__kpis']],
        'active_conversations' => $this->buildKpiCard($this->t('Active conversations'), number_format((int) $summary['active_conversations']), 'success'),
        'closed_conversations' => $this->buildKpiCard($this->t('Closed conversations'), number_format((int) $summary['closed_conversations']), 'neutral'),
        'sent_messages' => $this->buildKpiCard($this->t('Sent messages'), number_format((int) $summary['sent_messages']), 'info'),
        'received_messages' => $this->buildKpiCard($this->t('Received messages'), number_format((int) $summary['received_messages']), 'warning'),
        'generated_leads' => $this->buildKpiCard($this->t('Generated leads'), number_format((int) $summary['generated_leads']), 'success'),
        'tokens_consumed' => $this->buildKpiCard($this->t('Tokens consumed'), number_format((int) $summary['tokens_consumed']), 'info'),
        'openai_cost' => $this->buildKpiCard($this->t('OpenAI cost'), '$' . number_format((float) $summary['openai_cost'], 6), 'cost'),
      ],
      'rankings' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__rankings']],
        'cost_by_bot' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h2',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel-title']],
            '#value' => $this->t('Cost by bot'),
          ],
          'table' => [
            '#type' => 'table',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__table']],
            '#header' => [
              $this->t('Bot'),
              $this->t('AI tokens'),
              $this->t('Estimated cost'),
            ],
            '#rows' => $this->buildCostByBotRows($metrics['cost_by_bot']),
            '#empty' => $this->t('No bot cost data available.'),
          ],
        ],
        'cost_by_conversation' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h2',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel-title']],
            '#value' => $this->t('Highest-cost conversations'),
          ],
          'table' => [
            '#type' => 'table',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__table']],
            '#header' => [
              $this->t('Conversation'),
              $this->t('Messages'),
              $this->t('AI tokens'),
              $this->t('Estimated cost'),
            ],
            '#rows' => $this->buildCostByConversationRows($metrics['cost_by_conversation']),
            '#empty' => $this->t('No conversation cost data available.'),
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Builds a KPI card.
   */
  private function buildKpiCard(string|\Stringable $label, string $value, string $tone): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-dashboard__kpi', 'ai-whatsapp-dashboard__kpi--' . $tone]],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__kpi-label']],
        '#value' => $label,
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__kpi-value']],
        '#value' => $value,
      ],
    ];
  }

  /**
   * Builds bot cost table rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Metric rows.
   *
   * @return array<int, array<int, array<string, mixed>>>
   *   Table rows.
   */
  private function buildCostByBotRows(array $rows): array {
    return array_map(function (array $row): array {
      return [
        [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__bot']],
            'name' => ['#plain_text' => $row['name'] !== '' ? (string) $row['name'] : (string) $this->t('Unassigned')],
            'hint' => ['#markup' => '<span>' . $this->t('All tracked conversations') . '</span>'],
          ],
        ],
        ['data' => ['#markup' => $this->formatTokens((int) $row['total_tokens'])]],
        ['data' => ['#markup' => $this->formatCost((float) $row['total_cost'])]],
      ];
    }, $rows);
  }

  /**
   * Builds conversation cost table rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Metric rows.
   *
   * @return array<int, array<int, array<string, mixed>>>
   *   Table rows.
   */
  private function buildCostByConversationRows(array $rows): array {
    return array_map(function (array $row): array {
      $is_web = $row['provider'] === 'web';
      $label = $is_web
        ? (string) $this->t('Web visitor')
        : ($row['name'] !== '' ? (string) $row['name'] : (string) $row['phone']);
      $source = $is_web
        ? (string) $this->t('Web chat')
        : (string) $this->t('WhatsApp') . ' · ' . $this->providerLabel((string) $row['provider']);
      $link = Link::fromTextAndUrl($label, Url::fromRoute('entity.ai_whatsapp_conversation.canonical', [
        'ai_whatsapp_conversation' => $row['id'],
      ]))->toRenderable();

      return [
        [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__conversation']],
            'link' => $link,
            'meta' => ['#markup' => '<span>' . $source . ' · #' . (int) $row['id'] . '</span>'],
          ],
        ],
        ['data' => ['#markup' => (string) (int) $row['message_count']]],
        ['data' => ['#markup' => $this->formatTokens((int) $row['total_tokens'])]],
        ['data' => ['#markup' => $this->formatCost((float) $row['total_cost'])]],
      ];
    }, $rows);
  }

  /**
   * Formats token counts for fast scanning.
   */
  private function formatTokens(int $tokens): string {
    if ($tokens >= 1000) {
      return number_format($tokens / 1000, 1) . 'k';
    }

    return number_format($tokens);
  }

  /**
   * Formats small costs without visually noisy trailing digits.
   */
  private function formatCost(float $cost): string {
    return '$' . number_format($cost, $cost < 0.01 ? 4 : 2);
  }

  /**
   * Returns a human-readable provider label.
   */
  private function providerLabel(string $provider): string {
    return match ($provider) {
      'twilio' => 'Twilio',
      'cloud_api' => 'Cloud API',
      'evolution' => 'Evolution',
      default => $provider,
    };
  }

}
