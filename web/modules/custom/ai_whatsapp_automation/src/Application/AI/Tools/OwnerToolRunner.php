<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI\Tools;

use Drupal\ai_whatsapp_automation\Application\AI\BotManagerService;
use Drupal\ai_whatsapp_automation\Application\OpenAI\OpenAIServiceInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Lets a line's owner bot use owner tools, and runs the model's tool calls.
 *
 * Three locks, all required (José, 2026-10-11: client bots must never use
 * them, nor client roles see or enable them):
 * 1. The bot has "Owner tools" on. Only users with the dedicated permission
 *    see or change that field (field access in the .module).
 * 2. The bot is the Owner bot of the conversation's line. A public bot
 *    (Hermes, JG's, any client's) never gets tools, switch or not.
 * 3. The person writing is one of that line's owner numbers.
 *
 * Every other conversation never reaches this class's model calls: the
 * engine sends exactly the request it always sent.
 */
final class OwnerToolRunner {

  /**
   * Tool rounds per incoming message: each one is another paid request.
   */
  public const MAX_ROUNDS = 3;

  /**
   * Largest tool result handed to the model, in bytes.
   */
  private const MAX_RESULT_BYTES = 4000;

  /**
   * Registered providers.
   *
   * @var \Drupal\ai_whatsapp_automation\Application\AI\Tools\OwnerToolProviderInterface[]
   */
  private array $providers = [];

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Creates an OwnerToolRunner instance.
   */
  public function __construct(
    private readonly BotManagerService $botManager,
    private readonly OpenAIServiceInterface $openAIService,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('ai_whatsapp_automation');
  }

  /**
   * Registers a provider (service collector).
   */
  public function addProvider(OwnerToolProviderInterface $provider): void {
    $this->providers[] = $provider;
  }

  /**
   * Whether a conversation may use owner tools: all three locks.
   */
  public function isAvailable(ContentEntityInterface $conversation, ContentEntityInterface $bot): bool {
    // Cheapest checks first: on every client line these end it here.
    if ($this->providers === [] || !$bot->hasField('owner_tools_enabled') || !(bool) $bot->get('owner_tools_enabled')->value) {
      return FALSE;
    }

    // A line without an owner bot (every client line) fails here.
    $account = $this->botManager->getAccountForConversation($conversation);
    if (!$account instanceof ContentEntityInterface || (string) $account->get('owner_bot')->target_id !== (string) $bot->id()) {
      return FALSE;
    }

    $phone = $conversation->hasField('phone') ? (string) $conversation->get('phone')->value : '';
    if (!$this->botManager->isOwner($account, $phone)) {
      $this->logger->warning('Owner tools refused for conversation @conversation: the sender is not an owner number of its line.', [
        '@conversation' => (string) $conversation->id(),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Answers with tools available, running the model's calls.
   *
   * @param array<string, mixed> $prompt_data
   *   What PromptBuilderService built: prompt, model, options.
   *
   * @return array<string, mixed>
   *   Like OpenAIServiceInterface::sendPrompt(), with token counts and cost
   *   summed over every round, plus "tool_calls": the names that were run.
   */
  public function respond(array $prompt_data, ContentEntityInterface $conversation): array {
    [$tools, $owners] = $this->catalogue();
    $model = $prompt_data['model'] ?? NULL;
    $options = (is_array($prompt_data['options'] ?? NULL) ? $prompt_data['options'] : []) + ['tools' => $tools];

    $response = $this->openAIService->sendPrompt((string) $prompt_data['prompt'], $model, $options);
    $totals = $this->totals([], $response);
    $ran = [];

    for ($round = 1; ($response['function_calls'] ?? []) !== []; $round++) {
      $last_round = $round > self::MAX_ROUNDS;
      $outputs = [];
      foreach ($response['function_calls'] as $call) {
        $outputs[] = [
          'type' => 'function_call_output',
          'call_id' => $call['call_id'],
          'output' => $last_round
            ? '{"error":"Límite de consultas alcanzado: responde con lo que ya tienes."}'
            : $this->run($call, $owners, $conversation, $ran),
        ];
      }

      $follow_up = $options + [
        'previous_response_id' => (string) $response['id'],
        'input_items' => $outputs,
      ];
      if ($last_round) {
        // No more tools: the model must answer with what it has.
        $follow_up['tool_choice'] = 'none';
      }
      $response = $this->openAIService->sendPrompt('', $model, $follow_up);
      $totals = $this->totals($totals, $response);

      if ($last_round) {
        break;
      }
    }

    $response['usage'] = $totals['usage'];
    $response['cost']['estimated_cost'] = $totals['cost'] === NULL ? NULL : number_format($totals['cost'], 6, '.', '');
    $response['tool_calls'] = $ran;

    return $response;
  }

  /**
   * Builds the tool list for the model and an index of name to provider.
   *
   * @return array{0: array<int, array<string, mixed>>, 1: array<string, \Drupal\ai_whatsapp_automation\Application\AI\Tools\OwnerToolProviderInterface>}
   *   OpenAI tool definitions, and providers by tool name.
   */
  private function catalogue(): array {
    $tools = [];
    $owners = [];
    foreach ($this->providers as $provider) {
      foreach ($provider->definitions() as $definition) {
        $name = (string) ($definition['name'] ?? '');
        if (!preg_match('/^[a-z_]{1,64}$/', $name) || isset($owners[$name])) {
          $this->logger->error('Owner tool @name skipped: invalid or duplicated name.', ['@name' => $name]);
          continue;
        }
        $owners[$name] = $provider;
        $tools[] = [
          'type' => 'function',
          'name' => $name,
          'description' => (string) ($definition['description'] ?? ''),
          'parameters' => $definition['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ];
      }
    }

    return [$tools, $owners];
  }

  /**
   * Runs one call from the model and returns its output as JSON text.
   *
   * @param array{call_id: string, name: string, arguments: string} $call
   *   The model's call.
   * @param array<string, \Drupal\ai_whatsapp_automation\Application\AI\Tools\OwnerToolProviderInterface> $owners
   *   Providers by tool name.
   * @param string[] $ran
   *   Names run so far, appended to.
   */
  private function run(array $call, array $owners, ContentEntityInterface $conversation, array &$ran): string {
    $name = $call['name'];
    if (!isset($owners[$name])) {
      // Only names this module offered can run; anything else is refused.
      return '{"error":"Esa herramienta no existe."}';
    }

    try {
      $arguments = json_decode($call['arguments'] !== '' ? $call['arguments'] : '{}', TRUE, 16, JSON_THROW_ON_ERROR);
      $result = $owners[$name]->execute($name, is_array($arguments) ? $arguments : [], $conversation);
      $ran[] = $name;
      $this->logger->info('Owner tool @tool ran for conversation @conversation.', [
        '@tool' => $name,
        '@conversation' => (string) $conversation->id(),
      ]);
      $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Owner tool @tool failed for conversation @conversation: @message', [
        '@tool' => $name,
        '@conversation' => (string) $conversation->id(),
        '@message' => $exception->getMessage(),
      ]);
      return '{"error":"La herramienta no pudo completarse. Díselo al usuario; no inventes el dato."}';
    }

    return strlen($json) > self::MAX_RESULT_BYTES
      ? json_encode(['truncated' => TRUE, 'partial' => mb_strcut($json, 0, self::MAX_RESULT_BYTES)], JSON_UNESCAPED_UNICODE)
      : $json;
  }

  /**
   * Adds a round's token counts and cost to the running totals.
   *
   * @param array{usage?: array<string, int>, cost?: float|null} $totals
   *   Totals so far.
   * @param array<string, mixed> $response
   *   One sendPrompt() result.
   *
   * @return array{usage: array<string, int>, cost: float|null}
   *   Updated totals; the cost stays NULL while no round had a known cost.
   */
  private function totals(array $totals, array $response): array {
    $usage = $totals['usage'] ?? [];
    foreach (is_array($response['usage'] ?? NULL) ? $response['usage'] : [] as $key => $value) {
      $usage[$key] = ($usage[$key] ?? 0) + (int) $value;
    }
    $round_cost = $response['cost']['estimated_cost'] ?? NULL;

    return [
      'usage' => $usage,
      'cost' => $round_cost === NULL ? ($totals['cost'] ?? NULL) : ($totals['cost'] ?? 0.0) + (float) $round_cost,
    ];
  }

}
