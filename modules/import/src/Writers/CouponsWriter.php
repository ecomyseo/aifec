<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Modules\Coupons\Coupons;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * Los cupones del origen. Un cupón entra con sus usos ya hechos (así el límite de usos sigue valiendo) y sin tocar a nadie.
 *
 * La regla de oro: un cupón nunca es MÁS generoso aquí que en el origen. Si trae una restricción que aquí no se puede poner
 * (excluir productos, limitar a unos correos, un máximo de gasto, un solo transportista…), o si una lista de productos,
 * categorías o el cliente al que iba dirigido no se trajo y la restricción desaparecería, entra INACTIVO, con una nota y en
 * «revisa a mano». Si solo se pierde una facilidad (un regalo, el envío gratis a la vez que el descuento), entra activo con una nota.
 *
 * Un código que ya existe aquí y no vino de este origen choca: no se toca.
 */
final class CouponsWriter extends WriterBase
{
    /** Lo que se pierde sin hacer el cupón más generoso: una facilidad menos, o nada. */
    private const HARMLESS = ['gift', 'free_shipping_with_discount', 'free shipping together with a discount', 'combinations', 'automatic', 'no_discount'];

    private const KINDS = ['percent' => 'percent', 'amount' => 'fixed', 'free_shipping' => 'free_shipping'];

    public function type(): string
    {
        return 'coupons';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $code = Coupons::normalizeCode((string) $record['code']);
        if ($code === '' || mb_strlen($code, 'UTF-8') > 40) {
            return $this->result(self::SKIPPED, null, 'The coupon code is empty or longer than 40 letters');
        }
        $found = $this->resolve('coupons', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('coupons', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        $same = $db->row('SELECT id FROM {coupons} WHERE code = ?', [$code]);
        if ($same !== null && ($row === null || (int) $same['id'] !== (int) $row['id'])) {
            return $this->result(self::CONFLICT, (int) $same['id'], 'A coupon with this code already exists here and was left as it is');
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $review = [];
        $kind = isset($record['kind']) && is_string($record['kind']) && isset(self::KINDS[$record['kind']]) ? self::KINDS[$record['kind']] : 'percent';
        $amount = 0;
        if ($kind === 'fixed') {
            $includes = !empty($record['amount_includes_tax']);
            $amount = $this->price(isset($record['amount']) ? (int) $record['amount'] : 0, $includes, null, $ctx);
        }
        $products = $this->references($record, 'product_ids', 'products', $ctx, $review);
        $categories = $this->references($record, 'category_ids', 'categories', $ctx, $review);
        $customerId = null;
        if (isset($record['customer_id']) && is_string($record['customer_id']) && $record['customer_id'] !== '') {
            $customerId = $ctx->localId('customers', $record['customer_id']);
            if ($customerId === null || $db->value('SELECT id FROM {customers} WHERE id = ?', [$customerId]) === null) {
                $customerId = null;
                $review[] = 'the customer the coupon was meant for was not brought';
            }
        }
        foreach (isset($record['unsupported']) && is_array($record['unsupported']) ? $record['unsupported'] : [] as $item) {
            if (is_string($item) && $item !== '' && !in_array($item, self::HARMLESS, true)) {
                $review[] = 'it has a restriction that cannot be set here («' . $this->cut($item, 80) . '»)';
            } elseif (is_string($item) && $item !== '') {
                $ctx->note('coupons', $id, 'note', 'The coupon lost a feature that does not exist here («' . $this->cut($item, 80) . '»)');
            }
        }
        $active = !(isset($record['active']) && $record['active'] === false);
        if ($review !== []) {
            if ($active) {
                $ctx->note('coupons', $id, 'note', 'The coupon was brought inactive, because here it would be more generous than in the source: ' . implode('; ', $review));
                $ctx->unmapped('review', 'Coupon brought inactive', 1, 'The coupon would be more generous here than in the source: check it before switching it on');
            }
            $active = false;
        }

        $now = $this->now();
        $uses = max(0, isset($record['uses']) ? (int) $record['uses'] : 0);
        $note = isset($record['note']) && is_string($record['note']) ? $this->cut($record['note'], 191) : '';
        $data = [
            'code' => $code, 'kind' => $kind, 'percent' => $kind === 'percent' && isset($record['percent']) ? max(0, (int) $record['percent']) : 0, 'amount' => $amount,
            'min_subtotal' => isset($record['min_subtotal']) && is_int($record['min_subtotal']) ? max(0, $record['min_subtotal']) : 0,
            'starts_at' => $this->sqlDate(isset($record['starts_at']) ? $record['starts_at'] : null), 'ends_at' => $this->sqlDate(isset($record['ends_at']) ? $record['ends_at'] : null),
            'max_uses' => isset($record['max_uses']) && is_int($record['max_uses']) ? max(0, $record['max_uses']) : 0,
            'max_uses_per_customer' => isset($record['max_uses_per_customer']) && is_int($record['max_uses_per_customer']) ? max(0, $record['max_uses_per_customer']) : 0,
            'first_order_only' => !empty($record['first_order_only']) ? 1 : 0, 'exclude_sale' => !empty($record['exclude_sale']) ? 1 : 0,
            'combinable' => !empty($record['combinable']) ? 1 : 0, 'customer_id' => $customerId,
            'products' => $products !== [] ? json_encode($products) : null, 'categories' => $categories !== [] ? json_encode($categories) : null,
            'note' => $note !== '' ? $note : null, 'active' => $active ? 1 : 0, 'updated_at' => $now,
        ];
        if ($row === null) {
            $localId = $db->insert('coupons', $data + ['uses' => $uses, 'created_at' => $now]);
        } else {
            $localId = (int) $row['id'];
            // Los usos que se hayan canjeado aquí desde la última vez no se pierden.
            $db->update('coupons', $data + ['uses' => max($uses, (int) $row['uses'])], ['id' => $localId]);
        }
        $ctx->remember('coupons', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    public function checksums(WriteContext $ctx): array
    {
        return ['coupons_count' => $this->mappedCount('coupons', 'coupons')];
    }

    /**
     * Los identificadores de aquí de una lista del origen. Si no queda ninguno de una lista que tenía, la restricción
     * desaparecería: se apunta en $review.
     *
     * @param array<string,mixed> $record
     * @param string[]            $review
     *
     * @return int[]
     */
    private function references(array $record, string $field, string $type, WriteContext $ctx, array &$review): array
    {
        $wanted = isset($record[$field]) && is_array($record[$field]) ? $record[$field] : [];
        $table = $type === 'products' ? 'products' : 'categories';
        $out = [];
        foreach ($wanted as $source) {
            $local = is_string($source) ? $ctx->localId($type, $source) : null;
            if ($local !== null && $this->exists($table, ['id' => $local])) {
                $out[$local] = $local;
            }
        }
        if ($wanted !== [] && $out === []) {
            $review[] = 'none of the ' . ($type === 'products' ? 'products' : 'categories') . ' it was limited to were brought';
        } elseif (count($out) < count($wanted)) {
            $ctx->note('coupons', (string) $record['id'], 'note', 'Some of the ' . ($type === 'products' ? 'products' : 'categories') . ' the coupon was limited to were not brought');
        }

        return array_values($out);
    }
}
