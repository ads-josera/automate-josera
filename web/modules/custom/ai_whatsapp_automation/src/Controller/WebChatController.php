<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\ai_whatsapp_automation\Application\WebChat\WebChatService;
use Drupal\ai_whatsapp_automation\Exception\WebChatLimitException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public web chat widget controller.
 */
final class WebChatController extends ControllerBase {

  /**
   * Constructs a WebChatController object.
   */
  public function __construct(
    private readonly WebChatService $webChat,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_whatsapp_automation.web_chat'),
    );
  }

  /**
   * Displays the embeddable chat page.
   */
  public function page(Request $request, string $token): Response {
    $bot = $this->webChat->loadBot($token);
    if (!$bot instanceof ContentEntityInterface || !$this->webChat->isRequestAllowed($bot, $request)) {
      return new Response('Chat not available.', Response::HTTP_FORBIDDEN);
    }

    $config = $this->webChat->widgetConfig($bot);
    $labels = $this->chatLabels($config['language']);
    $api_url = Url::fromRoute('ai_whatsapp_automation.web_chat_api', ['token' => $this->publicToken($bot)], ['absolute' => TRUE])->toString();

    $settings = json_encode([
      'apiUrl' => $api_url,
      'apiKey' => $this->getFieldValue($bot, 'web_widget_api_key'),
      'token' => $this->publicToken($bot),
      'language' => $config['language'],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $css_url = $this->assetUrl($request, 'css/web-chat.css');
    $js_url = $this->assetUrl($request, 'js/web-chat.js');
    $logo = $config['logoUrl'] !== ''
      ? '<img class="aiwa-chat__logo" src="' . $this->escape($config['logoUrl']) . '" alt="">'
      : '<span class="aiwa-chat__logo-fallback">AI</span>';
    $html = '<!doctype html><html lang="' . $this->escape($config['language']) . '"><head>'
      . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
      . '<link rel="stylesheet" href="' . $this->escape($css_url) . '">'
      . '</head><body><div class="aiwa-chat-shell"><div class="aiwa-chat" style="--aiwa-primary:' . $this->safeColor($config['primaryColor']) . ';--aiwa-secondary:' . $this->safeColor($config['secondaryColor']) . ';">'
      . '<div class="aiwa-chat__header">' . $logo . '<div class="aiwa-chat__title"><strong>' . $this->escape($config['name']) . '</strong><span class="aiwa-chat__presence">' . $this->escape($labels['status']) . '</span></div><button type="button" class="aiwa-chat__minimize" data-aiwa-minimize aria-label="' . $this->escape($labels['minimize']) . '"><span aria-hidden="true">&#8722;</span></button></div>'
      . '<div class="aiwa-chat__messages" data-aiwa-messages><div class="aiwa-message aiwa-message--ai">' . $this->escape($config['welcomeMessage']) . '</div></div>'
      . '<form class="aiwa-chat__form" data-aiwa-form><textarea data-aiwa-input rows="1" maxlength="1400" placeholder="' . $this->escape($labels['placeholder']) . '"></textarea><button type="submit" aria-label="' . $this->escape($labels['send']) . '"><span aria-hidden="true">&#8593;</span></button></form>'
      . '</div></div><script>window.drupalSettings=window.drupalSettings||{};window.drupalSettings.aiWhatsappAutomationWebChat=' . $settings . ';</script>'
      . '<script src="' . $this->escape($js_url) . '"></script></body></html>';

    return new Response($html, Response::HTTP_OK, [
      'Content-Type' => 'text/html; charset=UTF-8',
      'Cache-Control' => 'no-store, private',
    ]);
  }

  /**
   * Returns JavaScript that embeds a floating chat widget.
   */
  public function embed(Request $request, string $token): Response {
    $bot = $this->webChat->loadBot($token);
    if (!$bot instanceof ContentEntityInterface || !$this->webChat->isRequestAllowed($bot, $request)) {
      return new Response('// AI WhatsApp widget not available.', Response::HTTP_FORBIDDEN, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
      ]);
    }

    $config = $this->webChat->widgetConfig($bot);
    $chat_url = Url::fromRoute('ai_whatsapp_automation.web_chat_page', ['token' => $this->publicToken($bot)], [
      'absolute' => TRUE,
      'query' => $this->apiKeyQuery($bot),
    ])->toString();
    $payload = [
      'chatUrl' => $chat_url,
      'name' => $config['name'],
      'position' => $config['position'],
      'icon' => $config['icon'],
      'size' => $config['size'],
      'primaryColor' => $config['primaryColor'],
      'secondaryColor' => $config['secondaryColor'],
    ];

    $js = 'window.AIWhatsAppAutomationWidget=' . json_encode($payload, JSON_THROW_ON_ERROR) . ';' . "\n" . $this->embedScript();
    $response = new Response($js, Response::HTTP_OK, [
      'Content-Type' => 'application/javascript; charset=UTF-8',
      'Access-Control-Allow-Origin' => $this->webChat->corsOrigin($bot, $request),
    ]);

    return $response;
  }

  /**
   * Processes a chat message.
   */
  public function message(Request $request, string $token): JsonResponse {
    $bot = $this->webChat->loadBot($token);
    if (!$bot instanceof ContentEntityInterface) {
      return new JsonResponse(['error' => 'Chat not available.'], Response::HTTP_NOT_FOUND);
    }

    if ($request->getMethod() === 'OPTIONS') {
      return $this->jsonWithCors([], $bot, $request);
    }

    if (!$this->webChat->isRequestAllowed($bot, $request)) {
      return $this->jsonWithCors(['error' => 'Forbidden.'], $bot, $request, Response::HTTP_FORBIDDEN);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return $this->jsonWithCors(['error' => 'Invalid JSON payload.'], $bot, $request, Response::HTTP_BAD_REQUEST);
    }

    $message = trim((string) ($payload['message'] ?? ''));
    if ($message === '') {
      return $this->jsonWithCors(['error' => 'Message is required.'], $bot, $request, Response::HTTP_BAD_REQUEST);
    }

    try {
      $response = $this->webChat->processMessage($bot, (string) ($payload['session_id'] ?? ''), $message);
    }
    catch (WebChatLimitException $exception) {
      return $this->jsonWithCors(['error' => $exception->getMessage()], $bot, $request, Response::HTTP_TOO_MANY_REQUESTS);
    }
    catch (\Throwable $exception) {
      $this->getLogger('ai_whatsapp_automation')->error('Web chat request failed: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return $this->jsonWithCors(['error' => 'The assistant could not respond right now.'], $bot, $request, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return $this->jsonWithCors($response, $bot, $request);
  }

  /**
   * Returns a JSON response with CORS headers.
   *
   * @param array<string, mixed> $data
   *   Response data.
   */
  private function jsonWithCors(array $data, ContentEntityInterface $bot, Request $request, int $status = Response::HTTP_OK): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Access-Control-Allow-Origin', $this->webChat->corsOrigin($bot, $request));
    $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-AI-WhatsApp-Key');

    return $response;
  }

  /**
   * Returns the public token for a bot.
   */
  private function publicToken(ContentEntityInterface $bot): string {
    return $this->getFieldValue($bot, 'web_widget_token') ?: (string) $bot->uuid();
  }

  /**
   * Returns optional API key query parameters.
   *
   * @return array<string, string>
   *   Query parameters.
   */
  private function apiKeyQuery(ContentEntityInterface $bot): array {
    $api_key = $this->getFieldValue($bot, 'web_widget_api_key');

    return $api_key !== '' ? ['key' => $api_key] : [];
  }

  /**
   * Sanitizes color values for CSS variables.
   */
  private function safeColor(string $color): string {
    return preg_match('/^#[0-9A-Fa-f]{3,8}$/', $color) ? $color : '#155EEF';
  }

  /**
   * Returns browser-facing labels for the configured widget language.
   *
   * @return array{status: string, placeholder: string, send: string, minimize: string}
   *   Widget labels.
   */
  private function chatLabels(string $language): array {
    return match (strtolower($language)) {
      'es', 'es-mx' => [
        'status' => 'Asistente en línea',
        'placeholder' => 'Escribe tu mensaje...',
        'send' => 'Enviar',
        'minimize' => 'Minimizar chat',
      ],
      default => [
        'status' => 'Online assistant',
        'placeholder' => 'Write your message...',
        'send' => 'Send',
        'minimize' => 'Minimize chat',
      ],
    };
  }

  /**
   * Returns a versioned public URL to avoid stale standalone-widget assets.
   */
  private function assetUrl(Request $request, string $asset): string {
    $relative_path = 'modules/custom/ai_whatsapp_automation/' . ltrim($asset, '/');
    $file_path = DRUPAL_ROOT . '/' . $relative_path;
    $version = is_file($file_path) ? (string) filemtime($file_path) : '1';

    return rtrim($request->getSchemeAndHttpHost(), '/') . base_path() . $relative_path . '?v=' . $version;
  }

  /**
   * Escapes a value for the standalone public chat HTML document.
   */
  private function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Reads a scalar field value from an entity.
   */
  private function getFieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * Floating widget JavaScript.
   */
  private function embedScript(): string {
    return <<<'JS'
(function () {
  function mount() {
    var config = window.AIWhatsAppAutomationWidget || {};
    if (!config.chatUrl || document.querySelector('[data-aiwa-widget-root]')) {
      return;
    }

  var root = document.createElement('div');
  root.setAttribute('data-aiwa-widget-root', 'true');
  root.style.position = 'fixed';
  root.style.zIndex = '2147483000';
  root.style.bottom = '22px';
  root.style[config.position === 'left' ? 'left' : 'right'] = '22px';
  root.style.fontFamily = 'Arial, sans-serif';

  var frame = document.createElement('iframe');
  frame.title = config.name || 'AI chat';
  frame.src = config.chatUrl;
  frame.style.position = 'fixed';
  frame.style.bottom = '88px';
  frame.style[config.position === 'left' ? 'left' : 'right'] = '22px';
  frame.style.width = config.size === 'large' ? '420px' : (config.size === 'small' ? '330px' : '380px');
  frame.style.height = config.size === 'large' ? '660px' : (config.size === 'small' ? '500px' : '580px');
  frame.style.maxWidth = 'calc(100vw - 32px)';
  frame.style.maxHeight = 'calc(100vh - 112px)';
  frame.style.border = '0';
  frame.style.borderRadius = '16px';
  frame.style.boxShadow = '0 20px 55px rgba(15, 23, 42, .25)';
  frame.style.display = 'none';
  frame.style.background = '#fff';

  var button = document.createElement('button');
  button.type = 'button';
  button.setAttribute('aria-label', config.name || 'Open chat');
  button.innerHTML = '<span aria-hidden="true" style="display:flex;align-items:center;justify-content:center;width:25px;height:19px;border:2px solid currentColor;border-radius:8px;position:relative;box-sizing:border-box"><i style="position:absolute;bottom:-5px;left:5px;width:7px;height:7px;border-left:2px solid currentColor;border-bottom:2px solid currentColor;transform:skewY(-34deg);background:inherit"></i><b style="display:block;width:3px;height:3px;border-radius:50%;background:currentColor;box-shadow:6px 0 0 currentColor,-6px 0 0 currentColor"></b></span>';
  button.style.width = '62px';
  button.style.height = '62px';
  button.style.borderRadius = '999px';
  button.style.border = '0';
  button.style.cursor = 'pointer';
  button.style.background = config.primaryColor || '#155EEF';
  button.style.color = '#fff';
  button.style.fontWeight = '700';
  button.style.boxShadow = '0 12px 30px rgba(15, 23, 42, .24)';

  button.addEventListener('click', function () {
    frame.style.display = frame.style.display === 'none' ? 'block' : 'none';
  });

  window.addEventListener('message', function (event) {
    if (event.source === frame.contentWindow && event.data && event.data.type === 'aiwa:minimize-chat') {
      frame.style.display = 'none';
    }
  });

  root.appendChild(frame);
  root.appendChild(button);
    document.body.appendChild(root);
  }

  if (document.body) {
    mount();
  }
  else {
    document.addEventListener('DOMContentLoaded', mount, { once: true });
  }
}());
JS;
  }

}
