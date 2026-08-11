<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
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
          '#markup' => '<h2>AI AdminOps console</h2>',
        ],
        'description' => [
          '#markup' => '<p>Organize servers, operational events, approval requests, and audit activity from one controlled workspace. Server registration does not create a remote connection.</p>',
        ],
      ],
      'metrics' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-adminops-dashboard__metrics']],
        'servers' => $this->metric((string) $this->t('Servers'), $server_total, (string) $this->t('@active active', ['@active' => $active_servers])),
        'events' => $this->metric((string) $this->t('Open events'), $open_events, (string) $this->t('Needs review')),
        'requests' => $this->metric((string) $this->t('Pending approvals'), $pending_requests, (string) $this->t('No remote execution')),
      ],
      'navigation' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-adminops-dashboard__navigation']],
        'title' => ['#markup' => '<h3>' . $this->t('Console') . '</h3>'],
        'links' => [
          '#theme' => 'item_list',
          '#items' => [
            Link::fromTextAndUrl($this->t('Manage servers'), Url::fromRoute('ai_adminops.servers')),
            Link::fromTextAndUrl($this->t('Review events'), Url::fromRoute('ai_adminops.events')),
            Link::fromTextAndUrl($this->t('Review approvals'), Url::fromRoute('ai_adminops.action_requests')),
            Link::fromTextAndUrl($this->t('View audit log'), Url::fromRoute('ai_adminops.executions')),
            Link::fromTextAndUrl($this->t('Open settings'), Url::fromRoute('ai_adminops.settings')),
          ],
          '#attributes' => ['class' => ['ai-adminops-dashboard__links']],
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

}
