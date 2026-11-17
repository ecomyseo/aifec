<?php
/**
 * AIFEC — lo que el núcleo registra al arrancar: los intermediarios de serie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Gate;
use Aifec\Kernel\App;

return static function (App $app): void {
    $app->middleware('admin', [Gate::class, 'admin']);
    $app->middleware('customer', [Gate::class, 'customer']);
    $app->middleware('csrf', [Gate::class, 'csrf']);
    $app->middleware('api', [Gate::class, 'api']);
};
