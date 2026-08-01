<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Plugin\Mail;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends Drupal mail through the Amazon SES HTTPS API.
 *
 * Credentials live in settings.php so they never enter configuration exports
 * or the database.
 */
#[Mail(
  id: 'amazon_ses_api',
  label: new TranslatableMarkup('Amazon SES API'),
  description: new TranslatableMarkup('Sends email through the Amazon SES HTTPS API.'),
)]
final class AmazonSesApiMail implements MailInterface, ContainerFactoryPluginInterface {

  /**
   * Creates the mail backend.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($container->get('logger.channel.mail'));
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    foreach ($message['body'] as &$part) {
      $part = $part instanceof MarkupInterface
        ? MailFormatHelper::htmlToText($part)
        : MailFormatHelper::wrapMail((string) $part);
    }

    $message['body'] = implode("\n\n", $message['body']);
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    $settings = Settings::get('ai_whatsapp_automation_ses', []);
    if (!$this->isConfigured($settings)) {
      $this->logger->error('Amazon SES API mail is not configured in settings.php.');
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
        'Destinations' => $this->recipients($message),
        'RawMessage' => [
          'Data' => $email->toString(),
        ],
      ]);

      $this->logger->info('Amazon SES accepted mail @id for delivery.', [
        '@id' => (string) $result->get('MessageId'),
      ]);
      return TRUE;
    }
    catch (AwsException $exception) {
      $this->logger->error('Amazon SES rejected mail delivery: @message', [
        '@message' => $exception->getAwsErrorMessage() ?: $exception->getMessage(),
      ]);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Amazon SES mail delivery failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }

    return FALSE;
  }

  /**
   * Builds a MIME message for SES raw delivery.
   */
  private function buildEmail(array $message, array $settings): Email {
    $email = (new Email())
      ->from(new Address(
        $settings['from_address'],
        (string) ($settings['from_name'] ?? '')
      ))
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
    if (str_starts_with(strtolower($headers['content-type'] ?? ''), 'text/html')) {
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
    $addresses = [];
    foreach (str_getcsv($value, escape: '\\') as $address) {
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
