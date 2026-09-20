<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Dashboard;

use Drupal\ai_whatsapp_automation\Ui\ActivityChart;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daily activity behind the dashboard chart.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ActivitySeriesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'options',
    'text',
    'views',
    'ai_whatsapp_automation',
  ];

  /**
   * Conversations of two clients, keyed by client key.
   *
   * @var array<string, \Drupal\Core\Entity\ContentEntityInterface>
   */
  private array $conversations = [];

  /**
   * Client IDs, keyed by client key.
   *
   * @var array<string, int>
   */
  private array $clients = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    foreach ([
      'ai_whatsapp_client',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['system', 'ai_whatsapp_automation']);

    foreach (['a' => 'JG Mylard', 'b' => 'Laboratorio JVC'] as $key => $name) {
      $client = $this->create('ai_whatsapp_client', ['name' => $name]);
      $this->clients[$key] = (int) $client->id();
      $bot = $this->create('ai_whatsapp_bot', ['name' => 'Bot ' . $key, 'status' => 'active', 'client' => $client->id()]);
      $this->conversations[$key] = $this->create('ai_whatsapp_conversation', [
        'phone' => '+5255000000' . ($key === 'a' ? '01' : '02'),
        'channel' => 'whatsapp',
        'provider' => 'twilio',
        'status' => 'AI_ACTIVE',
        'bot' => $bot->id(),
        'client' => $client->id(),
      ]);
    }
  }

  /**
   * Every day of the window is returned, including the days without messages.
   */
  public function testTheWindowIsCompleteAndCounted(): void {
    // Times in the past: a message that has not happened yet is not counted,
    // and "noon today" is still in the future early in the morning.
    $now = \Drupal::time()->getRequestTime() - 60;
    $this->message('a', 'contact', $now);
    $this->message('a', 'contact', $now);
    $this->message('a', 'ai', $now);
    $this->message('a', 'ai', $now - 86400);
    // Older than the window: it must not appear.
    $this->message('a', 'ai', $now - (40 * 86400));

    $series = $this->service()->getActivitySeries(NULL, 0);

    $this->assertCount(30, $series, 'Without a period, the last 30 days are shown');
    $this->assertSame($this->day($now), end($series)['day'], 'The window ends on the day of the last message');
    $last = end($series);
    $this->assertSame(2, $last['received']);
    $this->assertSame(1, $last['sent']);
    $yesterday = $series[count($series) - 2];
    $this->assertSame(['received' => 0, 'sent' => 1], ['received' => $yesterday['received'], 'sent' => $yesterday['sent']]);
    $this->assertSame(0, array_sum(array_column(array_slice($series, 0, 28), 'sent')), 'Older messages are outside the window');
  }

  /**
   * A client only sees the messages of their own conversations.
   */
  public function testTheSeriesIsScopedToOneClient(): void {
    $now = \Drupal::time()->getRequestTime() - 60;
    $this->message('a', 'contact', $now);
    $this->message('b', 'contact', $now);
    $this->message('b', 'contact', $now);

    $own = $this->service()->getActivitySeries(NULL, $this->clients['a']);
    $this->assertSame(1, end($own)['received'], 'Only the own client is counted');

    $other = $this->service()->getActivitySeries(NULL, $this->clients['b']);
    $this->assertSame(2, end($other)['received']);
  }

  /**
   * A chosen period returns exactly its days.
   */
  public function testAPeriodReturnsItsOwnDays(): void {
    $start = (int) strtotime('today') - (2 * 86400);
    $end = (int) strtotime('today') + 86400;
    $this->message('a', 'contact', $start + 3600);

    $series = $this->service()->getActivitySeries(['start' => $start, 'end' => $end], 0);

    $this->assertSame($this->day($start), $series[0]['day']);
    $this->assertSame(1, $series[0]['received']);
    $this->assertLessThanOrEqual(4, count($series));
  }

  /**
   * The chart draws both series and says so when there is nothing.
   */
  public function testTheChartDrawsTheSeries(): void {
    $rendered = (string) $this->container->get('renderer')->renderInIsolation(
      ActivityChart::build([
        ['day' => '2026-09-18', 'received' => 3, 'sent' => 2],
        ['day' => '2026-09-19', 'received' => 0, 'sent' => 0],
      ])
    );
    $this->assertStringContainsString('<svg', $rendered, 'The SVG is not stripped by the renderer');
    $this->assertSame(2, substr_count($rendered, '<rect'), 'One hover band per day');
    $this->assertStringContainsString('aiwa-chart__line--received', $rendered, 'Both series are drawn');
    $this->assertStringContainsString('aiwa-chart__line--sent', $rendered);
    $this->assertStringContainsString('18 sep', $rendered, 'Days are labelled in Spanish');

    $empty = (string) $this->container->get('renderer')->renderInIsolation(
      ActivityChart::build([['day' => '2026-09-19', 'received' => 0, 'sent' => 0]])
    );
    $this->assertStringContainsString('Todavía no hay mensajes', $empty);
    $this->assertStringNotContainsString('<rect', $empty);
  }

  /**
   * Returns the day a timestamp belongs to, in the panel's time zone.
   */
  private function day(int $timestamp): string {
    return $this->container->get('date.formatter')->format($timestamp, 'custom', 'Y-m-d');
  }

  /**
   * Returns the metrics service.
   */
  private function service(): \Drupal\ai_whatsapp_automation\Application\Dashboard\DashboardMetricsService {
    return $this->container->get('ai_whatsapp_automation.dashboard_metrics');
  }

  /**
   * Creates a message on a given day.
   */
  private function message(string $client_key, string $sender, int $created): void {
    $message = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_message')->create([
      'conversation' => $this->conversations[$client_key]->id(),
      'sender' => $sender,
      'content' => 'Hola',
    ]);
    $message->set('created', $created);
    $message->save();
  }

  /**
   * Creates and saves an entity.
   *
   * @param array<string, mixed> $values
   *   Field values.
   */
  private function create(string $entity_type_id, array $values): ContentEntityInterface {
    $entity = $this->container->get('entity_type.manager')->getStorage($entity_type_id)->create($values);
    $entity->save();

    return $entity;
  }

}
