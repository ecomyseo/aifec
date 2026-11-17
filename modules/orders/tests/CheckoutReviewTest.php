<?php
/**
 * AIFEC — pruebas de revisar y confirmar con el motor de la caja: el pedido exacto que se haría, la ficha de confirmación
 * (que deja de valer si cambia algo), hacer el pedido una sola vez, lo que sigue a cada forma de pago y que el pedido que
 * sale es idéntico al de la caja de siempre.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Cart;
use Aifec\Modules\Orders\Front\CheckoutState;

require_once __DIR__ . '/FrontKit.php';

/** Una tienda con un producto y dos unidades en el carrito. @return array{0:App,1:TestClient,2:int} */
function ckRevShop(array $settings = []): array
{
    $app = frontShop(['settings' => $settings]);
    $web = new TestClient($app);
    $p = frontProduct($app);
    frontAdd($web, $p, 2);

    return [$app, $web, $p];
}

/** Ejecuta $fn($motor, $app) dentro de una petición del navegador de mentira. @return mixed */
function ckRevRun(TestClient $web, callable $fn)
{
    return aifecCartRun($web, static function ($cart, App $app) use ($fn) {
        return $fn($app->service('checkout'), $app);
    });
}

/**
 * @param array<string,string> $extra
 *
 * @return array<string,string>
 */
function ckRevFields(array $extra = []): array
{
    return $extra + [
        'email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '999345678',
        'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'country' => 'ES',
    ];
}

/** Rellena la caja entera y elige «gw»: lo que queda para poder revisar y confirmar. */
function ckRevFill(TestClient $web): array
{
    return ckRevRun($web, static function ($co): array {
        $co->fill(ckRevFields());
        $co->setPayment('gw');

        return $co->review();
    });
}

/**
 * Del pedido, lo que tiene que ser igual en dos pedidos iguales: sin identificadores, número, ficha ni fechas.
 *
 * @param array<string,mixed> $order
 *
 * @return array<string,mixed>
 */
function ckRevShape(array $order): array
{
    $keep = ['customer_id', 'email', 'lang', 'currency', 'status', 'payment_status', 'fulfillment_status', 'payment_method', 'payment_title', 'carrier_id',
        'carrier_title', 'prices_include_tax', 'items_total', 'discount_total', 'shipping_total', 'fee_total', 'total_net', 'total_tax', 'total', 'paid_total',
        'refunded_total', 'taxes', 'adjustments', 'billing', 'shipping', 'coupons', 'customer_note', 'source', 'attribution', 'test', 'ip'];
    $shape = [];
    foreach ($keep as $key) {
        $shape[$key] = array_key_exists($key, $order) ? $order[$key] : '«falta»';
    }
    $shape['items'] = [];
    foreach (is_array($order['items']) ? $order['items'] : [] as $item) {
        unset($item['id'], $item['order_id'], $item['created_at'], $item['updated_at']);
        $shape['items'][] = $item;
    }
    $shape['history'] = count(is_array($order['history']) ? $order['history'] : []);

    return $shape;
}

test('review() da el mismo total, al céntimo, que el carrito, con cupón, dos tipos de IVA y envío', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $a = frontProduct($app);
    $b = aifecProduct($app, ['name' => 'Taza', 'price' => 1100, 'tax_class_id' => 'reduced', 'weight' => 300, 'stock' => 10]);
    couponsMake($app);
    frontAdd($web, $a, 2);
    frontAdd($web, $b, 1);

    $res = ckRevRun($web, static function ($co, App $app): array {
        $app->service('cart')->applyCoupon('otono');
        $co->fill(ckRevFields());
        $co->setPayment('gw');

        return ['review' => $co->review(), 'summary' => $app->service('cart')->summary()];
    });
    $r = $res['review'];
    $s = $res['summary'];
    same($s['totals']['total'], $r['total'], 'el mismo total que el carrito');
    same($app->i18n()->money($s['totals']['total'], 'EUR'), $r['total_formatted']);
    same('EUR', $r['currency']);
    same(true, $r['prices_include_tax']);
    same(2, count($r['lines']));
    same(495, $r['shipping']['price']);
    same('Estándar', $r['shipping']['name']);
    same(1, count($r['discounts']));
    same($s['coupons'][0]['amount'], $r['discounts'][0]['amount']);
    check($r['discounts'][0]['amount'] > 0, 'el cupón descuenta algo');
    same(2, count($r['taxes']), 'un renglón por tipo de IVA');
    $taxSum = 0;
    foreach ($r['taxes'] as $tax) {
        $taxSum += $tax['amount'];
    }
    same($s['totals']['total_tax'], $taxSum, 'los impuestos por tipo suman el IVA del carrito');

    // La cuenta se puede hacer con lo que enseña: líneas (ya con su descuento) + envío + recargos = total.
    $sum = $r['shipping']['price'];
    foreach ($r['lines'] as $line) {
        $sum += $line['total'];
    }
    foreach ($r['fees'] as $fee) {
        $sum += $fee['amount'];
    }
    same($r['total'], $sum, 'líneas + envío + recargos = total');
    same(true, $r['ready']);
    same([], $r['missing']);
    same('ana@cliente.test', $r['email']);
    same('Calle Mayor 1', $r['address']['line1']);
    same('gw', $r['payment']['id']);
    same('Pasarela de pruebas', $r['payment']['name']);
    check(preg_match('/^[a-f0-9]{64}$/D', $r['confirm_token']) === 1, 'la ficha es un HMAC en hexadecimal');
});

test('review() con el recargo de la forma de pago lo suma una vez y la cuenta sigue cuadrando', function (): void {
    [$app, $web] = ckRevShop();
    aifecOrderPayment($app)->fee = 300;
    $r = ckRevFill($web);
    same(1, count($r['fees']));
    same(300, $r['fees'][0]['amount']);
    $sum = $r['shipping']['price'] + $r['fees'][0]['amount'];
    foreach ($r['lines'] as $line) {
        $sum += $line['total'];
    }
    same($r['total'], $sum);
    same(24200 + 495 + 300, $r['total']);
});

test('review() sin estar listo no da ficha de confirmación y dice qué impide hacer el pedido', function (): void {
    [$app, $web] = ckRevShop();
    $r = ckRevRun($web, static function ($co): array {
        return $co->review();
    });
    same(false, $r['ready']);
    same('', $r['confirm_token']);
    same(['contact', 'address', 'payment'], $r['missing']);
    foreach (['email', 'shipping.first_name', 'shipping.line1', 'shipping.postcode', 'shipping.city', 'payment'] as $key) {
        check(isset($r['errors'][$key]), 'falta «' . $key . '»');
    }
    same(null, $r['payment']);
});

test('place() con una ficha que no es la de ahora, vacía o inventada no crea ningún pedido', function (): void {
    [$app, $web] = ckRevShop();
    ckRevFill($web);
    foreach (['', 'x', str_repeat('a', 64), 'ficha-de-mentira'] as $token) {
        $res = ckRevRun($web, static function ($co) use ($token): array {
            return $co->place($token, ['accept_terms' => true]);
        });
        same(false, $res['ok'], 'la ficha «' . $token . '» no vale');
        same('confirm_token', $res['field']);
        contains('Vuelve a revisarlo', $res['error']);
    }
    same(0, aifecOrderCount($app, 'orders'));
    same(10, aifecOrderStock($app, 1), 'no se tocó ninguna existencia');
});

test('la ficha deja de valer si cambia una cantidad, la dirección, el transportista, la forma de pago o un cupón', function (): void {
    $mutations = [
        'una cantidad' => static function ($co, App $app): void {
            $app->service('cart')->update(Cart::lineKey(1, 0), 3);
        },
        'la dirección (aunque el total no cambie)' => static function ($co): void {
            $co->fill(['city' => 'Getafe']);
        },
        'el transportista' => static function ($co, App $app): void {
            $co->setShipping((int) $app->db()->value("SELECT id FROM {carriers} WHERE code = 'fast'"));
        },
        'la forma de pago' => static function ($co): void {
            $co->setPayment('transfer');
        },
        'un cupón' => static function ($co, App $app): void {
            $app->service('cart')->applyCoupon('otono');
        },
    ];
    foreach ($mutations as $what => $mutate) {
        [$app, $web] = ckRevShop();
        $fast = shippingAddCarrier($app, 'fast', ['name' => 'Urgente', 'delay' => '24 h']);
        foreach (['Peninsula' => 1295, 'Balearic Islands' => 1995, 'Canary Islands' => 2995, 'Ceuta and Melilla' => 2495] as $zone => $price) {
            shippingAddRate($app, $fast, shippingZoneId($app, $zone), 'weight', 0, 0, $price);
        }
        $app->service('payments')->register(new AifecOrderPayment('transfer'));
        couponsMake($app);
        $review = ckRevFill($web);
        check($review['confirm_token'] !== '', 'hay ficha antes de cambiar ' . $what);

        $after = ckRevRun($web, static function ($co, App $app) use ($mutate, $review): array {
            $mutate($co, $app);
            $new = $co->review();

            return ['review' => $new, 'place' => $co->place($review['confirm_token'], ['accept_terms' => true])];
        });
        check($after['review']['confirm_token'] !== $review['confirm_token'], 'la ficha cambia al cambiar ' . $what);
        same(false, $after['place']['ok'], 'la ficha vieja no vale tras cambiar ' . $what);
        same('confirm_token', $after['place']['field'], $what);
        same(0, aifecOrderCount($app, 'orders'), 'ningún pedido tras cambiar ' . $what);
        same(10, aifecOrderStock($app, 1), 'ninguna existencia tocada tras cambiar ' . $what);
    }
});

test('la ficha es la misma mientras no cambia nada, y revisar no toca el carrito', function (): void {
    [$app, $web] = ckRevShop();
    $first = ckRevFill($web);
    $before = $app->db()->row('SELECT checkout, destination, carrier_id, payment_method, coupons FROM {carts} WHERE id = 1');
    $second = ckRevRun($web, static function ($co): array {
        return $co->review();
    });
    same($first['confirm_token'], $second['confirm_token'], 'revisar dos veces da la misma ficha');
    same($before, $app->db()->row('SELECT checkout, destination, carrier_id, payment_method, coupons FROM {carts} WHERE id = 1'), 'revisar no escribe nada');
});

test('place() con la ficha buena hace el pedido, enseña a dónde ir y deja el carrito convertido', function (): void {
    [$app, $web, $p] = ckRevShop();
    $review = ckRevFill($web);
    $res = ckRevRun($web, static function ($co) use ($review): array {
        return $co->place($review['confirm_token'], ['accept_terms' => true, 'ip' => '203.0.113.9']);
    });
    same(true, $res['ok'], (string) $res['error']);
    same('', $res['error']);
    same(1, $res['id']);
    check(preg_match('#/pedido/' . preg_quote($res['number'], '#') . '/[a-f0-9]{48}$#', $res['order_url']) === 1, 'el enlace del pedido: ' . $res['order_url']);
    same('redirect', $res['payment']['type']);
    same('https://pasarela.test/pagar/' . $res['number'], $res['payment']['url']);
    same('get', $res['payment']['method']);

    $order = $app->service('orders')->findBySecret($res['number'], $res['secret']);
    check($order !== null, 'el pedido existe');
    same($review['total'], $order['total'], 'el pedido cobra lo que decía la revisión');
    same('pending_payment', $order['status']);
    same('203.0.113.9', $order['ip']);
    same(8, aifecOrderStock($app, $p));

    $after = ckRevRun($web, static function ($co): array {
        return $co->review();
    });
    same(false, $after['ready'], 'el carrito ya es un pedido');
    same('', $after['confirm_token']);
});

test('dos place() con la misma ficha hacen un solo pedido', function (): void {
    [$app, $web, $p] = ckRevShop();
    $review = ckRevFill($web);
    $both = ckRevRun($web, static function ($co) use ($review): array {
        return [$co->place($review['confirm_token'], ['accept_terms' => true]), $co->place($review['confirm_token'], ['accept_terms' => true])];
    });
    same(true, $both[0]['ok']);
    same(false, $both[1]['ok']);
    same('cart', $both[1]['field']);
    same(1, aifecOrderCount($app, 'orders'));
    same(8, aifecOrderStock($app, $p), 'las existencias se descontaron una vez');

    // Y desde otra petición, igual.
    $again = ckRevRun($web, static function ($co) use ($review): array {
        return $co->place($review['confirm_token'], ['accept_terms' => true]);
    });
    same(false, $again['ok']);
    same(1, aifecOrderCount($app, 'orders'));
});

test('place() sin aceptar las condiciones no hace el pedido y avisa de la casilla', function (): void {
    [$app, $web] = ckRevShop();
    $review = ckRevFill($web);
    $res = ckRevRun($web, static function ($co) use ($review): array {
        return $co->place($review['confirm_token']);
    });
    same(false, $res['ok']);
    same('accept_terms', $res['field']);
    contains('Tienes que aceptar los términos y condiciones', $res['error']);
    same(0, aifecOrderCount($app, 'orders'));
    $ok = ckRevRun($web, static function ($co) use ($review): array {
        return $co->place($review['confirm_token'], ['accept_terms' => true]);
    });
    same(true, $ok['ok'], 'aceptadas, sigue valiendo la misma ficha');
});

test('place() si falta algo dice qué y con qué clave de campo, sin tocar nada', function (): void {
    [$app, $web] = ckRevShop();
    $res = ckRevRun($web, static function ($co): array {
        $co->fill(['first_name' => 'Ana']);

        return $co->place('lo-que-sea', ['accept_terms' => true]);
    });
    same(false, $res['ok']);
    same('email', $res['field'], 'lo primero que falta es el contacto');
    foreach (['email', 'shipping.line1', 'shipping.postcode', 'shipping.city', 'payment'] as $key) {
        check(isset($res['errors'][$key]), 'falta «' . $key . '»');
    }
    same(0, aifecOrderCount($app, 'orders'));

    $empty = ckRevRun(new TestClient($app), static function ($co): array {
        return $co->place('x');
    });
    same(false, $empty['ok']);
    same('cart', $empty['field']);
    same('Tu carrito está vacío', $empty['error']);
});

test('una empresa sin NIF impide el pedido, y con el NIF puesto la revisión da ficha', function (): void {
    [$app, $web] = ckRevShop();
    $r = ckRevRun($web, static function ($co): array {
        $co->fill(ckRevFields(['company' => 'Muebles Roble SL']));
        $co->setPayment('gw');

        return $co->review();
    });
    same(false, $r['ready']);
    check(isset($r['errors']['shipping.vat_id']), 'el NIF de la empresa');
    same('', $r['confirm_token']);

    $ok = ckRevRun($web, static function ($co): array {
        $co->fill(['vat_id' => 'B12345678']);

        return $co->review();
    });
    same(true, $ok['ready']);
    $placed = ckRevRun($web, static function ($co) use ($ok): array {
        return $co->place($ok['confirm_token'], ['accept_terms' => true]);
    });
    same(true, $placed['ok']);
    same('B12345678', $app->service('orders')->find(1)['billing']['vat_id']);
});

test('con la factura a otros datos, revisar enseña los dos y la ficha cubre los dos', function (): void {
    [$app, $web] = ckRevShop();
    ckRevFill($web);
    $r = ckRevRun($web, static function ($co): array {
        $co->fill(['billing' => ['first_name' => 'Muebles', 'last_name' => 'Roble SL', 'line1' => 'Calle Fábrica 4', 'postcode' => '08001', 'city' => 'Barcelona', 'country' => 'ES', 'company' => 'Muebles Roble SL', 'vat_id' => 'B12345678']]);

        return $co->review();
    });
    same(true, $r['ready']);
    same('Calle Mayor 1', $r['address']['line1']);
    same('Calle Fábrica 4', $r['billing']['line1']);
    $res = ckRevRun($web, static function ($co) use ($r): array {
        return $co->place($r['confirm_token'], ['accept_terms' => true]);
    });
    same(true, $res['ok']);
    $order = $app->service('orders')->find(1);
    same('Calle Fábrica 4', $order['billing']['line1']);
    same('Calle Mayor 1', $order['shipping']['line1']);
});

test('lo que sigue a cada forma de pago: instrucciones, pagado, formulario a la pasarela o redirección', function (): void {
    $cases = [
        'instrucciones' => [PaymentAction::instructions('<p>Paga por transferencia al ES00</p>'), 'instructions', 'on_hold'],
        'pagado' => [PaymentAction::paid('PAY-9'), 'none', 'processing'],
        'formulario' => [PaymentAction::form('https://pasarela.test/pagar', ['Ds_Merchant' => 'a"b<c>', 'token' => 'x&y']), 'redirect', 'pending_payment'],
        'redirección' => [PaymentAction::redirect('https://pasarela.test/ir'), 'redirect', 'pending_payment'],
    ];
    foreach ($cases as $name => $case) {
        [$app, $web] = ckRevShop();
        aifecOrderPayment($app)->action = $case[0];
        $review = ckRevFill($web);
        $res = ckRevRun($web, static function ($co) use ($review): array {
            return $co->place($review['confirm_token'], ['accept_terms' => true]);
        });
        same(true, $res['ok'], $name);
        same($case[1], $res['payment']['type'], $name);
        same($case[2], $app->service('orders')->find(1)['status'], $name);
        if ($name === 'instrucciones') {
            contains('Paga por transferencia', $res['payment']['text']);
        }
        if ($name === 'formulario') {
            same('post', $res['payment']['method']);
            same(['Ds_Merchant' => 'a"b<c>', 'token' => 'x&y'], $res['payment']['fields']);
            same('https://pasarela.test/pagar', $res['payment']['url']);
        }
        if ($name === 'redirección') {
            same('https://pasarela.test/ir', $res['payment']['url']);
        }
    }
});

test('si el cobro no puede empezar: error en el campo de pago, pedido fallido, existencias devueltas y carrito libre', function (): void {
    [$app, $web, $p] = ckRevShop();
    aifecOrderPayment($app)->throwOnStart = true;
    $review = ckRevFill($web);
    $res = ckRevRun($web, static function ($co) use ($review): array {
        return $co->place($review['confirm_token'], ['accept_terms' => true]);
    });
    same(false, $res['ok']);
    same('payment', $res['field']);
    same(true, $res['reopened']);
    contains('No se ha podido empezar el pago', $res['error']);
    same('failed', $app->service('orders')->find(1)['status']);
    same(10, aifecOrderStock($app, $p), 'las existencias vuelven');

    aifecOrderPayment($app)->throwOnStart = false;
    $again = ckRevRun($web, static function ($co): array {
        return $co->review();
    });
    same(true, $again['ready'], 'el carrito vuelve a estar libre, con todo lo escrito');
    same(24200 + 495, $again['total']);
});

test('el origen del pedido, la atribución y la nota llegan al pedido; un origen desconocido es «web»', function (): void {
    [$app, $web] = ckRevShop();
    $review = ckRevFill($web);
    $res = ckRevRun($web, static function ($co) use ($review): array {
        return $co->place($review['confirm_token'], [
            'accept_terms' => true, 'source' => 'assistant', 'ip' => '203.0.113.7', 'attribution' => ['utm_source' => 'asistente'], 'note' => 'Sin prisa & con cariño',
        ]);
    });
    same(true, $res['ok']);
    $order = $app->service('orders')->find(1);
    same('assistant', $order['source']);
    same('203.0.113.7', $order['ip']);
    same(['utm_source' => 'asistente'], $order['attribution']);
    same('Sin prisa & con cariño', $order['customer_note']);

    [$app2, $web2] = ckRevShop();
    $review2 = ckRevFill($web2);
    ckRevRun($web2, static function ($co) use ($review2): array {
        return $co->place($review2['confirm_token'], ['accept_terms' => true, 'source' => 'inventado']);
    });
    same('web', $app2->service('orders')->find(1)['source']);
});

test('con los invitados apagados, una compra sin cuenta no se hace aunque la ficha sea buena', function (): void {
    [$app, $web] = ckRevShop(['orders.guest_checkout' => false]);
    $review = ckRevFill($web);
    $res = ckRevRun($web, static function ($co) use ($review): array {
        return $co->place($review['confirm_token'], ['accept_terms' => true]);
    });
    same(false, $res['ok']);
    same('email', $res['field']);
    same(0, aifecOrderCount($app, 'orders'));
});

test('el pedido que hace el motor es idéntico al que hacía la caja anterior, campo a campo', function (): void {
    $app = frontShop();
    $a = frontProduct($app);
    $b = aifecProduct($app, ['name' => 'Taza', 'price' => 1100, 'tax_class_id' => 'reduced', 'weight' => 300, 'stock' => 10]);
    couponsMake($app);
    $webOld = new TestClient($app);
    $webNew = new TestClient($app);
    foreach ([$webOld, $webNew] as $web) {
        frontAdd($web, $a, 2);
        frontAdd($web, $b, 1);
    }
    aifecOrderPayment($app)->fee = 300;

    // La caja de antes: guardar el destino y la forma de pago y llamar a Orders::place() con lo que arma CheckoutState.
    $old = aifecCartRun($webOld, static function (Cart $cart, App $app): array {
        $cart->applyCoupon('otono');
        $state = [
            'email' => 'ana@cliente.test', 'address_id' => 0, 'shipping' => CheckoutState::address(['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'country' => 'ES', 'phone' => '999345678']),
            'billing_other' => false, 'billing' => CheckoutState::address([]), 'carrier_id' => 0, 'payment_method' => 'gw', 'note' => 'Llamar antes', 'accept_terms' => true, 'marketing' => true,
        ];
        $cart->setDestination(['country' => 'ES', 'region' => '28', 'postcode' => '28001', 'city' => 'Madrid']);
        $cart->setPaymentMethod('gw');

        return $app->service('orders')->place(CheckoutState::forPlace($state, null, [], true), ['source' => 'web', 'ip' => '203.0.113.9']);
    });
    same(true, $old['ok']);
    $mailsAfterOld = count(aifecOrderMails($app));

    // La caja de ahora: el motor.
    $new = aifecCartRun($webNew, static function (Cart $cart, App $app): array {
        $cart->applyCoupon('otono');
        $co = $app->service('checkout');
        $co->fill(ckRevFields(['note' => 'Llamar antes']));
        $co->setPayment('gw');
        $review = $co->review();

        return $co->place($review['confirm_token'], ['accept_terms' => true, 'marketing' => true, 'ip' => '203.0.113.9']);
    });
    same(true, $new['ok'], (string) $new['error']);

    $orderOld = $app->service('orders')->find((int) $old['id']);
    $orderNew = $app->service('orders')->find((int) $new['id']);
    same(ckRevShape($orderOld), ckRevShape($orderNew), 'el mismo pedido, campo a campo');
    same(count(aifecOrderMails($app)) - $mailsAfterOld, $mailsAfterOld, 'los mismos correos en cola');
    check(count($orderNew['items']) >= 3, 'tiene sus líneas: dos productos, el envío y el recargo');
    same(300, $orderNew['fee_total']);
});
