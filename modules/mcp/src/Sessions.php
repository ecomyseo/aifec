<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mcp;

use Aifec\Kernel\App;
use Aifec\Security\Random;

/**
 * Las sesiones del MCP y el carrito de cada una.
 *
 * La sesión la abre initialize: el servidor entrega un identificador al azar (cabecera Mcp-Session-Id) y el cliente lo manda en
 * todas las peticiones siguientes. El carrito de la sesión sale de ese identificador (el SHA-256 de «aifec-mcp-cart|<id>|<n>», 48
 * cifras), así que no hace falta guardar nada aparte: el carrito se crea con la primera herramienta que escribe (cart_add) y
 * lleva source = mcp. Cuando el carrito de la sesión se convierte en pedido, el siguiente es el del número n + 1.
 *
 * Para no escribir otro carrito, la ficha se pone en la petición como si fuera la cookie y el servicio «cart» (el mismo de la web, el
 * del asistente y el de la caja) trabaja con ella.
 */
final class Sessions
{
    public const SOURCE = 'mcp';

    /** Cuántos carritos seguidos puede acabar una sesión en pedido. */
    private const MAX_ORDERS = 50;

    /** Un identificador de sesión nuevo. */
    public static function newId(): string
    {
        return Random::token(16);
    }

    public static function valid(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
    }

    /**
     * La ficha del carrito n de una sesión.
     */
    public static function token(string $id, int $n = 0): string
    {
        return substr(hash('sha256', 'aifec-mcp-cart|' . $id . '|' . $n), 0, 48);
    }

    /**
     * Pone el carrito de la sesión en la petición. Con $create, lo crea si todavía no existe.
     *
     * @return string|null la ficha en claro, o null si no hay carrito (y no se ha pedido crearlo)
     */
    public static function bind(App $app, string $id, bool $create): ?string
    {
        if (!self::valid($id)) {
            return null;
        }
        for ($n = 0; $n < self::MAX_ORDERS; ++$n) {
            $raw = self::token($id, $n);
            $row = $app->db()->row('SELECT id, order_id FROM {carts} WHERE token = ?', [hash('sha256', $raw)]);
            if ($row !== null && $row['order_id'] !== null && (int) $row['order_id'] > 0) {
                continue;
            }
            if ($row === null) {
                if (!$create) {
                    return null;
                }
                self::insert($app, $raw);
            }
            $app->request()->cookies[$app->cookieName('k')] = $raw;

            return $raw;
        }

        return null;
    }

    /**
     * Tras quitar la última línea el carrito de la web desaparece. Para una sesión eso sería perder su carrito: si ya no hay fila
     * (y no es un pedido), se vuelve a crear vacía con la misma ficha. Devuelve true si hizo falta.
     */
    public static function keep(App $app, string $raw): bool
    {
        if ($app->db()->row('SELECT id FROM {carts} WHERE token = ?', [hash('sha256', $raw)]) !== null) {
            return false;
        }
        self::insert($app, $raw);

        return true;
    }

    private static function insert(App $app, string $raw): void
    {
        $now = $app->clock()->sql();
        $app->db()->insert('carts', [
            'token' => hash('sha256', $raw), 'customer_id' => null, 'lang' => $app->lang(), 'items' => '[]', 'source' => self::SOURCE,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
