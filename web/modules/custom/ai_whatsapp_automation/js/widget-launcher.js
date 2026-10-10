/**
 * @file
 * Paints the floating chat button: its colour and its icon.
 *
 * One painter for two places. The embed script serves this file to the
 * client's website, and the bot form loads it for the live preview, so what
 * the preview shows is what visitors get. Keep it free of Drupal and in plain
 * ES5: it runs on third-party pages, in whatever browser their visitors have.
 */
(function (root) {
  'use strict';

  var DEFAULT_COLOR = '#155EEF';
  var LIGHT_INK = '#ffffff';
  var DARK_INK = '#111827';
  var SVG_OPEN = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';

  var ICONS = {
    chat: SVG_OPEN
      + '<path d="M21 11.5a8.4 8.4 0 0 1-12.2 7.5L3.5 20.5l1.6-4.8A8.4 8.4 0 1 1 21 11.5z"/>'
      + '<path d="M8.3 11.5h.01M12.3 11.5h.01M16.3 11.5h.01" stroke-width="2.8"/></svg>',
    sparkles: SVG_OPEN
      + '<path d="M11 3.5l1.9 5.1 5.1 1.9-5.1 1.9-1.9 5.1-1.9-5.1-5.1-1.9 5.1-1.9z"/>'
      + '<path d="M18.5 14.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg>',
    help: SVG_OPEN
      + '<circle cx="12" cy="12" r="9"/>'
      + '<path d="M9.4 9.3a2.7 2.7 0 0 1 5.2 1c0 1.9-2.6 2.4-2.6 4"/>'
      + '<path d="M12 17.4h.01" stroke-width="2.8"/></svg>'
  };

  /**
   * Returns [r, g, b] for #RGB or #RRGGBB, or null for anything else.
   */
  function parseHex(hex) {
    var match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex || '').trim());
    if (!match) {
      return null;
    }
    var digits = match[1].length === 3
      ? match[1].replace(/(.)/g, '$1$1')
      : match[1];
    return [0, 2, 4].map(function (start) {
      return parseInt(digits.substr(start, 2), 16);
    });
  }

  /**
   * WCAG relative luminance of an [r, g, b] colour.
   */
  function luminance(rgb) {
    var channel = rgb.map(function (value) {
      var c = value / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * channel[0] + 0.7152 * channel[1] + 0.0722 * channel[2];
  }

  /**
   * WCAG contrast ratio between two hex colours (1 to 21), or 0 if invalid.
   */
  function contrast(first, second) {
    var a = parseHex(first);
    var b = parseHex(second);
    if (!a || !b) {
      return 0;
    }
    var la = luminance(a);
    var lb = luminance(b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  }

  /**
   * The icon colour that reads best on the given background: white or ink.
   *
   * A pale brand colour would otherwise leave a white icon invisible.
   */
  function inkFor(background) {
    return contrast(background, LIGHT_INK) >= contrast(background, DARK_INK) ? LIGHT_INK : DARK_INK;
  }

  /**
   * Paints a button with the bot's colour and icon.
   *
   * @param {HTMLElement} button
   *   The launcher button.
   * @param {object} config
   *   primaryColor, icon ('chat', 'sparkles', 'help' or 'image') and
   *   buttonImageUrl.
   */
  function paint(button, config) {
    var color = parseHex(config.primaryColor) ? String(config.primaryColor).trim() : DEFAULT_COLOR;
    var useImage = config.icon === 'image' && config.buttonImageUrl;

    button.style.background = color;
    button.style.color = inkFor(color);
    button.style.display = 'flex';
    button.style.alignItems = 'center';
    button.style.justifyContent = 'center';
    button.style.padding = '0';
    button.style.overflow = 'hidden';
    button.innerHTML = '';

    if (!useImage) {
      button.innerHTML = ICONS[config.icon] || ICONS.chat;
      return;
    }

    var image = document.createElement('img');
    image.src = config.buttonImageUrl;
    image.alt = '';
    image.style.width = '100%';
    image.style.height = '100%';
    image.style.objectFit = 'cover';
    image.style.display = 'block';
    // A deleted or blocked picture must never leave an empty circle.
    image.onerror = function () {
      button.innerHTML = ICONS.chat;
    };
    button.appendChild(image);
  }

  root.AIWhatsAppAutomationLauncher = {
    paint: paint,
    inkFor: inkFor,
    contrast: contrast,
    icons: ICONS
  };
}(window));
