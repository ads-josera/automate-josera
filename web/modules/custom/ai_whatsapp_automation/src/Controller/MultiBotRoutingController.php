<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\ai_whatsapp_automation\Application\AI\BotManagerService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays WhatsApp account to bot routing.
 */
final class MultiBotRoutingController extends ControllerBase {

  /**
   * Constructs a MultiBotRoutingController object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $automationEntityTypeManager,
    private readonly BotManagerService $botManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ai_whatsapp_automation.bot_manager'),
    );
  }

  /**
   * Builds the routing overview.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function overview(): array {
    $build['#attached']['library'][] = 'ai_whatsapp_automation/multibot_routing';

    $storage = $this->automationEntityTypeManager->getStorage('ai_whatsapp_account');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('provider')
      ->sort('name')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $account) {
      if (!$account instanceof ContentEntityInterface) {
        continue;
      }

      $bot = $this->botManager->getBotForAccount($account);
      $knowledge_base = $bot instanceof ContentEntityInterface
        ? $this->botManager->getEffectiveKnowledgeBase($bot, $account)
        : NULL;

      $client = $account->get('client')->entity ?? ($bot instanceof ContentEntityInterface ? $bot->get('client')->entity : NULL);
      // Incoming messages are only routed to active or connected accounts.
      $answers = $this->fieldValue($account, 'status') === 'active' || $this->fieldValue($account, 'connection_status') === 'CONNECTED';
      $rows[] = [
        'client' => $client instanceof ContentEntityInterface ? $client->label() : $this->t('Sin cliente'),
        'account' => $account->toLink(),
        'provider' => $this->providerLabel($this->fieldValue($account, 'provider')),
        'number' => $this->fieldValue($account, 'phone_number'),
        'status' => $answers
          ? $this->statusLabel($this->fieldValue($account, 'status'))
          : ['data' => ['#markup' => '<strong class="ai-whatsapp-routing-table__warning">' . $this->t('Inactiva: no responde') . '</strong>']],
        // Connection status is tracked only for Evolution instances; Twilio and
        // Cloud API accounts keep a stale default that reads as a real state.
        'connection' => $this->fieldValue($account, 'provider') === 'evolution'
          ? $this->connectionLabel($this->fieldValue($account, 'connection_status'))
          : $this->t('No aplica'),
        'bot' => $this->botCell($bot, $this->fieldValue($account, 'prompt_override') !== ''),
        'model' => $bot instanceof ContentEntityInterface ? ($this->botManager->getEffectiveModel($bot, $account) ?: $this->t('Predeterminado')) : '',
        'knowledge_base' => $knowledge_base instanceof ContentEntityInterface ? $knowledge_base->toLink() : $this->t('Ninguna'),
        'operations' => Link::fromTextAndUrl($this->t('Editar cuenta'), Url::fromRoute('entity.ai_whatsapp_account.edit_form', [
          'ai_whatsapp_account' => $account->id(),
        ])),
      ];
    }

    $build['setup_guide'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-whatsapp-routing-guide'],
      ],
    ];
    $build['setup_guide']['heading'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-guide__heading']],
    ];
    $build['setup_guide']['heading']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Configura un cliente nuevo'),
    ];
    $build['setup_guide']['heading']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Sigue este orden. Cada paso te lleva al siguiente con el dato ya elegido.'),
    ];
    $build['setup_guide']['steps'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-guide__steps']],
    ];
    $steps = [
      'client' => [
        'title' => $this->t('Crea el cliente'),
        'description' => $this->t('La empresa a la que das el servicio. Todo lo demás le pertenece.'),
        'label' => $this->t('Agregar cliente'),
        'route' => 'entity.ai_whatsapp_client.add_form',
      ],
      'bot' => [
        'title' => $this->t('Crea y configura su bot'),
        'description' => $this->t('Elige el cliente y define instrucciones, modelo, base de conocimiento, límites y notificaciones de leads.'),
        'label' => $this->t('Agregar bot'),
        'route' => 'entity.ai_whatsapp_bot.add_form',
      ],
      'account' => [
        'title' => $this->t('Conecta su número de WhatsApp'),
        'description' => $this->t('Credenciales del proveedor y número; elige el bot del paso 2. Déjala activa para que responda.'),
        'label' => $this->t('Conectar número de WhatsApp'),
        'route' => 'entity.ai_whatsapp_account.add_form',
      ],
    ];
    $number = 0;
    foreach ($steps as $key => $step) {
      $number++;
      $build['setup_guide']['steps'][$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-routing-step']],
        'number' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => (string) $number,
          '#attributes' => ['class' => ['ai-whatsapp-routing-step__number']],
        ],
        'content' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-whatsapp-routing-step__content']],
          'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $step['title']],
          'description' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $step['description']],
          'action' => [
            '#type' => 'link',
            '#title' => $step['label'],
            '#url' => Url::fromRoute($step['route']),
            // Only the first step is the primary action of the page.
            '#attributes' => ['class' => $number === 1 ? ['button', 'button--primary'] : ['button']],
          ],
        ],
      ];
    }

    $build['assignments_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Asignaciones actuales'),
      '#attributes' => ['class' => ['ai-whatsapp-routing-assignments-title']],
    ];
    $build['routing_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-table-wrapper']],
    ];
    $build['routing_wrapper']['routing'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['ai-whatsapp-routing-table']],
      '#header' => [
        $this->t('Cliente'),
        $this->t('Cuenta'),
        $this->t('Proveedor'),
        $this->t('Número'),
        $this->t('Estado'),
        $this->t('Conexión'),
        $this->t('Bot'),
        $this->t('Modelo'),
        $this->t('Base de conocimiento'),
        $this->t('Acciones'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Todavía no hay números de WhatsApp. Empieza por el paso 1: crea el cliente.'),
    ];

    return $build;
  }

  /**
   * Returns a scalar field value.
   */
  private function fieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Builds the bot cell, noting when the account overrides its instructions.
   */
  private function botCell(?ContentEntityInterface $bot, bool $overrides_prompt): Link|array|string|\Stringable {
    if (!$bot instanceof ContentEntityInterface) {
      return $this->t('Sin bot activo');
    }
    if (!$overrides_prompt) {
      return $bot->toLink();
    }

    return [
      'data' => [
        'bot' => $bot->toLink()->toRenderable(),
        'note' => ['#markup' => '<div class="ai-whatsapp-routing-table__note">' . $this->t('Instrucciones propias de esta cuenta') . '</div>'],
      ],
    ];
  }

  /**
   * Returns a readable provider name.
   */
  private function providerLabel(string $provider): string|\Stringable {
    return match ($provider) {
      'twilio' => 'Twilio',
      'cloud_api' => 'Cloud API',
      'evolution' => 'Evolution',
      '' => $this->t('Sin proveedor'),
      default => $provider,
    };
  }

  /**
   * Returns a readable account status.
   */
  private function statusLabel(string $status): string|\Stringable {
    return match ($status) {
      'active' => $this->t('Activa'),
      'inactive' => $this->t('Inactiva'),
      'paused' => $this->t('En pausa'),
      default => $status,
    };
  }

  /**
   * Returns a readable Evolution connection state.
   */
  private function connectionLabel(string $connection): string|\Stringable {
    return match ($connection) {
      'CONNECTED' => $this->t('Conectada'),
      'DISCONNECTED' => $this->t('Desconectada'),
      'CONNECTING' => $this->t('Conectando'),
      '' => $this->t('Sin datos'),
      default => $connection,
    };
  }

}
