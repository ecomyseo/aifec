<?php
/**
 * AIFEC — módulo de medios: publica los servicios «media» y «files» y carga el selector de imágenes en el panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Media\Library;
use Aifec\Modules\Media\PrivateFiles;

return static function (App $app): void {
    $app->bind('media', static function (App $app): Library {
        return new Library($app);
    });
    $app->bind('files', static function (App $app): PrivateFiles {
        return new PrivateFiles($app);
    });

    // El selector de imágenes (lo que pinta Form::mediaControl) vive en todo el panel: guion y estilos van en sus huecos.
    $app->hooks()->slot('admin.head', static function () use ($app): string {
        return '<link rel="stylesheet" href="' . e($app->url()->asset('m', 'media', 'media.css')) . '">';
    });
    $app->hooks()->slot('admin.foot', static function () use ($app): string {
        return '<script src="' . e($app->url()->asset('m', 'media', 'media.js')) . '" defer></script>';
    });
};
