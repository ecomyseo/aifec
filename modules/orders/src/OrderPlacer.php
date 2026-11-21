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

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Modules\Customers\Customers;
use Aifec\Security\Random;

/**
 * Crea el pedido a partir del carrito (la mitad de place() que escribe en la base de datos).
 *
 * Primero se validan los datos de la caja sin tocar nada. Después, DENTRO de una transacción: se vuelve a calcular el
 * carrito con el destino de la dirección de envío, se comprueban envío, forma de pago y avisos, se saca el número, se
 * descuentan las existencias línea a línea (sentencia condicionada del catálogo), se crea o reutiliza el cliente, se
 * guarda el pedido con sus líneas, se canjean los cupones, se apuntan los consentimientos y el carrito queda convertido.
 * Cualquier fallo lanza OrderFailure y la transacción lo deshace todo.
 *
 * Las líneas de order_items suman el total: lo que cuesta cada producto (ya con su parte del descuento), el envío, los
 * recargos y los ajustes con su importe real. Los descuentos por cupón o grupo salen aparte como líneas informativas
 * («informational» en data, base e impuesto a cero) porque ya están repartidos en las líneas de producto.
 */
final class OrderPlacer
{
    private const SOURCES = ['web', 'assistant', 'api', 'mcp', 'ucp', 'acp', 'admin', 'import'];

    /** @var App */
    private $app;

    /** @var OrderLog */
    private $log;

    /** @var OrderStock */
    private $stock;

    /** @var Sequence */
    private $sequence;

    public function __construct(App $app, OrderLog $log, OrderStock $stock, Sequence $sequence)
    {
        $this->app = $app;
        $this->log = $log;
        $this->stock = $stock;
        $this->sequence = $sequence;
    }

    /**
     * @param array<string,mixed> $checkout ver Orders::place()
     * @param array<string,mixed> $options  source, attribution, ip
     *
     * @return array<string,mixed> ['ok' => true, 'id', 'number', 'secret'] o ['ok' => false, 'error', 'errors']
     */
    public function place(array $checkout, array $options): array
    {
        $app = $this->app;
        /** @var Cart $cart */
        $cart = $app->service('cart');
        $summary = $cart->summary();
        if ($summary['id'] === null || (int) $summary['count'] < 1 || $summary['lines'] === []) {
            return $this->fail(t('Your cart is empty'));
        }
        // El cliente: el que ha entrado, salvo que el carrito se calcule para otro (el pedido a mano del panel, Cart::actAs()).
        $acting = $cart->actingAs();
        $customer = $acting !== false ? $acting : ($app->has('customers') ? $app->service('customers')->current() : null);

        // 1. Los datos de la caja, sin tocar nada.
        $email = $this->email($checkout, $summary, $customer);
        $billing = $this->address(isset($checkout['billing']) ? $checkout['billing'] : []);
        $shippingGiven = isset($checkout['shipping']) && is_array($checkout['shipping']) && $checkout['shipping'] !== [] ? $this->address($checkout['shipping']) : null;
        $needsShipping = !empty($summary['needs_shipping']);
        $createAccount = $customer === null && self::truthy(isset($checkout['create_account']) ? $checkout['create_account'] : false);
        $password = isset($checkout['password']) && is_string($checkout['password']) ? $checkout['password'] : '';
        $errors = $this->validate($checkout, $email, $billing, $shippingGiven, $needsShipping, $customer, $createAccount, $password);
        if ($errors !== []) {
            return $this->fail(reset($errors), $errors);
        }
        $shipping = $needsShipping ? ($shippingGiven !== null ? $shippingGiven : $billing) : null;
        $destinationAddress = $shipping !== null ? $shipping : $billing;
        $destination = [
            'country' => $destinationAddress['country'], 'region' => $destinationAddress['region'],
            'postcode' => $destinationAddress['postcode'], 'city' => $destinationAddress['city'],
        ];
        $source = isset($options['source']) && is_string($options['source']) && in_array($options['source'], self::SOURCES, true) ? $options['source'] : 'web';

        // 2. La transacción.
        try {
            $placed = $app->db()->transaction(function (Db $db) use ($cart, $destination, $email, $billing, $shipping, $checkout, $customer, $createAccount, $password, $options, $source): array {
                return $this->create($db, $cart, $destination, $email, $billing, $shipping, $checkout, $customer, $createAccount, $password, $options, $source);
            });
        } catch (OrderFailure $e) {
            return $this->fail($e->getMessage(), $e->errors());
        }

        return ['ok' => true, 'error' => '', 'errors' => [], 'id' => $placed['id'], 'number' => $placed['number'], 'secret' => $placed['secret']];
    }

    /**
     * Todo lo que se escribe, dentro de la transacción.
     *
     * @param array<string,string>      $destination
     * @param array<string,string>      $billing
     * @param array<string,string>|null $shipping
     * @param array<string,mixed>       $checkout
     * @param array<string,mixed>|null  $customer
     * @param array<string,mixed>       $options
     *
     * @return array{id:int,number:string,secret:string}
     */
    private function create(Db $db, Cart $cart, array $destination, string $email, array $billing, ?array $shipping, array $checkout, ?array $customer, bool $createAccount, string $password, array $options, string $source): array
    {
        $app = $this->app;
        $now = $app->clock()->sql();

        // El carrito con el destino de verdad.
        $cart->setDestination($destination);
        $s = $cart->summary();
        if ($s['id'] === null || $s['lines'] === []) {
            throw new OrderFailure(t('Your cart is empty'));
        }
        if ($s['problems'] !== []) {
            throw new OrderFailure((string) $s['problems'][0], ['cart' => (string) $s['problems'][0]]);
        }
        foreach ($s['lines'] as $line) {
            if (empty($line['available'])) {
                throw new OrderFailure(t('This product is no longer available'), ['cart' => (string) $line['name']]);
            }
        }
        if (!empty($s['needs_shipping']) && ($s['carrier_id'] === null || $s['totals']['shipping'] === null)) {
            throw new OrderFailure(t('We cannot deliver to this destination'), ['carrier' => t('We cannot deliver to this destination')]);
        }
        $method = $s['payment_method'] !== null ? $app->service('payments')->get((string) $s['payment_method']) : null;
        if ($method === null) {
            throw new OrderFailure(t('Choose a payment method'), ['payment' => t('Choose a payment method')]);
        }
        $totals = $s['totals'];
        $info = $method->info();

        // Los cupones, antes de crear el pedido: «solo el primer pedido» no debe contar el que se está creando.
        $coupons = $this->couponsToRedeem($s, $email, $customer);

        // El número.
        $number = Sequence::format($app->settings()->str('orders.number_format', Sequence::DEFAULT_FORMAT), $this->sequence->next('order'), (int) substr($now, 0, 4));

        // Las existencias, línea a línea. Una sola que falle deshace todo (también lo ya descontado).
        foreach ($s['lines'] as $line) {
            $ok = $app->service('catalog')->adjustStock((int) $line['product_id'], (int) $line['variant_id'], -(int) $line['qty'], 'Order placed', $number);
            if (!$ok) {
                $message = t('There is not enough stock left of {name}', ['name' => (string) $line['name']]);
                throw new OrderFailure($message, ['cart' => $message]);
            }
        }

        // El cliente.
        $customerId = $this->customerId($customer, $email, $billing, $createAccount, $password);

        // El pedido.
        $raw = Orders::viewToken($app, $number);
        $carrierTitle = null;
        $carrierId = null;
        if (!empty($s['needs_shipping'])) {
            $carrierId = (int) $s['carrier_id'];
            $carrierTitle = (string) $totals['shipping']['label'];
        }
        $ip = isset($options['ip']) && is_string($options['ip']) && filter_var($options['ip'], FILTER_VALIDATE_IP) !== false ? $options['ip'] : null;
        $note = isset($checkout['note']) && is_scalar($checkout['note']) ? trim((string) $checkout['note']) : '';
        $orderId = $db->insert('orders', [
            'number' => $number,
            'customer_id' => $customerId,
            'email' => $email,
            'lang' => (string) $s['lang'],
            'currency' => (string) $s['currency'],
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'fulfillment_status' => !empty($s['needs_shipping']) ? 'unfulfilled' : 'not_needed',
            'payment_method' => strtolower(trim($method->code())),
            'payment_title' => mb_substr((string) $info['title'], 0, 120, 'UTF-8'),
            'carrier_id' => $carrierId,
            'carrier_title' => $carrierTitle !== null ? mb_substr($carrierTitle, 0, 120, 'UTF-8') : null,
            'prices_include_tax' => !empty($totals['prices_include_tax']) ? 1 : 0,
            'items_total' => (int) $totals['items_total'],
            'discount_total' => (int) $totals['discount_total'],
            'shipping_total' => $totals['shipping'] !== null ? (int) $totals['shipping']['price'] : 0,
            'fee_total' => (int) $totals['fee_total'],
            'total_net' => (int) $totals['total_net'],
            'total_tax' => (int) $totals['total_tax'],
            'total' => (int) $totals['total'],
            'paid_total' => 0,
            'refunded_total' => 0,
            'taxes' => OrderLog::json($totals['taxes']),
            'adjustments' => OrderLog::json($totals['adjustments']),
            'billing' => OrderLog::json($billing),
            'shipping' => $shipping !== null ? OrderLog::json($shipping) : null,
            'coupons' => OrderLog::json($this->couponSummary($coupons)),
            'customer_note' => $note !== '' ? mb_substr($note, 0, 2000, 'UTF-8') : null,
            'source' => $source,
            'attribution' => OrderLog::json(isset($options['attribution']) && is_array($options['attribution']) ? $options['attribution'] : []),
            'secret' => hash('sha256', $raw),
            'cart_id' => (int) $s['id'],
            'test' => $method->isTest() ? 1 : 0,
            'ip' => $ip,
            'placed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertItems($db, $orderId, $s, $totals, $coupons);

        $this->log->add($orderId, 'status', null, 'pending_payment', t('Order placed'), true);

        // Los cupones: uno que se agote en este instante deshace el pedido.
        foreach ($coupons as $coupon) {
            if (!$app->service('coupons')->redeem((int) $coupon['id'], $orderId, $customerId, $email, (int) $coupon['amount'])) {
                throw new OrderFailure(t('A coupon in your cart has just run out'), ['coupon' => t('A coupon in your cart has just run out')]);
            }
        }

        // Los consentimientos.
        $this->consents($customerId, $email, $number, $checkout);

        // El carrito deja de ser un carrito: queda con su pedido y se olvida su cookie.
        $cart->markConverted($orderId);

        return ['id' => $orderId, 'number' => $number, 'secret' => $raw];
    }

    /**
     * Las líneas del pedido: productos, envío, recargos, ajustes y descuentos (estos, solo informativos).
     *
     * @param array<string,mixed>            $s
     * @param array<string,mixed>            $totals
     * @param array<int,array<string,mixed>> $coupons
     */
    private function insertItems(Db $db, int $orderId, array $s, array $totals, array $coupons): void
    {
        $position = 0;
        $sumNet = 0;
        $sumTax = 0;
        foreach ($s['lines'] as $line) {
            $data = isset($line['data']) && is_array($line['data']) ? $line['data'] : [];
            $db->insert('order_items', [
                'order_id' => $orderId, 'kind' => 'product', 'product_id' => (int) $line['product_id'], 'variant_id' => (int) $line['variant_id'],
                'sku' => $line['sku'] !== '' ? mb_substr((string) $line['sku'], 0, 64, 'UTF-8') : null,
                'name' => mb_substr((string) $line['name'], 0, 191, 'UTF-8'),
                'variant_label' => $line['variant_label'] !== '' ? mb_substr((string) $line['variant_label'], 0, 191, 'UTF-8') : null,
                'qty' => (int) $line['qty'], 'unit_price' => (int) $line['unit_price'], 'discount' => (int) $line['discount'],
                'line_total' => (int) $line['total'], 'line_net' => (int) $line['net'], 'line_tax' => (int) $line['tax'],
                'tax_rate' => (int) $line['tax_rate'], 'tax_name' => $line['tax_name'] !== '' ? mb_substr((string) $line['tax_name'], 0, 60, 'UTF-8') : null,
                'weight' => (int) $line['weight'], 'is_digital' => !empty($line['is_digital']) ? 1 : 0,
                'refunded_qty' => 0, 'refunded_total' => 0, 'data' => OrderLog::json($data), 'position' => $position++,
            ]);
            $sumNet += (int) $line['net'];
            $sumTax += (int) $line['tax'];
        }
        if ($totals['shipping'] !== null) {
            $ship = $totals['shipping'];
            $this->insertExtra($db, $orderId, 'shipping', (string) $ship['label'], (int) $ship['price'], $ship, [], $position++);
            $sumNet += (int) $ship['net'];
            $sumTax += (int) $ship['tax'];
        }
        foreach ($totals['fees'] as $fee) {
            $this->insertExtra($db, $orderId, 'fee', (string) $fee['label'], (int) $fee['amount'], $fee, [], $position++);
            $sumNet += (int) $fee['net'];
            $sumTax += (int) $fee['tax'];
        }
        foreach ($totals['adjustments'] as $adjustment) {
            $kind = (int) $adjustment['amount'] < 0 ? 'discount' : 'fee';
            $this->insertExtra($db, $orderId, $kind, (string) $adjustment['label'], (int) $adjustment['amount'], $adjustment, ['adjustment' => true, 'source' => (string) $adjustment['source']], $position++);
            $sumNet += (int) $adjustment['net'];
            $sumTax += (int) $adjustment['tax'];
        }
        // Descuentos ya repartidos en las líneas de producto: se enseñan, no suman.
        foreach ($totals['discounts'] as $discount) {
            if ((int) $discount['amount'] < 1) {
                continue;
            }
            $label = trim((string) $discount['label']) !== '' ? (string) $discount['label'] : (trim((string) $discount['code']) !== '' ? (string) $discount['code'] : t('Discount'));
            $db->insert('order_items', [
                'order_id' => $orderId, 'kind' => 'discount', 'name' => mb_substr($label, 0, 191, 'UTF-8'), 'qty' => 1,
                'unit_price' => -(int) $discount['amount'], 'discount' => 0, 'line_total' => -(int) $discount['amount'],
                'line_net' => 0, 'line_tax' => 0, 'tax_rate' => 0, 'weight' => 0, 'is_digital' => 0, 'refunded_qty' => 0, 'refunded_total' => 0,
                'data' => OrderLog::json(['informational' => true, 'code' => (string) $discount['code'], 'kind' => (string) $discount['kind']]),
                'position' => $position++,
            ]);
        }
        if ($sumNet + $sumTax !== (int) $totals['total']) {
            throw new \LogicException('The order lines do not add up to the order total');
        }
    }

    /**
     * Una línea de envío, recargo o ajuste (importes «tal como se cobraron» y su base e impuesto).
     *
     * @param array<string,mixed> $source   el bloque de Totals (net, tax, tax_rate, tax_name)
     * @param array<string,mixed> $data
     */
    private function insertExtra(Db $db, int $orderId, string $kind, string $name, int $amount, array $source, array $data, int $position): void
    {
        $db->insert('order_items', [
            'order_id' => $orderId, 'kind' => $kind, 'name' => mb_substr($name !== '' ? $name : t('Price adjustment'), 0, 191, 'UTF-8'), 'qty' => 1,
            'unit_price' => $amount, 'discount' => 0, 'line_total' => $amount, 'line_net' => (int) $source['net'], 'line_tax' => (int) $source['tax'],
            'tax_rate' => (int) $source['tax_rate'], 'tax_name' => (string) $source['tax_name'] !== '' ? mb_substr((string) $source['tax_name'], 0, 60, 'UTF-8') : null,
            'weight' => 0, 'is_digital' => 0, 'refunded_qty' => 0, 'refunded_total' => 0, 'data' => OrderLog::json($data), 'position' => $position,
        ]);
    }

    /**
     * Los cupones aplicados con su identificador (lo pide redeem), comprobados otra vez contra el carrito de ahora.
     *
     * @param array<string,mixed>      $s
     * @param array<string,mixed>|null $customer
     *
     * @return array<int,array{id:int,code:string,label:string,amount:int}>
     */
    private function couponsToRedeem(array $s, string $email, ?array $customer): array
    {
        $applied = isset($s['coupons']) && is_array($s['coupons']) ? $s['coupons'] : [];
        if ($applied === [] || !$this->app->has('coupons')) {
            return [];
        }
        $catalog = $this->app->service('catalog');
        $lines = [];
        $itemsTotal = 0;
        foreach ($s['lines'] as $line) {
            $p = $catalog->purchasable((int) $line['product_id'], (int) $line['variant_id'], (string) $s['lang']);
            $lines[] = [
                'key' => (string) $line['key'], 'product_id' => (int) $line['product_id'],
                'category_ids' => $p !== null && isset($p['category_ids']) && is_array($p['category_ids']) ? $p['category_ids'] : [],
                'unit_price' => (int) $line['unit_price'], 'qty' => (int) $line['qty'], 'on_sale' => !empty($line['on_sale']),
            ];
            $itemsTotal += (int) $line['unit_price'] * (int) $line['qty'];
        }
        $cartData = ['lines' => $lines, 'items_total' => $itemsTotal, 'customer_id' => $customer !== null ? (int) $customer['id'] : null, 'email' => $email];
        $out = [];
        foreach ($applied as $coupon) {
            $result = $this->app->service('coupons')->check((string) $coupon['code'], $cartData);
            if (empty($result['ok']) || !is_array($result['coupon'])) {
                $message = !empty($result['error']) ? (string) $result['error'] : t('A coupon in your cart is no longer valid');
                throw new OrderFailure($message, ['coupon' => $message]);
            }
            $out[] = ['id' => (int) $result['coupon']['id'], 'code' => (string) $coupon['code'], 'label' => (string) $coupon['label'], 'amount' => (int) $coupon['amount']];
        }

        return $out;
    }

    /**
     * Lo que se guarda del cupón en el pedido.
     *
     * @param array<int,array{id:int,code:string,label:string,amount:int}> $coupons
     *
     * @return array<int,array<string,mixed>>
     */
    private function couponSummary(array $coupons): array
    {
        $out = [];
        foreach ($coupons as $coupon) {
            $out[] = ['coupon_id' => $coupon['id'], 'code' => $coupon['code'], 'label' => $coupon['label'], 'amount' => $coupon['amount']];
        }

        return $out;
    }

    /**
     * El cliente del pedido: el que ha entrado, una cuenta nueva si la pidió, o un invitado.
     *
     * @param array<string,mixed>|null $customer
     * @param array<string,string>     $billing
     */
    private function customerId(?array $customer, string $email, array $billing, bool $createAccount, string $password): ?int
    {
        if (!$this->app->has('customers')) {
            return null;
        }
        if ($customer !== null) {
            return (int) $customer['id'];
        }
        $customers = $this->app->service('customers');
        $data = [
            'first_name' => $billing['first_name'], 'last_name' => $billing['last_name'], 'company' => $billing['company'],
            'vat_id' => $billing['vat_id'], 'phone' => $billing['phone'], 'locale' => $this->app->lang(),
        ];
        if ($createAccount) {
            try {
                return $customers->register($data + ['email' => $email, 'password' => $password]);
            } catch (\InvalidArgumentException $e) {
                // El correo ya es de una cuenta o el registro está cerrado: el pedido sigue sin decirlo (el dueño ya recibe su aviso).
                $this->app->log()->info('Account creation at checkout was refused', ['reason' => $e->getMessage()]);
            }
        }

        return $customers->ensureGuest($email, $data);
    }

    /**
     * @param array<string,mixed> $checkout
     */
    private function consents(?int $customerId, string $email, string $number, array $checkout): void
    {
        if (!$this->app->has('customers')) {
            return;
        }
        $customers = $this->app->service('customers');
        $page = $this->app->settings()->int('orders.terms_page_id', 0);
        $customers->consent($customerId, $email, 'terms', true, $page > 0 ? (string) $page : '', 'order:' . $number);
        if (self::truthy(isset($checkout['marketing']) ? $checkout['marketing'] : false)) {
            $customers->consent($customerId, $email, 'marketing', true, '', 'order:' . $number);
        }
    }

    // ------------------------------------------------------------------ validación

    /**
     * @param array<string,mixed>       $checkout
     * @param array<string,string>      $billing
     * @param array<string,string>|null $shippingGiven
     * @param array<string,mixed>|null  $customer
     *
     * @return array<string,string> campo => mensaje (vacío = todo bien)
     */
    private function validate(array $checkout, string $email, array $billing, ?array $shippingGiven, bool $needsShipping, ?array $customer, bool $createAccount, string $password): array
    {
        $errors = [];
        $settings = $this->app->settings();
        if ($email === '') {
            $errors['email'] = t('Enter a valid email address');
        }
        if (trim($billing['first_name'] . $billing['last_name']) === '') {
            $errors['billing.first_name'] = t('Enter the name for the invoice');
        }
        if ($settings->bool('orders.require_vat_id', false) && $billing['vat_id'] === '') {
            $errors['billing.vat_id'] = t('Enter the tax ID for the invoice');
        }
        if ($needsShipping) {
            // La entrega lleva teléfono: lo exigen todos los transportistas (16/11/2026).
            $errors += $this->incomplete($billing, 'billing', $shippingGiven === null);
            if ($shippingGiven !== null) {
                $errors += $this->incomplete($shippingGiven, 'shipping', true);
            }
        }
        if (!self::truthy(isset($checkout['accept_terms']) ? $checkout['accept_terms'] : false)) {
            $errors['accept_terms'] = t('You must accept the terms and conditions');
        }
        if ($customer === null && !$createAccount && !$settings->bool('orders.guest_checkout', true)) {
            $errors['email'] = t('Sign in or create an account to place your order');
        }
        if ($createAccount && (mb_strlen($password, 'UTF-8') < Customers::MIN_PASSWORD || strlen($password) > Customers::MAX_PASSWORD)) {
            $errors['password'] = t('The password must be at least {n} characters long', ['n' => Customers::MIN_PASSWORD]);
        }

        return $errors;
    }

    /**
     * Lo que falta de una dirección de envío o de facturación completa.
     *
     * @param array<string,string> $address
     *
     * @return array<string,string>
     */
    private function incomplete(array $address, string $prefix, bool $delivery = false): array
    {
        $errors = [];
        if (trim($address['first_name'] . $address['last_name']) === '') {
            $errors[$prefix . '.first_name'] = t('Enter the name');
        }
        foreach (['line1' => t('Enter the address'), 'postcode' => t('Enter the postcode'), 'city' => t('Enter the city')] as $field => $message) {
            if ($address[$field] === '') {
                $errors[$prefix . '.' . $field] = $message;
            }
        }
        if ($delivery) {
            $phone = DeliveryPhone::problem($address['phone']);
            if ($phone !== '') {
                $errors[$prefix . '.phone'] = $phone;
            }
        }
        if (preg_match('/^[A-Z]{2}$/D', $address['country']) !== 1) {
            $errors[$prefix . '.country'] = t('Choose the country');
        }

        return $errors;
    }

    /**
     * El correo del pedido: el de la caja, o el que ya tenía el carrito, o el de la cuenta. Vacío si no es válido.
     *
     * @param array<string,mixed>      $checkout
     * @param array<string,mixed>      $summary
     * @param array<string,mixed>|null $customer
     */
    private function email(array $checkout, array $summary, ?array $customer): string
    {
        $candidates = [
            isset($checkout['email']) ? $checkout['email'] : '',
            isset($summary['email']) ? $summary['email'] : '',
            $customer !== null && isset($customer['email']) ? $customer['email'] : '',
        ];
        foreach ($candidates as $candidate) {
            $email = is_string($candidate) ? strtolower(trim($candidate)) : '';
            if ($email !== '') {
                return strlen($email) <= 191 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
            }
        }

        return '';
    }

    /**
     * Una dirección con todas sus claves, solo texto y con los largos de las columnas.
     *
     * @param mixed $raw
     *
     * @return array<string,string>
     */
    private function address($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $limits = ['first_name' => 80, 'last_name' => 120, 'company' => 160, 'vat_id' => 40, 'phone' => 40, 'line1' => 191, 'line2' => 191, 'postcode' => 16, 'city' => 100, 'region' => 16, 'country' => 2];
        $out = [];
        foreach ($limits as $key => $max) {
            $value = isset($raw[$key]) && is_scalar($raw[$key]) ? trim((string) $raw[$key]) : '';
            $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
            $value = mb_substr(trim(is_string($clean) ? $clean : $value), 0, $max, 'UTF-8');
            $out[$key] = $key === 'country' || $key === 'postcode' ? strtoupper($value) : $value;
        }
        // Sin provincia pero con un código postal español válido, la provincia sale de las dos primeras cifras. Una que
        // ya vino escrita no se toca.
        if ($out['region'] === '' && $out['country'] === 'ES') {
            $province = \Aifec\Geo\Spain::provinceByPostcode($out['postcode']);
            $out['region'] = $province !== null ? (string) $province['prefix'] : '';
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function truthy($value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
        }

        return !empty($value);
    }

    /**
     * @param array<string,string> $errors
     *
     * @return array<string,mixed>
     */
    private function fail(string $error, array $errors = []): array
    {
        return ['ok' => false, 'error' => $error, 'errors' => $errors, 'id' => 0, 'number' => '', 'secret' => ''];
    }
}
