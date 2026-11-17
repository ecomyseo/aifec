/**
 * AIFEC — AI-First E-Commerce.
 *
 * Pantallas de la migración: cada 5 segundos pregunta al servidor cómo va la migración (o el simulacro) y repinta las
 * barras y los avisos sin recargar la página. Si el estado cambia (termina, se pausa, falla), recarga para enseñar los
 * botones y el resumen que corresponden. Todo va por delegación sobre document.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    var INTERVAL = 5000;
    var running = false;

    function root() {
        return document.querySelector('[data-af-import-poll]');
    }

    function paint(el, data) {
        var bars = el.querySelector('[data-af-import-bars]');
        if (bars && typeof data.bars_html === 'string') {
            bars.innerHTML = data.bars_html;
        }
        var notices = el.querySelector('[data-af-import-notices]');
        if (notices && typeof data.notices_html === 'string') {
            notices.innerHTML = data.notices_html;
        }
    }

    function done() {
        running = false;
    }

    function tick() {
        var el = root();
        if (!el || running || el.getAttribute('data-af-import-open') !== '1' || document.visibilityState === 'hidden') {
            return;
        }
        var url = el.getAttribute('data-af-import-poll');
        if (!url || !window.Aifec || typeof window.Aifec.post !== 'function') {
            return;
        }
        running = true;
        window.Aifec.post(url, {}).then(function (data) {
            running = false;
            if (!data || data.ok !== true) {
                return;
            }
            if (data.status !== el.getAttribute('data-af-import-status')) {
                window.location.reload();
                return;
            }
            paint(el, data);
        }, done);
    }

    window.setInterval(tick, INTERVAL);

    // «Imprimir» de la página imprimible.
    document.addEventListener('click', function (event) {
        var target = event.target;
        while (target && target !== document) {
            if (target.hasAttribute && target.hasAttribute('data-af-import-print')) {
                window.print();
                return;
            }
            target = target.parentNode;
        }
    });
}());
