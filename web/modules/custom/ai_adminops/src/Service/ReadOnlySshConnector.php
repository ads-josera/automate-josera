<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\Process\Process;

/**
 * Runs the small, fixed set of remote read-only AdminOps checks.
 *
 * The remote key must use a forced-command wrapper. This class does not accept
 * arbitrary commands, passwords, command arguments, or host-key prompts.
 */
final class ReadOnlySshConnector {

  /**
   * Tool IDs accepted by both the local client and the remote wrapper.
   */
  private const ALLOWED_TOOLS = [
    'get_server_load',
    'get_cpu_usage',
    'get_memory_usage',
    'get_disk_usage',
    'get_exim_queue',
    'get_ssl_status',
  ];

  /**
   * Checks whether a server has a complete SSH profile in settings.php.
   */
  public function isConfigured(ConfigEntityInterface $server): bool {
    return $this->profile($server) !== NULL;
  }

  /**
   * Executes one fixed read-only monitoring tool.
   *
   * @return array<string, mixed>
   *   Normalized collector output.
   */
  public function collect(ConfigEntityInterface $server, string $tool_id): array {
    if (!in_array($tool_id, self::ALLOWED_TOOLS, TRUE)) {
      throw new \InvalidArgumentException('The requested monitoring tool is not allowed.');
    }

    $profile = $this->profile($server);
    if ($profile === NULL) {
      throw new \LogicException('No secure SSH profile is configured for this server.');
    }

    $command = [
      '/usr/bin/ssh',
      '-F', '/dev/null',
      '-o', 'BatchMode=yes',
      '-o', 'IdentitiesOnly=yes',
      '-o', 'StrictHostKeyChecking=yes',
      '-o', 'PasswordAuthentication=no',
      '-o', 'KbdInteractiveAuthentication=no',
      '-o', 'RequestTTY=no',
      '-o', 'UserKnownHostsFile=' . $profile['known_hosts_path'],
      '-i', $profile['private_key_path'],
      '-p', (string) $server->get('port'),
      $profile['username'] . '@' . $server->get('hostname'),
      'adminops-read',
      $tool_id,
    ];

    $process = new Process($command);
    $process->setTimeout(20);
    $process->run();

    if (!$process->isSuccessful()) {
      throw new \RuntimeException('The read-only SSH monitoring command could not be completed.');
    }

    $output = trim($process->getOutput());
    if ($output === '' || strlen($output) > 65535) {
      throw new \RuntimeException('The monitoring connector returned an invalid response.');
    }

    try {
      $result = json_decode($output, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \RuntimeException('The monitoring connector returned an invalid response.');
    }
    if (!is_array($result)) {
      throw new \RuntimeException('The monitoring connector returned an invalid response.');
    }

    return $result;
  }

  /**
   * Resolves a non-secret profile stored exclusively in settings.php.
   *
   * @return array{username: string, private_key_path: string, known_hosts_path: string}|null
   *   The usable local SSH profile, or NULL when it was not configured.
   */
  private function profile(ConfigEntityInterface $server): ?array {
    if ((string) $server->get('connection_type') !== 'ssh') {
      return NULL;
    }

    $reference = trim((string) $server->get('credential_reference'));
    $profiles = Settings::get('ai_adminops_ssh', []);
    $profile = is_array($profiles) && isset($profiles[$reference]) && is_array($profiles[$reference])
      ? $profiles[$reference]
      : [];

    $username = trim((string) ($profile['username'] ?? ''));
    $private_key_path = trim((string) ($profile['private_key_path'] ?? ''));
    $known_hosts_path = trim((string) ($profile['known_hosts_path'] ?? ''));
    if ($reference === '' || $username === '' || $private_key_path === '' || $known_hosts_path === '') {
      return NULL;
    }
    if (!is_file($private_key_path) || !is_readable($private_key_path) || !is_file($known_hosts_path) || !is_readable($known_hosts_path)) {
      return NULL;
    }

    return [
      'username' => $username,
      'private_key_path' => $private_key_path,
      'known_hosts_path' => $known_hosts_path,
    ];
  }

}
