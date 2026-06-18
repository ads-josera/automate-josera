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

    $build['actions'] = [
      '#type' => 'actions',
    ];
    $build['actions']['add_account'] = [
      '#type' => 'link',
      '#title' => $this->t('Add WhatsApp account'),
      '#url' => Url::fromRoute('entity.ai_whatsapp_account.add_form'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    $build['actions']['add_bot'] = [
      '#type' => 'link',
      '#title' => $this->t('Add bot'),
      '#url' => Url::fromRoute('entity.ai_whatsapp_bot.add_form'),
      '#attributes' => ['class' => ['button']],
    ];

    $build['routing'] = [
      '#type' => 'table',
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
      '#empty' => $this->t('No WhatsApp accounts were found.'),
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
