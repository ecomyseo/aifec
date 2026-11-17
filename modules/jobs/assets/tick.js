/**
 * AIFEC — AI-First E-Commerce.
 *
 * Latido de las tareas en segundo plano: mientras alguien tiene el panel abierto y la pestaña a la vista,
 * llama a POST /admin/jobs/tick una vez por minuto. El servidor ejecuta lo que toca (cron y cola) y contesta JSON.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
(function () {
    'use strict';

    var INTERVAL = 60000;
    var script = document.currentScript || document.querySelector('script[data-af-tick-url]');
    var url = script ? script.getAttribute('data-af-tick-url') : '';
    if (!url) {
        return;
    }
    var running = false;
    var last = Date.now();

    function token() {
        var meta = document.querySelector('meta[name="af-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function done() {
        running = false;
    }

    function tick() {
        if (running || document.visibilityState !== 'visible') {
            return;
        }
        running = true;
        last = Date.now();
        try {
            if (window.Aifec && typeof window.Aifec.post === 'function') {
                window.Aifec.post(url, {}).then(done, done);
            } else {
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-CSRF-Token': token(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                }).then(done, done);
            }
        } catch (e) {
            done();
        }
    }

    window.setInterval(tick, INTERVAL);
    // Al volver a la pestaña después de un rato, se pone al día sin esperar al siguiente minuto.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible' && Date.now() - last >= INTERVAL) {
            tick();
        }
    });
}());
