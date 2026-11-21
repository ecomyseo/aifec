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

    /** Protege una sesión antigua si ya existía un intento delegado no conciliado. */
    public static function legacyPaymentLocked(App $app, string $checkout): bool
    {
        $scope = 'acpspt_' . hash('sha256', $app->url()->origin() . '|' . $checkout) . '_';
        return $app->db()->value("SELECT id FROM {order_payments} WHERE method = ? AND kind = ? AND reference LIKE ? ESCAPE '!' AND status IN (?, ?) LIMIT 1",
            ['acp_stripe_spt', 'acp_attempt', str_replace('_', '!_', $scope) . '%', 'pending', 'paid']) !== null;
    }

    public static function newId(): string
    {
        return 'chk_' . Random::token(16);
    }

    public static function valid(string $id): bool
    {
        return preg_match('/^chk_[a-f0-9]{32}$/D', $id) === 1;
    }

    /** La ficha del carrito de la sesión (la que iría en la cookie). */
    public static function cartToken(App $app, string $id): string
    {
        $legacy = substr(hash('sha256', 'aifec-ucp-cart|' . $id), 0, 48);
        $previous = $app->db()->value('SELECT continue_hash FROM {ucp_checkouts} WHERE public_id = ?', [$id]);
        if (is_string($previous) && hash_equals($previous, hash('sha256', $legacy))) {
            return $legacy;
        }
        return substr(hash_hmac('sha256', 'aifec-ucp-cart|' . $id, $app->site()->secret()), 0, 48);
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

    /** Consulta el pedido nativo del mismo carrito. Un intento pendiente no completa el protocolo. */
    public static function refreshOrder(App $app, array $row): array
    {
        if ($row['status'] !== self::OPEN || $row['snapshot'] === null) {
            return $row;
        }
        $order = $app->db()->row('SELECT o.* FROM {carts} c JOIN {orders} o ON o.id = c.order_id WHERE c.token = ?', [$row['continue_hash']]);
        if ($order === null || $order['payment_status'] !== 'paid') {
            return $row;
        }
        $snapshot = json_decode((string) $row['snapshot'], true);
        $snapshot['currency'] = (string) $order['currency'];
        $snapshot['line_items'] = [];
        $items = $app->db()->select("SELECT id, product_id, variant_id, name, qty, unit_price, line_total FROM {order_items} WHERE order_id = ? AND kind = 'product' ORDER BY position, id", [(int) $order['id']]);
        foreach ($items as $item) {
            $product = (string) $item['product_id'] . ((int) $item['variant_id'] > 0 ? '-' . $item['variant_id'] : '');
            $snapshot['line_items'][] = [
                'id' => 'line_' . $item['id'], 'item' => ['id' => $product, 'title' => (string) $item['name'], 'price' => (int) $item['unit_price']],
                'quantity' => (int) $item['qty'], 'totals' => [['type' => 'total', 'amount' => (int) $item['line_total']]],
            ];
        }
        $snapshot['totals'] = [['type' => 'subtotal', 'amount' => (int) $order['items_total'],
            'display_text' => $order['prices_include_tax'] ? t('Subtotal (including taxes)') : t('Subtotal (excluding taxes)')]];
        foreach (['discount' => -(int) $order['discount_total'], 'fulfillment' => (int) $order['shipping_total'],
            'fee' => (int) $order['fee_total'], 'tax' => $order['prices_include_tax'] ? 0 : (int) $order['total_tax']] as $type => $amount) {
            if ($amount !== 0) { $snapshot['totals'][] = ['type' => $type, 'amount' => $amount]; }
        }
        $snapshot['totals'][] = ['type' => 'total', 'amount' => (int) $order['total']];
        unset($snapshot['fulfillment']);
        $changes = [
            'status' => self::COMPLETED,
            'order_id' => (int) $order['id'],
            'order_number' => (string) $order['number'],
            'order_url' => Ucp::absolute($app, $app->url()->route('order.thanks', ['number' => (string) $order['number'], 'secret' => $app->service('orders')->viewTokenOf((string) $order['number'])])),
            'snapshot' => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        self::update($app, (string) $row['public_id'], $changes);
        return array_replace($row, $changes);
    }

    /**
     * Crea la sesión y su carrito vacío.
     *
     * @return array<string,mixed>
     */
    public static function create(App $app, string $platform, ?int $keyId, string $kind = 'checkout'): array
    {
        $id = self::newId();
        $now = $app->clock()->sql();
        $app->db()->insert('ucp_checkouts', [
            'public_id' => $id, 'status' => self::OPEN, 'kind' => $kind, 'continue_hash' => hash('sha256', self::cartToken($app, $id)), 'platform' => $platform !== '' ? $platform : null, 'key_id' => $keyId,
            'order_id' => null, 'order_number' => null, 'order_url' => null, 'buyer' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::insertCart($app, self::cartToken($app, $id));
        $row = self::find($app, $id);
        if ($row === null) {
            throw new \RuntimeException('The UCP checkout could not be saved');
        }

        return $row;
    }

    /** Pone el carrito de la sesión en la petición; si ya no existe (se vació), lo vuelve a crear vacío. */
    public static function bind(App $app, string $id): string
    {
        $raw = self::cartToken($app, $id);
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
        $raw = self::cartToken($app, $id);
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
        $hash = hash('sha256', self::cartToken($app, $id));
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
        $app->db()->run('DELETE FROM {carts} WHERE token = ? AND order_id IS NULL', [hash('sha256', self::cartToken($app, $id))]);
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
