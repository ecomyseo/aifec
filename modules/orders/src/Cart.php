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
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Modules\Coupons\Coupons;
use Aifec\Security\Random;

/**
 * Servicio «cart»: el carrito con precios (contrato § 4 y § 5 de 03-comercio.md).
 *
 * Identidad. La cookie $app->cookieName('k') lleva una ficha aleatoria; en la tabla carts solo se guarda su SHA-256.
 * La cookie se pide UNA vez, al crear el carrito (al añadir lo primero), y se olvida cuando el carrito se vacía o se
 * convierte en pedido. Quien no ha añadido nada no tiene cookie ni fila: summary() y count() no tocan la base de datos
 * y las páginas públicas siguen saliendo de la caché.
 *
 * El cálculo. summary() vuelve a pedir al catálogo cada línea (precio y existencias de AHORA), resuelve impuestos,
 * descuentos, envío y recargos y lo pasa por Aifec\Commerce\Totals::calculate(), la misma cuenta que usará el pedido.
 * Se calcula una vez por petición y se olvida en cuanto algo cambia.
 *
 * Qué es una línea. La misma combinación de producto, variante y datos propios es la misma línea; su clave es
 * «p<producto>-v<variante>-<6 primeros del md5 de los datos>».
 *
 * Ganchos. Acción cart.changed (array $resumen) tras cada cambio. Filtro SELLADO cart.adjustments
 * (array $ajustes, array $resumen): cada ajuste ['label','amount','source','tax_rate','tax_name'] sale como línea de
 * ajuste con su origen en totals['adjustments'].
 */
final class Cart
{
    private const COOKIE_DAYS = 30;
    private const MAX_LINES = 100;
    private const MAX_QTY = 9999;
    private const MAX_DATA = 4000;

    /** @var App */
    private $app;

    /** @var Request|null la petición a la que pertenece lo que hay en memoria */
    private $request;

    /** @var bool ¿ya se ha buscado el carrito en esta petición? */
    private $loaded = false;

    /** @var array<string,mixed>|null */
    private $row;

    /** @var string|null ficha en claro del carrito creado o reabierto en esta petición */
    private $raw;

    /** @var array<string,mixed>|null */
    private $summary;

    /**
     * @var array<string,mixed>|false|null el cliente para el que se calcula el carrito en esta petición (actAs()): una fila de
     *                                    Customers::find() con su grupo, null para un cliente sin cuenta, false = el que ha entrado
     */
    private $owner = false;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * El carrito con precios. Sin carrito devuelve el mismo array, vacío, sin crear cookie ni fila ni consultar nada.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $this->sync();
        if ($this->summary !== null) {
            return $this->summary;
        }
        $row = $this->load();
        $this->summary = $row === null ? $this->emptySummary() : $this->build($row);

        return $this->summary;
    }

    /** Unidades en el carrito. 0 sin tocar la base de datos si no hay cookie. */
    public function count(): int
    {
        $row = $this->load();
        if ($row === null) {
            return 0;
        }
        $total = 0;
        foreach ($this->items($row) as $item) {
            $total += $item['qty'];
        }

        return $total;
    }

    /** ¿Hay carrito? Sin cookie, no hay y no se consulta nada. */
    public function exists(): bool
    {
        return $this->load() !== null;
    }

    /**
     * Deja al visitante con carrito YA: si la petición no trae uno vivo, lo crea vacío y encola su cookie ahora.
     *
     * Para quien va a escribir la respuesta por partes (Response::stream): las cookies salen con las cabeceras, antes del primer
     * trozo, y el carrito que naciera después —al añadir un producto a mitad de la respuesta— quedaría en la base de datos sin
     * que el navegador recibiera su cookie: la petición siguiente lo vería vacío, sin un solo error. Un carrito vacío de más lo
     * limpia la tarea de carritos viejos; una cookie que no llega no la arregla nadie.
     */
    public function ensure(): void
    {
        if ($this->load() === null) {
            $this->create([]);
        }
    }

    /**
     * Calcula el carrito de esta petición para otro cliente, no para el que ha entrado en la tienda: lo usa el pedido a mano del
     * panel (Admin\ManualOrderDraft), donde quien compra no es quien está delante. Su grupo (descuento y exención) y sus cupones
     * («solo el primer pedido») son los suyos, y Orders::place() le da el pedido a él. null = un cliente sin cuenta. Olvida lo
     * que tenía en memoria y vale solo para esta petición: con la siguiente vuelve a mandar quien haya entrado.
     *
     * @param array<string,mixed>|null $customer una fila de Customers::find()
     */
    public function actAs(?array $customer): void
    {
        $this->sync();
        if ($customer !== null && $this->app->has('customers')) {
            $group = $this->app->service('customers')->group(isset($customer['group_id']) && $customer['group_id'] !== null ? (int) $customer['group_id'] : null);
            $customer['group'] = $group;
            $customer['group_tax_exempt'] = !empty($group['tax_exempt']);
        }
        $this->owner = $customer;
        $this->loaded = false;
        $this->row = null;
        $this->raw = null;
        $this->summary = null;
    }

    /**
     * El cliente de actAs() en esta petición (array, o null si no tiene cuenta), o false si el carrito es el de quien ha entrado.
     *
     * @return array<string,mixed>|false|null
     */
    public function actingAs()
    {
        $this->sync();

        return $this->owner;
    }

    /** La clave de una línea: «p12-v0-ab12cd». */
    public static function lineKey(int $productId, int $variantId, array $data = []): string
    {
        return 'p' . $productId . '-v' . $variantId . '-' . substr(md5(self::canonical($data)), 0, 6);
    }

    // ------------------------------------------------------------------ líneas

    /**
     * Añade unidades de un producto (o de una variante). Crea el carrito si no existe.
     *
     * @param array<mixed> $data datos propios de la línea (personalización); llegan al pedido
     *
     * @return array{ok:bool,error:string,key:string}
     */
    public function add(int $productId, int $variantId, int $qty, array $data = []): array
    {
        $this->sync();
        if ($productId <= 0 || $variantId < 0) {
            return $this->fail(t('This product is not available'));
        }
        if ($qty < 1) {
            return $this->fail(t('Enter a valid quantity'));
        }
        $clean = self::cleanData($data);
        if (strlen((string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_DATA) {
            return $this->fail(t('The extra details are too long'));
        }
        $p = $this->catalog()->purchasable($productId, $variantId, $this->app->lang());
        if ($p === null) {
            return $this->fail(t('This product is not available'));
        }
        if (empty($p['available'])) {
            return $this->fail((string) $p['reason'] !== '' ? (string) $p['reason'] : t('This product is not available'));
        }

        $variantId = (int) $p['variant_id'];
        $key = self::lineKey($productId, $variantId, $clean);
        $row = $this->load();
        $items = $row !== null ? $this->items($row) : [];
        $index = null;
        foreach ($items as $i => $item) {
            if ($item['key'] === $key) {
                $index = $i;
                break;
            }
        }
        $have = $index !== null ? $items[$index]['qty'] : 0;
        $problem = $this->qtyProblem($p, $have + $qty, $have);
        if ($problem !== '') {
            return $this->fail($problem);
        }
        if ($index === null && count($items) >= self::MAX_LINES) {
            return $this->fail(t('Your cart is full'));
        }
        // Un plugin puede negarse a juntar esta línea con las que ya hay (un vendedor por carrito): devuelve el motivo.
        $veto = $this->app->hooks()->apply('cart.add.check', '', ['product_id' => $productId, 'variant_id' => $variantId, 'qty' => $qty, 'items' => $items]);
        if (is_string($veto) && trim($veto) !== '') {
            return $this->fail(trim($veto));
        }

        if ($index !== null) {
            $items[$index]['qty'] = $have + $qty;
        } else {
            $items[] = ['key' => $key, 'product_id' => $productId, 'variant_id' => $variantId, 'qty' => $qty, 'data' => $clean];
        }
        if ($row === null) {
            $this->create($items);
        } else {
            $this->write(['items' => $this->encode($items)]);
        }
        $this->changed();

        return ['ok' => true, 'error' => '', 'key' => $key];
    }

    /**
     * Cambia la cantidad de una línea. Con 0 la quita.
     *
     * @return array{ok:bool,error:string,key:string}
     */
    public function update(string $key, int $qty): array
    {
        $row = $this->load();
        if ($row === null) {
            return $this->fail(t('Your cart is empty'));
        }
        $items = $this->items($row);
        $index = null;
        foreach ($items as $i => $item) {
            if ($item['key'] === $key) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return $this->fail(t('This item is no longer in your cart'));
        }
        if ($qty < 0) {
            // Una cantidad negativa es un error, no «quitar»: la línea se queda como estaba (0 sí quita).
            return $this->fail(t('Enter a valid quantity'));
        }
        if ($qty === 0) {
            $this->remove($key);

            return ['ok' => true, 'error' => '', 'key' => $key];
        }
        $p = $this->catalog()->purchasable($items[$index]['product_id'], $items[$index]['variant_id'], $this->app->lang());
        if ($p === null || empty($p['available'])) {
            return $this->fail($p !== null && (string) $p['reason'] !== '' ? (string) $p['reason'] : t('This product is no longer available'));
        }
        $problem = $this->qtyProblem($p, $qty, 0);
        if ($problem !== '') {
            return $this->fail($problem);
        }
        $items[$index]['qty'] = $qty;
        $this->write(['items' => $this->encode($items)]);
        $this->changed();

        return ['ok' => true, 'error' => '', 'key' => $key];
    }

    /** Quita una línea. Si era la última, el carrito desaparece (fila y cookie). */
    public function remove(string $key): void
    {
        $row = $this->load();
        if ($row === null) {
            return;
        }
        $kept = [];
        foreach ($this->items($row) as $item) {
            if ($item['key'] !== $key) {
                $kept[] = $item;
            }
        }
        if ($kept === []) {
            $this->forget();
        } else {
            $this->write(['items' => $this->encode($kept)]);
        }
        $this->changed();
    }

    /** Vacía el carrito: borra la fila y olvida la cookie. */
    public function clear(): void
    {
        if ($this->load() === null) {
            return;
        }
        $this->forget();
        $this->changed();
    }

    // ------------------------------------------------------------------ cupones

    /**
     * Aplica un código. Vale si el cupón es válido para este carrito y se puede combinar con los que ya hay.
     *
     * @return array{ok:bool,error:string,code:string}
     */
    public function applyCoupon(string $code): array
    {
        $row = $this->load();
        if ($row === null) {
            return ['ok' => false, 'error' => t('Your cart is empty'), 'code' => ''];
        }
        if (!$this->app->has('coupons')) {
            return ['ok' => false, 'error' => t('Coupons are not available'), 'code' => ''];
        }
        $coupons = $this->app->service('coupons');
        $normalized = $this->normalizeCode($code);
        $codes = $this->codes($row);
        if ($normalized !== '' && in_array($normalized, $codes, true)) {
            return ['ok' => true, 'error' => '', 'code' => $normalized];
        }
        $couponCart = $this->couponCart($this->resolveLines($this->items($row), $this->app->lang()), $row, $this->customer());
        if ($normalized === '') {
            $check = $coupons->check('', $couponCart);

            return ['ok' => false, 'error' => (string) $check['error'], 'code' => ''];
        }

        $result = $coupons->combine(array_merge($codes, [$normalized]), $couponCart);
        $accepted = [];
        foreach ($result['accepted'] as $item) {
            $accepted[] = (string) $item['coupon']['code'];
        }
        if (!in_array($normalized, $accepted, true)) {
            $reason = t('This coupon code is not valid');
            foreach ($result['rejected'] as $rejected) {
                if ($rejected['code'] === $normalized) {
                    $reason = (string) $rejected['error'];
                }
            }

            return ['ok' => false, 'error' => $reason, 'code' => $normalized];
        }
        $this->write(['coupons' => $this->encode($accepted)]);
        $this->changed();

        return ['ok' => true, 'error' => '', 'code' => $normalized];
    }

    public function removeCoupon(string $code): void
    {
        $row = $this->load();
        if ($row === null) {
            return;
        }
        $normalized = $this->normalizeCode($code);
        $kept = [];
        foreach ($this->codes($row) as $existing) {
            if ($existing !== $normalized) {
                $kept[] = $existing;
            }
        }
        $this->write(['coupons' => $this->encode($kept)]);
        $this->changed();
    }

    /** El código como lo guarda y lo busca el módulo de cupones. */
    private function normalizeCode(string $code): string
    {
        return $this->app->has('coupons') ? Coupons::normalizeCode($code) : strtoupper(trim($code));
    }

    // ------------------------------------------------------------------ envío, pago y caja

    /**
     * Destino de la compra: country (2 letras), region, postcode y city. Un país que no es de dos letras se ignora y
     * vale el de por defecto. Sin carrito no hace nada (no se crea una cookie por esto).
     *
     * @param array<string,mixed> $destination
     */
    public function setDestination(array $destination): void
    {
        if ($this->load() === null) {
            return;
        }
        $country = isset($destination['country']) && is_scalar($destination['country']) ? strtoupper(trim((string) $destination['country'])) : '';
        $text = static function (string $key, int $max) use ($destination): string {
            $value = isset($destination[$key]) && is_scalar($destination[$key]) ? trim((string) $destination[$key]) : '';

            return mb_substr($value, 0, $max, 'UTF-8');
        };
        $clean = [
            'country' => preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : '',
            'region' => $text('region', 16),
            'postcode' => strtoupper($text('postcode', 12)),
            'city' => $text('city', 80),
        ];
        $this->write(['destination' => $this->encode($clean)]);
        $this->changed();
    }

    public function setCarrier(int $carrierId): void
    {
        if ($this->load() === null) {
            return;
        }
        $this->write(['carrier_id' => $carrierId > 0 ? $carrierId : null]);
        $this->changed();
    }

    /** La forma de pago elegida. Un código que no está registrado se olvida. */
    public function setPaymentMethod(string $code): void
    {
        if ($this->load() === null) {
            return;
        }
        $method = $this->app->has('payments') ? $this->app->service('payments')->get($code) : null;
        $this->write(['payment_method' => $method !== null ? strtolower(trim($method->code())) : null]);
        $this->changed();
    }

    /**
     * Lo que se va rellenando en la caja (contacto, direcciones, nota). Se funde con lo que había; un valor null borra
     * su clave. Si trae un correo válido, también queda en la columna email del carrito.
     *
     * @param array<string,mixed> $data
     *
     * @throws \InvalidArgumentException si lo guardado pasaría de 30 KB
     */
    public function setCheckout(array $data): void
    {
        $row = $this->load();
        if ($row === null) {
            return;
        }
        $merged = $this->decodeMap(isset($row['checkout']) ? $row['checkout'] : null);
        foreach ($data as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_.\-]{1,60}$/D', $key) !== 1) {
                continue;
            }
            if ($value === null) {
                unset($merged[$key]);
            } elseif (is_array($value)) {
                $merged[$key] = self::cleanData($value);
            } elseif (is_scalar($value)) {
                $merged[$key] = mb_substr((string) $value, 0, 2000, 'UTF-8');
            }
        }
        $json = $this->encode($merged);
        if (strlen($json) > 30000) {
            throw new \InvalidArgumentException('Checkout data is too large');
        }
        $changes = ['checkout' => $json];
        if (isset($data['email']) && is_string($data['email'])) {
            $email = strtolower(trim($data['email']));
            if ($email !== '' && strlen($email) <= 191 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $changes['email'] = $email;
            }
        }
        $this->write($changes);
        $this->changed();
    }

    // ------------------------------------------------------------------ de carrito a pedido

    /** El carrito se ha hecho pedido: guarda order_id y olvida la cookie. */
    public function markConverted(int $orderId): void
    {
        $row = $this->load();
        if ($row === null || $orderId <= 0) {
            return;
        }
        $this->app->db()->update('carts', ['order_id' => $orderId, 'updated_at' => $this->app->clock()->sql()], ['id' => (int) $row['id']]);
        $this->dropCookie();
        $this->raw = null;
        $this->row = null;
        $this->loaded = true;
        $this->summary = null;
    }

    /**
     * Un cobro que no se completó: si el pedido de esa ficha sigue sin pagar, su carrito queda libre otra vez
     * (order_id a null), con ficha nueva y su cookie puesta. False si el pedido no existe, la ficha no coincide, ya
     * está pagado o ya no se conserva su carrito.
     */
    public function reopen(int $orderId, string $secret): bool
    {
        $this->sync();
        if ($orderId <= 0 || $secret === '') {
            return false;
        }
        $db = $this->app->db();
        $order = $db->row('SELECT id, secret, payment_status FROM {orders} WHERE id = ?', [$orderId]);
        if ($order === null) {
            return false;
        }
        $stored = isset($order['secret']) && is_string($order['secret']) ? $order['secret'] : '';
        if ($stored === '' || !hash_equals($stored, hash('sha256', $secret))) {
            return false;
        }
        if (!in_array((string) $order['payment_status'], ['pending', 'failed', 'cancelled'], true)) {
            return false;
        }
        $cart = $db->row('SELECT * FROM {carts} WHERE order_id = ?', [$orderId]);
        if ($cart === null) {
            return false;
        }

        $raw = Random::token(24);
        $changes = ['order_id' => null, 'token' => hash('sha256', $raw), 'updated_at' => $this->app->clock()->sql()];
        $customer = $this->customer();
        if ($customer !== null && ($cart['customer_id'] === null || (int) $cart['customer_id'] === 0)) {
            $changes['customer_id'] = (int) $customer['id'];
        }
        $db->update('carts', $changes, ['id' => (int) $cart['id']]);
        $this->rememberCookie($raw);
        $this->raw = $raw;
        $this->row = null;
        $this->loaded = false;
        $this->summary = null;

        return true;
    }

    /**
     * Un cliente acaba de entrar (acción customer.login). El carrito de este navegador pasa a ser suyo; si ya tenía
     * otro carrito sin pedido, se funden: se suman las cantidades sin pasar de los límites de cada producto y queda uno.
     *
     * @param array<string,mixed> $customer
     */
    public function onLogin(array $customer): void
    {
        $id = isset($customer['id']) ? (int) $customer['id'] : 0;
        $row = $this->load();
        if ($row === null || $id <= 0) {
            return;
        }
        $db = $this->app->db();
        $others = $db->select(
            'SELECT * FROM {carts} WHERE customer_id = ? AND id <> ? AND order_id IS NULL ORDER BY updated_at DESC, id DESC',
            [$id, (int) $row['id']]
        );
        if ($others === []) {
            if ((int) $row['customer_id'] !== $id) {
                $this->write(['customer_id' => $id]);
            }

            return;
        }

        $lang = $this->app->lang();
        $items = $this->items($row);
        $codes = $this->codes($row);
        foreach ($others as $other) {
            foreach ($this->items($other) as $theirs) {
                $p = $this->catalog()->purchasable($theirs['product_id'], $theirs['variant_id'], $lang);
                if ($p === null) {
                    continue;
                }
                $limit = $this->limitOf($p);
                $found = null;
                foreach ($items as $i => $mine) {
                    if ($mine['key'] === $theirs['key']) {
                        $found = $i;
                        break;
                    }
                }
                if ($found !== null) {
                    $want = $items[$found]['qty'] + $theirs['qty'];
                    if ($limit !== null) {
                        $want = min($want, max($limit, $items[$found]['qty']));
                    }
                    $items[$found]['qty'] = min($want, self::MAX_QTY);
                } elseif (count($items) < self::MAX_LINES) {
                    $qty = $theirs['qty'];
                    if ($limit !== null && $limit > 0) {
                        $qty = min($qty, $limit);
                    }
                    $theirs['qty'] = min($qty, self::MAX_QTY);
                    $items[] = $theirs;
                }
            }
            foreach ($this->codes($other) as $code) {
                if (!in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }
        $this->write(['customer_id' => $id, 'items' => $this->encode($items), 'coupons' => $this->encode($codes)]);
        foreach ($others as $other) {
            $db->delete('carts', ['id' => (int) $other['id']]);
        }
        $this->changed();
    }

    // ------------------------------------------------------------------ memoria y base de datos

    /** La cookie del carrito (no se deja leer) y, a su lado, la marca que sí ve el tema para saber si pedir el resumen. */
    private function rememberCookie(string $raw): void
    {
        $this->app->setCookie($this->cookieName(), $raw, self::COOKIE_DAYS);
        $this->app->setCookie($this->app->cookieName('ck'), '1', self::COOKIE_DAYS, true);
    }

    private function dropCookie(): void
    {
        $this->app->forgetCookie($this->cookieName());
        $this->app->forgetCookie($this->app->cookieName('ck'));
    }

    private function cookieName(): string
    {
        return $this->app->cookieName('k');
    }

    /** Lo que hay en memoria solo vale para la petición que lo cargó. */
    private function sync(): void
    {
        $request = $this->app->request();
        if ($this->request !== $request) {
            $this->request = $request;
            $this->loaded = false;
            $this->row = null;
            $this->raw = null;
            $this->summary = null;
            $this->owner = false;
        }
    }

    /** La ficha en claro: la que creó esta petición o la de la cookie. Null si no hay. */
    private function rawToken(): ?string
    {
        if ($this->raw !== null) {
            return $this->raw;
        }
        $cookie = $this->app->request()->cookie($this->cookieName());

        return is_string($cookie) && preg_match('/^[a-f0-9]{48}$/D', $cookie) === 1 ? $cookie : null;
    }

    /**
     * La fila del carrito de esta petición, o null. Sin cookie no se consulta nada; un carrito ya convertido en pedido
     * no cuenta.
     *
     * @return array<string,mixed>|null
     */
    private function load(): ?array
    {
        $this->sync();
        if ($this->loaded) {
            return $this->row;
        }
        $this->loaded = true;
        $this->row = null;
        $raw = $this->rawToken();
        if ($raw === null) {
            return null;
        }
        $row = $this->app->db()->row('SELECT * FROM {carts} WHERE token = ?', [hash('sha256', $raw)]);
        if ($row === null || ($row['order_id'] !== null && (int) $row['order_id'] > 0)) {
            return null;
        }
        $this->row = $row;

        return $row;
    }

    /**
     * Crea la fila, pone la cookie y deja el carrito cargado.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function create(array $items): void
    {
        $raw = Random::token(24);
        $now = $this->app->clock()->sql();
        $customer = $this->customer();
        $id = $this->app->db()->insert('carts', [
            'token' => hash('sha256', $raw),
            'customer_id' => $customer !== null ? (int) $customer['id'] : null,
            'lang' => $this->app->lang(),
            'items' => $this->encode($items),
            'source' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->rememberCookie($raw);
        $this->raw = $raw;
        $this->loaded = true;
        $this->row = $this->app->db()->find('carts', ['id' => $id]);
        $this->summary = null;
    }

    /**
     * @param array<string,mixed> $changes
     */
    private function write(array $changes): void
    {
        $row = $this->load();
        if ($row === null) {
            return;
        }
        $changes['updated_at'] = $this->app->clock()->sql();
        $this->app->db()->update('carts', $changes, ['id' => (int) $row['id']]);
        $this->row = array_replace($row, $changes);
        $this->summary = null;
    }

    /** Borra el carrito y la cookie. */
    private function forget(): void
    {
        $row = $this->load();
        if ($row !== null) {
            $this->app->db()->delete('carts', ['id' => (int) $row['id']]);
        }
        $this->dropCookie();
        $this->raw = null;
        $this->row = null;
        $this->loaded = true;
        $this->summary = null;
    }

    /** Tras un cambio: se olvida el cálculo, se vuelve a hacer y se avisa. */
    private function changed(): void
    {
        $this->summary = null;
        $this->app->hooks()->fire('cart.changed', $this->summary());
    }

    /**
     * @return array{ok:bool,error:string,key:string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'key' => ''];
    }

    /** @return \Aifec\Modules\Catalog\Catalog */
    private function catalog()
    {
        return $this->app->service('catalog');
    }

    // ------------------------------------------------------------------ datos de la fila

    /**
     * @param array<string,mixed> $row
     *
     * @return array<int,array{key:string,product_id:int,variant_id:int,qty:int,data:array<mixed>}>
     */
    private function items(array $row): array
    {
        $data = isset($row['items']) && is_string($row['items']) ? json_decode($row['items'], true) : null;
        $out = [];
        foreach (is_array($data) ? $data : [] as $item) {
            if (!is_array($item) || !isset($item['product_id'], $item['qty']) || (int) $item['qty'] < 1) {
                continue;
            }
            $productId = (int) $item['product_id'];
            $variantId = isset($item['variant_id']) ? (int) $item['variant_id'] : 0;
            $extra = isset($item['data']) && is_array($item['data']) ? $item['data'] : [];
            $out[] = [
                'key' => isset($item['key']) && is_string($item['key']) && $item['key'] !== '' ? $item['key'] : self::lineKey($productId, $variantId, $extra),
                'product_id' => $productId,
                'variant_id' => $variantId,
                'qty' => (int) $item['qty'],
                'data' => $extra,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return string[]
     */
    private function codes(array $row): array
    {
        $data = isset($row['coupons']) && is_string($row['coupons']) ? json_decode($row['coupons'], true) : null;
        $out = [];
        foreach (is_array($data) ? $data : [] as $code) {
            if (is_string($code) && $code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     *
     * @return array<string,mixed>
     */
    private function decodeMap($value): array
    {
        $data = is_string($value) && $value !== '' ? json_decode($value, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * @param mixed $value
     */
    private function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '[]';
    }

    /**
     * Los datos propios de una línea, saneados: claves sencillas, textos de hasta 1000 caracteres, hasta tres niveles
     * y 30 entradas por nivel. Lo vacío no cuenta (una nota en blanco no crea otra línea).
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function cleanData(array $data, int $depth = 0): array
    {
        $out = [];
        $n = 0;
        foreach ($data as $key => $value) {
            if (++$n > 30) {
                break;
            }
            if (is_string($key) && preg_match('/^[A-Za-z0-9_.\- ]{1,60}$/D', $key) !== 1) {
                continue;
            }
            if (is_array($value)) {
                $inner = $depth < 2 ? self::cleanData($value, $depth + 1) : [];
                if ($inner !== []) {
                    $out[$key] = $inner;
                }
            } elseif (is_bool($value)) {
                $out[$key] = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $text = mb_substr(trim((string) $value), 0, 1000, 'UTF-8');
                if ($text !== '') {
                    $out[$key] = $text;
                }
            }
        }

        return $out;
    }

    /**
     * Los datos de una línea en una forma única, para que el orden de las claves no cree líneas distintas.
     *
     * @param array<mixed> $data
     */
    private static function canonical(array $data): string
    {
        $sorted = self::sortDeep($data);
        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '[]';
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function sortDeep(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortDeep($value);
            }
        }
        if (!array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    // ------------------------------------------------------------------ cantidades

    /**
     * El tope de unidades de un producto: el máximo por pedido y las existencias (si se controlan y no admite pedidos
     * sin existencias). Null = sin tope.
     *
     * @param array<string,mixed> $p
     */
    private function limitOf(array $p): ?int
    {
        $caps = [];
        if ((int) $p['max_qty'] > 0) {
            $caps[] = (int) $p['max_qty'];
        }
        if ($p['stock'] !== null && (string) $p['backorders'] !== 'yes') {
            $caps[] = max(0, (int) $p['stock']);
        }

        return $caps !== [] ? min($caps) : null;
    }

    /**
     * El motivo por el que esta cantidad no vale, o «» si vale. $have = lo que ya hay de la misma línea en el carrito.
     *
     * @param array<string,mixed> $p
     */
    private function qtyProblem(array $p, int $qty, int $have): string
    {
        $min = (int) $p['min_qty'];
        $max = (int) $p['max_qty'];
        if ($min > 0 && $qty < $min) {
            return t('The minimum quantity is {n}', ['n' => $min]);
        }
        if ($max > 0 && $qty > $max) {
            return t('The maximum quantity is {n}', ['n' => $max]);
        }
        if ($qty > self::MAX_QTY) {
            return t('The maximum quantity is {n}', ['n' => self::MAX_QTY]);
        }
        if ($p['stock'] !== null && (string) $p['backorders'] !== 'yes' && $qty > (int) $p['stock']) {
            $left = max(0, (int) $p['stock']);
            if ($have > 0) {
                return tn('Only {n} unit left in stock and you already have {have} in your cart', 'Only {n} units left in stock and you already have {have} in your cart', $left, ['have' => $have]);
            }

            return tn('Only {n} unit left', 'Only {n} units left', $left);
        }

        return '';
    }

    // ------------------------------------------------------------------ cliente y destino

    /**
     * El cliente que ha entrado, con su grupo (group, group_tax_exempt), o null. Si el carrito no es suyo todavía, lo
     * asocia.
     *
     * @return array<string,mixed>|null
     */
    private function customer(): ?array
    {
        if ($this->owner !== false) {
            return $this->owner;
        }
        if (!$this->app->has('customers')) {
            return null;
        }
        $customers = $this->app->service('customers');
        $current = $customers->current();
        if ($current === null) {
            return null;
        }
        $row = $this->row;
        if ($row !== null && (int) $row['customer_id'] !== (int) $current['id']) {
            $this->app->db()->update('carts', ['customer_id' => (int) $current['id']], ['id' => (int) $row['id']]);
            $this->row['customer_id'] = (int) $current['id'];
        }
        $group = $customers->group(isset($current['group_id']) ? $current['group_id'] : null);
        $current['group'] = $group;
        $current['group_tax_exempt'] = !empty($group['tax_exempt']);

        return $current;
    }

    /**
     * El destino guardado, o el de por defecto de la tienda.
     *
     * @param array<string,mixed>|null $row
     *
     * @return array{country:string,region:string,postcode:string,city:string}
     */
    private function destinationOf(?array $row): array
    {
        $saved = $row !== null ? $this->decodeMap(isset($row['destination']) ? $row['destination'] : null) : [];
        $country = isset($saved['country']) && is_string($saved['country']) ? $saved['country'] : '';
        if ($country === '') {
            $country = $this->app->has('shipping') ? $this->app->service('shipping')->defaultCountry() : $this->app->service('tax')->baseDestination()['country'];
        }
        $text = static function (string $key) use ($saved): string {
            return isset($saved[$key]) && is_string($saved[$key]) ? $saved[$key] : '';
        };

        return ['country' => $country, 'region' => $text('region'), 'postcode' => $text('postcode'), 'city' => $text('city')];
    }

    // ------------------------------------------------------------------ el cálculo

    /**
     * El resumen de un carrito vacío: la misma forma, sin consultar nada.
     *
     * @return array<string,mixed>
     */
    private function emptySummary(): array
    {
        $tax = $this->app->service('tax');

        return [
            'id' => null,
            'count' => 0,
            'lines' => [],
            'totals' => Totals::calculate(['prices_include_tax' => $tax->pricesIncludeTax()]),
            'display_with_tax' => $tax->displayWithTax(),
            'coupons' => [],
            'destination' => $this->destinationOf(null),
            'needs_shipping' => false,
            'weight' => 0,
            'shipping_options' => [],
            'carrier_id' => null,
            'payment_methods' => [],
            'payment_method' => null,
            'problems' => [],
            'currency' => $this->app->settings()->str('shop.currency', 'EUR'),
            'email' => '',
            'checkout' => [],
            'customer_id' => null,
            'lang' => $this->app->lang(),
            'tax_exempt' => false,
        ];
    }

    /**
     * Cada línea con lo que dice el catálogo AHORA: si se puede comprar y los avisos de cantidad.
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array{item:array<string,mixed>,p:array<string,mixed>|null,usable:bool,warnings:string[]}>
     */
    private function resolveLines(array $items, string $lang): array
    {
        $out = [];
        foreach ($items as $item) {
            $p = $this->catalog()->purchasable($item['product_id'], $item['variant_id'], $lang);
            $warnings = [];
            $usable = false;
            if ($p === null) {
                $warnings[] = t('This product is no longer available');
            } elseif (empty($p['available'])) {
                $warnings[] = (string) $p['reason'] !== '' ? (string) $p['reason'] : t('This product is no longer available');
            } else {
                $usable = true;
                $problem = $this->qtyProblem($p, $item['qty'], 0);
                if ($problem !== '') {
                    $warnings[] = $problem;
                }
            }
            $out[] = ['item' => $item, 'p' => $p, 'usable' => $usable, 'warnings' => $warnings];
        }

        return $out;
    }

    /**
     * Lo que el módulo de cupones necesita saber del carrito.
     *
     * @param array<int,array<string,mixed>> $resolved
     * @param array<string,mixed>            $row
     * @param array<string,mixed>|null       $customer
     *
     * @return array<string,mixed>
     */
    private function couponCart(array $resolved, array $row, ?array $customer): array
    {
        $lines = [];
        $itemsTotal = 0;
        foreach ($resolved as $r) {
            if (!$r['usable']) {
                continue;
            }
            $p = $r['p'];
            $lines[] = [
                'key' => $r['item']['key'],
                'product_id' => $r['item']['product_id'],
                'category_ids' => isset($p['category_ids']) && is_array($p['category_ids']) ? $p['category_ids'] : [],
                'unit_price' => (int) $p['unit_price'],
                'qty' => $r['item']['qty'],
                'on_sale' => !empty($p['on_sale']),
            ];
            $itemsTotal += (int) $p['unit_price'] * $r['item']['qty'];
        }
        $email = isset($row['email']) && is_string($row['email']) ? $row['email'] : '';
        if ($email === '' && $customer !== null && isset($customer['email']) && is_string($customer['email'])) {
            $email = $customer['email'];
        }

        return ['lines' => $lines, 'items_total' => $itemsTotal, 'customer_id' => $customer !== null ? (int) $customer['id'] : null, 'email' => $email];
    }

    /**
     * El resumen completo de un carrito que existe.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function build(array $row): array
    {
        $app = $this->app;
        $lang = $app->lang();
        $tax = $app->service('tax');
        $items = $this->items($row);
        $customer = $this->customer();
        $destination = $this->destinationOf($row);
        $inclusive = $tax->pricesIncludeTax();
        $exempt = $tax->exempt($customer, $destination);
        $currency = $app->settings()->str('shop.currency', 'EUR');
        $resolved = $this->resolveLines($items, $lang);

        $problems = [];
        $rates = [];
        $taxLines = [];
        $lines = [];
        $lineRates = [];
        $needsShipping = false;
        $weight = 0;
        $shipQty = 0;
        // Las líneas que viajan, para los transportes que cobran por producto (ShippingHandler: «lines» del paquete).
        $shipLines = [];
        $payLines = [];
        $itemsTotal = 0;
        $count = 0;
        foreach ($resolved as $r) {
            $item = $r['item'];
            $p = $r['p'];
            $count += $item['qty'];
            $name = $p !== null && (string) $p['name'] !== '' ? (string) $p['name'] : '#' . $item['product_id'];
            foreach ($r['warnings'] as $warning) {
                $problems[] = $name . ': ' . $warning;
            }
            $limit = $p !== null ? $this->limitOf($p) : null;
            $line = [
                'key' => $item['key'],
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'name' => $name,
                'variant_label' => $p !== null ? (string) $p['variant_label'] : '',
                'sku' => $p !== null ? (string) $p['sku'] : '',
                'image' => $p !== null && isset($p['image']) ? $p['image'] : null,
                'url' => $p !== null ? (string) $p['url'] : '',
                'qty' => $item['qty'],
                'max_qty' => $limit !== null ? $limit : 0,
                'unit_price' => $r['usable'] ? (int) $p['unit_price'] : 0,
                'subtotal' => 0,
                'discount' => 0,
                'total' => 0,
                'net' => 0,
                'tax' => 0,
                'gross' => 0,
                'tax_rate' => 0,
                'tax_name' => '',
                'warnings' => $r['warnings'],
                'data' => $item['data'],
                'kind' => $p !== null ? (string) $p['kind'] : '',
                'is_digital' => $p !== null && !empty($p['is_digital']),
                'weight' => $p !== null ? (int) $p['weight'] : 0,
                'tax_class_id' => $p !== null ? $p['tax_class_id'] : null,
                'on_sale' => $p !== null && !empty($p['on_sale']),
                'compare_price' => $p !== null ? $p['compare_price'] : null,
                'available' => $r['usable'],
            ];
            if ($r['usable']) {
                $classId = $p['tax_class_id'];
                $rateKey = $classId === null ? 'default' : (string) $classId;
                if (!isset($rates[$rateKey])) {
                    $rates[$rateKey] = $tax->rate($classId === null ? null : (int) $classId, $destination);
                }
                $rate = $rates[$rateKey];
                $taxLines[] = [
                    'key' => $item['key'], 'unit_price' => (int) $p['unit_price'], 'qty' => $item['qty'],
                    'tax_rate' => (int) $rate['rate'], 'tax_name' => (string) $rate['name'], 'discountable' => true,
                ];
                $lineRates[] = ['rate' => (int) $rate['rate'], 'name' => (string) $rate['name']];
                $itemsTotal += (int) $p['unit_price'] * $item['qty'];
                $payLines[] = ['product_id' => (int) $item['product_id'], 'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : 0, 'qty' => (int) $item['qty']];
                if ((string) $p['kind'] === 'physical') {
                    $needsShipping = true;
                    $weight += (int) $p['weight'] * $item['qty'];
                    $shipQty += $item['qty'];
                    $shipLines[] = ['product_id' => (int) $item['product_id'], 'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : 0, 'qty' => (int) $item['qty']];
                }
            }
            $lines[$item['key']] = $line;
        }

        // Descuentos, en orden: primero el del grupo del cliente y después los cupones.
        $discounts = [];
        $groupOffset = 0;
        if ($customer !== null && !empty($customer['group']['discount']) && (int) $customer['group']['discount'] > 0) {
            $discounts[] = ['kind' => 'percent', 'percent' => (int) $customer['group']['discount'], 'code' => '', 'label' => t('Group discount'), 'keys' => null];
            $groupOffset = 1;
        }
        $couponInfo = [];
        $codes = $this->codes($row);
        if ($codes !== [] && $app->has('coupons')) {
            $combined = $app->service('coupons')->combine($codes, $this->couponCart($resolved, $row, $customer));
            foreach ($combined['accepted'] as $accepted) {
                $couponInfo[] = ['code' => (string) $accepted['coupon']['code'], 'label' => isset($accepted['discount']['label']) ? (string) $accepted['discount']['label'] : (string) $accepted['coupon']['code']];
                $discounts[] = $accepted['discount'];
            }
            if ($combined['rejected'] !== []) {
                $kept = [];
                foreach ($couponInfo as $info) {
                    $kept[] = $info['code'];
                }
                foreach ($combined['rejected'] as $rejected) {
                    $problems[] = t('Coupon {code} was removed: {reason}', ['code' => (string) $rejected['code'], 'reason' => (string) $rejected['error']]);
                }
                $this->write(['coupons' => $this->encode($kept)]);
            }
        }

        // Envío.
        $options = [];
        $chosen = null;
        $shipping = null;
        if ($needsShipping && $app->has('shipping')) {
            $options = $app->service('shipping')->options(['weight' => $weight, 'items_total' => $itemsTotal, 'qty' => $shipQty, 'needs_shipping' => true, 'lines' => $shipLines], $destination);
            $options = array_values(array_filter($options, static function ($option): bool {
                return is_array($option) && isset($option['carrier_id'], $option['price']);
            }));
            $wanted = isset($row['carrier_id']) && $row['carrier_id'] !== null ? (int) $row['carrier_id'] : 0;
            foreach ($options as $option) {
                if ($wanted > 0 && (int) $option['carrier_id'] === $wanted) {
                    $chosen = $option;
                    break;
                }
            }
            if ($chosen === null) {
                foreach ($options as $option) {
                    if ($chosen === null || (int) $option['price'] < (int) $chosen['price']) {
                        $chosen = $option;
                    }
                }
            }
            if ($chosen === null) {
                $problems[] = t('We cannot deliver to this destination');
            } else {
                $classId = isset($chosen['tax_class_id']) && $chosen['tax_class_id'] !== null ? (int) $chosen['tax_class_id'] : null;
                $rate = $classId !== null ? $tax->rate($classId, $destination) : $tax->shippingRate($destination, $lineRates);
                $shipping = ['label' => (string) $chosen['name'], 'price' => (int) $chosen['price'], 'tax_rate' => (int) $rate['rate'], 'tax_name' => (string) $rate['name']];
            }
        }

        $input = ['prices_include_tax' => $inclusive, 'tax_exempt' => $exempt, 'lines' => $taxLines, 'discounts' => $discounts, 'shipping' => $shipping];
        $totals = Totals::calculate($input);
        foreach ($lines as $key => $line) {
            if (isset($totals['lines'][$key])) {
                $calculated = $totals['lines'][$key];
                foreach (['subtotal', 'discount', 'total', 'net', 'tax', 'gross', 'tax_rate', 'tax_name'] as $field) {
                    $lines[$key][$field] = $calculated[$field];
                }
            }
        }

        $summary = [
            'id' => (int) $row['id'],
            'count' => $count,
            'lines' => $lines,
            'totals' => $totals,
            'display_with_tax' => $tax->displayWithTax(),
            'coupons' => [],
            'destination' => $destination,
            'needs_shipping' => $needsShipping,
            'weight' => $weight,
            'shipping_options' => $options,
            'carrier_id' => $chosen !== null ? (int) $chosen['carrier_id'] : null,
            'payment_methods' => [],
            'payment_method' => null,
            'problems' => $problems,
            'currency' => $currency,
            'email' => $this->emailOf($row, $customer),
            'checkout' => $this->decodeMap(isset($row['checkout']) ? $row['checkout'] : null),
            'customer_id' => $customer !== null ? (int) $customer['id'] : null,
            'lang' => $lang,
            'tax_exempt' => $exempt,
        ];

        // Ajustes de módulos (filtro sellado): líneas con nombre y origen, nunca un precio cambiado a escondidas.
        $adjustments = $this->cleanAdjustments($app->hooks()->apply('cart.adjustments', [], $summary), $destination);
        if ($adjustments !== []) {
            try {
                $totals = Totals::calculate($input + ['adjustments' => $adjustments]);
                $input['adjustments'] = $adjustments;
            } catch (\DomainException $e) {
                // Un descuento de más no deja el carrito en negativo: ese ajuste no se aplica.
                $adjustments = [];
            }
        }

        // Formas de pago: se deciden con lo que se va a pagar y su recargo entra como una línea más.
        $context = [
            'total' => (int) $totals['total'], 'currency' => $currency, 'country' => $destination['country'],
            'needs_shipping' => $needsShipping, 'customer_id' => $customer !== null ? (int) $customer['id'] : 0, 'lang' => $lang,
            // Todas las líneas, para las formas de pago que dependen de lo que se compra (el TPV de cada vendedor).
            'lines' => $payLines,
        ];
        $methods = [];
        $selected = isset($row['payment_method']) && is_string($row['payment_method']) ? $row['payment_method'] : '';
        $payment = null;
        $feeLine = null;
        foreach ($app->service('payments')->available($context) as $method) {
            $info = $method->info();
            $fee = max(0, (int) $method->fee($context));
            $code = strtolower(trim($method->code()));
            $methods[] = [
                'code' => $code, 'title' => (string) $info['title'], 'description' => (string) $info['description'],
                'icon' => (string) $info['icon'], 'fee' => $fee,
            ];
            if ($selected !== '' && $code === $selected) {
                $payment = $code;
                if ($fee > 0) {
                    $feeRate = $tax->rate(null, $destination);
                    $feeLine = [
                        'label' => t('Payment fee: {method}', ['method' => (string) $info['title']]),
                        'amount' => $fee, 'tax_rate' => (int) $feeRate['rate'], 'tax_name' => (string) $feeRate['name'],
                    ];
                }
            }
        }
        if ($feeLine !== null) {
            $input['fees'] = [$feeLine];
            $totals = Totals::calculate($input);
        }

        $applied = [];
        foreach ($couponInfo as $i => $info) {
            $index = $groupOffset + $i;
            $applied[] = ['code' => $info['code'], 'label' => $info['label'], 'amount' => isset($totals['discounts'][$index]) ? (int) $totals['discounts'][$index]['amount'] : 0];
        }

        $summary['totals'] = $totals;
        $summary['coupons'] = $applied;
        $summary['payment_methods'] = $methods;
        $summary['payment_method'] = $payment;

        return $summary;
    }

    /**
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $customer
     */
    private function emailOf(array $row, ?array $customer): string
    {
        if (isset($row['email']) && is_string($row['email']) && $row['email'] !== '') {
            return $row['email'];
        }

        return $customer !== null && isset($customer['email']) && is_string($customer['email']) ? $customer['email'] : '';
    }

    /**
     * Los ajustes que devuelve el filtro, limpios: importe entero distinto de cero, texto y origen siempre presentes y
     * el impuesto de la clase por defecto si no dicen otro.
     *
     * @param mixed                $raw
     * @param array<string,string> $destination
     *
     * @return array<int,array<string,mixed>>
     */
    private function cleanAdjustments($raw, array $destination): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $adjustment) {
            if (!is_array($adjustment) || !isset($adjustment['amount']) || !is_numeric($adjustment['amount']) || (int) $adjustment['amount'] === 0) {
                continue;
            }
            if (isset($adjustment['tax_rate']) && is_numeric($adjustment['tax_rate'])) {
                $rate = ['rate' => (int) $adjustment['tax_rate'], 'name' => isset($adjustment['tax_name']) && is_scalar($adjustment['tax_name']) ? (string) $adjustment['tax_name'] : ''];
            } else {
                $rate = $this->app->service('tax')->rate(null, $destination);
            }
            $label = isset($adjustment['label']) && is_scalar($adjustment['label']) ? trim((string) $adjustment['label']) : '';
            $source = isset($adjustment['source']) && is_scalar($adjustment['source']) ? trim((string) $adjustment['source']) : '';
            $out[] = [
                'label' => $label !== '' ? $label : t('Price adjustment'),
                'source' => $source !== '' ? $source : 'plugin',
                'amount' => (int) $adjustment['amount'],
                'tax_rate' => (int) $rate['rate'],
                'tax_name' => (string) $rate['name'],
            ];
        }

        return $out;
    }
}
