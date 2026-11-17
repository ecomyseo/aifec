<?php
/**
 * AIFEC — módulo de IA: publica el servicio «ai», aplica las propuestas aprobadas (filtro «ai.apply»), pone el botón «Proponer con IA»
 * en la ficha de producto, avisa en el inicio si la IA está encendida sin clave y enseña la marca de «redactado con ayuda de IA».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Ai\Ai;
use Aifec\Modules\Ai\AiAdmin;
use Aifec\Modules\Ai\Applier;
use Aifec\Modules\Ai\Notice;

return static function (App $app): void {
    // La entrada única de toda la IA del sistema.
    $app->bind('ai', static function (App $app): Ai {
        return new Ai($app);
    });

    // Este módulo sabe aplicar los campos de producto por idioma y el texto alternativo de una imagen; el resto lo aplican sus dueños (cms…).
    $app->hooks()->filter('ai.apply', static function ($done, array $proposal) use ($app): bool {
        return Applier::apply($app, $done === true, $proposal);
    });

    // La tarjeta «Proponer con IA» en la ficha de producto, sin tocar el módulo del catálogo.
    $app->hooks()->filter('admin.definitions', [AiAdmin::class, 'definitions']);

    // Avisos del inicio: IA encendida sin clave o sin precios.
    $app->hooks()->filter('admin.status', [AiAdmin::class, 'status']);

    // La marca de la tienda: solo con el ajuste encendido y con textos aprobados de ese producto.
    $app->hooks()->slot('product.bottom', static function (array $product) use ($app): string {
        return Notice::render($app, $product);
    });
};
