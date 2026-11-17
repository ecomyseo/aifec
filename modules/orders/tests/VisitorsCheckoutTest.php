<?php
/**
 * AIFEC — pruebas de lo que encontraron los visitantes en el carrito y la caja (P106): cada error con el nombre de su campo,
 * lo obligatorio anunciado en el HTML, los grupos de entrega y de factura con nombre, el total del carrito que dice lo mismo
 * que summary.json, el envío estimado de la caja, la cantidad negativa, el cierre de sesión solo para quien ha entrado, el
 * aviso de una dirección guardada sin tarifa, la provincia que se deduce del código postal y la caja que vuelve a su dirección
 * tras «Actualizar» sin JavaScript.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/FrontKit.php';

/** Una tienda con un producto y dos unidades en el carrito. @return array{0:App,1:TestClient,2:int} */
function vcShop(array $settings = []): array
{
    $app = frontShop(['settings' => $settings]);
    $web = new TestClient($app);
    $p = frontProduct($app);
    frontAdd($web, $p, 2);

    return [$app, $web, $p];
}

/** Lo que dice un nodo (texto), o '' si no existe. */
function vcText(string $html, string $xpath): string
{
    $node = frontNode($html, $xpath);

    return $node !== null ? trim(preg_replace('/\s+/', ' ', $node->textContent)) : '';
}

test('(1) con la caja casi vacía, cada error nombra su campo: el nombre de la entrega no habla de la factura y está asociado a su control', function (): void {
    [$app, $web] = vcShop();
    $post = frontForm($web, ['email' => 'ana@cliente.test', 'accept_terms' => '1']);
    $r = $web->post('/finalizar-compra/place', $post);
    same(422, $r->status);
    notContains('Escribe el nombre para la factura', $r->body, 'sin factura aparte, el nombre de la entrega no es «de la factura»');
    same('Escribe el nombre', vcText($r->body, '//span[@id="af-co-ship-first-error"]'));
    $input = frontNode($r->body, '//input[@id="af-co-ship-first"]');
    check($input instanceof DOMElement, 'el campo existe');
    same('true', $input->getAttribute('aria-invalid'));
    contains('af-co-ship-first-error', $input->getAttribute('aria-describedby'));
    same('Escribe la dirección', vcText($r->body, '//span[@id="af-co-ship-line1-error"]'));
    same('Escribe el código postal', vcText($r->body, '//span[@id="af-co-ship-postcode-error"]'));
    same('Escribe la población', vcText($r->body, '//span[@id="af-co-ship-city-error"]'));

    // Con la factura a otros datos, el nombre de ese bloque sí es el de la factura y sale en su bloque.
    $other = $web->post('/finalizar-compra/place', frontForm($web, frontFill(['billing_other' => '1', 'billing[first_name]' => '', 'billing[last_name]' => '', 'billing[line1]' => 'Calle Otra 2', 'billing[postcode]' => '28001', 'billing[city]' => 'Madrid'])));
    same(422, $other->status);
    same('Escribe el nombre para la factura', vcText($other->body, '//span[@id="af-co-bill-first-error"]'));
    check(frontNode($other->body, '//span[@id="af-co-ship-first-error"]') === null, 'y la entrega no sale marcada');
});

test('(2) nombre, dirección, código postal y población se anuncian como obligatorios; la factura plegada y las direcciones guardadas no los bloquean', function (): void {
    [$app, $web] = vcShop();
    $body = $web->get('/finalizar-compra')->body;
    foreach (['first_name', 'line1', 'postcode', 'city'] as $field) {
        check(frontNode($body, '//input[@name="shipping[' . $field . ']"][@required][@aria-required="true"]') !== null, 'shipping[' . $field . '] va como obligatorio');
        check(frontNode($body, '//input[@name="billing[' . $field . ']"][@required]') === null, 'billing[' . $field . '] plegado no bloquea el envío');
    }
    check(frontNode($body, '//button[@data-af-update-button][@formnovalidate]') !== null, '«Actualizar» sigue sin validar para recalcular sin JavaScript');
    check(frontNode($body, '//input[@name="shipping[line2]"][@required]') === null, 'el piso no es obligatorio');

    // Con una dirección guardada elegida, los campos de una dirección nueva sobran y no se exigen.
    $ana = aifecCartCustomer($app);
    $app->service('customers')->saveAddress($ana, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Palmera 5', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES', 'phone' => '999345678']);
    frontLogin($web);
    $logged = $web->get('/finalizar-compra')->body;
    check(frontNode($logged, '//input[@name="shipping[line1]"][@required]') === null, 'con direcciones guardadas no se exigen los campos de una nueva');

    // Un pedido solo digital: el nombre de la factura sí es obligatorio.
    $digital = new TestClient($app);
    $guide = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    frontAdd($digital, $guide, 1);
    $page = $digital->get('/finalizar-compra')->body;
    check(frontNode($page, '//input[@name="billing[first_name]"][@required][@aria-required="true"]') !== null, 'lo digital: el nombre de facturación es obligatorio');
});

test('(3, 15, 16) la entrega y la factura son grupos con nombre y los textos de la caja se entienden', function (): void {
    [$app, $web] = vcShop();
    $body = $web->get('/finalizar-compra')->body;
    $ship = frontNode($body, '//div[@data-af-address="shipping"]');
    $bill = frontNode($body, '//div[@data-af-address="billing"]');
    check($ship instanceof DOMElement && $bill instanceof DOMElement, 'hay un grupo de entrega y otro de factura');
    same('group', $ship->getAttribute('role'));
    same('group', $bill->getAttribute('role'));
    same('Dirección de entrega', vcText($body, '//span[@id="' . $ship->getAttribute('aria-labelledby') . '"]'));
    same('Datos de facturación', vcText($body, '//*[@id="' . $bill->getAttribute('aria-labelledby') . '"]'));
    same(1, substr_count($body, '>Datos de facturación<'), 'el nombre del grupo de factura es su encabezado, no se repite oculto');
    check($ship->getAttribute('aria-labelledby') !== $bill->getAttribute('aria-labelledby'), 'cada grupo con su propio nombre');

    contains('La factura va a otro nombre o dirección', $body);
    notContains('La factura va a otros datos', $body);
    contains('Opcional: escribe o dicta tus datos en una sola frase y rellenaremos los campos por ti.', $body);
    notContains('Pega o dicta tu nombre', $body);
    contains('Solo si compras como empresa: abre el apartado de abajo', $body, 'quien lee la caja sabe que «Necesito factura de empresa» es un apartado que se abre, no una casilla');
});

test('(4) el total del carrito sin destino se llama «Total sin envío» y summary.json y las respuestas de las acciones dicen lo mismo; con destino, «Total» con el envío', function (): void {
    [$app, $web, $p] = vcShop();
    $page = $web->get('/carrito')->body;
    contains('Total sin envío', $page);
    contains('Se calcula en el siguiente paso', $page);
    $shown = frontTotal($page);
    same(24200, $shown, 'sin destino la página no suma el envío');
    $web->get('/carrito/summary.json');
    $json = $web->json();
    same($shown, $json['totals']['total'], 'summary.json coincide con la página (antes daba el total con envío)');
    same($app->i18n()->money(24200, 'EUR'), $json['totals']['total_formatted']);
    same(true, $json['totals']['shipping_pending']);
    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    same($shown + 12100, $web->json()['totals']['total'], 'y la respuesta de añadir también: 3 unidades sin envío');

    // Con un código postal calculado, el envío entra y el total se llama «Total».
    $web->post('/carrito/destination', ['country' => 'ES', 'postcode' => '28001']);
    $after = $web->follow()->body;
    notContains('Total sin envío', $after);
    same(36300 + 495, frontTotal($after), 'con destino lleva el envío a la península');
    $web->get('/carrito/summary.json');
    same(36300 + 495, $web->json()['totals']['total'], 'summary.json sigue a la página');
    same(false, $web->json()['totals']['shipping_pending']);
});

test('(5) la caja llama «Envío estimado» al envío del resumen mientras no hay código postal, y con él pasa a ser el envío', function (): void {
    [$app, $web] = vcShop();
    $page = $web->get('/finalizar-compra');
    contains('Envío estimado', $page->body);
    contains('El envío del resumen es una estimación. Escribe tu código postal', $page->body, 'el bloque de envío dice lo mismo que el resumen');
    contains('<noscript>', $page->body);
    contains('Sin JavaScript, pulsa «Actualizar» después de escribir tu código postal', $page->body);
    $web->post('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => '28001']), $page->body));
    $after = $web->follow();
    notContains('Envío estimado', $after->body, 'con código postal ya es el envío de verdad');
    contains('name="carrier_id"', $after->body);
});

test('(6) una cantidad negativa, vacía o que no es un número no quita la línea: da el error de añadir y la deja como estaba; 0 sí la quita', function (): void {
    [$app, $web, $p] = vcShop();
    $web->get('/carrito');
    check(preg_match('/name="key" value="([^"]+)"/', $web->last->body, $m) === 1, 'la línea trae su clave');
    $key = $m[1];
    foreach (['-5', '', 'abc', '-1'] as $bad) {
        $web->post('/carrito/update', ['key' => $key, 'qty' => $bad]);
        same(302, $web->last->status);
        $page = $web->follow();
        contains('Escribe una cantidad válida', $page->body, 'qty «' . $bad . '»');
        contains('data-af-cart-line="' . $key . '"', $page->body, 'la línea sigue en la página');
        contains('value="2"', $page->body, 'la línea queda con sus 2 unidades');
    }
    $json = $web->postJson('/carrito/update', ['key' => $key, 'qty' => -5]);
    same(422, $json->status);
    same(2, $web->json()['count']);

    $web->post('/carrito/update', ['key' => $key, 'qty' => '3']);
    $web->follow();
    $web->get('/carrito/summary.json');
    same(3, $web->json()['count'], 'una cantidad buena sí se cambia');
    $web->post('/carrito/update', ['key' => $key, 'qty' => '0']);
    $web->get('/carrito/summary.json');
    same(0, $web->json()['count'], '0 quita la línea (es lo que hace «Quitar»)');
});

test('(7) el formulario de cerrar sesión solo está en la caja de quien ha entrado, y «Actualizar» vuelve a la dirección de la caja con lo escrito', function (): void {
    [$app, $web] = vcShop();
    $guest = $web->get('/finalizar-compra');
    check(frontNode($guest->body, '//form[@id="af-co-logout"]') === null, 'un invitado no tiene formulario de cerrar sesión');
    notContains('af-co-logout', $guest->body);

    // «Actualizar» sin JavaScript: 303 a la caja, y la caja trae lo escrito, las casillas y los avisos de campo.
    $sent = $web->post('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => '99999', 'note' => 'Llamar antes']), $guest->body));
    same(303, $sent->status);
    same('/finalizar-compra', (string) preg_replace('#^https?://[^/]+#', '', $sent->headers['Location']), 'no se queda «/update» en el navegador');
    $back = $web->follow();
    same(200, $back->status);
    contains('Llamar antes', $back->body, 'lo escrito se conserva');
    contains('value="99999"', $back->body);
    same('Escribe un código postal válido', vcText($back->body, '//span[@id="af-co-ship-postcode-error"]'));
    $terms = frontNode($back->body, '//input[@name="accept_terms"]');
    check($terms instanceof DOMElement && $terms->hasAttribute('checked'), 'la casilla de las condiciones sigue marcada');
    $again = $web->get('/finalizar-compra');
    check(frontNode($again->body, '//span[@id="af-co-ship-postcode-error"]') === null, 'al recargar la caja, el aviso de un solo uso ya no sale');

    $ana = aifecCartCustomer($app);
    $app->service('customers')->saveAddress($ana, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Palmera 5', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES', 'phone' => '999345678']);
    frontLogin($web);
    $logged = $web->get('/finalizar-compra');
    check(frontNode($logged->body, '//form[@id="af-co-logout"]') !== null, 'quien ha entrado sí lo tiene');
});

test('(8) una dirección guardada a la que no se envía lo dice con su destino y qué hacer; elegir «otra dirección» usa lo escrito', function (): void {
    $app = aifecOrderApp(['carrier' => false]);
    $carrier = shippingAddCarrier($app, 'std', ['name' => 'Estándar', 'delay' => '24-48 h']);
    shippingAddRate($app, $carrier, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 495);
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);
    $ana = aifecCartCustomer($app);
    $app->service('customers')->saveAddress($ana, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle del Mar 3', 'postcode' => '07001', 'city' => 'Palma', 'country' => 'ES', 'phone' => '999345678']);
    frontLogin($web);

    $page = $web->get('/finalizar-compra');
    $warn = vcText($page->body, '//p[contains(@class,"af-alert-warn")]');
    contains('No podemos enviar a la dirección guardada que has elegido (07001 Palma (ES))', $warn);
    contains('Usar otra dirección', $warn, 'dice qué hacer');
    contains('Actualizar', $warn);

    // Con «Usar otra dirección» marcada, la caja usa la del formulario: Madrid sí tiene envío.
    $web->post('/finalizar-compra/update', frontForm($web, frontFill(['address_id' => '0']), $page->body));
    $after = $web->follow();
    check(frontNode($after->body, '//input[@name="carrier_id"]') !== null, 'con Madrid escrito y «otra dirección» hay opciones de envío');
    notContains('No podemos enviar a la dirección guardada', $after->body);

    // Y sin sesión, el aviso del destino escrito también lo nombra.
    $guest = new TestClient($app);
    frontAdd($guest, frontProduct($app), 1);
    $g = $guest->get('/finalizar-compra');
    $guest->post('/finalizar-compra/update', frontForm($guest, frontFill(['shipping[postcode]' => '07001', 'shipping[city]' => 'Palma']), $g->body));
    $shown = $guest->follow();
    contains('No podemos enviar a 07001 Palma (ES). Cambia el país o el código postal y pulsa «Actualizar».', vcText($shown->body, '//p[contains(@class,"af-alert-warn")]'));
});

test('(9) la provincia sale del código postal español si llega vacía, en la caja y al crear el pedido por otro camino; una elegida no se toca', function (): void {
    [$app, $web] = vcShop();
    $page = $web->get('/finalizar-compra');
    $web->post('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => '41001', 'shipping[region]' => '']), $page->body));
    $after = $web->follow();
    check(frontNode($after->body, '//select[@id="af-co-ship-region-es"]/option[@value="41"][@selected]') !== null, 'en la caja: 41 es Sevilla');

    // Otro camino que guarda una dirección: el pedido hecho por el servicio (API, asistente, panel).
    $placed = aifecOrderPlace($app, new TestClient($app), [[frontProduct($app), 0, 1]], ['billing' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Sierpes 1', 'postcode' => '41001', 'city' => 'Sevilla', 'country' => 'ES', 'region' => '', 'phone' => '999345678'], 'shipping' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Sierpes 1', 'postcode' => '41001', 'city' => 'Sevilla', 'country' => 'ES', 'region' => '', 'phone' => '999345678']]);
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
    $order = $app->service('orders')->find((int) $placed['id']);
    same('41', (string) $order['shipping']['region'], 'la dirección de envío del pedido lleva la provincia');
    same('41', (string) $order['billing']['region'], 'y la de facturación');

    // Una provincia que el cliente sí puso en esa dirección no se cambia.
    $kept = aifecOrderPlace($app, new TestClient($app), [[frontProduct($app), 0, 1]], ['shipping' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Sierpes 1', 'postcode' => '41001', 'city' => 'Sevilla', 'country' => 'ES', 'region' => 'SE', 'phone' => '999345678']]);
    check(!empty($kept['ok']), 'el pedido se crea: ' . (string) $kept['error']);
    same('SE', (string) $app->service('orders')->find((int) $kept['id'])['shipping']['region'], 'la elegida se respeta');
});
