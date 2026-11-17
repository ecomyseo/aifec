<?php
/**
 * AIFEC — pruebas de la capa de datos en el panel y en el navegador: la pantalla de eventos del servidor, los ajustes, los permisos,
 * las marcas de inicio de sesión y alta, y el guion datalayer.js (peso, eventos que empuja, nada visible).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la pantalla de eventos del servidor se abre con permiso, enseña estados y respuestas escapadas, y da 403 sin permiso', function (): void {
    $app = dlApp();
    $pedido = dlOrder($app, dlWeb($app, 'sm'));
    $app->db()->insert('datalayer_events', ['order_id' => $pedido['id'], 'kind' => 'purchase', 'destination' => 'meta', 'ref' => '', 'event_id' => 'purchase_X', 'status' => 'failed', 'http_status' => 400, 'response' => '<b>error</b> & "más"', 'created_at' => $app->clock()->sql()]);
    $app->db()->insert('datalayer_events', ['order_id' => $pedido['id'], 'kind' => 'purchase', 'destination' => 'ga4', 'ref' => '', 'event_id' => 'purchase_X', 'status' => 'skipped', 'http_status' => null, 'response' => 'No consent', 'created_at' => $app->clock()->sql()]);
    $web = adminClient($app);
    $r = $web->get('/admin/datalayer/events');
    same(200, $r->status);
    contains('Eventos del servidor', $r->body);
    contains($pedido['number'], $r->body);
    contains('&lt;b&gt;error&lt;/b&gt; &amp; &quot;más&quot;', $r->body);
    notContains('<b>error</b>', $r->body);
    contains('Sin consentimiento', $r->body, 'los motivos se traducen');
    contains('Los eventos del servidor están apagados.', $r->body);
    same(403, adminClient($app, 'support')->get('/admin/datalayer/events')->status);
    same(302, (new TestClient($app))->get('/admin/datalayer/events')->status);
});

test('la pantalla de eventos sin nada que enseñar lo dice', function (): void {
    $app = dlApp(['settings' => ['datalayer.server_events' => true]]);
    $r = adminClient($app)->get('/admin/datalayer/events');
    same(200, $r->status);
    contains('Todavía no hay eventos del servidor', $r->body);
    notContains('Los eventos del servidor están apagados.', $r->body);
});

test('los ajustes de medición se abren con una pestaña por destino con el prefijo del módulo, y solo con permiso', function (): void {
    $app = dlApp();
    $r = adminClient($app)->get('/admin/settings/datalayer');
    same(200, $r->status);
    foreach (['dlgeneral', 'dlgtm', 'dlga4', 'dlads', 'dlmeta', 'dltiktok', 'dluet', 'dlcustom'] as $tab) {
        contains($tab, $r->body, 'pestaña ' . $tab);
    }
    contains('Mandar compras y reembolsos desde el servidor', $r->body);
    same(403, adminClient($app, 'support')->get('/admin/settings/datalayer')->status);
});

test('el interruptor de los eventos del servidor nace apagado y los secretos no vuelven a la pantalla', function (): void {
    $app = dlApp();
    same(false, $app->settings()->bool('datalayer.server_events', false));
    $app->setSecret('datalayer.meta_token', 'token-muy-secreto-123');
    $r = adminClient($app)->get('/admin/settings/datalayer');
    notContains('token-muy-secreto-123', $r->body);
});

test('al iniciar sesión se deja la marca de un día solo si se aceptaron las estadísticas, y no dice quién es', function (): void {
    $app = dlApp();
    aifecCartCustomer($app, 'ana@cliente.test');
    $mark = $app->cookieName('dl');
    $web = dlWeb($app, 's');
    $r = $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1234']);
    same(303, $r->status);
    check(isset($r->cookies[$mark]), 'con estadísticas aceptadas sale la marca');
    same('login', $r->cookies[$mark]['value']);
    same(false, $r->cookies[$mark]['options']['httponly'], 'la lee el guion');
    notContains('ana', (string) $r->cookies[$mark]['value']);

    $none = dlWeb($app, '');
    $r = $none->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1234']);
    same(303, $r->status);
    check(!isset($r->cookies[$mark]), 'sin estadísticas no hay marca');
    $undecided = dlWeb($app);
    $r = $undecided->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1234']);
    check(!isset($r->cookies[$mark]), 'sin decisión tampoco');
});

test('al darse de alta la marca es «sign_up», aunque el alta también inicie sesión', function (): void {
    $app = dlApp(['modules' => ['mail']]);
    $web = dlWeb($app, 'sm');
    $r = $web->post('/mi-cuenta/registro', ['first_name' => 'ana', 'last_name' => 'ruiz', 'email' => 'nueva@cliente.test', 'password' => 'clave-larga-1', 'accept_privacy' => 'on']);
    same(303, $r->status);
    $mark = $app->cookieName('dl');
    check(isset($r->cookies[$mark]), 'sale la marca');
    same('sign_up', $r->cookies[$mark]['value']);
});

test('la marca de inicio de sesión no existe con el módulo apagado', function (): void {
    $app = dlApp(['settings' => ['datalayer.enabled' => false]]);
    aifecCartCustomer($app, 'ana@cliente.test');
    $r = dlWeb($app, 'sm')->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1234']);
    check(!isset($r->cookies[$app->cookieName('dl')]), 'sin módulo no hay marca');
});

test('el guion datalayer.js pesa 8 KB o menos, no trae cadenas visibles ni llamadas a otros dominios', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/datalayer.js');
    check(strlen($js) <= 8192, 'datalayer.js pesa ' . strlen($js) . ' bytes (tope 8192)');
    foreach (['http://', 'https://', 'import ', 'require(', 'document.write', 'eval('] as $bad) {
        notContains($bad, $js);
    }
    foreach (['Accept', 'Add to cart', 'Rechazar', 'Añadir', 'Error'] as $word) {
        check(preg_match('/[\'"][^\'"]*' . $word . '[^\'"]*[\'"]/', $js) !== 1, 'sin cadenas visibles con «' . $word . '»');
    }
});

test('el guion empuja los eventos de comercio de GA4, los propios de AIFEC y respeta el consentimiento', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/datalayer.js');
    foreach (['view_item_list', 'select_item', 'add_to_cart', 'remove_from_cart', 'add_shipping_info', 'add_payment_info', 'search', 'login', 'sign_up',
        'assistant_open', 'view_item', 'begin_checkout', 'purchase', 'ai_source'] as $name) {
        contains($name, $js, 'conoce «' . $name . '»');
    }
    contains('aifec:consent', $js);
    contains('aifec:cart', $js);
    contains('af_ai', $js);
    contains('data-af-dl', $js);
    contains('navigator.webdriver', $js);
    contains('bot|crawl|spider', $js);
    contains('eventID', $js, 'Meta recibe el identificador de evento');
    contains('event_id', $js, 'TikTok y los demás también');
    contains('C.dbg', $js);
});

test('el módulo declara sus dependencias y los dominios a los que habla el servidor', function (): void {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/module.json'), true);
    same(['catalog', 'orders', 'consent'], $manifest['requires']['modules']);
    same(['www.google-analytics.com', 'graph.facebook.com'], $manifest['contract']['hosts']);
    same(['datalayer_orders', 'datalayer_events'], $manifest['contract']['tables']);
});
