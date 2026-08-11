<?php

declare(strict_types=1);

namespace Drupal\ai_adminops\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Url;
use Drupal\ai_adminops\Entity\AdminOpsServer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the safe AdminOps server registration form.
 */
final class AdminOpsServerForm extends FormBase {

  /**
   * Creates an AdminOpsServerForm instance.
   */
  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_adminops_server_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?AdminOpsServer $ai_adminops_server = NULL): array {
    $server = $ai_adminops_server ?? $this->entityTypeManager->getStorage('ai_adminops_server')->create();
    $is_new = $server->isNew();
    $form['#attached']['library'][] = 'ai_adminops/admin';
    $form['#attributes']['class'][] = 'ai-adminops-server-form';

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Server profile'),
      '#open' => TRUE,
      '#description' => $this->t('Register the infrastructure target and its operational context. This form never stores passwords, tokens, or private keys.'),
    ];
    $form['identity']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server name'),
      '#required' => TRUE,
      '#default_value' => $server->label(),
      '#maxlength' => 128,
    ];
    $form['identity']['id'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#default_value' => $server->id(),
      '#machine_name' => [
        'exists' => '\\Drupal\\ai_adminops\\Entity\\AdminOpsServer::load',
      ],
      '#disabled' => !$is_new,
      '#required' => TRUE,
    ];
    $form['identity']['hostname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hostname or IP address'),
      '#required' => TRUE,
      '#default_value' => (string) $server->get('hostname'),
      '#maxlength' => 255,
      '#description' => $this->t('For example server.example.com or 203.0.113.20.'),
    ];
    $form['identity']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => (string) $server->get('description'),
      '#rows' => 3,
      '#description' => $this->t('Optional operational context such as the hosted application or environment.'),
    ];
    $form['identity']['tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tags'),
      '#default_value' => implode(', ', (array) $server->get('tags')),
      '#description' => $this->t('Optional comma-separated labels, for example production, cpanel, mexico.'),
    ];

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection profile'),
      '#open' => TRUE,
      '#description' => $this->t('Connection profiles are declarative only in this version. No remote request is made when you save this form.'),
    ];
    $form['connection']['connection_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Connection type'),
      '#options' => [
        'ssh' => $this->t('SSH'),
        'whm_api' => $this->t('WHM API'),
        'cpanel_api' => $this->t('cPanel API'),
        'manual' => $this->t('Manual / no connector'),
      ],
      '#default_value' => (string) $server->get('connection_type') ?: 'ssh',
    ];
    $form['connection']['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Port'),
      '#min' => 1,
      '#max' => 65535,
      '#default_value' => (int) $server->get('port') ?: 22,
    ];
    $form['connection']['credential_reference'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Credential reference'),
      '#default_value' => (string) $server->get('credential_reference'),
      '#maxlength' => 255,
      '#description' => $this->t('Reference only, such as a Key module ID or an external vault path. Do not enter a password, API token, or private key.'),
    ];

    $form['operations'] = [
      '#type' => 'details',
      '#title' => $this->t('Operations'),
      '#open' => TRUE,
    ];
    $form['operations']['operating_system'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Operating system'),
      '#default_value' => (string) $server->get('operating_system'),
      '#maxlength' => 128,
      '#description' => $this->t('For example AlmaLinux 9 or Ubuntu 24.04.'),
    ];
    $form['operations']['provider'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provider'),
      '#default_value' => (string) $server->get('provider'),
      '#maxlength' => 128,
      '#description' => $this->t('For example WHM, AWS, Hetzner, or local hosting.'),
    ];
    $form['operations']['server_status'] = [
      '#type' => 'select',
      '#title' => $this->t('Current status'),
      '#options' => [
        'unknown' => $this->t('Unknown'),
        'healthy' => $this->t('Healthy'),
        'degraded' => $this->t('Degraded'),
        'offline' => $this->t('Offline'),
      ],
      '#default_value' => (string) $server->get('server_status') ?: 'unknown',
    ];
    $form['operations']['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable monitoring for this server'),
      '#default_value' => (bool) $server->get('active'),
      '#description' => $this->t('The scheduler can create read-only work for active servers once a connector is implemented.'),
    ];

    $form['server_id'] = [
      '#type' => 'value',
      '#value' => $server->id(),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $is_new ? $this->t('Add server') : $this->t('Save server'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('ai_adminops.servers'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $hostname = trim((string) $form_state->getValue(['identity', 'hostname']));
    if (!$this->isValidHostnameOrIp($hostname)) {
      $form_state->setErrorByName('identity][hostname', $this->t('Enter a valid hostname or IP address without a protocol, port, path, or spaces.'));
    }
    $reference = (string) $form_state->getValue(['connection', 'credential_reference']);
    if (preg_match('/\s/', $reference) === 1 || preg_match('/(?:-----BEGIN|sk-|token|password|secret)/i', $reference) === 1) {
      $form_state->setErrorByName('credential_reference', $this->t('Enter only a credential reference. Do not store a secret value in this field.'));
    }
  }

  /**
   * Validates an IP address or a DNS hostname without resolving it.
   */
  private function isValidHostnameOrIp(string $value): bool {
    if ($value === '' || strlen($value) > 253 || preg_match('/\s/', $value) === 1) {
      return FALSE;
    }

    if (filter_var($value, FILTER_VALIDATE_IP) !== FALSE) {
      return TRUE;
    }

    // DNS labels may begin with a digit, as in provider-generated hostnames.
    $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
    return preg_match('/^(?:' . $label . ')(?:\\.(?:' . $label . '))*$/', $value) === 1;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $server_id = (string) $form_state->getValue('server_id');
    /** @var \Drupal\ai_adminops\Entity\AdminOpsServer $server */
    $server = $server_id === ''
      ? $this->entityTypeManager->getStorage('ai_adminops_server')->create()
      : $this->entityTypeManager->getStorage('ai_adminops_server')->load($server_id);
    if (!$server instanceof AdminOpsServer) {
      throw new \RuntimeException('The requested AdminOps server could not be loaded.');
    }
    $server->set('label', trim((string) $form_state->getValue(['identity', 'label'])));
    if ($server->isNew()) {
      $server->set('id', (string) $form_state->getValue(['identity', 'id']));
    }
    $server->set('hostname', trim((string) $form_state->getValue(['identity', 'hostname'])));
    $server->set('port', (int) $form_state->getValue(['connection', 'port']));
    $server->set('operating_system', trim((string) $form_state->getValue(['operations', 'operating_system'])));
    $server->set('provider', trim((string) $form_state->getValue(['operations', 'provider'])));
    $server->set('connection_type', (string) $form_state->getValue(['connection', 'connection_type']));
    $server->set('credential_reference', trim((string) $form_state->getValue(['connection', 'credential_reference'])));
    $server->set('server_status', (string) $form_state->getValue(['operations', 'server_status']));
    $server->set('description', trim((string) $form_state->getValue(['identity', 'description'])));
    $server->set('active', (bool) $form_state->getValue(['operations', 'active']));
    $server->set('tags', $this->tags((string) $form_state->getValue(['identity', 'tags'])));
    $status = $server->save();

    $this->messenger()->addStatus($status === \SAVED_NEW
      ? $this->t('Server %label has been added.', ['%label' => $server->label()])
      : $this->t('Server %label has been updated.', ['%label' => $server->label()]));
    $form_state->setRedirectUrl(Url::fromRoute('ai_adminops.servers'));
  }

  /**
   * Converts comma-separated tags into normalized unique values.
   *
   * @return string[]
   *   The tags to store.
   */
  private function tags(string $value): array {
    $tags = array_map('trim', explode(',', $value));
    $tags = array_filter($tags, static fn(string $tag): bool => $tag !== '');
    return array_values(array_unique($tags));
  }

}
