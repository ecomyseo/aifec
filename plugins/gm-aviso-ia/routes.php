<?php
/**
 * AIFEC — GM AI notice: la página que explica cómo se redactan los textos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Gm\AvisoIa\InfoController;

return static function (Router $r, App $app): void {
    $r->get('/' . trim($app->settings()->str('gm_aviso_ia.url', 'textos-con-ia'), '/'), [InfoController::class, 'show'])->name('gm.aviso_ia');
};
