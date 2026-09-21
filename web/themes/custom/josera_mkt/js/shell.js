/**
 * @file
 * Folds the panel navigation away on a narrow screen.
 *
 * The markup ships with the menu open and the button hidden, so somebody
 * without JavaScript keeps a working menu. This turns it into a disclosure
 * only once it has run.
 */

((Drupal, once) => {
  'use strict';

  /**
   * Whether the viewport is narrow enough for the menu to fold away.
   *
   * Matches the breakpoint in shell.css, where the shell starts stacking.
   */
  const isNarrow = () => window.matchMedia('(max-width: 62em)').matches;

  Drupal.behaviors.joseraShellMenu = {
    attach(context) {
      once('josera-shell-menu', '.josera-shell__toggle', context).forEach((toggle) => {
        const menu = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!menu) {
          return;
        }

        const setOpen = (open) => {
          toggle.setAttribute('aria-expanded', String(open));
          menu.classList.toggle('is-open', open);
        };

        // Folded on a narrow screen, always open on a wide one.
        const applyWidth = () => {
          toggle.hidden = !isNarrow();
          setOpen(!isNarrow());
        };
        applyWidth();
        window.addEventListener('resize', applyWidth);

        toggle.addEventListener('click', () => {
          setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });

        // Following a link closes it, so the page does not load behind a
        // menu that covers it.
        menu.addEventListener('click', (event) => {
          if (isNarrow() && event.target.closest('a')) {
            setOpen(false);
          }
        });

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && isNarrow() && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            toggle.focus();
          }
        });
      });
    },
  };
})(Drupal, once);
