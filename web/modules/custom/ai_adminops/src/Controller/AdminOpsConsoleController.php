<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the operational console lists for AI AdminOps.
 */
final class AdminOpsConsoleController extends ControllerBase {

  /**
   * Creates an AdminOpsConsoleController instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $adminOpsEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $adminOpsConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('config.factory'),
    );
  }

  /**
   * Lists registered infrastructure targets.
   */
  public function servers(): array {
    $storage = $this->adminOpsEntityTypeManager->getStorage('ai_adminops_server');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('label')->execute();
    $servers = $storage->loadMultiple($ids);
    $rows = [];

    foreach ($servers as $server) {
      $active = (bool) $server->get('active');
      $rows[] = [
        'data' => [
          ['data' => $this->serverCell($server)],
          ['data' => ['#markup' => $this->badge((string) $server->get('server_status'), 'status')]],
          ['data' => ['#plain_text' => $this->connectionLabel((string) $server->get('connection_type'))]],
          ['data' => ['#plain_text' => (string) $server->get('provider') ?: '-']],
          ['data' => ['#markup' => $this->badge($active ? 'Active' : 'Paused', $active ? 'good' : 'muted')]],
          ['data' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('ai_adminops.server_edit', ['ai_adminops_server' => $server->id()]))->toRenderable()],
        ],
      ];
    }

    return $this->consolePage(
      $this->t('Servers'),
      $this->t('Infrastructure targets are registered here. Credential references are identifiers only; secrets are never stored in this console.'),
      [
        '#type' => 'link',
        '#title' => $this->t('Add server'),
        '#url' => Url::fromRoute('ai_adminops.server_add'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      [
        '#theme' => 'table',
        '#header' => [$this->t('Server'), $this->t('Status'), $this->t('Connection'), $this->t('Provider'), $this->t('Monitoring'), $this->t('Operations')],
        '#rows' => $rows,
        '#empty' => $this->t('No servers are registered yet. Add a server to prepare its monitoring profile.'),
        '#attributes' => ['class' => ['ai-adminops-table']],
      ],
    );
  }

  /**
   * Lists recent operational events.
   */
  public function events(): array {
    $storage = $this->adminOpsEntityTypeManager->getStorage('ai_adminops_event');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('occurred_at', 'DESC')->range(0, 100)->execute();
    $rows = [];

    foreach ($storage->loadMultiple($ids) as $event) {
      $rows[] = [
        'data' => [
          ['data' => $this->serverReferenceCell($event)],
          ['data' => ['#markup' => $this->badge((string) $event->get('severity')->value, 'severity')]],
          ['data' => ['#markup' => '<strong>' . $this->escape((string) $event->label()) . '</strong><div class="ai-adminops-table__secondary">' . $this->escape((string) $event->get('event_type')->value) . '</div>']],
          ['data' => ['#markup' => $this->badge((string) $event->get('status')->value, 'status')]],
          ['data' => ['#plain_text' => $this->formatTimestamp((int) $event->get('occurred_at')->value)]],
          ['data' => Link::fromTextAndUrl($this->t('Review'), Url::fromRoute('ai_adminops.event_status', ['ai_adminops_event' => $event->id()]))->toRenderable()],
        ],
      ];
    }

    return $this->consolePage(
      $this->t('Events'),
      $this->t('A normalized inbox of infrastructure alerts. Review, acknowledge, or resolve events without exposing raw connector output.'),
      NULL,
      [
        '#theme' => 'table',
        '#header' => [$this->t('Server'), $this->t('Severity'), $this->t('Event'), $this->t('Status'), $this->t('Occurred'), $this->t('Operations')],
        '#rows' => $rows,
        '#empty' => $this->t('No operational events have been recorded.'),
        '#attributes' => ['class' => ['ai-adminops-table']],
      ],
    );
  }

  /**
   * Lists controlled action approval requests.
   */
  public function actionRequests(): array {
    $storage = $this->adminOpsEntityTypeManager->getStorage('ai_adminops_action_request');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('requested_at', 'DESC')->range(0, 100)->execute();
    $rows = [];

    foreach ($storage->loadMultiple($ids) as $request) {
      $status = (string) $request->get('status')->value;
      $rows[] = [
        'data' => [
          ['data' => $this->serverReferenceCell($request)],
          ['data' => ['#markup' => '<strong>' . $this->escape((string) $request->label()) . '</strong><div class="ai-adminops-table__secondary">' . $this->escape((string) $request->get('tool_id')->value) . '</div>']],
          ['data' => ['#markup' => $this->badge((string) $request->get('risk')->value, 'risk')]],
          ['data' => ['#markup' => $this->badge($status, 'status')]],
          ['data' => ['#plain_text' => $this->formatTimestamp((int) $request->get('requested_at')->value)]],
          ['data' => $status === 'pending'
            ? Link::fromTextAndUrl($this->t('Review'), Url::fromRoute('ai_adminops.action_request_status', ['ai_adminops_action_request' => $request->id()]))->toRenderable()
            : ['#plain_text' => '-']],
        ],
      ];
    }

    return $this->consolePage(
      $this->t('Action requests'),
      $this->t('Controlled and critical actions require an explicit review. Approval does not execute an operation by itself.'),
      NULL,
      [
        '#theme' => 'table',
        '#header' => [$this->t('Server'), $this->t('Request'), $this->t('Risk'), $this->t('Status'), $this->t('Requested'), $this->t('Operations')],
        '#rows' => $rows,
        '#empty' => $this->t('No controlled action requests are waiting for review.'),
        '#attributes' => ['class' => ['ai-adminops-table']],
      ],
    );
  }

  /**
   * Lists the execution audit trail.
   */
  public function executions(): array {
    $storage = $this->adminOpsEntityTypeManager->getStorage('ai_adminops_tool_execution');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 100)
      ->execute();
    $rows = [];

    foreach ($storage->loadMultiple($ids) as $execution) {
      $rows[] = [
        'data' => [
          ['data' => $this->serverReferenceCell($execution)],
          ['data' => ['#markup' => '<strong>' . $this->escape((string) $execution->get('tool_label')->value) . '</strong><div class="ai-adminops-table__secondary">' . $this->escape((string) $execution->get('tool_id')->value) . '</div>']],
          ['data' => ['#markup' => $this->badge((string) $execution->get('risk')->value, 'risk')]],
          ['data' => ['#markup' => $this->badge((string) $execution->get('status')->value, 'status')]],
          ['data' => ['#plain_text' => $this->formatTimestamp((int) $execution->get('created')->value)]],
        ],
      ];
    }

    return $this->consolePage(
      $this->t('Bitácora de actividad'),
      $this->t('La actividad más reciente aparece primero. Este registro seguro no expone credenciales del conector ni la salida de comandos.'),
      NULL,
      [
        '#theme' => 'table',
        '#header' => [$this->t('Servidor'), $this->t('Verificación'), $this->t('Acceso'), $this->t('Resultado'), $this->t('Registrado')],
        '#rows' => $rows,
        '#empty' => $this->t('Aún no se ha registrado actividad de monitoreo.'),
        '#attributes' => ['class' => ['ai-adminops-table']],
      ],
    );
  }

  /**
   * Builds a common console page layout.
   */
  private function consolePage(string|\Stringable $title, string|\Stringable $description, ?array $action, array $table): array {
    $build = [
      '#attached' => ['library' => ['ai_adminops/admin']],
      '#attributes' => ['class' => ['ai-adminops-console']],
      'heading' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-adminops-console__heading']],
        'copy' => [
          '#type' => 'container',
          'title' => ['#markup' => '<h2>' . $this->escape((string) $title) . '</h2>'],
          'description' => ['#markup' => '<p>' . $this->escape((string) $description) . '</p>'],
        ],
      ],
      'table' => $table,
    ];
    if ($action !== NULL) {
      $build['heading']['action'] = $action;
    }
    return $build;
  }

  /**
   * Builds a server link cell.
   */
  private function serverCell(object $server): array {
    $hostname = trim((string) $server->get('hostname'));
    $port = (int) $server->get('port');
    $endpoint = $hostname !== ''
      ? $hostname . ($port > 0 ? ':' . $port : '')
      : (string) $this->t('Host not configured');

    return [
      '#markup' => '<strong>' . Link::fromTextAndUrl($this->serverDisplayLabel($server), Url::fromRoute('ai_adminops.server_edit', ['ai_adminops_server' => $server->id()]))->toString() . '</strong><div class="ai-adminops-table__secondary">' . $this->escape($endpoint) . '</div>',
    ];
  }

  /**
   * Returns a visible server name even for legacy records without a label.
   */
  private function serverDisplayLabel(object $server): string {
    $label = trim((string) $server->label());
    if ($label !== '') {
      return $label;
    }

    $id = trim((string) $server->id());
    if ($id !== '') {
      return ucwords(str_replace(['_', '-'], ' ', $id));
    }

    $hostname = trim((string) $server->get('hostname'));
    return $hostname !== '' ? $hostname : (string) $this->t('Unnamed server');
  }

  /**
   * Builds a server reference cell for a content entity.
   */
  private function serverReferenceCell(ContentEntityInterface $entity): array {
    // Server configuration entities use a string machine name such as
    // "whm_produccion", so never cast the reference to an integer.
    $server_id = trim((string) $entity->get('server')->target_id);
    $server = $server_id !== ''
      ? $this->adminOpsEntityTypeManager->getStorage('ai_adminops_server')->load($server_id)
      : NULL;
    if ($server === NULL) {
      return ['#plain_text' => $this->t('Servidor no disponible')];
    }
    return $this->serverCell($server);
  }

  /**
   * Returns a semantic visual status label.
   */
  private function badge(string $value, string $type): string {
    $value = trim($value);
    $class = 'ai-adminops-badge ai-adminops-badge--' . Html::getClass($type) . ' ai-adminops-badge--' . Html::getClass(strtr(strtolower($value), ['_' => '-']));
    return '<span class="' . $class . '">' . $this->escape(ucwords(strtr($value, ['_' => ' ']))) . '</span>';
  }

  /**
   * Gives connection types a human-readable label.
   */
  private function connectionLabel(string $connection_type): string {
    return match ($connection_type) {
      'whm_api' => 'WHM API',
      'cpanel_api' => 'cPanel API',
      'ssh' => 'SSH',
      default => ucfirst(str_replace('_', ' ', $connection_type)),
    };
  }

  /**
   * Formats a timestamp consistently for the administrative UI.
   */
  private function formatTimestamp(int $timestamp): string {
    if ($timestamp <= 0) {
      return '-';
    }

    $timezone = (string) $this->adminOpsConfigFactory->get('system.date')->get('timezone.default');
    return $this->dateFormatter->format($timestamp, 'custom', 'd M Y - H:i', $timezone ?: NULL);
  }

  /**
   * Escapes a value for the limited markup used by the controller.
   */
  private function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
