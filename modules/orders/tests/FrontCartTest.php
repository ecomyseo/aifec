<?php
/**
 * AIFEC — pruebas de la página del carrito: líneas, cantidades, cupones, estimación del envío, desglose, huecos y el botón
 * de finalizar la compra. Se recorre como un navegador: se pide la página y se envían los formularios que lleva.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\Front\TotalsView;

require_once __DIR__ . '/FrontKit.php';

test('el carrito vacío enseña el aviso y un enlace a la tienda, y no se indexa ni se guarda en caché', function (): void {
    $app = frontShop();
    $web = new TestClient($app);

    $r = $web->get('/carrito');
    same(200, $r->status);
    contains('af-empty', $r->body);
    contains('Tu carrito está vacío', $r->body);
    contains('noindex', $r->body);
    contains('no-store', $r->headers['Cache-Control']);
    notContains('data-af-cart-line=', $r->body);
    check(isset($r->headers['Content-Type']) && strpos($r->headers['Content-Type'], 'text/html') === 0, 'es una página HTML');
});

test('el carrito con líneas enseña nombre, cantidad, total de la línea y un total sin envío mientras no hay destino', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $p = frontProduct($app);
    frontAdd($web, $p, 2);

    $r = $web->get('/carrito');
    same(200, $r->status);
    contains('Mesa de roble', $r->body);
    contains('af-cart-line', $r->body);
    contains($app->i18n()->money(24200, 'EUR'), $r->body, 'el total de la línea');
    contains('Se calcula en el siguiente paso', $r->body);
    check(preg_match('/Se calcula en el siguiente paso \(desde [0-9]/u', $r->body) === 1, 'con lo que costaría al destino de serie (ronda 3)');
    contains('IVA incluido', $r->body);
    // Sin destino el total es el de los artículos: el envío no se cuela en la cifra.
    same(24200, frontTotal($r->body));
    contains('href="' . route('checkout') . '"', $r->body);
    check(preg_match('/<aside.*?<\/aside>/s', $r->body, $aside) === 1, 'el resumen está en la página');
    notContains('disabled', $aside[0], 'sin problemas el botón de finalizar se puede pulsar');
});

test('cambiar la cantidad y quitar una línea con los formularios de la propia página', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $p = frontProduct($app);
    frontAdd($web, $p, 1);

    $html = $web->get('/carrito')->body;
    $key = frontNode($html, '//li[@data-af-cart-line]')->attributes->getNamedItem('data-af-cart-line')->nodeValue;
    $form = frontNode($html, '//form[@action="' . route('cart.update') . '"]');
    check($form !== null, 'la línea trae su formulario de cantidad');

    $r = $web->post('/carrito/update', ['key' => $key, 'qty' => '3']);
    same(302, $r->status);
    $after = $web->follow()->body;
    same(36300, frontTotal($after), 'tres unidades');
    contains('value="3"', $after);

    $web->post('/carrito/remove', ['key' => $key]);
    $empty = $web->follow()->body;
    contains('af-empty', $empty);
});

test('un cupón bueno aparece con su importe y se puede quitar; uno malo da su mensaje y no cambia nada', function (): void {
    $app = frontShop();
    couponsMake($app, ['code' => 'OTONO', 'kind' => 'percent', 'percent' => 1000]);
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 2);

    $web->post('/carrito/coupon', ['code' => 'otono']);
    $html = $web->follow()->body;
    contains('data-af-coupon="OTONO"', $html);
    contains('-' . $app->i18n()->money(2420, 'EUR'), $html, 'el importe del cupón');
    same(24200 - 2420, frontTotal($html), 'el total ya lleva el descuento');
    contains('af-totals-discount', $html);

    $web->post('/carrito/coupon', ['code' => 'NOEXISTE']);
    $bad = $web->follow()->body;
    contains('Este código de cupón no es válido', $bad);
    same(24200 - 2420, frontTotal($bad), 'el cupón malo no cambia el total');

    $web->post('/carrito/coupon', ['remove' => 'OTONO']);
    $removed = $web->follow()->body;
    notContains('data-af-coupon', $removed);
    same(24200, frontTotal($removed));
});

test('calcular el envío con país y código postal enseña las opciones con su precio y mete el envío en el total', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 2);

    $web->post('/carrito/destination', ['country' => 'ES', 'postcode' => '35001', 'region' => '']);
    same(302, $web->last->status);
    $html = $web->follow()->body;
    contains('data-af-shipping-options', $html);
    contains('Estándar', $html);
    contains($app->i18n()->money(1595, 'EUR'), $html, 'el envío a Canarias');
    notContains('Se calcula en el siguiente paso', $html);
    same(24200 + 1595, frontTotal($html));
    contains('value="35001"', $html, 'el código postal queda escrito');

    $web->post('/carrito/destination', ['country' => 'ES', 'postcode' => '28001', 'region' => '']);
    $peninsula = $web->follow()->body;
    same(24200 + 495, frontTotal($peninsula));
});

test('un país sin zona de envío no ofrece opciones y lo dice', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);

    $web->post('/carrito/destination', ['country' => 'JP', 'postcode' => '1000001', 'region' => '']);
    $html = $web->follow()->body;
    contains('No podemos enviar a este destino', $html);
    contains('disabled', $html, 'sin envío posible no se puede finalizar');
});

test('un problema del carrito desactiva el botón de finalizar y lo explica', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $p = frontProduct($app, ['stock' => 5]);
    frontAdd($web, $p, 3);
    $app->db()->update('products', ['stock' => 1], ['id' => $p]);

    $html = $web->get('/carrito')->body;
    contains('af-alert-bad', $html);
    contains('Solo queda 1 unidad', $html);
    check(preg_match('/<aside.*?<\/aside>/s', $html, $aside) === 1, 'el resumen está en la página');
    contains('disabled', $aside[0]);
    notContains('href="' . route('checkout') . '"', $aside[0], 'el resumen ya no enlaza a la caja');
    contains('Resuelve los problemas de arriba', $html);
});

test('el desglose suma al céntimo con los precios con y sin impuestos, con descuentos, envío y recargo de pago', function (): void {
    foreach ([[true, true], [false, false], [false, true]] as [$inclusive, $withTax]) {
        $app = frontShop(['settings' => ['tax.prices_include_tax' => $inclusive, 'tax.display_with_tax' => $withTax]]);
        couponsMake($app, ['code' => 'OTONO', 'kind' => 'percent', 'percent' => 1000]);
        aifecOrderPayment($app)->fee = 300;
        $web = new TestClient($app);
        $a = frontProduct($app, ['price' => 12100, 'tax_class_id' => 'standard']);
        $b = frontProduct($app, ['name' => 'Taza', 'price' => 1990, 'tax_class_id' => 'reduced', 'weight' => 300]);

        $view = aifecCartRun($web, static function ($cart, App $app) use ($a, $b): array {
            $cart->add($a, 0, 2);
            $cart->add($b, 0, 3);
            $cart->applyCoupon('OTONO');
            $cart->setDestination(['country' => 'ES', 'postcode' => '28001']);
            $cart->setPaymentMethod('gw');
            $summary = $cart->summary();

            return [TotalsView::forCart($app, $summary, true), (int) $summary['totals']['total']];
        });
        [$v, $total] = $view;
        $sum = $v['subtotal'];
        foreach ($v['rows'] as $row) {
            if ($row['cents'] !== null) {
                $sum += $row['cents'];
            }
        }
        $mode = ($inclusive ? 'con' : 'sin') . ' impuestos en el precio, ' . ($withTax ? 'con' : 'sin') . ' ellos en pantalla';
        same($total, $v['total'], 'el total es el del cálculo (' . $mode . ')');
        same($total, $sum, 'las filas suman el total (' . $mode . ')');
        $kinds = array_column($v['rows'], 'kind');
        check(in_array('discount', $kinds, true) && in_array('shipping', $kinds, true) && in_array('fee', $kinds, true) && in_array('tax', $kinds, true), 'salen descuento, envío, recargo e impuestos (' . $mode . ')');
        same($withTax ? 'IVA incluido' : 'Más impuestos', $v['note']);
    }
});

test('los huecos del carrito se pintan y un dato con comillas, signos y etiquetas sale escapado', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    foreach (['cart.before', 'cart.lines.after', 'cart.totals.after', 'cart.after'] as $slot) {
        $app->hooks()->slot($slot, static function (array $cart) use ($slot): string {
            return '<p data-slot="' . $slot . '">' . count($cart['lines']) . '</p>';
        });
    }
    frontAdd($web, frontProduct($app, ['name' => 'Mesa <b>"Roble"</b> & Co']), 1);

    $html = $web->get('/carrito')->body;
    foreach (['cart.before', 'cart.lines.after', 'cart.totals.after', 'cart.after'] as $slot) {
        contains('data-slot="' . $slot . '"', $html, 'el hueco ' . $slot);
    }
    notContains('<b>"Roble"</b>', $html);
    contains('&lt;b&gt;', $html);
    contains('&amp; Co', $html);
});

test('con la estimación del envío apagada el carrito no enseña el formulario y deja el envío para la caja', function (): void {
    $app = frontShop(['settings' => ['shipping.estimate_in_cart' => false]]);
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);

    $html = $web->get('/carrito')->body;
    notContains('action="' . route('cart.destination') . '"', $html);
    notContains('data-af-shipping-options', $html);
    contains('Se calcula en el siguiente paso', $html);
    same(12100, frontTotal($html));
});

