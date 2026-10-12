<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_adminops\Kernel;

use Drupal\ai_adminops\Service\ActionExecutorInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Test\AssertMailTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Sentinel's actions: nothing runs without the emailed code.
 *
 * A scripted executor stands in for the server and records every action it
 * is asked to run; no test reaches SSH.
 */
#[Group('ai_adminops')]
#[RunTestsInSeparateProcesses]
final class SentinelActionApprovalTest extends AdminOpsKernelTestBase {

  use AssertMailTrait;

  /**
   * Actions the scripted executor ran.
   */
  public array $ran = [];

  /**
   * What the scripted executor returns, or throws.
   */
  public array|\Throwable $actionResult = ['status' => 'ok', 'message' => 'httpd restarted'];

  /**
   * Whether the scripted executor reports itself configured.
   */
  public bool $actionsConfigured = TRUE;

  private ContentEntityInterface $conversation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('ai_whatsapp_conversation');
    $this->setSetting('ai_adminops_sentinel_approval_email', 'jose@example.com');

    $test = $this;
    $this->container->set('ai_adminops.action_ssh_connector', new class($test) implements ActionExecutorInterface {

      public function __construct(private readonly SentinelActionApprovalTest $test) {}

      public function isConfigured(ConfigEntityInterface $server): bool {
        return $this->test->actionsConfigured;
      }

      public function run(ConfigEntityInterface $server, string $action): array {
        $this->test->ran[] = $action;
        if ($this->test->actionResult instanceof \Throwable) {
          throw $this->test->actionResult;
        }
        return $this->test->actionResult;
      }

    });
    $this->conversation = $this->newConversation();
  }

  /**
   * Without an address or an action profile, Sentinel is not told of actions.
   */
  public function testNoActionToolsUntilConfigured(): void {
    $this->assertSame(['solicitar_accion', 'confirmar_accion'], array_column($this->tools()->definitions(), 'name'));

    $this->actionsConfigured = FALSE;
    $this->assertSame([], $this->tools()->definitions(), 'No action profile in settings.php.');

    $this->actionsConfigured = TRUE;
    $this->setSetting('ai_adminops_sentinel_approval_email', '');
    $this->assertSame([], $this->tools()->definitions(), 'No approval address in settings.php.');
  }

  /**
   * Asking emails a code, runs nothing, and never shows the model the code.
   */
  public function testARequestEmailsTheCodeAndRunsNothing(): void {
    $result = $this->ask();

    $this->assertSame([], $this->ran);
    $mails = $this->getMails(['key' => 'sentinel_approval']);
    $this->assertCount(1, $mails);
    $this->assertSame('jose@example.com', $mails[0]['to']);
    $code = $this->codeFrom($mails[0]);
    $this->assertStringNotContainsString($code, json_encode($result), 'The model never gets the code.');
    $this->assertSame('j***@example.com', $result['codigo_enviado_a']);

    $request = $this->request($result['solicitud']);
    $this->assertSame('pending', $request->get('status')->value);
    $this->assertSame('critical', $request->get('risk')->value);
    $this->assertSame('Apache no responde', $request->get('note')->value);
  }

  /**
   * The right code runs the action once, recorded with its request.
   */
  public function testTheRightCodeRunsTheActionOnce(): void {
    $result = $this->ask();
    $code = $this->codeFrom($this->getMails()[0]);

    $outcome = $this->confirm($code);

    $this->assertSame('ejecutada', $outcome['resultado']);
    $this->assertSame(['restart_apache'], $this->ran);
    $this->assertSame('executed', $this->request($result['solicitud'])->get('status')->value);
    $execution = current($this->container->get('entity_type.manager')->getStorage('ai_adminops_tool_execution')->loadByProperties(['tool_id' => 'restart_apache']));
    $this->assertSame('succeeded', $execution->get('status')->value);
    $this->assertSame('critical', $execution->get('risk')->value);
    $this->assertSame((string) $result['solicitud'], (string) $execution->get('action_request')->target_id);

    $again = $this->confirm($code);
    $this->assertArrayHasKey('error', $again, 'A code works once.');
    $this->assertSame(['restart_apache'], $this->ran);
  }

  /**
   * The model confirms with the code alone, and a wrong length is a try.
   *
   * On 2026-10-11 the tool needed the request number, which the model does
   * not keep between messages, so it asked for a new code every time José
   * typed one and nothing ever ran.
   */
  public function testTheCodeAloneConfirms(): void {
    $definitions = array_column($this->tools()->definitions(), NULL, 'name');
    $this->assertSame(['codigo'], $definitions['confirmar_accion']['parameters']['required']);

    $this->ask();
    $this->assertStringContainsString('Quedan 2', $this->confirm('12344456')['error'], 'Eight digits count as a wrong code.');
    $this->assertSame('ejecutada', $this->confirm($this->codeFrom($this->getMails()[0]))['resultado']);
    $this->assertSame(['restart_apache'], $this->ran);
    $this->assertArrayHasKey('error', $this->confirm('123456'), 'Nothing is pending any more.');
  }

  /**
   * Three wrong codes refuse the request; the right one is useless after.
   */
  public function testThreeWrongCodesRefuseTheRequest(): void {
    $result = $this->ask();
    $code = $this->codeFrom($this->getMails()[0]);
    $wrong = $code === '000000' ? '111111' : '000000';

    $first = $this->confirm($wrong);
    $this->assertStringContainsString('Quedan 2', $first['error']);
    $this->confirm($wrong);
    $this->confirm($wrong);

    $this->assertSame('rejected', $this->request($result['solicitud'])->get('status')->value);
    $this->assertArrayHasKey('error', $this->confirm($code));
    $this->assertSame([], $this->ran);
  }

  /**
   * An expired code runs nothing.
   */
  public function testAnExpiredCodeRunsNothing(): void {
    $result = $this->ask();
    $code = $this->codeFrom($this->getMails()[0]);
    $this->request($result['solicitud'])->set('expires_at', time() - 1)->save();

    $this->assertArrayHasKey('error', $this->confirm($code));
    $this->assertSame('expired', $this->request($result['solicitud'])->get('status')->value);
    $this->assertSame([], $this->ran);
  }

  /**
   * Only the conversation that asked can confirm.
   */
  public function testAnotherConversationCannotConfirm(): void {
    $result = $this->ask();
    $code = $this->codeFrom($this->getMails()[0]);

    $outcome = $this->tools()->execute('confirmar_accion', ['codigo' => $code], $this->newConversation());

    $this->assertArrayHasKey('error', $outcome);
    $this->assertSame([], $this->ran);
    $this->assertSame('pending', $this->request($result['solicitud'])->get('status')->value);
  }

  /**
   * A new request replaces the pending one, whose code stops working.
   */
  public function testANewRequestReplacesThePendingOne(): void {
    $first = $this->ask();
    $first_code = $this->codeFrom($this->getMails()[0]);
    $this->ask('restart_php_fpm');

    $this->assertSame('expired', $this->request($first['solicitud'])->get('status')->value);
    $this->assertArrayHasKey('error', $this->confirm($first_code));
    $this->assertSame([], $this->ran);
  }

  /**
   * At most five codes an hour per conversation.
   */
  public function testCodesAreRateLimited(): void {
    for ($i = 0; $i < 5; $i++) {
      $this->assertArrayHasKey('solicitud', $this->ask());
    }

    $this->assertArrayHasKey('error', $this->ask());
    $this->assertCount(5, $this->getMails(['key' => 'sentinel_approval']));
  }

  /**
   * If the email cannot be sent, the request is cancelled.
   */
  public function testAFailedEmailCancelsTheRequest(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturn(['result' => FALSE]);
    $this->container->set('plugin.manager.mail', $mail);
    $this->container->set('ai_adminops.sentinel_action_approval', NULL);
    $this->container->set('ai_adminops.sentinel_action_tools', NULL);

    $result = $this->ask();

    $this->assertArrayHasKey('error', $result);
    $requests = $this->container->get('entity_type.manager')->getStorage('ai_adminops_action_request')->loadMultiple();
    $this->assertSame(['expired'], array_values(array_map(static fn ($request) => $request->get('status')->value, $requests)));
  }

  /**
   * A failure on the server is reported and recorded, not hidden.
   */
  public function testAServerFailureIsRecorded(): void {
    $this->actionResult = new \RuntimeException('Permission denied');
    $result = $this->ask();

    $outcome = $this->confirm($this->codeFrom($this->getMails()[0]));

    $this->assertSame('fallida', $outcome['resultado']);
    $this->assertStringNotContainsString('Permission denied', json_encode($outcome));
    $this->assertSame('failed', $this->request($result['solicitud'])->get('status')->value);
  }

  /**
   * Only the two listed actions exist.
   */
  public function testOnlyListedActions(): void {
    $definitions = array_column($this->tools()->definitions(), NULL, 'name');
    $this->assertSame(['restart_apache', 'restart_php_fpm'], $definitions['solicitar_accion']['parameters']['properties']['accion']['enum']);

    $this->expectException(\InvalidArgumentException::class);
    $this->ask('restart_mysql');
  }

  /**
   * Asks for an action as the model would.
   */
  private function ask(string $action = 'restart_apache'): array {
    return $this->tools()->execute('solicitar_accion', ['servidor' => 'produccion', 'accion' => $action, 'motivo' => 'Apache no responde'], $this->conversation);
  }

  /**
   * Confirms with only the code, as the model does between two messages.
   */
  private function confirm(string $code): array {
    return $this->tools()->execute('confirmar_accion', ['codigo' => $code], $this->conversation);
  }

  /**
   * Returns the action tools provider.
   */
  private function tools(): object {
    return $this->container->get('ai_adminops.sentinel_action_tools');
  }

  /**
   * Reads the six-digit code from an approval email.
   */
  private function codeFrom(array $mail): string {
    $this->assertMatchesRegularExpression('/Código: (\d{6})/', $mail['body']);
    preg_match('/Código: (\d{6})/', $mail['body'], $match);

    return $match[1];
  }

  /**
   * Loads an action request, fresh.
   */
  private function request(int $id): ContentEntityInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_adminops_action_request');
    $storage->resetCache([$id]);

    return $storage->load($id);
  }

  /**
   * Creates a WhatsApp conversation.
   */
  private function newConversation(): ContentEntityInterface {
    $conversation = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_conversation')->create([
      'phone' => '+5215530050231',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
    ]);
    $conversation->save();

    return $conversation;
  }

}
