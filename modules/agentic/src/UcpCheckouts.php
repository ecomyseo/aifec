<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Security\Random;

/**
 * Las sesiones de compra de UCP y su carrito.
 *
 * Cada sesión tiene un identificador al azar («chk_» y 32 cifras hexadecimales) que la plataforma manda en cada petición, y
 * una fila en ucp_checkouts con su estado (open, completed, canceled), la plataforma y el pedido. El carrito sale del
 * identificador (el SHA-256 de «aifec-ucp-cart|<id>», 48 cifras) y es el carrito de siempre (source = ucp): se pone en la
 * petición como si fuera la cookie y lo calculan el servicio «cart» y la caja, igual que para la web, la API y el MCP.
 */
final class UcpCheckouts
{
    public const SOURCE = 'ucp';

    public const OPEN = 'open';
    public const COMPLETED = 'completed';
    public const CANCELED = 'canceled';

    public static function newId(): string
    {
        return 'chk_' . Random::token(16);
    }

    public static function valid(string $id): bool
    {
        return preg_match('/^chk_[a-f0-9]{32}$/D', $id) === 1;
    }

    /** La ficha del carrito de la sesión (la que iría en la cookie). */
    public static function cartToken(string $id): string
    {
        return substr(hash('sha256', 'aifec-ucp-cart|' . $id), 0, 48);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(App $app, string $id): ?array
    {
        if (!self::valid($id)) {
            return null;
        }

        return $app->db()->row('SELECT * FROM {ucp_checkouts} WHERE public_id = ?', [$id]);
    }

    /**
     * Crea la sesión y su carrito vacío.
     *
     * @return array<string,mixed>
     */
    public static function create(App $app, string $platform, ?int $keyId): array
    {
        $id = self::newId();
        $now = $app->clock()->sql();
        $app->db()->insert('ucp_checkouts', [
            'public_id' => $id, 'status' => self::OPEN, 'platform' => $platform !== '' ? $platform : null, 'key_id' => $keyId,
            'order_id' => null, 'order_number' => null, 'order_url' => null, 'buyer' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::insertCart($app, self::cartToken($id));
        $row = self::find($app, $id);
        if ($row === null) {
            throw new \RuntimeException('The UCP checkout could not be saved');
        }

        return $row;
    }

    /** Pone el carrito de la sesión en la petición; si ya no existe (se vació), lo vuelve a crear vacío. */
    public static function bind(App $app, string $id): string
    {
        $raw = self::cartToken($id);
        $row = $app->db()->row('SELECT id FROM {carts} WHERE token = ?', [hash('sha256', $raw)]);
        if ($row === null) {
            self::insertCart($app, $raw);
        }
        $app->request()->cookies[$app->cookieName('k')] = $raw;

        return $raw;
    }

    /** Tras quitar la última línea el carrito desaparece: para una sesión eso es perderlo, así que se vuelve a crear. */
    public static function keep(App $app, string $id): void
    {
        $raw = self::cartToken($id);
        if ($app->db()->row('SELECT id FROM {carts} WHERE token = ?', [hash('sha256', $raw)]) === null) {
            self::insertCart($app, $raw);
        }
    }

    /**
     * Tras un cobro que no salió, la caja deja el carrito libre otra vez pero con una ficha nueva al azar (Cart::reopen).
     * Se le devuelve la ficha de la sesión, para que la plataforma pueda reintentar con la misma sesión.
     */
    public static function restoreCart(App $app, string $id, int $cartId): void
    {
        if ($cartId < 1) {
            return;
        }
        $hash = hash('sha256', self::cartToken($id));
        $app->db()->run('UPDATE {carts} SET token = ? WHERE id = ? AND order_id IS NULL AND token <> ?', [$hash, $cartId, $hash]);
    }

    /** @param array<string,mixed> $changes */
    public static function update(App $app, string $id, array $changes): void
    {
        $changes['updated_at'] = $app->clock()->sql();
        $app->db()->update('ucp_checkouts', $changes, ['public_id' => $id]);
    }

    /** Borra la sesión y su carrito (una creación que no ha podido añadir nada). */
    public static function delete(App $app, string $id): void
    {
        $app->db()->run('DELETE FROM {carts} WHERE token = ? AND order_id IS NULL', [hash('sha256', self::cartToken($id))]);
        $app->db()->delete('ucp_checkouts', ['public_id' => $id]);
    }

    private static function insertCart(App $app, string $raw): void
    {
        $now = $app->clock()->sql();
        $app->db()->insert('carts', [
            'token' => hash('sha256', $raw), 'customer_id' => null, 'lang' => $app->lang(), 'items' => '[]', 'source' => self::SOURCE,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
