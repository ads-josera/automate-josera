<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Approves Sentinel's actions with a one-time code sent by email.
 *
 * José's choice (2026-10-11): the code goes to his email, never to the
 * WhatsApp that asked, so a phone left unlocked is not enough to restart a
 * service. The flow:
 * 1. request(): an action request (AdminOps "Action requests") and a
 *    six-digit code, mailed to the address in settings.php. The code is
 *    stored only as a keyed hash and never reaches the model.
 * 2. confirm(): José types the code in WhatsApp. Three wrong codes refuse the
 *    request; a right one is used once, approves the request and runs the
 *    action, recorded in the AdminOps log with the request.
 *
 * The address lives in settings.php on purpose: someone who gets into the
 * panel cannot send the codes elsewhere.
 */
final class SentinelActionApproval {

  /**
   * Minutes a code stays valid.
   */
  public const CODE_MINUTES = 10;

  /**
   * Wrong codes before a request is refused.
   */
  public const MAX_ATTEMPTS = 3;

  /**
   * Requests (and so emails) per conversation per hour.
   */
  private const MAX_REQUESTS_PER_HOUR = 5;

  /**
   * Creates a SentinelActionApproval instance.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AdminOpsActionRequestManager $requestManager,
    private readonly AdminOpsExecutionAudit $executionAudit,
    private readonly ActionExecutorInterface $executor,
    private readonly MailManagerInterface $mailManager,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether actions can be requested at all: an address and an executor.
   */
  public function isAvailable(ConfigEntityInterface $server): bool {
    return $this->approvalEmail() !== '' && $this->executor->isConfigured($server);
  }

  /**
   * Opens a request and emails its code.
   *
   * @return array<string, mixed>
   *   What the model may tell José; never the code.
   */
  public function request(ConfigEntityInterface $server, string $action, string $reason, ContentEntityInterface $conversation): array {
    if (!isset(ActionExecutorInterface::ACTIONS[$action])) {
      throw new \InvalidArgumentException('Unknown action.');
    }
    if (!$this->isAvailable($server)) {
      return ['error' => 'Las acciones no están configuradas para este servidor.'];
    }

    $conversation_id = (string) $conversation->id();
    $now = $this->time->getRequestTime();
    $recent = array_filter((array) $this->state->get($this->key('sent', $conversation_id), []), static fn ($at) => $now - (int) $at < 3600);
    if (count($recent) >= self::MAX_REQUESTS_PER_HOUR) {
      return ['error' => 'Ya se enviaron ' . self::MAX_REQUESTS_PER_HOUR . ' códigos en la última hora. Espera un rato antes de pedir otro.'];
    }

    // One pending request per conversation: a new one replaces the old.
    $previous = $this->state->get($this->key('pending', $conversation_id));
    if (is_array($previous)) {
      $this->discard((int) $previous['request'], $conversation_id, 'expired', 'Reemplazada por otra solicitud.');
    }

    $label = ActionExecutorInterface::ACTIONS[$action];
    $reason = mb_substr(trim($reason), 0, 300);
    $request = $this->requestManager->request(
      (string) $server->id(),
      $action,
      $label,
      ['source' => 'sentinel', 'conversation' => $conversation_id],
      'critical',
      NULL,
      $now + self::CODE_MINUTES * 60,
      $reason,
    );
    $request_id = (int) $request->id();
    $code = sprintf('%06d', random_int(0, 999999));

    $delivery = $this->mailManager->mail('ai_adminops', 'sentinel_approval', $this->approvalEmail(), 'es', [
      'subject' => sprintf('[Sentinel] Código %s: %s en %s', $code, $label, $server->label()),
      'body' => implode("\n", [
        'Sentinel pidió permiso para hacer esto:',
        '',
        'Acción: ' . $label,
        'Servidor: ' . $server->label() . ' (' . $server->get('hostname') . ')',
        'Motivo: ' . ($reason !== '' ? $reason : '(sin motivo)'),
        'Solicitud: #' . $request_id,
        '',
        'Código: ' . $code,
        '',
        'Para aprobarla, escribe el código en WhatsApp a Sentinel. Vale ' . self::CODE_MINUTES . ' minutos y una sola vez.',
        'Si tú no lo pediste, no lo escribas: alguien más está usando tu número.',
      ]),
    ]);
    if (empty($delivery['result'])) {
      $this->requestManager->close($request_id, 'expired', 'No se pudo enviar el código por correo.');
      $this->logger->error('Sentinel approval code for request @request could not be emailed.', ['@request' => (string) $request_id]);
      return ['error' => 'No pude enviar el código por correo, así que la solicitud se canceló. No se hizo nada.'];
    }

    $this->state->set($this->key('code', (string) $request_id), [
      'hash' => $this->hash($request_id, $code),
      'attempts' => 0,
      'conversation' => $conversation_id,
    ]);
    $this->state->set($this->key('pending', $conversation_id), ['request' => $request_id]);
    $this->state->set($this->key('sent', $conversation_id), [...array_values($recent), $now]);

    return [
      'solicitud' => $request_id,
      'accion' => $label,
      'servidor' => $server->id(),
      'codigo_enviado_a' => $this->mask($this->approvalEmail()),
      'vence_en_minutos' => self::CODE_MINUTES,
      'siguiente_paso' => 'Cuando José escriba el código, llama a confirmar_accion solo con ese código. No pidas otra solicitud.',
    ];
  }

  /**
   * Checks a code against the conversation's pending request.
   *
   * The model only passes the code: between two WhatsApp messages it keeps
   * the text, not what its tools returned, so on 2026-10-11 it never knew
   * the request number and asked for a new code every time José typed one.
   *
   * @return array<string, mixed>
   *   The outcome for the model.
   */
  public function confirmPending(string $code, ContentEntityInterface $conversation): array {
    $pending = $this->state->get($this->key('pending', (string) $conversation->id()));
    if (!is_array($pending)) {
      return ['error' => 'No hay ninguna solicitud pendiente en esta conversación. Si José quiere la acción, pídela con solicitar_accion.'];
    }

    return $this->confirm((int) $pending['request'], $code, $conversation);
  }

  /**
   * Checks a code and, when right, runs the action once.
   *
   * @return array<string, mixed>
   *   The outcome for the model.
   */
  private function confirm(int $request_id, string $code, ContentEntityInterface $conversation): array {
    $conversation_id = (string) $conversation->id();
    $record = $this->state->get($this->key('code', (string) $request_id));
    // Only the conversation that asked can confirm, and only once.
    if (!is_array($record) || $record['conversation'] !== $conversation_id) {
      return ['error' => 'No hay una solicitud pendiente con ese número. Pide la acción de nuevo.'];
    }

    $request = $this->entityTypeManager->getStorage('ai_adminops_action_request')->load($request_id);
    $expires_at = $request instanceof ContentEntityInterface ? (int) $request->get('expires_at')->value : 0;
    if (!$request instanceof ContentEntityInterface || (string) $request->get('status')->value !== 'pending' || $expires_at <= $this->time->getRequestTime()) {
      $this->discard($request_id, $conversation_id, 'expired', 'El código venció.');
      return ['error' => 'La solicitud ya venció o se cerró. Pide la acción de nuevo para recibir otro código.'];
    }

    if (!hash_equals($record['hash'], $this->hash($request_id, trim($code)))) {
      $record['attempts']++;
      if ($record['attempts'] >= self::MAX_ATTEMPTS) {
        $this->discard($request_id, $conversation_id, 'rejected', self::MAX_ATTEMPTS . ' códigos incorrectos.');
        $this->logger->warning('Sentinel request @request refused after @count wrong codes.', ['@request' => (string) $request_id, '@count' => (string) self::MAX_ATTEMPTS]);
        return ['error' => 'Código incorrecto por ' . self::MAX_ATTEMPTS . 'ª vez: la solicitud se canceló y no se hizo nada.'];
      }
      $this->state->set($this->key('code', (string) $request_id), $record);
      return ['error' => 'Código incorrecto. Quedan ' . (self::MAX_ATTEMPTS - $record['attempts']) . ' intentos.'];
    }

    // Right code: used up before anything else happens.
    $this->state->delete($this->key('code', (string) $request_id));
    $this->state->delete($this->key('pending', $conversation_id));
    $this->requestManager->approve($request_id, $this->approver());

    return $this->execute($request);
  }

  /**
   * Runs an approved request and records it.
   *
   * @return array<string, mixed>
   *   The outcome for the model.
   */
  private function execute(ContentEntityInterface $request): array {
    $request_id = (int) $request->id();
    $server_id = (string) $request->get('server')->target_id;
    $action = (string) $request->get('tool_id')->value;
    $server = $this->entityTypeManager->getStorage('ai_adminops_server')->load($server_id);

    $execution = $this->executionAudit->begin($server_id, $action, ActionExecutorInterface::ACTIONS[$action] ?? $action, ['source' => 'sentinel'], 'critical', $request_id);
    $this->executionAudit->markRunning((int) $execution->id());
    try {
      if (!$server instanceof ConfigEntityInterface) {
        throw new \LogicException('The server no longer exists.');
      }
      $result = $this->executor->run($server, $action);
    }
    catch (\Throwable $exception) {
      $this->executionAudit->markFailed((int) $execution->id(), ['status' => 'failed']);
      $this->requestManager->close($request_id, 'failed', 'No se pudo completar en el servidor.');
      $this->logger->error('Sentinel action @action (request @request) failed: @message', [
        '@action' => $action,
        '@request' => (string) $request_id,
        '@message' => $exception->getMessage(),
      ]);
      return ['solicitud' => $request_id, 'resultado' => 'fallida', 'detalle' => 'El servidor no completó la acción. Revisa el estado del servicio.'];
    }

    // The server schedules the action a few seconds ahead and answers at
    // once: restarting Apache or PHP-FPM inline killed this very request on
    // 2026-10-11, and José never got the reply.
    $scheduled = ($result['status'] ?? '') === 'scheduled';
    $ok = $scheduled || ($result['status'] ?? '') === 'ok';
    $ok ? $this->executionAudit->markSucceeded((int) $execution->id(), $result) : $this->executionAudit->markFailed((int) $execution->id(), $result);
    $this->requestManager->close($request_id, $ok ? 'executed' : 'failed', $scheduled ? 'Programada en el servidor; el resultado queda en la revisión get_last_action.' : '');

    if ($scheduled) {
      return [
        'solicitud' => $request_id,
        'resultado' => 'programada',
        'se_ejecuta_en_segundos' => (int) ($result['runs_in_seconds'] ?? 30),
        'siguiente_paso' => 'Dile a José que quedó aprobada y que se ejecuta en esos segundos. No verifiques ahora: todavía no ocurre. Cuando José pregunte (en un minuto o más), usa leer_ahora y mira get_last_action.',
      ];
    }

    return ['solicitud' => $request_id, 'resultado' => $ok ? 'ejecutada' : 'fallida', 'respuesta_del_servidor' => $result];
  }

  /**
   * Closes a pending request and forgets its code.
   */
  private function discard(int $request_id, string $conversation_id, string $status, string $note): void {
    $this->state->delete($this->key('code', (string) $request_id));
    $this->state->delete($this->key('pending', $conversation_id));
    try {
      $this->requestManager->close($request_id, $status, $note);
    }
    catch (\LogicException) {
      // Already closed (expired in the panel, approved there…): nothing to do.
    }
  }

  /**
   * Returns the user the code was sent to, when there is one.
   */
  private function approver(): ?int {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $this->approvalEmail()]);
    $user = reset($users);

    return $user ? (int) $user->id() : NULL;
  }

  /**
   * The address codes go to, from settings.php only.
   */
  private function approvalEmail(): string {
    $email = trim((string) Settings::get('ai_adminops_sentinel_approval_email', ''));

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
  }

  /**
   * Keyed hash of a code, bound to its request.
   */
  private function hash(int $request_id, string $code): string {
    return hash_hmac('sha256', $request_id . ':' . $code, Settings::getHashSalt());
  }

  /**
   * Shows an address as a***@domain.
   */
  private function mask(string $email): string {
    [$local, $domain] = explode('@', $email, 2);

    return mb_substr($local, 0, 1) . '***@' . $domain;
  }

  /**
   * State key for this flow.
   */
  private function key(string $kind, string $id): string {
    return 'ai_adminops.sentinel.' . $kind . '.' . $id;
  }

}
