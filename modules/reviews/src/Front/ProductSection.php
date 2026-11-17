<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Reviews\Front;

use Aifec\Kernel\App;
use Aifec\Modules\Reviews\Reviews;

/**
 * La sección de valoraciones de la ficha de producto (hueco «product.bottom»): resumen de una línea, reparto por estrellas, lista paginada y el
 * formulario plegado (se abre con ?valorar=1). Va dentro de la página de la ficha, que está en la caché de página: no lleva nada de la persona (ni su nombre ni su
 * correo) y se invalida al publicar o retirar (etiqueta product:<id>). La página de opiniones va en la dirección
 * (?valoraciones=2), que la caché guarda como otra copia.
 */
final class ProductSection
{
    /**
     * @param array<string,mixed> $product ProductData (catalog->product)
     */
    public static function render(App $app, array $product): string
    {
        $id = (int) $product['id'];
        /** @var Reviews $reviews */
        $reviews = $app->service('reviews');
        $request = $app->request();
        $notice = $request->str('valoracion');

        return $app->view()->render('reviews::front/product', [
            'product' => $product,
            'data' => $reviews->forProduct($id, max(1, $request->int('valoraciones', 1))),
            'notice' => in_array($notice, ['sent', 'published'], true) ? $notice : '',
            'open' => $request->str('valorar') === '1',
            'action' => $app->url()->route('reviews.submit', ['productId' => $id]),
            'values' => [],
            'errors' => [],
        ]);
    }
}
