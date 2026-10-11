<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Form;

use Drupal\ai_whatsapp_automation\Entity\WhatsAppAccount;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides the default form for AI WhatsApp Automation content entities.
 */
final class AutomationEntityForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $entity = $this->getEntity();
    $this->prefillFromQuery($entity);
    $form = parent::buildForm($form, $form_state);

    // Bots and knowledge bases always belong to a client. Accounts may leave
    // it empty: they inherit their bot's client.
    if (in_array($entity->getEntityTypeId(), ['ai_whatsapp_bot', 'ai_whatsapp_knowledge_base'], TRUE) && isset($form['client']['widget'])) {
      $form['client']['widget']['#required'] = TRUE;
    }
    if ($entity->getEntityTypeId() === 'ai_whatsapp_account') {
      $this->showProviderFields($form);
    }

    if ($entity->getEntityTypeId() === 'ai_whatsapp_bot') {
      $this->organizeBotForm($form, $entity->isNew());
    }

    if ($entity->getEntityTypeId() === 'ai_whatsapp_bot' && isset($form['web_widget_logo_url'])) {
      // Kept as a rendering fallback for existing bots, but new logos upload
      // through the file field instead of requiring a public URL.
      $form['web_widget_logo_url']['#access'] = FALSE;
    }

    if ($entity->getEntityTypeId() === 'ai_whatsapp_account' && isset($form['twilio_auth_token']['widget'][0]['value'])) {
      $form['twilio_auth_token']['widget'][0]['value']['#type'] = 'password';
      $form['twilio_auth_token']['widget'][0]['value']['#default_value'] = '';
      $form['twilio_auth_token']['widget'][0]['value']['#description'] = $entity->get('twilio_auth_token')->isEmpty()
        ? $this->t('Enter the Twilio Auth Token for this account.')
        : $this->t('A token is already configured. Leave empty to keep it.');
    }

    return $form;
  }

  /**
   * Prefills the parent record passed by the setup flow's "next step" links.
   *
   * ?client= on a new bot or knowledge base, ?bot= on a new account.
   */
  private function prefillFromQuery(ContentEntityInterface $entity): void {
    if (!$entity->isNew()) {
      return;
    }
    $query = $this->getRequest()->query;
    $parameters = [
      'ai_whatsapp_bot' => ['client' => 'ai_whatsapp_client'],
      'ai_whatsapp_knowledge_base' => ['client' => 'ai_whatsapp_client'],
      'ai_whatsapp_account' => ['bot' => 'ai_whatsapp_bot'],
    ];
    foreach ($parameters[$entity->getEntityTypeId()] ?? [] as $field_name => $target_type) {
      $id = (int) $query->get($field_name, 0);
      if ($id > 0 && $entity->get($field_name)->isEmpty() && $this->entityTypeManager->getStorage($target_type)->load($id) !== NULL) {
        $entity->set($field_name, $id);
      }
    }
  }

  /**
   * Shows only the fields that apply to the selected WhatsApp provider.
   *
   * Twilio credentials and templates for Twilio; instance and connection
   * status for Evolution (the connection status is set by its QR flow).
   */
  private function showProviderFields(array &$form): void {
    $provider_fields = [
      'twilio' => ['twilio_account_sid', 'twilio_auth_token', 'lead_notification_template_sid', 'lead_notification_template_variables'],
      'evolution' => ['evolution_instance_name', 'connection_status'],
    ];
    foreach ($provider_fields as $provider => $field_names) {
      foreach ($field_names as $field_name) {
        if (isset($form[$field_name])) {
          $form[$field_name]['#states'] = [
            'visible' => [':input[name="provider"]' => ['value' => $provider]],
          ];
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): ContentEntityInterface {
    $entity = parent::validateForm($form, $form_state);
    foreach (\Drupal::service('ai_whatsapp_automation.client_consistency')->conflicts($entity) as $field_name => $message) {
      $form_state->setErrorByName($field_name, $message);
    }
    if ($entity->getEntityTypeId() === 'ai_whatsapp_account') {
      $this->validateOwnerRouting($entity, $form_state);
    }
    if ($entity->getEntityTypeId() === 'ai_whatsapp_bot' && (bool) $entity->get('handoff_enabled')->value) {
      $handoff = \Drupal::service('ai_whatsapp_automation.lead_handoff');
      if (!$handoff->canEverCreateLead($entity)) {
        $form_state->setErrorByName('handoff_minimum_fields', $this->t('Con esta configuración este bot nunca crearía un prospecto: pide @minimum grupos de señales y solo tiene @groups. Cada línea de «Lead handoff required signals» es un grupo (las comas separan alternativas dentro de la misma línea). Baja el mínimo o escribe un grupo por línea.', [
          '@minimum' => $handoff->requiredSignalCount($entity),
          '@groups' => $handoff->signalGroupCount($entity),
        ]));
      }
    }
    if ($entity->getEntityTypeId() === 'ai_whatsapp_account' && WhatsAppAccount::answers($entity)) {
      // Two active accounts on one number make the routing pick one of them
      // without saying so; on 2026-10-10 a test account kept answering the
      // number meant for a new bot.
      $conflicts = \Drupal::service('ai_whatsapp_automation.bot_manager')->otherActiveAccountsWithNumber(
        (string) $entity->get('provider')->value,
        (string) $entity->get('phone_number')->value,
        $entity->id(),
      );
      if ($conflicts !== []) {
        $form_state->setErrorByName('phone_number', $this->t('La cuenta activa %account ya contesta este número. Desactívala primero, o deja esta cuenta inactiva: con dos cuentas activas en un número, los mensajes llegarían solo a una de ellas.', [
          '%account' => reset($conflicts)->label(),
        ]));
      }
    }

    return $entity;
  }

  /**
   * Refuses owner routing that could expose the owner bot or never work.
   */
  private function validateOwnerRouting(ContentEntityInterface $account, FormStateInterface $form_state): void {
    $bot_manager = \Drupal::service('ai_whatsapp_automation.bot_manager');
    $lines = array_filter(array_map('trim', preg_split('/\R+/', (string) $account->get('owner_numbers')->value) ?: []));
    $owner_bot = $account->get('owner_bot')->entity;

    foreach ($lines as $line) {
      if ($bot_manager->comparablePhone($line) === '') {
        $form_state->setErrorByName('owner_numbers', $this->t('%number no es un número completo. Escríbelo con lada de país, por ejemplo +5215530050231.', ['%number' => $line]));
      }
    }
    if ($lines !== [] && $owner_bot === NULL) {
      $form_state->setErrorByName('owner_bot', $this->t('Elige el bot del dueño, o borra los números: sin bot, los números no se usan.'));
    }
    if ($owner_bot === NULL) {
      return;
    }
    if ($lines === []) {
      $form_state->setErrorByName('owner_numbers', $this->t('Escribe al menos un número del dueño: sin números, el bot del dueño no contestaría a nadie.'));
    }
    if ((string) $owner_bot->id() === (string) $account->get('bot')->target_id) {
      $form_state->setErrorByName('owner_bot', $this->t('El bot del dueño no puede ser también el bot de la cuenta: cualquiera que escriba a este número hablaría con él.'));
    }
    // The account's knowledge base answers through the public bot: the owner
    // bot's private one must not be put there.
    $owner_knowledge_base = $owner_bot->hasField('knowledge_base') ? (string) $owner_bot->get('knowledge_base')->target_id : '';
    if ($owner_knowledge_base !== '' && $owner_knowledge_base === (string) $account->get('knowledge_base')->target_id) {
      $form_state->setErrorByName('knowledge_base', $this->t('Esta base de conocimiento es la del bot del dueño. Puesta en la cuenta, el bot público la usaría para contestar a cualquiera.'));
    }
    $account_client = (string) $account->get('client')->target_id;
    $owner_client = $owner_bot->hasField('client') ? (string) $owner_bot->get('client')->target_id : '';
    if ($account_client !== '' && $owner_client !== '' && $account_client !== $owner_client) {
      $form_state->setErrorByName('owner_bot', $this->t('El bot del dueño pertenece a otro cliente.'));
    }
  }

  /**
   * Organizes the bot form into focused, collapsible sections.
   */
  private function organizeBotForm(array &$form, bool $is_new): void {
    $form['#attributes']['class'][] = 'aiwa-bot-form';
    $form['#attached']['library'][] = 'ai_whatsapp_automation/bot_form';

    $sections = [
      'profile' => [
        'title' => $this->t('Bot profile'),
        'description' => $this->t('Identity, model, and knowledge source used for each conversation.'),
        'open' => TRUE,
        'fields' => ['client', 'name', 'description', 'status', 'model', 'reasoning_effort', 'temperature', 'knowledge_base', 'owner_tools_enabled'],
      ],
      'instructions' => [
        'title' => $this->t('AI instructions'),
        'description' => $this->t('Define how this assistant responds and what it should prioritize.'),
        'open' => TRUE,
        'fields' => ['system_prompt'],
      ],
      'handoff' => [
        'title' => $this->t('Lead handoff'),
        'description' => $this->t('Set when a qualified conversation becomes a lead and who receives the notification.'),
        'open' => FALSE,
        'fields' => [
          'handoff_enabled',
          'handoff_required_fields',
          'handoff_minimum_fields',
          'handoff_trigger_phrases',
          'handoff_prompt_rules',
          'lead_notification_template_sid',
          'lead_notification_template_variables',
          'lead_notification_account',
          'notification_recipient_reply_text',
        ],
      ],
      'web_widget' => [
        'title' => $this->t('Web widget'),
        'description' => $this->t('Customize the public chat experience embedded on your website.'),
        'open' => FALSE,
        'fields' => [
          'web_widget_enabled',
          'web_widget_logo_file',
          'web_widget_primary_color',
          'web_widget_secondary_color',
          'web_widget_assistant_name',
          'web_widget_welcome_message',
          'web_widget_position',
          'web_widget_icon',
          'web_widget_button_image',
          'web_widget_size',
          'web_widget_language',
          'web_widget_allowed_domains',
        ],
      ],
      'web_security' => [
        'title' => $this->t('Web widget advanced settings'),
        'description' => $this->t('Public identifier and optional API protection for external integrations.'),
        'open' => FALSE,
        'fields' => ['web_widget_token', 'web_widget_api_key'],
      ],
      'web_limits' => [
        'title' => $this->t('Web widget usage limits'),
        'description' => $this->t('Control public chat consumption for this bot. A value of 0 disables an individual limit.'),
        'open' => FALSE,
        'fields' => [
          'web_widget_message_limit',
          'web_widget_message_window_minutes',
          'web_widget_daily_conversation_limit',
          'web_widget_daily_budget',
        ],
      ],
      'whatsapp_limits' => [
        'title' => $this->t('WhatsApp usage limits'),
        'description' => $this->t('Control WhatsApp consumption for this bot. A value of 0 disables an individual limit.'),
        'open' => FALSE,
        'fields' => [
          'whatsapp_message_limit',
          'whatsapp_message_window_minutes',
          'whatsapp_daily_conversation_limit',
          'whatsapp_daily_budget',
        ],
      ],
    ];

    foreach ($sections as $key => $section) {
      $form[$key] = [
        '#type' => 'details',
        '#title' => $section['title'],
        '#description' => $section['description'],
        '#open' => $section['open'],
        '#attributes' => [
          'class' => ['aiwa-bot-form__section', 'aiwa-bot-form__section--' . str_replace('_', '-', $key)],
        ],
      ];
      foreach ($section['fields'] as $field_name) {
        if (isset($form[$field_name])) {
          $form[$key][$field_name] = $form[$field_name];
          unset($form[$field_name]);
        }
      }
    }

    $this->addWidgetPreview($form);
    $this->describeSignalGroups($form);

    if (!$is_new) {
      $entity = $this->getEntity();
      $form['web_integration_action'] = [
        '#type' => 'link',
        '#title' => $this->t('Open web integration'),
        '#url' => Url::fromRoute('ai_whatsapp_automation.bot_web_integration', [
          'ai_whatsapp_bot' => $entity->id(),
        ]),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'aiwa-web-integration-form-link'],
        ],
        '#weight' => -100,
      ];
    }
  }

  /**
   * Says, under the minimum, how many signal groups the bot really has.
   *
   * A list typed on one line is one group, which is how two bots ended up
   * unable to ever create a lead; showing the count makes it visible before
   * saving, not only as an error after.
   */
  private function describeSignalGroups(array &$form): void {
    $element = &$form['handoff']['handoff_minimum_fields']['widget'][0]['value'];
    if (!isset($element)) {
      return;
    }
    $groups = \Drupal::service('ai_whatsapp_automation.lead_handoff')->signalGroupCount($this->getEntity());
    $element['#description'] = $this->formatPlural($groups, 'Este bot tiene hoy 1 grupo de señales (cada línea de «Lead handoff required signals» es un grupo). El mínimo no puede ser mayor.', 'Este bot tiene hoy @count grupos de señales (cada línea de «Lead handoff required signals» es un grupo). El mínimo no puede ser mayor.');
  }

  /**
   * Adds the live preview of the chat button and header to the widget section.
   *
   * The preview is drawn by js/widget-launcher.js, the same painter the embed
   * script serves to clients' websites, so it cannot drift from what visitors
   * see. It is a mock-up rather than the real chat in an iframe: the chat page
   * only allows framing from each bot's own domains, and loosening that for a
   * preview would weaken it for everyone.
   */
  private function addWidgetPreview(array &$form): void {
    if (!isset($form['web_widget'])) {
      return;
    }

    $form['#attached']['library'][] = 'ai_whatsapp_automation/bot_widget_preview';
    $form['web_widget']['widget_preview'] = [
      '#type' => 'container',
      '#weight' => -50,
      '#attributes' => [
        'class' => ['aiwa-widget-preview'],
        'data-aiwa-widget-preview' => '',
      ],
      'heading' => [
        '#markup' => '<p class="aiwa-widget-preview__heading">' . $this->t('Preview') . '</p>',
      ],
    ];

    if (isset($form['web_widget']['web_widget_button_image'])) {
      $form['web_widget']['web_widget_button_image']['#states'] = [
        'visible' => [
          ':input[name="web_widget_icon"]' => ['value' => 'image'],
        ],
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $entity = $this->getEntity();
    if (
      $entity->getEntityTypeId() === 'ai_whatsapp_account'
      && !$entity->isNew()
      && $entity->hasField('twilio_auth_token')
      && trim((string) $entity->get('twilio_auth_token')->value) === ''
    ) {
      $original = \Drupal::entityTypeManager()
        ->getStorage('ai_whatsapp_account')
        ->loadUnchanged($entity->id());
      if ($original !== NULL && !$original->get('twilio_auth_token')->isEmpty()) {
        $entity->set('twilio_auth_token', $original->get('twilio_auth_token')->value);
      }
    }

    $is_new = $entity->isNew();
    $result = parent::save($form, $form_state);

    $this->messenger()->addStatus($this->t('Saved %label.', [
      '%label' => $entity->label(),
    ]));
    $this->addNextStepMessage($entity, $is_new);

    $form_state->setRedirectUrl($entity->toUrl('collection'));

    return $result;
  }

  /**
   * Guides the setup order: client, bot, WhatsApp number.
   *
   * Also warns when an account cannot answer: incoming messages are only
   * routed to accounts that are active or connected, so an inactive account
   * silently stores messages without replying.
   */
  private function addNextStepMessage(ContentEntityInterface $entity, bool $is_new): void {
    $type = $entity->getEntityTypeId();
    if ($is_new && $type === 'ai_whatsapp_client') {
      $this->messenger()->addStatus($this->t('Siguiente paso: <a href=":url">crea el bot de %client</a>.', [
        ':url' => Url::fromRoute('entity.ai_whatsapp_bot.add_form', [], ['query' => ['client' => $entity->id()]])->toString(),
        '%client' => $entity->label(),
      ]));
    }
    if ($is_new && $type === 'ai_whatsapp_bot') {
      $this->messenger()->addStatus($this->t('Siguiente paso: <a href=":account">conecta su número de WhatsApp</a>. Para el chat de su sitio web usa <a href=":web">Integración web</a>.', [
        ':account' => Url::fromRoute('entity.ai_whatsapp_account.add_form', [], ['query' => ['bot' => $entity->id()]])->toString(),
        ':web' => Url::fromRoute('ai_whatsapp_automation.bot_web_integration', ['ai_whatsapp_bot' => $entity->id()])->toString(),
      ]));
    }
    if ($type === 'ai_whatsapp_account' && !WhatsAppAccount::answers($entity)) {
      $this->messenger()->addWarning($this->t('La cuenta %account está inactiva: los mensajes que lleguen a su número no se responderán. Cambia su Estado a «Active» cuando tenga sus credenciales.', [
        '%account' => $entity->label(),
      ]));
    }
  }

}
