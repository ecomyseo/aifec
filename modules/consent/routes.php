<?php
/**
 * AIFEC — consentimiento: la prueba de cada decisión (POST de la tienda) y el informe del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Consent\ConsentAdmin;
use Aifec\Modules\Consent\ConsentController;

return static function (Router $r, App $app): void {
    // La tienda: solo desde la propia web (intermediario csrf: desde otro origen, 403).
    $r->post('/consentimiento', [ConsentController::class, 'record'])->middleware('csrf')->name('consent.record');
    // La política de cookies: la página legal de la tienda si está publicada; si no, la tabla de lo que de verdad se pone.
    $r->get('/cookies', [ConsentController::class, 'policy'])->name('consent.policy');

    // El panel: el informe por semanas y la exportación del registro.
    $r->group(['prefix' => '/admin/consent', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [ConsentAdmin::class, 'report'])->name('admin.consent');
        $r->get('/export.csv', [ConsentAdmin::class, 'export'])->name('admin.consent.export');
    });
};
