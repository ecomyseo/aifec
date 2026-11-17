<?php
/**
 * AIFEC — pruebas de la página de la caja: el orden de sus bloques, el autocompletado de cada campo, lo que se esconde
 * hasta que se pide, «rellénalo de una vez», los avisos junto a cada campo, el envío que aparece con el código postal, la
 * cuenta abierta, el presupuesto de consultas y el peso y la limpieza de su guion y de su hoja de estilos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

require_once __DIR__ . '/FrontKit.php';

/** Una tienda con un producto y dos unidades en el carrito. @return array{0:App,1:TestClient,2:int} */
function ckPageShop(array $settings = []): array
{
    $app = frontShop(['settings' => $settings]);
    $web = new TestClient($app);
    $p = frontProduct($app);
    frontAdd($web, $p, 2);

    return [$app, $web, $p];
}

/** El valor de un atributo del primer nodo que cumple el XPath, o null si no hay nodo o atributo. */
function ckPageAttr(string $html, string $xpath, string $attr): ?string
{
    $node = frontNode($html, $xpath);
    if (!$node instanceof DOMElement || !$node->hasAttribute($attr)) {
        return null;
    }

    return $node->getAttribute($attr);
}

/** Un fichero del tema base. */
function ckPageAsset(string $relative): string
{
    return (string) file_get_contents(rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/base/assets/' . $relative);
}

test('cada campo de la entrega y de la factura a otros datos lleva su autocomplete y su teclado', function (): void {
    [$app, $web] = ckPageShop();
    $body = $web->get('/finalizar-compra')->body;
    $expected = [
        'email' => ['email', 'email'],
        'shipping[first_name]' => ['given-name', null],
        'shipping[last_name]' => ['family-name', null],
        'shipping[line1]' => ['address-line1', null],
        'shipping[line2]' => ['address-line2', null],
        'shipping[postcode]' => ['postal-code', 'numeric'],
        'shipping[city]' => ['address-level2', null],
        'shipping[phone]' => ['tel', 'tel'],
        'shipping[company]' => ['organization', null],
        'billing[first_name]' => ['billing given-name', null],
        'billing[line1]' => ['billing address-line1', null],
        'billing[postcode]' => ['billing postal-code', 'numeric'],
        'billing[company]' => ['billing organization', null],
    ];
    foreach ($expected as $name => $attrs) {
        $path = '//input[@name="' . $name . '"]';
        same($attrs[0], ckPageAttr($body, $path, 'autocomplete'), 'autocomplete de ' . $name);
        same($attrs[1], ckPageAttr($body, $path, 'inputmode'), 'inputmode de ' . $name);
    }
    same('country', ckPageAttr($body, '//select[@name="shipping[country]"]', 'autocomplete'));
    same('billing country', ckPageAttr($body, '//select[@name="billing[country]"]', 'autocomplete'));
    same('address-level1', ckPageAttr($body, '//input[@name="shipping[region]"]', 'autocomplete'), 'la provincia de fuera de España');
});

test('el orden de la entrega: país, nombre, apellidos, dirección, piso, código postal, ciudad, provincia y teléfono', function (): void {
    [$app, $web] = ckPageShop();
    $body = $web->get('/finalizar-compra')->body;
    $last = -1;
    foreach (['af-co-ship-country', 'af-co-ship-first', 'af-co-ship-last', 'af-co-ship-line1', 'af-co-ship-line2', 'af-co-ship-postcode', 'af-co-ship-city', 'af-co-ship-region-es', 'af-co-ship-phone'] as $id) {
        $at = strpos($body, 'id="' . $id . '"');
        check($at !== false && $at > $last, $id . ' va después del anterior');
        $last = (int) $at;
    }
    $contact = (int) strpos($body, 'id="af-co-email"');
    check($contact > 0 && $contact < (int) strpos($body, 'id="af-co-ship-country"'), 'el correo va antes de la entrega');
    check(strpos($body, 'id="af-co-payment"') < strpos($body, 'data-af-checkout-submit'), 'el botón de pagar va al final');
});

test('la empresa y el NIF salen dentro de «Necesito factura de empresa», cerrado salvo que haga falta', function (): void {
    [$app, $web] = ckPageShop();
    $body = $web->get('/finalizar-compra')->body;
    $details = frontNodes($body, '//details[contains(@class,"af-co-company")]');
    same(2, count($details), 'una en la entrega y otra en la factura a otros datos');
    check(!$details[0]->hasAttribute('open'), 'cerrado por defecto');
    contains('Necesito factura de empresa', $body);
    check(frontNode($body, '//details[contains(@class,"af-co-company")]//input[@name="shipping[vat_id]"]') !== null, 'el NIF está dentro');
    check(frontNode($body, '//details[contains(@class,"af-co-company")]//input[@name="shipping[company]"]') !== null, 'la empresa está dentro');
    check(frontNode($body, '//div[@data-af-address="shipping"]/div[contains(@class,"af-field")]//input[@name="shipping[vat_id]"]') === null, 'ni a la vista');

    $web->post('/finalizar-compra/update', frontForm($web, frontFill(['shipping[company]' => 'Muebles Roble SL']), $body));
    $written = $web->follow();
    $open = frontNodes($written->body, '//details[contains(@class,"af-co-company")]');
    check($open[0]->hasAttribute('open'), 'con una empresa escrita se abre');

    [$app2, $web2] = ckPageShop(['orders.require_vat_id' => true]);
    $req = frontNodes($web2->get('/finalizar-compra')->body, '//details[contains(@class,"af-co-company")]');
    check($req[0]->hasAttribute('open'), 'con el NIF obligatorio se abre');
});

test('la factura a otros datos es una casilla con su bloque, que se enseña sin JavaScript al marcarla', function (): void {
    [$app, $web] = ckPageShop();
    $body = $web->get('/finalizar-compra')->body;
    $input = frontNode($body, '//div[contains(@class,"af-co-toggle")]/input[@name="billing_other"]');
    check($input instanceof DOMElement && !$input->hasAttribute('checked'), 'la casilla empieza sin marcar');
    check(frontNode($body, '//div[contains(@class,"af-co-toggle")]/input[@name="billing_other"]/following-sibling::div[@id="af-co-billing-box"]') !== null, 'el bloque es hermano de la casilla: el CSS lo enseña al marcarla');
    contains('.af-co-toggle-input:checked~.af-co-toggle-box{display:block}', ckPageAsset('css/checkout.css'));
    $web->post('/finalizar-compra/update', frontForm($web, frontFill(['billing_other' => '1']), $body));
    $marked = $web->follow();
    $checked = frontNode($marked->body, '//input[@name="billing_other"]');
    check($checked instanceof DOMElement && $checked->hasAttribute('checked'), 'marcada, se queda marcada');
});

test('«Rellénalo de una vez» está en la entrega, escondido hasta que el guion lo enseña, con sus avisos en atributos', function (): void {
    [$app, $web] = ckPageShop();
    $body = $web->get('/finalizar-compra')->body;
    $box = frontNode($body, '//div[@data-af-understand]');
    check($box instanceof DOMElement && $box->hasAttribute('hidden'), 'sin JavaScript no se ve');
    same('shipping', $box->getAttribute('data-af-group'));
    foreach (['data-af-done', 'data-af-none', 'data-af-doubt-guessed', 'data-af-doubt-invalid', 'data-af-doubt-ambiguous'] as $attr) {
        check($box->getAttribute($attr) !== '', 'el aviso ' . $attr . ' viene de la página');
    }
    contains('Rellénalo de una vez', $body);
    check(frontNode($body, '//textarea[@data-af-understand-text][not(@name)]') !== null, 'el cuadro no se envía con el formulario');
    contains('data-af-understand-url="/finalizar-compra/understand"', $body);
    check(frontNode($body, '//section[@id="af-co-delivery"]//div[@data-af-understand]') !== null, 'va dentro de la entrega');
});

test('checkout.understand devuelve los campos, lo dudoso y lo que falta, y no guarda nada', function (): void {
    [$app, $web] = ckPageShop();
    $before = $app->db()->row('SELECT checkout, email, destination FROM {carts} WHERE id = 1');
    $r = $web->post('/finalizar-compra/understand', ['text' => 'Ana Ruiz, calle Mayor 1 3ºB, 35001 Las Palmas, 999000001, ana@correo.test'], ['accept' => 'application/json']);
    same(200, $r->status);
    contains('no-store', $r->headers['Cache-Control']);
    $json = $web->json();
    same(true, $json['ok']);
    same('Ana', $json['fields']['first_name']);
    same('Calle Mayor 1', $json['fields']['line1']);
    same('3ºB', $json['fields']['line2']);
    same('35', $json['fields']['region']);
    same('Las Palmas', $json['fields']['city']);
    same([], $json['doubts']);
    same([], $json['missing']);
    same($before, $app->db()->row('SELECT checkout, email, destination FROM {carts} WHERE id = 1'), 'el carrito queda igual');
    contains('value=""', $web->get('/finalizar-compra')->body, 'la caja no ha guardado nada');

    $none = $web->post('/finalizar-compra/understand', ['text' => 'quiero una mesa'], ['accept' => 'application/json']);
    same(200, $none->status);
    same([], $web->json()['fields']);
    same(['email', 'first_name', 'line1', 'postcode', 'city'], $web->json()['missing']);
    $huge = $web->post('/finalizar-compra/understand', ['text' => str_repeat('x', 50000)], ['accept' => 'application/json']);
    same(200, $huge->status, 'un texto enorme se recorta');
});

test('checkout.understand solo se acepta desde la propia web, con carrito y con la cuenta que se pida', function (): void {
    [$app, $web] = ckPageShop();
    same(403, $web->post('/finalizar-compra/understand', ['text' => 'Ana'], ['sec-fetch-site' => 'cross-site', 'accept' => 'application/json'])->status);
    same(403, $web->post('/finalizar-compra/understand', ['text' => 'Ana'], ['origin' => 'https://otra-web.test', 'sec-fetch-site' => 'cross-site'])->status);

    $empty = new TestClient($app);
    $r = $empty->post('/finalizar-compra/understand', ['text' => 'Ana'], ['accept' => 'application/json']);
    same(409, $r->status);
    same(route('cart'), $empty->json()['redirect']);

    [$app2, $web2] = ckPageShop(['orders.guest_checkout' => false]);
    $guest = $web2->post('/finalizar-compra/understand', ['text' => 'Ana'], ['accept' => 'application/json']);
    same(401, $guest->status);
    contains(route('account.login'), $web2->json()['redirect']);
});

test('la ruta de actualización con JSON devuelve los avisos de cada campo y guarda lo bueno', function (): void {
    [$app, $web] = ckPageShop();
    $post = frontForm($web, frontFill(['email' => 'esto no es un correo', 'shipping[postcode]' => '99999']));
    $r = $web->postJson('/finalizar-compra/update', $post);
    same(200, $r->status);
    $json = $web->json();
    same(true, $json['ok']);
    same(['email', 'shipping.postcode'], array_keys($json['errors']));
    same('Escribe un correo electrónico válido', $json['errors']['email']);
    same('Escribe un código postal válido', $json['errors']['shipping.postcode']);

    $page = $web->get('/finalizar-compra')->body;
    contains('value="Calle Mayor 1"', $page, 'lo bueno se guardó según se rellenaba');
    contains('value="Madrid"', $page);
    notContains('value="99999"', $page, 'lo malo no');

    $clean = $web->postJson('/finalizar-compra/update', frontForm($web, frontFill()));
    same([], $web->json()['errors'], 'sin errores, la lista viene vacía');
});

test('sin JavaScript, «Actualizar» con un dato malo vuelve a pintar la caja con el aviso junto a su campo y lo escrito', function (): void {
    [$app, $web] = ckPageShop();
    $sent = $web->post('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => '99999'])));
    same(303, $sent->status, 'se vuelve a la dirección de la caja: no se queda «/update» en el navegador');
    same('/finalizar-compra', (string) preg_replace('#^https?://[^/]+#', '', $sent->headers['Location']));
    $r = $web->follow();
    same(200, $r->status);
    check(frontNode($r->body, '//span[@id="af-co-ship-postcode-error"]') !== null, 'el aviso del código postal');
    contains('value="99999"', $r->body, 'lo escrito se enseña aunque no se haya guardado');
    contains('value="Calle Mayor 1"', $r->body);
});

test('el envío aparece en cuanto hay código postal y se actualiza solo al cambiarlo', function (): void {
    [$app, $web] = ckPageShop();
    $page = $web->get('/finalizar-compra');
    contains('data-af-shipping-wait', $page->body);
    notContains('name="carrier_id"', $page->body, 'sin código postal no hay opciones de envío');
    contains('Escribe tu código postal', $page->body);

    $json = $web->postJson('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => ''])));
    contains('data-af-shipping-wait', $web->json()['html']['shipping']);

    $web->postJson('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => '28001'])));
    $shipping = $web->json()['html']['shipping'];
    contains('name="carrier_id"', $shipping);
    notContains('data-af-shipping-wait', $shipping);
    contains($app->i18n()->money(495, 'EUR'), $shipping);

    $web->postJson('/finalizar-compra/update', frontForm($web, frontFill(['shipping[postcode]' => '35001'])));
    contains($app->i18n()->money(1595, 'EUR'), $web->json()['html']['shipping'], 'el envío de Canarias');
    $reload = $web->get('/finalizar-compra');
    contains('name="carrier_id"', $reload->body, 'al volver, el código postal guardado ya trae el envío');
});

test('con la cuenta abierta los datos vienen puestos y basta aceptar las condiciones para comprar', function (): void {
    [$app, $web] = ckPageShop();
    aifecOrderPayment($app)->action = Aifec\Commerce\PaymentAction::paid('PAY-20');
    aifecCartCustomer($app);
    frontLogin($web);
    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    contains('Has entrado como ana@cliente.test', $page->body);
    contains('name="email" value="ana@cliente.test"', $page->body);
    contains('value="Ana"', $page->body, 'el nombre de la cuenta');
    contains('value="Ruiz"', $page->body, 'los apellidos de la cuenta');

    // La cuenta no tiene teléfono: la entrega lo exige (16/11/2026), así que se escribe con la dirección.
    $post = frontForm($web, ['shipping[line1]' => 'Calle Mayor 1', 'shipping[postcode]' => '28001', 'shipping[city]' => 'Madrid', 'shipping[phone]' => '999345678', 'accept_terms' => '1'], $page->body);
    $place = $web->post('/finalizar-compra/place', $post);
    same(303, $place->status);
    $order = $app->service('orders')->find(1);
    same('ana@cliente.test', $order['email']);
    same('Ana', $order['billing']['first_name']);
});

test('un cliente con una dirección guardada compra con un clic: todo viene puesto y el envío es el de esa dirección', function (): void {
    [$app, $web] = ckPageShop();
    aifecOrderPayment($app)->action = Aifec\Commerce\PaymentAction::paid('PAY-21');
    $ana = aifecCartCustomer($app);
    $app->service('customers')->saveAddress($ana, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Palmera 5', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES', 'phone' => '999345678']);
    frontLogin($web);
    $page = $web->get('/finalizar-compra');
    notContains('data-af-shipping-wait', $page->body, 'la dirección guardada ya trae el envío');
    same(24200 + 1595, frontTotal($page->body));
    $place = $web->post('/finalizar-compra/place', frontForm($web, ['accept_terms' => '1'], $page->body));
    same(303, $place->status, 'basta con aceptar las condiciones');
    same('Calle Palmera 5', $app->service('orders')->find(1)['shipping']['line1']);
});

test('el resumen enseña la miniatura de cada línea con su cantidad, y en móvil va plegado arriba con el total a la vista', function (): void {
    [$app, $web] = ckPageShop();
    $body = $web->get('/finalizar-compra')->body;
    $qty = frontNode($body, '//ul[@data-af-summary-lines]/li//span[contains(@class,"af-co-qty")]');
    check($qty !== null && trim($qty->textContent) === '2', 'la cantidad de la línea');
    check(frontNode($body, '//ul[@data-af-summary-lines]/li//span[contains(@class,"af-co-thumb")]') !== null, 'la miniatura de la línea');
    $mobile = frontNode($body, '//details[@data-af-summary-details][contains(@class,"af-show-sm")]/summary//strong[@data-af-checkout-total]');
    check($mobile !== null && trim($mobile->textContent) === $app->i18n()->money(24200 + 495, 'EUR'), 'el total a la vista en el resumen plegable');
    check(frontNode($body, '//aside[contains(@class,"af-summary")][contains(@class,"af-hide-sm")]') !== null, 'en escritorio, a un lado');
    contains('af-has-bar', $body, 'el contenido reserva sitio para la barra inferior');
});

test('la forma de pago elegida es la que se despliega, y un botón, y solo uno, hace el pedido', function (): void {
    [$app, $web] = ckPageShop();
    $app->service('payments')->register(new AifecOrderPayment('transfer'));
    $body = $web->get('/finalizar-compra')->body;
    check(frontNode($body, '//label[@data-af-payment-kind]/input[@name="payment_method"][@checked]') !== null, 'hay una elegida');
    same(2, count(frontNodes($body, '//label[@data-af-payment-kind]')), 'las dos formas, cada una con su clase');
    contains('.af-radio-card input:not(:checked)+.af-radio-card-body .af-co-method-detail{display:none}', ckPageAsset('css/checkout.css'));
    same(1, count(frontNodes($body, '//form[@data-af-checkout-form]//button[@data-af-checkout-submit]')), 'un solo botón de pagar');
});

test('la caja de lo digital pone «rellénalo de una vez» sobre los datos de facturación y no pide dirección', function (): void {
    $app = frontShop();
    $web = new TestClient($app);
    frontAdd($web, aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]), 1);
    $body = $web->get('/finalizar-compra')->body;
    same('billing', ckPageAttr($body, '//div[@data-af-understand]', 'data-af-group'));
    check(frontNode($body, '//input[@name="billing[line1]"]') === null, 'ni calle');
    check(frontNode($body, '//input[@name="billing[first_name]"]') !== null, 'sí el nombre');
    check(frontNode($body, '//select[@name="billing[country]"]') !== null, 'y el país');
    same('given-name', ckPageAttr($body, '//input[@name="billing[first_name]"]', 'autocomplete'));
});

test('la página de la caja no hace más de 25 consultas a la base de datos: de invitado y con la cuenta abierta', function (): void {
    [$app, $web] = ckPageShop();
    $count = static function (TestClient $web) use ($app): int {
        $before = $app->db()->queries;
        $page = $web->get('/finalizar-compra');
        same(200, $page->status);

        return $app->db()->queries - $before;
    };
    $first = $count($web);
    check($first <= 25, 'de invitado, la primera vez: ' . $first . ' consultas');
    $second = $count($web);
    check($second <= 25, 'de invitado, al volver: ' . $second . ' consultas');

    $ana = aifecCartCustomer($app);
    $app->service('customers')->saveAddress($ana, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Palmera 5', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES', 'phone' => '999345678']);
    frontLogin($web);
    // La primera vez guarda en el carrito el destino de la dirección guardada y la forma de pago: son dos cálculos más.
    $cold = $count($web);
    check($cold <= 30, 'con la cuenta abierta, la primera vez: ' . $cold . ' consultas');
    $warm = $count($web);
    check($warm <= 25, 'con la cuenta abierta, al volver: ' . $warm . ' consultas');
});

test('actualizar sin cambiar nada no vuelve a escribir en el carrito', function (): void {
    [$app, $web] = ckPageShop();
    $post = frontForm($web, frontFill(['shipping[postcode]' => '35001']));
    $web->postJson('/finalizar-compra/update', $post);
    $row = $app->db()->row('SELECT checkout, destination, carrier_id, payment_method, updated_at FROM {carts} WHERE id = 1');
    $app->clock()->advance(60);
    $before = $app->db()->queries;
    $web->postJson('/finalizar-compra/update', $post);
    $queries = $app->db()->queries - $before;
    same($row, $app->db()->row('SELECT checkout, destination, carrier_id, payment_method, updated_at FROM {carts} WHERE id = 1'), 'el carrito queda igual, ni la fecha cambia');
    check($queries <= 25, 'una actualización sin cambios: ' . $queries . ' consultas');
});

test('la caja solo carga lo que usa: su guion y su hoja de estilos, y solo en la caja', function (): void {
    [$app, $web] = ckPageShop();
    $page = $web->get('/finalizar-compra')->body;
    same(1, substr_count($page, 'js/checkout.js'), 'un solo guion de la caja');
    same(1, substr_count($page, 'css/checkout.css'), 'una sola hoja de la caja');
    check(preg_match('#<link rel="stylesheet" href="[^"]*/assets/t/base/[^"]*/css/checkout\.css">#', $page) === 1, 'la hoja va en la cabecera');
    check(preg_match('#<script src="[^"]*/assets/t/base/[^"]*/js/checkout\.js" defer></script>#', $page) === 1, 'el guion va con defer');
    foreach (['/carrito', '/'] as $other) {
        $body = $web->get($other)->body;
        notContains('checkout.js', $body, 'el guion no se carga en ' . $other);
        notContains('checkout.css', $body, 'la hoja no se carga en ' . $other);
    }
});

test('checkout.js pesa como mucho 12 KB, no usa nada que un navegador viejo no entienda y no lleva ninguna cadena visible', function (): void {
    $js = ckPageAsset('js/checkout.js');
    check(strlen($js) <= 12 * 1024, 'checkout.js pesa ' . strlen($js) . ' bytes');
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $js);
    $code = (string) preg_replace('#^\s*//.*$#m', '', $code);
    foreach (['=>', 'let ', 'const ', '`', 'alert(', 'confirm(', 'prompt(', 'eval(', 'document.write'] as $banned) {
        notContains($banned, $code, 'checkout.js no puede usar ' . $banned);
    }
    $count = preg_match_all("/'((?:[^'\\\\\n]|\\\\.)*)'/", $code, $m);
    check($count > 30, 'se han encontrado las cadenas del guion (' . $count . ')');
    $allowed = ['POST', 'Accept', 'X-Requested-With', 'use strict', 'ES'];
    foreach ($m[1] as $literal) {
        if (in_array($literal, $allowed, true)) {
            continue;
        }
        same(0, preg_match('/[^\x00-\x7F]/', $literal), 'una cadena con tildes o eñes sería un texto visible: «' . $literal . '»');
        same(0, preg_match('/\p{L}{3,} \p{L}{3,}/u', $literal), 'dos palabras seguidas serían un texto visible: «' . $literal . '»');
        same(0, preg_match('/[A-Z]/', $literal), 'una mayúscula en una cadena sería un texto visible: «' . $literal . '»');
    }
    foreach (['Actualizar', 'Rellenar', 'Error', 'Pagar', 'Cargando', 'Update', 'Loading', 'Fill'] as $word) {
        notContains($word, implode("\n", $m[1]), 'el texto «' . $word . '» no va en el guion');
    }
});

test('checkout.css cumple el contrato: sin colores fuera de las variables, sin recursos de fuera y con el móvil cuidado', function (): void {
    $css = ckPageAsset('css/checkout.css');
    check(strlen($css) <= 8000, 'checkout.css pesa ' . strlen($css) . ' bytes');
    same(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', $css), 'ningún color fijo');
    same(0, preg_match('/\b(?:rgb|rgba|hsl|hsla)\(/', $css), 'ningún color funcional');
    foreach (['url(', '@import', '@font-face', 'http://', 'https://'] as $banned) {
        notContains($banned, $css);
    }
    foreach (['min-height:44px', 'scroll-margin-bottom', 'env(safe-area-inset-bottom)', 'prefers-reduced-motion', ':focus-visible'] as $needle) {
        contains($needle, $css, 'checkout.css no contiene «' . $needle . '»');
    }
});
