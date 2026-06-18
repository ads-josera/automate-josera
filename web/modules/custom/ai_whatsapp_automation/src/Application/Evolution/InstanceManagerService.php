<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\Evolution;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages Evolution API instances attached to WhatsApp accounts.
 */
final class InstanceManagerService {

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs an InstanceManagerService object.
   */
  public function __construct(
    private readonly EvolutionApiClient $client,
    private readonly QRProvider $qrProvider,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Creates the remote instance and requests the first QR.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function createInstance(ContentEntityInterface $account): array {
    $instance_name = $this->ensureInstanceName($account);
    $result = $this->client->createInstance($instance_name);
    $this->applyApiResult($account, $result, 'WAITING_QR');

    $qr = $this->extractQr($result);
    if ($qr !== '') {
      $this->storeQr($account, $qr);
    }

    $account->save();

    return $result;
  }

  /**
   * Deletes the remote instance and clears local QR state.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function deleteInstance(ContentEntityInterface $account): array {
    $instance_name = $this->ensureInstanceName($account);
    $result = $this->client->deleteInstance($instance_name);
    $this->setConnectionState($account, 'DISCONNECTED');
    $this->clearQr($account);
    $account->save();

    return $result;
  }

  /**
   * Restarts the remote instance.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function restartInstance(ContentEntityInterface $account): array {
    $instance_name = $this->ensureInstanceName($account);
    $result = $this->client->restartInstance($instance_name);
    $this->applyApiResult($account, $result, 'CONNECTING');
    $account->save();

    return $result;
  }

  /**
   * Disconnects the remote instance.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function disconnectInstance(ContentEntityInterface $account): array {
    $instance_name = $this->ensureInstanceName($account);
    $result = $this->client->logoutInstance($instance_name);
    $this->setConnectionState($account, 'DISCONNECTED');
    $this->clearQr($account);
    $account->save();

    return $result;
  }

  /**
   * Requests a fresh QR code.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function requestQr(ContentEntityInterface $account): array {
    $instance_name = $this->ensureInstanceName($account);
    $result = $this->qrProvider->getQr($instance_name);
    $this->applyApiResult($account, $result, 'WAITING_QR');

    $qr = $this->extractQr($result);
    if ($qr !== '') {
      $this->storeQr($account, $qr);
    }

    $account->save();

    return $result;
  }

  /**
   * Refreshes the local connection status from Evolution.
   *
   * @return array<string, mixed>
   *   The operation result.
   */
  public function refreshStatus(ContentEntityInterface $account): array {
    $instance_name = $this->ensureInstanceName($account);
    $result = $this->qrProvider->validateConnection($instance_name);
    $state = $this->extractConnectionState($result);
    $this->setConnectionState($account, $state);

    $phone = $this->extractConnectedPhone($result);
    if ($phone !== '' && $account->hasField('connected_phone_number')) {
      $account->set('connected_phone_number', $phone);
    }
    if ($state === 'CONNECTED' && $account->hasField('connected_at') && $account->get('connected_at')->isEmpty()) {
      $account->set('connected_at', time());
    }
    if ($account->hasField('last_status_check')) {
      $account->set('last_status_check', time());
    }

    $this->applyApiResult($account, $result, $state);
    $account->save();

    return $result;
  }

  /**
   * Refreshes all Evolution accounts that need periodic monitoring.
   */
  public function monitorAccounts(): void {
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_account');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider', 'evolution')
      ->condition('connection_status', ['WAITING_QR', 'CONNECTING', 'CONNECTED', 'ERROR'], 'IN')
      ->execute();

    if ($ids === []) {
      return;
    }

    foreach ($storage->loadMultiple($ids) as $account) {
      try {
        $this->refreshStatus($account);
      }
      catch (\Throwable $exception) {
        $this->logger->error('Evolution monitor failed for account @id: @message', [
          '@id' => $account->id(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }
  }

  /**
   * Ensures an account has a stable Evolution instance name.
   */
  public function ensureInstanceName(ContentEntityInterface $account): string {
    $instance_name = $account->hasField('evolution_instance_name') && !$account->get('evolution_instance_name')->isEmpty()
      ? (string) $account->get('evolution_instance_name')->value
      : '';

    if ($instance_name !== '') {
      return $instance_name;
    }

    $configured = (string) $this->configFactory
      ->get('ai_whatsapp_automation.settings')
      ->get('evolution.instance_name');
    $base = $configured !== '' ? $configured : (string) $account->label();
    $instance_name = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base), '-'));
    if ($instance_name === '') {
      $instance_name = 'ai-whatsapp-account-' . $account->id();
    }

    if ($account->hasField('evolution_instance_name')) {
      $account->set('evolution_instance_name', $instance_name);
    }

    return $instance_name;
  }

  /**
   * Applies remote call success or error state to the account.
   *
   * @param array<string, mixed> $result
   *   API result.
   */
  private function applyApiResult(ContentEntityInterface $account, array $result, string $success_state): void {
    if (($result['success'] ?? FALSE) === TRUE) {
      $this->setConnectionState($account, $success_state);
      if ($account->hasField('last_error')) {
        $account->set('last_error', '');
      }
      return;
    }

    $this->setConnectionState($account, 'ERROR');
    if ($account->hasField('last_error')) {
      $account->set('last_error', (string) ($result['error'] ?? $result['status'] ?? 'Unknown Evolution API error.'));
    }
  }

  /**
   * Sets the local connection state.
   */
  private function setConnectionState(ContentEntityInterface $account, string $state): void {
    if ($account->hasField('connection_status')) {
      $account->set('connection_status', $state);
    }
    if ($account->hasField('status')) {
      $account->set('status', $state === 'CONNECTED' ? 'active' : 'disconnected');
    }
  }

  /**
   * Stores the last QR code.
   */
  private function storeQr(ContentEntityInterface $account, string $qr): void {
    if ($account->hasField('last_qr')) {
      $account->set('last_qr', $qr);
    }
    if ($account->hasField('last_qr_generated')) {
      $account->set('last_qr_generated', time());
    }
  }

  /**
   * Clears QR-specific state.
   */
  private function clearQr(ContentEntityInterface $account): void {
    foreach (['last_qr', 'last_qr_generated'] as $field_name) {
      if ($account->hasField($field_name)) {
        $account->set($field_name, NULL);
      }
    }
  }

  /**
   * Extracts a QR string from known Evolution response shapes.
   *
   * @param array<string, mixed> $result
   *   API result.
   */
  private function extractQr(array $result): string {
    $data = is_array($result['data'] ?? NULL) ? $result['data'] : [];
    $candidates = [
      $data['base64'] ?? NULL,
      $data['qrcode'] ?? NULL,
      $data['qr'] ?? NULL,
      $data['code'] ?? NULL,
      $data['data']['base64'] ?? NULL,
      $data['data']['qrcode'] ?? NULL,
      $data['data']['qr'] ?? NULL,
      $data['data']['code'] ?? NULL,
    ];

    foreach ($candidates as $candidate) {
      if (is_string($candidate) && trim($candidate) !== '') {
        return trim($candidate);
      }
    }

    return '';
  }

  /**
   * Extracts and normalizes the remote connection state.
   *
   * @param array<string, mixed> $result
   *   API result.
   */
  private function extractConnectionState(array $result): string {
    if (($result['success'] ?? FALSE) !== TRUE) {
      return 'ERROR';
    }

    $data = is_array($result['data'] ?? NULL) ? $result['data'] : [];
    $state = strtolower((string) (
      $data['state']
      ?? $data['connection']
      ?? $data['instance']['state']
      ?? $data['instance']['connectionStatus']
      ?? $data['data']['state']
      ?? ''
    ));

    return match ($state) {
      'open', 'connected', 'online' => 'CONNECTED',
      'connecting' => 'CONNECTING',
      'qrcode', 'qr', 'waiting_qr' => 'WAITING_QR',
      'close', 'closed', 'disconnected', 'offline' => 'DISCONNECTED',
      default => 'ERROR',
    };
  }

  /**
   * Extracts a connected phone number from known Evolution response shapes.
   *
   * @param array<string, mixed> $result
   *   API result.
   */
  private function extractConnectedPhone(array $result): string {
    $data = is_array($result['data'] ?? NULL) ? $result['data'] : [];
    $raw = (string) (
      $data['instance']['ownerJid']
      ?? $data['instance']['profileName']
      ?? $data['ownerJid']
      ?? $data['data']['ownerJid']
      ?? ''
    );
    $phone = preg_replace('/\D+/', '', $raw) ?? '';

    return $phone;
  }

}
