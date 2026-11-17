<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La dirección de pago de un pedido: lo que un asistente le da a la persona cuando el pedido se paga en una pasarela. Abre
 * un intento nuevo en Redsys y manda el formulario al banco; con el pedido pagado o cancelado, vuelve a la página del pedido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\PaymentsRedsys\RedsysPayment;

require_once __DIR__ . '/RedsysKit.php';

test('la dirección de pago de un pedido sin cobrar manda el formulario al banco con un intento nuevo, y la página del pedido la enseña', function (): void {
    list($app, , $placed, $params) = rdsOrder('redsys');
    $web = new TestClient($app);
    $base = route('order.thanks', ['number' => (string) $placed['number'], 'secret' => (string) $placed['secret']]);

    $page = $web->get($base);
    same(200, $page->status);
    contains('data-af-pay', $page->body, 'la página del pedido ofrece pagarlo');
    contains('href="' . e($base . '/pay') . '"', $page->body);
    contains('Pagar este pedido ahora', $page->body);

    $pay = $web->get($base . '/pay');
    same(200, $pay->status);
    contains('data-af-autopost', $pay->body, 'un formulario que se envía solo');
    foreach (['Ds_SignatureVersion', 'Ds_MerchantParameters', 'Ds_Signature'] as $field) {
        contains('name="' . $field . '"', $pay->body);
    }
    contains('action="https://sis-t.redsys.es', $pay->body, 'al banco de pruebas');
    same('no-store, private', (string) $pay->headers['Cache-Control']);

    $attempts = rdsPayments(rdsOrderRow($app, $placed), RedsysPayment::ATTEMPT);
    same(2, count($attempts), 'el intento del pedido y el de la dirección de pago');
    check((string) $attempts[0]['reference'] !== (string) $attempts[1]['reference'], 'cada intento, con su número de Redsys');
    check(in_array((string) $params['DS_MERCHANT_ORDER'], [(string) $attempts[0]['reference'], (string) $attempts[1]['reference']], true), 'el primero es el del pedido');

    $wrong = route('order.thanks', ['number' => (string) $placed['number'], 'secret' => str_repeat('0', strlen((string) $placed['secret']))]);
    same(404, $web->get($wrong . '/pay')->status, 'sin la ficha del pedido no hay dirección de pago');
});

test('con el pedido pagado o cancelado, la dirección de pago vuelve a la página del pedido y no abre ningún intento', function (): void {
    foreach ([['payment_status' => 'paid'], ['status' => 'cancelled']] as $change) {
        list($app, , $placed) = rdsOrder('redsys');
        $web = new TestClient($app);
        $base = route('order.thanks', ['number' => (string) $placed['number'], 'secret' => (string) $placed['secret']]);
        $app->db()->update('orders', $change, ['id' => (int) $placed['id']]);

        $pay = $web->get($base . '/pay');
        same(303, $pay->status, json_encode($change));
        same($base, (string) $pay->headers['Location']);
        notContains('data-af-pay', $web->get($base)->body, 'y la página del pedido ya no ofrece pagarlo');
        same(1, count(rdsPayments(rdsOrderRow($app, $placed), RedsysPayment::ATTEMPT)), 'solo el intento del pedido');
    }
});
