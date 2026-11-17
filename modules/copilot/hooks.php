<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Copilot\Actions;
use Aifec\Modules\Copilot\Builtin;
use Aifec\Modules\Copilot\Copilot;
use Aifec\Modules\Copilot\Palette;

return static function (App $app): void {
    // El servicio: entiende, propone, aplica y deshace.
    $app->bind('copilot', static function (App $app): Copilot {
        return new Copilot($app);
    });

    // Las acciones de serie entran por el mismo filtro que las de los demás módulos y plugins (la primera con un identificador manda).
    $app->hooks()->filter(Actions::FILTER, [Builtin::class, 'register'], 1);

    // El buscador de órdenes del panel (Ctrl + K): ajustes por intención, datos, ayuda y «Hacer: …». Va el último (prioridad 99) para no
    // repetir lo que ya hayan puesto el catálogo, los pedidos o las páginas.
    $app->hooks()->filter('admin.palette', [Palette::class, 'filter'], 99);

    // Señala el campo al que lleva un resultado del buscador (#field=…) en las pantallas de ajustes.
    $app->hooks()->slot('admin.foot', static function () use ($app): string {
        if (!$app->auth()->can('copilot.use')) {
            return '';
        }

        return '<script src="' . e($app->url()->asset('m', 'copilot', 'copilot.js')) . '" defer></script>';
    });
};
