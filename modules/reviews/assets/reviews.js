/**
 * AIFEC reviews: «Read more» on long reviews. Without this script every review is shown in full.
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    function target(button) {
        return document.getElementById(button.getAttribute('aria-controls') || '');
    }

    function setup() {
        var buttons = document.querySelectorAll('[data-af-more]');
        for (var i = 0; i < buttons.length; i++) {
            var text = target(buttons[i]);
            if (!text) {
                continue;
            }
            text.classList.add('is-clamped');
            if (text.scrollHeight > text.clientHeight + 1) {
                buttons[i].hidden = false;
            } else {
                text.classList.remove('is-clamped');
            }
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('[data-af-more]') : null;
        var text = button ? target(button) : null;
        if (!button || !text) {
            return;
        }
        var expanded = button.getAttribute('aria-expanded') === 'true';
        button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        text.classList.toggle('is-clamped', expanded);
        button.textContent = button.getAttribute(expanded ? 'data-more' : 'data-less') || button.textContent;
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
}());
