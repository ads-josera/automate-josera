<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller\Evolution;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Evolution QR connection screens.
 */
final class EvolutionConnectionController extends ControllerBase {

  /**
   * Constructs an EvolutionConnectionController object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $automationEntityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Lists Evolution accounts and their connection status.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function overview(): array {
    $storage = $this->automationEntityTypeManager->getStorage('ai_whatsapp_account');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('provider', 'evolution')
      ->sort('changed', 'DESC')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $account) {
      $rows[] = [
        'name' => $account->toLink(),
        'instance' => $this->fieldValue($account, 'evolution_instance_name'),
        'status' => $this->fieldValue($account, 'connection_status') ?: $this->fieldValue($account, 'status'),
        'connected_phone' => $this->fieldValue($account, 'connected_phone_number'),
        'updated' => $this->fieldValue($account, 'changed'),
        'operations' => Link::fromTextAndUrl($this->t('Manage QR'), Url::fromRoute('ai_whatsapp_automation.evolution_account_qr', [
          'ai_whatsapp_account' => $account->id(),
        ])),
      ];
    }

    $build['actions'] = [
      '#type' => 'actions',
    ];
    $build['actions']['add_account'] = [
      '#type' => 'link',
      '#title' => $this->t('Add WhatsApp account'),
      '#url' => Url::fromRoute('entity.ai_whatsapp_account.add_form'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    $build['accounts'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Account'),
        $this->t('Instance'),
        $this->t('Connection status'),
        $this->t('Connected number'),
        $this->t('Updated'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No Evolution API WhatsApp accounts were found.'),
    ];

    return $build;
  }

  /**
   * Returns a scalar field value.
   */
  private function fieldValue(mixed $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

}
