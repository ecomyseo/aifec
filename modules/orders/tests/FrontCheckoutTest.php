<?php
/**
 * AIFEC — pruebas de la caja: el recorrido completo sin JavaScript, los totales, los errores junto a su campo, los
 * accesos, lo digital, las acciones de cada forma de pago, la pulsación doble, los envíos desde otra web, la actualización
 * con JSON, las direcciones guardadas y que ninguna página se guarde en caché ni se indexe. Se recorre como un navegador:
 * se pide la página, se recogen sus campos tal como los mandaría y se envían.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;

require_once __DIR__ . '/FrontKit.php';

/** Una tienda con un producto y dos unidades en el carrito. @return array{0:App,1:TestClient,2:int} */
function frontCheckoutShop(array $settings = []): array
{
    $app = frontShop(['settings' => $settings]);
    $web = new TestClient($app);
    $p = frontProduct($app);
    frontAdd($web, $p, 2);

    return [$app, $web, $p];
}

test('recorrido sin JavaScript: carrito, caja, Actualizar con otro código postal, enviar y página del pedido', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-1');

    $cart = $web->get('/carrito');
    same(200, $cart->status);
    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    $last = -1;
    foreach (['af-co-contact', 'af-co-delivery', 'af-co-shipping', 'af-co-payment', 'af-co-review'] as $section) {
        contains('id="' . $section . '"', $page->body, 'la sección ' . $section);
        $at = (int) strpos($page->body, 'id="' . $section . '"');
        check($at > $last, 'la sección ' . $section . ' va después de la anterior');
        $last = $at;
    }
    notContains('af-steps', $page->body, 'una sola página, sin pasos');
    contains('Pasarela de pruebas', $page->body);
    same(24200 + 495, frontTotal($page->body), 'envío a la península');

    // «Actualizar» con un código postal de Canarias: cambian el envío y el total.
    $web->post('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => '35001']), $page->body));
    $update = $web->follow();
    same(200, $update->status);
    contains('Los precios se han actualizado', $update->body);
    same(24200 + 1595, frontTotal($update->body), 'envío a Canarias');
    contains('value="Madrid"', $update->body, 'lo escrito se conserva');
    contains('value="35001"', $update->body);
    contains('name="shipping[region]"', $update->body);

    // Enviar el pedido con lo que dejó la actualización.
    $place = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['shipping[postcode]' => '35001']), $update->body));
    same(303, $place->status);
    [$number, $secret] = frontOrderPath($place);
    $order = $app->service('orders')->findBySecret($number, $secret);
    check($order !== null, 'el pedido existe y la ficha de la dirección da acceso a él');
    same('paid', $order['payment_status']);
    same(24200 + 1595, $order['total']);
    same(1595, $order['shipping_total']);
    same('Estándar', $order['carrier_title']);
    same('35', $order['shipping']['region'], 'la provincia sale del código postal');
    same('ana@cliente.test', $order['email']);
    same(1, aifecOrderCount($app, 'orders'));

    $thanks = $web->follow();
    same(200, $thanks->status);
    contains($number, $thanks->body);
    contains($app->i18n()->money($order['total'], 'EUR'), $thanks->body);
    contains('Pago recibido', $thanks->body);
    contains('Mesa de roble', $thanks->body);
});

test('el total del botón, el del resumen y el del pedido creado son la misma cifra', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->fee = 300;
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-2');

    $page = $web->get('/finalizar-compra');
    $button = frontButton($page->body);
    same($button, frontTotal($page->body), 'botón y resumen');
    same(24200 + 495 + 300, $button, 'artículos, envío y recargo de pago');
    contains($app->i18n()->money($button, 'EUR'), $page->body);
    preg_match('/data-af-checkout-submit[^>]*>([^<]*)</', $page->body, $label);
    contains($app->i18n()->money($button, 'EUR'), $label[1], 'el botón enseña el importe');
    preg_match('/name="expected_total" value="(\d+)"/', $page->body, $expected);
    same($button, (int) $expected[1], 'el formulario lleva el total que se enseñó');

    $place = $web->post('/finalizar-compra/place', frontForm($web, frontFill(), $page->body));
    [$number, $secret] = frontOrderPath($place);
    $order = $app->service('orders')->findBySecret($number, $secret);
    same($button, $order['total'], 'el pedido cobra lo que decía el botón');
    same(300, $order['fee_total']);
    same($button, frontTotal($web->follow()->body), 'y la página del pedido enseña lo mismo');
});

test('cada error de la caja sale junto a su campo, lo escrito se conserva y no se crea ningún pedido', function (): void {
    [$app, $web] = frontCheckoutShop();
    $page = $web->get('/finalizar-compra');
    $post = frontForm($web, ['email' => '', 'shipping[city]' => 'Madrid & "Sol"', 'note' => 'Llamar <al> timbre', 'accept_terms' => '1'], $page->body);

    $r = $web->post('/finalizar-compra/place', $post);
    same(422, $r->status);
    foreach (['af-co-email', 'af-co-ship-first', 'af-co-ship-line1', 'af-co-ship-postcode'] as $field) {
        check(frontNode($r->body, '//span[@id="' . $field . '-error"]') !== null, 'el error de ' . $field . ' sale junto a su campo');
    }
    check(frontNode($r->body, '//span[@id="af-co-ship-city-error"]') === null, 'un campo bien escrito no lleva error');
    contains('Revisa los campos marcados', $r->body);
    foreach (['/checkout' => '/finalizar-compra', '/caja' => '/finalizar-compra', '/cart' => '/carrito', '/cesta' => '/carrito'] as $alias => $to) {
        $go = (new TestClient($app))->get($alias);
        same(301, $go->status, $alias . ' lleva a la tienda');
        same($to, $go->headers['Location'] ?? '', $alias);
    }
    check(preg_match('/Revisa los campos marcados\.\s+Escribe un correo/u', $r->body) === 1, 'y el aviso dice qué falla, no solo que falla algo');
    contains('value="Madrid &amp; &quot;Sol&quot;"', $r->body, 'lo escrito vuelve escapado');
    contains('Llamar &lt;al&gt; timbre', $r->body);
    notContains('Llamar <al>', $r->body);
    same(0, aifecOrderCount($app, 'orders'), 'sin datos no hay pedido');
    contains('no-store', $r->headers['Cache-Control']);
});

test('sin aceptar las condiciones no hay pedido y el aviso sale junto a la casilla', function (): void {
    [$app, $web] = frontCheckoutShop();
    $r = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['accept_terms' => null])));

    same(422, $r->status);
    check(frontNode($r->body, '//span[@id="af-co-terms-error"]') !== null, 'el error de las condiciones');
    contains('Tienes que aceptar los términos y condiciones', $r->body);
    same(0, aifecOrderCount($app, 'orders'));
});

test('sin elegir forma de pago el error sale en su sección y la dirección escrita se conserva', function (): void {
    [$app, $web] = frontCheckoutShop();
    $page = $web->get('/finalizar-compra');
    $app->db()->update('carts', ['payment_method' => null], ['id' => 1]);
    $post = frontForm($web, frontFill(['payment_method' => null, 'expected_total' => null]), $page->body);

    $r = $web->post('/finalizar-compra/place', $post);
    same(422, $r->status);
    contains('Elige una forma de pago', $r->body);
    contains('value="Calle Mayor 1"', $r->body);
    same(0, aifecOrderCount($app, 'orders'));
});

test('un código postal que no es de ese país y una empresa sin NIF se avisan junto a su campo', function (): void {
    [$app, $web] = frontCheckoutShop();

    $bad = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['shipping[postcode]' => '99999'])));
    same(422, $bad->status);
    check(frontNode($bad->body, '//span[@id="af-co-ship-postcode-error"]') !== null, 'el código postal no vale');
    contains('Escribe un código postal válido', $bad->body);

    $company = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['shipping[company]' => 'Muebles Roble SL'])));
    same(422, $company->status);
    check(frontNode($company->body, '//span[@id="af-co-ship-vat-error"]') !== null, 'una empresa necesita NIF');

    $ok = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['shipping[company]' => 'Muebles Roble SL', 'shipping[vat_id]' => 'B12345678'])));
    same(303, $ok->status);
    $order = $app->service('orders')->find(1);
    same('B12345678', $order['billing']['vat_id'], 'el NIF va a los datos de factura');
});

test('con el NIF obligatorio, la factura a otros datos tiene sus propios campos y errores', function (): void {
    [$app, $web] = frontCheckoutShop(['orders.require_vat_id' => true]);

    $noVat = $web->post('/finalizar-compra/place', frontForm($web, frontFill()));
    same(422, $noVat->status);
    check(frontNode($noVat->body, '//span[@id="af-co-ship-vat-error"]') !== null, 'sin NIF, el error sale en la entrega');

    $other = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['billing_other' => '1', 'shipping[vat_id]' => 'B12345678'])));
    same(422, $other->status);
    check(frontNode($other->body, '//span[@id="af-co-bill-first-error"]') !== null, 'la factura a otros datos sin nombre');
    check(frontNode($other->body, '//span[@id="af-co-bill-line1-error"]') !== null, 'ni dirección');
    check(frontNode($other->body, '//div[@id="af-co-billing-box"]')->attributes->getNamedItem('hidden') === null, 'el bloque de factura se ve');

    $ok = $web->post('/finalizar-compra/place', frontForm($web, frontFill([
        'billing_other' => '1', 'billing[first_name]' => 'Muebles', 'billing[last_name]' => 'Roble SL', 'billing[company]' => 'Muebles Roble SL', 'billing[vat_id]' => 'B12345678',
        'billing[line1]' => 'Calle Fábrica 4', 'billing[postcode]' => '08001', 'billing[city]' => 'Barcelona', 'billing[country]' => 'ES',
    ])));
    same(303, $ok->status);
    $order = $app->service('orders')->find(1);
    same('Calle Fábrica 4', $order['billing']['line1']);
    same('Calle Mayor 1', $order['shipping']['line1'], 'el envío va a la dirección de entrega');
});

test('un carrito vacío manda de la caja al carrito, y enviar la caja sin carrito tampoco crea nada', function (): void {
    $app = frontShop();
    $web = new TestClient($app);

    $r = $web->get('/finalizar-compra');
    same(302, $r->status);
    same(route('cart'), $r->headers['Location']);
    $post = $web->post('/finalizar-compra/place', ['email' => 'ana@cliente.test', 'accept_terms' => '1']);
    same(303, $post->status);
    same(route('cart'), $post->headers['Location']);
    $update = $web->postJson('/finalizar-compra/update', []);
    same(409, $update->status);
    same(route('cart'), $web->json()['redirect']);
    same(0, aifecOrderCount($app, 'orders'));
});

test('con los invitados apagados la caja manda a entrar con la vuelta a la caja, y con la cuenta abierta se abre', function (): void {
    [$app, $web, $p] = frontCheckoutShop(['orders.guest_checkout' => false]);

    $r = $web->get('/finalizar-compra');
    same(302, $r->status);
    contains(route('account.login'), $r->headers['Location']);
    contains('next=' . rawurlencode(route('checkout')), $r->headers['Location']);
    $place = $web->post('/finalizar-compra/place', ['email' => 'ana@cliente.test', 'accept_terms' => '1']);
    same(303, $place->status, 'tampoco se puede hacer el pedido sin entrar');
    contains(route('account.login'), $place->headers['Location']);

    aifecCartCustomer($app);
    frontLogin($web);
    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    contains('Has entrado como ana@cliente.test', $page->body);
    same(0, aifecOrderCount($app, 'orders'));
});

test('solo digitales: ni entrega ni envío, datos de facturación, y la página del pedido pagado enseña las descargas', function (): void {
    $app = frontShop();
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-3');
    $web = new TestClient($app);
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    frontAdd($web, $guia, 1);

    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    notContains('id="af-co-delivery"', $page->body);
    notContains('id="af-co-shipping"', $page->body);
    notContains('name="shipping[', $page->body);
    notContains('name="carrier_id"', $page->body);
    contains('id="af-co-billing"', $page->body);
    contains('name="billing[first_name]"', $page->body);
    same(1500, frontTotal($page->body), 'sin envío en el total');

    $place = $web->post('/finalizar-compra/place', frontForm($web, ['email' => 'ana@cliente.test', 'billing[first_name]' => 'Ana', 'billing[last_name]' => 'Ruiz', 'accept_terms' => '1'], $page->body));
    same(303, $place->status);
    [$number] = frontOrderPath($place);
    $thanks = $web->follow();
    same(200, $thanks->status);
    contains('data-af-downloads', $thanks->body);
    contains('guia.txt', $thanks->body);
    $form = frontNode($thanks->body, '//li[@data-af-download]//form');
    check($form !== null, 'la descarga trae su botón');

    $download = $web->post($form->attributes->getNamedItem('action')->nodeValue);
    same(303, $download->status);
    check(preg_match('#/descarga/[a-f0-9]{48}$#', $download->headers['Location']) === 1, 'lleva al enlace de descarga: ' . $download->headers['Location']);
    $file = $web->follow();
    same(200, $file->status);
    same('Contenido de guia.txt', (string) file_get_contents((string) $file->file));
    same($number, $app->service('orders')->find(1)['number']);
});

test('acción de formulario: una página intermedia con los campos ocultos escapados que se envía sola', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->action = PaymentAction::form('https://pasarela.test/pagar', ['Ds_Merchant' => 'a"b<c>', 'token' => 'x&y']);

    $r = $web->post('/finalizar-compra/place', frontForm($web, frontFill()));
    same(200, $r->status);
    contains('action="https://pasarela.test/pagar"', $r->body);
    contains('name="Ds_Merchant" value="a&quot;b&lt;c&gt;"', $r->body);
    contains('name="token" value="x&amp;y"', $r->body);
    notContains('a"b<c>', $r->body);
    contains('data-af-autopost', $r->body);
    contains('pay-redirect.js', $r->body);
    contains('type="submit"', $r->body, 'el botón visible por si no hay JavaScript');
    contains('no-store', $r->headers['Cache-Control']);
    same(1, aifecOrderCount($app, 'orders'));
    same('pending', $app->service('orders')->find(1)['payment_status']);
});

test('acción de redirección: se lleva al cliente a la pasarela; una dirección que no es web se rechaza', function (): void {
    [$app, $web] = frontCheckoutShop();

    $r = $web->post('/finalizar-compra/place', frontForm($web, frontFill()));
    same(303, $r->status);
    same('https://pasarela.test/pagar/' . $app->service('orders')->find(1)['number'], $r->headers['Location']);

    [$app2, $web2] = frontCheckoutShop();
    aifecOrderPayment($app2)->action = PaymentAction::redirect('javascript:alert(1)');
    $bad = $web2->post('/finalizar-compra/place', frontForm($web2, frontFill()));
    same(303, $bad->status);
    contains('/pedido/', $bad->headers['Location'], 'una dirección peligrosa no se sigue: se queda en la página del pedido');
    notContains('javascript', $bad->headers['Location']);
});

test('acción de instrucciones: la página del pedido enseña cómo pagar, solo con etiquetas de texto', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->action = PaymentAction::instructions('<p onclick="x()">Haz una transferencia a ES00 0000 0000</p><script>alert(1)</script><ul><li>Concepto: <strong>pedido</strong></li></ul>');

    $place = $web->post('/finalizar-compra/place', frontForm($web, frontFill()));
    same(303, $place->status);
    $thanks = $web->follow();
    same(200, $thanks->status);
    contains('data-af-instructions', $thanks->body);
    contains('<p>Haz una transferencia a ES00 0000 0000</p>', $thanks->body);
    contains('<li>Concepto: <strong>pedido</strong></li>', $thanks->body);
    notContains('<script>', $thanks->body);
    notContains('alert(1)', $thanks->body);
    notContains('onclick', $thanks->body);
    contains('Pendiente de tu pago', $thanks->body);
    same('on_hold', $app->service('orders')->find(1)['status']);
});

test('si el cobro no puede empezar, el cliente vuelve a la caja con el aviso, el carrito y lo escrito', function (): void {
    [$app, $web, $p] = frontCheckoutShop();
    aifecOrderPayment($app)->throwOnStart = true;

    $r = $web->post('/finalizar-compra/place', frontForm($web, frontFill()));
    same(303, $r->status);
    same(route('checkout'), $r->headers['Location']);
    $page = $web->follow();
    same(200, $page->status);
    contains('No se ha podido empezar el pago', $page->body);
    contains('value="Calle Mayor 1"', $page->body, 'lo escrito sigue ahí');
    same(24200 + 495, frontTotal($page->body), 'el carrito vuelve a estar libre');
    same('failed', $app->service('orders')->find(1)['status']);
    same(10, aifecOrderStock($app, $p), 'las existencias vuelven');
});

test('una pulsación doble no crea dos pedidos: el segundo envío lleva a la página del primero', function (): void {
    [$app, $web, $p] = frontCheckoutShop();
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-4');
    $post = frontForm($web, frontFill());

    $first = $web->post('/finalizar-compra/place', $post);
    same(303, $first->status);
    $second = $web->post('/finalizar-compra/place', $post);
    same(303, $second->status);
    same(frontOrderPath($first), frontOrderPath($second), 'las dos van al mismo pedido');
    same(1, aifecOrderCount($app, 'orders'));
    same(8, aifecOrderStock($app, $p), 'las existencias se descontaron una vez');

    // Pasado un buen rato ya no es una pulsación doble: sin carrito, la caja manda al carrito.
    $app->clock()->advance(3600);
    $late = $web->post('/finalizar-compra/place', $post);
    same(route('cart'), $late->headers['Location']);
});

test('un envío desde otra web se rechaza con un 403 y no toca nada', function (): void {
    [$app, $web] = frontCheckoutShop();
    $post = frontForm($web, frontFill());

    foreach (['/finalizar-compra/place', '/finalizar-compra/update'] as $path) {
        $r = $web->post($path, $post, ['sec-fetch-site' => 'cross-site']);
        same(403, $r->status, $path);
    }
    $viaOrigin = $web->post('/finalizar-compra/place', $post, ['origin' => 'https://otra-web.test', 'sec-fetch-site' => 'cross-site']);
    same(403, $viaOrigin->status);
    same(0, aifecOrderCount($app, 'orders'));
    $own = $web->post('/finalizar-compra/place', $post, ['sec-fetch-site' => 'same-origin']);
    same(303, $own->status, 'desde la propia web sí');
});

test('la ruta de actualización con JSON devuelve los tres fragmentos, el total y el texto del botón', function (): void {
    [$app, $web] = frontCheckoutShop();
    $post = frontForm($web, frontFill(['shipping[postcode]' => '35001']));

    $r = $web->postJson('/finalizar-compra/update', $post);
    same(200, $r->status);
    contains('no-store', $r->headers['Cache-Control']);
    $json = $web->json();
    same(true, $json['ok']);
    same(['shipping', 'payment', 'summary'], array_keys($json['html']));
    foreach ($json['html'] as $name => $html) {
        check(is_string($html) && trim($html) !== '', 'el fragmento ' . $name . ' trae HTML');
    }
    same(24200 + 1595, $json['total']);
    same($app->i18n()->money(24200 + 1595, 'EUR'), $json['total_formatted']);
    contains('Pagar ' . $app->i18n()->money(24200 + 1595, 'EUR'), $json['button']);
    same(24200 + 1595, frontTotal($json['html']['summary']), 'el resumen es el mismo total');
    contains($app->i18n()->money(1595, 'EUR'), $json['html']['shipping']);
    contains('name="payment_method"', $json['html']['payment']);
    notContains('<html', $json['html']['summary'], 'son fragmentos, no páginas');

    // Lo escrito se guardó en el carrito: la caja, al volver a abrirla, lo trae.
    $page = $web->get('/finalizar-compra');
    contains('value="35001"', $page->body);
    same(24200 + 1595, frontTotal($page->body));
});

test('si al guardar lo escrito el total cambia, no se crea el pedido: se enseña el total nuevo y se confirma', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-5');
    $page = $web->get('/finalizar-compra');
    same(24200 + 495, frontButton($page->body));

    // Se cambia el código postal sin pulsar «Actualizar»: el formulario aún lleva el total de la península.
    $stale = frontForm($web, frontFill(['shipping[postcode]' => '35001']), $page->body);
    $r = $web->post('/finalizar-compra/place', $stale);
    same(200, $r->status);
    contains('El total ha cambiado', $r->body);
    same(0, aifecOrderCount($app, 'orders'), 'no se cobra un total que no se enseñó');
    same(24200 + 1595, frontButton($r->body));

    $again = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['shipping[postcode]' => '35001']), $r->body));
    same(303, $again->status);
    same(24200 + 1595, $app->service('orders')->find(1)['total']);
});

test('con el recargo de una forma de pago, su tarjeta lo enseña y el desglose lo lleva como una fila más', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->fee = 300;

    $page = $web->get('/finalizar-compra');
    contains('+ ' . $app->i18n()->money(300, 'EUR'), $page->body, 'la tarjeta del pago lleva su recargo');
    contains('af-totals-fee', $page->body);
    contains('Recargo de pago: Pasarela de pruebas', $page->body);
});

test('un cliente con cuenta elige entre sus direcciones, no ve las de otro y la guardada manda sobre lo escrito', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-6');
    $ana = aifecCartCustomer($app);
    $luis = aifecCartCustomer($app, 'luis@cliente.test', ['first_name' => 'Luis']);
    $customers = $app->service('customers');
    $own = $customers->saveAddress($ana, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Palmera 5', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES', 'phone' => '999345678']);
    $other = $customers->saveAddress($luis, ['first_name' => 'Luis', 'last_name' => 'Gil', 'line1' => 'Calle Ajena 9', 'postcode' => '08001', 'city' => 'Barcelona', 'country' => 'ES', 'phone' => '999345679']);
    frontLogin($web);

    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    contains('data-af-saved-address="' . $own . '"', $page->body);
    contains('Calle Palmera 5', $page->body);
    notContains('Calle Ajena 9', $page->body, 'la dirección de otro cliente no sale');
    notContains('data-af-saved-address="' . $other . '"', $page->body);
    check(frontNode($page->body, '//input[@name="address_id"][@value="' . $own . '"][@checked]') !== null, 'la de por defecto sale elegida');
    same(24200 + 1595, frontTotal($page->body), 'el envío ya es el de la dirección elegida');

    // Mandar el identificador de la dirección de otro cliente no sirve: se usa lo escrito.
    $post = frontForm($web, frontFill(['address_id' => (string) $other, 'expected_total' => null]), $page->body);
    $r = $web->post('/finalizar-compra/place', $post);
    same(303, $r->status);
    $order = $app->service('orders')->find(1);
    same('Calle Mayor 1', $order['shipping']['line1'], 'no se usó la dirección ajena');
    same($ana, $order['customer_id']);
    same('ana@cliente.test', $order['email']);

    // Con la suya elegida, el pedido lleva la guardada aunque se haya escrito otra cosa.
    $web2 = new TestClient($app);
    frontAdd($web2, 1, 1);
    frontLogin($web2);
    $page2 = $web2->get('/finalizar-compra');
    $post2 = frontForm($web2, ['address_id' => (string) $own, 'shipping[line1]' => 'Escrita pero ignorada', 'accept_terms' => '1'], $page2->body);
    same(303, $web2->post('/finalizar-compra/place', $post2)->status);
    $order2 = $app->service('orders')->find(2);
    same('Calle Palmera 5', $order2['shipping']['line1']);
    same('35', $order2['shipping']['region']);
});

test('la atribución de la visita que guardó otro módulo en la sesión llega al pedido', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecCartRun($web, static function ($cart, App $app): void {
        $app->session('customer')->set('attribution', ['utm_source' => 'boletin', 'utm_campaign' => 'otono']);
    });

    $r = $web->post('/finalizar-compra/place', frontForm($web, frontFill()));
    same(303, $r->status);
    same(['utm_source' => 'boletin', 'utm_campaign' => 'otono'], $app->service('orders')->find(1)['attribution']);
    same('web', $app->service('orders')->find(1)['source']);
});

test('la caja, su actualización y la página del pedido no se indexan ni se guardan en caché; el guion solo está en la caja', function (): void {
    [$app, $web] = frontCheckoutShop();
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-7');
    $page = $web->get('/finalizar-compra');
    contains('noindex', $page->body);
    contains('no-store', $page->headers['Cache-Control']);
    same(0, $page->cacheTtl);
    contains('checkout.js', $page->body);
    contains('data-af-update-button', $page->body, 'sin JavaScript queda el botón Actualizar');
    contains('formnovalidate', $page->body);
    notContains('checkout.js', $web->get('/carrito')->body, 'el guion no se carga fuera de la caja');

    $web->post('/finalizar-compra/update', frontForm($web, frontFill(), $page->body));
    $update = $web->follow();
    contains('no-store', $update->headers['Cache-Control']);

    $place = $web->post('/finalizar-compra/place', frontForm($web, frontFill(), $page->body));
    [$number, $secret] = frontOrderPath($place);
    $thanks = $web->get(route('order.thanks', ['number' => $number, 'secret' => $secret]));
    contains('noindex', $thanks->body);
    contains('no-store', $thanks->headers['Cache-Control']);
    same(0, $thanks->cacheTtl);
});

test('los huecos de la caja y de la página del pedido se pintan, y un producto con signos sale escapado en el resumen', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app, ['name' => 'Silla <i>& "Co"</i>']), 1);
    foreach (['checkout.before', 'checkout.review.after', 'checkout.after'] as $slot) {
        $app->hooks()->slot($slot, static function (array $summary) use ($slot): string {
            return '<p data-slot="' . $slot . '">' . count($summary['lines']) . '</p>';
        });
    }

    $page = $web->get('/finalizar-compra');
    foreach (['checkout.before', 'checkout.review.after', 'checkout.after'] as $slot) {
        contains('data-slot="' . $slot . '"', $page->body);
    }
    notContains('<i>& "Co"</i>', $page->body);
    contains('Silla &lt;i&gt;&amp; &quot;Co&quot;&lt;/i&gt;', $page->body);
});

test('el botón dice «Pagar» con el importe, o «Hacer el pedido» si la forma de pago es una transferencia o un contra reembolso', function (): void {
    [$app, $web] = frontCheckoutShop();
    $app->service('payments')->register(new AifecOrderPayment('transfer'));
    $page = $web->get('/finalizar-compra');
    $total = $app->i18n()->money(24200 + 495, 'EUR');
    contains('Pagar ' . $total, $page->body);

    $json = $web->postJson('/finalizar-compra/update', frontForm($web, frontFill(['payment_method' => 'transfer']), $page->body));
    same(200, $json->status);
    same('Hacer el pedido', $web->json()['button']);
    same(24200 + 495, $web->json()['total'], 'el importe no cambia: solo el texto');

    $back = $web->postJson('/finalizar-compra/update', frontForm($web, frontFill(['payment_method' => 'gw']), $page->body));
    contains('Pagar ' . $total, $web->json()['button']);
});

test('la lista de países es la de las zonas de envío y la provincia cambia de lista a texto según el país', function (): void {
    [$app, $web] = frontCheckoutShop();
    $fr = shippingAddZone($app, 'Francia', [['country' => 'FR']], 20);
    shippingAddRate($app, (int) $app->db()->value("SELECT id FROM {carriers} WHERE code = 'std'"), $fr, 'weight', 0, 0, 1995);

    $page = $web->get('/finalizar-compra');
    $countries = [];
    foreach (frontNodes($page->body, '//select[@id="af-co-ship-country"]/option') as $option) {
        $countries[] = $option->attributes->getNamedItem('value')->nodeValue;
    }
    same(['ES', 'FR'], $countries, 'solo los países con zona de envío');
    check(frontNode($page->body, '//select[@id="af-co-ship-region-es"][not(@disabled)]') !== null, 'en España la provincia es una lista');
    check(frontNode($page->body, '//input[@id="af-co-ship-region"][@disabled]') !== null, 'y el texto no se envía');
    check(frontNode($page->body, '//div[@data-af-region-other][@hidden]') !== null, 'el texto está oculto');

    $web->post('/finalizar-compra/update', frontForm($web, ['shipping[country]' => 'FR', 'shipping[postcode]' => '75001', 'shipping[region]' => 'IDF'], $page->body));
    $update = $web->follow();
    same(200, $update->status);
    same(24200 + 1995, frontTotal($update->body), 'el envío es el de Francia');
    check(frontNode($update->body, '//select[@id="af-co-ship-region-es"][@disabled]') !== null, 'fuera de España la lista no se envía');
    check(frontNode($update->body, '//input[@id="af-co-ship-region"][not(@disabled)][@value="IDF"]') !== null, 'la provincia es un texto y conserva lo escrito');

    // Un pedido solo digital puede facturarse a cualquier país: la lista no se limita a las zonas.
    $web2 = new TestClient($app);
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    frontAdd($web2, $guia, 1);
    $options = frontNodes($web2->get('/finalizar-compra')->body, '//select[@id="af-co-bill-country"]/option');
    check(count($options) > 100, 'lo digital se factura a cualquier país');
});

