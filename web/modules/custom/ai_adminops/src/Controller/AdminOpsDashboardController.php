<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Builds the initial AI AdminOps dashboard.
 */
final class AdminOpsDashboardController extends ControllerBase {

  /**
   * Displays the base dashboard until monitoring is configured.
   */
  public function dashboard(): array {
    return [
      '#attached' => [
        'library' => ['ai_adminops/admin'],
      ],
      'intro' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-adminops-dashboard']],
        'eyebrow' => [
          '#markup' => '<p class="ai-adminops-dashboard__eyebrow">ADMINISTRACION DE INFRAESTRUCTURA</p>',
        ],
        'title' => [
          '#markup' => '<h2>AI AdminOps esta listo para configurarse</h2>',
        ],
        'description' => [
          '#markup' => '<p>Agrega servidores en la siguiente fase para habilitar monitoreo, eventos y acciones controladas.</p>',
        ],
      ],
    ];
  }

}

