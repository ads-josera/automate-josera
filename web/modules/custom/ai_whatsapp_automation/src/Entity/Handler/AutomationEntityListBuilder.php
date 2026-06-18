<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity\Handler;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;

/**
 * Provides list tables for AI WhatsApp Automation content entities.
 */
final class AutomationEntityListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['status'] = $this->t('Status');
    $header['changed'] = $this->t('Updated');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['label'] = $entity->toLink();
    $row['status'] = $this->getFieldValue($entity, 'status');
    $row['changed'] = $this->getFieldValue($entity, 'changed');

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);

    if ($entity->getEntityTypeId() === 'ai_whatsapp_account' && $this->getFieldValue($entity, 'provider') === 'evolution') {
      $operations['manage_qr'] = [
        'title' => $this->t('Manage QR'),
        'weight' => 20,
        'url' => Url::fromRoute('ai_whatsapp_automation.evolution_account_qr', [
          'ai_whatsapp_account' => $entity->id(),
        ]),
      ];
    }

    if ($entity->getEntityTypeId() !== 'ai_whatsapp_conversation') {
      return $operations;
    }

    $route_params = ['ai_whatsapp_conversation' => $entity->id()];
    $operations['stop_ai'] = [
      'title' => $this->t('Stop AI'),
      'weight' => 20,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_stop_ai', $route_params),
    ];
    $operations['assign_operator'] = [
      'title' => $this->t('Assign operator'),
      'weight' => 21,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_assign_operator', $route_params),
    ];
    $operations['manual_reply'] = [
      'title' => $this->t('Manual reply'),
      'weight' => 22,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_manual_reply', $route_params),
    ];
    $operations['reactivate_ai'] = [
      'title' => $this->t('Reactivate AI'),
      'weight' => 23,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_reactivate_ai', $route_params),
    ];
    $operations['close'] = [
      'title' => $this->t('Close'),
      'weight' => 24,
      'url' => Url::fromRoute('ai_whatsapp_automation.conversation_close', $route_params),
    ];

    return $operations;
  }

  /**
   * Returns a scalar field value for list display.
   */
  private function getFieldValue(EntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

}
