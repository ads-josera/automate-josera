<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Client;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests how a client's user is welcomed: account e-mail and first landing.
 *
 * Both only change for users with the client agent role: administrators and
 * any other account keep Drupal's own behaviour.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ClientAgentWelcomeTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'options',
    'text',
    'views',
    'ai_whatsapp_automation',
  ];

  /**
   * A user of client "JG Mylard".
   */
  private UserInterface $agent;

  /**
   * A user without the client agent role.
   */
  private UserInterface $other;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('ai_whatsapp_client');
    $this->installConfig(['system', 'user', 'ai_whatsapp_automation']);
    $this->container->get('router.builder')->rebuild();
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('name', 'Josera Automate')->save();

    // The first user (uid 1) bypasses access checks: real users come after it.
    $this->createUser();
    $role = Role::create([
      'id' => 'ai_whatsapp_client_agent',
      'label' => 'Atención a clientes',
    ]);
    $role->grantPermission('view ai whatsapp automation dashboard')->save();
    $client = $this->container->get('entity_type.manager')
      ->getStorage('ai_whatsapp_client')
      ->create(['name' => 'JG Mylard']);
    $client->save();

    $this->agent = $this->createUser([], 'aseguramientojg', FALSE, [
      'mail' => 'agente@example.com',
      'roles' => ['ai_whatsapp_client_agent'],
      'ai_whatsapp_client' => $client->id(),
    ]);
    $this->other = $this->createUser([], 'otro', FALSE, ['mail' => 'otro@example.com']);
  }

  /**
   * The new account e-mail is Spanish, HTML and speaks about their chatbot.
   */
  public function testAccountEmailIsSpanishAndAboutTheirChatbot(): void {
    $message = $this->notify('register_admin_created', $this->agent);

    $this->assertStringContainsString('chatbot', mb_strtolower($message['subject']), 'The subject names what the account is for');
    $this->assertStringNotContainsString('An administrator created an account', $message['subject'], 'Drupal default subject is replaced');
    $this->assertStringContainsString('text/html', $message['headers']['Content-Type'], 'The e-mail is sent as HTML');
    $body = (string) $message['body'];
    $this->assertStringContainsString('JG Mylard', $body, 'It names the client the account belongs to');
    $this->assertStringContainsString('WhatsApp', $body);
    $this->assertStringContainsString('/user/reset/', $body, 'The one-time login link is kept');
    $this->assertStringNotContainsString('A site administrator at', $body, 'No English left over');
    $this->assertStringNotContainsString('[user:', $body, 'Every token is replaced');
  }

  /**
   * The body is HTML with a real button, as Amazon SES sends it.
   *
   * Asserted on the message itself: the test mail collector, unlike the SES
   * mailer used in production, turns every body into plain text.
   */
  public function testTheBodyIsHtmlWithALinkButton(): void {
    $message = [
      'module' => 'user',
      'key' => 'register_admin_created',
      'params' => ['account' => $this->agent],
      'subject' => '',
      'body' => [],
      'headers' => [],
    ];
    $this->container->get('ai_whatsapp_automation.client_agent_mail')->alter($message);

    $body = (string) $message['body'][0];
    $this->assertMatchesRegularExpression('#<a href="https?://[^"]+/user/reset/#', $body, 'The button points at the one-time login link');
    $this->assertStringContainsString('Activar mi cuenta', $body);
    $this->assertStringContainsString('text/html', $message['headers']['Content-Type']);
  }

  /**
   * Password reset and activation e-mails follow the same treatment.
   */
  public function testOtherAccountEmailsAreAlsoSpanish(): void {
    foreach (['password_reset', 'status_activated'] as $key) {
      $message = $this->notify($key, $this->agent);
      $this->assertStringContainsString('text/html', $message['headers']['Content-Type'], "$key is HTML");
      $this->assertStringContainsString('/user/reset/', (string) $message['body'], "$key keeps its link");
      $this->assertStringNotContainsString('Replacement login information', (string) $message['body'], "$key has no English left");
    }
  }

  /**
   * A user without the role keeps Drupal's own e-mail.
   */
  public function testUsersWithoutTheRoleKeepTheDefaultEmail(): void {
    $message = $this->notify('register_admin_created', $this->other);

    $this->assertStringContainsString('An administrator created an account for you', $message['subject'], 'Drupal\'s own English e-mail');
    $this->assertStringNotContainsString('chatbot', mb_strtolower((string) $message['body']));
    $this->assertStringNotContainsString('text/html', $message['headers']['Content-Type'] ?? '', 'Plain text, as Drupal sends it');
  }

  /**
   * After logging in, a client user lands on the dashboard.
   */
  public function testClientAgentLandsOnTheDashboardAfterLogin(): void {
    // The login submit handler has already logged the account in.
    $this->setCurrentUser($this->agent);
    $form = [];
    $form_state = new FormState();
    $form_state->set('uid', $this->agent->id());
    _ai_whatsapp_automation_login_redirect($form, $form_state);

    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('ai_whatsapp_automation.dashboard', $redirect->getRouteName());
  }

  /**
   * Everybody else keeps the landing Drupal chose (their own profile).
   */
  public function testOtherUsersKeepTheirOwnLanding(): void {
    $this->setCurrentUser($this->other);
    $form = [];
    $form_state = new FormState();
    $form_state->set('uid', $this->other->id());
    _ai_whatsapp_automation_login_redirect($form, $form_state);

    $this->assertNull($form_state->getRedirect(), 'Drupal decides where other users land');
  }

  /**
   * With a second factor pending nobody is logged in: TFA keeps its redirect.
   */
  public function testPendingSecondFactorIsLeftAlone(): void {
    $this->setCurrentUser(new AnonymousUserSession());
    $form = [];
    $form_state = new FormState();
    $form_state->set('uid', $this->agent->id());
    _ai_whatsapp_automation_login_redirect($form, $form_state);

    $this->assertNull($form_state->getRedirect(), 'The TFA entry form is not skipped');
  }

  /**
   * Saving their own account (first login sets the password) ends in the panel.
   */
  public function testSavingOwnAccountEndsInTheDashboard(): void {
    $this->setCurrentUser($this->agent);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('user', 'default');
    $form_object->setEntity($this->agent);
    $form = [];
    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    _ai_whatsapp_automation_account_saved_redirect($form, $form_state);

    $redirect = $form_state->getRedirect();
    $this->assertInstanceOf(Url::class, $redirect);
    $this->assertSame('ai_whatsapp_automation.dashboard', $redirect->getRouteName());

    // An administrator editing that same account stays where Drupal sends them.
    $this->setCurrentUser($this->createUser(['administer users']));
    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    _ai_whatsapp_automation_account_saved_redirect($form, $form_state);
    $this->assertNull($form_state->getRedirect(), 'Editing somebody else does not move the administrator');
  }

  /**
   * Sends one of the user module notifications and returns the sent message.
   *
   * @return array<string, mixed>
   *   The collected message.
   */
  private function notify(string $key, UserInterface $account): array {
    \Drupal::state()->set('system.test_mail_collector', []);
    _user_mail_notify($key, $account);
    $collected = \Drupal::state()->get('system.test_mail_collector', []);
    $this->assertCount(1, $collected, "Notification $key was sent");

    return end($collected);
  }

}
