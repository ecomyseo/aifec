/**
 * AIFEC — AI-First E-Commerce.
 *
 * El hueco de los resultados (data-af-search-more): pide la ayuda de la IA que la página no esperó y, si mejora, cambia lo
 * que hay en <main> por lo que pinta el servidor. Los textos vienen de la página y de la respuesta, no de aquí.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    function drop(node) {
        if (node && node.parentNode) {
            node.parentNode.removeChild(node);
        }
    }

    function start() {
        var hole = document.querySelector('[data-af-search-more]');
        var main = document.getElementById('main');
        var status = hole ? hole.querySelector('[data-af-search-more-status]') : null;
        if (!hole || !main || !status || !window.fetch) {
            return;
        }
        drop(hole.querySelector('[data-af-search-more-link]'));
        // El aviso pasa a ser hijo directo de <main>: sigue ahí cuando se cambia lo demás y el lector de pantalla lo anuncia.
        main.insertBefore(status, main.firstChild);
        status.hidden = false;
        fetch(hole.getAttribute('data-af-search-more'), {
            headers: {'X-Requested-With': 'fetch', 'Accept': 'application/json'},
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (response) {
            return response.ok ? response.json() : {};
        }).then(function (data) {
            if (!data || data.better !== true || typeof data.html !== 'string') {
                return Promise.reject(data);
            }
            var box = document.createElement('div');
            var node = main.firstChild;
            var next;
            box.innerHTML = data.html;
            while (node) {
                next = node.nextSibling;
                if (node !== status) {
                    main.removeChild(node);
                }
                node = next;
            }
            while (box.firstChild) {
                main.appendChild(box.firstChild);
            }
            status.textContent = data.said || '';
        }).catch(function () {
            drop(status);
            drop(hole);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
