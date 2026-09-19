<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Ui;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\HttpFoundation\Request;

/**
 * The "Cliente" filter shared by every admin list and the dashboard.
 *
 * The selected client travels in the ?client= query parameter, so the same
 * value drives the select element and the list or dashboard query.
 */
final class ClientFilter {

  /**
   * Returns the client ID selected in the request, or 0 for all clients.
   */
  public static function selectedId(Request $request): int {
    $client_id = (int) $request->query->get('client', 0);

    return $client_id > 0 ? $client_id : 0;
  }

  /**
   * Builds the client select element for a GET filter form.
   *
   * @return array<string, mixed>
   *   A select render element named "client".
   */
  public static function element(Request $request): array {
    $options = ['' => new TranslatableMarkup('Todos los clientes')];
    $storage = \Drupal::entityTypeManager()->getStorage('ai_whatsapp_client');
    $ids = $storage->getQuery()->accessCheck(TRUE)->sort('name', 'ASC')->execute();
    foreach ($storage->loadMultiple($ids) as $client) {
      $options[(string) $client->id()] = $client->label();
    }
    $selected = self::selectedId($request);

    return [
      '#type' => 'select',
      '#title' => new TranslatableMarkup('Cliente'),
      '#options' => $options,
      '#default_value' => $selected > 0 ? (string) $selected : '',
    ];
  }

}
