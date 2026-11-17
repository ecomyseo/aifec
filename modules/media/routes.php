<?php
/**
 * AIFEC — rutas del módulo de medios: la biblioteca, la subida, el selector y la ficha de cada imagen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Media\MediaController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/media', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [MediaController::class, 'index'])->name('admin.media');
        $r->get('/picker', [MediaController::class, 'picker'])->name('admin.media.picker');
        $r->post('/upload', [MediaController::class, 'upload'])->name('admin.media.upload');
        $r->get('/{id:\d+}', [MediaController::class, 'show']);
        $r->post('/{id:\d+}', [MediaController::class, 'save']);
        $r->post('/{id:\d+}/delete', [MediaController::class, 'delete']);
        $r->post('/{id:\d+}/regenerate', [MediaController::class, 'regenerate']);
    });
};
