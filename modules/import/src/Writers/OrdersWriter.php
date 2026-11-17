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

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;
use Aifec\Modules\Invoices\InvoiceData;
use Aifec\Modules\Orders\OrderLog;
use Aifec\Modules\Orders\Orders;

/**
 * Los pedidos del origen: historia, no ventas de hoy. Se escriben por la capa de datos, NUNCA por Orders::place(), markPaid() ni
 * setStatus(): no se llama a nada que avise (ni «pedido creado», ni «pagado», ni «enviado»), no se manda un correo, no se piden
 * opiniones, no se descuentan existencias, no se conceden licencias ni descargas.
 *
 * Lo importado es histórico:
 *  - lleva las fechas del origen (colocado, pagado, enviado…): el informe de ventas de hoy, que cuenta por la fecha de cobro, no lo ve;
 *  - sus existencias no se tocan jamás: el historial lleva la marca «existencias devueltas», así que cancelarlo o reembolsarlo después
 *    desde el panel no suma unidades que nunca se restaron;
 *  - un pedido que en el origen estaba pendiente entra «en espera» (on_hold), nunca «pendiente de pago»: la tarea que cancela los
 *    pedidos de pasarela sin pagar solo toca los pendientes y no puede cancelarle uno al comerciante;
 *  - no se crea ninguna petición de opinión (se anotan al completarse un pedido por el servicio, que aquí no se usa).
 *
 * Al céntimo: los importes se guardan tal cual vienen; no se recalcula nada. Si las líneas no suman el total del origen, el pedido
 * entra igual con una línea de ajuste con nombre por la diferencia y una nota. Las líneas, los pagos, las devoluciones y el historial
 * llevan lo que dice el origen; lo que el origen no sabe (el significado de un estado) se decide por la equivalencia elegida o por
 * una tabla fija a los estados de serie de aquí.
 */
final class OrdersWriter extends WriterBase
{
    /** Estado de aquí por el significado del estado del origen. «Pendiente» entra en espera: ver arriba. */
    private const MEANING = [
        'pending' => 'on_hold', 'on_hold' => 'on_hold', 'paid' => 'processing', 'processing' => 'processing', 'shipped' => 'shipped',
        'delivered' => 'completed', 'cancelled' => 'cancelled', 'refunded' => 'refunded', 'failed' => 'failed',
    ];

    /** Lo que llevan las filas que escribe este escritor, para volver a escribirlas sin tocar las que se añadieron aquí. */
    private const MARK = '{"import":true}';

    /** Marca de la línea de ajuste que añade este escritor (sin guion bajo: no se cuenta como línea del origen). */
    private const ADJUST = 'importadjust';

    public function type(): string
    {
        return 'orders';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $email = $this->cut(strtolower(trim((string) $record['email'])), 191);
        if ($email === '') {
            return $this->result(self::SKIPPED, null, 'The order has no email address');
        }
        $found = $this->resolve('orders', $record, $ctx, static function (int $localId) use ($db): ?array {
            return $db->find('orders', ['id' => $localId]);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        list($number, $why) = $this->number($record, $row, $ctx);
        if ($number === null) {
            return $this->result(self::CONFLICT, null, $why);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }

        $now = $this->now();
        $oldCustomer = $row !== null && $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
        $customerId = $this->customer($record, $ctx, $email, $now);
        $meaning = (string) $record['state_meaning'];
        $status = $this->status($record, $ctx, $meaning);
        $total = (int) $record['total'];
        $paidTotal = (int) $record['paid_total'];
        $refundedTotal = isset($record['refunded_total']) ? (int) $record['refunded_total'] : 0;
        $lines = $this->lines($record, $ctx, $id);
        $placed = $this->sqlDate(isset($record['placed_at']) ? $record['placed_at'] : null) ?: $now;
        $paidAt = $this->sqlDate(isset($record['paid_at']) ? $record['paid_at'] : null);
        if ($paidAt === null && $paidTotal > 0) {
            $paidAt = $placed;
        }
        if ($lines['adjustment'] !== null) {
            $ctx->note('orders', $id, 'note', 'The lines of the order did not add up to its total: a price adjustment line of ' . $lines['adjustment']['amount'] . ' cents was added so that they do');
            $ctx->unmapped('review', 'Order whose lines did not add up', 1, 'A price adjustment line was added: check the order by hand');
        }
        $coupons = $this->couponSummary($record);
        $invoices = $this->invoiceList($record);
        $data = [
            'number' => $number, 'customer_id' => $customerId, 'email' => $email, 'lang' => $this->lang($record, $ctx),
            'currency' => $this->cut(strtoupper(trim((string) $record['currency'])), 3) ?: 'EUR', 'status' => $status,
            'payment_status' => $this->paymentStatus($meaning, $total, $paidTotal, $refundedTotal),
            'fulfillment_status' => $this->fulfillment($lines['rows'], $status, $this->sqlDate(isset($record['shipped_at']) ? $record['shipped_at'] : null)),
            'payment_method' => $this->paymentMethod($record, $ctx), 'payment_title' => $this->nullable(isset($record['payment_title']) ? $record['payment_title'] : '', 120),
            'carrier_id' => $this->carrier($record, $ctx), 'carrier_title' => $this->nullable(isset($record['carrier_title']) ? $record['carrier_title'] : '', 120),
            'tracking_code' => $this->nullable(isset($record['tracking_code']) ? $record['tracking_code'] : '', 80),
            'prices_include_tax' => !empty($record['prices_include_tax']) ? 1 : 0, 'items_total' => (int) $record['items_total'],
            'discount_total' => (int) $record['discount_total'], 'shipping_total' => (int) $record['shipping_total'],
            'fee_total' => isset($record['fee_total']) ? (int) $record['fee_total'] : 0, 'total_net' => (int) $record['total_net'],
            'total_tax' => (int) $record['total_tax'], 'total' => $total, 'paid_total' => $paidTotal, 'refunded_total' => $refundedTotal,
            'taxes' => OrderLog::json($this->taxes($record)),
            'adjustments' => OrderLog::json($lines['adjustment'] !== null ? [$lines['adjustment']] : []),
            'billing' => OrderLog::json($this->address(isset($record['billing']) ? $record['billing'] : null)),
            'shipping' => OrderLog::json($this->address(isset($record['shipping']) ? $record['shipping'] : null)),
            'coupons' => OrderLog::json($coupons['summary']), 'customer_note' => $this->note($record),
            'source' => 'import', 'attribution' => OrderLog::json(['import' => ['source' => $ctx->source(), 'legacy_id' => $id, 'state' => (string) $record['state'], 'invoices' => $invoices]]),
            'secret' => hash('sha256', Orders::viewToken($this->app, $number)), 'cart_id' => null, 'test' => 0, 'ip' => null, 'legacy_id' => $this->cut($id, 60),
            'placed_at' => $placed, 'paid_at' => $paidAt, 'shipped_at' => $this->sqlDate(isset($record['shipped_at']) ? $record['shipped_at'] : null),
            'completed_at' => $this->sqlDate(isset($record['completed_at']) ? $record['completed_at'] : null),
            'cancelled_at' => $this->sqlDate(isset($record['cancelled_at']) ? $record['cancelled_at'] : null), 'updated_at' => $now,
        ];
        if ($row === null) {
            $localId = $db->insert('orders', $data + ['created_at' => $placed]);
        } else {
            $localId = (int) $row['id'];
            $this->clearChildren($localId);
            $db->update('orders', $data, ['id' => $localId]);
        }
        $itemIds = $this->insertItems($localId, $lines['rows']);
        $this->insertPayments($localId, $record, $number, $placed, $ctx);
        $this->insertRefunds($localId, $record, $itemIds, $placed);
        $this->insertHistory($localId, $record, $status, $placed, $ctx);
        $this->insertCouponUses($localId, $record, $customerId, $email, $placed, $ctx);
        $this->storeInvoices($localId, $record, $placed, $ctx);
        foreach (array_unique(array_filter([$customerId, $oldCustomer])) as $customer) {
            $this->recount((int) $customer);
        }
        $this->app->invalidate(['orders']);
        $ctx->remember('orders', $id, $localId, Neutral::hash($record));

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    public function checksums(WriteContext $ctx): array
    {
        $db = $this->db();

        return [
            'orders_count' => $this->mappedCount('orders', 'orders'),
            'orders_total' => (int) $db->value("SELECT COALESCE(SUM(o.total), 0) FROM {import_map} m JOIN {orders} o ON o.id = m.local_id WHERE m.type = 'orders'"),
            'order_items_count' => (int) $db->value(
                "SELECT COUNT(*) FROM {import_map} m JOIN {order_items} i ON i.order_id = m.local_id WHERE m.type = 'orders' AND (i.data IS NULL OR i.data NOT LIKE ?)",
                ['%' . self::ADJUST . '%']
            ),
        ];
    }

    // ------------------------------------------------------------------ número, estado y cliente

    /**
     * El número del pedido: el del origen, o con el prefijo de la opción «order_prefix» (o del ajuste import.order_prefix) si ya
     * hay uno igual aquí. Sin prefijo no entra: choca.
     *
     * @param array<string,mixed>      $record
     * @param array<string,mixed>|null $row
     *
     * @return array{0:?string,1:string} el número (null = no entra) y el motivo
     */
    private function number(array $record, ?array $row, WriteContext $ctx): array
    {
        $db = $this->db();
        $base = $this->cut((string) $record['number'], 32);
        $own = $row !== null ? (int) $row['id'] : 0;
        $holder = $db->value('SELECT id FROM {orders} WHERE number = ?', [$base]);
        if ($holder === null || (int) $holder === $own) {
            return [$base, ''];
        }
        $prefix = $ctx->option('order_prefix', null);
        if (!is_string($prefix)) {
            $prefix = $this->app->settings()->str('import.order_prefix', '');
        }
        $prefix = trim($prefix);
        if ($prefix === '') {
            return [null, 'The order number «' . $base . '» already exists here. Set the order prefix option (order_prefix) to bring it with another number'];
        }
        $prefixed = $this->cut($prefix . $base, 32);
        $holder = $db->value('SELECT id FROM {orders} WHERE number = ?', [$prefixed]);
        if ($holder === null || (int) $holder === $own) {
            $this->noteOnce($ctx, 'prefix', 'The order number «' . $base . '» already existed here: it was brought as «' . $prefixed . '»');

            return [$prefixed, ''];
        }

        return [null, 'The order numbers «' . $base . '» and «' . $prefixed . '» already exist here'];
    }

    /**
     * El estado de aquí: la equivalencia elegida (si ese estado existe) o la tabla fija por el significado. Nunca «pendiente de
     * pago»: ver la explicación de la clase.
     *
     * @param array<string,mixed> $record
     */
    private function status(array $record, WriteContext $ctx, string $meaning): string
    {
        $db = $this->db();
        $code = $ctx->equivalent('order_state', (string) $record['state']);
        if ($code !== null && $db->value('SELECT code FROM {order_states} WHERE code = ?', [$code]) === null) {
            $this->noteOnce($ctx, 'state', 'The state chosen for «' . $this->cut((string) $record['state'], 40) . '» does not exist here: its meaning was used instead');
            $code = null;
        }
        if ($code === null) {
            $code = isset(self::MEANING[$meaning]) ? self::MEANING[$meaning] : 'on_hold';
        }
        if ($code === 'pending_payment') {
            $code = 'on_hold';
            $this->noteOnce($ctx, 'hold', 'A pending order was brought on hold, so the automatic cancellation of unpaid orders does not touch it');
        } elseif ($meaning === 'pending' && $code === 'on_hold') {
            $this->noteOnce($ctx, 'hold', 'A pending order was brought on hold, so the automatic cancellation of unpaid orders does not touch it');
        }

        return $code;
    }

    /**
     * El estado del cobro, coherente con el estado del pedido y con lo cobrado y devuelto.
     */
    private function paymentStatus(string $meaning, int $total, int $paid, int $refunded): string
    {
        if ($meaning === 'refunded') {
            return 'refunded';
        }
        if ($meaning === 'failed') {
            return 'failed';
        }
        if ($paid > 0) {
            if ($refunded >= $paid) {
                return 'refunded';
            }

            return $refunded > 0 ? 'partially_refunded' : 'paid';
        }
        if ($meaning === 'cancelled') {
            return 'cancelled';
        }
        if ($total <= 0 && in_array($meaning, ['paid', 'processing', 'shipped', 'delivered'], true)) {
            return 'paid';
        }

        return 'pending';
    }

    /**
     * El envío del pedido: nada que enviar (solo digitales), enviado, devuelto o por preparar.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function fulfillment(array $rows, string $status, ?string $shippedAt): string
    {
        $physical = false;
        foreach ($rows as $row) {
            if ($row['kind'] === 'shipping' || ($row['kind'] === 'product' && empty($row['is_digital']))) {
                $physical = true;
            }
        }
        if (!$physical) {
            return 'not_needed';
        }
        if ($status === 'refunded' && $shippedAt !== null) {
            return 'returned';
        }

        return in_array($status, ['shipped', 'completed'], true) ? 'fulfilled' : 'unfulfilled';
    }

    /**
     * El cliente del pedido: el que casa por pareja, el que ya tiene ese correo o, si no hay ninguno, un cliente invitado (como en
     * una compra sin cuenta). Todo por la capa de datos.
     *
     * @param array<string,mixed> $record
     */
    private function customer(array $record, WriteContext $ctx, string $email, string $now): ?int
    {
        $db = $this->db();
        $source = isset($record['customer_id']) && is_string($record['customer_id']) ? $record['customer_id'] : '';
        if ($source !== '') {
            $local = $ctx->localId('customers', $source);
            if ($local !== null && $db->value('SELECT id FROM {customers} WHERE id = ?', [$local]) !== null) {
                return $local;
            }
            $this->noteOnce($ctx, 'customer', 'The customer of the order was not brought: the order was linked to the customer with its email address');
        }
        $byEmail = $db->value('SELECT id FROM {customers} WHERE email = ?', [$email]);
        if ($byEmail !== null) {
            return (int) $byEmail;
        }
        $billing = isset($record['billing']) && is_array($record['billing']) ? $record['billing'] : [];
        $default = $db->value('SELECT id FROM {customer_groups} WHERE is_default = 1');
        $placed = $this->sqlDate(isset($record['placed_at']) ? $record['placed_at'] : null) ?: $now;
        $this->noteOnce($ctx, 'guest', 'The order has no customer in the source: a guest customer was created for its email address');

        return $db->insert('customers', [
            'email' => $email, 'password' => null, 'guest' => 1, 'first_name' => $this->nullable(isset($billing['first_name']) ? $billing['first_name'] : '', 80),
            'last_name' => $this->nullable(isset($billing['last_name']) ? $billing['last_name'] : '', 120), 'phone' => $this->nullable(isset($billing['phone']) ? $billing['phone'] : '', 40),
            'group_id' => $default !== null ? (int) $default : null, 'locale' => $this->lang($record, $ctx), 'active' => 1, 'marketing' => 0, 'orders_count' => 0,
            'total_spent' => 0, 'source' => $this->cut($ctx->source(), 40), 'legacy_id' => null, 'created_at' => $placed, 'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $record
     */
    private function lang(array $record, WriteContext $ctx): string
    {
        $locale = isset($record['locale']) && is_string($record['locale']) ? $this->shortLang($record['locale']) : '';

        return in_array($locale, $ctx->languages(), true) ? $locale : $this->app->defaultLang();
    }

    /**
     * @param array<string,mixed> $record
     */
    private function paymentMethod(array $record, WriteContext $ctx): ?string
    {
        $code = isset($record['payment_method']) && is_string($record['payment_method']) ? trim($record['payment_method']) : '';
        if ($code === '') {
            return null;
        }
        $mapped = $ctx->equivalent('payment_method', $code);

        return $this->cut(strtolower($mapped !== null ? $mapped : $code), 40);
    }

    /**
     * @param array<string,mixed> $record
     */
    private function carrier(array $record, WriteContext $ctx): ?int
    {
        if (!isset($record['carrier_id']) || !is_string($record['carrier_id']) || $record['carrier_id'] === '') {
            return null;
        }
        $local = $ctx->localId('carriers', $record['carrier_id']);

        return $local !== null && $this->exists('carriers', ['id' => $local]) ? $local : null;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function note(array $record): ?string
    {
        $note = isset($record['customer_note']) && is_string($record['customer_note']) ? trim($record['customer_note']) : '';

        return $note !== '' ? mb_substr($note, 0, 2000, 'UTF-8') : null;
    }

    /**
     * @param mixed $value
     */
    private function nullable($value, int $max): ?string
    {
        $text = is_string($value) ? $this->cut($value, $max) : '';

        return $text !== '' ? $text : null;
    }

    // ------------------------------------------------------------------ direcciones e impuestos

    /**
     * Una dirección como la guarda el pedido (copia): los mismos campos y límites que el pedido de la compra normal.
     *
     * @param mixed $raw
     *
     * @return array<string,string>
     */
    private function address($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $limits = ['first_name' => 80, 'last_name' => 120, 'company' => 160, 'vat_id' => 40, 'phone' => 40, 'line1' => 191, 'line2' => 191, 'postcode' => 16, 'city' => 100, 'region' => 16, 'country' => 2];
        $out = [];
        foreach ($limits as $key => $max) {
            $value = isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string) $raw[$key]) : '';
            $value = $this->cut((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value), $max);
            if ($key === 'region') {
                $value = $this->region(isset($raw['country']) && is_string($raw['country']) ? $raw['country'] : '', $value);
            }
            $out[$key] = $key === 'country' || $key === 'postcode' ? strtoupper($value) : $value;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $record
     *
     * @return array<int,array<string,mixed>>
     */
    private function taxes(array $record): array
    {
        $out = [];
        foreach (isset($record['taxes']) && is_array($record['taxes']) ? $record['taxes'] : [] as $tax) {
            if (is_array($tax)) {
                $out[] = ['name' => isset($tax['name']) && is_string($tax['name']) ? $this->cut($tax['name'], 60) : '', 'rate' => isset($tax['rate']) ? (int) $tax['rate'] : 0,
                    'base' => isset($tax['base']) ? (int) $tax['base'] : 0, 'amount' => (int) $tax['amount']];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ líneas

    /**
     * Las líneas con todas sus columnas y, si no suman el total, la de ajuste. Una línea de descuento que el origen da APARTE de
     * las demás (ya repartido en ellas) se guarda como informativa: se enseña y no suma, como en la compra normal.
     *
     * @param array<string,mixed> $record
     *
     * @return array{rows:array<int,array<string,mixed>>,adjustment:?array<string,mixed>}
     */
    private function lines(array $record, WriteContext $ctx, string $id): array
    {
        $refunded = $this->refundedByLine($record);
        $rows = [];
        foreach (isset($record['items']) && is_array($record['items']) ? $record['items'] : [] as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = isset($item['kind']) && is_string($item['kind']) ? $item['kind'] : 'product';
            $name = isset($item['name']) && is_string($item['name']) ? $this->cut($item['name'], 191) : '';
            $row = [
                'index' => (int) $index, 'kind' => $kind, 'product_id' => null, 'variant_id' => null, 'sku' => $this->nullable(isset($item['sku']) ? $item['sku'] : '', 64),
                'name' => $name !== '' ? $name : 'Item', 'variant_label' => $this->nullable(isset($item['variant_label']) ? $item['variant_label'] : '', 191),
                'qty' => isset($item['qty']) ? (int) $item['qty'] : 1, 'unit_price' => (int) $item['unit_price'], 'discount' => isset($item['discount']) ? (int) $item['discount'] : 0,
                'line_total' => (int) $item['line_total'], 'line_net' => (int) $item['line_net'], 'line_tax' => (int) $item['line_tax'],
                'tax_rate' => isset($item['tax_rate']) ? (int) $item['tax_rate'] : 0, 'tax_name' => $this->nullable(isset($item['tax_name']) ? $item['tax_name'] : '', 60),
                'weight' => isset($item['weight']) ? (int) $item['weight'] : 0, 'is_digital' => !empty($item['is_digital']) ? 1 : 0,
                'refunded_qty' => isset($item['refunded_qty']) ? (int) $item['refunded_qty'] : 0, 'refunded_total' => isset($item['refunded_total']) ? (int) $item['refunded_total'] : 0,
                'data' => null,
            ];
            if ($row['refunded_qty'] === 0 && $row['refunded_total'] === 0 && isset($refunded[(int) $index])) {
                $row['refunded_qty'] = $refunded[(int) $index]['qty'];
                $row['refunded_total'] = $refunded[(int) $index]['amount'];
            }
            if ($kind === 'product' && isset($item['product_id']) && is_string($item['product_id']) && $item['product_id'] !== '') {
                $local = $ctx->localId('products', $item['product_id']);
                if ($local !== null && $this->exists('products', ['id' => $local])) {
                    $row['product_id'] = $local;
                    if (isset($item['variant_id']) && is_string($item['variant_id']) && $item['variant_id'] !== '') {
                        $row['variant_id'] = $ctx->localId('variants', $item['variant_id']);
                    }
                } else {
                    $this->noteOnce($ctx, 'noproduct', 'A line of the order refers to a product that was not brought: the line was kept with its name and reference');
                }
            }
            $rows[] = $row;
        }

        return $this->reconcile($rows, $record);
    }

    /**
     * Cuadra las líneas con el total del origen.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed>            $record
     *
     * @return array{rows:array<int,array<string,mixed>>,adjustment:?array<string,mixed>}
     */
    private function reconcile(array $rows, array $record): array
    {
        $total = (int) $record['total'];
        $all = 0;
        $withoutDiscount = 0;
        $discounts = 0;
        foreach ($rows as $row) {
            $all += $row['line_net'] + $row['line_tax'];
            if ($row['kind'] === 'discount') {
                ++$discounts;
            } else {
                $withoutDiscount += $row['line_net'] + $row['line_tax'];
            }
        }
        $informational = $discounts > 0 && $all !== $total && abs($withoutDiscount - $total) < abs($all - $total);
        $sum = $informational ? $withoutDiscount : $all;
        if ($informational) {
            foreach ($rows as $i => $row) {
                if ($row['kind'] === 'discount') {
                    $rows[$i]['line_net'] = 0;
                    $rows[$i]['line_tax'] = 0;
                    $rows[$i]['data'] = OrderLog::json(['informational' => true]);
                }
            }
        }
        $diff = $total - $sum;
        if ($diff === 0) {
            return ['rows' => $rows, 'adjustment' => null];
        }
        $sumTax = 0;
        foreach ($rows as $row) {
            $sumTax += $row['line_tax'];
        }
        $taxPart = (int) $record['total_net'] + (int) $record['total_tax'] === $total ? (int) $record['total_tax'] - $sumTax : 0;
        $netPart = $diff - $taxPart;
        $amount = !empty($record['prices_include_tax']) ? $diff : $netPart;
        $label = t('Price adjustment');
        $rows[] = [
            'index' => -1, 'kind' => $diff < 0 ? 'discount' : 'fee', 'product_id' => null, 'variant_id' => null, 'sku' => null, 'name' => $this->cut($label, 191),
            'variant_label' => null, 'qty' => 1, 'unit_price' => $amount, 'discount' => 0, 'line_total' => $amount, 'line_net' => $netPart, 'line_tax' => $taxPart, 'tax_rate' => 0,
            'tax_name' => null, 'weight' => 0, 'is_digital' => 0, 'refunded_qty' => 0, 'refunded_total' => 0,
            'data' => OrderLog::json(['adjustment' => true, 'source' => 'import', self::ADJUST => true]),
        ];

        return ['rows' => $rows, 'adjustment' => ['label' => $label, 'amount' => $amount, 'net' => $netPart, 'tax' => $taxPart, 'tax_rate' => 0, 'tax_name' => '', 'source' => 'import']];
    }

    /**
     * Lo devuelto por línea según las devoluciones (para las líneas que no traen su propia cuenta).
     *
     * @param array<string,mixed> $record
     *
     * @return array<int,array{qty:int,amount:int}>
     */
    private function refundedByLine(array $record): array
    {
        $out = [];
        foreach (isset($record['refunds']) && is_array($record['refunds']) ? $record['refunds'] : [] as $refund) {
            foreach (is_array($refund) && isset($refund['lines']) && is_array($refund['lines']) ? $refund['lines'] : [] as $line) {
                if (!is_array($line) || !isset($line['index'])) {
                    continue;
                }
                $index = (int) $line['index'];
                if (!isset($out[$index])) {
                    $out[$index] = ['qty' => 0, 'amount' => 0];
                }
                $out[$index]['qty'] += isset($line['qty']) ? (int) $line['qty'] : 0;
                $out[$index]['amount'] += isset($line['amount']) ? (int) $line['amount'] : 0;
            }
        }

        return $out;
    }

    /**
     * Escribe las líneas. Devuelve el identificador de cada una por su posición en el origen.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,int>
     */
    private function insertItems(int $orderId, array $rows): array
    {
        $db = $this->db();
        $ids = [];
        foreach (array_values($rows) as $position => $row) {
            $index = (int) $row['index'];
            unset($row['index']);
            $localId = $db->insert('order_items', ['order_id' => $orderId, 'position' => $position] + $row);
            if ($index >= 0) {
                $ids[$index] = $localId;
            }
        }

        return $ids;
    }

    // ------------------------------------------------------------------ pagos, devoluciones e historial

    /**
     * Los movimientos de dinero. La clave única (método, referencia, tipo) no puede chocar: si la referencia ya existe en otro pedido,
     * se le antepone el origen.
     *
     * @param array<string,mixed> $record
     */
    private function insertPayments(int $orderId, array $record, string $number, string $placed, WriteContext $ctx): void
    {
        $db = $this->db();
        $currency = $this->cut(strtoupper(trim((string) $record['currency'])), 3) ?: 'EUR';
        foreach (isset($record['payments']) && is_array($record['payments']) ? $record['payments'] : [] as $n => $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $method = isset($payment['method']) && is_string($payment['method']) ? $this->cut(strtolower(trim($payment['method'])), 40) : '';
            $mapped = $method !== '' ? $ctx->equivalent('payment_method', $method) : null;
            $method = $mapped !== null ? $this->cut(strtolower($mapped), 40) : ($method !== '' ? $method : 'manual');
            $kind = isset($payment['kind']) && $payment['kind'] === 'refund' ? 'refund' : 'charge';
            $reference = isset($payment['reference']) && is_string($payment['reference']) ? trim($payment['reference']) : '';
            if ($reference === '') {
                $reference = 'import-' . $number . '-' . ($n + 1);
            }
            $reference = $this->freeReference($method, $this->cut($reference, 120), $kind, $ctx->source(), $number);
            $status = isset($payment['status']) && in_array($payment['status'], ['ok', 'pending', 'failed'], true) ? $payment['status'] : 'ok';
            $db->insert('order_payments', [
                'order_id' => $orderId, 'method' => $method, 'kind' => $kind, 'reference' => $reference, 'amount' => (int) $payment['amount'], 'currency' => $currency,
                'status' => $status, 'test' => 0, 'raw' => self::MARK, 'created_at' => $this->sqlDate(isset($payment['at']) ? $payment['at'] : null) ?: $placed,
            ]);
        }
    }

    /**
     * Una referencia que no choque con ninguna otra (método, referencia, tipo): la del origen o, si ya existe, con el origen delante.
     */
    private function freeReference(string $method, string $reference, string $kind, string $source, string $number): string
    {
        $db = $this->db();
        $candidates = [$reference, $this->cut($source . ':' . $reference, 120), $this->cut($source . ':' . $number . ':' . $reference, 120)];
        foreach ($candidates as $candidate) {
            if ($db->value('SELECT id FROM {order_payments} WHERE method = ? AND reference = ? AND kind = ?', [$method, $candidate, $kind]) === null) {
                return $candidate;
            }
        }
        for ($n = 2;; ++$n) {
            $candidate = $this->cut($candidates[2], 110) . '#' . $n;
            if ($db->value('SELECT id FROM {order_payments} WHERE method = ? AND reference = ? AND kind = ?', [$method, $candidate, $kind]) === null) {
                return $candidate;
            }
        }
    }

    /**
     * Las devoluciones, cada una unida a su movimiento de dinero (el primero de tipo «refund» del mismo importe que quede libre).
     *
     * @param array<string,mixed> $record
     * @param array<int,int>      $itemIds
     */
    private function insertRefunds(int $orderId, array $record, array $itemIds, string $placed): void
    {
        $db = $this->db();
        $free = [];
        foreach ($db->select("SELECT id, amount FROM {order_payments} WHERE order_id = ? AND kind = 'refund' ORDER BY id", [$orderId]) as $payment) {
            $free[(int) $payment['id']] = (int) $payment['amount'];
        }
        foreach (isset($record['refunds']) && is_array($record['refunds']) ? $record['refunds'] : [] as $refund) {
            if (!is_array($refund)) {
                continue;
            }
            $items = [];
            foreach (isset($refund['lines']) && is_array($refund['lines']) ? $refund['lines'] : [] as $line) {
                $index = is_array($line) && isset($line['index']) ? (int) $line['index'] : -1;
                $qty = is_array($line) && isset($line['qty']) ? (int) $line['qty'] : 0;
                if ($qty > 0 && isset($itemIds[$index])) {
                    $items[$itemIds[$index]] = $qty;
                }
            }
            $amount = (int) $refund['amount'];
            $paymentId = array_search($amount, $free, true);
            if ($paymentId !== false) {
                unset($free[$paymentId]);
            }
            $reason = isset($refund['reason']) && is_string($refund['reason']) ? $this->cut($refund['reason'], 255) : '';
            $db->insert('order_refunds', [
                'order_id' => $orderId, 'amount' => $amount, 'tax' => isset($refund['tax']) ? (int) $refund['tax'] : 0, 'items' => OrderLog::json($items),
                'reason' => $reason !== '' ? $reason : null, 'restock' => !empty($refund['restock']) ? 1 : 0, 'payment_id' => $paymentId !== false ? (int) $paymentId : null,
                'user_id' => null, 'created_at' => $this->sqlDate(isset($refund['at']) ? $refund['at'] : null) ?: $placed,
            ]);
        }
    }

    /**
     * El historial con sus fechas y sus autores, más una apuntación del sistema que dice que las existencias de este pedido no se
     * descontaron aquí (así nadie las devuelve por error al cancelarlo o reembolsarlo).
     *
     * @param array<string,mixed> $record
     */
    private function insertHistory(int $orderId, array $record, string $status, string $placed, WriteContext $ctx): void
    {
        $db = $this->db();
        $db->insert('order_history', [
            'order_id' => $orderId, 'kind' => 'system', 'from_value' => 'stock_held', 'to_value' => 'stock_released', 'note' => t('Stock was not touched: this order was imported'),
            'is_public' => 0, 'user_id' => null, 'actor' => 'system', 'data' => self::MARK, 'created_at' => $placed,
        ]);
        $kinds = ['state' => 'status', 'note' => 'note', 'mail' => 'mail', 'other' => 'system'];
        foreach (isset($record['history']) && is_array($record['history']) ? $record['history'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $kind = isset($entry['kind']) && is_string($entry['kind']) && isset($kinds[$entry['kind']]) ? $kinds[$entry['kind']] : 'system';
            $actor = isset($entry['actor']) && is_string($entry['actor']) ? $this->cut($entry['actor'], 60) : '';
            $note = isset($entry['note']) && is_string($entry['note']) ? trim($entry['note']) : '';
            $db->insert('order_history', [
                'order_id' => $orderId, 'kind' => $kind, 'from_value' => $kind === 'status' ? $this->stateValue($entry, 'from', $record, $status, $ctx) : null,
                'to_value' => $kind === 'status' ? $this->stateValue($entry, 'to', $record, $status, $ctx) : null, 'note' => $note !== '' ? $note : null,
                'is_public' => !empty($entry['public']) ? 1 : 0, 'user_id' => null, 'actor' => $actor !== '' ? $actor : 'system', 'data' => self::MARK,
                'created_at' => $this->sqlDate(isset($entry['at']) ? $entry['at'] : null) ?: $placed,
            ]);
        }
    }

    /**
     * Un estado de una apuntación de historial: por la equivalencia elegida; el actual del pedido, el de aquí; y si no, el del origen.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $record
     */
    private function stateValue(array $entry, string $field, array $record, string $status, WriteContext $ctx): ?string
    {
        $value = isset($entry[$field]) && is_string($entry[$field]) ? trim($entry[$field]) : '';
        if ($value === '') {
            return null;
        }
        $mapped = $ctx->equivalent('order_state', $value);
        if ($mapped !== null) {
            return $this->cut($mapped, 40);
        }

        return $value === (string) $record['state'] ? $status : $this->cut($value, 40);
    }

    // ------------------------------------------------------------------ cupones y facturas

    /**
     * Los cupones del pedido tal como los guarda la compra normal, y los códigos con su importe.
     *
     * @param array<string,mixed> $record
     *
     * @return array{summary:array<int,array<string,mixed>>}
     */
    private function couponSummary(array $record): array
    {
        $db = $this->db();
        $out = [];
        foreach (isset($record['coupons']) && is_array($record['coupons']) ? $record['coupons'] : [] as $coupon) {
            if (!is_array($coupon) || !isset($coupon['code']) || !is_string($coupon['code'])) {
                continue;
            }
            $code = \Aifec\Modules\Coupons\Coupons::normalizeCode($coupon['code']);
            $local = $code !== '' ? $db->value('SELECT id FROM {coupons} WHERE code = ?', [$code]) : null;
            $out[] = ['coupon_id' => $local !== null ? (int) $local : null, 'code' => $code, 'label' => '', 'amount' => isset($coupon['amount']) ? (int) $coupon['amount'] : 0];
        }

        return ['summary' => $out];
    }

    /**
     * Cuenta como un uso del cupón (sin sumarlo otra vez a «uses»: el origen ya lo traía contado) en el cupón que se trajo de este origen.
     *
     * @param array<string,mixed> $record
     */
    private function insertCouponUses(int $orderId, array $record, ?int $customerId, string $email, string $placed, WriteContext $ctx): void
    {
        $db = $this->db();
        if (!$db->tableExists('coupon_uses')) {
            return;
        }
        foreach ($this->couponSummary($record)['summary'] as $coupon) {
            $couponId = $coupon['coupon_id'];
            $brought = $couponId !== null && $db->value("SELECT id FROM {import_map} WHERE type = 'coupons' AND local_id = ?", [$couponId]) !== null;
            if (!$brought) {
                $this->noteOnce($ctx, 'coupon-' . $coupon['code'], 'The coupon «' . $this->cut((string) $coupon['code'], 40) . '» of the order was not brought from this source: its use was not counted');
                continue;
            }
            $db->insertIgnore('coupon_uses', [
                'coupon_id' => $couponId, 'order_id' => $orderId, 'customer_id' => $customerId, 'email' => $email, 'amount' => max(0, (int) $coupon['amount']), 'created_at' => $placed,
            ]);
        }
    }

    /**
     * Las facturas del origen (número y fecha), tal como se guardan dentro del pedido.
     *
     * @param array<string,mixed> $record
     *
     * @return array<int,array<string,string>>
     */
    private function invoiceList(array $record): array
    {
        $out = [];
        foreach (isset($record['invoices']) && is_array($record['invoices']) ? $record['invoices'] : [] as $invoice) {
            if (is_array($invoice) && isset($invoice['number']) && is_string($invoice['number'])) {
                $out[] = ['number' => $this->cut($invoice['number'], 40), 'date' => isset($invoice['date']) && is_string($invoice['date']) ? $invoice['date'] : '',
                    'kind' => isset($invoice['kind']) && is_string($invoice['kind']) ? $invoice['kind'] : 'invoice'];
            }
        }

        return $out;
    }

    /**
     * Si hay módulo de facturas, cada factura del origen queda ahí con su número y su fecha (los datos congelados salen del pedido
     * ya escrito). Las rectificativas del origen solo quedan dentro del pedido. Nunca se avanza el contador de facturas.
     *
     * @param array<string,mixed> $record
     */
    private function storeInvoices(int $orderId, array $record, string $placed, WriteContext $ctx): void
    {
        $db = $this->db();
        if (!$this->app->has('invoices') || !$db->tableExists('invoices')) {
            return;
        }
        foreach (isset($record['invoices']) && is_array($record['invoices']) ? $record['invoices'] : [] as $n => $invoice) {
            if (!is_array($invoice) || !isset($invoice['number']) || !is_string($invoice['number'])) {
                continue;
            }
            if (isset($invoice['kind']) && $invoice['kind'] === 'credit') {
                $this->noteOnce($ctx, 'credit', 'A credit note of the source was kept inside the order only: the invoices module cannot store it');
                continue;
            }
            $full = $this->cut((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($invoice['number'])), 40);
            $key = 'invoice:' . $orderId . ($n > 0 ? ':' . $n : '');
            if ($full === '' || $db->value('SELECT id FROM {invoices} WHERE unique_key = ?', [$key]) !== null) {
                continue;
            }
            if ($db->value('SELECT id FROM {invoices} WHERE full_number = ?', [$full]) !== null) {
                $ctx->note('orders', (string) $record['id'], 'note', 'The invoice number «' . $full . '» already exists here: it was kept inside the order only');
                continue;
            }

            try {
                $order = $this->app->service('orders')->find($orderId);
                $data = InvoiceData::invoice($this->app, is_array($order) ? $order : []);
                $at = $this->sqlDate(isset($invoice['date']) ? $invoice['date'] : null) ?: $placed;
                $series = preg_match('/^(.*?)(\d+)$/', $full, $m) === 1 ? [$this->cut((string) preg_replace('/[^A-Za-z0-9]/', '', $m[1]), 20), (int) $m[2]] : ['', 0];
                $now = $this->now();
                $db->insert('invoices', [
                    'unique_key' => $key, 'order_id' => $orderId, 'type' => 'invoice', 'series' => $series[0] !== '' ? $series[0] : 'F', 'number' => $series[1], 'full_number' => $full,
                    'issued_at' => $at, 'refund_id' => null, 'original_id' => null, 'currency' => $this->cut(strtoupper(trim((string) $record['currency'])), 3) ?: 'EUR',
                    'net' => (int) $data['totals']['net'], 'tax' => (int) $data['totals']['tax'], 'total' => (int) $data['totals']['total'], 'test' => 0,
                    'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 'pdf_path' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            } catch (\Throwable $e) {
                $ctx->note('orders', (string) $record['id'], 'note', 'The invoice «' . $full . '» was kept inside the order only: ' . $this->cut($e->getMessage(), 120));
            }
        }
    }

    // ------------------------------------------------------------------ rehacer y contar

    /**
     * Antes de volver a escribir un pedido: fuera lo que escribió este escritor (líneas, devoluciones, y los pagos, el historial y los
     * usos de cupón con su marca). Lo que se añadió aquí a mano no se toca.
     */
    private function clearChildren(int $orderId): void
    {
        $db = $this->db();
        $db->delete('order_items', ['order_id' => $orderId]);
        $db->delete('order_refunds', ['order_id' => $orderId]);
        $db->delete('order_payments', ['order_id' => $orderId, 'raw' => self::MARK]);
        $db->delete('order_history', ['order_id' => $orderId, 'data' => self::MARK]);
        if ($db->tableExists('coupon_uses')) {
            $db->delete('coupon_uses', ['order_id' => $orderId]);
        }
    }

    /**
     * Las cifras del cliente, calculadas otra vez desde sus pedidos cobrados (como las deja el servicio al cobrar: pedidos con algo
     * cobrado, y su total). Es idempotente.
     */
    private function recount(int $customerId): void
    {
        $this->db()->run(
            "UPDATE {customers} SET orders_count = (SELECT COUNT(*) FROM {orders} WHERE customer_id = ? AND payment_status IN ('paid', 'partially_refunded', 'refunded')), "
            . "total_spent = (SELECT COALESCE(SUM(total), 0) FROM {orders} WHERE customer_id = ? AND payment_status IN ('paid', 'partially_refunded', 'refunded')) WHERE id = ?",
            [$customerId, $customerId, $customerId]
        );
    }
}
