<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Client;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Fills the client of records that belong to one, when they are saved.
 *
 * Runs from presave hooks so every creation path (web widget, webhooks,
 * reopened conversations, admin forms) is covered without each one having to
 * remember it. An existing client is never overwritten: conversations and
 * leads keep their owner even if their bot is later moved to another client.
 */
final class ClientAssignmentService {

  /**
   * Assigns the client to an entity about to be saved, if it has none.
   */
  public function assign(ContentEntityInterface $entity): void {
    if (!$entity->hasField('client') || !$entity->get('client')->isEmpty()) {
      return;
    }

    $client_id = match ($entity->getEntityTypeId()) {
      'ai_whatsapp_account' => $this->clientOf($entity, 'bot'),
      'ai_whatsapp_conversation' => $this->clientOf($entity, 'bot') ?? $this->accountClient($entity->get('whatsapp_account')->entity),
      'ai_whatsapp_lead' => $this->clientOf($entity, 'conversation') ?? $this->clientOf($entity, 'bot'),
      default => NULL,
    };

    if ($client_id !== NULL) {
      $entity->set('client', $client_id);
    }
  }

  /**
   * Returns the client of a WhatsApp account, falling back to its bot's.
   */
  private function accountClient(?ContentEntityInterface $account): ?string {
    if (!$account instanceof ContentEntityInterface) {
      return NULL;
    }

    return $this->ownClient($account) ?? $this->clientOf($account, 'bot');
  }

  /**
   * Returns the client of the entity referenced by a field.
   */
  private function clientOf(ContentEntityInterface $entity, string $reference_field): ?string {
    if (!$entity->hasField($reference_field)) {
      return NULL;
    }
    $referenced = $entity->get($reference_field)->entity;

    return $referenced instanceof ContentEntityInterface ? $this->ownClient($referenced) : NULL;
  }

  /**
   * Returns the client stored on an entity.
   */
  private function ownClient(ContentEntityInterface $entity): ?string {
    if (!$entity->hasField('client') || $entity->get('client')->isEmpty()) {
      return NULL;
    }

    return (string) $entity->get('client')->target_id;
  }

}
