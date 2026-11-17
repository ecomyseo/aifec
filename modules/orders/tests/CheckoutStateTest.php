<?php
/**
 * AIFEC — pruebas del motor de la caja (servicio «checkout»): el estado en cada punto, lo que falta y en qué orden, guardar
 * lo que vale y avisar de lo que no, elegir envío y forma de pago, y el borrador que lee la página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\Cart;

require_once __DIR__ . '/FrontKit.php';

/** Una tienda con un producto y dos unidades en el carrito. @return array{0:App,1:TestClient,2:int} */
function ckStateShop(array $settings = []): array
{
    $app = frontShop(['settings' => $settings]);
    $web = new TestClient($app);
    $p = frontProduct($app);
    frontAdd($web, $p, 2);

    return [$app, $web, $p];
}

/** Ejecuta $fn($motor, $app) dentro de una petición del navegador de mentira. @return mixed */
function ckStateRun(TestClient $web, callable $fn)
{
    return aifecCartRun($web, static function ($cart, App $app) use ($fn) {
        return $fn($app->service('checkout'), $app);
    });
}

/**
 * Los campos de una entrega correcta en Madrid, planos.
 *
 * @param array<string,string> $extra
 *
 * @return array<string,string>
 */
function ckStateFields(array $extra = []): array
{
    return $extra + [
        'email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '999345678',
        'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'country' => 'ES',
    ];
}

test('el servicio «checkout» existe y state() trae todas sus piezas sobre un carrito recién hecho', function (): void {
    [$app, $web] = ckStateShop();
    check($app->has('checkout'), 'el módulo de pedidos publica el servicio checkout');
    $st = ckStateRun($web, static function ($co): array {
        return $co->state();
    });
    foreach (['contact', 'address', 'billing', 'shipping', 'payment', 'totals', 'lines', 'missing', 'ready'] as $key) {
        check(array_key_exists($key, $st), 'state() trae «' . $key . '»');
    }
    same(true, $st['needs_shipping']);
    same(1, count($st['lines']));
    same(2, $st['lines'][0]['qty']);
    same(24200 + 495, $st['totals']['total']);
    same($app->i18n()->money(24695, 'EUR'), $st['totals']['total_formatted']);
    same(1, count($st['shipping']['options']));
    $option = $st['shipping']['options'][0];
    same('Estándar', $option['name']);
    same('24-48 h', $option['delay']);
    same(495, $option['price']);
    same($app->i18n()->money(495, 'EUR'), $option['price_formatted']);
    same(true, $option['selected']);
    same($option['id'], $st['shipping']['selected']);
    same(1, count($st['payment']['options']));
    same('gw', $st['payment']['options'][0]['id']);
    same('Pasarela de pruebas', $st['payment']['options'][0]['name']);
    same(null, $st['payment']['selected']);
});

test('state() sin nada escrito: falta el contacto, la dirección y el pago, en ese orden, y no está listo', function (): void {
    [$app, $web] = ckStateShop();
    $st = ckStateRun($web, static function ($co): array {
        return $co->state();
    });
    same(['contact', 'address', 'payment'], $st['missing']);
    same(false, $st['ready']);
    same('', $st['contact']['email']);
    same('ES', $st['address']['country'], 'el país de serie');
});

test('state() va quitando lo que falta a medida que se rellena: contacto, dirección y forma de pago', function (): void {
    [$app, $web] = ckStateShop();
    $miss = static function (TestClient $web): array {
        return ckStateRun($web, static function ($co): array {
            return $co->state();
        });
    };
    ckStateRun($web, static function ($co): void {
        $co->fill(['email' => 'ana@cliente.test']);
    });
    same(['address', 'payment'], $miss($web)['missing']);

    ckStateRun($web, static function ($co): void {
        $co->fill(ckStateFields());
    });
    same(['payment'], $miss($web)['missing']);

    $set = ckStateRun($web, static function ($co): array {
        return $co->setPayment('gw');
    });
    same(true, $set['ok']);
    $st = $miss($web);
    same([], $st['missing']);
    same(true, $st['ready']);
    same('gw', $st['payment']['selected']);
});

test('los cuatro faltantes salen en su orden: contacto, dirección, envío y pago (un país sin envío deja sin envío)', function (): void {
    [$app, $web] = ckStateShop();
    $res = ckStateRun($web, static function ($co): array {
        $co->fill(['country' => 'FR']);

        return $co->state();
    });
    same(['contact', 'address', 'shipping', 'payment'], $res['missing']);
    same([], $res['shipping']['options']);
    same(null, $res['shipping']['selected']);
});

test('un pedido solo digital no pide dirección ni envío: solo el nombre, el contacto y el pago', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    frontAdd($web, $guia, 1);

    $st = ckStateRun($web, static function ($co): array {
        return $co->state();
    });
    same(false, $st['needs_shipping']);
    same(['contact', 'address', 'payment'], $st['missing']);
    same(null, $st['shipping']['selected']);
    same(1500, $st['totals']['total']);

    $st = ckStateRun($web, static function ($co): array {
        $co->fill(['email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz']);
        $co->setPayment('gw');

        return $co->state();
    });
    same([], $st['missing'], 'sin calle ni código postal');
    same(true, $st['ready']);
    same('Ana', $st['billing']['first_name'], 'lo escrito va a los datos de facturación');
});

test('fill() con datos buenos y malos a la vez: lo bueno se queda y lo malo vuelve con su error', function (): void {
    [$app, $web] = ckStateShop();
    $res = ckStateRun($web, static function ($co): array {
        return $co->fill(ckStateFields(['email' => 'esto no es un correo', 'postcode' => '99999']));
    });
    same(false, $res['ok']);
    same(['email', 'shipping.postcode'], array_keys($res['errors']));
    same('Escribe un correo electrónico válido', $res['errors']['email']);
    same('Escribe un código postal válido', $res['errors']['shipping.postcode']);

    $st = ckStateRun($web, static function ($co): array {
        return $co->state();
    });
    same('Ana', $st['address']['first_name']);
    same('Calle Mayor 1', $st['address']['line1']);
    same('Madrid', $st['address']['city']);
    same('', $st['address']['postcode'], 'el código postal malo no se guardó');
    same('', $st['contact']['email'], 'el correo malo tampoco');
    check(in_array('shipping.first_name', $res['saved'], true) && !in_array('email', $res['saved'], true), 'saved dice lo que se guardó');
});

test('fill() guarda en el borrador del carrito: la página lo enseña al volver y el pedido sale de ahí', function (): void {
    [$app, $web] = ckStateShop();
    ckStateRun($web, static function ($co): void {
        $co->fill(ckStateFields(['line2' => '3ºB', 'note' => 'Llamar al timbre']));
    });
    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    foreach (['value="ana@cliente.test"', 'value="Ana"', 'value="Ruiz"', 'value="Calle Mayor 1"', 'value="3ºB"', 'value="28001"', 'value="Madrid"', 'value="999345678"', 'Llamar al timbre'] as $needle) {
        contains($needle, $page->body, 'la página enseña lo guardado: ' . $needle);
    }
    $draft = ckStateRun($web, static function ($co): array {
        return $co->draft();
    });
    same('Madrid', $draft['shipping']['city']);
    same('28', $draft['shipping']['region'], 'la provincia sale del código postal');
});

test('fill() con un código postal de otra zona recalcula el envío y trae la provincia', function (): void {
    [$app, $web] = ckStateShop();
    $st = ckStateRun($web, static function ($co): array {
        $co->fill(ckStateFields(['postcode' => '35001', 'city' => 'Las Palmas']));

        return $co->state();
    });
    same('35', $st['address']['region']);
    same(1595, $st['shipping']['options'][0]['price']);
    same(24200 + 1595, $st['totals']['total']);
});

test('fill() acepta los campos sueltos, dentro de «address» o de «shipping», y guarda textos con signos, tildes y emojis', function (): void {
    [$app, $web] = ckStateShop();
    $name = 'José & "Ñoño" 😀 <b>';
    $st = ckStateRun($web, static function ($co) use ($name): array {
        $co->fill(['address' => ['first_name' => $name, 'line1' => 'Calle Mayor 1']]);
        $co->fill(['shipping' => ['last_name' => 'Ruiz']]);
        $co->fill(['city' => 'Madrid']);

        return $co->state();
    });
    same($name, $st['address']['first_name']);
    same('Ruiz', $st['address']['last_name']);
    same('Madrid', $st['address']['city']);
});

test('fill() recorta lo demasiado largo y quita los caracteres de control', function (): void {
    [$app, $web] = ckStateShop();
    $st = ckStateRun($web, static function ($co): array {
        $co->fill(['first_name' => str_repeat('A', 500), 'line1' => "Calle\x00Mayor\n1", 'note' => str_repeat('n', 5000)]);

        return $co->state();
    });
    same(80, mb_strlen($st['address']['first_name'], 'UTF-8'));
    same('Calle Mayor 1', $st['address']['line1']);
    same(2000, mb_strlen($st['note'], 'UTF-8'));
});

test('fill() con un país que no es de dos letras avisa del país y no guarda el código postal en ese país', function (): void {
    [$app, $web] = ckStateShop();
    $res = ckStateRun($web, static function ($co): array {
        return $co->fill(['country' => 'España']);
    });
    same(['shipping.country'], array_keys($res['errors']));
    $st = ckStateRun($web, static function ($co): array {
        return $co->state();
    });
    same('ES', $st['address']['country'], 'se queda el país de antes');
});

test('fill() sin carrito no hace nada ni falla', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $res = ckStateRun($web, static function ($co): array {
        return $co->fill(ckStateFields());
    });
    same(true, $res['ok']);
    $st = ckStateRun($web, static function ($co): array {
        return $co->state();
    });
    same(false, $st['ready']);
    same([], $st['lines']);
    same(0, aifecOrderCount($app, 'carts'));
});

test('setShipping() solo acepta un envío que se ofrece, y setPayment() solo una forma de pago que se ofrece', function (): void {
    [$app, $web] = ckStateShop();
    $second = shippingAddCarrier($app, 'fast', ['name' => 'Urgente', 'delay' => '24 h']);
    foreach (['Peninsula' => 1295, 'Balearic Islands' => 1995, 'Canary Islands' => 2995, 'Ceuta and Melilla' => 2495] as $zone => $price) {
        shippingAddRate($app, $second, shippingZoneId($app, $zone), 'weight', 0, 0, $price);
    }
    $app->service('payments')->register(new AifecOrderPayment('transfer'));

    $res = ckStateRun($web, static function ($co) use ($second): array {
        $co->fill(ckStateFields());
        $bad = $co->setShipping(999);
        $none = $co->setShipping(0);
        $ok = $co->setShipping($second);
        $badPay = $co->setPayment('no-existe');
        $emptyPay = $co->setPayment('');
        $okPay = $co->setPayment('Transfer');

        return ['bad' => $bad, 'none' => $none, 'ok' => $ok, 'badPay' => $badPay, 'emptyPay' => $emptyPay, 'okPay' => $okPay, 'st' => $co->state()];
    });
    same(false, $res['bad']['ok']);
    contains('no está disponible', $res['bad']['error']);
    same(false, $res['none']['ok']);
    same(true, $res['ok']['ok']);
    same(false, $res['badPay']['ok']);
    same('Elige una forma de pago', $res['badPay']['error']);
    same(false, $res['emptyPay']['ok']);
    same(true, $res['okPay']['ok']);
    same($second, $res['st']['shipping']['selected']);
    same('transfer', $res['st']['payment']['selected']);
    same(24200 + 1295, $res['st']['totals']['total']);
});

test('cada forma de pago dice de qué clase es: en línea, fuera de la web o de pruebas', function (): void {
    [$app, $web] = ckStateShop();
    aifecOrderPayment($app)->test = false;
    $app->service('payments')->register(new AifecOrderPayment('transfer'));
    $app->service('payments')->register(aifecFakePayment('demo'));
    $st = ckStateRun($web, static function ($co): array {
        return $co->state();
    });
    $kinds = [];
    foreach ($st['payment']['options'] as $option) {
        $kinds[$option['id']] = $option['kind'];
    }
    ksort($kinds);
    same(['demo' => 'test', 'gw' => 'redirect', 'transfer' => 'offline'], $kinds);
});

test('el recargo de una forma de pago sale en su opción y entra en el total', function (): void {
    [$app, $web] = ckStateShop();
    aifecOrderPayment($app)->fee = 300;
    $st = ckStateRun($web, static function ($co): array {
        $co->setPayment('gw');

        return $co->state();
    });
    $option = $st['payment']['options'][0];
    same(300, $option['fee']);
    same($app->i18n()->money(300, 'EUR'), $option['fee_formatted']);
    same(24200 + 495 + 300, $st['totals']['total']);
    same(300, $st['totals']['fees']);
});

test('un cliente con cuenta: sus direcciones guardadas, la elegida manda y la de otro cliente no se puede elegir', function (): void {
    [$app, $web] = ckStateShop();
    $ana = aifecCartCustomer($app);
    $luis = aifecCartCustomer($app, 'luis@cliente.test', ['first_name' => 'Luis']);
    $customers = $app->service('customers');
    $own = $customers->saveAddress($ana, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Palmera 5', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES', 'phone' => '999345678']);
    $other = $customers->saveAddress($luis, ['first_name' => 'Luis', 'last_name' => 'Gil', 'line1' => 'Calle Ajena 9', 'postcode' => '08001', 'city' => 'Barcelona', 'country' => 'ES', 'phone' => '999345679']);
    frontLogin($web);

    $res = ckStateRun($web, static function ($co) use ($own, $other): array {
        $saved = $co->savedAddresses();
        $co->prepare();
        $first = $co->state();
        $co->fill(['address_id' => $other]);
        $afterOther = $co->state();
        $co->fill(['address_id' => $own]);

        return ['saved' => $saved, 'first' => $first, 'afterOther' => $afterOther, 'own' => $co->state()];
    });
    same(1, count($res['saved']), 'solo la suya');
    same('Calle Palmera 5', $res['saved'][0]['line1']);
    same('ana@cliente.test', $res['first']['contact']['email'], 'el correo es el de la cuenta');
    same(true, $res['first']['contact']['account']);
    same($own, $res['first']['address']['saved_id'], 'la de por defecto sale elegida');
    check(!isset($res['afterOther']['address']['saved_id']), 'la de otro cliente no se elige');
    same($own, $res['own']['address']['saved_id']);
    same('Calle Palmera 5', $res['own']['address']['line1']);
    same(24200 + 1595, $res['own']['totals']['total'], 'el envío es el de la dirección guardada');
    same([], $res['own']['missing'], 'prepare() ya había elegido la forma de pago');
});

test('prepare() elige la primera forma de pago si no había ninguna y deja el destino de la dirección que se enseña', function (): void {
    [$app, $web] = ckStateShop();
    $st = ckStateRun($web, static function ($co): array {
        $co->fill(ckStateFields(['postcode' => '35001', 'city' => 'Las Palmas']));
        $co->prepare();

        return $co->state();
    });
    same('gw', $st['payment']['selected']);
    same(1595, $st['shipping']['options'][0]['price']);
    $again = ckStateRun($web, static function ($co): array {
        $co->setPayment('gw');
        $co->prepare();

        return $co->state();
    });
    same('gw', $again['payment']['selected'], 'una elección que ya existía se respeta');
});

test('forCart() lee otro carrito y place() solo hace el pedido del carrito de la petición', function (): void {
    [$app, $web] = ckStateShop();
    $res = ckStateRun($web, static function ($co, App $app): array {
        $other = new Cart($app);
        $same = $co->forCart($app->service('cart'))->state();

        return ['same' => $same, 'mine' => $co->state(), 'foreign' => $co->forCart($other)];
    });
    same($res['mine']['totals']['total'], $res['same']['totals']['total'], 'el mismo carrito da la misma cuenta');
    throws(static function () use ($res): void {
        $res['foreign']->place('x');
    }, \LogicException::class);
});
