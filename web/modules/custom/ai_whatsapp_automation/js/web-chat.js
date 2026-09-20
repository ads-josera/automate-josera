(function (window, document) {
  'use strict';

(function attach(context) {
    var form = context.querySelector && context.querySelector('[data-aiwa-form]');
    if (!form || form.dataset.aiwaBound === 'true') {
      return;
    }
      form.dataset.aiwaBound = 'true';

      var settings = (window.drupalSettings || {}).aiWhatsappAutomationWebChat || {};
      var isSpanish = String(settings.language || '').toLowerCase().indexOf('es') === 0;
      var messages = context.querySelector('[data-aiwa-messages]');
      var input = context.querySelector('[data-aiwa-input]');
      var button = form.querySelector('button[type="submit"]');
      var minimizeButton = context.querySelector('[data-aiwa-minimize]');
      var storageKey = 'aiwa_web_chat_' + (settings.token || 'default');
      var sessionId = localStorage.getItem(storageKey);

      if (!sessionId) {
        sessionId = createSessionId();
        localStorage.setItem(storageKey, sessionId);
      }
      if (button) {
        button.dataset.aiwaLabel = button.textContent;
      }
      if (minimizeButton) {
        if (window.parent === window) {
          minimizeButton.hidden = true;
        }
        else {
          minimizeButton.addEventListener('click', function () {
            window.parent.postMessage({ type: 'aiwa:minimize-chat' }, '*');
          });
        }
      }

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var text = (input.value || '').trim();
        if (!text || !settings.apiUrl) {
          return;
        }

        appendMessage(messages, text, 'user');
        input.value = '';
        input.style.height = '';
        setLoading(button, true);
        var typing = appendTypingIndicator(messages, isSpanish);

        fetch(settings.apiUrl, {
          method: 'POST',
          headers: requestHeaders(settings),
          body: JSON.stringify({
            session_id: sessionId,
            message: text
          })
        })
          .then(function (response) {
            return response.json().then(function (payload) {
              if (!response.ok) {
                throw new Error(payload.error || 'Request failed');
              }
              return payload;
            });
          })
          .then(function (payload) {
            removeElement(typing);
            // An empty reply means the assistant chose to stay quiet, so no
            // bubble is drawn rather than an empty one.
            if (payload.message) {
              appendMessage(messages, payload.message, 'ai');
            }
          })
          .catch(function () {
            removeElement(typing);
            appendMessage(messages, isSpanish ? 'No pude responder en este momento. Intenta nuevamente.' : 'I could not respond right now. Please try again.', 'system');
          })
          .finally(function () {
            setLoading(button, false);
            input.focus();
          });
      });

      input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 112) + 'px';
      });

      input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
      });
  })(document);

  function requestHeaders(settings) {
    var headers = {
      'Content-Type': 'application/json'
    };
    if (settings.apiKey) {
      headers['X-AI-WhatsApp-Key'] = settings.apiKey;
    }
    return headers;
  }

  function appendMessage(container, text, type) {
    if (!container || !text) {
      return;
    }

    var item = document.createElement('div');
    item.className = 'aiwa-message aiwa-message--' + type;
    if (type === 'ai') {
      item.innerHTML = formatAssistantText(text);
    }
    else {
      item.textContent = text;
    }
    container.appendChild(item);
    scrollToLatest(container);
  }

  function appendTypingIndicator(container, isSpanish) {
    if (!container) {
      return null;
    }

    var item = document.createElement('div');
    item.className = 'aiwa-message aiwa-message--ai aiwa-message--typing';
    item.setAttribute('aria-label', isSpanish ? 'El asistente está escribiendo' : 'The assistant is typing');
    item.innerHTML = '<span></span><span></span><span></span>';
    container.appendChild(item);
    scrollToLatest(container);

    return item;
  }

  function removeElement(element) {
    if (element && element.parentNode) {
      element.parentNode.removeChild(element);
    }
  }

  function scrollToLatest(container) {
    container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
  }

  // Renders the assistant's Markdown subset: paragraphs, "- " bullet lists,
  // "1. " numbered lists and **bold**. Every text fragment is escaped before
  // any tag is added, so model output can never inject HTML.
  function formatAssistantText(text) {
    var lines = String(text).replace(/\r\n?/g, '\n').split('\n');
    var blocks = [];
    var paragraph = [];
    var list = null;

    function inline(value) {
      return escapeHtml(value).replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    }

    function flushParagraph() {
      if (paragraph.length) {
        blocks.push('<p>' + paragraph.join('<br>') + '</p>');
        paragraph = [];
      }
    }

    function flushList() {
      if (list) {
        var start = list.tag === 'ol' && list.start > 1 ? ' start="' + list.start + '"' : '';
        blocks.push('<' + list.tag + start + '>' + list.items.join('') + '</' + list.tag + '>');
        list = null;
      }
    }

    lines.forEach(function (rawLine) {
      var line = rawLine.trim();
      var bullet = line.match(/^[-*•]\s+(.+)$/);
      var numbered = line.match(/^(\d+)[.)]\s+(.+)$/);

      if (bullet || numbered) {
        var tag = bullet ? 'ul' : 'ol';
        flushParagraph();
        if (list && list.tag !== tag) {
          flushList();
        }
        if (!list) {
          list = { tag: tag, start: numbered ? parseInt(numbered[1], 10) : 1, items: [] };
        }
        list.items.push('<li>' + inline(bullet ? bullet[1] : numbered[2]) + '</li>');
        return;
      }

      flushList();
      if (line === '') {
        flushParagraph();
        return;
      }
      paragraph.push(inline(line));
    });

    flushParagraph();
    flushList();

    return blocks.join('');
  }

  function escapeHtml(text) {
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setLoading(button, loading) {
    if (!button) {
      return;
    }
    button.disabled = loading;
    button.textContent = loading ? '...' : (button.dataset.aiwaLabel || 'Send');
  }

  function createSessionId() {
    if (window.crypto && window.crypto.getRandomValues) {
      var values = new Uint32Array(4);
      window.crypto.getRandomValues(values);
      return Array.prototype.map.call(values, function (value) {
        return value.toString(16);
      }).join('');
    }

    return String(Date.now()) + String(Math.random()).slice(2);
  }
})(window, document);
