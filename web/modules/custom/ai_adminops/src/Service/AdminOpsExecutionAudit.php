<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maintains immutable-style audit records around future tool executions.
 */
final class AdminOpsExecutionAudit {

  /**
   * Creates an AdminOpsExecutionAudit instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly PayloadSanitizer $payloadSanitizer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Starts an audit record before a tool is ever called.
   *
   * @param array<string, mixed> $parameters
   *   Tool parameters, sanitized for persistent audit storage.
   */
  public function begin(
    string $server_id,
    string $tool_id,
    string $tool_label,
    array $parameters,
    string $risk = 'read_only',
    ?int $action_request_id = NULL,
    ?int $initiated_by = NULL,
  ): ContentEntityInterface {
    $this->assertServerExists($server_id);
    if (!in_array($risk, ['read_only', 'controlled', 'critical'], TRUE)) {
      throw new \InvalidArgumentException('The execution risk level is not supported.');
    }

    $execution = $this->entityTypeManager->getStorage('ai_adminops_tool_execution')->create([
      'server' => $server_id,
      'action_request' => $action_request_id,
      'tool_id' => trim($tool_id),
      'tool_label' => trim($tool_label),
      'parameters_json' => $this->payloadSanitizer->encode($parameters),
      'status' => 'queued',
      'risk' => $risk,
      'initiated_by' => $initiated_by,
    ]);
    $execution->save();

    return $execution;
  }

  /**
   * Marks an execution as actively running.
   */
  public function markRunning(int $execution_id): ContentEntityInterface {
    $execution = $this->loadExecution($execution_id);
    $execution->set('status', 'running');
    $execution->set('started_at', $this->time->getRequestTime());
    $execution->save();
    return $execution;
  }

  /**
   * Marks an execution as completed successfully.
   *
   * @param array<string, mixed> $result
   *   Tool output, sanitized before persistent storage.
   */
  public function markSucceeded(int $execution_id, array $result = []): ContentEntityInterface {
    return $this->complete($execution_id, 'succeeded', $result);
  }

  /**
   * Marks an execution as failed without exposing raw infrastructure details.
   *
   * @param array<string, mixed> $result
   *   Sanitized error result.
   */
  public function markFailed(int $execution_id, array $result = []): ContentEntityInterface {
    return $this->complete($execution_id, 'failed', $result);
  }

  /**
   * Marks an execution as denied before any tool call took place.
   */
  public function markDenied(int $execution_id, string $reason = ''): ContentEntityInterface {
    return $this->complete($execution_id, 'denied', ['reason' => $reason]);
  }

  /**
   * Completes an execution record.
   *
   * @param array<string, mixed> $result
   *   Result payload.
   */
  private function complete(int $execution_id, string $status, array $result): ContentEntityInterface {
    $execution = $this->loadExecution($execution_id);
    $execution->set('status', $status);
    $execution->set('result', $this->payloadSanitizer->encode($result));
    $execution->set('completed_at', $this->time->getRequestTime());
    $execution->save();

    $this->logger->notice('AdminOps execution @execution finished with @status status.', [
      '@execution' => (string) $execution_id,
      '@status' => $status,
    ]);

    return $execution;
  }

  /**
   * Loads an execution record or raises a clear exception.
   */
  private function loadExecution(int $execution_id): ContentEntityInterface {
    $execution = $this->entityTypeManager->getStorage('ai_adminops_tool_execution')->load($execution_id);
    if (!$execution instanceof ContentEntityInterface) {
      throw new \InvalidArgumentException(sprintf('AdminOps tool execution %d does not exist.', $execution_id));
    }

    return $execution;
  }

  /**
   * Ensures the target server exists before starting an audit record.
   */
  private function assertServerExists(string $server_id): void {
    if ($this->entityTypeManager->getStorage('ai_adminops_server')->load($server_id) === NULL) {
      throw new \InvalidArgumentException(sprintf('AdminOps server "%s" does not exist.', $server_id));
    }
  }

}
