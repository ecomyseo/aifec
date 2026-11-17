<?php
/**
 * AIFEC — pruebas de la página del pedido: acceso con la ficha, estados en palabras claras, refresco con tope mientras el
 * pago no se confirma, el indicador de «primera vez», seguimiento, descargas, direcciones, desglose y el enlace a la cuenta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Front\TotalsView;

require_once __DIR__ . '/FrontKit.php';

/**
 * Un pedido hecho de verdad con la caja del servicio. Devuelve [app, navegador, resultado de place()].
 *
 * @param array<string,mixed> $settings
 * @param array<string,mixed> $caja
 *
 * @return array{0:App,1:TestClient,2:array<string,mixed>}
 */
function frontOrderShop(array $settings = [], array $caja = []): array
{
    $app = frontShop(['settings' => $settings]);
    $web = new TestClient($app);
    $p = frontProduct($app);
    $placed = aifecOrderPlace($app, $web, [[$p, 0, 1]], $caja);
    check(!empty($placed['ok']), 'el pedido se crea');

    return [$app, $web, $placed];
}

/** La dirección de la página del pedido. @param array<string,mixed> $placed */
function frontOrderUrl(array $placed, array $extra = []): string
{
    return route('order.thanks', ['number' => (string) $placed['number'], 'secret' => (string) $placed['secret']] + $extra);
}

test('con la ficha mala o un número que no existe, la página del pedido da un 404', function (): void {
    [$app, $web, $placed] = frontOrderShop();

    same(200, $web->get(frontOrderUrl($placed))->status);
    same(404, $web->get(route('order.thanks', ['number' => (string) $placed['number'], 'secret' => 'ficha-inventada']))->status);
    same(404, $web->get(route('order.thanks', ['number' => '2099-000001', 'secret' => (string) $placed['secret']]))->status);
    // La ficha de otro pedido no abre este.
    $third = aifecOrderPlace($app, $web, [[1, 0, 1]]);
    same(404, $web->get(route('order.thanks', ['number' => (string) $placed['number'], 'secret' => (string) $third['secret']]))->status);
});

test('mientras el pago de una pasarela no se confirma la página se refresca sola y se para en el tope', function (): void {
    [$app, $web, $placed] = frontOrderShop();

    $first = $web->get(frontOrderUrl($placed));
    same(200, $first->status);
    contains('Esperando la confirmación del pago', $first->body);
    $meta = frontNode($first->body, '//meta[@http-equiv="refresh"]');
    check($meta !== null, 'lleva el meta refresh');
    same('5;url=' . frontOrderUrl($placed, ['r' => 1]), $meta->attributes->getNamedItem('content')->nodeValue);

    $eleven = $web->get(frontOrderUrl($placed, ['r' => 11]));
    same('5;url=' . frontOrderUrl($placed, ['r' => 12]), frontNode($eleven->body, '//meta[@http-equiv="refresh"]')->attributes->getNamedItem('content')->nodeValue, 'la refrescada número 12 es la última');

    $last = $web->get(frontOrderUrl($placed, ['r' => 12]));
    same(200, $last->status);
    check(frontNode($last->body, '//meta[@http-equiv="refresh"]') === null, 'en el tope deja de refrescarse');
    contains('está tardando más de lo normal', $last->body);
    $silly = $web->get(frontOrderUrl($placed, ['r' => 99999]));
    check(frontNode($silly->body, '//meta[@http-equiv="refresh"]') === null, 'un contador enorme tampoco refresca');
    $negative = $web->get(frontOrderUrl($placed, ['r' => -5]));
    same('5;url=' . frontOrderUrl($placed, ['r' => 1]), frontNode($negative->body, '//meta[@http-equiv="refresh"]')->attributes->getNamedItem('content')->nodeValue);

    // Cobrado: deja de refrescarse y dice que el pago llegó.
    $order = $app->service('orders')->find((int) $placed['id']);
    check($app->service('orders')->markPaid((int) $placed['id'], 'gw', 'PAY-9', (int) $order['total']), 'el cobro se apunta');
    $paid = $web->get(frontOrderUrl($placed));
    check(frontNode($paid->body, '//meta[@http-equiv="refresh"]') === null, 'cobrado no se refresca');
    contains('Pago recibido', $paid->body);
    notContains('Esperando la confirmación', $paid->body);
});

test('el indicador first es verdadero solo la primera vez que se enseña el pedido ya pagado', function (): void {
    [$app, $web, $placed] = frontOrderShop();
    $seen = [];
    $app->hooks()->slot('order.thanks', static function (array $order, bool $first) use (&$seen): string {
        $seen[] = [$order['number'], $first];

        return '<p data-slot="order.thanks"></p>';
    });

    $pending = $web->get(frontOrderUrl($placed));
    contains('data-slot="order.thanks"', $pending->body);
    $order = $app->service('orders')->find((int) $placed['id']);
    $app->service('orders')->markPaid((int) $placed['id'], 'gw', 'PAY-10', (int) $order['total']);
    $web->get(frontOrderUrl($placed));
    $web->get(frontOrderUrl($placed));
    // Otro navegador (otro dispositivo) tampoco repite la medición.
    (new TestClient($app))->get(frontOrderUrl($placed));

    same([[(string) $placed['number'], false], [(string) $placed['number'], true], [(string) $placed['number'], false], [(string) $placed['number'], false]], $seen);
    same(1, aifecOrderCount($app, 'order_history', "kind = 'system' AND to_value = 'thanks_shown' AND order_id = ?", [(int) $placed['id']]), 'la marca se apunta una sola vez');
});

test('el estado se cuenta en palabras claras: transferencia pendiente, pago recibido, enviado y cancelado', function (): void {
    $app = frontShop();
    $transfer = new AifecOrderPayment('transfer');
    $transfer->action = PaymentAction::instructions('<p>Ingresa el importe en la cuenta de la tienda.</p>');
    $app->service('payments')->register($transfer);
    $web = new TestClient($app);
    $p = frontProduct($app);

    $placed = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 1);
        $cart->setPaymentMethod('transfer');

        return $app->service('orders')->place(aifecOrderCheckout());
    });
    check(!empty($placed['ok']), 'el pedido por transferencia se crea');
    $html = $web->get(frontOrderUrl($placed))->body;
    contains('Pendiente de tu transferencia', $html);
    contains('Ingresa el importe en la cuenta de la tienda.', $html);
    contains('Hemos recibido tu pedido ' . $placed['number'] . '. Lo prepararemos en cuanto recibamos tu pago.', $html);
    check(frontNode($html, '//meta[@http-equiv="refresh"]') === null, 'una transferencia no se refresca');

    $order = $app->service('orders')->find((int) $placed['id']);
    $app->service('orders')->markPaid((int) $placed['id'], 'transfer', 'TR-1', (int) $order['total']);
    contains('Pago recibido', $web->get(frontOrderUrl($placed))->body);

    $app->service('orders')->setStatus((int) $placed['id'], 'shipped', '', null, false);
    $shipped = $web->get(frontOrderUrl($placed))->body;
    contains('Enviado', $shipped);

    $other = aifecOrderPlace($app, $web, [[$p, 0, 1]]);
    $app->service('orders')->cancel((int) $other['id'], 'prueba');
    $cancelled = $web->get(frontOrderUrl($other))->body;
    contains('Cancelado', $cancelled);
    contains('No se te ha cobrado nada', $cancelled);
});

test('el seguimiento enseña el código y un enlace solo si es una dirección web', function (): void {
    [$app, $web, $placed] = frontOrderShop();
    $order = $app->service('orders')->find((int) $placed['id']);
    $app->db()->update('carriers', ['tracking_url' => 'https://seguimiento.test/buscar?c={code}'], ['id' => (int) $order['carrier_id']]);
    $app->db()->update('orders', ['tracking_code' => 'AB 123/9', 'status' => 'shipped', 'payment_status' => 'paid'], ['id' => (int) $placed['id']]);

    $html = $web->get(frontOrderUrl($placed))->body;
    contains('Código de seguimiento: AB 123/9', $html);
    contains('href="https://seguimiento.test/buscar?c=AB%20123%2F9"', $html);
    contains('Transportista: Estándar', $html);

    $app->db()->update('carriers', ['tracking_url' => 'javascript:alert({code})'], ['id' => (int) $order['carrier_id']]);
    $unsafe = $web->get(frontOrderUrl($placed))->body;
    contains('Código de seguimiento: AB 123/9', $unsafe);
    notContains('javascript:', $unsafe, 'un enlace que no es web no se enseña');
});

test('las descargas solo salen si está pagado, el botón da un enlace nuevo y uno agotado no se ofrece', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    aifecOrderFile($app, $guia, 'extra.txt');
    $placed = aifecOrderPlace($app, $web, [[$guia, 0, 1]], ['billing' => ['first_name' => 'Ana', 'country' => 'ES']]);

    $unpaid = $web->get(frontOrderUrl($placed))->body;
    notContains('data-af-downloads', $unpaid, 'sin pagar no hay descargas');

    $order = $app->service('orders')->find((int) $placed['id']);
    $app->service('orders')->markPaid((int) $placed['id'], 'gw', 'PAY-11', (int) $order['total']);
    $html = $web->get(frontOrderUrl($placed))->body;
    contains('data-af-downloads', $html);
    contains('guia.txt', $html);
    contains('extra.txt', $html);
    $rows = $app->db()->select('SELECT id FROM {order_downloads} ORDER BY id');
    same(2, count($rows));

    $url = frontOrderUrl($placed) . '/download/' . (int) $rows[0]['id'];
    $r = $web->post($url);
    same(303, $r->status);
    check(preg_match('#/descarga/[a-f0-9]{48}$#', $r->headers['Location']) === 1, 'lleva al enlace nuevo');
    same(200, $web->follow()->status);

    // Agotada la primera: ya no se ofrece y pedirla no da enlace.
    $app->db()->update('order_downloads', ['max_downloads' => 1, 'downloads' => 1], ['id' => (int) $rows[0]['id']]);
    $after = $web->get(frontOrderUrl($placed))->body;
    contains('Esta descarga ya no está disponible', $after);
    $denied = $web->post($url);
    same(303, $denied->status);
    notContains('/descarga/', $denied->headers['Location']);
    contains('/pedido/', $denied->headers['Location']);

    // Una descarga que no es de este pedido, o una ficha mala, no dan nada.
    $other = aifecOrderPlace($app, $web, [[$guia, 0, 1]], ['billing' => ['first_name' => 'Luis', 'country' => 'ES']]);
    $foreign = $web->post(frontOrderUrl($other) . '/download/' . (int) $rows[1]['id']);
    notContains('/descarga/', $foreign->headers['Location']);
    same(404, $web->post(route('order.thanks', ['number' => (string) $placed['number'], 'secret' => 'mala']) . '/download/' . (int) $rows[1]['id'])->status);
    same(403, $web->post($url, [], ['sec-fetch-site' => 'cross-site'])->status, 'desde otra web, no');
});

test('la página enseña las líneas, las dos direcciones con el NIF solo en la de factura, y todo escapado', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    $p = frontProduct($app, ['name' => 'Mesa <b>"Roble"</b>']);
    $address = ['first_name' => 'Ana <i>', 'last_name' => 'Ruiz & Co', 'company' => 'Roble SL', 'vat_id' => 'B12345678', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'region' => '28', 'country' => 'ES', 'phone' => '999345678'];
    $placed = aifecOrderPlace($app, $web, [[$p, 0, 2]], ['billing' => $address, 'shipping' => ['vat_id' => ''] + $address, 'note' => 'Llamar <al> timbre']);
    check(!empty($placed['ok']), 'el pedido se crea');

    $html = $web->get(frontOrderUrl($placed))->body;
    contains('Mesa &lt;b&gt;&quot;Roble&quot;&lt;/b&gt;', $html);
    notContains('<b>"Roble"</b>', $html);
    contains('Ana &lt;i&gt; Ruiz &amp; Co', $html);
    contains('Llamar &lt;al&gt; timbre', $html);
    preg_match('/Dirección de envío.*?<\/address>/s', $html, $ship);
    preg_match('/Dirección de facturación.*?<\/address>/s', $html, $bill);
    check(isset($ship[0], $bill[0]), 'salen las dos direcciones');
    contains('28001 Madrid', $ship[0]);
    contains('España', $ship[0]);
    notContains('B12345678', $ship[0], 'el NIF no va en la dirección de envío');
    contains('B12345678', $bill[0], 'el NIF va en la de factura');
    contains('data-total="' . $app->service('orders')->find((int) $placed['id'])['total'] . '"', $html);
});

test('el desglose de un pedido suma al céntimo con y sin impuestos en el precio, con descuento, envío y recargo', function (): void {
    foreach ([true, false] as $inclusive) {
        $app = frontShop(['settings' => ['tax.prices_include_tax' => $inclusive]]);
        couponsMake($app, ['code' => 'OTONO', 'kind' => 'percent', 'percent' => 1000]);
        aifecOrderPayment($app)->fee = 300;
        $web = new TestClient($app);
        $a = frontProduct($app);
        $b = frontProduct($app, ['name' => 'Taza', 'price' => 1990, 'tax_class_id' => 'reduced', 'weight' => 300]);
        $placed = aifecCartRun($web, static function ($cart, App $app) use ($a, $b): array {
            $cart->add($a, 0, 2);
            $cart->add($b, 0, 3);
            $cart->applyCoupon('OTONO');
            $cart->setPaymentMethod('gw');

            return $app->service('orders')->place(aifecOrderCheckout());
        });
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
        $order = $app->service('orders')->find((int) $placed['id']);

        $v = TotalsView::forOrder($app, $order);
        $sum = $v['subtotal'];
        foreach ($v['rows'] as $row) {
            if ($row['cents'] !== null) {
                $sum += $row['cents'];
            }
        }
        $mode = $inclusive ? 'precios con impuestos' : 'precios sin impuestos';
        same($order['total'], $v['total'], $mode);
        same($order['total'], $sum, 'las filas suman el total: ' . $mode);
        $kinds = array_column($v['rows'], 'kind');
        check(in_array('discount', $kinds, true) && in_array('shipping', $kinds, true) && in_array('fee', $kinds, true) && in_array('tax', $kinds, true), 'salen descuento, envío, recargo e impuestos: ' . $mode);
        $html = $web->get(frontOrderUrl($placed))->body;
        same($order['total'], frontTotal($html));
        contains($inclusive ? 'Incluye' : 'Impuestos:', $html);
    }
});

test('el enlace a la cuenta cambia según quién mira: invitado, dueño con cuenta sin entrar y dueño dentro', function (): void {
    $app = frontShop();
    aifecCartCustomer($app);
    $p = frontProduct($app);

    $guest = new TestClient($app);
    $guestOrder = aifecOrderPlace($app, $guest, [[$p, 0, 1]], ['email' => 'otra@cliente.test']);
    contains('Crea una cuenta para seguir tus pedidos', $guest->get(frontOrderUrl($guestOrder))->body);
    contains(route('account.register'), $guest->get(frontOrderUrl($guestOrder))->body);

    $owner = new TestClient($app);
    frontLogin($owner);
    $ownerOrder = aifecOrderPlace($app, $owner, [[$p, 0, 1]]);
    $inside = $owner->get(frontOrderUrl($ownerOrder))->body;
    contains('Verlo en mi cuenta', $inside);
    contains(route('account.order', ['number' => (string) $ownerOrder['number']]), $inside);

    $outside = (new TestClient($app))->get(frontOrderUrl($ownerOrder))->body;
    contains('Entra para seguir tus pedidos', $outside);
    contains('next=', $outside);
});

test('la página del pedido nunca se guarda en caché ni se indexa, y la ficha no sale en el HTML', function (): void {
    [$app, $web, $placed] = frontOrderShop();
    $r = $web->get(frontOrderUrl($placed));

    same(200, $r->status);
    contains('no-store', $r->headers['Cache-Control']);
    same(0, $r->cacheTtl);
    contains('noindex', $r->body);
    $canonical = frontNode($r->body, '//link[@rel="canonical"]');
    check($canonical !== null, 'tiene canónica');
    contains('af-type-order', $r->body);
    $json = $app->service('orders')->find((int) $placed['id']);
    check(!array_key_exists('secret', $json), 'el pedido que se pasa a la plantilla no lleva la ficha');
});
