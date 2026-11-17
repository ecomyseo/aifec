<?php
/**
 * AIFEC — GM coming soon: la ruta del «avísame».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Gm\Proximamente\NotifyController;

return static function (Router $r, App $app): void {
    $base = '/' . trim($app->settings()->str('gm_proximamente.url', 'proximamente'), '/');
    // Solo se acepta desde la propia web (intermediario csrf).
    $r->group(['prefix' => $base, 'middleware' => ['csrf']], static function (Router $r): void {
        $r->post('/avisame', [NotifyController::class, 'notify'])->name('gm.soon.notify');
    });
};
