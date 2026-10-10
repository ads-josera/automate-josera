/**
 * @file
 * Live preview of the chat button and header on the bot form.
 *
 * Reads the form as it is being edited and redraws a mock-up on every change.
 * The button is painted by AIWhatsAppAutomationLauncher, the same code the
 * embed script serves to clients' websites. The header mirrors css/web-chat.css:
 * keep the two in step if the real chat header changes.
 */
((Drupal, once) => {
  'use strict';

  const WHITE = '#ffffff';
  const MIN_CONTRAST = 4.5;

  /**
   * What a visitor reads in the chat, per widget language.
   *
   * Mirrors WebChatController::chatLabels(): the preview shows the visitor's
   * language, not the admin's, so these are not passed through Drupal.t().
   */
  const VISITOR_TEXT = {
    es: { status: 'Asistente en línea', sample: 'Quiero una cotización', welcome: 'Hola, ¿en qué puedo ayudarte?' },
    en: { status: 'Online assistant', sample: 'I would like a quote', welcome: 'Hello, how can I help you?' },
  };

  /**
   * Returns the form control for a field, by its name attribute.
   */
  const control = (form, name) => form.querySelector(`[name="${name}"]`);

  /**
   * Returns the URL of the file a managed-file widget currently holds, or ''.
   *
   * Drupal re-renders the widget after an upload or a removal, and then shows
   * the stored file as a link; that link is the only reliable source.
   */
  const fileUrl = (form, selector) => {
    const link = form.querySelector(`[data-drupal-selector^="${selector}"] .file a[href]`);
    return link ? link.href : '';
  };

  /**
   * Normalises a hex colour typed by hand to #RRGGBB, or returns ''.
   */
  const fullHex = (value) => {
    const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(value || '').trim());
    if (!match) {
      return '';
    }
    const digits = match[1].length === 3 ? match[1].replace(/(.)/g, '$1$1') : match[1];
    return `#${digits.toUpperCase()}`;
  };

  /**
   * Adds a native colour picker beside a hex text field, kept in step both ways.
   *
   * The text field stays the source of truth: it is what is saved, validated
   * and readable by assistive technology. The picker only writes into it.
   */
  const addColorPicker = (input, label) => {
    const picker = document.createElement('input');
    picker.type = 'color';
    picker.className = 'aiwa-color-picker';
    picker.setAttribute('aria-label', label);
    picker.value = (fullHex(input.value) || '#000000').toLowerCase();

    picker.addEventListener('input', () => {
      input.value = picker.value.toUpperCase();
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    input.addEventListener('input', () => {
      const hex = fullHex(input.value);
      if (hex) {
        picker.value = hex.toLowerCase();
      }
    });

    const wrapper = document.createElement('span');
    wrapper.className = 'aiwa-color-field';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(picker);
    wrapper.appendChild(input);
  };

  /**
   * Builds the static parts of the mock-up once.
   */
  const buildStage = (preview) => {
    const stage = document.createElement('div');
    stage.className = 'aiwa-widget-preview__stage';
    stage.innerHTML = `
      <div class="aiwa-widget-preview__chat" aria-hidden="true">
        <div class="aiwa-widget-preview__header">
          <span class="aiwa-widget-preview__logo"></span>
          <span class="aiwa-widget-preview__title">
            <strong></strong>
            <span class="aiwa-widget-preview__status"></span>
          </span>
        </div>
        <div class="aiwa-widget-preview__messages">
          <p class="aiwa-widget-preview__bubble aiwa-widget-preview__bubble--ai"><span></span></p>
          <p class="aiwa-widget-preview__bubble aiwa-widget-preview__bubble--user"><span></span></p>
        </div>
      </div>
      <button type="button" class="aiwa-widget-preview__launcher" tabindex="-1" aria-hidden="true"></button>`;
    preview.appendChild(stage);

    const notes = document.createElement('div');
    notes.className = 'aiwa-widget-preview__notes';
    notes.setAttribute('aria-live', 'polite');
    preview.appendChild(notes);

    return { stage, notes };
  };

  /**
   * Redraws the mock-up from the current state of the form.
   */
  const render = (form, parts) => {
    const launcher = window.AIWhatsAppAutomationLauncher;
    const primary = fullHex(control(form, 'web_widget_primary_color[0][value]')?.value);
    const color = primary || '#155EEF';
    const icon = control(form, 'web_widget_icon')?.value || 'chat';
    const position = control(form, 'web_widget_position')?.value || 'right';
    const name = control(form, 'web_widget_assistant_name[0][value]')?.value.trim()
      || control(form, 'name[0][value]')?.value.trim()
      || Drupal.t('Assistant');
    const language = String(control(form, 'web_widget_language')?.value || 'es').toLowerCase();
    const visitor = VISITOR_TEXT[language.startsWith('es') ? 'es' : 'en'];
    const welcome = control(form, 'web_widget_welcome_message[0][value]')?.value.trim()
      || visitor.welcome;
    const logoUrl = fileUrl(form, 'edit-web-widget-logo-file');
    const buttonImageUrl = fileUrl(form, 'edit-web-widget-button-image') || logoUrl;

    const { stage, notes } = parts;
    stage.style.setProperty('--aiwa-preview-primary', color);
    stage.classList.toggle('is-left', position === 'left');

    stage.querySelector('.aiwa-widget-preview__title strong').textContent = name;
    stage.querySelector('.aiwa-widget-preview__status').textContent = visitor.status;
    stage.querySelector('.aiwa-widget-preview__bubble--ai > span').textContent = welcome;
    stage.querySelector('.aiwa-widget-preview__bubble--user > span').textContent = visitor.sample;
    const logo = stage.querySelector('.aiwa-widget-preview__logo');
    logo.textContent = '';
    if (logoUrl) {
      const image = document.createElement('img');
      image.src = logoUrl;
      image.alt = '';
      logo.appendChild(image);
    }
    else {
      logo.textContent = 'AI';
    }

    launcher.paint(stage.querySelector('.aiwa-widget-preview__launcher'), {
      primaryColor: color,
      icon,
      buttonImageUrl,
    });

    // Say what the mock-up cannot make obvious on its own.
    const messages = [];
    if (!primary) {
      messages.push(Drupal.t('The primary colour is not a valid hex colour, so the default blue is shown. Use a value such as #155EEF.'));
    }
    const ratio = launcher.contrast(color, WHITE);
    if (primary && ratio < MIN_CONTRAST) {
      messages.push(Drupal.t('White text on this colour has a contrast of @ratio:1, below the 4.5:1 needed to read it comfortably. Visitors\' messages and the send button use it.', {
        '@ratio': ratio.toFixed(1),
      }));
    }
    if (icon === 'image' && !buttonImageUrl) {
      messages.push(Drupal.t('No image uploaded yet: the button shows the chat icon until you add one, or a logo.'));
    }
    notes.innerHTML = '';
    messages.forEach((text) => {
      const note = document.createElement('p');
      note.className = 'aiwa-widget-preview__note';
      note.textContent = text;
      notes.appendChild(note);
    });
  };

  Drupal.behaviors.aiWhatsappAutomationWidgetPreview = {
    attach(context) {
      once('aiwa-widget-preview', '[data-aiwa-widget-preview]', context).forEach((preview) => {
        const form = preview.closest('form');
        if (!form || !window.AIWhatsAppAutomationLauncher) {
          return;
        }
        preview.aiwaParts = buildStage(preview);
        [
          ['web_widget_primary_color[0][value]', Drupal.t('Pick the primary colour')],
          ['web_widget_secondary_color[0][value]', Drupal.t('Pick the secondary colour')],
        ].forEach(([name, label]) => {
          const input = control(form, name);
          if (input) {
            addColorPicker(input, label);
          }
        });

        const redraw = () => render(form, preview.aiwaParts);
        form.addEventListener('input', redraw);
        form.addEventListener('change', redraw);
      });

      // Uploading or removing a file re-renders only that widget and calls
      // this behaviour on it, so redraw from the whole form every time.
      document.querySelectorAll('[data-aiwa-widget-preview]').forEach((preview) => {
        const form = preview.closest('form');
        if (form && preview.aiwaParts) {
          render(form, preview.aiwaParts);
        }
      });
    },
  };
})(Drupal, once);
