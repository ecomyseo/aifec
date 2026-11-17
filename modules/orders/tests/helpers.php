<?php
/**
 * AIFEC — ayudas de prueba del carrito y de los pedidos. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   aifecCartApp(array $opciones = []): App
 *       Aplicación con el módulo «orders» (y todo lo que necesita: catálogo, impuestos, envíos, cupones y clientes),
 *       arrancada, con precios CON impuestos dentro, un transportista y una forma de pago de mentira registrada.
 *       Opciones:
 *         settings   ajustes iniciales (se funden con los de serie)
 *         prices_include_tax   true (por defecto) o false: ajuste tax.prices_include_tax
 *         carrier    true (por defecto) crea el transportista «std» con tarifa por peso en las cuatro zonas de serie
 *                    (hasta 1000 g: 4,95 €; hasta 5000 g: 7,95 €; más: 12,95 €); false no crea ninguno
 *         payment    true (por defecto) registra aifecFakePayment(); false no registra ninguna; o un código de texto
 *         modules    módulos que se cargan además de «orders» (['mail', 'media', 'jobs'])
 *       Los productos se crean con aifecProduct() del catálogo (ya cargado): precio en céntimos, tax_class_id
 *       («standard» 21 %, «reduced» 10 %), weight en gramos, stock, min_qty, max_qty, kind…
 *
 *   aifecFakePayment(string $code = 'fake', int $fee = 0): PaymentMethod
 *       Forma de pago de mentira. Recargo $fee (céntimos). Propiedades públicas para retocarla: $countries (lista de
 *       países donde se ofrece; null = todos), $minTotal (importe mínimo para ofrecerla) y $title.
 *
 *   aifecCartRun(TestClient $web, callable $fn)
 *       Ejecuta $fn(Cart $cart, App $app) dentro de UNA petición del navegador de mentira $web (con sus cookies), y
 *       devuelve lo que devuelva. Es la forma de probar el servicio «cart» con la cookie que de verdad lleva el cliente.
 *
 *   aifecCartCustomer(App $app, string $email = 'ana@cliente.test', array $datos = []): int
 *       Da de alta un cliente con cuenta (contraseña «clave-larga-1234») y devuelve su id. $datos: group_id, vat_id…
 *
 *   aifecCartGroup(App $app, int $descuento, bool $exento = false): int
 *       Grupo de clientes con ese descuento en centésimas de punto (500 = 5 %); devuelve su id.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../catalog/tests/helpers.php';
require_once __DIR__ . '/../../shipping/tests/ShippingKit.php';
require_once __DIR__ . '/../../coupons/tests/CouponsKit.php';

if (!class_exists('AifecFakePayment')) {
    /** Forma de pago de mentira para las pruebas del carrito y de la caja. */
    final class AifecFakePayment implements PaymentMethod
    {
        /** @var string */
        public $code;

        /** @var int */
        public $fee;

        /** @var string */
        public $title;

        /** @var array<int,string>|null */
        public $countries;

        /** @var int */
        public $minTotal = 0;

        public function __construct(string $code, int $fee)
        {
            $this->code = $code;
            $this->fee = $fee;
            $this->title = 'Pago de mentira ' . $code;
        }

        public function code(): string
        {
            return $this->code;
        }

        public function info(): array
        {
            return ['title' => $this->title, 'description' => 'Un pago que no cobra nada', 'icon' => ''];
        }

        public function available(array $context): bool
        {
            if ($this->countries !== null && !in_array(isset($context['country']) ? $context['country'] : '', $this->countries, true)) {
                return false;
            }

            return $this->minTotal <= 0 || (isset($context['total']) ? (int) $context['total'] : 0) >= $this->minTotal;
        }

        public function fee(array $context): int
        {
            return $this->fee;
        }

        public function isTest(): bool
        {
            return true;
        }

        public function start(array $order): PaymentAction
        {
            return PaymentAction::instructions('<p>Pago de mentira</p>');
        }

        public function supportsRefund(): bool
        {
            return false;
        }

        public function refund(array $order, int $amount, string $reason): string
        {
            throw new \RuntimeException('The fake payment does not refund');
        }
    }
}

if (!function_exists('aifecCartApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function aifecCartApp(array $opciones = []): App
    {
        $settings = isset($opciones['settings']) && is_array($opciones['settings']) ? $opciones['settings'] : [];
        $settings += ['tax.prices_include_tax' => !array_key_exists('prices_include_tax', $opciones) || !empty($opciones['prices_include_tax'])];
        $extraModules = isset($opciones['modules']) && is_array($opciones['modules']) ? array_values($opciones['modules']) : [];
        $app = testApp(['modules' => array_merge(['orders'], $extraModules), 'settings' => $settings]);
        $app->boot();

        if (!array_key_exists('carrier', $opciones) || !empty($opciones['carrier'])) {
            $carrier = shippingAddCarrier($app, 'std', ['name' => 'Estándar', 'delay' => '24-48 h']);
            foreach (['Peninsula', 'Balearic Islands', 'Canary Islands', 'Ceuta and Melilla'] as $zone) {
                $zoneId = shippingZoneId($app, $zone);
                shippingAddRate($app, $carrier, $zoneId, 'weight', 0, 1000, 495);
                shippingAddRate($app, $carrier, $zoneId, 'weight', 1001, 5000, 795);
                shippingAddRate($app, $carrier, $zoneId, 'weight', 5001, 0, 1295);
            }
        }
        $payment = array_key_exists('payment', $opciones) ? $opciones['payment'] : true;
        if ($payment !== false) {
            $app->service('payments')->register(aifecFakePayment(is_string($payment) ? $payment : 'fake'));
        }

        return $app;
    }

    function aifecFakePayment(string $code = 'fake', int $fee = 0): PaymentMethod
    {
        return new AifecFakePayment($code, $fee);
    }

    /**
     * @return mixed lo que devuelve $fn
     */
    function aifecCartRun(TestClient $web, callable $fn)
    {
        static $n = 0;
        ++$n;
        $out = null;
        $web->app->router()->get('/_aifec-cart-' . $n, static function (Request $request, App $app) use ($fn, &$out): Response {
            $out = $fn($app->service('cart'), $app);

            return Response::text('ok');
        });
        $web->get('/_aifec-cart-' . $n);

        return $out;
    }

    /**
     * @param array<string,mixed> $datos
     */
    function aifecCartCustomer(App $app, string $email = 'ana@cliente.test', array $datos = []): int
    {
        $now = $app->clock()->sql();

        return $app->db()->insert('customers', $datos + [
            'email' => $email, 'password' => \Aifec\Security\Passwords::hash('clave-larga-1234'), 'guest' => 0, 'first_name' => 'Ana',
            'last_name' => 'Ruiz', 'group_id' => 1, 'locale' => 'es', 'active' => 1, 'marketing' => 0, 'orders_count' => 0,
            'total_spent' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    function aifecCartGroup(App $app, int $descuento, bool $exento = false): int
    {
        return $app->db()->insert('customer_groups', ['name' => 'Grupo ' . $descuento, 'discount' => $descuento, 'tax_exempt' => $exento ? 1 : 0, 'is_default' => 0]);
    }
}

// ------------------------------------------------------------------ pedidos
//
//   aifecOrderApp(array $opciones = []): App
//       Como aifecCartApp(), pero con los módulos «mail», «media» y «jobs» cargados y con una forma de pago
//       configurable de código «gw» (aifecOrderPayment) en vez de la de mentira. El ajuste store.email vale
//       tienda@ejemplo.test. Acepta las mismas opciones que aifecCartApp().
//
//   aifecOrderPayment(App $app, string $code = 'gw'): AifecOrderPayment
//       La forma de pago de pruebas ya registrada. Propiedades públicas para retocarla: $action (PaymentAction que
//       devuelve start(); por defecto una redirección), $throwOnStart (start() lanza), $canRefund, $refundThrows,
//       $started (los pedidos que ha recibido start()), $refunds (lo que ha devuelto: [importe, motivo]), $test y $fee.
//
//   aifecOrderCheckout(array $cambios = []): array
//       Los datos de la caja de un cliente de Madrid con todo correcto. $cambios se funde encima.
//
//   aifecOrderPlace(App $app, TestClient $web, array $lineas, array $caja = [], array $opciones = []): array
//       En UNA petición: añade las líneas ([[producto, variante, unidades], …]), elige la forma de pago «gw» y llama a
//       Orders::place(). Devuelve lo que devuelve place().
//
//   aifecOrderFile(App $app, int $productoId, string $nombre = 'guia.txt', ?int $varianteId = null): int
//       Fichero privado de verdad (en la carpeta privada del sitio) ligado al producto; devuelve el id de product_files.
//
//   aifecOrderMails(App $app, string $plantilla = ''): array
//       Las filas de mail_messages (de esa plantilla, si se dice), de la más antigua a la más nueva.
//
//   aifecOrderCount(App $app, string $tabla, string $donde = '1 = 1', array $params = []): int
//       Cuenta filas.
//
//   aifecOrderStock(App $app, int $productoId): int
//       Las existencias de un producto sin variantes.

if (!class_exists('AifecOrderPayment')) {
    /** Forma de pago de pruebas con todo configurable: lo que devuelve start(), si lanza y si devuelve dinero. */
    final class AifecOrderPayment implements PaymentMethod
    {
        /** @var string */
        public $code;

        /** @var PaymentAction|null */
        public $action;

        /** @var bool */
        public $throwOnStart = false;

        /** @var bool */
        public $canRefund = true;

        /** @var bool */
        public $refundThrows = false;

        /** @var bool */
        public $test = true;

        /** @var int recargo de la forma de pago, en céntimos */
        public $fee = 0;

        /** @var array<int,array<string,mixed>> */
        public $started = [];

        /** @var array<int,array{0:int,1:string}> */
        public $refunds = [];

        public function __construct(string $code)
        {
            $this->code = $code;
        }

        public function code(): string
        {
            return $this->code;
        }

        public function info(): array
        {
            return ['title' => 'Pasarela de pruebas', 'description' => 'Una pasarela de mentira', 'icon' => ''];
        }

        public function available(array $context): bool
        {
            return true;
        }

        public function fee(array $context): int
        {
            return $this->fee;
        }

        public function isTest(): bool
        {
            return $this->test;
        }

        public function start(array $order): PaymentAction
        {
            $this->started[] = $order;
            if ($this->throwOnStart) {
                throw new \RuntimeException('The gateway is not reachable');
            }

            return $this->action !== null ? $this->action : PaymentAction::redirect('https://pasarela.test/pagar/' . (string) $order['number']);
        }

        public function supportsRefund(): bool
        {
            return $this->canRefund;
        }

        public function refund(array $order, int $amount, string $reason): string
        {
            if ($this->refundThrows) {
                throw new \RuntimeException('The gateway refused the refund');
            }
            $this->refunds[] = [$amount, $reason];

            return 'RF-' . count($this->refunds);
        }
    }
}

if (!function_exists('aifecOrderApp')) {
    /**
     * @param array<string,mixed> $opciones
     */
    function aifecOrderApp(array $opciones = []): App
    {
        $settings = isset($opciones['settings']) && is_array($opciones['settings']) ? $opciones['settings'] : [];
        $opciones['settings'] = $settings + ['store.email' => 'tienda@ejemplo.test'];
        $opciones['modules'] = array_merge(['mail', 'media', 'jobs'], isset($opciones['modules']) && is_array($opciones['modules']) ? $opciones['modules'] : []);
        $conPago = !array_key_exists('payment', $opciones) || $opciones['payment'] !== false;
        $opciones['payment'] = false;
        $app = aifecCartApp($opciones);
        if ($conPago) {
            $app->service('payments')->register(new AifecOrderPayment('gw'));
        }

        return $app;
    }

    function aifecOrderPayment(App $app, string $code = 'gw'): AifecOrderPayment
    {
        $method = $app->service('payments')->get($code);
        check($method instanceof AifecOrderPayment, 'la forma de pago ' . $code . ' es la de pruebas');

        return $method;
    }

    /**
     * @param array<string,mixed> $cambios
     *
     * @return array<string,mixed>
     */
    function aifecOrderCheckout(array $cambios = []): array
    {
        $address = ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'region' => '28', 'country' => 'ES', 'phone' => '999345678'];

        return $cambios + ['email' => 'ana@cliente.test', 'billing' => $address, 'shipping' => null, 'note' => '', 'accept_terms' => true, 'marketing' => false];
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $caja
     * @param array<string,mixed>       $opciones
     *
     * @return array<string,mixed>
     */
    function aifecOrderPlace(App $app, TestClient $web, array $lineas, array $caja = [], array $opciones = []): array
    {
        return aifecCartRun($web, static function ($cart, App $app) use ($lineas, $caja, $opciones): array {
            foreach ($lineas as $linea) {
                $added = $cart->add((int) $linea[0], isset($linea[1]) ? (int) $linea[1] : 0, isset($linea[2]) ? (int) $linea[2] : 1);
                check(!empty($added['ok']), 'la línea se añade al carrito: ' . (isset($added['error']) ? (string) $added['error'] : ''));
            }
            $cart->setPaymentMethod('gw');

            return $app->service('orders')->place(aifecOrderCheckout($caja), $opciones);
        });
    }

    function aifecOrderFile(App $app, int $productoId, string $nombre = 'guia.txt', ?int $varianteId = null): int
    {
        $tmp = tmpDir('aifec-file') . '/' . $nombre;
        file_put_contents($tmp, 'Contenido de ' . $nombre);
        $stored = $app->service('files')->store($tmp, $nombre, 'pedidos');

        return $app->db()->insert('product_files', [
            'product_id' => $productoId, 'variant_id' => $varianteId, 'name' => $stored['name'], 'path' => $stored['path'],
            'bytes' => $stored['bytes'], 'position' => 0, 'created_at' => $app->clock()->sql(),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function aifecOrderMails(App $app, string $plantilla = ''): array
    {
        if ($plantilla === '') {
            return $app->db()->select('SELECT * FROM {mail_messages} ORDER BY id');
        }

        return $app->db()->select('SELECT * FROM {mail_messages} WHERE template = ? ORDER BY id', [$plantilla]);
    }

    /**
     * @param array<int,mixed> $params
     */
    function aifecOrderCount(App $app, string $tabla, string $donde = '1 = 1', array $params = []): int
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {' . $tabla . '} WHERE ' . $donde, $params);
    }

    function aifecOrderStock(App $app, int $productoId): int
    {
        return (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$productoId]);
    }

    /**
     * Cuántas filas hay en todo lo que escribe un pedido, para comprobar que una validación fallida no escribe nada.
     *
     * @return array<string,int>
     */
    function aifecOrderSnapshot(App $app): array
    {
        $out = [];
        foreach (['orders', 'order_items', 'order_history', 'order_payments', 'coupon_uses', 'customers', 'consents', 'stock_moves', 'mail_messages'] as $table) {
            $out[$table] = aifecOrderCount($app, $table);
        }
        $out['sequence'] = (int) $app->db()->value("SELECT value FROM {sequences} WHERE name = 'order'");
        $out['stock'] = (int) $app->db()->value('SELECT SUM(stock) FROM {products}');
        $out['coupon_uses_count'] = (int) $app->db()->value('SELECT SUM(uses) FROM {coupons}');

        return $out;
    }

    /**
     * Crea un pedido y lo da por pagado (la pasarela avisa con el importe justo). Devuelve el id del pedido.
     *
     * @param array<int,array<int,int>> $lineas
     * @param array<string,mixed>       $caja
     */
    function aifecOrderPaid(App $app, TestClient $web, array $lineas, array $caja = []): int
    {
        static $n = 0;
        ++$n;
        $placed = aifecOrderPlace($app, $web, $lineas, $caja);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
        $order = $app->service('orders')->find((int) $placed['id']);
        check($order !== null, 'el pedido existe');
        $ok = $app->service('orders')->markPaid((int) $placed['id'], 'gw', 'PAY-' . $n, (int) $order['total']);
        check($ok, 'el cobro se apunta');

        return (int) $placed['id'];
    }
}
