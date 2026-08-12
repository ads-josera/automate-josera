<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the initial AI AdminOps dashboard.
 */
final class AdminOpsDashboardController extends ControllerBase {

  /**
   * Creates an AdminOpsDashboardController instance.
   */
  public function __construct(private readonly EntityTypeManagerInterface $adminOpsEntityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * Displays the base dashboard until monitoring is configured.
   */
  public function dashboard(): array {
    $servers = $this->adminOpsEntityTypeManager->getStorage('ai_adminops_server');
    $events = $this->adminOpsEntityTypeManager->getStorage('ai_adminops_event');
    $requests = $this->adminOpsEntityTypeManager->getStorage('ai_adminops_action_request');
    $server_total = (int) $servers->getQuery()->accessCheck(FALSE)->count()->execute();
    $active_servers = (int) $servers->getQuery()->accessCheck(FALSE)->condition('active', TRUE)->count()->execute();
    $open_events = (int) $events->getQuery()->accessCheck(FALSE)->condition('status', 'resolved', '<>')->count()->execute();
    $pending_requests = (int) $requests->getQuery()->accessCheck(FALSE)->condition('status', 'pending')->count()->execute();

    return [
      '#attached' => [
        'library' => ['ai_adminops/admin'],
      ],
      '#attributes' => ['class' => ['ai-adminops-dashboard']],
      'intro' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-adminops-dashboard__intro']],
        'eyebrow' => [
          '#markup' => '<p class="ai-adminops-dashboard__eyebrow">ADMINISTRACION DE INFRAESTRUCTURA</p>',
        ],
        'title' => [
          '#markup' => '<h2>' . $this->t('Consola AI AdminOps') . '</h2>',
        ],
        'description' => [
          '#markup' => '<p>' . $this->t('Administra servidores, alertas, aprobaciones y actividad de auditoria desde un solo espacio de trabajo controlado.') . '</p>',
        ],
      ],
      'metrics' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-adminops-dashboard__metrics']],
        'servers' => $this->metric((string) $this->t('Servidores'), $server_total, (string) $this->t('@active activos', ['@active' => $active_servers])),
        'events' => $this->metric((string) $this->t('Alertas abiertas'), $open_events, (string) $this->t('Requieren revision')),
        'requests' => $this->metric((string) $this->t('Aprobaciones pendientes'), $pending_requests, (string) $this->t('Sin ejecucion remota')),
      ],
      'navigation' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-adminops-dashboard__navigation']],
        'title' => ['#markup' => '<h3>' . $this->t('Acciones de consola') . '</h3>'],
        'links' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-adminops-dashboard__links']],
          'servers' => $this->actionLink((string) $this->t('Servidores'), (string) $this->t('Gestionar conexiones y monitoreo'), 'ai_adminops.servers'),
          'events' => $this->actionLink((string) $this->t('Alertas'), (string) $this->t('Revisar eventos operativos'), 'ai_adminops.events'),
          'approvals' => $this->actionLink((string) $this->t('Aprobaciones'), (string) $this->t('Consultar solicitudes pendientes'), 'ai_adminops.action_requests'),
          'audit' => $this->actionLink((string) $this->t('Bitacora'), (string) $this->t('Ver actividad de monitoreo'), 'ai_adminops.executions'),
          'settings' => $this->actionLink((string) $this->t('Configuracion'), (string) $this->t('Ajustar alertas y monitoreo'), 'ai_adminops.settings'),
        ],
      ],
    ];
  }

  /**
   * Builds one compact dashboard metric.
   */
  private function metric(string $label, int $value, string $detail): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-adminops-metric']],
      'label' => ['#markup' => '<span>' . $label . '</span>'],
      'value' => ['#markup' => '<strong>' . $value . '</strong>'],
      'detail' => ['#markup' => '<small>' . $detail . '</small>'],
    ];
  }

  /**
   * Builds one dashboard navigation action.
   */
  private function actionLink(string $label, string $detail, string $route_name): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-adminops-dashboard__action']],
      'link' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute($route_name),
        '#attributes' => ['class' => ['ai-adminops-dashboard__action-link']],
      ],
      'detail' => [
        '#markup' => '<span>' . $detail . '</span>',
      ],
    ];
  }

}
