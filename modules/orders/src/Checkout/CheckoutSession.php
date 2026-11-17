<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Checkout;

use Aifec\Commerce\PaymentAction;
use Aifec\Geo\Countries;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Cart;
use Aifec\Modules\Orders\CartView;
use Aifec\Modules\Orders\DeliveryPhone;
use Aifec\Modules\Orders\Front\CheckoutState;
use Aifec\Modules\Orders\Front\TotalsView;
use Aifec\Modules\Orders\Orders;

/**
 * El motor único de la caja (servicio «checkout»): lo usan la página web, el asistente y la API. No pinta HTML y no lee
 * la petición: trabaja sobre el carrito y guarda lo que se va rellenando en su borrador, el mismo que lee la página.
 *
 * Un pedido por conversación sigue siempre estos pasos, en cualquier canal:
 *   1. understand() y fill(): entender lo que escribe la persona y guardar lo que vale.
 *   2. setShipping() y setPayment(): elegir envío y forma de pago.
 *   3. review(): el pedido exacto que se haría, con su ficha de confirmación (confirm_token).
 *   4. place(): solo con esa ficha. Si entre la revisión y la confirmación cambia algo (una cantidad, la dirección, el
 *      envío, el pago, un cupón o el total), la ficha deja de valer y no se crea nada.
 *
 * Todo importe sale del carrito (Cart::summary(), que usa Totals): aquí no se suma nada. El pedido lo crea Orders::place(),
 * con los mismos datos que la caja de siempre: el pedido que sale es idéntico.
 *
 * Los errores de un campo llevan la clave del formulario de la página: «email», «shipping.postcode», «billing.vat_id»,
 * «address» (la dirección guardada que se eligió), «carrier», «payment» y «accept_terms».
 */
final class CheckoutSession
{
    /** Campos de una dirección de la caja. */
    private const ADDRESS_KEYS = ['first_name', 'last_name', 'company', 'vat_id', 'phone', 'line1', 'line2', 'postcode', 'city', 'region', 'country'];

    /** Lo que se pide para dar una compra por lista, en este orden. */
    private const MISSING_ORDER = ['contact', 'address', 'shipping', 'payment'];

    /** @var App */
    private $app;

    /** @var Cart|null el carrito de otra petición; null = el de la petición en curso */
    private $cart;

    /** @var object|null la petición a la que pertenecen el cliente y las direcciones de abajo */
    private $peopleRequest;

    /** @var array<string,mixed>|null el cliente que ha entrado en esa petición */
    private $customer;

    /** @var string quién es el cliente de esas direcciones (para no mezclar dos clientes de una misma aplicación) */
    private $peopleKey = '';

    /** @var array<int,array<string,mixed>> */
    private $addresses = [];

    public function __construct(App $app, ?Cart $cart = null)
    {
        $this->app = $app;
        $this->cart = $cart;
    }

    /** El mismo motor sobre otro carrito. place() solo crea el pedido del carrito de la petición en curso. */
    public function forCart(Cart $cart): self
    {
        return new self($this->app, $cart);
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Cómo está la compra ahora.
     *
     * Claves: contact (email, account), address (la dirección de entrega; si todo es digital, la de facturación, con
     * saved_id si se eligió una guardada), billing (la que irá a la factura), billing_other, needs_shipping, note,
     * shipping (selected, options [id, name, delay, price, price_formatted, free, selected]), payment (selected, options
     * [id, name, kind, description, fee, fee_formatted, selected]), totals, lines, coupons, problems, missing (contact,
     * address, shipping y payment, en ese orden) y ready.
     *
     * @return array<string,mixed>
     */
    public function state(): array
    {
        return $this->build($this->load());
    }

    /**
     * Lo que se entiende de un texto escrito de cualquier manera. No guarda nada y no inventa nada: devuelve fields (con
     * los nombres de los campos de la dirección), doubts (campo => guessed|invalid|ambiguous) y missing (los campos que
     * siguen faltando, juntando lo que ya había en el borrador con lo entendido).
     *
     * @return array{fields:array<string,string>,doubts:array<string,string>,missing:string[]}
     */
    public function understand(string $text): array
    {
        $c = $this->load();
        $known = $c['delivery'];
        $known['email'] = (string) CheckoutState::forPlace($c['draft'], $c['customer'], $c['addresses'], $c['needs'])['email'];

        return TextReader::read($text, $known, $c['needs']);
    }

    /**
     * El borrador de la caja en la forma de Front\CheckoutState (email, address_id, shipping, billing_other, billing,
     * carrier_id, payment_method, note…): lo que la página enseña al abrirla de nuevo.
     *
     * @return array<string,mixed>
     */
    public function draft(): array
    {
        return $this->load()['draft'];
    }

    /**
     * Quien ha entrado, o null si es un invitado. Se lee una vez por petición.
     *
     * @return array<string,mixed>|null
     */
    public function customer(): ?array
    {
        if (!$this->app->has('customers')) {
            return null;
        }
        $request = $this->app->request();
        if ($this->peopleRequest !== $request) {
            $this->customer = $this->app->service('customers')->current();
            $this->peopleRequest = $request;
            $this->peopleKey = '';
            $this->addresses = [];
        }

        return $this->customer;
    }

    /**
     * Las direcciones guardadas de quien ha entrado (vacío para un invitado), solo las suyas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function savedAddresses(): array
    {
        return $this->load()['addresses'];
    }

    // ------------------------------------------------------------------ escritura

    /**
     * Deja el carrito listo para enseñarlo: el destino de la dirección que se va a enseñar (para que el envío y los
     * impuestos sean los suyos) y una forma de pago elegida, la primera si no había ninguna.
     */
    public function prepare(): void
    {
        $c = $this->load();
        $summary = $c['summary'];
        if ($summary['id'] === null) {
            return;
        }
        $cart = $this->cart();
        $delivery = $c['delivery'];
        $destination = is_array($summary['destination']) ? $summary['destination'] : [];
        $changed = false;
        if ($delivery['country'] !== '' && ((string) $destination['country'] !== $delivery['country'] || (string) $destination['postcode'] !== $delivery['postcode'])) {
            $cart->setDestination(['country' => $delivery['country'], 'region' => $delivery['region'], 'postcode' => $delivery['postcode'], 'city' => $delivery['city']]);
            $changed = true;
        }
        if ($summary['payment_method'] === null && $summary['payment_methods'] !== []) {
            $cart->setPaymentMethod((string) $summary['payment_methods'][0]['code']);
            $changed = true;
        }
        if ($changed) {
            // Con el destino nuevo puede que la forma de pago elegida ya no se ofrezca: entonces, la primera que sí.
            $after = $cart->summary();
            if ($after['payment_method'] === null && $after['payment_methods'] !== []) {
                $cart->setPaymentMethod((string) $after['payment_methods'][0]['code']);
            }
        }
    }

    /**
     * Valida y guarda en el borrador del carrito lo que vale; lo que no vale vuelve en errors, con la clave de su campo,
     * y lo bueno se guarda igual.
     *
     * Admite: email, address_id, note, billing_other, carrier_id y payment_method; los campos de una dirección sueltos
     * (first_name, last_name, company, vat_id, phone, line1, line2, postcode, city, region, country: van a la dirección de
     * entrega) o dentro de «address», «shipping» o «billing». Una dirección que se escribe en «billing» con envío físico
     * activa la factura a otros datos.
     *
     * @param array<string,mixed> $fields
     *
     * @return array{ok:bool,errors:array<string,string>,saved:string[]}
     */
    public function fill(array $fields): array
    {
        $c = $this->load();
        $needs = $c['needs'];
        $draft = $c['draft'];
        $errors = [];
        $saved = [];

        if (array_key_exists('email', $fields)) {
            $email = strtolower(trim(self::text($fields['email'])));
            if ($email === '') {
                $draft['email'] = '';
                $saved[] = 'email';
            } elseif (strlen($email) <= 191 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $draft['email'] = $email;
                $saved[] = 'email';
            } else {
                $errors['email'] = t('Enter a valid email address');
            }
        }

        if (array_key_exists('address_id', $fields) && $c['customer'] !== null) {
            $id = is_numeric($fields['address_id']) ? max(0, (int) $fields['address_id']) : 0;
            $mine = $id > 0 && CheckoutState::savedAddress(['address_id' => $id], $c['addresses']) !== null;
            $draft['address_id'] = $mine ? $id : 0;
            $saved[] = 'address_id';
        }

        foreach ($this->groupsOf($fields, $needs) as $group => $values) {
            $result = $this->mergeAddress($group, $draft[$group], $values);
            $draft[$group] = $result['address'];
            $errors += $result['errors'];
            foreach ($result['saved'] as $key) {
                $saved[] = $group . '.' . $key;
            }
        }

        if (array_key_exists('billing_other', $fields)) {
            $draft['billing_other'] = self::truthy($fields['billing_other']);
            $saved[] = 'billing_other';
        } elseif ($needs && isset($fields['billing']) && is_array($fields['billing']) && self::anyValue($fields['billing'])) {
            $draft['billing_other'] = true;
        }
        if (array_key_exists('note', $fields)) {
            $draft['note'] = mb_substr(self::text($fields['note']), 0, 2000, 'UTF-8');
            $saved[] = 'note';
        }

        // Cada cambio del carrito lo vuelve a calcular entero: solo se escribe lo que de verdad cambia.
        $cart = $this->cart();
        $stored = CheckoutState::stored($draft);
        if (self::compact($stored) != self::compact($c['summary']['checkout'])) {
            try {
                $cart->setCheckout($stored);
            } catch (\InvalidArgumentException $e) {
                $this->app->log()->info('The checkout data is too large to be kept', ['reason' => $e->getMessage()]);
            }
        }
        $delivery = CheckoutState::delivery($draft, $c['addresses'], $needs);
        if ($delivery['country'] !== '' && !self::sameDestination($c['summary']['destination'], $delivery)) {
            $cart->setDestination(['country' => $delivery['country'], 'region' => $delivery['region'], 'postcode' => $delivery['postcode'], 'city' => $delivery['city']]);
        }
        $now = $cart->summary();
        if (isset($fields['carrier_id']) && is_numeric($fields['carrier_id']) && (int) $fields['carrier_id'] > 0 && (int) $fields['carrier_id'] !== (int) $now['carrier_id']) {
            // Con el destino ya guardado, un envío de otra pasada que ya no se ofrece se ignora: vale el más barato.
            foreach ($now['shipping_options'] as $option) {
                if ((int) $option['carrier_id'] === (int) $fields['carrier_id']) {
                    $cart->setCarrier((int) $fields['carrier_id']);
                    $saved[] = 'carrier_id';
                    break;
                }
            }
        }
        if (isset($fields['payment_method']) && is_scalar($fields['payment_method']) && trim((string) $fields['payment_method']) !== '') {
            $method = strtolower(trim((string) $fields['payment_method']));
            if ($method !== $now['payment_method']) {
                $cart->setPaymentMethod($method);
                $saved[] = 'payment_method';
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'saved' => $saved];
    }

    /**
     * Elige el envío entre los que se ofrecen para el destino de ahora.
     *
     * @return array{ok:bool,error:string}
     */
    public function setShipping(int $carrierId): array
    {
        $summary = $this->cart()->summary();
        if ($summary['id'] === null) {
            return ['ok' => false, 'error' => t('Your cart is empty')];
        }
        foreach ($summary['shipping_options'] as $option) {
            if ((int) $option['carrier_id'] === $carrierId && $carrierId > 0) {
                $this->cart()->setCarrier($carrierId);

                return ['ok' => true, 'error' => ''];
            }
        }

        return ['ok' => false, 'error' => t('This shipping method is not available for this order')];
    }

    /**
     * Elige la forma de pago entre las que se ofrecen para esta compra.
     *
     * @return array{ok:bool,error:string}
     */
    public function setPayment(string $method): array
    {
        $summary = $this->cart()->summary();
        if ($summary['id'] === null) {
            return ['ok' => false, 'error' => t('Your cart is empty')];
        }
        $code = strtolower(trim($method));
        foreach ($summary['payment_methods'] as $option) {
            if ((string) $option['code'] === $code && $code !== '') {
                $this->cart()->setPaymentMethod($code);

                return ['ok' => true, 'error' => ''];
            }
        }

        return ['ok' => false, 'error' => t('Choose a payment method')];
    }

    // ------------------------------------------------------------------ revisar y confirmar

    /**
     * El pedido exacto que se haría con lo que hay ahora, y su ficha de confirmación.
     *
     * Claves: ready y missing (como en state()), errors (clave del campo => aviso, lo que impide hacer el pedido),
     * lines, discounts, shipping (null si no hay que enviar), fees, taxes (por tipo), total, total_formatted, currency,
     * prices_include_tax, email, address, billing, payment (id, name, kind) y confirm_token. La ficha es un HMAC con la
     * clave del sitio de lo que se va a pagar (líneas y cantidades, direcciones, envío, forma de pago, cupones y total);
     * si todavía no se puede hacer el pedido, va vacía.
     *
     * @return array<string,mixed>
     */
    public function review(): array
    {
        $c = $this->load();
        $state = $this->build($c);
        $summary = $c['summary'];
        $totals = $summary['totals'];
        $currency = (string) $summary['currency'];
        $money = $this->moneyOf($currency);
        $withTax = !empty($summary['display_with_tax']);
        $shown = static function (array $parts) use ($withTax): int {
            return $withTax ? (int) $parts['gross'] : (int) $parts['net'];
        };

        $lines = [];
        foreach ($state['lines'] as $line) {
            $lines[] = [
                'key' => (string) $line['key'], 'name' => (string) $line['name'], 'variant_label' => (string) $line['variant_label'],
                'qty' => (int) $line['qty'], 'unit_price' => (int) $line['unit_price_shown'], 'unit_price_formatted' => (string) $line['unit_price_formatted'],
                'total' => (int) $line['total_shown'], 'total_formatted' => (string) $line['total_formatted'],
            ];
        }
        $discounts = [];
        foreach ($totals['discounts'] as $discount) {
            if ((int) $discount['amount'] > 0) {
                $discounts[] = [
                    'code' => isset($discount['code']) ? (string) $discount['code'] : '', 'label' => isset($discount['label']) ? (string) $discount['label'] : '',
                    'amount' => (int) $discount['amount'], 'amount_formatted' => $money((int) $discount['amount']),
                ];
            }
        }
        $shipping = null;
        if (!empty($summary['needs_shipping']) && $totals['shipping'] !== null) {
            $cents = $shown($totals['shipping']);
            $shipping = [
                'id' => $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0, 'name' => (string) $totals['shipping']['label'],
                'price' => $cents, 'price_formatted' => !empty($totals['shipping']['free']) ? t('Free') : $money($cents), 'free' => !empty($totals['shipping']['free']),
            ];
        }
        $fees = [];
        foreach ($totals['fees'] as $fee) {
            $fees[] = ['label' => (string) $fee['label'], 'amount' => $shown($fee), 'amount_formatted' => $money($shown($fee))];
        }
        $taxes = [];
        foreach ($totals['taxes'] as $tax) {
            $taxes[] = ['name' => (string) $tax['name'], 'rate' => (int) $tax['rate'], 'amount' => (int) $tax['amount'], 'amount_formatted' => $money((int) $tax['amount'])];
        }
        $payment = null;
        foreach ($state['payment']['options'] as $option) {
            if ($option['selected']) {
                $payment = ['id' => $option['id'], 'name' => $option['name'], 'kind' => $option['kind']];
            }
        }
        $total = (int) $totals['total'];

        return [
            'ready' => $state['ready'],
            'missing' => $state['missing'],
            'errors' => $this->problems($c),
            'lines' => $lines,
            'discounts' => $discounts,
            'shipping' => $shipping,
            'fees' => $fees,
            'taxes' => $taxes,
            'total' => $total,
            'total_formatted' => $money($total),
            'currency' => $currency,
            'prices_include_tax' => !empty($totals['prices_include_tax']),
            'email' => $state['contact']['email'],
            'address' => $state['address'],
            'billing' => $state['billing'],
            'needs_shipping' => $state['needs_shipping'],
            'payment' => $payment,
            'confirm_token' => $state['ready'] ? $this->tokenOf($c, $state) : '',
        ];
    }

    /**
     * Hace el pedido, solo si la ficha de confirmación sigue valiendo para lo que hay ahora.
     *
     * $options: source (web, assistant o api), ip, attribution, accept_terms (la persona acepta las condiciones: sin eso
     * no hay pedido), marketing y note.
     *
     * Devuelve ok, number, order_url, id y payment (type none|instructions|redirect; con text las instrucciones, o con
     * url la dirección a la que llevar al cliente y, si hay fields, el formulario que se le envía con POST), o ok = false
     * con error, field y errors (clave del campo => aviso). Si el cobro no pudo empezar, el pedido queda fallido y el
     * carrito vuelve a estar libre (reopened = true).
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function place(string $confirmToken, array $options = []): array
    {
        if ($this->cart !== null && $this->cart !== $this->app->service('cart')) {
            throw new \LogicException('Only the cart of the current request can be turned into an order');
        }
        $c = $this->load();
        if ($c['summary']['id'] === null || $c['summary']['lines'] === []) {
            return $this->refuse(t('Your cart is empty'), ['cart' => t('Your cart is empty')]);
        }
        $errors = $this->problems($c);
        if ($errors !== []) {
            return $this->refuse((string) reset($errors), $errors);
        }
        $state = $this->build($c);
        if ($confirmToken === '' || !hash_equals($this->tokenOf($c, $state), $confirmToken)) {
            return $this->refuse(t('The order has changed since you reviewed it. Review it again before confirming.'), ['confirm_token' => t('The order has changed since you reviewed it. Review it again before confirming.')]);
        }

        $draft = $c['draft'];
        $draft['accept_terms'] = !empty($options['accept_terms']);
        $draft['marketing'] = !empty($options['marketing']);
        if (isset($options['note']) && is_scalar($options['note'])) {
            $draft['note'] = mb_substr((string) $options['note'], 0, 2000, 'UTF-8');
        }
        $placeOptions = [];
        foreach (['source', 'ip', 'attribution'] as $key) {
            if (isset($options[$key])) {
                $placeOptions[$key] = $options[$key];
            }
        }
        $result = $this->orders()->place(CheckoutState::forPlace($draft, $c['customer'], $c['addresses'], $c['needs']), $placeOptions);

        if (!empty($result['ok'])) {
            $number = (string) $result['number'];
            $secret = (string) $result['secret'];

            return [
                'ok' => true, 'error' => '', 'field' => '', 'errors' => [], 'id' => (int) $result['id'], 'number' => $number, 'secret' => $secret,
                'order_url' => route('order.thanks', ['number' => $number, 'secret' => $secret]),
                'payment' => $this->paymentOf($result['action']),
            ];
        }
        if ((int) $result['id'] > 0) {
            // El cobro no pudo empezar: el carrito vuelve a estar libre para reintentar o elegir otra forma de pago.
            $this->cart()->reopen((int) $result['id'], (string) $result['secret']);

            return [
                'ok' => false, 'error' => (string) $result['error'], 'field' => 'payment', 'errors' => ['payment' => (string) $result['error']],
                'id' => (int) $result['id'], 'number' => (string) $result['number'], 'secret' => (string) $result['secret'], 'reopened' => true,
                'payment' => ['type' => 'none'],
            ];
        }
        $mapped = CheckoutState::mapErrors(is_array($result['errors']) ? $result['errors'] : [], $draft, $c['addresses'], $c['needs']);
        if ($mapped === []) {
            $mapped = ['cart' => (string) $result['error']];
        }

        return $this->refuse((string) $result['error'], $mapped);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Lo que hay ahora, de una vez: el carrito con precios, la persona, el borrador y las direcciones. El carrito calcula
     * una sola vez por petición, así que llamar a varios métodos seguidos no repite el trabajo.
     *
     * @return array<string,mixed>
     */
    private function load(): array
    {
        $summary = $this->cart()->summary();
        $customer = $this->customer();
        $addresses = $this->addressesOf($customer);
        $draft = CheckoutState::fromCart($this->app, $summary, $customer, $addresses);
        $needs = !empty($summary['needs_shipping']);

        return [
            'summary' => $summary, 'customer' => $customer, 'addresses' => $addresses, 'draft' => $draft, 'needs' => $needs,
            'delivery' => CheckoutState::delivery($draft, $addresses, $needs),
        ];
    }

    /**
     * Las direcciones guardadas de la persona que ha entrado. Se leen una vez por petición y por cliente.
     *
     * @param array<string,mixed>|null $customer
     *
     * @return array<int,array<string,mixed>>
     */
    private function addressesOf(?array $customer): array
    {
        if ($customer === null || !$this->app->has('customers')) {
            return [];
        }
        $key = (string) (int) $customer['id'];
        if ($this->peopleKey !== $key) {
            $this->addresses = array_values($this->app->service('customers')->addresses((int) $customer['id']));
            $this->peopleKey = $key;
        }

        return $this->addresses;
    }

    /**
     * @param array<string,mixed> $c lo que devuelve load()
     *
     * @return array<string,mixed>
     */
    private function build(array $c): array
    {
        $summary = $c['summary'];
        $app = $this->app;
        $needs = $c['needs'];
        $currency = (string) $summary['currency'];
        $money = $this->moneyOf($currency);
        $place = CheckoutState::forPlace($c['draft'], $c['customer'], $c['addresses'], $needs);

        $carriers = [];
        foreach ($summary['shipping_options'] as $option) {
            $price = TotalsView::optionPrice($app, $summary, $option);
            $carriers[] = [
                'id' => (int) $option['carrier_id'], 'name' => (string) $option['name'], 'delay' => isset($option['delay']) ? (string) $option['delay'] : '',
                'price' => $price['cents'], 'price_formatted' => $price['free'] ? t('Free') : $money($price['cents']), 'free' => $price['free'],
                'selected' => $summary['carrier_id'] !== null && (int) $option['carrier_id'] === (int) $summary['carrier_id'],
            ];
        }
        $methods = [];
        foreach ($summary['payment_methods'] as $method) {
            $fee = TotalsView::feeShown($app, $summary, (int) $method['fee']);
            $methods[] = [
                'id' => (string) $method['code'], 'name' => (string) $method['title'], 'kind' => $this->kindOf((string) $method['code']),
                'description' => (string) $method['description'], 'fee' => $fee, 'fee_formatted' => $fee > 0 ? $money($fee) : '',
                'selected' => $summary['payment_method'] !== null && (string) $method['code'] === (string) $summary['payment_method'],
            ];
        }
        $lines = [];
        foreach ($summary['lines'] as $line) {
            $lines[] = CartView::line($app, $line, $summary);
        }
        $totals = $summary['totals'];
        $total = (int) $totals['total'];

        $missing = $this->missingOf($c);
        $address = $c['delivery'];
        $saved = $needs ? CheckoutState::savedAddress($c['draft'], $c['addresses']) : null;
        if ($saved !== null) {
            $address['saved_id'] = (int) $saved['id'];
        }

        return [
            'contact' => ['email' => (string) $place['email'], 'account' => $c['customer'] !== null],
            'address' => $address,
            'billing' => CheckoutState::address($place['billing']),
            'billing_other' => !empty($c['draft']['billing_other']),
            'needs_shipping' => $needs,
            'note' => (string) $c['draft']['note'],
            'shipping' => ['selected' => $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : null, 'options' => $carriers],
            'payment' => ['selected' => $summary['payment_method'] !== null ? (string) $summary['payment_method'] : null, 'options' => $methods],
            'totals' => [
                'items' => (int) $totals['items_total'], 'discount' => (int) $totals['discount_total'], 'shipping' => $totals['shipping'] !== null ? (int) $totals['shipping']['price'] : null,
                'fees' => (int) $totals['fee_total'], 'tax' => (int) $totals['total_tax'], 'total' => $total, 'total_formatted' => $money($total), 'currency' => $currency,
            ],
            'lines' => $lines,
            'coupons' => $summary['coupons'],
            'problems' => $summary['problems'],
            'missing' => $missing,
            'ready' => $missing === [] && $summary['problems'] === [] && $summary['lines'] !== [],
        ];
    }

    /**
     * Lo que falta para poder hacer el pedido, en el orden en que se pide.
     *
     * @param array<string,mixed> $c
     *
     * @return string[]
     */
    private function missingOf(array $c): array
    {
        $errors = $this->problems($c);
        $found = [];
        foreach (array_keys($errors) as $key) {
            $key = (string) $key;
            if ($key === 'cart' || $key === 'accept_terms') {
                continue;
            }
            if ($key === 'email') {
                $found['contact'] = true;
            } elseif ($key === 'carrier') {
                $found['shipping'] = true;
            } elseif ($key === 'payment') {
                $found['payment'] = true;
            } else {
                $found['address'] = true;
            }
        }

        return array_values(array_filter(self::MISSING_ORDER, static function (string $part) use ($found): bool {
            return isset($found[$part]);
        }));
    }

    /**
     * Lo que impide hacer el pedido ahora, con las claves de los campos de la página (las que usa Orders::place() más la
     * comprobación del NIF de empresa y del código postal de la caja).
     *
     * @param array<string,mixed> $c
     *
     * @return array<string,string>
     */
    private function problems(array $c): array
    {
        $summary = $c['summary'];
        if ($summary['id'] === null || $summary['lines'] === []) {
            return ['cart' => t('Your cart is empty')];
        }
        $needs = $c['needs'];
        $draft = $c['draft'];
        $errors = [];
        $place = CheckoutState::forPlace($draft, $c['customer'], $c['addresses'], $needs);
        $email = strtolower(trim((string) $place['email']));
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('Enter a valid email address');
        }
        $billing = CheckoutState::address($place['billing']);
        if (trim($billing['first_name'] . $billing['last_name']) === '') {
            // «para la factura» solo si ese campo es de la factura: con la factura a los mismos datos, el error se
            // pinta junto al nombre de la entrega (mapErrors) y no puede hablar de otra cosa.
            $separate = !$needs || !empty($draft['billing_other']);
            $errors['billing.first_name'] = $separate ? t('Enter the name for the invoice') : t('Enter the name');
        }
        if ($this->app->settings()->bool('orders.require_vat_id', false) && $billing['vat_id'] === '') {
            $errors['billing.vat_id'] = t('Enter the tax ID for the invoice');
        }
        if ($needs) {
            // La entrega lleva teléfono: lo exigen todos los transportistas (16/11/2026). Es el de la dirección de
            // envío, o el de la factura cuando la entrega va a esos mismos datos.
            $errors += self::incomplete($billing, 'billing', $place['shipping'] === []);
            if ($place['shipping'] !== []) {
                $errors += self::incomplete(CheckoutState::address($place['shipping']), 'shipping', true);
            }
        }
        $errors += CheckoutState::check($draft, $c['customer'], $c['addresses'], $needs);
        $errors = CheckoutState::mapErrors($errors, $draft, $c['addresses'], $needs);
        if ($needs && $summary['carrier_id'] === null) {
            $errors['carrier'] = t('We cannot deliver to this destination');
        }
        if ($summary['payment_method'] === null) {
            $errors['payment'] = t('Choose a payment method');
        }
        if ($errors === [] && $summary['problems'] !== []) {
            $errors['cart'] = (string) $summary['problems'][0];
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
    private static function incomplete(array $address, string $prefix, bool $delivery = false): array
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
     * La ficha de confirmación: HMAC de todo lo que se va a pagar y a dónde va.
     *
     * @param array<string,mixed> $c
     * @param array<string,mixed> $state
     */
    private function tokenOf(array $c, array $state): string
    {
        $summary = $c['summary'];
        $lines = [];
        foreach ($summary['lines'] as $line) {
            $lines[] = [(string) $line['key'], (int) $line['qty'], (int) $line['unit_price'], (int) $line['total']];
        }
        $coupons = [];
        foreach ($summary['coupons'] as $coupon) {
            $coupons[] = [(string) $coupon['code'], (int) $coupon['amount']];
        }
        $place = CheckoutState::forPlace($c['draft'], $c['customer'], $c['addresses'], $c['needs']);
        $payload = [
            'v' => 1,
            'cart' => (int) $summary['id'],
            'lines' => $lines,
            'email' => (string) $state['contact']['email'],
            'billing' => CheckoutState::address($place['billing']),
            'shipping' => $place['shipping'] !== [] ? CheckoutState::address($place['shipping']) : [],
            'carrier' => $summary['carrier_id'] !== null ? (int) $summary['carrier_id'] : 0,
            'payment' => $summary['payment_method'] !== null ? (string) $summary['payment_method'] : '',
            'coupons' => $coupons,
            'total' => (int) $summary['totals']['total'],
            'currency' => (string) $summary['currency'],
        ];

        return hash_hmac('sha256', 'checkout-confirm:' . (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->app->site()->secret());
    }

    /**
     * Qué hacer con el cliente después de crear el pedido.
     *
     * @param mixed $action PaymentAction|null
     *
     * @return array<string,mixed>
     */
    private function paymentOf($action): array
    {
        if ($action instanceof PaymentAction) {
            if ($action->type === PaymentAction::INSTRUCTIONS) {
                return ['type' => 'instructions', 'text' => $action->html];
            }
            if ($action->type === PaymentAction::REDIRECT) {
                return ['type' => 'redirect', 'url' => $action->url, 'fields' => [], 'method' => 'get'];
            }
            if ($action->type === PaymentAction::FORM) {
                $fields = [];
                foreach ($action->fields as $name => $value) {
                    $fields[(string) $name] = (string) $value;
                }

                return ['type' => 'redirect', 'url' => $action->url, 'fields' => $fields, 'method' => 'post'];
            }
        }

        return ['type' => 'none'];
    }

    /**
     * offline (se paga fuera de la web: transferencia, contra reembolso), test (de pruebas) o redirect (se cobra en línea).
     */
    private function kindOf(string $code): string
    {
        $method = $this->app->service('payments')->get($code);
        if ($method === null) {
            return 'redirect';
        }
        $offline = method_exists($method, 'isOffline') ? (bool) $method->isOffline() : in_array($code, ['transfer', 'cod'], true);
        if ($offline) {
            return 'offline';
        }

        return $method->isTest() ? 'test' : 'redirect';
    }

    /**
     * Reparte lo que llega en grupos de dirección: cada grupo con los campos que trae.
     *
     * @param array<string,mixed> $fields
     *
     * @return array<string,array<string,mixed>> grupo (shipping o billing) => campos
     */
    private function groupsOf(array $fields, bool $needs): array
    {
        $delivery = $needs ? 'shipping' : 'billing';
        $groups = [];
        foreach (['address' => $delivery, 'shipping' => 'shipping', 'billing' => 'billing'] as $key => $group) {
            if (isset($fields[$key]) && is_array($fields[$key])) {
                $groups[$group] = (isset($groups[$group]) ? $groups[$group] : []) + $fields[$key];
            }
        }
        foreach (self::ADDRESS_KEYS as $key) {
            if (array_key_exists($key, $fields) && is_scalar($fields[$key])) {
                $groups[$delivery][$key] = $fields[$key];
            }
        }
        if (!$needs) {
            // Sin envío no hay dirección de entrega: lo escrito en «shipping» no tiene dónde guardarse.
            unset($groups['shipping']);
        }

        return $groups;
    }

    /**
     * Junta lo escrito con la dirección que ya había y comprueba lo que vale: el país y el código postal.
     *
     * @param array<string,string> $current
     * @param array<string,mixed>  $values
     *
     * @return array{address:array<string,string>,errors:array<string,string>,saved:string[]}
     */
    private function mergeAddress(string $group, array $current, array $values): array
    {
        $errors = [];
        $saved = [];
        $next = $current;
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array($key, self::ADDRESS_KEYS, true) && is_scalar($value)) {
                $next[$key] = (string) $value;
            }
        }
        $clean = CheckoutState::address($next);

        if (array_key_exists('country', $values) && is_scalar($values['country']) && trim((string) $values['country']) !== ''
            && preg_match('/^[A-Za-z]{2}$/D', trim((string) $values['country'])) !== 1) {
            $errors[$group . '.country'] = t('Choose the country');
            $clean['country'] = $current['country'];
        }
        if (array_key_exists('postcode', $values) && $clean['postcode'] !== '' && $clean['country'] !== ''
            && !Countries::validPostcode($clean['country'], $clean['postcode'])) {
            $errors[$group . '.postcode'] = t('Enter a valid postcode');
            $clean['postcode'] = $current['postcode'];
            $clean['region'] = $current['region'];
        }
        foreach (array_keys($values) as $key) {
            if (in_array($key, self::ADDRESS_KEYS, true) && !isset($errors[$group . '.' . $key])) {
                $saved[] = (string) $key;
            }
        }

        return ['address' => $clean, 'errors' => $errors, 'saved' => $saved];
    }

    /**
     * Lo guardado sin los valores vacíos: el carrito no guarda los campos vacíos, así que así se pueden comparar.
     *
     * @param mixed $data
     *
     * @return array<string,mixed>
     */
    private static function compact($data): array
    {
        $out = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            if (is_array($value)) {
                $value = self::compact($value);
            }
            if ($value !== '' && $value !== []) {
                $out[(string) $key] = $value;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * ¿El destino que guarda el carrito ya es este? Lo que Cart::setDestination() recorta a sus largos nunca coincide y se
     * vuelve a escribir.
     *
     * @param mixed                $current  el destino del carrito (Cart::summary()['destination'])
     * @param array<string,string> $delivery la dirección de entrega
     */
    private static function sameDestination($current, array $delivery): bool
    {
        if (!is_array($current)) {
            return false;
        }
        foreach (['country', 'region', 'postcode', 'city'] as $key) {
            if (!isset($current[$key]) || (string) $current[$key] !== (string) $delivery[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function anyValue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
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
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string,string> $errors
     *
     * @return array<string,mixed>
     */
    private function refuse(string $error, array $errors): array
    {
        $field = '';
        foreach (array_keys($errors) as $key) {
            $field = (string) $key;
            break;
        }

        return ['ok' => false, 'error' => $error, 'field' => $field, 'errors' => $errors, 'payment' => ['type' => 'none']];
    }

    private function moneyOf(string $currency): \Closure
    {
        $app = $this->app;

        return static function (int $cents) use ($app, $currency): string {
            return $app->i18n()->money($cents, $currency);
        };
    }

    private function cart(): Cart
    {
        return $this->cart !== null ? $this->cart : $this->app->service('cart');
    }

    private function orders(): Orders
    {
        return $this->app->service('orders');
    }
}
