<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\ai_whatsapp_automation\Ui\ActivityChart;
use Drupal\ai_whatsapp_automation\Ui\ClientFilter;
use Drupal\ai_whatsapp_automation\Ui\ResponsiveTable;
use Drupal\ai_whatsapp_automation\Application\Dashboard\DashboardMetricsService;
use Drupal\ai_whatsapp_automation\Form\DashboardFilterForm;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the AI WhatsApp metrics dashboard.
 */
final class DashboardController extends ControllerBase {


  /**
   * Inline icons of the KPI cards, drawn on a 24x24 grid.
   */
  private const ICONS = [
    'chat' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.7 7.1L4 20.5l1.4-5A8 8 0 1 1 21 12Z"/></svg>',
    'check' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6.5 9.5 17 4 11.5"/></svg>',
    'inbox' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13h5l1.5 3h5L16 13h5M3 13l3-8h12l3 8v6H3v-6Z"/></svg>',
    'send' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 3 3 10.5l7 2.5 2.5 7L21 3Z"/></svg>',
    'star' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3.5 2.7 5.6 6.1.9-4.4 4.3 1 6.2-5.4-2.9-5.4 2.9 1-6.2L3.2 10l6.1-.9L12 3.5Z"/></svg>',
    'chip' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 8h8v8H8zM4 10h4M4 14h4M16 10h4M16 14h4M10 4v4M14 4v4M10 16v4M14 16v4"/></svg>',
    'cost' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M16 7.5C16 6 14.2 5 12 5S8 6 8 7.5 9.8 10 12 10.5s4 1.5 4 3-1.8 2.5-4 2.5-4-1-4-2.5"/></svg>',
  ];

  /**
   * Constructs a DashboardController object.
   */
  public function __construct(
    private readonly DashboardMetricsService $metricsService,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_whatsapp_automation.dashboard_metrics'),
      $container->get('request_stack'),
    );
  }

  /**
   * Builds the dashboard page.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function dashboard(): array {
    $period = $this->periodRange();
    $request = $this->requestStack->getCurrentRequest();
    $client_access = \Drupal::service('ai_whatsapp_automation.client_access');
    $is_admin = $client_access->isAdmin($this->currentUser());
    // Client users always see their own client; -1 (no client) matches nothing.
    $client_id = $is_admin
      ? ($request ? ClientFilter::selectedId($request) : 0)
      : ($client_access->clientId($this->currentUser()) ?? -1);
    $metrics = $this->metricsService->getMetrics($period['range'], $client_id);
    $summary = $metrics['summary'];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-dashboard']],
      '#attached' => [
        'library' => [
          'ai_whatsapp_automation/dashboard',
        ],
      ],
      'filters' => \Drupal::formBuilder()->getForm(DashboardFilterForm::class),
      'overview' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__overview']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__eyebrow']],
          '#value' => $this->t('Resumen de operación'),
        ],
        'lead' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__lead']],
          '#value' => $is_admin
            ? $this->t('Conversaciones, mensajes, prospectos y consumo del modelo en @period.', ['@period' => $period['label']])
            : $this->t('Conversaciones, mensajes y prospectos de tu chatbot en @period.', ['@period' => $period['label']]),
        ],
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__kpis']],
        'active_conversations' => $this->buildKpiCard($this->t('Conversaciones activas'), number_format((int) $summary['active_conversations']), 'success', $this->t('Abiertas en este momento'), 'chat'),
        'closed_conversations' => $this->buildKpiCard($this->t('Conversaciones cerradas'), number_format((int) $summary['closed_conversations']), 'neutral', $this->t('Atendidas y finalizadas'), 'check'),
        'received_messages' => $this->buildKpiCard($this->t('Mensajes recibidos'), number_format((int) $summary['received_messages']), 'warning', $this->t('Escritos por tus clientes'), 'inbox'),
        'sent_messages' => $this->buildKpiCard($this->t('Mensajes enviados'), number_format((int) $summary['sent_messages']), 'info', $this->t('Respuestas de la IA y del equipo'), 'send'),
        'generated_leads' => $this->buildKpiCard($this->t('Prospectos generados'), number_format((int) $summary['generated_leads']), 'lead', $this->t('Listos para dar seguimiento'), 'star'),
        'tokens_consumed' => $this->buildKpiCard($this->t('Tokens consumidos'), number_format((int) $summary['tokens_consumed']), 'info', $this->t('Consumo del modelo'), 'chip'),
        'openai_cost' => $this->buildKpiCard($this->t('Costo de OpenAI'), '$' . number_format((float) $summary['openai_cost'], 4), 'cost', $this->t('Estimado del periodo'), 'cost'),
      ],
      'activity' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel', 'ai-whatsapp-dashboard__panel--chart']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel-title']],
          '#value' => $this->t('Actividad por día'),
        ],
        'hint' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel-hint']],
          '#value' => $this->t('Mensajes intercambiados; en periodos largos se muestran los últimos 30 días.'),
        ],
        'chart' => ActivityChart::build($metrics['activity']),
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
            '#value' => $this->t('Costo por bot'),
          ],
          'table' => [
            '#type' => 'table',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__table']],
            '#header' => [
              $this->t('Bot'),
              $this->t('Tokens de IA'),
              $this->t('Costo estimado'),
            ],
            '#rows' => $this->buildCostByBotRows($metrics['cost_by_bot']),
            '#empty' => $this->t('Todavía no hay consumo registrado por bot.'),
          ],
        ],
        'cost_by_channel' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h2',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__panel-title']],
            '#value' => $this->t('Costo por canal y bot'),
          ],
          'table' => [
            '#type' => 'table',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__table']],
            '#header' => [
              $this->t('Canal'),
              $this->t('Bot'),
              $this->t('Conversaciones'),
              $this->t('Mensajes'),
              $this->t('Tokens de IA'),
              $this->t('Costo estimado'),
            ],
            '#rows' => $this->buildCostByChannelRows($metrics['cost_by_channel']),
            '#empty' => $this->t('Todavía no hay consumo registrado por canal.'),
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
    foreach (['cost_by_bot', 'cost_by_channel'] as $panel) {
      $build['rankings'][$panel]['table'] = ResponsiveTable::wrap($build['rankings'][$panel]['table']);
    }

    // Costs and tokens are for the administrator only: clients are billed by
    // plan, not by consumption.
    if (!$is_admin) {
      unset($build['summary']['tokens_consumed'], $build['summary']['openai_cost'], $build['rankings']);
      $build['#cache']['contexts'][] = 'user';
    }

    return $build;
  }

  /**
   * Builds a KPI card.
   *
   * The hint says what the number means: "21" alone tells an operator
   * nothing, "21 conversaciones cerradas · atendidas y finalizadas" does.
   */
  private function buildKpiCard(string|\Stringable $label, string $value, string $tone, string|\Stringable $hint = '', string $icon = ''): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-dashboard__kpi', 'ai-whatsapp-dashboard__kpi--' . $tone]],
      'icon' => $icon === '' ? [] : [
        '#markup' => Markup::create('<span class="ai-whatsapp-dashboard__kpi-icon">' . self::ICONS[$icon] . '</span>'),
      ],
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
      'hint' => $hint === '' ? [] : [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['ai-whatsapp-dashboard__kpi-hint']],
        '#value' => $hint,
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
            'name' => ['#plain_text' => $row['name'] !== '' ? (string) $row['name'] : (string) $this->t('Sin asignar')],
            'hint' => ['#markup' => '<span>' . $this->t('Todas sus conversaciones') . '</span>'],
          ],
        ],
        ['data' => ['#markup' => $this->formatTokens((int) $row['total_tokens'])]],
        ['data' => ['#markup' => $this->formatCost((float) $row['total_cost'])]],
      ];
    }, $rows);
  }

  /**
   * Builds channel and bot cost table rows.
   *
   * @param array<int, array<string, mixed>> $rows
   *   Metric rows.
   *
   * @return array<int, array<int, array<string, mixed>>>
   *   Table rows.
   */
  private function buildCostByChannelRows(array $rows): array {
    return array_map(function (array $row): array {
      $is_web = $row['provider'] === 'web';
      $channel = $is_web
        ? (string) $this->t('Chat web')
        : (string) $this->t('WhatsApp');
      $provider = $is_web ? '' : $this->providerLabel((string) $row['provider']);

      return [
        [
          'data' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['ai-whatsapp-dashboard__channel']],
            'name' => ['#plain_text' => $channel],
            'meta' => $provider === '' ? [] : ['#markup' => '<span>' . $provider . '</span>'],
          ],
        ],
        ['data' => ['#plain_text' => $row['bot_name'] !== '' ? (string) $row['bot_name'] : (string) $this->t('Sin asignar')]],
        ['data' => ['#markup' => (string) (int) $row['conversation_count']]],
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

  /**
   * Resolves the requested reporting period around a reference date.
   *
   * @return array{range: ?array{start: int, end: int}, label: string}
   *   Timestamp range and human-readable period label.
   */
  private function periodRange(): array {
    $request = $this->requestStack->getCurrentRequest();
    $period = (string) $request?->query->get('period', 'month');
    $date_value = (string) $request?->query->get('date', date('Y-m-d'));
    try {
      $date = new \DateTimeImmutable($date_value ?: 'today');
    }
    catch (\Exception) {
      $date = new \DateTimeImmutable('today');
    }

    if ($period === 'all') {
      return [
        'range' => NULL,
        'label' => (string) $this->t('todo el histórico'),
      ];
    }

    $start = match ($period) {
      'day' => $date->setTime(0, 0),
      'year' => $date->setDate((int) $date->format('Y'), 1, 1)->setTime(0, 0),
      default => $date->modify('first day of this month')->setTime(0, 0),
    };
    $end = match ($period) {
      'day' => $start->modify('+1 day'),
      'year' => $start->modify('+1 year'),
      default => $start->modify('+1 month'),
    };
    $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $month = $months[(int) $start->format('n') - 1];
    $label = match ($period) {
      'day' => $start->format('j') . ' de ' . $month . ' de ' . $start->format('Y'),
      'year' => $start->format('Y'),
      default => $month . ' de ' . $start->format('Y'),
    };

    return [
      'range' => [
        'start' => $start->getTimestamp(),
        'end' => $end->getTimestamp(),
      ],
      'label' => $label,
    ];
  }

}
