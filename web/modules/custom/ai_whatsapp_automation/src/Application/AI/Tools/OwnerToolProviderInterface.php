<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI\Tools;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Offers tools that a line's owner bot may use for its owner.
 *
 * Implement it in another module and tag the service
 * "ai_whatsapp_automation.owner_tool_provider". This module knows nothing of
 * what the tools do: OwnerToolRunner decides who may use them (only the owner
 * bot of a line, only for an owner number, only when the bot has owner tools
 * switched on) and runs the model's calls.
 *
 * Tools must be read-only unless an approval step guards them.
 */
interface OwnerToolProviderInterface {

  /**
   * Returns the tools this provider offers.
   *
   * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
   *   Tool names must be unique across providers and match ^[a-z_]{1,64}$.
   *   "parameters" is a JSON schema object; use an empty "properties" object
   *   and an empty "required" list for a tool without arguments.
   */
  public function definitions(): array;

  /**
   * Runs one of this provider's tools.
   *
   * @param string $name
   *   One of the names returned by definitions().
   * @param array<string, mixed> $arguments
   *   Arguments decoded from the model's call. Validate them: they come from
   *   the model, not from a person.
   * @param \Drupal\Core\Entity\ContentEntityInterface $conversation
   *   The owner's conversation, for auditing who asked.
   *
   * @return array<string, mixed>
   *   A small, JSON-serialisable result for the model. Never secrets.
   */
  public function execute(string $name, array $arguments, ContentEntityInterface $conversation): array;

}
