<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Client;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Detects references from a bot or account to another client's records.
 *
 * A bot of client A pointing to client B's knowledge base would answer A's
 * customers with B's information, so the forms refuse such combinations.
 * Records without a client (shared) are allowed.
 */
final class ClientConsistencyChecker {

  use StringTranslationTrait;

  /**
   * References checked per entity type, with the label used in messages.
   */
  private const REFERENCES = [
    'ai_whatsapp_bot' => [
      'knowledge_base' => 'La base de conocimiento',
      'lead_notification_account' => 'La cuenta de notificación',
    ],
    'ai_whatsapp_account' => [
      'bot' => 'El bot',
      'knowledge_base' => 'La base de conocimiento',
    ],
  ];

  /**
   * Returns conflicts keyed by field name, or an empty array.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Error messages for fields referencing another client's record.
   */
  public function conflicts(ContentEntityInterface $entity): array {
    $references = self::REFERENCES[$entity->getEntityTypeId()] ?? [];
    $client = $this->effectiveClient($entity);
    if ($references === [] || $client === NULL) {
      return [];
    }

    $conflicts = [];
    foreach ($references as $field_name => $label) {
      // The account's own bot defines its client when it has none.
      if ($entity->getEntityTypeId() === 'ai_whatsapp_account' && $field_name === 'bot' && $entity->get('client')->isEmpty()) {
        continue;
      }
      $referenced = $entity->hasField($field_name) ? $entity->get($field_name)->entity : NULL;
      $referenced_client = $referenced instanceof ContentEntityInterface ? $this->effectiveClient($referenced) : NULL;
      if ($referenced_client !== NULL && $referenced_client->id() !== $client->id()) {
        $conflicts[$field_name] = $this->t('@label «@referenced» pertenece al cliente «@other», pero este registro es del cliente «@client». Elige uno del mismo cliente.', [
          '@label' => $label,
          '@referenced' => $referenced->label(),
          '@other' => $referenced_client->label(),
          '@client' => $client->label(),
        ]);
      }
    }

    return $conflicts;
  }

  /**
   * Returns the client of a record: its own, or its bot's for accounts.
   */
  private function effectiveClient(ContentEntityInterface $entity): ?ContentEntityInterface {
    $client = $entity->hasField('client') ? $entity->get('client')->entity : NULL;
    if ($client instanceof ContentEntityInterface) {
      return $client;
    }
    if ($entity->getEntityTypeId() === 'ai_whatsapp_account') {
      $bot = $entity->get('bot')->entity;
      $client = $bot instanceof ContentEntityInterface ? $bot->get('client')->entity : NULL;
    }

    return $client instanceof ContentEntityInterface ? $client : NULL;
  }

}
