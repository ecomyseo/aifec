<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Coupons;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Security\Random;

/**
 * Servicio «coupons»: códigos de descuento con sus límites.
 *
 *   $r = $app->service('coupons')->check('otoño', $cart);
 *   if ($r['ok']) { $discounts[] = $r['discount']; } else { $mensaje = $r['error']; }
 *
 * $cart = ['lines' => [['key','product_id','category_ids','unit_price','qty','on_sale']], 'items_total', 'customer_id', 'email'].
 * El descuento que devuelve check() tiene la forma que espera Aifec\Commerce\Totals:
 *   percent       ['kind' => 'percent', 'percent' => 1000, 'keys' => […], 'code' => 'OTOÑO', 'label' => 'Cupón OTOÑO']
 *   fixed         ['kind' => 'fixed', 'amount' => 500, …]
 *   free_shipping ['kind' => 'free_shipping', …]
 * «keys» solo va cuando el cupón no vale para todo el carrito (productos, categorías o sin artículos en oferta).
 *
 * Alcance: si el cupón limita a productos y a categorías, una línea entra si coincide con una cosa O con la otra.
 *
 * Los usos se cuentan con sentencia condicionada (redeem) y son idempotentes por pedido: canjear dos veces el mismo
 * pedido no suma, y liberar dos veces el mismo pedido no resta.
 */
final class Coupons
{
    public const KINDS = ['percent', 'fixed', 'free_shipping'];

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private App $app;

    /** @var bool|null ¿existe la tabla de pedidos? (el módulo de pedidos puede no estar cargado) */
    private ?bool $hasOrders = null;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** El código como se guarda y se busca: sin espacios alrededor y en mayúsculas. */
    public static function normalizeCode(string $code): string
    {
        $clean = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $code);
        if (!is_string($clean)) {
            $clean = trim($code);
        }

        return mb_strtoupper($clean, 'UTF-8');
    }

    // ------------------------------------------------------------------ comprobar

    /**
     * ¿Vale este código para este carrito?
     *
     * @param array<string,mixed> $cart
     *
     * @return array{ok:bool,error:string,coupon:array<string,mixed>|null,discount:array<string,mixed>|null}
     */
    public function check(string $code, array $cart): array
    {
        $normalized = self::normalizeCode($code);
        if ($normalized === '') {
            return $this->fail(t('Enter a coupon code'));
        }
        $db = $this->app->db();
        $row = $db->row('SELECT * FROM {coupons} WHERE code = ?', [$normalized]);
        if ($row === null || (int) $row['active'] !== 1) {
            // El mismo mensaje para «no existe» y «está apagado»: no se cuenta qué códigos hay.
            return $this->fail(t('This coupon code is not valid'));
        }
        $id = (int) $row['id'];

        $now = $this->app->clock()->now();
        $starts = Clock::parse(isset($row['starts_at']) ? (string) $row['starts_at'] : null);
        if ($starts > 0 && $now < $starts) {
            return $this->fail(t('This coupon is not valid yet. It starts on {date}.', ['date' => $this->date($starts)]), $row);
        }
        $ends = Clock::parse(isset($row['ends_at']) ? (string) $row['ends_at'] : null);
        if ($ends > 0 && $now > $ends) {
            return $this->fail(t('This coupon has expired'), $row);
        }
        if ((int) $row['max_uses'] > 0 && (int) $row['uses'] >= (int) $row['max_uses']) {
            return $this->fail(t('This coupon has reached its usage limit'), $row);
        }

        $customerId = isset($cart['customer_id']) && is_numeric($cart['customer_id']) && (int) $cart['customer_id'] > 0 ? (int) $cart['customer_id'] : null;
        $email = isset($cart['email']) && is_string($cart['email']) ? mb_strtolower(trim($cart['email']), 'UTF-8') : '';

        if ($row['customer_id'] !== null && (int) $row['customer_id'] > 0 && (int) $row['customer_id'] !== $customerId) {
            return $this->fail(t('This coupon is for another customer'), $row);
        }
        if ((int) $row['max_uses_per_customer'] > 0 && $this->usesBy($id, $customerId, $email) >= (int) $row['max_uses_per_customer']) {
            return $this->fail(t('You have already used this coupon the allowed number of times'), $row);
        }
        if ((int) $row['first_order_only'] === 1 && $this->hasEarlierOrders($customerId, $email)) {
            return $this->fail(t('This coupon is only valid for your first order'), $row);
        }

        $lines = $this->lines($cart);
        $itemsTotal = isset($cart['items_total']) && is_numeric($cart['items_total']) ? (int) $cart['items_total'] : $this->linesTotal($lines);
        if ((int) $row['min_subtotal'] > 0 && $itemsTotal < (int) $row['min_subtotal']) {
            return $this->fail(t('Add {amount} more to your order to use this coupon', ['amount' => $this->money((int) $row['min_subtotal'] - $itemsTotal)]), $row);
        }

        $scope = $this->scope($row, $lines);
        if ($scope['keys'] === []) {
            return $this->fail(t('This coupon does not apply to any item in your cart'), $row);
        }

        return ['ok' => true, 'error' => '', 'coupon' => $row, 'discount' => $this->discount($row, $scope)];
    }

    /**
     * De varios códigos, los que valen juntos y el motivo de los que no. Solo se suman si TODOS son combinables;
     * si no, se queda el primero que valga y se explica por qué se descarta el resto.
     *
     * @param array<int,mixed>    $codes
     * @param array<string,mixed> $cart
     *
     * @return array{accepted:array<int,array<string,mixed>>,rejected:array<int,array{code:string,error:string}>,discounts:array<int,array<string,mixed>>}
     */
    public function combine(array $codes, array $cart): array
    {
        $accepted = [];
        $rejected = [];
        $discounts = [];
        $seen = [];
        $allCombinable = true;
        foreach ($codes as $code) {
            $normalized = is_scalar($code) ? self::normalizeCode((string) $code) : '';
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $result = $this->check($normalized, $cart);
            if (!$result['ok'] || $result['coupon'] === null || $result['discount'] === null) {
                $rejected[] = ['code' => $normalized, 'error' => $result['error']];
                continue;
            }
            $combinable = (int) $result['coupon']['combinable'] === 1;
            if ($accepted !== [] && !($combinable && $allCombinable)) {
                $rejected[] = ['code' => $normalized, 'error' => t('This coupon cannot be combined with other coupons')];
                continue;
            }
            $allCombinable = $allCombinable && $combinable;
            $accepted[] = $result;
            $discounts[] = $result['discount'];
        }

        return ['accepted' => $accepted, 'rejected' => $rejected, 'discounts' => $discounts];
    }

    // ------------------------------------------------------------------ canjear

    /**
     * Suma un uso. Falso si el cupón se ha agotado (nunca pasa de max_uses, ni con dos peticiones a la vez).
     * Canjear dos veces el mismo pedido no suma otra vez y devuelve verdadero.
     */
    public function redeem(int $couponId, int $orderId, ?int $customerId, string $email, int $amount): bool
    {
        $db = $this->app->db();
        $email = mb_strtolower(trim($email), 'UTF-8');
        $now = $this->app->clock()->sql();

        return (bool) $db->transaction(static function ($db) use ($couponId, $orderId, $customerId, $email, $amount, $now): bool {
            if ($db->value('SELECT id FROM {coupon_uses} WHERE coupon_id = ? AND order_id = ?', [$couponId, $orderId]) !== null) {
                return true;
            }
            // Solo el que consigue cambiar la fila se queda con el uso.
            if ($db->run('UPDATE {coupons} SET uses = uses + 1 WHERE id = ? AND (max_uses = 0 OR uses < max_uses)', [$couponId]) !== 1) {
                return false;
            }
            $inserted = $db->insertIgnore('coupon_uses', [
                'coupon_id' => $couponId,
                'order_id' => $orderId,
                'customer_id' => $customerId !== null && $customerId > 0 ? $customerId : null,
                'email' => $email !== '' ? $email : null,
                'amount' => max(0, $amount),
                'created_at' => $now,
            ]);
            if (!$inserted) {
                // Otra petición canjeó este mismo pedido un instante antes: se deshace la suma.
                $db->run('UPDATE {coupons} SET uses = uses - 1 WHERE id = ? AND uses > 0', [$couponId]);
            }

            return true;
        });
    }

    /** Un pedido cancelado devuelve sus usos. Se puede llamar más de una vez: solo resta lo que de verdad borra. */
    public function release(int $orderId): void
    {
        $db = $this->app->db();
        $db->transaction(static function ($db) use ($orderId): void {
            foreach ($db->select('SELECT id, coupon_id FROM {coupon_uses} WHERE order_id = ?', [$orderId]) as $use) {
                if ($db->run('DELETE FROM {coupon_uses} WHERE id = ?', [(int) $use['id']]) === 1) {
                    $db->run('UPDATE {coupons} SET uses = uses - 1 WHERE id = ? AND uses > 0', [(int) $use['coupon_id']]);
                }
            }
        });
    }

    // ------------------------------------------------------------------ utilidades

    /** Un código libre al azar, sin letras que se confundan (O, I, 0, 1): «K7QM-2XHD». */
    public function generateCode(): string
    {
        $db = $this->app->db();
        for ($i = 0; $i < 20; ++$i) {
            $code = Random::pick(self::CODE_ALPHABET, 4) . '-' . Random::pick(self::CODE_ALPHABET, 4);
            if ($db->value('SELECT id FROM {coupons} WHERE code = ?', [$code]) === null) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not find a free coupon code');
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,mixed>|null $coupon
     *
     * @return array{ok:bool,error:string,coupon:array<string,mixed>|null,discount:null}
     */
    private function fail(string $error, ?array $coupon = null): array
    {
        return ['ok' => false, 'error' => $error, 'coupon' => $coupon, 'discount' => null];
    }

    /**
     * El descuento con la forma de Totals.
     *
     * @param array<string,mixed>                            $row
     * @param array{restricted:bool,keys:array<int,string>} $scope
     *
     * @return array<string,mixed>
     */
    private function discount(array $row, array $scope): array
    {
        $kind = (string) $row['kind'];
        if ($kind === 'percent') {
            $discount = ['kind' => 'percent', 'percent' => (int) $row['percent']];
        } elseif ($kind === 'fixed') {
            $discount = ['kind' => 'fixed', 'amount' => (int) $row['amount']];
        } else {
            return ['kind' => 'free_shipping', 'code' => (string) $row['code'], 'label' => t('Coupon {code}', ['code' => (string) $row['code']])];
        }
        if ($scope['restricted']) {
            $discount['keys'] = $scope['keys'];
        }
        $discount['code'] = (string) $row['code'];
        $discount['label'] = t('Coupon {code}', ['code' => (string) $row['code']]);

        return $discount;
    }

    /**
     * Qué líneas del carrito entran en el cupón.
     *
     * @param array<string,mixed>                $row
     * @param array<int,array<string,mixed>>     $lines
     *
     * @return array{restricted:bool,keys:array<int,string>}
     */
    private function scope(array $row, array $lines): array
    {
        $products = self::ids(isset($row['products']) ? $row['products'] : null);
        $categories = self::ids(isset($row['categories']) ? $row['categories'] : null);
        $excludeSale = (int) $row['exclude_sale'] === 1;
        $keys = [];
        foreach ($lines as $line) {
            if ($line['qty'] <= 0 || $line['unit_price'] <= 0) {
                continue;
            }
            if ($excludeSale && $line['on_sale']) {
                continue;
            }
            if ($products !== [] || $categories !== []) {
                $inside = in_array($line['product_id'], $products, true) || array_intersect($line['category_ids'], $categories) !== [];
                if (!$inside) {
                    continue;
                }
            }
            $keys[] = $line['key'];
        }

        return ['restricted' => $products !== [] || $categories !== [] || $excludeSale, 'keys' => $keys];
    }

    /**
     * @param array<string,mixed> $cart
     *
     * @return array<int,array{key:string,product_id:int,category_ids:array<int,int>,unit_price:int,qty:int,on_sale:bool}>
     */
    private function lines(array $cart): array
    {
        $out = [];
        $raw = isset($cart['lines']) && is_array($cart['lines']) ? $cart['lines'] : [];
        foreach ($raw as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $categories = [];
            foreach (isset($line['category_ids']) && is_array($line['category_ids']) ? $line['category_ids'] : [] as $categoryId) {
                if (is_numeric($categoryId) && (int) $categoryId > 0) {
                    $categories[] = (int) $categoryId;
                }
            }
            $out[] = [
                'key' => isset($line['key']) ? (string) $line['key'] : 'l' . $index,
                'product_id' => isset($line['product_id']) && is_numeric($line['product_id']) ? (int) $line['product_id'] : 0,
                'category_ids' => $categories,
                'unit_price' => isset($line['unit_price']) && is_numeric($line['unit_price']) ? (int) $line['unit_price'] : 0,
                'qty' => isset($line['qty']) && is_numeric($line['qty']) ? (int) $line['qty'] : 1,
                'on_sale' => !empty($line['on_sale']),
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     */
    private function linesTotal(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += max(0, $line['unit_price']) * max(0, $line['qty']);
        }

        return $total;
    }

    /**
     * Lista de identificadores guardada como JSON.
     *
     * @param mixed $json
     *
     * @return array<int,int>
     */
    public static function ids($json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : (is_array($json) ? $json : null);
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $out[(int) $id] = (int) $id;
            }
        }

        return array_values($out);
    }

    /** Usos de este cupón por esta persona: por identificador de cliente o por correo. */
    private function usesBy(int $couponId, ?int $customerId, string $email): int
    {
        $conditions = [];
        $params = [$couponId];
        if ($customerId !== null) {
            $conditions[] = 'customer_id = ?';
            $params[] = $customerId;
        }
        if ($email !== '') {
            $conditions[] = 'email = ?';
            $params[] = $email;
        }
        if ($conditions === []) {
            return 0;
        }

        return (int) $this->app->db()->value('SELECT COUNT(*) FROM {coupon_uses} WHERE coupon_id = ? AND (' . implode(' OR ', $conditions) . ')', $params);
    }

    /** ¿Ya ha comprado esta persona? Usos de cualquier cupón o, si hay pedidos, algún pedido pagado. */
    private function hasEarlierOrders(?int $customerId, string $email): bool
    {
        $conditions = [];
        $params = [];
        if ($customerId !== null) {
            $conditions[] = 'customer_id = ?';
            $params[] = $customerId;
        }
        if ($email !== '') {
            $conditions[] = 'email = ?';
            $params[] = $email;
        }
        if ($conditions === []) {
            // Sin cliente ni correo no se puede saber todavía: se vuelve a comprobar cuando se sepa.
            return false;
        }
        $db = $this->app->db();
        if ((int) $db->value('SELECT COUNT(*) FROM {coupon_uses} WHERE ' . implode(' OR ', $conditions), $params) > 0) {
            return true;
        }
        if ($this->hasOrders === null) {
            $this->hasOrders = $db->tableExists('orders') && count(array_intersect(['customer_id', 'email', 'payment_status'], $db->columns('orders'))) === 3;
        }

        return $this->hasOrders
            && (int) $db->value("SELECT COUNT(*) FROM {orders} WHERE payment_status = 'paid' AND (" . implode(' OR ', $conditions) . ')', $params) > 0;
    }

    private function money(int $cents): string
    {
        return $this->app->i18n()->money($cents, $this->app->settings()->str('shop.currency', 'EUR'));
    }

    private function date(int $timestamp): string
    {
        return $this->app->i18n()->date($timestamp, 'medium', $this->app->site()->timezone());
    }
}
