<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form\Evolution;

use Drupal\ai_whatsapp_automation\Application\Evolution\InstanceManagerService;
use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides QR connection management for an Evolution WhatsApp account.
 */
final class EvolutionQrForm extends FormBase {

  /**
   * The WhatsApp account being managed.
   */
  private ContentEntityInterface $account;

  /**
   * Constructs an EvolutionQrForm object.
   */
  public function __construct(
    private readonly InstanceManagerService $instanceManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_whatsapp_automation.evolution.instance_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_whatsapp_automation_evolution_qr_form';
  }

  /**
   * Builds the QR management form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, mixed $ai_whatsapp_account = NULL): array {
    $this->account = $ai_whatsapp_account;

    if (!$this->account instanceof ContentEntityInterface || $this->fieldValue('provider') !== 'evolution') {
      $form['message'] = [
        '#markup' => $this->t('This screen only supports WhatsApp accounts using the Evolution API provider.'),
      ];

      return $form;
    }

    $form['summary'] = [
      '#type' => 'table',
      '#header' => [$this->t('Field'), $this->t('Value')],
      '#rows' => [
        [$this->t('Account'), $this->account->label()],
        [$this->t('Instance name'), $this->fieldValue('evolution_instance_name') ?: $this->t('Not created yet')],
        [$this->t('Connection status'), $this->fieldValue('connection_status') ?: $this->t('Disconnected')],
        [$this->t('Connected number'), $this->fieldValue('connected_phone_number') ?: $this->t('Not connected')],
        [$this->t('Connected at'), $this->formatTimestamp('connected_at')],
        [$this->t('Last QR generated'), $this->formatTimestamp('last_qr_generated')],
        [$this->t('Last status check'), $this->formatTimestamp('last_status_check')],
      ],
    ];

    if ($this->fieldValue('last_error') !== '') {
      $form['last_error'] = [
        '#type' => 'item',
        '#title' => $this->t('Last error'),
        '#markup' => Html::escape($this->fieldValue('last_error')),
      ];
    }

    $qr = $this->fieldValue('last_qr');
    if ($qr !== '') {
      $form['qr'] = [
        '#type' => 'details',
        '#title' => $this->t('QR code'),
        '#open' => TRUE,
      ];
      $form['qr']['image'] = $this->buildQrPreview($qr);
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create instance'),
      '#submit' => ['::createInstance'],
      '#button_type' => 'primary',
    ];
    $form['actions']['qr'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate QR'),
      '#submit' => ['::generateQr'],
    ];
    $form['actions']['refresh'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh status'),
      '#submit' => ['::refreshStatus'],
    ];
    $form['actions']['reconnect'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reconnect'),
      '#submit' => ['::reconnect'],
    ];
    $form['actions']['disconnect'] = [
      '#type' => 'submit',
      '#value' => $this->t('Disconnect'),
      '#submit' => ['::disconnect'],
      '#attributes' => [
        'class' => ['button--danger'],
      ],
    ];

    return $form;
  }

  /**
   * Creates an Evolution instance.
   */
  public function createInstance(array &$form, FormStateInterface $form_state): void {
    $this->handleResult($this->instanceManager->createInstance($this->account), $this->t('The Evolution instance was created.'));
  }

  /**
   * Requests a fresh QR.
   */
  public function generateQr(array &$form, FormStateInterface $form_state): void {
    $this->handleResult($this->instanceManager->requestQr($this->account), $this->t('A fresh QR code was requested.'));
  }

  /**
   * Refreshes connection status.
   */
  public function refreshStatus(array &$form, FormStateInterface $form_state): void {
    $this->handleResult($this->instanceManager->refreshStatus($this->account), $this->t('The connection status was refreshed.'));
  }

  /**
   * Restarts the instance and asks for a QR.
   */
  public function reconnect(array &$form, FormStateInterface $form_state): void {
    $this->instanceManager->restartInstance($this->account);
    $this->handleResult($this->instanceManager->requestQr($this->account), $this->t('The Evolution instance was restarted and a QR code was requested.'));
  }

  /**
   * Disconnects the instance.
   */
  public function disconnect(array &$form, FormStateInterface $form_state): void {
    $this->handleResult($this->instanceManager->disconnectInstance($this->account), $this->t('The Evolution instance was disconnected.'));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * Displays an operation result and rebuilds the form.
   *
   * @param array<string, mixed> $result
   *   Operation result.
   */
  private function handleResult(array $result, string|\Stringable $success_message): void {
    if (($result['success'] ?? FALSE) === TRUE) {
      $this->messenger()->addStatus($success_message);
      return;
    }

    $this->messenger()->addError($this->t('Evolution API operation failed: @message', [
      '@message' => (string) ($result['error'] ?? $result['status'] ?? 'Unknown error'),
    ]));
  }

  /**
   * Builds QR preview markup.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  private function buildQrPreview(string $qr): array {
    if (str_starts_with($qr, 'data:image/')) {
      return [
        '#theme' => 'image',
        '#uri' => $qr,
        '#alt' => $this->t('Evolution API QR code'),
        '#attributes' => [
          'style' => 'max-width: 320px; height: auto;',
        ],
      ];
    }

    if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $qr) === 1 && strlen($qr) > 100) {
      return [
        '#theme' => 'image',
        '#uri' => 'data:image/png;base64,' . preg_replace('/\s+/', '', $qr),
        '#alt' => $this->t('Evolution API QR code'),
        '#attributes' => [
          'style' => 'max-width: 320px; height: auto;',
        ],
      ];
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'pre',
      '#value' => Html::escape($qr),
    ];
  }

  /**
   * Returns a field scalar value.
   */
  private function fieldValue(string $field_name): string {
    if (!$this->account->hasField($field_name) || $this->account->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $this->account->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Formats a timestamp field.
   */
  private function formatTimestamp(string $field_name): string|\Stringable {
    $value = $this->fieldValue($field_name);
    if ($value === '' || (int) $value <= 0) {
      return $this->t('Not available');
    }

    return $this->dateFormatter->format((int) $value, 'short');
  }

}
