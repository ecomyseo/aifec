<?php
/**
 * AIFEC — pruebas del cobro por PayPal: cuándo se ofrece, la orden que se crea en PayPal (importe, moneda, referencia y direcciones
 * de vuelta), el intento que se apunta, el fallo de la API, el entorno live y la ficha de acceso que se reutiliza.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Commerce\PaymentAction;
use Aifec\Http\HttpException;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalKit.php';

/**
 * @param array<string,mixed> $cambios
 *
 * @return array<string,mixed>
 */
function ppContext(array $cambios = []): array
{
    return $cambios + ['total' => 2000, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => true, 'customer_id' => 0, 'lang' => 'es'];
}

test('sin identificador y secreto no se ofrece; con los del entorno elegido, sí', function (): void {
    $app = ppApp([], false);
    $method = $app->service('payments')->get('paypal');
    check($method !== null, 'la forma de pago está registrada');

    same(false, $method->available(ppContext()), 'sin credenciales no se ofrece');
    $app->setSecret(PayPalSettings::SECRET_LIVE, PP_LIVE_SECRET);
    $app->settings()->set(PayPalSettings::CLIENT_ID_LIVE, PP_LIVE_CLIENT);
    same(false, $method->available(ppContext()), 'las de producción no valen mientras el entorno es el sandbox');
    $app->settings()->set(PayPalSettings::CLIENT_ID_SANDBOX, PP_CLIENT);
    same(false, $method->available(ppContext()), 'el identificador solo, tampoco');
    $app->setSecret(PayPalSettings::SECRET_SANDBOX, PP_SECRET);
    same(true, $method->available(ppContext()), 'con las del entorno elegido se ofrece');

    $app->settings()->set(PayPalSettings::CLIENT_ID_SANDBOX, '');
    same(false, $method->available(ppContext()), 'sin identificador no');
    same(0, $method->fee(ppContext()), 'no lleva recargo');
    same('paypal', $method->code());
    same(true, $method->supportsRefund());
    same(true, $method->isTest(), 'el sandbox es de pruebas');
    // P094: además de la cartera, de serie se ofrece la tarjeta en la página de PayPal (paypal_card), después.
    same(['paypal', 'paypal_card'], array_map(static function ($m): string {
        return $m->code();
    }, ppApp()->service('payments')->available(ppContext())), 'la caja la ofrece');
});

test('el título es el de serie traducido o el que escribe el comerciante tal cual, con su «&»', function (): void {
    $app = ppApp();
    $method = $app->service('payments')->get('paypal');

    same('PayPal', $method->info()['title']);
    contains('PayPal', $method->info()['description']);
    $app->settings()->set(PayPalSettings::TITLE, '  Paga con PayPal & tarjeta  ');
    same('Paga con PayPal & tarjeta', $method->info()['title']);
});

test('start(): la orden lleva el importe con dos decimales y punto, la moneda, el número de pedido y las direcciones de vuelta y de cancelación', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $action = $placed['action'];

    $calls = ppCalls($fake, 'POST', '/v2/checkout/orders');
    same(1, count($calls), 'una sola orden creada');
    $r = $calls[0];
    same('https://api-m.sandbox.paypal.com/v2/checkout/orders', $r->url);
    same('Bearer ' . PP_TOKEN, $r->headers['Authorization']);
    $b = ppBody($r);
    same('CAPTURE', $b['intent']);
    $unit = $b['purchase_units'][0];
    same(Money::decimal((int) $order['total']), $unit['amount']['value'], 'el importe exacto del pedido');
    check(preg_match('/^\d+\.\d{2}$/', $unit['amount']['value']) === 1, 'con punto y dos decimales: ' . $unit['amount']['value']);
    check((int) $order['total'] > 2000, 'y lleva el envío dentro');
    same('EUR', $unit['amount']['currency_code']);
    same($order['number'], $unit['custom_id'], 'la referencia propia es el número de pedido');
    same($order['number'], $unit['invoice_id']);
    $ctx = $b['payment_source']['paypal']['experience_context'];
    same('https://tienda.test/pago/paypal/vuelta/' . $placed['number'] . '/' . $placed['secret'], $ctx['return_url']);
    same('https://tienda.test/pago/paypal/cancelado/' . $placed['number'] . '/' . $placed['secret'], $ctx['cancel_url']);
    same('SET_PROVIDED_ADDRESS', $ctx['shipping_preference'], 'se manda la dirección que el cliente ya dio en la caja');
    same('Calle Mayor 1', $unit['shipping']['address']['address_line_1']);
    same('ES', $unit['shipping']['address']['country_code']);
    same('Madrid', $unit['shipping']['address']['admin_area_1'], 'la provincia viaja con su nombre, no con el código «28» con que se guarda');

    same(PaymentAction::REDIRECT, $action->type);
    same('https://www.sandbox.paypal.com/checkoutnow?token=' . $paypalOrder, $action->url, 'la acción es ir al enlace de aprobación');
});

test('start() apunta el intento pendiente con la orden de PayPal como referencia, sin datos del comprador ni secretos', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);

    $attempts = ppPayments($order, PayPalPayment::ATTEMPT);
    same(1, count($attempts));
    same(['paypal', $paypalOrder, 'pending', (int) $order['total'], 'EUR', true], [
        $attempts[0]['method'], $attempts[0]['reference'], $attempts[0]['status'], $attempts[0]['amount'], $attempts[0]['currency'], $attempts[0]['test'],
    ]);
    same('pending', $order['payment_status'], 'crear la orden no cobra nada');
    same('pending_payment', $order['status']);
    same([], ppPayments($order, 'charge'), 'ningún cobro todavía');
});

test('si PayPal rechaza crear la orden, la caja recibe un fallo con un mensaje para el cliente y sin secretos', function (): void {
    $app = ppApp();
    $fake = ppFake($app);
    $fake->createResponse = ppJson(422, [
        'name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed ' . PP_SECRET . ' ' . PP_TOKEN, 'debug_id' => 'dbg-422',
        'details' => [['issue' => 'CURRENCY_NOT_SUPPORTED']],
    ]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);

    $placed = ppPlace($app, new TestClient($app), $p);
    same(false, $placed['ok']);
    same(PaymentAction::FAILED, $placed['action']->type);
    contains('PayPal', $placed['error']);
    notContains(PP_SECRET, $placed['error']);
    notContains(PP_TOKEN, $placed['error']);
    notContains('CURRENCY_NOT_SUPPORTED', $placed['error'], 'el detalle técnico no se enseña al cliente');
    $order = $app->service('orders')->find((int) $placed['id']);
    same('failed', $order['payment_status'], 'el pedido queda fallido');
    same([], ppPayments($order, PayPalPayment::ATTEMPT), 'sin intento apuntado');

    $logged = json_encode($app->log()->records, JSON_UNESCAPED_UNICODE);
    contains('dbg-422', $logged, 'el detalle técnico va al registro');
    contains('CURRENCY_NOT_SUPPORTED', $logged);
    notContains(PP_SECRET, $logged);
    notContains(PP_TOKEN, $logged);
});

test('un fallo de red o una respuesta sin enlace de aprobación también acaban en un fallo para la caja', function (): void {
    $app = ppApp();
    $fake = ppFake($app);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);

    $fake->createResponse = new HttpException('cURL error 28: timed out with ' . PP_SECRET);
    $placed = ppPlace($app, new TestClient($app), $p);
    same(false, $placed['ok']);
    same(PaymentAction::FAILED, $placed['action']->type);
    notContains(PP_SECRET, $placed['error']);

    $fake->createResponse = ppJson(201, ['id' => 'PPORDER-X', 'status' => 'CREATED', 'links' => []]);
    $placed = ppPlace($app, new TestClient($app), $p);
    same(false, $placed['ok'], 'sin enlace no hay a dónde llevar al cliente');
    same(PaymentAction::FAILED, $placed['action']->type);
    notContains(PP_SECRET, json_encode($app->log()->records, JSON_UNESCAPED_UNICODE));
});

test('con el entorno live se usan las otras credenciales y el otro dominio, y el pedido no es de pruebas', function (): void {
    $app = ppApp([PayPalSettings::ENVIRONMENT => 'live']);
    $fake = ppFake($app);
    $method = $app->service('payments')->get('paypal');
    same(false, $method->isTest());
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);

    $placed = ppPlace($app, new TestClient($app), $p);
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
    $token = ppCalls($fake, 'POST', '/v1/oauth2/token')[0];
    same('https://api-m.paypal.com/v1/oauth2/token', $token->url);
    same('Basic ' . base64_encode(PP_LIVE_CLIENT . ':' . PP_LIVE_SECRET), $token->headers['Authorization'], 'las credenciales de producción');
    same(1, count(ppCalls($fake, 'POST', 'https://api-m.paypal.com/v2/checkout/orders')), 'la orden se crea en el dominio de producción');
    same(0, count(ppCalls($fake, 'POST', 'sandbox')), 'y nada va al sandbox');
    $attempt = ppAttempt($app, $placed);
    same(0, (int) $attempt['test'], 'el intento se apunta como real');

    // Con las credenciales del sandbox y el entorno live sin las suyas, no se ofrece.
    $app->settings()->set(PayPalSettings::CLIENT_ID_LIVE, '');
    same(false, $method->available(ppContext()));
});

test('la ficha de acceso se reutiliza: dos operaciones seguidas piden una sola autorización', function (): void {
    $app = ppApp();
    $fake = ppFake($app);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);

    $one = ppPlace($app, new TestClient($app), $p);
    $two = ppPlace($app, new TestClient($app), $p);
    check(!empty($one['ok']) && !empty($two['ok']), 'los dos pedidos se crean');
    same(2, count(ppCalls($fake, 'POST', '/v2/checkout/orders')), 'dos órdenes en PayPal');
    same(1, count(ppCalls($fake, 'POST', '/v1/oauth2/token')), 'una sola petición de autorización');

    // La ficha está en la caché del sitio, no en la base de datos.
    $key = 'paypal.token.sandbox.' . substr(hash('sha256', PP_CLIENT), 0, 16);
    $stored = $app->cache()->get($key);
    check(is_array($stored) && $stored['token'] === PP_TOKEN, 'la ficha está en la caché del sitio');
    foreach (['settings', 'order_payments', 'order_history'] as $table) {
        notContains(PP_TOKEN, json_encode($app->db()->select('SELECT * FROM {' . $table . '}'), JSON_UNESCAPED_UNICODE), 'ni en ' . $table);
    }
});

test('una petición web nueva reutiliza la ficha guardada, y cuando caduca pide otra', function (): void {
    $app = ppApp();
    $fake = ppFake($app);
    $settings = new PayPalSettings($app);

    $settings->client()->accessToken($app->clock()->now());
    $settings->client()->accessToken($app->clock()->now() + 600);
    same(1, count(ppCalls($fake, 'POST', '/v1/oauth2/token')), 'otra instancia del cliente usa la ficha de la caché');
    $settings->client()->accessToken($app->clock()->now() + 40000);
    same(2, count(ppCalls($fake, 'POST', '/v1/oauth2/token')), 'caducada, pide otra');
});
