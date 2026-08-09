<?php

declare(strict_types=1);

namespace Drupal\ses_api_mailer\Plugin\Mail;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends Drupal mail through the Amazon SES HTTPS API.
 *
 * Credentials are read from settings.php and never stored in configuration.
 */
#[Mail(
  id: 'ses_api_mailer',
  label: new TranslatableMarkup('Amazon SES API'),
  description: new TranslatableMarkup('Sends email through the Amazon SES HTTPS API.'),
)]
final class SesApiMailer implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * Creates the mail backend.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $container->get('logger.channel.mail'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('lock'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    $body = is_array($message['body'] ?? NULL) ? $message['body'] : [$message['body'] ?? ''];
    $is_html = $this->isHtmlMessage($message);

    foreach ($body as $part) {
      // Webform supplies HTML email bodies as markup. Preserve it instead of
      // converting it to plain text, otherwise CSS and markup are displayed
      // literally by the recipient's mail client.
      $is_html = $is_html || $part instanceof MarkupInterface;
    }

    foreach ($body as &$part) {
      $part = $is_html
        ? (string) $part
        : MailFormatHelper::wrapMail((string) $part);
    }

    if ($is_html) {
      $message['html'] = TRUE;
      $message['headers']['Content-Type'] = 'text/html; charset=UTF-8';
    }

    $message['body'] = implode("\n\n", $body);
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    $settings = $this->settings();
    if (!$this->isConfigured($settings)) {
      $message = 'Amazon SES API mail is not configured in settings.php.';
      $this->recordFailure($message);
      $this->logger->error($message);
      return FALSE;
    }

    $recipients = $this->recipients($message);
    if ($recipients === []) {
      $failure = 'Amazon SES mail delivery failed: no recipients were supplied.';
      $this->recordFailure($failure);
      $this->logger->error($failure);
      return FALSE;
    }

    $reserved_recipients = count($recipients);
    if (!$this->reserveDailyCapacity($reserved_recipients)) {
      return FALSE;
    }

    try {
      $email = $this->buildEmail($message, $settings);
      $client = new SesClient([
        'version' => 'latest',
        'region' => $settings['region'] ?? 'us-east-1',
        'credentials' => [
          'key' => $settings['access_key_id'],
          'secret' => $settings['secret_access_key'],
        ],
      ]);

      $result = $client->sendRawEmail([
        'Source' => $settings['from_address'],
        'Destinations' => $recipients,
        'RawMessage' => ['Data' => $email->toString()],
      ]);

      $this->recordSuccess($reserved_recipients, (string) $result->get('MessageId'));
      $this->logger->info('Amazon SES accepted mail @id for delivery.', [
        '@id' => (string) $result->get('MessageId'),
      ]);
      return TRUE;
    }
    catch (AwsException $exception) {
      $this->releaseDailyCapacity($reserved_recipients);
      $failure = $exception->getAwsErrorMessage() ?: $exception->getMessage();
      $this->recordFailure($failure);
      $this->logger->error('Amazon SES rejected mail delivery: @message', [
        '@message' => $failure,
      ]);
    }
    catch (\Throwable $exception) {
      $this->releaseDailyCapacity($reserved_recipients);
      $this->recordFailure($exception->getMessage());
      $this->logger->error('Amazon SES mail delivery failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * Reserves daily recipient capacity before requesting SES delivery.
   */
  private function reserveDailyCapacity(int $recipients): bool {
    $limit = $this->dailySendLimit();
    $lock_name = 'ses_api_mailer.daily_send_limit.' . $this->currentDay();
    if (!$this->lock->acquire($lock_name, 5.0)) {
      $this->recordFailure('The local SES daily send counter is busy.');
      $this->logger->error('Amazon SES mail delivery was not attempted because the daily send counter is busy.');
      return FALSE;
    }

    try {
      $key = $this->dailyCounterKey();
      $sent = (int) $this->state->get($key, 0);
      if ($limit > 0 && $sent + $recipients > $limit) {
        $this->recordFailure('The local SES daily recipient limit was reached.');
        $this->logger->warning('Amazon SES daily recipient limit reached (@sent of @limit). Mail was not sent.', [
          '@sent' => $sent,
          '@limit' => $limit,
        ]);
        return FALSE;
      }
      $this->state->set($key, $sent + $recipients);
      return TRUE;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Returns unused capacity when SES rejects a request.
   */
  private function releaseDailyCapacity(int $recipients): void {
    $lock_name = 'ses_api_mailer.daily_send_limit.' . $this->currentDay();
    if (!$this->lock->acquire($lock_name, 5.0)) {
      return;
    }

    try {
      $key = $this->dailyCounterKey();
      $sent = (int) $this->state->get($key, 0);
      $this->state->set($key, max(0, $sent - $recipients));
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Gets the daily recipient limit, where zero means unlimited.
   */
  private function dailySendLimit(): int {
    return max(0, (int) ($this->configFactory->get('ses_api_mailer.settings')->get('daily_send_limit') ?? 50));
  }

  /**
   * Gets the current date in the site's timezone.
   */
  private function currentDay(): string {
    $timezone = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: date_default_timezone_get());
    return (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d');
  }

  /**
   * Gets the state key for the current day's accepted recipients.
   */
  private function dailyCounterKey(): string {
    return 'ses_api_mailer.daily_send_count.' . $this->currentDay();
  }

  /**
   * Records the latest accepted delivery without retaining message data.
   */
  private function recordSuccess(int $recipients, string $message_id): void {
    $this->state->set('ses_api_mailer.last_success', [
      'timestamp' => time(),
      'recipients' => $recipients,
      'message_id' => $message_id,
    ]);
  }

  /**
   * Records the latest failure for the administrator health panel.
   */
  private function recordFailure(string $message): void {
    $this->state->set('ses_api_mailer.last_failure', [
      'timestamp' => time(),
      'message' => mb_substr(trim($message), 0, 240),
    ]);
  }

  /**
   * Returns secure SES settings, with a temporary legacy fallback.
   */
  private function settings(): array {
    $settings = Settings::get('ses_api_mailer', []);
    if (is_array($settings) && $settings !== []) {
      return $settings;
    }

    // Supports the initial project integration while it is migrated.
    $legacy = Settings::get('ai_whatsapp_automation_ses', []);
    return is_array($legacy) ? $legacy : [];
  }

  /**
   * Builds a MIME message for SES raw delivery.
   */
  private function buildEmail(array $message, array $settings): Email {
    $email = (new Email())
      ->from(new Address($settings['from_address'], (string) ($settings['from_name'] ?? '')))
      ->to(...$this->addresses((string) $message['to']))
      ->subject((string) $message['subject']);

    $headers = $this->headers($message);
    if (!empty($headers['reply-to'])) {
      $email->replyTo(...$this->addresses($headers['reply-to']));
    }
    if (!empty($headers['cc'])) {
      $email->cc(...$this->addresses($headers['cc']));
    }
    if (!empty($headers['bcc'])) {
      $email->bcc(...$this->addresses($headers['bcc']));
    }

    $body = (string) $message['body'];
    if ($this->isHtmlMessage($message)) {
      $email->html($body);
    }
    else {
      $email->text($body);
    }

    $this->addAttachments($email, $message['params']['attachments'] ?? []);
    return $email;
  }

  /**
   * Returns all envelope recipients without duplicates.
   */
  private function recipients(array $message): array {
    $headers = $this->headers($message);
    $addresses = [
      ...$this->addresses((string) $message['to']),
      ...$this->addresses($headers['cc'] ?? ''),
      ...$this->addresses($headers['bcc'] ?? ''),
    ];

    $recipients = [];
    foreach ($addresses as $address) {
      $recipients[strtolower($address->getAddress())] = $address->getAddress();
    }
    return array_values($recipients);
  }

  /**
   * Parses a Drupal recipient header into Symfony addresses.
   */
  private function addresses(string $value): array {
    if ($value === '') {
      return [];
    }

    $addresses = [];
    foreach (str_getcsv($value, escape: '\\') as $address) {
      if (!is_string($address)) {
        continue;
      }
      $address = trim($address);
      if ($address !== '') {
        $addresses[] = Address::create($address);
      }
    }
    return $addresses;
  }

  /**
   * Normalizes message header names.
   */
  private function headers(array $message): array {
    $headers = [];
    foreach ($message['headers'] ?? [] as $name => $value) {
      $headers[strtolower((string) $name)] = (string) $value;
    }
    return $headers;
  }

  /**
   * Determines whether a Drupal mail message contains HTML.
   */
  private function isHtmlMessage(array $message): bool {
    if (!empty($message['html'])) {
      return TRUE;
    }

    $headers = $this->headers($message);
    return str_starts_with(strtolower($headers['content-type'] ?? ''), 'text/html');
  }

  /**
   * Adds common Drupal attachment structures to the MIME message.
   */
  private function addAttachments(Email $email, array $attachments): void {
    foreach ($attachments as $attachment) {
      if (!is_array($attachment)) {
        continue;
      }

      $filename = $attachment['filename'] ?? NULL;
      $mime_type = $attachment['filemime'] ?? NULL;
      if (!empty($attachment['filecontent'])) {
        $email->attach((string) $attachment['filecontent'], $filename, $mime_type);
      }
      elseif (!empty($attachment['filepath']) && is_readable($attachment['filepath'])) {
        $email->attachFromPath($attachment['filepath'], $filename, $mime_type);
      }
    }
  }

  /**
   * Checks the secure settings needed for SES API delivery.
   */
  private function isConfigured(mixed $settings): bool {
    return is_array($settings)
      && !empty($settings['access_key_id'])
      && !empty($settings['secret_access_key'])
      && !empty($settings['from_address']);
  }

}
