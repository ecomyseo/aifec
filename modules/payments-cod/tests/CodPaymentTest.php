<?php
/**
 * AIFEC — pruebas del contra reembolso: cuándo se ofrece (envío, país, tope), el recargo (fijo, porcentaje y mínimo), su línea
 * aparte con impuesto en el carrito y en el pedido con el total hecho a mano, las instrucciones y la falta de devolución por pasarela.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;

require_once __DIR__ . '/../../payments-simulated/tests/PaymentsKit.php';

/**
 * Una tienda con el contra reembolso (recargo de 3,00 € más 1 %) y una camiseta de 20,00 € con IVA, 200 g.
 *
 * @param array<string,mixed> $settings
 *
 * @return array{0:App,1:int}
 */
function codShop(array $settings = []): array
{
    $app = paymentsApp(['payments-cod'], $settings + ['payments.cod.fee' => 300, 'payments.cod.percent' => 100]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);

    return [$app, $p];
}

test('se ofrece solo si el pedido necesita envío', function (): void {
    list($app, $p) = codShop();
    $method = $app->service('payments')->get('cod');
    check($method !== null, 'la forma de pago está registrada');

    same(true, $method->available(paymentsContext()));
    same(false, $method->available(paymentsContext(['needs_shipping' => false])), 'un carrito solo digital no lo ofrece');
    same(false, $method->available(['total' => 2000, 'country' => 'ES']), 'sin saber si hay envío, tampoco');
    same(['cod'], paymentsCodes($app->service('payments')->available(paymentsContext())));

    // Con un producto digital de verdad, el carrito no lo ofrece.
    $digital = aifecProduct($app, ['name' => 'Guía', 'price' => 1500, 'kind' => 'digital']);
    $web = new TestClient($app);
    $methods = aifecCartRun($web, static function ($cart, App $app) use ($digital): array {
        $cart->add($digital, 0, 1);

        return $cart->summary()['payment_methods'];
    });
    same([], $methods);
});

test('se ofrece solo en los países de la lista (por defecto, España) y sin importar mayúsculas ni repetidos', function (): void {
    list($app, $p) = codShop();
    $method = $app->service('payments')->get('cod');
    same(['ES'], $app->settings()->arr('payments.cod.countries'), 'por defecto, solo ES');

    same(true, $method->available(paymentsContext(['country' => 'ES'])));
    same(true, $method->available(paymentsContext(['country' => 'es'])), 'el país llega como sea');
    same(false, $method->available(paymentsContext(['country' => 'PT'])));
    same(false, $method->available(paymentsContext(['country' => ''])), 'sin país, no');

    $app->settings()->set('payments.cod.countries', [' pt ', 'ES', 'es', '']);
    same(true, $method->available(paymentsContext(['country' => 'PT'])));
    same(true, $method->available(paymentsContext(['country' => 'ES'])));
    same(false, $method->available(paymentsContext(['country' => 'FR'])));
    $app->settings()->set('payments.cod.countries', []);
    same(false, $method->available(paymentsContext(['country' => 'ES'])), 'una lista vacía no ofrece nada');
});

test('el tope de importe: el total justo en el tope pasa, un céntimo más no, y cero es sin tope', function (): void {
    list($app, $p) = codShop(['payments.cod.max_total' => 10000]);
    $method = $app->service('payments')->get('cod');

    same(true, $method->available(paymentsContext(['total' => 10000])));
    same(false, $method->available(paymentsContext(['total' => 10001])));
    same(true, $method->available(paymentsContext(['total' => 0])));
    $app->settings()->set('payments.cod.max_total', 0);
    same(true, $method->available(paymentsContext(['total' => 99999999])), 'cero = sin tope');

    // Y en el carrito de verdad: una camiseta cabe; veinte no.
    $app->settings()->set('payments.cod.max_total', 5000);
    $web = new TestClient($app);
    $codes = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 1);
        $few = array_column($cart->summary()['payment_methods'], 'code');
        $cart->update(array_keys($cart->summary()['lines'])[0], 9);
        $many = array_column($cart->summary()['payment_methods'], 'code');

        return [$few, $many];
    });
    same([['cod'], []], $codes);
});

test('el recargo es un fijo más un porcentaje del total, con un mínimo, y nunca negativo', function (): void {
    list($app, $p) = codShop();
    $method = $app->service('payments')->get('cod');

    same(325, $method->fee(paymentsContext(['total' => 2495])), '3,00 € + 1 % de 24,95 € (24,95 céntimos: 25 redondeado)');
    same(300, $method->fee(paymentsContext(['total' => 0])), 'sobre cero, solo el fijo');
    same(10300, $method->fee(paymentsContext(['total' => 1000000])), '3,00 € + 1 % de 10.000,00 €');

    $app->settings()->set('payments.cod.min_fee', 500);
    same(500, $method->fee(paymentsContext(['total' => 2495])), 'el mínimo gana al cálculo');
    same(10300, $method->fee(paymentsContext(['total' => 1000000])), 'y no sube lo que ya lo pasa');

    $app->settings()->set('payments.cod.fee', 0);
    $app->settings()->set('payments.cod.percent', 250);
    $app->settings()->set('payments.cod.min_fee', 0);
    same(1250, $method->fee(paymentsContext(['total' => 50000])), 'solo porcentaje: 2,5 % de 500,00 €');

    $app->settings()->set('payments.cod.percent', 0);
    same(0, $method->fee(paymentsContext()), 'sin nada configurado, no hay recargo');

    $app->settings()->set('payments.cod.fee', -300);
    $app->settings()->set('payments.cod.percent', -100);
    same(0, $method->fee(paymentsContext(['total' => 2000])), 'un ajuste negativo no regala dinero');
});

test('el recargo sale como línea aparte en el carrito, con su impuesto, y el total cuadra con la cuenta hecha a mano', function (): void {
    list($app, $p) = codShop();
    $web = new TestClient($app);
    $summary = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 1);
        $cart->setPaymentMethod('cod');

        return $cart->summary();
    });

    // A mano, con los precios con IVA dentro: camiseta 20,00 + envío 4,95 = 24,95; recargo 3,00 + 1 % de 24,95 (0,25) = 3,25.
    // Total 28,20. El recargo lleva el IVA del 21 % dentro: base 2,69 e IVA 0,56.
    same('cod', $summary['payment_method']);
    same(2000, $summary['totals']['items_total']);
    same(495, $summary['totals']['shipping']['price']);
    same(325, $summary['totals']['fee_total']);
    same(1, count($summary['totals']['fees']));
    $fee = $summary['totals']['fees'][0];
    same(325, $fee['amount']);
    same(2100, $fee['tax_rate']);
    same([269, 56], [$fee['net'], $fee['tax']], 'base e IVA del recargo');
    contains('Contra reembolso', $fee['label'], 'la línea dice de qué es');
    same(2820, $summary['totals']['total'], '20,00 + 4,95 + 3,25 = 28,20');
    same(325, array_column($summary['payment_methods'], 'fee', 'code')['cod'], 'la caja enseña el recargo junto a la forma de pago');

    // Sin elegirlo, no hay recargo.
    $without = aifecCartRun($web, static function ($cart, App $app): array {
        $cart->setPaymentMethod('');

        return $cart->summary();
    });
    same(0, $without['totals']['fee_total']);
    same(2495, $without['totals']['total']);
});

test('el pedido guarda el recargo como línea aparte con su impuesto y el total cuadra', function (): void {
    list($app, $p) = codShop();
    $placed = paymentsPlace($app, new TestClient($app), 'cod', [[$p, 0, 1]]);
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
    same(PaymentAction::INSTRUCTIONS, $placed['action']->type);

    $order = $app->service('orders')->find((int) $placed['id']);
    same('cod', $order['payment_method']);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    same(false, $order['test']);
    same(325, $order['fee_total']);
    same(2820, $order['total']);
    $fees = array_values(array_filter($order['items'], static function (array $item): bool {
        return $item['kind'] === 'fee';
    }));
    same(1, count($fees), 'una línea de recargo');
    same([325, 269, 56, 2100], [$fees[0]['line_total'], $fees[0]['line_net'], $fees[0]['line_tax'], $fees[0]['tax_rate']]);
    $sum = 0;
    foreach ($order['items'] as $item) {
        if ($item['kind'] !== 'discount') {
            $sum += $item['line_total'];
        }
    }
    same(2820, $sum, 'las líneas suman el total: 20,00 + 4,95 + 3,25');
    same(2820, $order['total_net'] + $order['total_tax'], 'base más impuestos también');
    same(9, aifecOrderStock($app, $p), 'las existencias quedan reservadas');
});

test('las instrucciones dicen que se paga al recibir el pedido, con el importe, y salen en el idioma del pedido', function (): void {
    list($app, $p) = codShop();
    $placed = paymentsPlace($app, new TestClient($app), 'cod', [[$p, 0, 1]]);
    contains('Pagarás al recibir el pedido', $placed['action']->html);
    contains('28,20', $placed['action']->html);
    $mails = aifecOrderMails($app, 'order_on_hold');
    same(1, count($mails));
    contains('Pagarás al recibir el pedido', (string) $mails[0]['html']);

    $method = $app->service('payments')->get('cod');
    $en = $method->start(['number' => 'X-1', 'total' => 2820, 'currency' => 'EUR', 'lang' => 'en'])->html;
    contains('You will pay when you receive the order.', $en);
    contains('€28.20', $en);
    $bare = $method->start(['number' => 'X-1'])->html;
    contains('Pagarás', $bare, 'aguanta un pedido sin importe');
});

test('el título y la descripción son los de serie traducidos, o los del comerciante tal cual; no devuelve por pasarela', function (): void {
    list($app, $p) = codShop();
    $method = $app->service('payments')->get('cod');
    same('Contra reembolso', $method->info()['title']);
    contains('al recibir', $method->info()['description']);

    $app->settings()->set('payments.cod.title', 'Paga en casa & sin sustos');
    same('Paga en casa & sin sustos', $method->info()['title']);
    same('cod', $method->code());
    same(false, $method->isTest());
    same(false, $method->supportsRefund());
    throws(static function () use ($method): void {
        $method->refund(['number' => 'X'], 100, 'motivo');
    }, \RuntimeException::class);
});
