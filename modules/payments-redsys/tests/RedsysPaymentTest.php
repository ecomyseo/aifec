<?php
/**
 * AIFEC — pruebas del cobro por Redsys: cuándo se ofrece (tarjeta y Bizum), el formulario firmado que va al banco, el intento
 * que se apunta, los números de pedido distintos en cada intento y el cambio de entorno.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Modules\PaymentsRedsys\RedsysPayment;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/RedsysKit.php';

test('sin clave no se ofrece; con comercio, terminal y clave del entorno elegido, sí', function (): void {
    $app = rdsApp([], false);
    $method = $app->service('payments')->get('redsys');
    check($method !== null, 'la forma de pago está registrada');
    $context = paymentsContextForRedsys();

    same(false, $method->available($context), 'sin clave no se ofrece');
    $app->setSecret(RedsysSettings::SECRET_LIVE, RDS_LIVE_KEY);
    same(false, $method->available($context), 'la clave de producción no vale mientras el entorno es el de pruebas');
    $app->setSecret(RedsysSettings::SECRET_TEST, RDS_KEY);
    same(true, $method->available($context), 'con la clave del entorno elegido se ofrece');

    $app->settings()->set(RedsysSettings::MERCHANT_CODE, '');
    same(false, $method->available($context), 'sin número de comercio no');
    $app->settings()->set(RedsysSettings::MERCHANT_CODE, RDS_MERCHANT);
    $app->settings()->set(RedsysSettings::TERMINAL, '');
    same(false, $method->available($context), 'sin terminal no');
    $app->settings()->set(RedsysSettings::TERMINAL, '1');
    same(true, $method->available($context));
    same(0, $method->fee($context), 'no lleva recargo');
    same('redsys', $method->code());
    same(true, $method->supportsRefund());
});

test('solo se cobra en euros', function (): void {
    $app = rdsApp();
    $method = $app->service('payments')->get('redsys');

    same(true, $method->available(paymentsContextForRedsys(['currency' => 'EUR'])));
    same(true, $method->available(paymentsContextForRedsys(['currency' => 'eur'])), 'las mayúsculas dan igual');
    same(false, $method->available(paymentsContextForRedsys(['currency' => 'USD'])));
    same(false, $method->available(['total' => 2000]), 'sin moneda en el contexto no se arriesga');
});

test('Bizum es una segunda forma de pago que solo se ofrece si está encendida', function (): void {
    $app = rdsApp();
    $bizum = $app->service('payments')->get('bizum');
    check($bizum !== null, 'Bizum está registrado');
    $context = paymentsContextForRedsys();

    same('bizum', $bizum->code());
    same(false, $bizum->available($context), 'apagado por defecto');
    $app->settings()->set(RedsysSettings::BIZUM, true);
    same(true, $bizum->available($context));
    same(['redsys', 'bizum'], array_map(static function ($m): string {
        return $m->code();
    }, $app->service('payments')->available($context)), 'la caja ofrece las dos');
    $app->setSecret(RedsysSettings::SECRET_TEST, '');
    same(false, $bizum->available($context), 'sin clave tampoco');
});

test('los títulos son los de serie traducidos o los que escribe el comerciante tal cual, con su «&»', function (): void {
    $app = rdsApp();
    $card = $app->service('payments')->get('redsys');
    $bizum = $app->service('payments')->get('bizum');

    same('Pago con tarjeta', $card->info()['title']);
    contains('tarjeta', $card->info()['description']);
    same('Bizum', $bizum->info()['title']);
    contains('Bizum', $bizum->info()['description']);

    $app->settings()->set(RedsysSettings::TITLE, '  Tarjeta & débito  ');
    $app->settings()->set(RedsysSettings::BIZUM_TITLE, 'Bizum, al instante');
    same('Tarjeta & débito', $card->info()['title']);
    same('Bizum, al instante', $bizum->info()['title']);
});

test('start(): un formulario al banco de pruebas con los tres campos de Redsys y el importe exacto del pedido', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $action = $placed['action'];
    $order = rdsOrderRow($app, $placed);

    same(PaymentAction::FORM, $action->type);
    same('https://sis-t.redsys.es:25443/sis/realizarPago', $action->url);
    same(['Ds_SignatureVersion', 'Ds_MerchantParameters', 'Ds_Signature'], array_keys($action->fields));
    same('HMAC_SHA256_V1', $action->fields['Ds_SignatureVersion']);

    same((string) $order['total'], $params['DS_MERCHANT_AMOUNT'], 'el importe es el del pedido, en céntimos');
    check($order['total'] > 2000, 'y lleva el envío dentro');
    same('978', $params['DS_MERCHANT_CURRENCY']);
    same('0', $params['DS_MERCHANT_TRANSACTIONTYPE']);
    same(RDS_MERCHANT, $params['DS_MERCHANT_MERCHANTCODE']);
    same('1', $params['DS_MERCHANT_TERMINAL']);
    same('https://tienda.test/pago/redsys/aviso', $params['DS_MERCHANT_MERCHANTURL'], 'la dirección de aviso es absoluta y sin idioma');
    same('https://tienda.test/pago/redsys/ok/' . $placed['number'] . '/' . $placed['secret'], $params['DS_MERCHANT_URLOK']);
    same('https://tienda.test/pago/redsys/ko/' . $placed['number'] . '/' . $placed['secret'], $params['DS_MERCHANT_URLKO']);
    same('001', $params['DS_MERCHANT_CONSUMERLANGUAGE'], 'el idioma del pedido');
    contains((string) $placed['number'], $params['DS_MERCHANT_PRODUCTDESCRIPTION']);
    check(!isset($params['DS_MERCHANT_PAYMETHODS']), 'con tarjeta, la página del banco ofrece lo que el comercio tenga');

    same(true, rdsRequestSignatureOk($action->fields['Ds_MerchantParameters'], $action->fields['Ds_Signature'], RDS_KEY), 'la firma se verifica con la clave');
    same(false, rdsRequestSignatureOk($action->fields['Ds_MerchantParameters'], $action->fields['Ds_Signature'], RDS_LIVE_KEY), 'y no con otra');
    notContains(RDS_KEY, json_encode($action->fields), 'la clave no viaja al navegador');
});

test('start() apunta el intento como pendiente en order_payments, con el número de Redsys como referencia', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $number = $params['DS_MERCHANT_ORDER'];

    check(preg_match('/^[0-9]{4}[0-9A-Za-z]{0,8}$/D', $number) === 1, 'un número de pedido válido para Redsys: ' . $number);
    check(strlen($number) >= 4 && strlen($number) <= 12, 'de 4 a 12 caracteres');
    $order = rdsOrderRow($app, $placed);
    $attempts = rdsPayments($order, RedsysPayment::ATTEMPT);
    same(1, count($attempts));
    same(['redsys', $number, 'pending', true, (int) $order['total']], [$attempts[0]['method'], $attempts[0]['reference'], $attempts[0]['status'], $attempts[0]['test'], $attempts[0]['amount']]);
    same([], rdsPayments($order, 'charge'), 'todavía no hay ningún cobro');
    same('pending', $order['payment_status']);
    same('pending_payment', $order['status']);
});

test('dos intentos del mismo pedido tienen números de Redsys distintos, y los de pedidos distintos también', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('redsys');
    $method = $app->service('payments')->get('redsys');
    $order = $app->service('orders')->find((int) $placed['id']);
    $secret = ['secret' => (string) $placed['secret']] + $order;

    $numbers = [$params['DS_MERCHANT_ORDER']];
    for ($i = 0; $i < 30; ++$i) {
        $again = rdsParams($method->start($secret));
        $numbers[] = $again['DS_MERCHANT_ORDER'];
        check(preg_match('/^[0-9]{4}[0-9A-Za-z]{0,8}$/D', $again['DS_MERCHANT_ORDER']) === 1, 'sigue siendo válido: ' . $again['DS_MERCHANT_ORDER']);
    }
    same(31, count(array_unique($numbers)), 'ni uno repetido en 31 intentos (pasa de la Z)');
    same(31, count(rdsPayments(rdsOrderRow($app, $placed), RedsysPayment::ATTEMPT)));

    $other = rdsPlace($app, new TestClient($app), 'redsys', $p);
    $otherNumber = rdsParams($other['action'])['DS_MERCHANT_ORDER'];
    check(!in_array($otherNumber, $numbers, true), 'otro pedido, otro número');
    same(substr($numbers[0], 0, 4), sprintf('%04d', (int) $placed['id']), 'los cuatro primeros son el identificador del pedido');
});

test('Bizum pide la página de Bizum al banco y el pedido guarda ese método de pago', function (): void {
    list($app, $p, $placed, $params) = rdsOrder('bizum');

    same('z', $params['DS_MERCHANT_PAYMETHODS']);
    same('bizum', rdsOrderRow($app, $placed)['payment_method']);
    same(1, count(rdsPayments(rdsOrderRow($app, $placed), RedsysPayment::ATTEMPT)));
});

test('sin claves la caja no deja elegir Redsys; con una clave rota start() falla y place() deja el pedido fallido con las existencias de vuelta', function (): void {
    $app = rdsApp([], false);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $before = aifecOrderSnapshot($app);
    $placed = rdsPlace($app, new TestClient($app), 'redsys', $p);
    same(false, $placed['ok'], 'la caja no acepta una forma de pago que no se ofrece');
    same($before, aifecOrderSnapshot($app), 'y no escribe nada');

    // Una clave que no es de Redsys (no son 24 bytes en base64): se ofrece, pero no se puede firmar nada con ella.
    $app->setSecret(RedsysSettings::SECRET_TEST, 'esto-no-es-una-clave-de-redsys');
    $placed = rdsPlace($app, new TestClient($app), 'redsys', $p);
    same(false, $placed['ok']);
    same(PaymentAction::FAILED, $placed['action']->type);
    $order = rdsOrderRow($app, $placed);
    same('failed', $order['status']);
    same(10, aifecOrderStock($app, $p));
    same([], rdsPayments($order, RedsysPayment::ATTEMPT), 'sin formulario no se deja ningún intento pendiente');

    $app->setSecret(RedsysSettings::SECRET_TEST, RDS_KEY);
    $method = $app->service('payments')->get('redsys');
    $base = ['id' => 77, 'number' => 'T-0077', 'total' => 1000, 'currency' => 'EUR', 'lang' => 'es', 'secret' => 'ficha-de-mentira'];
    throws(function () use ($method, $base): void {
        $method->start(['secret' => ''] + $base);
    }, \RuntimeException::class);
    throws(function () use ($method, $base): void {
        $method->start(['currency' => 'USD'] + $base);
    }, \RuntimeException::class);
    throws(function () use ($method, $base): void {
        $method->start(['id' => 0] + $base);
    }, \RuntimeException::class);
    same(0, aifecOrderCount($app, 'order_payments', "kind = 'attempt'"), 'ningún intento apuntado por las peticiones malas');
});

test('el entorno de producción usa la otra clave y la otra dirección, isTest() lo refleja y el pedido lo guarda', function (): void {
    $app = rdsApp();
    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'live');
    $method = $app->service('payments')->get('redsys');
    same(false, $method->available(paymentsContextForRedsys()), 'en producción sin clave de producción no se ofrece');
    $app->setSecret(RedsysSettings::SECRET_LIVE, RDS_LIVE_KEY);
    same(true, $method->available(paymentsContextForRedsys()));
    same(false, $method->isTest());

    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = rdsPlace($app, new TestClient($app), 'redsys', $p);
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
    $action = $placed['action'];
    same('https://sis.redsys.es/sis/realizarPago', $action->url);
    same(true, rdsRequestSignatureOk($action->fields['Ds_MerchantParameters'], $action->fields['Ds_Signature'], RDS_LIVE_KEY), 'firmado con la clave de producción');
    same(false, rdsRequestSignatureOk($action->fields['Ds_MerchantParameters'], $action->fields['Ds_Signature'], RDS_KEY), 'y no con la de pruebas');
    $attempts = rdsPayments(rdsOrderRow($app, $placed), RedsysPayment::ATTEMPT);
    same(false, $attempts[0]['test'], 'el intento se apunta como real');

    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'test');
    same(true, $method->isTest());
    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'cualquier-cosa');
    same(true, $method->isTest(), 'un valor raro cuenta como pruebas, que es el lado seguro');
});

/**
 * El contexto que la caja da a available(): una compra de 20,00 € con envío a España.
 *
 * @param array<string,mixed> $cambios
 *
 * @return array<string,mixed>
 */
function paymentsContextForRedsys(array $cambios = []): array
{
    return $cambios + ['total' => 2000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => true, 'customer_id' => 0, 'lang' => 'es'];
}
