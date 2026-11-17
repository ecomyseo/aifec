<?php
/**
 * AIFEC — pruebas de la transferencia bancaria: cuándo se ofrece, las instrucciones (en la caja y en el correo del pedido en
 * espera), el IBAN en grupos de cuatro, el escape de lo que escribe el comerciante, el plazo y la falta de devolución por pasarela.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;

require_once __DIR__ . '/TransferKit.php';

test('sin IBAN, o con uno de dígitos de control malos, la transferencia no se ofrece', function (): void {
    $app = paymentsApp(['payments-transfer']);
    $method = $app->service('payments')->get('transfer');
    check($method !== null, 'la forma de pago está registrada');
    same(false, $method->available(paymentsContext()), 'sin IBAN');
    same([], paymentsCodes($app->service('payments')->available(paymentsContext())));

    $app->settings()->set('payments.transfer.iban', TRANSFER_IBAN_BAD);
    same(false, $method->available(paymentsContext()), 'con los dígitos de control mal, tampoco');

    $app->settings()->set('payments.transfer.iban', 'es82 0000 0000 0000 0000 0000');
    same(true, $method->available(paymentsContext()), 'con un IBAN bueno, con espacios y en minúsculas, sí');
    same(['transfer'], paymentsCodes($app->service('payments')->available(paymentsContext())));
    same(0, $method->fee(paymentsContext()));
    same(false, $method->isTest());
});

test('con IBAN, el pedido queda en espera y las instrucciones llevan el IBAN en grupos de cuatro y el número de pedido', function (): void {
    list($app, $p, $placed) = transferOrder();
    same(PaymentAction::INSTRUCTIONS, $placed['action']->type);
    $html = $placed['action']->html;
    contains('ES82 0000 0000 0000 0000 0000', $html);
    notContains('ES8200000000', $html, 'el IBAN no sale pegado');
    contains((string) $placed['number'], $html);
    contains('Sillas del Sur', $html);
    contains('Titular', $html);
    contains('Concepto', $html);
    contains('Importe', $html);

    $order = $app->service('orders')->find((int) $placed['id']);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    same(false, $order['test'], 'una transferencia no es de pruebas');
    same(8, aifecOrderStock($app, $p), 'las existencias quedan reservadas: 10 menos 2');
    same(0, count($order['payments']));

    $mails = aifecOrderMails($app, 'order_on_hold');
    same(1, count($mails), 'el cliente recibe el correo de pedido en espera');
    contains('ES82 0000 0000 0000 0000 0000', (string) $mails[0]['html']);
    contains((string) $placed['number'], (string) $mails[0]['html']);
});

test('un titular, un banco o una nota con «&», menor que y comillas salen escapados; la nota respeta los saltos de línea', function (): void {
    list($app, $p, $placed) = transferOrder([
        'payments.transfer.holder' => 'Sillas & Mesas <S.L.>',
        'payments.transfer.bank' => 'Banco "Ñandú" & Cía',
        'payments.transfer.note' => "Ojo: <b>no</b> pagues en efectivo.\nGracias & hasta pronto",
    ]);
    $html = $placed['action']->html;

    contains('Sillas &amp; Mesas &lt;S.L.&gt;', $html);
    contains('Banco &quot;Ñandú&quot; &amp; Cía', $html);
    contains('Ojo: &lt;b&gt;no&lt;/b&gt; pagues en efectivo.<br>' . "\n" . 'Gracias &amp; hasta pronto', $html);
    notContains('<S.L.>', $html);
    notContains('<b>', $html);
    notContains('<script', $html);
});

test('el plazo sale en las instrucciones con su singular y su plural, y con cero días no se menciona', function (): void {
    list($app, $p, $placed) = transferOrder(['payments.transfer.days' => 5]);
    contains('plazo de 5 días', $placed['action']->html);

    $method = $app->service('payments')->get('transfer');
    $app->settings()->set('payments.transfer.days', 1);
    contains('plazo de 1 día.', $method->start(['number' => 'X-1', 'total' => 1000, 'currency' => 'EUR', 'lang' => 'es'])->html);
    $app->settings()->set('payments.transfer.days', 0);
    notContains('plazo', $method->start(['number' => 'X-1', 'total' => 1000, 'currency' => 'EUR', 'lang' => 'es'])->html);
});

test('las instrucciones salen en el idioma del pedido, con su importe', function (): void {
    $app = paymentsApp(['payments-transfer'], ['payments.transfer.iban' => TRANSFER_IBAN, 'payments.transfer.holder' => 'Sillas del Sur']);
    $method = $app->service('payments')->get('transfer');

    $en = $method->start(['number' => '2026-000007', 'total' => 4050, 'currency' => 'EUR', 'lang' => 'en'])->html;
    contains('Account holder', $en);
    contains('Reference', $en);
    contains('please pay within 5 days', strtolower($en));
    contains('€40.50', $en);
    $es = $method->start(['number' => '2026-000007', 'total' => 4050, 'currency' => 'EUR', 'lang' => 'es'])->html;
    contains('Titular', $es);
    contains('Concepto', $es);
    contains('40,50', $es);
    contains('2026-000007', $es);
});

test('sin IBAN bueno, start() no inventa unas instrucciones', function (): void {
    $app = paymentsApp(['payments-transfer']);
    $method = $app->service('payments')->get('transfer');

    throws(static function () use ($method): void {
        $method->start(['number' => 'X-1', 'total' => 1000, 'currency' => 'EUR']);
    }, \RuntimeException::class);
});

test('no devuelve dinero por pasarela: una devolución a mano sí, pero por pasarela da error y no apunta nada', function (): void {
    list($app, $p, $placed) = transferOrder();
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $method = $app->service('payments')->get('transfer');
    same(false, $method->supportsRefund());
    throws(static function () use ($method): void {
        $method->refund(['number' => 'X'], 100, 'motivo');
    }, \RuntimeException::class);

    $order = $orders->find($id);
    same(true, $orders->markPaid($id, 'transfer', 'TRF-1', (int) $order['total']), 'quien lleva la tienda marca la transferencia como cobrada');
    throws(static function () use ($orders, $id): void {
        $orders->refund($id, 500, [], 'Devolución', true, false);
    }, \RuntimeException::class);
    same(0, $orders->find($id)['refunded_total'], 'no queda dinero devuelto apuntado');
    same(true, $orders->refund($id, 500, [], 'Devolución a mano', false, false) > 0, 'a mano sí se puede');
});
