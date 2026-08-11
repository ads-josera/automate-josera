<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages human approval for controlled and critical AdminOps operations.
 */
final class AdminOpsActionRequestManager {

  /**
   * Creates an AdminOpsActionRequestManager instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly PayloadSanitizer $payloadSanitizer,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Creates a pending approval request. It does not execute an action.
   *
   * @param array<string, mixed> $parameters
   *   Sanitized before being stored for audit.
   */
  public function request(
    string $server_id,
    string $tool_id,
    string $title,
    array $parameters,
    string $risk,
    ?int $requested_by = NULL,
    ?int $expires_at = NULL,
    string $note = '',
  ): ContentEntityInterface {
    $this->assertServerExists($server_id);
    $tool_id = trim($tool_id);
    $title = trim($title);
    if ($tool_id === '' || $title === '') {
      throw new \InvalidArgumentException('A tool ID and title are required.');
    }
    if (!in_array($risk, ['controlled', 'critical'], TRUE)) {
      throw new \InvalidArgumentException('Only controlled and critical actions require approval requests.');
    }

    $requested_at = $this->time->getRequestTime();
    $request = $this->entityTypeManager->getStorage('ai_adminops_action_request')->create([
      'server' => $server_id,
      'tool_id' => $tool_id,
      'title' => $title,
      'parameters_json' => $this->payloadSanitizer->encode($parameters),
      'risk' => $risk,
      'status' => 'pending',
      'requested_by' => $requested_by,
      'note' => trim($note),
      'requested_at' => $requested_at,
      'expires_at' => $expires_at,
    ]);
    $request->save();

    $this->logger->notice('AdminOps action request @request created for tool @tool on server @server.', [
      '@request' => (string) $request->id(),
      '@tool' => $tool_id,
      '@server' => $server_id,
    ]);

    return $request;
  }

  /**
   * Approves a pending request. Execution remains a separate concern.
   */
  public function approve(int $request_id, int $approved_by): ContentEntityInterface {
    $request = $this->loadPendingRequest($request_id);
    $request->set('status', 'approved');
    $request->set('approved_by', $approved_by);
    $request->set('approved_at', $this->time->getRequestTime());
    $request->save();
    return $request;
  }

  /**
   * Rejects a pending request with an optional operator note.
   */
  public function reject(int $request_id, int $approved_by, string $note = ''): ContentEntityInterface {
    $request = $this->loadPendingRequest($request_id);
    $request->set('status', 'rejected');
    $request->set('approved_by', $approved_by);
    $request->set('approved_at', $this->time->getRequestTime());
    $request->set('note', trim($note));
    $request->save();
    return $request;
  }

  /**
   * Loads a pending and non-expired action request.
   */
  private function loadPendingRequest(int $request_id): ContentEntityInterface {
    $request = $this->entityTypeManager->getStorage('ai_adminops_action_request')->load($request_id);
    if (!$request instanceof ContentEntityInterface) {
      throw new \InvalidArgumentException(sprintf('AdminOps action request %d does not exist.', $request_id));
    }
    if ($request->get('status')->value !== 'pending') {
      throw new \LogicException('Only pending action requests can be changed.');
    }

    $expires_at = (int) ($request->get('expires_at')->value ?? 0);
    if ($expires_at > 0 && $expires_at <= $this->time->getRequestTime()) {
      $request->set('status', 'expired');
      $request->save();
      throw new \LogicException('This action request has expired.');
    }

    return $request;
  }

  /**
   * Ensures the target server exists before creating a request.
   */
  private function assertServerExists(string $server_id): void {
    if ($this->entityTypeManager->getStorage('ai_adminops_server')->load($server_id) === NULL) {
      throw new \InvalidArgumentException(sprintf('AdminOps server "%s" does not exist.', $server_id));
    }
  }

}
