<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Geo\Countries;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Cart;
use Aifec\Modules\Orders\Front\Destinations;
use Aifec\Security\Random;

/**
 * El borrador de un pedido a mano: lo que la persona del equipo va eligiendo en «Pedido nuevo», guardado en su sesión del panel,
 * sobre un carrito de verdad de la tabla carts (source = admin).
 *
 * El carrito es el de la tienda, no una copia: precios, impuestos, envío, cupones, existencias y recargos los calcula Cart con
 * Totals, y el pedido lo crea Orders::place(). Para que el servicio «cart» trabaje con ESTE carrito sin tocar la cookie de la
 * tienda de quien está en el panel, la ficha del borrador se pone en la petición como si fuera la cookie (lo mismo que hace la
 * API con X-Cart-Token) y Cart::actAs() hace que el cliente sea el elegido, no el que haya entrado en la tienda en ese navegador.
 * release() lo deja todo como estaba; si el carrito pidió borrar su cookie (al quitar la última línea o al hacerse pedido), la de
 * la tienda de esa persona se vuelve a poner.
 *
 * Lo que guarda la sesión (clave KEY): cart (ficha en claro del carrito), customer_id, address_id, pay («», «paid» o
 * «method:<código>»), paid_with, note, notify, prices (clave de línea => [price, reason]) y quotes (las últimas fichas de
 * confirmación enseñadas con su resumen, para decir qué cambió si al confirmar ya no valen).
 */
final class ManualOrderDraft
{
    public const KEY = 'manual_order';

    /** El último pedido creado desde la pantalla: [token, id]. Un reenvío del mismo formulario lleva a él. */
    public const LAST = 'manual_order_last';

    public const MAX_REASON = 120;
    public const MAX_NOTE = 2000;
    public const MAX_QUOTES = 3;

    /** Formas de pago que se pagan fuera de la web cuando la forma no dice nada (como Checkout\CheckoutSession::kindOf()). */
    private const OFFLINE = ['transfer', 'cod'];

    private App $app;

    /** @var array<string,mixed> */
    private array $state;

    private bool $dirty = false;

    private int $cartId = 0;

    private ?Cart $cart = null;

    /** @var array{0:bool,1:string}|null la cookie de carrito que traía la petición (la de la tienda de quien está en el panel) */
    private ?array $original = null;

    /** El carrito puede haber pedido borrar su cookie (se quitó la última línea o se hizo el pedido). */
    private bool $mayDrop = false;

    /** @var array<string,mixed>|false|null false = sin leer */
    private $customer = false;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->state = self::normalize($app->session('admin')->get(self::KEY, []));
    }

    public function app(): App
    {
        return $this->app;
    }

    /**
     * @return array<string,mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /** Guarda en la sesión del panel lo que haya cambiado. */
    public function save(): void
    {
        if ($this->dirty) {
            $this->app->session('admin')->set(self::KEY, $this->state);
            $this->dirty = false;
        }
    }

    /** Deja la petición como estaba: la cookie de la tienda de quien está en el panel y la forma de pago y los precios, apagados. */
    public function release(): void
    {
        if ($this->app->has(ManualOrderPrices::SERVICE)) {
            ManualOrderPrices::forApp($this->app)->disarm();
        }
        $pay = $this->app->has('payments') ? $this->app->service('payments')->get(ManualOrderPayment::CODE) : null;
        if ($pay instanceof ManualOrderPayment) {
            $pay->disarm();
        }
        if ($this->original !== null) {
            $request = $this->app->request();
            $name = $this->app->cookieName('k');
            if ($this->original[0]) {
                $request->cookies[$name] = $this->original[1];
                if ($this->mayDrop) {
                    $this->app->setCookie($name, $this->original[1], 30);
                    $this->app->setCookie($this->app->cookieName('ck'), '1', 30, true);
                }
            } else {
                unset($request->cookies[$name]);
            }
            $this->original = null;
        }
        $this->cart = null;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * El cliente elegido (Customers::find()), o null. Una cuenta apagada no vale.
     *
     * @return array<string,mixed>|null
     */
    public function customer(): ?array
    {
        if ($this->customer === false) {
            $id = (int) $this->state['customer_id'];
            $row = $id > 0 && $this->app->has('customers') ? $this->app->service('customers')->find($id) : null;
            $this->customer = is_array($row) && (int) $row['active'] === 1 ? $row : null;
        }

        return $this->customer;
    }

    /**
     * Las direcciones guardadas del cliente elegido, las de por defecto primero.
     *
     * @return array<int,array<string,mixed>>
     */
    public function addresses(): array
    {
        $customer = $this->customer();
        if ($customer === null) {
            return [];
        }
        $list = $this->app->service('customers')->addresses((int) $customer['id']);

        return is_array($list) ? array_values($list) : [];
    }

    /**
     * La dirección elegida, solo si es del cliente elegido.
     *
     * @return array<string,mixed>|null
     */
    public function address(): ?array
    {
        $id = (int) $this->state['address_id'];
        if ($id <= 0) {
            return null;
        }
        foreach ($this->addresses() as $address) {
            if ((int) $address['id'] === $id) {
                return $address;
            }
        }

        return null;
    }

    /**
     * El carrito del borrador, listo para el servicio «cart» (se crea la fila si hace falta).
     */
    public function cart(): Cart
    {
        if ($this->cart !== null) {
            return $this->cart;
        }
        $raw = $this->ensureRow();
        $this->bindToken($raw);
        $cart = $this->app->service('cart');
        if (!$cart instanceof Cart) {
            throw new \LogicException('The cart service is not available');
        }
        ManualOrderPayment::forApp($this->app)->arm((string) $this->state['paid_with']);
        ManualOrderPrices::forApp($this->app)->arm($this->cartId, $this->state['prices']);
        $cart->actAs($this->customer());
        $this->cart = $cart;

        return $cart;
    }

    /**
     * @return array<string,mixed> Cart::summary() del borrador
     */
    public function summary(): array
    {
        return $this->cart()->summary();
    }

    public function cartId(): int
    {
        $this->cart();

        return $this->cartId;
    }

    /**
     * Las formas de pago que se pagan fuera de la web (transferencia, contra reembolso…) que la tienda ofrece para este carrito.
     *
     * @return array<int,array{code:string,title:string,fee:int}>
     */
    public function offlineMethods(): array
    {
        $out = [];
        $payments = $this->app->service('payments');
        foreach ($this->summary()['payment_methods'] as $method) {
            $code = (string) $method['code'];
            if ($code === ManualOrderPayment::CODE) {
                continue;
            }
            $object = $payments->get($code);
            $offline = $object !== null && method_exists($object, 'isOffline') ? (bool) $object->isOffline() : in_array($code, self::OFFLINE, true);
            if ($offline) {
                $out[] = ['code' => $code, 'title' => (string) $method['title'], 'fee' => (int) $method['fee']];
            }
        }

        return $out;
    }

    public function hasPrices(): bool
    {
        return $this->state['prices'] !== [];
    }

    // ------------------------------------------------------------------ cliente

    /**
     * @return array<string,mixed> ok, error, errors, message
     */
    public function chooseCustomer(int $id): array
    {
        if ($id <= 0) {
            $this->set('customer_id', 0);
            $this->set('address_id', 0);
            $this->rebind();

            return self::done(t('Choose the customer of the order.'));
        }
        $row = $this->app->has('customers') ? $this->app->service('customers')->find($id) : null;
        if (!is_array($row) || (int) $row['active'] !== 1) {
            return self::fail(t('This customer does not exist or is disabled.'), 'customer');
        }
        $this->set('customer_id', $id);
        $this->set('address_id', 0);
        $this->rebind();
        $first = 0;
        foreach ($this->addresses() as $address) {
            if ($first === 0 || (int) $address['default_shipping'] === 1) {
                $first = (int) $address['id'];
                if ((int) $address['default_shipping'] === 1) {
                    break;
                }
            }
        }
        if ($first > 0) {
            $this->set('address_id', $first);
            $this->applyDestination();
        }

        return self::done(t('Customer: {name}', ['name' => self::personName($row)]));
    }

    /**
     * «Cliente nuevo»: nombre, apellidos, correo y teléfono. Si el correo ya es de un cliente no se crea otro: vuelve en
     * «existing» para ofrecerlo.
     *
     * @param array<string,mixed> $in
     *
     * @return array<string,mixed>
     */
    public function newCustomer(array $in): array
    {
        $first = self::field($in, 'first_name', 80);
        $last = self::field($in, 'last_name', 120);
        $email = strtolower(self::field($in, 'email', 191));
        $phone = self::field($in, 'phone', 40);
        $errors = [];
        if ($first === '') {
            $errors['first_name'] = t('Enter the name');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = t('Enter a valid email address: the confirmation of the order goes there.');
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => (string) reset($errors), 'errors' => $errors, 'message' => ''];
        }
        $customers = $this->app->service('customers');
        $existing = $customers->findByEmail($email);
        if (is_array($existing)) {
            $message = t('There is already a customer with this email. Use it instead of creating another one.');

            return [
                'ok' => false, 'error' => $message, 'errors' => ['email' => $message], 'message' => '',
                'existing' => ['id' => (int) $existing['id'], 'name' => self::personName($existing), 'email' => (string) $existing['email']],
            ];
        }
        try {
            $id = (int) $customers->ensureGuest($email, ['first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'locale' => $this->app->lang()]);
        } catch (\InvalidArgumentException $e) {
            return self::fail(t($e->getMessage()), 'phone');
        }
        $result = $this->chooseCustomer($id);
        if (!empty($result['ok'])) {
            $result['message'] = t('New customer: {name}', ['name' => trim($first . ' ' . $last)]);
        }

        return $result;
    }

    // ------------------------------------------------------------------ productos

    /**
     * @return array<string,mixed>
     */
    public function addLine(int $productId, int $variantId, int $qty): array
    {
        if ($productId <= 0 || $variantId < 0) {
            return self::fail(t('This product is not available'), 'lines');
        }
        $result = $this->cart()->add($productId, $variantId, max(1, $qty));
        if (empty($result['ok'])) {
            return self::fail((string) $result['error'], 'lines');
        }
        $line = $this->line((string) $result['key']);

        return self::done(t('Added: {product}', ['product' => $line !== null ? ManualOrderPrices::lineName($line) : '']));
    }

    /**
     * @return array<string,mixed>
     */
    public function setQty(string $key, int $qty): array
    {
        if ($this->line($key) === null) {
            return self::fail(t('This item is no longer in your cart'), 'lines');
        }
        if ($qty < 1) {
            return $this->removeLine($key);
        }
        $result = $this->cart()->update($key, $qty);
        if (empty($result['ok'])) {
            return self::fail((string) $result['error'], 'lines');
        }

        return self::done(t('Units updated.'));
    }

    /**
     * @return array<string,mixed>
     */
    public function removeLine(string $key): array
    {
        $line = $this->line($key);
        if ($line === null) {
            return self::fail(t('This item is no longer in your cart'), 'lines');
        }
        $this->mayDrop = true;
        $this->cart()->remove($key);
        $prices = $this->state['prices'];
        unset($prices[$key]);
        $this->set('prices', $prices);
        $this->afterRemove();

        return self::done(t('Removed: {product}', ['product' => ManualOrderPrices::lineName($line)]));
    }

    /**
     * «Precio de esta venta» de una línea: precio unitario con impuestos y un motivo corto. El permiso lo mira quien llama.
     *
     * @return array<string,mixed>
     */
    public function setPrice(string $key, string $text, string $reason): array
    {
        if ($this->line($key) === null) {
            return self::fail(t('This item is no longer in your cart'), 'lines');
        }
        $price = self::parsePrice($text);
        if ($price === null) {
            return self::fail(t('Enter a valid price, for example 12.50. It cannot be negative.'), 'price');
        }
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if ($reason === '') {
            return self::fail(t('Write why this sale has a different price.'), 'reason');
        }
        $prices = $this->state['prices'];
        $prices[$key] = ['price' => $price, 'reason' => mb_substr($reason, 0, self::MAX_REASON, 'UTF-8')];
        $this->set('prices', $prices);
        $this->refresh();

        return self::done(t('Price of this sale saved.'));
    }

    /**
     * @return array<string,mixed>
     */
    public function clearPrice(string $key): array
    {
        $prices = $this->state['prices'];
        unset($prices[$key]);
        $this->set('prices', $prices);
        $this->refresh();

        return self::done(t('The line has its catalogue price again.'));
    }

    // ------------------------------------------------------------------ entrega y envío

    /**
     * @return array<string,mixed>
     */
    public function chooseAddress(int $id): array
    {
        if ($this->customer() === null) {
            return self::fail(t('Choose the customer first.'), 'customer');
        }
        $this->set('address_id', $id);
        if ($this->address() === null) {
            $this->set('address_id', 0);

            return self::fail(t('Choose one of the addresses of the customer.'), 'address_id');
        }
        $this->applyDestination();

        return self::done(t('Delivery address chosen.'));
    }

    /**
     * Una dirección nueva, que se guarda en la cuenta del cliente con la misma validación que sus direcciones de siempre.
     *
     * @param array<string,mixed> $in
     *
     * @return array<string,mixed>
     */
    public function newAddress(array $in): array
    {
        $customer = $this->customer();
        if ($customer === null) {
            return self::fail(t('Choose the customer first.'), 'customer');
        }
        $limits = ['first_name' => 80, 'last_name' => 120, 'company' => 160, 'vat_id' => 40, 'phone' => 40, 'line1' => 191, 'line2' => 191, 'postcode' => 16, 'city' => 100, 'country' => 2];
        $data = [];
        foreach ($limits as $name => $max) {
            $data[$name] = self::field($in, $name, $max);
        }
        $data['country'] = strtoupper($data['country']);
        $data['postcode'] = strtoupper($data['postcode']);
        $errors = [];
        if ($data['first_name'] === '') {
            $errors['first_name'] = t('Enter the name');
        }
        if ($data['line1'] === '') {
            $errors['line1'] = t('Enter the address');
        }
        if ($data['city'] === '') {
            $errors['city'] = t('Enter the city');
        }
        if (!isset(Destinations::countries($this->app, true)[$data['country']])) {
            $errors['country'] = t('Choose the country');
        } elseif ($data['postcode'] === '' || !Countries::validPostcode($data['country'], $data['postcode'])) {
            $errors['postcode'] = t('Enter a valid postcode');
        }
        if ($errors !== []) {
            return ['ok' => false, 'error' => (string) reset($errors), 'errors' => $errors, 'message' => ''];
        }
        try {
            $id = (int) $this->app->service('customers')->saveAddress((int) $customer['id'], $data);
        } catch (\InvalidArgumentException $e) {
            return self::fail(t($e->getMessage()), 'line1');
        }
        $this->set('address_id', $id);
        $this->applyDestination();

        return self::done(t('Address saved in the account of the customer and chosen.'));
    }

    /**
     * El envío, solo entre los que la tienda da a este carrito y a esta dirección.
     *
     * @return array<string,mixed>
     */
    public function setCarrier(int $carrierId): array
    {
        foreach ($this->summary()['shipping_options'] as $option) {
            if ($carrierId > 0 && (int) $option['carrier_id'] === $carrierId) {
                $this->cart()->setCarrier($carrierId);

                return self::done(t('Shipping: {name}', ['name' => (string) $option['name']]));
            }
        }

        return self::fail(t('This shipping method is not available for this order'), 'carrier_id');
    }

    // ------------------------------------------------------------------ cupón

    /**
     * @return array<string,mixed>
     */
    public function applyCoupon(string $code): array
    {
        if ($this->summary()['lines'] === []) {
            return self::fail(t('Add a product first.'), 'code');
        }
        $result = $this->cart()->applyCoupon($code);
        if (empty($result['ok'])) {
            return self::fail((string) $result['error'], 'code');
        }

        return self::done(t('Coupon {code} applied.', ['code' => (string) $result['code']]));
    }

    /**
     * @return array<string,mixed>
     */
    public function removeCoupon(string $code): array
    {
        $this->cart()->removeCoupon($code);

        return self::done(t('Coupon removed.'));
    }

    // ------------------------------------------------------------------ pago

    /**
     * Cómo se paga: «method:<código>» (una forma que da instrucciones), «paid» con «con qué se pagó», o «link» (apagado: la
     * página del pedido no deja pagar un pedido pendiente).
     *
     * @return array<string,mixed>
     */
    public function setPayment(string $mode, string $how): array
    {
        if ($mode === 'link') {
            return self::fail(self::linkUnavailable(), 'mode');
        }
        if ($mode === 'paid') {
            $how = trim((string) preg_replace('/\s+/u', ' ', $how));
            if ($how === '') {
                return self::fail(t('Write how it was paid: cash, card, Bizum…'), 'paid_with');
            }
            $how = mb_substr($how, 0, ManualOrderPayment::MAX_HOW, 'UTF-8');
            $this->set('pay', 'paid');
            $this->set('paid_with', $how);
            ManualOrderPayment::forApp($this->app)->arm($how);
            $this->cart()->setPaymentMethod(ManualOrderPayment::CODE);

            return self::done(t('Already paid: {how}', ['how' => $how]));
        }
        if (strpos($mode, 'method:') === 0) {
            $code = substr($mode, 7);
            foreach ($this->offlineMethods() as $method) {
                if ($method['code'] === $code) {
                    $this->set('pay', 'method:' . $code);
                    $this->set('paid_with', '');
                    $this->cart()->setPaymentMethod($code);

                    return self::done(t('Payment: {method}', ['method' => $method['title']]));
                }
            }
        }

        return self::fail(t('Choose how the order is paid.'), 'mode');
    }

    /** Por qué «Mandar enlace de pago» está apagado. */
    public static function linkUnavailable(): string
    {
        return t('Not available yet: the order page cannot take the payment of an order that is still waiting for it.');
    }

    /** La nota interna y la casilla del correo (llegan con el botón de crear el pedido). */
    public function setNote(string $note, bool $notify): void
    {
        $this->set('note', mb_substr(trim($note), 0, self::MAX_NOTE, 'UTF-8'));
        $this->set('notify', $notify);
    }

    // ------------------------------------------------------------------ confirmación

    /**
     * Guarda lo que se enseñó junto a su ficha de confirmación (las últimas MAX_QUOTES).
     *
     * @param array<string,mixed> $snapshot
     */
    public function remember(string $token, array $snapshot): void
    {
        if ($token === '') {
            return;
        }
        $quotes = $this->state['quotes'];
        if (isset($quotes[$token]) && $quotes[$token] == $snapshot) {
            return;
        }
        unset($quotes[$token]);
        $quotes[$token] = $snapshot;
        $this->set('quotes', array_slice($quotes, -self::MAX_QUOTES, null, true));
    }

    /**
     * @return array<string,mixed>|null lo que se enseñó con esa ficha
     */
    public function seen(string $token): ?array
    {
        return $token !== '' && isset($this->state['quotes'][$token]) && is_array($this->state['quotes'][$token]) ? $this->state['quotes'][$token] : null;
    }

    /**
     * Se queda con el carrito para crear el pedido: le cambia la ficha con una sentencia condicionada. De dos envíos a la vez del
     * mismo borrador (un doble clic, un reenvío) solo uno gana; el otro recibe null y no crea nada. El ganador sigue con la ficha
     * nueva. Devuelve el identificador del carrito.
     */
    public function claim(): ?int
    {
        $old = (string) $this->state['cart'];
        if ($old === '') {
            return null;
        }
        $new = Random::token(24);
        $changed = $this->app->db()->run(
            'UPDATE {carts} SET token = ?, updated_at = ? WHERE token = ? AND order_id IS NULL',
            [hash('sha256', $new), $this->app->clock()->sql(), hash('sha256', $old)]
        );
        if ($changed !== 1) {
            return null;
        }
        $this->set('cart', $new);
        $this->cart = null;
        $this->mayDrop = true;
        $this->cart();

        return $this->cartId;
    }

    /** Empieza de cero: el carrito del borrador se borra (si no es ya un pedido). */
    public function reset(): void
    {
        $raw = (string) $this->state['cart'];
        if ($raw !== '') {
            $this->app->db()->run('DELETE FROM {carts} WHERE token = ? AND order_id IS NULL', [hash('sha256', $raw)]);
        }
        $this->state = self::normalize([]);
        $this->dirty = true;
        $this->customer = false;
        $this->cart = null;
        $this->cartId = 0;
    }

    /**
     * «Repetir»: un borrador nuevo con el cliente, las líneas y la dirección de un pedido. Lo que ya no existe o está agotado no
     * se añade (y se dice); de lo que queda menos, se pone lo que hay.
     *
     * @param array<string,mixed> $order Orders::find()
     *
     * @return string[] avisos de lo que no se ha podido copiar
     */
    public function repeat(array $order): array
    {
        $this->reset();
        $warnings = [];
        $customerId = $order['customer_id'] !== null ? (int) $order['customer_id'] : 0;
        if ($customerId > 0) {
            $chosen = $this->chooseCustomer($customerId);
            if (empty($chosen['ok'])) {
                $warnings[] = t('The customer of that order no longer exists: choose one.');
            }
        }
        $catalog = $this->app->service('catalog');
        foreach ($order['items'] as $item) {
            if ((string) $item['kind'] !== 'product') {
                continue;
            }
            $name = (string) $item['name'] . ((string) $item['variant_label'] !== '' ? ' (' . (string) $item['variant_label'] . ')' : '');
            $productId = $item['product_id'] !== null ? (int) $item['product_id'] : 0;
            $p = $productId > 0 ? $catalog->purchasable($productId, $item['variant_id'] !== null ? (int) $item['variant_id'] : 0) : null;
            if ($p === null) {
                $warnings[] = t('{product} no longer exists: it was left out.', ['product' => $name]);
                continue;
            }
            if (empty($p['available'])) {
                $warnings[] = t('{product} is not available ({reason}): it was left out.', ['product' => $name, 'reason' => (string) $p['reason']]);
                continue;
            }
            $qty = (int) $item['qty'];
            if ($p['stock'] !== null && (string) $p['backorders'] !== 'yes' && $qty > (int) $p['stock']) {
                $qty = (int) $p['stock'];
                $warnings[] = t('Only {n} of {product} are left: the order has {n}.', ['n' => $qty, 'product' => $name]);
            }
            $data = is_array($item['data']) ? $item['data'] : [];
            $added = $this->cart()->add($productId, (int) $p['variant_id'], max(1, $qty), $data);
            if (empty($added['ok'])) {
                $warnings[] = $name . ': ' . (string) $added['error'];
            }
        }
        $shipping = is_array($order['shipping']) ? $order['shipping'] : (is_array($order['billing']) ? $order['billing'] : []);
        if ($this->customer() !== null && $shipping !== []) {
            $problem = $this->repeatAddress($shipping);
            if ($problem !== '') {
                $warnings[] = t('The address of that order could not be copied: {reason}', ['reason' => $problem]);
            }
        }

        return $warnings;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * La línea del carrito con esa clave, o null.
     *
     * @return array<string,mixed>|null
     */
    public function line(string $key): ?array
    {
        $lines = $this->summary()['lines'];

        return $key !== '' && isset($lines[$key]) ? $lines[$key] : null;
    }

    /**
     * La dirección del pedido repetido: la guardada que coincide, o se guarda en la cuenta.
     *
     * @param array<string,mixed> $address
     *
     * @return string «» si se ha podido, o el motivo
     */
    private function repeatAddress(array $address): string
    {
        $same = static function (array $a, array $b): bool {
            foreach (['line1', 'postcode', 'city', 'country'] as $key) {
                $x = isset($a[$key]) ? strtolower(trim((string) $a[$key])) : '';
                $y = isset($b[$key]) ? strtolower(trim((string) $b[$key])) : '';
                if ($x !== $y) {
                    return false;
                }
            }

            return true;
        };
        foreach ($this->addresses() as $saved) {
            if ($same($saved, $address)) {
                $this->chooseAddress((int) $saved['id']);

                return '';
            }
        }
        $result = $this->newAddress($address);

        return !empty($result['ok']) ? '' : (string) $result['error'];
    }

    /** El destino del carrito sale de la dirección elegida (impuestos y envío son los suyos). */
    private function applyDestination(): void
    {
        $address = $this->address();
        if ($address === null) {
            return;
        }
        $this->cart()->setDestination([
            'country' => (string) $address['country'], 'region' => (string) $address['region'],
            'postcode' => (string) $address['postcode'], 'city' => (string) $address['city'],
        ]);
    }

    /** Al quitar la última línea el carrito de la tienda desaparece entero: se vuelve a crear con la misma ficha y lo elegido. */
    private function afterRemove(): void
    {
        if ($this->app->db()->value('SELECT id FROM {carts} WHERE id = ?', [$this->cartId]) !== null) {
            return;
        }
        $this->cart = null;
        $this->cart();
        $this->applyDestination();
        $pay = (string) $this->state['pay'];
        if ($pay === 'paid') {
            $this->cart()->setPaymentMethod(ManualOrderPayment::CODE);
        } elseif (strpos($pay, 'method:') === 0) {
            $this->cart()->setPaymentMethod(substr($pay, 7));
        }
    }

    /** El cliente o los precios han cambiado: el carrito se vuelve a calcular con ellos. */
    private function refresh(): void
    {
        if ($this->cart !== null) {
            ManualOrderPrices::forApp($this->app)->arm($this->cartId, $this->state['prices']);
            $this->cart->actAs($this->customer());
        }
    }

    private function rebind(): void
    {
        $this->customer = false;
        if ($this->cart !== null) {
            $this->ensureRow();
            $this->refresh();
        }
    }

    /** La fila del carrito del borrador: la que hay, o una nueva (con la misma ficha si se borró). Devuelve la ficha. */
    private function ensureRow(): string
    {
        $db = $this->app->db();
        $raw = (string) $this->state['cart'];
        $customer = $this->customer();
        $customerId = $customer !== null ? (int) $customer['id'] : null;
        if ($raw !== '') {
            $row = $db->row('SELECT id, order_id, customer_id FROM {carts} WHERE token = ?', [hash('sha256', $raw)]);
            if ($row !== null && ($row['order_id'] === null || (int) $row['order_id'] <= 0)) {
                $this->cartId = (int) $row['id'];
                $current = $row['customer_id'] !== null ? (int) $row['customer_id'] : null;
                if ($current !== $customerId) {
                    $db->update('carts', ['customer_id' => $customerId, 'updated_at' => $this->app->clock()->sql()], ['id' => $this->cartId]);
                }

                return $raw;
            }
            if ($row !== null) {
                $raw = '';
            }
        }
        if ($raw === '') {
            $raw = Random::token(24);
        }
        $now = $this->app->clock()->sql();
        $this->cartId = $db->insert('carts', [
            'token' => hash('sha256', $raw), 'customer_id' => $customerId, 'lang' => $this->app->lang(), 'items' => '[]', 'source' => 'admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->set('cart', $raw);

        return $raw;
    }

    /** Pone la ficha del borrador en la petición como si fuera la cookie del carrito (recordando la que había). */
    private function bindToken(string $raw): void
    {
        $request = $this->app->request();
        $name = $this->app->cookieName('k');
        if ($this->original === null) {
            $had = isset($request->cookies[$name]);
            $this->original = [$had, $had ? (string) $request->cookies[$name] : ''];
        }
        $request->cookies[$name] = $raw;
    }

    /**
     * @param mixed $value
     */
    private function set(string $key, $value): void
    {
        if (!array_key_exists($key, $this->state) || $this->state[$key] !== $value) {
            $this->state[$key] = $value;
            $this->dirty = true;
        }
    }

    /**
     * Lo guardado en la sesión, con sus tipos (lo que no tiene forma se descarta).
     *
     * @param mixed $raw
     *
     * @return array<string,mixed>
     */
    private static function normalize($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $text = static function (string $key, int $max) use ($raw): string {
            return isset($raw[$key]) && is_scalar($raw[$key]) ? mb_substr(trim((string) $raw[$key]), 0, $max, 'UTF-8') : '';
        };
        $cart = $text('cart', 48);
        $pay = $text('pay', 60);
        if ($pay !== 'paid' && preg_match('/^method:[a-z0-9_-]{1,40}$/D', $pay) !== 1) {
            $pay = '';
        }
        $prices = [];
        foreach (isset($raw['prices']) && is_array($raw['prices']) ? $raw['prices'] : [] as $key => $price) {
            if (is_string($key) && preg_match('/^p\d+-v\d+-[a-f0-9]{6}$/D', $key) === 1 && is_array($price) && isset($price['price']) && is_int($price['price']) && $price['price'] >= 0) {
                $prices[$key] = ['price' => $price['price'], 'reason' => isset($price['reason']) && is_string($price['reason']) ? mb_substr($price['reason'], 0, self::MAX_REASON, 'UTF-8') : ''];
            }
        }
        $quotes = [];
        foreach (isset($raw['quotes']) && is_array($raw['quotes']) ? $raw['quotes'] : [] as $token => $snapshot) {
            if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1 && is_array($snapshot)) {
                $quotes[$token] = $snapshot;
            }
        }

        return [
            'cart' => preg_match('/^[a-f0-9]{48}$/D', $cart) === 1 ? $cart : '',
            'customer_id' => isset($raw['customer_id']) && is_int($raw['customer_id']) && $raw['customer_id'] > 0 ? $raw['customer_id'] : 0,
            'address_id' => isset($raw['address_id']) && is_int($raw['address_id']) && $raw['address_id'] > 0 ? $raw['address_id'] : 0,
            'pay' => $pay,
            'paid_with' => $text('paid_with', ManualOrderPayment::MAX_HOW),
            'note' => $text('note', self::MAX_NOTE),
            'notify' => !array_key_exists('notify', $raw) || !empty($raw['notify']),
            'prices' => $prices,
            'quotes' => array_slice($quotes, -self::MAX_QUOTES, null, true),
        ];
    }

    /**
     * Un precio escrito por una persona («12,50», «12.5», «0») a céntimos; null si no vale (vacío, negativo, letras…).
     */
    public static function parsePrice(string $text): ?int
    {
        $text = trim($text);
        if (preg_match('/^0+(?:[.,]0{1,2})?$/D', $text) === 1) {
            return 0;
        }

        return OrdersAdmin::parseMoney($text);
    }

    /**
     * @param array<string,mixed> $in
     */
    private static function field(array $in, string $name, int $max): string
    {
        $value = isset($in[$name]) && is_scalar($in[$name]) ? (string) $in[$name] : '';
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return mb_substr(trim(is_string($clean) ? $clean : ''), 0, $max, 'UTF-8');
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function personName(array $row): string
    {
        $name = trim((isset($row['first_name']) ? (string) $row['first_name'] : '') . ' ' . (isset($row['last_name']) ? (string) $row['last_name'] : ''));

        return $name !== '' ? $name : (isset($row['email']) ? (string) $row['email'] : '');
    }

    /**
     * @return array<string,mixed>
     */
    private static function done(string $message): array
    {
        return ['ok' => true, 'error' => '', 'errors' => [], 'message' => $message];
    }

    /**
     * @return array<string,mixed>
     */
    private static function fail(string $error, string $field): array
    {
        return ['ok' => false, 'error' => $error, 'errors' => [$field => $error], 'message' => ''];
    }
}
