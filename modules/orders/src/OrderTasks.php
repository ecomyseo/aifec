<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Kernel\App;

/**
 * Tareas programadas de los pedidos (las llama el módulo de tareas, ver jobs.php).
 */
final class OrderTasks
{
    /**
     * Cancela los pedidos de pasarela sin pagar tras orders.pending_minutes. Devuelve una nota corta («3 cancelled») o
     * null si no había nada que hacer.
     */
    public static function expire(App $app): ?string
    {
        $cancelled = $app->service('orders')->expireUnpaid();

        return $cancelled > 0 ? $cancelled . ' cancelled' : null;
    }

    /** Días sin tocarse tras los que se borra un carrito sin pedido. Su cookie dura 30: a los 45 ya no lo puede abrir nadie. */
    public const CART_DAYS = 45;

    /** Días que se guarda el carrito de un pedido ya hecho (hace falta para volver a intentar un pago que falló). */
    public const ORDERED_CART_DAYS = 90;

    /**
     * Borra los carritos que ya no puede abrir nadie: los que se quedaron sin pedido y llevan CART_DAYS sin tocarse, y los de
     * pedidos hechos hace más de ORDERED_CART_DAYS. Solo borra filas propias: no avisa a nadie ni toca pedidos. Sin esto la
     * tabla crece para siempre (cada visitante que añade algo y se va deja una fila).
     */
    public static function cleanCarts(App $app): ?string
    {
        $db = $app->db();
        $clock = $app->clock();
        $abandoned = (int) $db->run('DELETE FROM {carts} WHERE order_id IS NULL AND updated_at < ?', [$clock->sql($clock->now() - self::CART_DAYS * 86400)]);
        $ordered = (int) $db->run('DELETE FROM {carts} WHERE order_id IS NOT NULL AND updated_at < ?', [$clock->sql($clock->now() - self::ORDERED_CART_DAYS * 86400)]);

        return $abandoned + $ordered > 0 ? ($abandoned + $ordered) . ' carts deleted' : null;
    }
}
