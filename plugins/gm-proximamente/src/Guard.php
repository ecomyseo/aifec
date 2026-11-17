<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Proximamente;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Lo que impide comprar un producto «próximamente».
 *
 * Dos puertas, para que no se cuele por ningún camino:
 *  - early(): el formulario «Añadir al carrito» de la tienda (POST …/add). Contesta con el mensaje claro y no añade nada.
 *  - changed(): cualquier otra entrada al carrito (la API, el asistente): si tras añadir queda una línea de un producto
 *    «próximamente», se quita. El carrito no se toca por dentro: se usa su propio servicio.
 *
 * Solo se mira la petición cuando es un POST a la dirección de añadir: las demás páginas no pagan nada.
 */
final class Guard
{
    /** El texto que se enseña. */
    public static function message(): string
    {
        return t('This product is coming soon and cannot be bought yet.');
    }

    /**
     * Filtro «request.start»: devuelve la respuesta de rechazo si es un intento de añadir un producto «próximamente».
     *
     * @param mixed $early
     *
     * @return mixed
     */
    public static function early($early, Request $request, App $app)
    {
        if ($early instanceof Response || $request->method !== 'POST' || !$request->isSameOrigin()) {
            return $early;
        }
        if ($request->path !== '/' . trim($app->settings()->str('urls.cart', 'carrito'), '/') . '/add') {
            return $early;
        }
        if (!Soon::isSoon($app, $request->int('product_id'))) {
            return $early;
        }
        $message = self::message();
        $count = $app->has('cart') ? (int) $app->service('cart')->count() : 0;
        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $message, 'count' => $count, 'line' => null], 422);
        }
        $app->session('customer')->flash('cart_error', $message);

        return Response::redirect($app->url()->route('cart'));
    }

    /**
     * Acción «cart.changed»: quita del carrito lo que sea de un producto «próximamente» (entradas que no pasan por el formulario).
     *
     * @param mixed $summary el carrito con precios
     */
    public static function changed($summary, App $app): void
    {
        if (!is_array($summary) || !isset($summary['lines']) || !is_array($summary['lines']) || $summary['lines'] === []) {
            return;
        }
        $soon = Soon::ids($app);
        if ($soon === []) {
            return;
        }
        foreach ($summary['lines'] as $key => $line) {
            if (is_array($line) && isset($line['product_id']) && in_array((int) $line['product_id'], $soon, true)) {
                $app->service('cart')->remove((string) (isset($line['key']) ? $line['key'] : $key));
            }
        }
    }
}
