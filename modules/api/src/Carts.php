<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Security\Random;

/**
 * El carrito de un programa. El carrito de la web se identifica con una cookie; el de la API, con una ficha que viaja en la
 * cabecera X-Cart-Token. En la tabla carts solo se guarda el SHA-256 de la ficha, como con la cookie.
 *
 * Para no escribir otro carrito, la ficha se pone en la petición como si fuera la cookie y el servicio «cart» (el mismo de la web,
 * el del asistente y el de la caja) trabaja con ella. Así el carrito, los totales y el pedido son exactamente los de la tienda.
 */
final class Carts
{
    public const HEADER = 'x-cart-token';

    /** ¿Tiene una ficha el aspecto de una ficha de carrito? */
    public static function valid(string $raw): bool
    {
        return preg_match('/^[a-f0-9]{48}$/D', $raw) === 1;
    }

    /**
     * Crea un carrito vacío y devuelve su ficha en claro.
     */
    public static function create(App $app, string $source): string
    {
        $raw = Random::token(24);
        self::insert($app, $raw, $source);

        return $raw;
    }

    /**
     * Crea el carrito de una ficha que ya se conoce (el carrito de una sesión MCP sale de su identificador).
     */
    public static function insert(App $app, string $raw, string $source): void
    {
        $now = $app->clock()->sql();
        $app->db()->insert('carts', [
            'token' => hash('sha256', $raw), 'customer_id' => null, 'lang' => $app->lang(), 'items' => '[]', 'source' => $source,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * La fila de un carrito, o null si no existe. Un carrito que ya es un pedido sigue existiendo (con order_id).
     *
     * @return array<string,mixed>|null
     */
    public static function find(App $app, string $raw): ?array
    {
        return self::valid($raw) ? $app->db()->row('SELECT id, order_id, source FROM {carts} WHERE token = ?', [hash('sha256', $raw)]) : null;
    }

    /**
     * El carrito de la petición: lee la ficha de la cabecera, comprueba que existe y que sigue abierto, y lo deja puesto para
     * el servicio «cart». Devuelve la ficha.
     *
     * @throws HttpError 401 si falta la ficha o no es de ningún carrito, 409 si ya es un pedido
     */
    public static function open(App $app, Request $request): string
    {
        $raw = strtolower(trim((string) $request->header(self::HEADER, '')));
        if ($raw === '') {
            throw new HttpError(401, 'Send the cart token as X-Cart-Token (POST /api/v1/carts gives you one)');
        }

        return self::openToken($app, $raw);
    }

    /**
     * Lo mismo con la ficha ya en la mano (la compra con enlaces la trae en la dirección, no en una cabecera).
     *
     * @throws HttpError 401 si no es de ningún carrito, 409 si ya es un pedido
     */
    public static function openToken(App $app, string $raw): string
    {
        $row = self::find($app, $raw);
        if ($row === null) {
            throw new HttpError(401, 'The cart token is wrong or the cart no longer exists');
        }
        if ($row['order_id'] !== null && (int) $row['order_id'] > 0) {
            throw new HttpError(409, 'This cart has already been ordered');
        }
        self::bind($app, $raw);

        return $raw;
    }

    /** Pone la ficha en la petición como si fuera la cookie del carrito. */
    public static function bind(App $app, string $raw): void
    {
        $app->request()->cookies[$app->cookieName('k')] = $raw;
    }

    /**
     * Tras quitar la última línea el carrito de la web desaparece (fila y cookie). Para un programa eso sería perder su ficha:
     * si ya no hay fila (y no es un pedido), se vuelve a crear vacía con la misma ficha.
     */
    public static function keep(App $app, string $raw, string $source): void
    {
        if (self::find($app, $raw) === null) {
            self::insert($app, $raw, $source);
        }
    }

    /**
     * La dirección que abre ese carrito en el navegador de una persona y la lleva a la caja.
     */
    public static function resumeUrl(App $app, string $raw): string
    {
        return $app->url()->origin() . $app->url()->route('api.cart.resume', ['token' => $raw], '');
    }
}
