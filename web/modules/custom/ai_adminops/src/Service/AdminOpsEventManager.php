<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates and updates normalized operational events.
 */
final class AdminOpsEventManager {

  /**
   * Creates an AdminOpsEventManager instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly PayloadSanitizer $payloadSanitizer,
    private readonly NotificationBridge $notificationBridge,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records an operational event for a registered server.
   *
   * @param array<string, mixed> $evidence
   *   Structured context that is sanitized before storage.
   */
  public function record(
    string $server_id,
    string $event_type,
    string $severity,
    string $summary,
    string $details = '',
    array $evidence = [],
    ?int $occurred_at = NULL,
  ): ContentEntityInterface {
    $server = $this->entityTypeManager->getStorage('ai_adminops_server')->load($server_id);
    if ($server === NULL) {
      throw new \InvalidArgumentException(sprintf('AdminOps server "%s" does not exist.', $server_id));
    }

    $event_type = trim($event_type);
    $summary = trim($summary);
    if ($event_type === '' || $summary === '') {
      throw new \InvalidArgumentException('An event type and summary are required.');
    }
    if (!in_array($severity, ['info', 'warning', 'critical'], TRUE)) {
      throw new \InvalidArgumentException('The event severity is not supported.');
    }

    $event = $this->entityTypeManager->getStorage('ai_adminops_event')->create([
      'server' => $server_id,
      'event_type' => $event_type,
      'severity' => $severity,
      'summary' => $summary,
      'details' => trim($details),
      'evidence_json' => $this->payloadSanitizer->encode($evidence),
      'fingerprint' => hash('sha256', implode('|', [$server_id, $event_type, $summary])),
      'status' => 'open',
      'occurred_at' => $occurred_at ?? $this->time->getRequestTime(),
    ]);
    $event->save();

    try {
      $this->notificationBridge->notifyEvent($event);
    }
    catch (\Throwable $exception) {
      $this->logger->error('AdminOps event @event was stored, but notification delivery failed: @message', [
        '@event' => (string) $event->id(),
        '@message' => $exception->getMessage(),
      ]);
    }

    $this->logger->notice('AdminOps event @event recorded for server @server with @severity severity.', [
      '@event' => (string) $event->id(),
      '@server' => $server_id,
      '@severity' => $severity,
    ]);

    return $event;
  }

  /**
   * Acknowledges an open operational event.
   */
  public function acknowledge(int $event_id): ContentEntityInterface {
    $event = $this->loadEvent($event_id);
    if ($event->get('status')->value === 'resolved') {
      throw new \LogicException('A resolved event cannot be acknowledged.');
    }

    $event->set('status', 'acknowledged');
    $event->save();
    return $event;
  }

  /**
   * Resolves an operational event.
   */
  public function resolve(int $event_id): ContentEntityInterface {
    $event = $this->loadEvent($event_id);
    $event->set('status', 'resolved');
    $event->set('resolved_at', $this->time->getRequestTime());
    $event->save();
    return $event;
  }

  /**
   * Loads a known event or raises a clear exception.
   */
  private function loadEvent(int $event_id): ContentEntityInterface {
    $event = $this->entityTypeManager->getStorage('ai_adminops_event')->load($event_id);
    if (!$event instanceof ContentEntityInterface) {
      throw new \InvalidArgumentException(sprintf('AdminOps event %d does not exist.', $event_id));
    }

    return $event;
  }

}
