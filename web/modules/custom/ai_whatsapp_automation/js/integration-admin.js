(function (Drupal) {
  'use strict';

  Drupal.behaviors.aiWhatsappAutomationIntegrationAdmin = {
    attach: function attach(context) {
      var buttons = context.querySelectorAll ? context.querySelectorAll('[data-aiwa-copy-target]') : [];
      buttons.forEach(function (button) {
        if (button.dataset.aiwaBound === 'true') {
          return;
        }
        button.dataset.aiwaBound = 'true';
        button.addEventListener('click', function () {
          var target = document.getElementById(button.dataset.aiwaCopyTarget);
          if (!target) {
            return;
          }
          copyText(target.value).then(function () {
            var original = button.textContent;
            button.textContent = 'Copied';
            button.classList.add('is-copied');
            setTimeout(function () {
              button.textContent = original;
              button.classList.remove('is-copied');
            }, 1300);
          });
        });
      });
    }
  };

  function copyText(value) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(value);
    }

    var textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
    return Promise.resolve();
  }
})(Drupal);
