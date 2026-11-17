/**
 * AIFEC — AI-First E-Commerce.
 *
 * «Más opciones» de la ficha de producto: un <details> por pestaña que recuerda si se abrió. El servidor ya lo abre solo si
 * algún campo tiene valor o error; esto añade que, si la persona lo abrió a mano, vuelva a estar abierto la próxima vez.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    var PREFIX = 'aifec.more.';

    function remember(key, open) {
        try {
            if (open) {
                window.localStorage.setItem(PREFIX + key, '1');
            } else {
                window.localStorage.removeItem(PREFIX + key);
            }
        } catch (e) {
            // Sin almacenamiento (navegación privada): simplemente no se recuerda.
        }
    }

    function remembered(key) {
        try {
            return window.localStorage.getItem(PREFIX + key) === '1';
        } catch (e) {
            return false;
        }
    }

    function restore() {
        var list = document.querySelectorAll('details[data-af-more]');
        for (var i = 0; i < list.length; i++) {
            if (!list[i].open && remembered(list[i].getAttribute('data-af-more'))) {
                list[i].open = true;
            }
        }
    }

    // El evento «toggle» no sube por el árbol: se escucha en la fase de captura, sobre el documento.
    document.addEventListener('toggle', function (e) {
        var el = e.target;
        if (el && el.nodeType === 1 && el.hasAttribute('data-af-more')) {
            remember(el.getAttribute('data-af-more'), el.open);
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restore);
    } else {
        restore();
    }
}());
