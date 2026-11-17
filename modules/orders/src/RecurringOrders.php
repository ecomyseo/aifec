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

use Aifec\Commerce\Totals;
use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/** Pedido contable de una cuota confirmada: una clave externa, un pedido y un cobro. */
final class RecurringOrders
{
    private App $app;
    private Orders $orders;

    public function __construct(App $app, Orders $orders)
    {
        $this->app = $app;
        $this->orders = $orders;
    }

    /**
     * @param array<string,mixed> $snapshot
     *
     * @return array{id:int,number:string}
     */
    public function create(array $snapshot, string $externalKey): array
    {
        if (trim($externalKey) === '' || strlen($externalKey) > 512) {
            throw new \InvalidArgumentException('Invalid recurring payment key');
        }
        $s = $this->normalise($snapshot);
        $key = hash('sha256', $externalKey);
        $identity = $s;
        // La hora de recepción puede variar entre reintentos del mismo aviso.
        unset($identity['paid_at']);
        $fingerprint = hash('sha256', OrderLog::json($identity));
        try {
            return $this->app->db()->transaction(function (Db $db) use ($s, $key, $fingerprint): array {
                $existing = $this->existing($key, $fingerprint);
                if ($existing !== null) {
                    return $existing;
                }
                // La clave se reserva ANTES del contador y del pedido; la restricción única decide las carreras.
                $db->insert('order_recurring_payments', [
                    'external_key' => $key, 'fingerprint' => $fingerprint, 'order_id' => null, 'created_at' => $this->app->clock()->sql(),
                ]);
                $result = $this->insert($s);
                $db->update('order_recurring_payments', ['order_id' => $result['id']], ['external_key' => $key]);

                return $result;
            });
        } catch (\PDOException $e) {
            // El perdedor de una carrera relee después de deshacer su transacción o su savepoint.
            $existing = $this->existing($key, $fingerprint);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    /** @return array{id:int,number:string}|null */
    private function existing(string $key, string $fingerprint): ?array
    {
        $row = $this->app->db()->row('SELECT fingerprint, order_id FROM {order_recurring_payments} WHERE external_key = ?', [$key]);
        if ($row === null) {
            return null;
        }
        if (!hash_equals((string) $row['fingerprint'], $fingerprint)) {
            throw new \InvalidArgumentException('The recurring payment key belongs to different data');
        }
        $order = $this->orders->find((int) $row['order_id']);
        if ($order === null || (string) $order['payment_status'] !== 'paid') {
            throw new \LogicException('The recurring payment order is incomplete');
        }

        return ['id' => (int) $order['id'], 'number' => (string) $order['number']];
    }

    /** @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normalise(array $raw): array
    {
        foreach (['customer_id', 'amount', 'subscription_id'] as $field) {
            if (!isset($raw[$field]) || !is_int($raw[$field]) || $raw[$field] < 1) {
                throw new \InvalidArgumentException('Invalid recurring payment ' . $field);
            }
        }
        if ($raw['amount'] > 2000000000) {
            throw new \InvalidArgumentException('The recurring payment amount is too large');
        }
        $s = [];
        foreach (['email', 'currency', 'name', 'method', 'reference'] as $field) {
            if (!isset($raw[$field]) || !is_string($raw[$field]) || trim($raw[$field]) === '') {
                throw new \InvalidArgumentException('Invalid recurring payment ' . $field);
            }
            $s[$field] = trim($raw[$field]);
        }
        $s['email'] = strtolower($s['email']);
        if (filter_var($s['email'], FILTER_VALIDATE_EMAIL) === false || strlen($s['email']) > 191
            || preg_match('/^[A-Z]{3}$/', $s['currency']) !== 1
            || preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $s['method']) !== 1
            || mb_strlen($s['name'], 'UTF-8') > 191 || mb_strlen($s['reference'], 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Invalid recurring payment details');
        }
        $customer = $this->app->service('customers')->find($raw['customer_id']);
        if ($customer === null || strtolower((string) $customer['email']) !== $s['email']) {
            throw new \InvalidArgumentException('The recurring payment customer does not match');
        }
        $billing = isset($raw['billing']) && is_array($raw['billing']) ? $raw['billing'] : [];
        $s['billing'] = [];
        foreach (['first_name', 'last_name', 'company', 'vat_id', 'phone', 'line1', 'line2', 'postcode', 'city', 'region', 'country'] as $field) {
            $s['billing'][$field] = isset($billing[$field]) && is_string($billing[$field]) ? trim($billing[$field]) : '';
        }
        $rate = isset($raw['tax_rate']) ? $raw['tax_rate'] : 0;
        if (!is_int($rate) || $rate < 0 || $rate > 100000 || (isset($raw['tax_included']) && $raw['tax_included'] !== true)) {
            throw new \InvalidArgumentException('Invalid recurring payment tax');
        }
        $paidAt = isset($raw['paid_at']) && is_string($raw['paid_at']) ? $raw['paid_at'] : $this->app->clock()->sql();
        $stamp = Clock::parse($paidAt);
        if ($stamp <= 0 || $this->app->clock()->sql($stamp) !== $paidAt || $stamp > $this->app->clock()->now() + 300) {
            throw new \InvalidArgumentException('Invalid recurring payment date');
        }
        $test = !empty($raw['test']);
        if ($this->app->site()->isDemo() && !$test) {
            throw new \InvalidArgumentException('A demo cannot record a live recurring payment');
        }
        $s += ['customer_id' => $raw['customer_id'], 'amount' => $raw['amount'], 'subscription_id' => $raw['subscription_id'],
            'tax_rate' => $rate, 'tax_name' => isset($raw['tax_name']) && is_string($raw['tax_name']) ? mb_substr($raw['tax_name'], 0, 60, 'UTF-8') : '',
            'paid_at' => $paidAt, 'test' => $test];

        return $s;
    }

    /** @param array<string,mixed> $s
     * @return array{id:int,number:string}
     */
    private function insert(array $s): array
    {
        $app = $this->app;
        $db = $app->db();
        $now = $app->clock()->sql();
        $totals = Totals::calculate(['prices_include_tax' => true, 'lines' => [[
            'key' => 'subscription', 'unit_price' => $s['amount'], 'qty' => 1, 'tax_rate' => $s['tax_rate'], 'tax_name' => $s['tax_name'],
        ]]]);
        if ((int) $totals['total'] !== $s['amount']) {
            throw new \LogicException('The recurring payment total does not match');
        }
        $number = Sequence::format($app->settings()->str('orders.number_format', Sequence::DEFAULT_FORMAT), (new Sequence($app))->next('order'), (int) substr($now, 0, 4));
        $paymentTitles = ['subscription_simulated' => t('Simulated monthly payment'), 'subscription_paypal' => t('PayPal monthly payment')];
        $id = $db->insert('orders', [
            'number' => $number, 'customer_id' => $s['customer_id'], 'email' => $s['email'], 'lang' => $app->defaultLang(), 'currency' => $s['currency'],
            'status' => 'pending_payment', 'payment_status' => 'pending', 'fulfillment_status' => 'not_needed',
            'payment_method' => $s['method'], 'payment_title' => $paymentTitles[$s['method']] ?? $s['method'], 'prices_include_tax' => 1,
            'items_total' => $totals['items_total'], 'total_net' => $totals['total_net'], 'total_tax' => $totals['total_tax'], 'total' => $totals['total'],
            'taxes' => OrderLog::json($totals['taxes']), 'billing' => OrderLog::json($s['billing']), 'source' => 'subscription',
            'secret' => hash('sha256', Orders::viewToken($app, $number)), 'test' => $s['test'] ? 1 : 0,
            'placed_at' => $s['paid_at'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $line = $totals['lines']['subscription'];
        $db->insert('order_items', [
            'order_id' => $id, 'kind' => 'product', 'name' => $s['name'], 'qty' => 1, 'unit_price' => $s['amount'],
            'line_total' => $line['total'], 'line_net' => $line['net'], 'line_tax' => $line['tax'], 'tax_rate' => $s['tax_rate'],
            'tax_name' => $s['tax_name'], 'data' => OrderLog::json(['kind' => 'service', 'subscription_id' => $s['subscription_id']]),
        ]);
        $app->hooks()->fire('order.placed', $this->orders->find($id));
        if (!$this->orders->markPaid($id, $s['method'], $s['reference'], $s['amount'], ['subscription_id' => $s['subscription_id']], $s['test'])) {
            throw new \LogicException('The recurring payment could not be recorded');
        }
        // Se conserva la fecha del proveedor, no la hora a la que llegó su aviso.
        $db->update('orders', ['paid_at' => $s['paid_at']], ['id' => $id]);

        return ['id' => $id, 'number' => $number];
    }
}
