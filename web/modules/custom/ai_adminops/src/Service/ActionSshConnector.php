<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Site\Settings;
use Symfony\Component\Process\Process;

/**
 * Runs approved actions over a dedicated SSH key, separate from monitoring.
 *
 * The key belongs to its own remote account, whose forced command only
 * accepts "adminops-act <action>" for the actions in ACTIONS and whose
 * sudoers entry only allows those cPanel scripts. The profile lives in
 * settings.php under "ai_adminops_ssh_actions"; without it, no action can
 * run and Sentinel is not even offered the action tools.
 */
final class ActionSshConnector implements ActionExecutorInterface {

  /**
   * {@inheritdoc}
   */
  public function isConfigured(ConfigEntityInterface $server): bool {
    return $this->profile($server) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function run(ConfigEntityInterface $server, string $action): array {
    if (!isset(self::ACTIONS[$action])) {
      throw new \InvalidArgumentException('The requested action is not allowed.');
    }

    $profile = $this->profile($server);
    if ($profile === NULL) {
      throw new \LogicException('No action SSH profile is configured for this server.');
    }

    $process = new Process([
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
      'adminops-act',
      $action,
    ]);
    // A graceful Apache restart can take a while on a busy server.
    $process->setTimeout(90);
    $process->run();

    $output = trim($process->getOutput());
    $result = $output === '' || strlen($output) > 65535 ? NULL : json_decode($output, TRUE);
    if (!$process->isSuccessful() || !is_array($result)) {
      throw new \RuntimeException('The action could not be completed on the server.');
    }

    return $result;
  }

  /**
   * Resolves the action profile stored exclusively in settings.php.
   *
   * @return array{username: string, private_key_path: string, known_hosts_path: string}|null
   *   The usable profile, or NULL when it was not configured.
   */
  private function profile(ConfigEntityInterface $server): ?array {
    if ((string) $server->get('connection_type') !== 'ssh') {
      return NULL;
    }

    $reference = trim((string) $server->get('credential_reference'));
    $profiles = Settings::get('ai_adminops_ssh_actions', []);
    $profile = is_array($profiles) && is_array($profiles[$reference] ?? NULL) ? $profiles[$reference] : [];

    $username = trim((string) ($profile['username'] ?? ''));
    $private_key_path = trim((string) ($profile['private_key_path'] ?? ''));
    $known_hosts_path = trim((string) ($profile['known_hosts_path'] ?? ''));
    if ($reference === '' || $username === '' || !is_file($private_key_path) || !is_readable($private_key_path) || !is_file($known_hosts_path) || !is_readable($known_hosts_path)) {
      return NULL;
    }

    return [
      'username' => $username,
      'private_key_path' => $private_key_path,
      'known_hosts_path' => $known_hosts_path,
    ];
  }

}
