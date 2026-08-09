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

      $rows[] = [
        'account' => $account->toLink(),
        'provider' => $this->fieldValue($account, 'provider'),
        'number' => $this->fieldValue($account, 'phone_number'),
        'status' => $this->fieldValue($account, 'status'),
        'connection' => $this->fieldValue($account, 'connection_status'),
        'bot' => $bot instanceof ContentEntityInterface ? $bot->toLink() : $this->t('No active bot'),
        'model' => $bot instanceof ContentEntityInterface ? ($this->botManager->getEffectiveModel($bot, $account) ?: $this->t('Default')) : '',
        'knowledge_base' => $knowledge_base instanceof ContentEntityInterface ? $knowledge_base->toLink() : $this->t('None'),
        'prompt' => $this->fieldValue($account, 'prompt_override') !== '' ? $this->t('Account override') : $this->t('Bot prompt'),
        'operations' => Link::fromTextAndUrl($this->t('Edit account'), Url::fromRoute('entity.ai_whatsapp_account.edit_form', [
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
      '#value' => $this->t('Set up a WhatsApp bot'),
    ];
    $build['setup_guide']['heading']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Create the bot first, then connect its WhatsApp number and assign it to that bot.'),
    ];
    $build['setup_guide']['steps'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-guide__steps']],
    ];
    $build['setup_guide']['steps']['bot'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-step']],
    ];
    $build['setup_guide']['steps']['bot']['number'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => '1',
      '#attributes' => ['class' => ['ai-whatsapp-routing-step__number']],
    ];
    $build['setup_guide']['steps']['bot']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-step__content']],
    ];
    $build['setup_guide']['steps']['bot']['content']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Create and configure the bot'),
    ];
    $build['setup_guide']['steps']['bot']['content']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Define its instructions, model, knowledge base, limits, and lead notifications.'),
    ];
    $build['setup_guide']['steps']['bot']['content']['action'] = [
      '#type' => 'link',
      '#title' => $this->t('Create bot'),
      '#url' => Url::fromRoute('entity.ai_whatsapp_bot.add_form'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    $build['setup_guide']['steps']['account'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-step']],
    ];
    $build['setup_guide']['steps']['account']['number'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => '2',
      '#attributes' => ['class' => ['ai-whatsapp-routing-step__number']],
    ];
    $build['setup_guide']['steps']['account']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-step__content']],
    ];
    $build['setup_guide']['steps']['account']['content']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Connect the WhatsApp number'),
    ];
    $build['setup_guide']['steps']['account']['content']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Add the provider credentials and number, then select the bot created in step 1.'),
    ];
    $build['setup_guide']['steps']['account']['content']['action'] = [
      '#type' => 'link',
      '#title' => $this->t('Connect WhatsApp number'),
      '#url' => Url::fromRoute('entity.ai_whatsapp_account.add_form'),
      '#attributes' => ['class' => ['button']],
    ];

    $build['assignments_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Current assignments'),
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
        $this->t('Account'),
        $this->t('Provider'),
        $this->t('Number'),
        $this->t('Status'),
        $this->t('Connection'),
        $this->t('Bot'),
        $this->t('Model'),
        $this->t('Knowledge base'),
        $this->t('Prompt'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No WhatsApp accounts were found. Start with step 1 to create a bot.'),
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

}
