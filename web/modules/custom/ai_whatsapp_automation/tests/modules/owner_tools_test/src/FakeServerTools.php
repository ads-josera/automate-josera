<?php

declare(strict_types=1);

namespace Drupal\owner_tools_test;

use Drupal\ai_whatsapp_automation\Application\AI\Tools\OwnerToolProviderInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * A fake server tool that records every call it gets.
 */
final class FakeServerTools implements OwnerToolProviderInterface {

  /**
   * Calls received, as [name, arguments, conversation id].
   */
  public array $calls = [];

  /**
   * {@inheritdoc}
   */
  public function definitions(): array {
    return [
      [
        'name' => 'estado_servidor',
        'description' => 'Reads the state of one server.',
        'parameters' => [
          'type' => 'object',
          'properties' => ['servidor' => ['type' => 'string']],
          'required' => ['servidor'],
          'additionalProperties' => FALSE,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function execute(string $name, array $arguments, ContentEntityInterface $conversation): array {
    $this->calls[] = [$name, $arguments, $conversation->id()];
    if (($arguments['servidor'] ?? '') === 'roto') {
      throw new \RuntimeException('SSH timeout');
    }

    return ['servidor' => $arguments['servidor'] ?? '', 'carga' => '0.42'];
  }

}
