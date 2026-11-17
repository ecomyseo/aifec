<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Subscriptions;

use Aifec\Commerce\Totals;
use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Payments\PayPal\PayPalClient;

/** Mandatos y cuotas: el precio aceptado queda congelado; una cuota siempre origina un único pedido. */
final class Subscriptions
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function available(): bool
    {
        return $this->app->settings()->bool('subscriptions.enabled', false)
            && ($this->provider() !== 'simulated' || $this->simulationAllowed());
    }

    public function simulationAllowed(): bool
    {
        return $this->app->site()->isDemo() || $this->app->site()->debug();
    }

    public function provider(): string
    {
        return $this->app->site()->isDemo() ? 'simulated' : ($this->app->settings()->str('subscriptions.provider', 'simulated') === 'paypal' ? 'paypal' : 'simulated');
    }

    public function plans(bool $all = false): array
    {
        return $this->app->db()->select('SELECT * FROM {subscription_plans}' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY id LIMIT 200');
    }

    public function plan(int $id): ?array
    {
        return $this->app->db()->row('SELECT * FROM {subscription_plans} WHERE id = ?', [$id]);
    }

    /** Alta de planes para el panel y los constructores de demos, sin exponer tablas ajenas. */
    public function savePlan(array $data, ?int $id = null): int
    {
        $name = trim(isset($data['name']) && is_string($data['name']) ? $data['name'] : '');
        $amount = $data['amount'] ?? 0;
        if ($name === '' || mb_strlen($name) > 120 || !is_int($amount) || $amount <= 0 || $amount > 100000000) {
            throw new \InvalidArgumentException('Invalid monthly plan name or amount');
        }
        $current = $id !== null ? $this->plan($id) : null;
        if ($id !== null && $current === null) {
            throw new \InvalidArgumentException('Unknown monthly plan');
        }
        $class = !empty($data['tax_class_id']) ? (int) $data['tax_class_id'] : null;
        if ($class !== null && !isset($this->app->service('tax')->classes()[$class])) {
            throw new \InvalidArgumentException('Unknown monthly plan tax class');
        }
        $description = isset($data['description']) && is_string($data['description']) ? $data['description'] : '';
        if (mb_strlen($description) > 5000) {
            throw new \InvalidArgumentException('Monthly plan description is too long');
        }
        $values = ['name' => $name, 'amount' => $amount, 'description' => $description, 'tax_class_id' => $class,
            'active' => !array_key_exists('active', $data) || !empty($data['active']) ? 1 : 0, 'updated_at' => $this->app->clock()->sql()];
        if ($id !== null) {
            $this->app->db()->update('subscription_plans', $values, ['id' => $id]);
            return $id;
        }
        $values['created_at'] = $this->app->clock()->sql();
        return $this->app->db()->insert('subscription_plans', $values);
    }

    public function find(int $id, ?int $customerId = null): ?array
    {
        return $customerId === null
            ? $this->app->db()->row('SELECT * FROM {subscriptions} WHERE id = ?', [$id])
            : $this->app->db()->row('SELECT * FROM {subscriptions} WHERE id = ? AND customer_id = ?', [$id, $customerId]);
    }

    public function byPublic(string $id, int $customerId): ?array
    {
        return $this->app->db()->row('SELECT * FROM {subscriptions} WHERE public_id = ? AND customer_id = ?', [$id, $customerId]);
    }

    public function forCustomer(int $id): array
    {
        return $this->app->db()->select('SELECT * FROM {subscriptions} WHERE customer_id = ? ORDER BY id DESC LIMIT 200', [$id]);
    }

    public function payments(int $id): array
    {
        return $this->app->db()->select('SELECT * FROM {subscription_payments} WHERE subscription_id = ? ORDER BY id DESC LIMIT 100', [$id]);
    }

    public function customerPayments(int $customerId): array
    {
        $grouped = [];
        $rows = $this->app->db()->select('SELECT p.* FROM {subscription_payments} p INNER JOIN {subscriptions} s ON s.id = p.subscription_id WHERE s.customer_id = ? ORDER BY p.id DESC LIMIT 1000', [$customerId]);
        foreach ($rows as $row) {
            $grouped[(int) $row['subscription_id']][] = $row;
        }
        return $grouped;
    }

    /** Impuestos y cliente salen de los servicios de la tienda, nunca del formulario ni del modelo. */
    public function quote(int $planId, int $customerId, int $addressId): array
    {
        $plan = $this->plan($planId);
        $customer = $this->app->service('customers')->find($customerId);
        $address = $this->app->service('customers')->address($addressId, $customerId);
        if ($plan === null || empty($plan['active']) || (int) $plan['amount'] <= 0 || $customer === null || $address === null) {
            throw new \InvalidArgumentException('The plan or billing address is not available');
        }
        $tax = $this->app->service('tax');
        $rate = $tax->rate(!empty($plan['tax_class_id']) ? (int) $plan['tax_class_id'] : null, $address);
        $exempt = $tax->exempt($customer, $address);
        $totals = Totals::calculate(['prices_include_tax' => $tax->pricesIncludeTax(), 'tax_exempt' => $exempt,
            'lines' => [['key' => 'subscription', 'unit_price' => (int) $plan['amount'], 'qty' => 1, 'tax_rate' => $rate['rate'], 'tax_name' => $rate['name']]]]);
        if ((int) $totals['total'] <= 0) {
            throw new \InvalidArgumentException('A monthly payment must be greater than zero');
        }

        return ['name' => (string) $plan['name'], 'amount' => (int) $totals['total'], 'currency' => $this->app->settings()->str('shop.currency', 'EUR'),
            'customer_id' => $customerId, 'email' => (string) $customer['email'], 'billing' => $address,
            'tax_rate' => $exempt ? 0 : (int) $rate['rate'], 'tax_name' => (string) $rate['name'], 'tax_included' => true,
            'unit_price' => (int) $plan['amount'], 'tax_class_id' => $plan['tax_class_id'], 'totals' => $totals];
    }

    /** La clave del formulario hace idempotente el doble clic, también antes de hablar con la pasarela. */
    public function start(int $planId, int $customerId, int $addressId, bool $consent, int $acceptedAmount, string $nonce): array
    {
        if (!$this->available() || !$consent || preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            throw new \InvalidArgumentException('Accept the monthly payment before subscribing');
        }
        $snapshot = $this->quote($planId, $customerId, $addressId);
        if ($acceptedAmount !== $snapshot['amount']) {
            throw new \InvalidArgumentException('The price changed; review the monthly payment again');
        }
        $provider = $this->provider();
        $environment = $provider === 'simulated' ? 'simulated' : ($this->app->settings()->str('subscriptions.paypal.environment', 'sandbox') === 'live' ? 'live' : 'sandbox');
        $public = 'S-' . substr(hash('sha256', $this->app->site()->id . ':' . $customerId . ':' . $nonce), 0, 40);
        $now = $this->app->clock()->sql();
        $db = $this->app->db();
        try {
            $db->transaction(function (Db $db) use ($public, $planId, $customerId, $provider, $environment, $snapshot, $now): void {
                $existing = $db->row('SELECT * FROM {subscriptions} WHERE public_id = ?', [$public]);
                if ($existing !== null) {
                    if ((int) $existing['customer_id'] !== $customerId || (int) $existing['plan_id'] !== $planId || $existing['snapshot'] !== self::encode($snapshot)) {
                        throw new \InvalidArgumentException('This confirmation is for another subscription');
                    }
                    return;
                }
                $db->insert('subscriptions', ['public_id' => $public, 'plan_id' => $planId, 'customer_id' => $customerId, 'status' => 'setup_failed',
                    'provider' => $provider, 'environment' => $environment, 'snapshot' => self::encode($snapshot), 'consented_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            });
        } catch (\PDOException $e) {
            // Une la carrera de dos altas con la misma ficha; el segundo no crea otro mandato externo.
            if ($db->value('SELECT id FROM {subscriptions} WHERE public_id = ?', [$public]) === null) {
                throw $e;
            }
        }
        $sub = $this->byPublic($public, $customerId);
        if ($sub === null || (int) $sub['plan_id'] !== $planId || $sub['snapshot'] !== self::encode($snapshot)) {
            throw new \InvalidArgumentException('This confirmation is for another subscription');
        }
        if ($sub['status'] !== 'setup_failed') {
            return $sub;
        }
        if (Clock::parse((string) $sub['created_at']) < $this->app->clock()->now() - 86400) {
            throw new \InvalidArgumentException('The previous setup needs manual review before trying again');
        }
        if ($db->run("UPDATE {subscriptions} SET status = 'starting', updated_at = ? WHERE id = ? AND status = 'setup_failed'", [$now, (int) $sub['id']]) !== 1) {
            return $this->find((int) $sub['id']);
        }
        try {
            if ($provider === 'simulated') {
                $db->transaction(function (Db $db) use ($sub, $public, $now, $snapshot): void {
                    $db->update('subscriptions', ['provider_id' => 'SIM-' . $public, 'status' => 'active', 'updated_at' => $now], ['id' => (int) $sub['id']]);
                    $this->recordPayment((int) $sub['id'], 'SIM-' . $public . '-1', $snapshot['amount'], $snapshot['currency'], $now);
                });
            } else {
                $created = (new PayPalGateway($this->app, $environment))->create($sub, $snapshot);
                $db->update('subscriptions', ['provider_id' => $created['id'], 'provider_plan_id' => $created['plan_id'], 'approval_url' => $created['approval_url'], 'status' => 'pending', 'updated_at' => $now], ['id' => (int) $sub['id']]);
            }
        } catch (\Throwable $e) {
            $db->run("UPDATE {subscriptions} SET status = 'setup_failed', updated_at = ? WHERE id = ? AND status = 'starting'", [$now, (int) $sub['id']]);
            throw $e;
        }

        return $this->find((int) $sub['id']);
    }

    public function change(int $id, string $action, ?int $customerId = null): void
    {
        if (!in_array($action, ['cancel', 'suspend', 'activate'], true) || ($customerId !== null && $action !== 'cancel')) {
            throw new \InvalidArgumentException('This subscription action is not allowed');
        }
        $sub = $this->find($id, $customerId);
        if ($sub === null) {
            throw new \InvalidArgumentException('Unknown subscription');
        }
        if (in_array($sub['status'], ['cancelled', 'expired'], true)) {
            if ($action === 'cancel') {
                return;
            }
            throw new \InvalidArgumentException('A finished subscription cannot be activated');
        }
        if ($sub['status'] === 'starting') {
            throw new \InvalidArgumentException('The payment provider is still setting up this subscription');
        }
        if ($action === 'activate' && (!in_array($sub['status'], ['suspended', 'past_due'], true) || empty($sub['provider_id']))) {
            throw new \InvalidArgumentException('Only an existing suspended or overdue mandate can be activated');
        }
        if ($action === 'suspend' && (!in_array($sub['status'], ['active', 'past_due'], true) || empty($sub['provider_id']))) {
            throw new \InvalidArgumentException('Only an active mandate can be suspended');
        }
        if ($sub['provider'] === 'paypal' && !empty($sub['provider_id'])) {
            (new PayPalGateway($this->app, (string) $sub['environment']))->change((string) $sub['provider_id'], $action, (string) $sub['public_id'] . '-' . $action . '-' . (int) $sub['version']);
        }
        $status = ['cancel' => 'cancelled', 'suspend' => 'suspended', 'activate' => 'active'][$action];
        $set = ['status' => $status, 'updated_at' => $this->app->clock()->sql()];
        if ($action === 'cancel') {
            $set['cancelled_at'] = $this->app->clock()->sql();
            $set['next_payment_at'] = null;
        }
        $this->app->db()->update('subscriptions', $set, ['id' => $id]);
        $this->app->db()->run('UPDATE {subscriptions} SET version = version + 1 WHERE id = ?', [$id]);
    }

    public function recordPayment(int $id, string $reference, int $amount, string $currency, string $paidAt): array
    {
        if ($reference === '' || strlen($reference) > 120 || Clock::parse($paidAt) <= 0) {
            throw new \InvalidArgumentException('Invalid subscription payment');
        }

        return $this->app->db()->transaction(function (Db $db) use ($id, $reference, $amount, $currency, $paidAt): array {
            // Este UPDATE serializa las cuotas de un mandato tanto en MariaDB como en SQLite.
            $db->run('UPDATE {subscriptions} SET version = version + 1 WHERE id = ?', [$id]);
            $sub = $this->find($id);
            if ($sub === null) {
                throw new \InvalidArgumentException('Unknown subscription');
            }
            $snapshot = self::snapshot($sub);
            if ($amount !== (int) $snapshot['amount'] || $currency !== (string) $snapshot['currency']) {
                throw new \InvalidArgumentException('Subscription payment amount or currency mismatch');
            }
            $key = hash('sha256', $sub['provider'] . ':' . $sub['environment'] . ':' . $reference);
            $old = $db->row('SELECT * FROM {subscription_payments} WHERE payment_key = ?', [$key]);
            if ($old !== null) {
                if ((int) $old['subscription_id'] !== $id) {
                    throw new \InvalidArgumentException('The payment belongs to another subscription');
                }
                return $old;
            }
            $snapshot += ['method' => 'subscription_' . $sub['provider'], 'reference' => $reference, 'test' => $sub['environment'] !== 'live', 'paid_at' => $paidAt, 'subscription_id' => $id];
            $order = $this->app->service('orders')->createRecurringPayment($snapshot, 'subscriptions:' . $key);
            $paymentId = $db->insert('subscription_payments', ['subscription_id' => $id, 'payment_key' => $key, 'reference' => $reference,
                'amount' => $amount, 'currency' => $currency, 'order_id' => (int) $order['id'], 'paid_at' => $paidAt, 'status' => 'paid']);
            $set = ['failures' => 0, 'updated_at' => $this->app->clock()->sql()];
            if (!in_array($sub['status'], ['cancelled', 'expired', 'suspended'], true)) {
                $set['status'] = 'active';
            }
            if ($sub['provider'] === 'simulated' && !in_array($sub['status'], ['cancelled', 'expired'], true)) {
                $set['next_payment_at'] = self::nextMonth($paidAt, (int) substr((string) $sub['consented_at'], 8, 2));
            }
            $db->update('subscriptions', $set, ['id' => $id]);

            return $db->row('SELECT * FROM {subscription_payments} WHERE id = ?', [$paymentId]);
        });
    }

    /** Una cuota simulada; el panel puede adelantarla expresamente para enseñar una renovación ficticia. */
    public function simulate(int $id, string $outcome, bool $advance = false, string $expectedDue = ''): void
    {
        $sub = $this->find($id);
        if ($sub === null || $sub['provider'] !== 'simulated' || !$this->simulationAllowed() || !in_array($sub['status'], ['active', 'past_due'], true)) {
            throw new \InvalidArgumentException('This simulated subscription cannot be charged');
        }
        $due = (string) $sub['next_payment_at'];
        if ($advance && $expectedDue !== $due) {
            throw new \InvalidArgumentException('The simulated payment changed; reload the subscription');
        }
        if ($due === '' || (!$advance && Clock::parse($due) > $this->app->clock()->now())) {
            throw new \InvalidArgumentException('The next monthly payment is not due yet');
        }
        if ($outcome === 'failed') {
            $this->app->db()->run("UPDATE {subscriptions} SET status = 'past_due', failures = failures + 1, updated_at = ? WHERE id = ?", [$this->app->clock()->sql(), $id]);
            return;
        }
        if ($outcome !== 'paid') {
            throw new \InvalidArgumentException('Unknown simulated outcome');
        }
        $snapshot = self::snapshot($sub);
        $this->app->db()->transaction(function (Db $db) use ($id, $sub, $due, $snapshot, $advance): void {
            $db->run('UPDATE {subscriptions} SET version = version + 1 WHERE id = ?', [$id]);
            $fresh = $this->find($id);
            if ($fresh === null || $fresh['next_payment_at'] !== $due || !in_array($fresh['status'], ['active', 'past_due'], true)) {
                throw new \InvalidArgumentException('The simulated payment changed; reload the subscription');
            }
            $this->recordPayment($id, 'SIM-' . $sub['public_id'] . '-' . str_replace(['-', ' ', ':'], '', $due), (int) $snapshot['amount'], (string) $snapshot['currency'], $advance ? $this->app->clock()->sql() : $due);
            $db->update('subscriptions', ['next_payment_at' => self::nextMonth($due, (int) substr((string) $sub['consented_at'], 8, 2))], ['id' => $id]);
        });
    }

    public function refresh(int $id): void
    {
        $sub = $this->find($id);
        if ($sub === null || $sub['provider'] !== 'paypal' || empty($sub['provider_id'])) {
            throw new \InvalidArgumentException('Unknown PayPal subscription');
        }
        $details = (new PayPalGateway($this->app, (string) $sub['environment']))->details((string) $sub['provider_id']);
        $this->applyDetails($sub, $details);
    }

    /** Firma remota antes de leer el evento. El entorno forma parte de la ruta y del mandato. */
    public function notify(string $environment, array $headers, string $body): bool
    {
        $gateway = new PayPalGateway($this->app, $environment);
        if (strlen($body) > 131072 || !$gateway->verify($headers, $body)) {
            return false;
        }
        $event = json_decode($body, true);
        if (!is_array($event) || !isset($event['id'], $event['event_type'], $event['resource']) || !is_string($event['id']) || !is_string($event['event_type']) || !is_array($event['resource'])) {
            return false;
        }
        $kind = (string) $event['event_type'];
        $resource = $event['resource'];
        if (in_array($kind, ['PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.REVERSED'], true)) {
            return $this->refundEvent($environment, $event, $body);
        }
        $sale = $kind === 'PAYMENT.SALE.COMPLETED';
        $providerId = $sale ? (string) ($resource['billing_agreement_id'] ?? '') : (string) ($resource['id'] ?? '');
        if (!$sale && strpos($kind, 'BILLING.SUBSCRIPTION.') !== 0) {
            return true; // Ninguna otra familia puede activar ni cobrar una suscripción.
        }
        $sub = $this->app->db()->row("SELECT * FROM {subscriptions} WHERE provider = 'paypal' AND environment = ? AND provider_id = ?", [$environment, $providerId]);
        if ($sub === null) {
            return true;
        }
        $details = $gateway->details($providerId);
        $this->assertDetails($sub, $details);
        $eventKey = hash('sha256', $environment . ':' . $event['id']);
        $digest = hash('sha256', $body);
        $this->app->db()->transaction(function (Db $db) use ($sub, $eventKey, $digest, $kind, $sale, $resource, $details, $event, $environment): void {
            $id = (int) $sub['id'];
            $db->run('UPDATE {subscriptions} SET version = version + 1 WHERE id = ?', [$id]);
            $old = $db->row('SELECT * FROM {subscription_events} WHERE event_key = ?', [$eventKey]);
            if ($old !== null) {
                if ($old['digest'] !== $digest) {
                    throw new \InvalidArgumentException('A subscription event was reused with different data');
                }
                return;
            }
            if ($sale) {
                $money = isset($resource['amount']) && is_array($resource['amount']) ? $resource['amount'] : [];
                $currency = isset($money['currency']) ? (string) $money['currency'] : '';
                $amount = self::minor((string) ($money['total'] ?? ''), $currency);
                $stamp = (string) ($resource['create_time'] ?? '');
                $at = self::providerTime($stamp);
                if (strtolower((string) ($resource['state'] ?? '')) !== 'completed' || $at === null) {
                    throw new \InvalidArgumentException('The subscription sale is not a completed payment');
                }
                $this->recordPayment($id, (string) ($resource['id'] ?? ''), $amount, $currency, $at);
                $this->drainRefunds($environment, (string) ($resource['id'] ?? ''));
            }
            $this->applyDetails($sub, $details);
            if ($kind === 'BILLING.SUBSCRIPTION.PAYMENT.FAILED') {
                $this->applyFailure($id, $details, $event);
            }
            $db->insert('subscription_events', ['event_key' => $eventKey, 'subscription_id' => $id, 'kind' => $kind, 'digest' => $digest, 'created_at' => $this->app->clock()->sql()]);
        });

        return true;
    }

    /** Una devolución notificada nunca vuelve a pedir al proveedor que devuelva el dinero. */
    private function refundEvent(string $environment, array $event, string $body): bool
    {
        $resource = $event['resource'];
        $reference = (string) ($resource['sale_id'] ?? '');
        if ($event['event_type'] === 'PAYMENT.SALE.REVERSED') {
            $reference = (string) ($resource['id'] ?? '');
        }
        if ($reference === '') {
            foreach (isset($resource['links']) && is_array($resource['links']) ? $resource['links'] : [] as $link) {
                if (is_array($link) && ($link['rel'] ?? '') === 'up') {
                    $path = (string) parse_url((string) ($link['href'] ?? ''), PHP_URL_PATH);
                    if (preg_match('~/v1/payments/sale/([A-Za-z0-9]+)$~', $path, $match) === 1) {
                        $reference = $match[1];
                    }
                }
            }
        }
        $resourceId = (string) ($resource['id'] ?? '');
        if ($resourceId === '' || $reference === '') {
            return false;
        }
        $paymentKey = hash('sha256', 'paypal:' . $environment . ':' . $reference);
        $payment = $this->app->db()->row('SELECT * FROM {subscription_payments} WHERE payment_key = ?', [$paymentKey]);
        if ($payment === null) {
            return $this->holdRefund($environment, $event, $body, $paymentKey);
        }
        $id = (int) $payment['subscription_id'];
        $resourceKey = hash('sha256', $environment . ':' . $event['event_type'] . ':' . $resourceId);
        $eventKey = hash('sha256', $environment . ':' . $event['id']);
        $digest = hash('sha256', $body);
        $this->app->db()->transaction(function (Db $db) use ($id, $payment, $resource, $resourceKey, $eventKey, $event, $digest): void {
            $db->run('UPDATE {subscriptions} SET version = version + 1 WHERE id = ?', [$id]);
            $old = $db->row('SELECT * FROM {subscription_events} WHERE event_key = ? OR resource_key = ?', [$eventKey, $resourceKey]);
            if ($old !== null) {
                if ($old['event_key'] === $eventKey && $old['digest'] !== $digest) {
                    throw new \InvalidArgumentException('A refund event was reused with different data');
                }
                if ((int) $old['processed'] === 1) {
                    return;
                }
            }
            $fresh = $db->row('SELECT * FROM {subscription_payments} WHERE id = ?', [(int) $payment['id']]);
            $money = isset($resource['amount']) && is_array($resource['amount']) ? $resource['amount'] : [];
            $currency = (string) ($money['currency'] ?? '');
            $amount = self::minor((string) ($money['total'] ?? ''), $currency);
            if ($currency !== $fresh['currency'] || $amount <= 0 || $amount > (int) $fresh['amount'] - (int) $fresh['refunded_amount']) {
                throw new \InvalidArgumentException('Invalid subscription refund amount or currency');
            }
            $this->app->service('orders')->refund((int) $fresh['order_id'], $amount, [], 'PayPal subscription payment returned', false, false, null);
            $returned = (int) $fresh['refunded_amount'] + $amount;
            $db->update('subscription_payments', ['refunded_amount' => $returned, 'status' => $returned === (int) $fresh['amount'] ? 'refunded' : 'part_refunded'], ['id' => (int) $fresh['id']]);
            if ($event['event_type'] === 'PAYMENT.SALE.REVERSED') {
                $db->run("UPDATE {subscriptions} SET status = 'past_due' WHERE id = ? AND status = 'active'", [$id]);
            }
            if ($old !== null) {
                $db->update('subscription_events', ['subscription_id' => $id, 'processed' => 1, 'body' => null], ['id' => (int) $old['id']]);
            } else {
                $db->insert('subscription_events', ['event_key' => $eventKey, 'resource_key' => $resourceKey, 'subscription_id' => $id, 'kind' => $event['event_type'], 'digest' => $digest, 'created_at' => $this->app->clock()->sql()]);
            }
        });
        return true;
    }

    /** El proveedor puede entregar primero la devolución. Solo se acepta después de conservarla de forma duradera. */
    private function holdRefund(string $environment, array $event, string $body, string $paymentKey): bool
    {
        $eventKey = hash('sha256', $environment . ':' . $event['id']);
        $resourceKey = hash('sha256', $environment . ':' . $event['event_type'] . ':' . $event['resource']['id']);
        $digest = hash('sha256', $body);
        $db = $this->app->db();
        try {
            $db->transaction(function (Db $db) use ($eventKey, $resourceKey, $digest, $event, $body, $paymentKey, $environment): void {
                $old = $db->row('SELECT * FROM {subscription_events} WHERE event_key = ? OR resource_key = ?', [$eventKey, $resourceKey]);
                if ($old !== null) {
                    if ($old['event_key'] === $eventKey && $old['digest'] !== $digest) {
                        throw new \InvalidArgumentException('A refund event was reused with different data');
                    }
                    return;
                }
                $db->insert('subscription_events', ['event_key' => $eventKey, 'resource_key' => $resourceKey, 'subscription_id' => 0,
                    'kind' => $event['event_type'], 'digest' => $digest, 'payment_key' => $paymentKey, 'body' => $body, 'processed' => 0, 'created_at' => $this->app->clock()->sql()]);
                $locking = $db->driver() === 'mysql' ? ' FOR UPDATE' : '';
                if ($db->value('SELECT id FROM {subscription_payments} WHERE payment_key = ?' . $locking, [$paymentKey]) !== null) {
                    // La búsqueda por clave usa el mismo entorno verificado con el que se conservó este aviso.
                    if (!$this->refundEvent($environment, $event, $body)) {
                        throw new \UnexpectedValueException('A stored subscription refund could not be processed');
                    }
                }
            });
        } catch (\PDOException $e) {
            // Dos entregas paralelas pueden competir por la misma clave; solo se acepta si quedó conservada exactamente.
            $old = $db->row('SELECT * FROM {subscription_events} WHERE event_key = ?', [$eventKey]);
            if ($old === null || $old['digest'] !== $digest) {
                throw $e;
            }
        }
        return true;
    }

    private function drainRefunds(string $environment, string $reference): void
    {
        $key = hash('sha256', 'paypal:' . $environment . ':' . $reference);
        $locking = $this->app->db()->driver() === 'mysql' ? ' FOR UPDATE' : '';
        $rows = $this->app->db()->select('SELECT body FROM {subscription_events} WHERE payment_key = ? AND processed = 0 ORDER BY id LIMIT 100' . $locking, [$key]);
        foreach ($rows as $row) {
            $event = json_decode((string) $row['body'], true);
            if (!is_array($event) || !$this->refundEvent($environment, $event, (string) $row['body'])) {
                throw new \UnexpectedValueException('A stored subscription refund could not be processed');
            }
        }
    }

    /** Un aviso antiguo de impago no pisa un cobro posterior que ya consta en PayPal o en la tienda. */
    private function applyFailure(int $id, array $details, array $event): void
    {
        $billing = isset($details['billing_info']) && is_array($details['billing_info']) ? $details['billing_info'] : [];
        $failure = isset($billing['last_failed_payment']) && is_array($billing['last_failed_payment']) ? $billing['last_failed_payment'] : [];
        $last = isset($billing['last_payment']) && is_array($billing['last_payment']) ? $billing['last_payment'] : [];
        $failedAt = self::providerTime(isset($failure['time']) && is_string($failure['time']) ? $failure['time'] : '');
        $failedAt = $failedAt !== null ? $failedAt : self::providerTime(isset($event['create_time']) && is_string($event['create_time']) ? $event['create_time'] : '');
        $failedAt = $failedAt !== null ? $failedAt : $this->app->clock()->sql();
        $remotePaid = self::providerTime(isset($last['time']) && is_string($last['time']) ? $last['time'] : '');
        $localPaid = $this->app->db()->value('SELECT MAX(paid_at) FROM {subscription_payments} WHERE subscription_id = ?', [$id]);
        $latestPaid = max(is_string($localPaid) ? $localPaid : '', $remotePaid !== null ? $remotePaid : '');
        if ($latestPaid !== '' && $latestPaid >= $failedAt) {
            return;
        }
        $failures = isset($billing['failed_payments_count']) && is_numeric($billing['failed_payments_count']) ? max(1, (int) $billing['failed_payments_count']) : 1;
        $this->app->db()->run("UPDATE {subscriptions} SET failures = ?, status = CASE WHEN status IN ('cancelled','expired','suspended') THEN status ELSE 'past_due' END WHERE id = ?", [$failures, $id]);
    }

    private function assertDetails(array $sub, array $details): void
    {
        if (($details['id'] ?? '') !== $sub['provider_id'] || ($details['custom_id'] ?? '') !== $sub['public_id'] || ($details['plan_id'] ?? '') !== $sub['provider_plan_id']) {
            throw new \InvalidArgumentException('PayPal subscription identity mismatch');
        }
    }

    private function applyDetails(array $sub, array $details): void
    {
        $this->assertDetails($sub, $details);
        $map = ['APPROVAL_PENDING' => 'pending', 'APPROVED' => 'pending', 'ACTIVE' => 'active', 'SUSPENDED' => 'suspended', 'CANCELLED' => 'cancelled', 'EXPIRED' => 'expired'];
        $status = $map[(string) ($details['status'] ?? '')] ?? '';
        if ($status === '') {
            throw new \UnexpectedValueException('Unknown PayPal subscription status');
        }
        $current = $this->find((int) $sub['id']);
        if ($current === null || (in_array($current['status'], ['cancelled', 'expired'], true) && !in_array($status, ['cancelled', 'expired'], true))) {
            return;
        }
        $set = ['status' => $status, 'updated_at' => $this->app->clock()->sql(), 'next_payment_at' => self::providerTime((string) ($details['billing_info']['next_billing_time'] ?? ''))];
        if (in_array($status, ['cancelled', 'expired'], true)) {
            $set['next_payment_at'] = null;
        }
        if ($status === 'cancelled') {
            $set['cancelled_at'] = $this->app->clock()->sql();
        }
        // Una lectura remota iniciada antes de una cancelación local no vuelve a activar el mandato.
        $this->app->db()->update('subscriptions', $set, ['id' => (int) $sub['id'], 'status' => (string) $current['status']]);
    }

    public static function snapshot(array $sub): array
    {
        $decoded = json_decode((string) $sub['snapshot'], true);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Invalid subscription snapshot');
        }

        return $decoded;
    }

    public static function nextMonth(string $sql, int $anchor = 0): string
    {
        $date = new \DateTimeImmutable($sql, new \DateTimeZone('UTC'));
        $day = $anchor >= 1 && $anchor <= 31 ? $anchor : (int) $date->format('d');
        $next = $date->modify('first day of next month');

        return $next->setDate((int) $next->format('Y'), (int) $next->format('m'), min($day, (int) $next->format('t')))->format('Y-m-d H:i:s');
    }

    private static function providerTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function minor(string $value, string $currency): int
    {
        $decimals = PayPalClient::currencyDecimals($currency);
        if (preg_match('/^([0-9]{1,9})(?:\.([0-9]{1,2}))?$/', $value, $match) !== 1 || ($decimals === 0 && !empty($match[2]) && (int) $match[2] !== 0)) {
            throw new \InvalidArgumentException('Invalid payment amount');
        }

        return $decimals === 0 ? (int) $match[1] : (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
