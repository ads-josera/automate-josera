/**
 * @file
 * Mounts the floating chat button and its iframe on the client's website.
 *
 * Served by WebChatController::embed() after widget-launcher.js and the
 * window.AIWhatsAppAutomationWidget configuration, in one response, so the
 * snippet a client pasted keeps working unchanged. Plain ES5, no Drupal.
 */
(function () {
  'use strict';

  function mount() {
    var config = window.AIWhatsAppAutomationWidget || {};
    if (!config.chatUrl || document.querySelector('[data-aiwa-widget-root]')) {
      return;
    }
    var side = config.position === 'left' ? 'left' : 'right';

    var root = document.createElement('div');
    root.setAttribute('data-aiwa-widget-root', 'true');
    root.style.position = 'fixed';
    root.style.zIndex = '2147483000';
    root.style.bottom = '22px';
    root.style[side] = '22px';
    root.style.fontFamily = 'Arial, sans-serif';

    var frame = document.createElement('iframe');
    frame.title = config.name || 'AI chat';
    frame.src = config.chatUrl;
    frame.style.position = 'fixed';
    frame.style.bottom = '88px';
    frame.style[side] = '22px';
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
    button.style.width = '62px';
    button.style.height = '62px';
    button.style.borderRadius = '999px';
    button.style.border = '0';
    button.style.cursor = 'pointer';
    button.style.boxShadow = '0 12px 30px rgba(15, 23, 42, .24)';
    window.AIWhatsAppAutomationLauncher.paint(button, config);

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
