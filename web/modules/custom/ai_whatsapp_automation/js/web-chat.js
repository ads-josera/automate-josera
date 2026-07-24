(function (window, document) {
  'use strict';

(function attach(context) {
    var form = context.querySelector && context.querySelector('[data-aiwa-form]');
    if (!form || form.dataset.aiwaBound === 'true') {
      return;
    }
      form.dataset.aiwaBound = 'true';

      var settings = (window.drupalSettings || {}).aiWhatsappAutomationWebChat || {};
      var messages = context.querySelector('[data-aiwa-messages]');
      var input = context.querySelector('[data-aiwa-input]');
      var button = form.querySelector('button[type="submit"]');
      var storageKey = 'aiwa_web_chat_' + (settings.token || 'default');
      var sessionId = localStorage.getItem(storageKey);

      if (!sessionId) {
        sessionId = createSessionId();
        localStorage.setItem(storageKey, sessionId);
      }
      if (button) {
        button.dataset.aiwaLabel = button.textContent;
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
            appendMessage(messages, payload.message || '', 'ai');
          })
          .catch(function () {
            appendMessage(messages, 'No pude responder en este momento. Intenta nuevamente.', 'system');
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
    item.textContent = text;
    container.appendChild(item);
    container.scrollTop = container.scrollHeight;
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
