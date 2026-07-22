<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;

/**
 * Displays web integration snippets for a bot.
 */
final class WebIntegrationController extends ControllerBase {

  /**
   * Builds the integration overview.
   */
  public function overview(ContentEntityInterface $ai_whatsapp_bot): array {
    $token = $this->publicToken($ai_whatsapp_bot);
    $query = $this->apiKeyQuery($ai_whatsapp_bot);
    $chat_url = Url::fromRoute('ai_whatsapp_automation.web_chat_page', ['token' => $token], ['absolute' => TRUE, 'query' => $query])->toString();
    $js_url = Url::fromRoute('ai_whatsapp_automation.web_chat_embed', ['token' => $token], ['absolute' => TRUE, 'query' => $query])->toString();
    $iframe = '<iframe src="' . $chat_url . '" width="380" height="580" style="border:0;border-radius:16px;max-width:100%;" loading="lazy"></iframe>';
    $javascript = '<script async src="' . $js_url . '"></script>';
    $shortcode = '[ai_whatsapp_bot url="' . $js_url . '"]';
    $enabled = $ai_whatsapp_bot->hasField('web_widget_enabled') && (bool) $ai_whatsapp_bot->get('web_widget_enabled')->value;

    $build = [
      '#attached' => [
        'library' => ['ai_whatsapp_automation/integration_admin'],
      ],
      'hero' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aiwa-integration-hero']],
        'title' => [
          '#markup' => '<h2>' . $this->t('Web integration for @bot', ['@bot' => $ai_whatsapp_bot->label()]) . '</h2>',
        ],
        'summary' => [
          '#markup' => '<p>' . $this->t('Use these snippets to embed this assistant in WordPress, Drupal, Joomla, HTML, React, Vue, Angular, or any site that accepts iframe or JavaScript embeds.') . '</p>',
        ],
      ],
    ];

    if (!$enabled) {
      $build['disabled'] = [
        '#markup' => '<div class="aiwa-integration-warning">' . $this->t('This bot web widget is currently disabled. Edit the bot and enable "Enable web widget" before using these snippets.') . '</div>',
      ];
    }

    $build['cards'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['aiwa-integration-grid']],
      'url' => $this->snippetCard($this->t('Public chat URL'), $chat_url),
      'iframe' => $this->snippetCard($this->t('Iframe'), $iframe),
      'javascript' => $this->snippetCard($this->t('JavaScript'), $javascript),
      'shortcode' => $this->snippetCard($this->t('WordPress shortcode'), $shortcode),
    ];

    return $build;
  }

  /**
   * Builds a copyable snippet card.
   */
  private function snippetCard(string|\Stringable $title, string $code): array {
    $id = 'aiwa-snippet-' . substr(hash('sha256', $code), 0, 12);

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['aiwa-integration-card']],
      'title' => [
        '#markup' => '<h3>' . htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') . '</h3>',
      ],
      'code' => [
        '#markup' => '<textarea id="' . $id . '" readonly rows="4">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</textarea>',
      ],
      'copy' => [
        '#markup' => '<button type="button" class="button button--primary aiwa-copy-button" data-aiwa-copy-target="' . $id . '">' . $this->t('Copy') . '</button>',
      ],
    ];
  }

  /**
   * Returns the public token for a bot.
   */
  private function publicToken(ContentEntityInterface $bot): string {
    if ($bot->hasField('web_widget_token') && !$bot->get('web_widget_token')->isEmpty()) {
      return (string) $bot->get('web_widget_token')->value;
    }

    return (string) $bot->uuid();
  }

  /**
   * Returns optional API key query parameters for generated snippets.
   *
   * @return array<string, string>
   *   Query parameters.
   */
  private function apiKeyQuery(ContentEntityInterface $bot): array {
    if ($bot->hasField('web_widget_api_key') && !$bot->get('web_widget_api_key')->isEmpty()) {
      return ['key' => (string) $bot->get('web_widget_api_key')->value];
    }

    return [];
  }

}
