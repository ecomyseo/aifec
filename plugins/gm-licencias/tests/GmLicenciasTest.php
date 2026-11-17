<?php
/**
 * AIFEC — pruebas de GM licences: una licencia por unidad al pagar (sin duplicar), revocación al devolver, la API de verificación
 * (tope de dominios, respuestas uniformes, límite de uso), la zona del cliente, el panel con sus permisos, las claves firmadas, la
 * limpieza del registro y el ciclo de instalar y desinstalar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\Licencias\KeyFormat;
use Gm\Licencias\Licenses;
use Gm\Licencias\Nif;
use Gm\Licencias\Products;
use Gm\Licencias\SignedKey;

require_once dirname(__DIR__, 3) . '/modules/orders/tests/FrontKit.php';

/** @return string[] */
function gmLicActive(): array
{
    return ['plugins', 'orders', 'mail', 'media', 'jobs', 'gm-licencias'];
}

/**
 * @param array<string,mixed> $settings
 */
function gmLicShop(array $settings = []): App
{
    $app = aifecOrderApp(['settings' => $settings + ['modules.active' => gmLicActive()], 'modules' => ['plugins', 'gm-licencias']]);
    $app->migrate(true);

    return $app;
}

/** Un producto con licencia de dominios. */
function gmLicProduct(App $app, string $name = 'Módulo de ejemplo', int $domains = 2, int $months = 12, string $kind = 'domain'): int
{
    $id = aifecProduct($app, ['name' => $name, 'price' => 9900, 'kind' => 'digital', 'manage_stock' => 0]);
    Products::set($app, $id, $kind, $domains, $months);

    return $id;
}

function gmLicService(App $app): Licenses
{
    $service = $app->service('gm_licenses');
    check($service instanceof Licenses, 'el servicio gm_licenses es el del plugin');

    return $service;
}

/**
 * Un pedido pagado con estas líneas. Devuelve el id.
 *
 * @param array<int,array<int,int>> $lineas
 * @param array<string,mixed>       $caja
 */
function gmLicPaid(App $app, array $lineas, array $caja = []): int
{
    return aifecOrderPaid($app, new TestClient($app), $lineas, $caja);
}

/** Cuántas veces se contestó en total a la API, por resultado. */
function gmLicChecks(App $app, string $result): int
{
    return aifecOrderCount($app, 'gm_license_checks', 'result = ?', [$result]);
}

/**
 * Una clave privada de pruebas (curva prime256v1) y su pública. Hace falta un openssl.cnf mínimo: en algunos Windows no hay.
 *
 * @return array{0:string,1:string}
 */
function gmLicKeys(): array
{
    $cnf = tmpDir('gm-lic') . '/openssl.cnf';
    file_put_contents($cnf, "[req]\ndefault_bits = 2048\ndistinguished_name = req_dn\n[req_dn]\n");
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048, 'config' => $cnf]);
    check($key !== false, 'se puede crear una clave de pruebas');
    openssl_pkey_export($key, $pem, null, ['config' => $cnf]);
    $details = openssl_pkey_get_details($key);

    return [(string) $pem, (string) $details['key']];
}

test('gm-licencias se activa, se carga y crea sus tablas y sus rutas', function (): void {
    $app = gmLicShop();
    check($app->modules()->isLoaded('gm-licencias'), 'el plugin está cargado');
    check(!isset($app->modules()->failed()['gm-licencias']), 'no falló al cargar');
    foreach (['gm_license_products', 'gm_licenses', 'gm_license_domains', 'gm_license_checks'] as $table) {
        check($app->db()->tableExists($table), 'existe ' . $table);
    }
    foreach (['gm.license.verify', 'gm.license.deactivate', 'gm.licenses.account'] as $route) {
        check($app->router()->has($route), 'tiene la ruta ' . $route);
    }
});

test('un pedido pagado genera una licencia por unidad, con su clave legible, sus dominios y la fecha de actualizaciones', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Módulo de ejemplo', 2, 12);
    $orderId = gmLicPaid($app, [[$id, 0, 3]]);
    $rows = gmLicService($app)->forOrder($orderId);
    same(3, count($rows));
    $keys = [];
    foreach ($rows as $i => $row) {
        check(KeyFormat::looksValid((string) $row['license_key']), 'la clave tiene el formato legible: ' . $row['license_key']);
        $keys[] = $row['license_key'];
        same('active', (string) $row['status']);
        same('domain', (string) $row['kind']);
        same(2, (int) $row['max_domains']);
        same('ana@cliente.test', (string) $row['email']);
        same('Módulo de ejemplo', (string) $row['product_name']);
        same($i + 1, (int) $row['unit']);
        same('2027-11-12', substr((string) $row['updates_until'], 0, 10));
    }
    same(3, count(array_unique($keys)), 'ninguna clave se repite');
});

test('markPaid dos veces y un segundo aviso de pago no duplican las licencias ni el correo', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app);
    $orderId = gmLicPaid($app, [[$id, 0, 2]]);
    $order = $app->service('orders')->find($orderId);
    same(false, $app->service('orders')->markPaid($orderId, 'gw', 'PAY-OTRO', (int) $order['total']), 'el segundo cobro no es el primero');
    $app->hooks()->fire('order.paid', $order);
    same(2, aifecOrderCount($app, 'gm_licenses'));
    same(2, count(gmLicService($app)->issueForOrder($order)) + 2, 'emitir otra vez no crea nada nuevo');
    same(2, aifecOrderCount($app, 'gm_licenses'));
});

test('las claves van dentro del correo del pedido, escapadas, sin correo aparte, y un producto sin licencia no genera nada', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Módulo <b> & Co');
    $plain = aifecProduct($app, ['name' => 'Camiseta', 'stock' => 20]);
    gmLicPaid($app, [[$id, 0, 1], [$plain, 0, 1]]);
    same(0, count(aifecOrderMails($app, 'license_issued')), 'el correo aparte desaparece: el del pedido ya lleva las claves');
    $mails = aifecOrderMails($app, 'order_confirmed');
    same(1, count($mails));
    same('ana@cliente.test', (string) $mails[0]['to_email']);
    $key = (string) $app->db()->value('SELECT license_key FROM {gm_licenses}');
    contains('Tus licencias', (string) $mails[0]['html']);
    contains($key, (string) $mails[0]['html']);
    contains($key, (string) $mails[0]['text_body'], 'y en la versión de texto');
    contains('Módulo &lt;b&gt; &amp; Co', (string) $mails[0]['html']);
    notContains('Módulo <b>', (string) $mails[0]['html']);
    same(1, aifecOrderCount($app, 'gm_licenses'), 'solo el producto con licencia genera una');

    $other = aifecProduct($app, ['name' => 'Taza', 'stock' => 20]);
    gmLicPaid($app, [[$other, 0, 1]]);
    same(1, aifecOrderCount($app, 'gm_licenses'));
    same(0, count(aifecOrderMails($app, 'license_issued')));
    $second = aifecOrderMails($app, 'order_confirmed');
    notContains('Tus licencias', (string) $second[count($second) - 1]['html'], 'un pedido sin licencias no lleva el bloque');
});

test('si las claves no caben en el bloque del correo del pedido, sale el correo aparte con todas', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Paquete grande', 2, 12);
    gmLicPaid($app, [[$id, 0, 14]]);
    same(14, aifecOrderCount($app, 'gm_licenses'));
    $confirmed = aifecOrderMails($app, 'order_confirmed');
    notContains('Tus licencias', (string) $confirmed[0]['html'], 'catorce claves no caben en doce líneas');
    $own = aifecOrderMails($app, 'license_issued');
    same(1, count($own), 'las claves se mandan en su correo, una sola vez');
    $keys = $app->db()->column('SELECT license_key FROM {gm_licenses}');
    foreach ($keys as $key) {
        contains((string) $key, (string) $own[0]['html']);
    }
});

test('devolver el pedido revoca sus licencias; una devolución parcial, solo las unidades devueltas; cancelar, todas', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app);
    $orders = $app->service('orders');

    $full = gmLicPaid($app, [[$id, 0, 2]]);
    $order = $orders->find($full);
    $orders->refund($full, (int) $order['paid_total'], [], 'Cliente descontento', false, false);
    same(2, aifecOrderCount($app, 'gm_licenses', "order_id = ? AND status = 'revoked'", [$full]));

    $partial = gmLicPaid($app, [[$id, 0, 3]]);
    $order = $orders->find($partial);
    $item = (int) $order['items'][0]['id'];
    $orders->refund($partial, 5000, [$item => 1], 'Una unidad', false, false);
    same(1, aifecOrderCount($app, 'gm_licenses', "order_id = ? AND status = 'revoked'", [$partial]));
    same(2, aifecOrderCount($app, 'gm_licenses', "order_id = ? AND status = 'active'", [$partial]));
    same(3, (int) $app->db()->value('SELECT unit FROM {gm_licenses} WHERE order_id = ? AND status = ?', [$partial, 'revoked']), 'se revoca la última unidad');

    // El módulo de pedidos solo cancela pedidos sin pagar (que no tienen licencias), pero si un módulo cancelara uno pagado, las
    // licencias se revocarían.
    $cancelled = gmLicPaid($app, [[$id, 0, 1]]);
    $app->hooks()->fire('order.cancelled', $orders->find($cancelled));
    same(1, aifecOrderCount($app, 'gm_licenses', "order_id = ? AND status = 'revoked'", [$cancelled]));
    same(3, aifecOrderCount($app, 'gm_licenses', "status = 'active' AND order_id = ?", [$partial]) + 1, 'las demás siguen como estaban');
});

test('verificar con un dominio nuevo lo activa hasta el tope y después rechaza; el dominio ya activo sigue valiendo', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Módulo', 2);
    $orderId = gmLicPaid($app, [[$id, 0, 1]]);
    $key = (string) gmLicService($app)->forOrder($orderId)[0]['license_key'];
    $svc = gmLicService($app);

    $a = $svc->verify($key, 'Tienda-Uno.test');
    same(true, $a['valid']);
    same('ok', $a['reason']);
    same(true, $a['activated']);
    same(1, $a['domains_used']);
    same(2, $a['domains_max']);
    same(true, $a['updates_active']);
    same('2027-11-12', $a['updates_until']);

    $again = $svc->verify(strtolower($key), 'https://www.tienda-uno.test/admin?x=1');
    same(true, $again['valid'], 'el mismo dominio escrito de otra forma');
    same(false, $again['activated']);
    same(1, $again['domains_used']);

    same(true, $svc->verify($key, 'tienda-dos.test')['valid']);
    $full = $svc->verify($key, 'tienda-tres.test');
    same(false, $full['valid']);
    same('domain_limit', $full['reason']);
    same(2, aifecOrderCount($app, 'gm_license_domains'));
    same(true, $svc->verify($key, 'tienda-uno.test')['valid'], 'el que ya estaba activo sigue valiendo');

    $app->clock()->advance(400 * 86400);
    $late = $svc->verify($key, 'tienda-uno.test');
    same(true, $late['valid'], 'la licencia no caduca');
    same(false, $late['updates_active'], 'pero las actualizaciones sí');
});

test('una clave inventada, una revocada y una pendiente dan exactamente la misma respuesta', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app);
    $orderId = gmLicPaid($app, [[$id, 0, 2]]);
    $svc = gmLicService($app);
    $rows = $svc->forOrder($orderId);
    $svc->revoke((int) $rows[0]['id']);
    $invented = $svc->verify('AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'tienda.test');
    $revoked = $svc->verify((string) $rows[0]['license_key'], 'tienda.test');
    $garbage = $svc->verify('esto no es una clave', 'tienda.test');
    same($invented, $revoked);
    same($invented, $garbage);
    same(false, $invented['valid']);
    same('invalid', $invented['reason']);
    same(array_keys($invented), array_keys($svc->verify((string) $rows[1]['license_key'], 'tienda.test')), 'la misma forma que una válida');
    same(0, aifecOrderCount($app, 'gm_license_domains', 'license_id = ?', [(int) $rows[0]['id']]), 'una revocada no activa nada');
});

test('la API HTTP contesta JSON con la misma forma, desactiva un dominio y rechaza lo mal hecho', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Módulo', 1);
    $orderId = gmLicPaid($app, [[$id, 0, 1]]);
    $key = (string) gmLicService($app)->forOrder($orderId)[0]['license_key'];
    $web = new TestClient($app);

    $r = $web->postJson('/api/gm/licencia/verificar', ['key' => $key, 'domain' => 'tienda.test']);
    same(200, $r->status);
    $body = $web->json();
    same(['valid', 'reason', 'updates_until', 'updates_active', 'domains_used', 'domains_max', 'activated'], array_keys($body));
    same(true, $body['valid']);
    $web->postJson('/api/gm/licencia/verificar', ['key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'tienda.test']);
    same(array_keys($body), array_keys($web->json()));

    $r = $web->post('/api/gm/licencia/verificar', ['key' => $key, 'domain' => 'otra.test']);
    same(200, $r->status, 'también acepta un formulario');
    same('domain_limit', $web->json()['reason']);
    same(true, $web->postJson('/api/gm/licencia/desactivar', ['key' => $key, 'domain' => 'tienda.test'])->status === 200 && $web->json()['deactivated']);
    same(true, $web->postJson('/api/gm/licencia/verificar', ['key' => $key, 'domain' => 'otra.test'])->status === 200 && $web->json()['valid'], 'el sitio liberado se puede usar');
    same(false, $web->postJson('/api/gm/licencia/desactivar', ['key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'x.test'])->status !== 200 || $web->json()['deactivated']);
    same(400, $web->postJson('/api/gm/licencia/verificar', ['domain' => 'tienda.test'])->status);
    same(false, $web->json()['valid']);
    check(!isset($web->last->headers['Set-Cookie']), 'la API no crea sesión');
});

test('el límite de uso por clave y por dirección contesta 429, sin mirar si la clave existe', function (): void {
    $app = gmLicShop(['gm_licencias.rate_per_key' => 2, 'gm_licencias.rate_per_address' => 100]);
    $web = new TestClient($app);
    $keys = array_keys(['AAAAA-BBBBB-CCCCC-DDDDD-EEEEE' => 1]);
    same(200, $web->postJson('/api/gm/licencia/verificar', ['key' => $keys[0], 'domain' => 'a.test'])->status);
    same(200, $web->postJson('/api/gm/licencia/verificar', ['key' => $keys[0], 'domain' => 'a.test'])->status);
    $limited = $web->postJson('/api/gm/licencia/verificar', ['key' => $keys[0], 'domain' => 'a.test']);
    same(429, $limited->status);
    same('60', $limited->headers['Retry-After']);
    same('rate_limited', $web->json()['reason']);
    same(200, $web->postJson('/api/gm/licencia/verificar', ['key' => 'ZZZZZ-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'a.test'])->status, 'otra clave no está limitada');

    $app2 = gmLicShop(['gm_licencias.rate_per_address' => 2]);
    $web2 = new TestClient($app2);
    same(200, $web2->postJson('/api/gm/licencia/verificar', ['key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'a.test'])->status);
    same(200, $web2->postJson('/api/gm/licencia/desactivar', ['key' => 'BBBBB-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'a.test'])->status);
    same(429, $web2->postJson('/api/gm/licencia/verificar', ['key' => 'CCCCC-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'a.test'])->status);
});

test('el registro de comprobaciones no guarda direcciones ni dominios, y la tarea borra lo de más de 90 días', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app);
    $orderId = gmLicPaid($app, [[$id, 0, 1]]);
    $key = (string) gmLicService($app)->forOrder($orderId)[0]['license_key'];
    $web = new TestClient($app);
    $web->postJson('/api/gm/licencia/verificar', ['key' => $key, 'domain' => 'secreto.tienda-cliente.test']);
    $web->postJson('/api/gm/licencia/verificar', ['key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'otra.test']);
    same(2, aifecOrderCount($app, 'gm_license_checks'));
    $dump = json_encode($app->db()->select('SELECT * FROM {gm_license_checks}'));
    notContains('secreto', (string) $dump);
    notContains('127.0.0.1', (string) $dump);
    $columns = $app->db()->columns('gm_license_checks');
    $names = array_merge(array_keys($columns), array_values($columns));
    foreach (['ip', 'domain', 'email', 'address'] as $personal) {
        check(!in_array($personal, $names, true), 'la tabla del registro no tiene la columna «' . $personal . '»');
    }

    $app->db()->update('gm_license_checks', ['created_at' => $app->clock()->sql($app->clock()->now() - 91 * 86400)], ['result' => 'invalid']);
    same(1, gmLicService($app)->purgeChecks());
    same(1, aifecOrderCount($app, 'gm_license_checks'));
    $jobs = $app->modules()->definition('gm-licencias', 'jobs');
    check(isset($jobs['cron']['gm_licencias.purge']), 'la tarea de limpieza está declarada');
    same(null, call_user_func($jobs['cron']['gm_licencias.purge']['run'], $app));
});

test('el cliente ve sus licencias en su zona y en la página del pedido, y no las de otro', function (): void {
    $app = gmLicShop();
    $ana = aifecCartCustomer($app, 'ana@cliente.test');
    $luis = aifecCartCustomer($app, 'luis@cliente.test', ['first_name' => 'Luis']);
    $now = $app->clock()->sql();
    foreach ([[$ana, 'AAAAA-AAAAA-AAAAA-AAAAA-AAAAA', 'Módulo de Ana'], [$luis, 'BBBBB-BBBBB-BBBBB-BBBBB-BBBBB', 'Módulo de Luis']] as $row) {
        $app->db()->insert('gm_licenses', ['license_key' => $row[1], 'kind' => 'domain', 'status' => 'active', 'customer_id' => $row[0], 'email' => $row[0] === $ana ? 'ana@cliente.test' : 'luis@cliente.test',
            'product_name' => $row[2], 'max_domains' => 1, 'units' => 1, 'holder' => '', 'created_at' => $now, 'updated_at' => $now]);
    }
    $web = new TestClient($app);
    same(302, $web->get('/mi-cuenta/licencias')->status, 'sin entrar, a la pantalla de acceso');
    frontLogin($web, 'ana@cliente.test');
    $r = $web->get('/mi-cuenta/licencias');
    same(200, $r->status);
    contains('AAAAA-AAAAA-AAAAA-AAAAA-AAAAA', $r->body);
    contains('Módulo de Ana', $r->body);
    notContains('BBBBB-BBBBB', $r->body);
    notContains('Módulo de Luis', $r->body);
    contains('no-store', (string) $r->headers['Cache-Control']);
    contains('Tienes 1 licencia.', $web->get('/mi-cuenta')->body);
    contains('/mi-cuenta/licencias', $web->get('/mi-cuenta')->body);
});

test('la página del pedido enseña las claves recién emitidas', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app);
    $web = new TestClient($app);
    frontAdd($web, $id, 1);
    aifecOrderPayment($app)->action = \Aifec\Commerce\PaymentAction::paid('PAY-GM-LIC');
    $page = $web->get('/finalizar-compra');
    $place = $web->post('/finalizar-compra/place', frontForm($web, ['email' => 'ana@cliente.test', 'billing[first_name]' => 'Ana', 'billing[last_name]' => 'Ruiz', 'accept_terms' => '1'], $page->body));
    frontOrderPath($place);
    $thanks = $web->follow();
    same(200, $thanks->status);
    $key = (string) $app->db()->value('SELECT license_key FROM {gm_licenses}');
    contains($key, $thanks->body);
    contains('Tus licencias', $thanks->body);
});

test('las claves firmadas llevan el NIF, las unidades y la fecha, y se comprueban con la clave pública', function (): void {
    [$pem, $public] = gmLicKeys();
    $app = gmLicShop();
    $app->setSecret('gm_licencias.signing_key', $pem);
    $id = gmLicProduct($app, 'EcomTPV', 0, 12, 'signed');
    $address = ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '28001', 'city' => 'Madrid', 'region' => '28', 'country' => 'ES', 'phone' => '999345678', 'vat_id' => '12345678z'];
    $orderId = gmLicPaid($app, [[$id, 0, 3]], ['billing' => $address]);
    $rows = gmLicService($app)->forOrder($orderId);
    same(1, count($rows), 'una sola clave por línea, con las tres unidades dentro');
    $key = (string) $rows[0]['license_key'];
    same('active', (string) $rows[0]['status']);
    same('12345678Z', (string) $rows[0]['holder']);
    same(3, (int) $rows[0]['units']);
    same(0, strpos($key, 'ECTPV-'));
    $info = SignedKey::inspect($key, $public);
    check($info !== null, 'la clave se lee');
    same('12345678Z', $info['nif']);
    same(3, $info['units']);
    same(true, $info['valid']);
    same(SignedKey::dayOf($app->clock()->now()), $info['issued_day']);
    same(SignedKey::dayOf($app->clock()->now() + 365 * 86400), $info['updates_day']);
    $tampered = substr($key, 0, 12) . ($key[12] === 'A' ? 'B' : 'A') . substr($key, 13);
    $broken = SignedKey::inspect($tampered, $public);
    check($broken === null || $broken['valid'] === false, 'una clave tocada no pasa la firma');
    same(false, SignedKey::inspect($key, gmLicKeys()[1])['valid'], 'ni con otra clave pública');
    same(true, gmLicService($app)->verify($key, '')['valid'], 'la API también la reconoce, sin dominio');
});

test('una clave firmada sin NIF válido queda pendiente y el panel la reemite con el NIF bueno', function (): void {
    [$pem, $public] = gmLicKeys();
    $app = gmLicShop();
    $app->setSecret('gm_licencias.signing_key', $pem);
    $id = gmLicProduct($app, 'EcomTPV', 0, 12, 'signed');
    $orderId = gmLicPaid($app, [[$id, 0, 1]]);
    $row = gmLicService($app)->forOrder($orderId)[0];
    same('pending', (string) $row['status']);
    contains('PENDING-', (string) $row['license_key']);
    contains('no tiene NIF', (string) $row['note']);
    same(false, gmLicService($app)->verify((string) $row['license_key'], '')['valid'], 'una pendiente no vale');

    $web = adminClient($app);
    $bad = $web->post('/admin/gm-licencias/' . $row['id'] . '/reissue', ['_token' => aifecToken($web), 'nif' => '12345678A']);
    same(302, $bad->status);
    same('pending', (string) gmLicService($app)->find((int) $row['id'])['status'], 'un NIF malo no emite nada');
    $web->get('/admin/r/gm_licenses/' . $row['id']);
    $ok = $web->post('/admin/gm-licencias/' . $row['id'] . '/reissue', ['_token' => aifecToken($web), 'nif' => '12345678Z']);
    same(302, $ok->status);
    $fresh = gmLicService($app)->find((int) $row['id']);
    same('active', (string) $fresh['status']);
    same('12345678Z', (string) $fresh['holder']);
    same(true, SignedKey::inspect((string) $fresh['license_key'], $public)['valid']);
});

test('el panel: listado con búsqueda por clave, correo y producto; ficha con dominios; alta manual; y 403 sin permiso', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Módulo Rojo & Co', 2);
    $orderId = gmLicPaid($app, [[$id, 0, 1]]);
    $row = gmLicService($app)->forOrder($orderId)[0];
    gmLicService($app)->verify((string) $row['license_key'], 'tienda-uno.test');
    $other = gmLicService($app)->createManual(['email' => 'luis@cliente.test', 'product_name' => 'Otro módulo', 'max_domains' => 1]);

    $web = adminClient($app);
    $list = $web->get('/admin/r/gm_licenses');
    same(200, $list->status);
    contains((string) $row['license_key'], $list->body);
    contains('Módulo Rojo &amp; Co', $list->body);
    foreach ([(string) $row['license_key'], 'ana@cliente.test', 'Rojo'] as $term) {
        $r = $web->get('/admin/r/gm_licenses?q=' . rawurlencode($term));
        contains((string) $row['license_key'], $r->body, 'se encuentra por ' . $term);
        notContains('Otro módulo', $r->body);
    }
    contains('Otro módulo', $web->get('/admin/r/gm_licenses?q=luis')->body);

    $sheet = $web->get('/admin/r/gm_licenses/' . $row['id']);
    same(200, $sheet->status);
    contains('tienda-uno.test', $sheet->body);
    contains('/admin/gm-licencias/' . $row['id'] . '/revoke', $sheet->body);

    $new = $web->get('/admin/r/gm_licenses/new');
    same(200, $new->status);
    $save = $web->post('/admin/r/gm_licenses/save', ['_token' => aifecToken($web), 'f' => ['email' => 'nueva@cliente.test', 'product_name' => 'Alta a mano', 'kind' => 'domain', 'status' => 'active', 'max_domains' => '3', 'license_key' => '']]);
    same(302, $save->status, $save->body);
    $created = $app->db()->row("SELECT * FROM {gm_licenses} WHERE product_name = 'Alta a mano'");
    check($created !== null && KeyFormat::looksValid((string) $created['license_key']), 'se generó una clave');
    same(3, (int) $created['max_domains']);
    check($other > 0, 'el alta por servicio también funciona');

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/r/gm_licenses')->status);
    same(403, $support->get('/admin/r/gm_licenses/' . $row['id'])->status);
});

test('las acciones del panel: revocar, reactivar, ampliar, quitar un dominio; y sin permiso no pasa nada', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Módulo', 2, 12);
    $orderId = gmLicPaid($app, [[$id, 0, 1]]);
    $row = gmLicService($app)->forOrder($orderId)[0];
    $svc = gmLicService($app);
    $svc->verify((string) $row['license_key'], 'tienda-uno.test');
    $domainId = (int) $app->db()->value('SELECT id FROM {gm_license_domains} WHERE license_id = ?', [(int) $row['id']]);
    $web = adminClient($app);
    $post = static function (string $path, array $data = []) use ($web, $row): \Aifec\Kernel\Response {
        $web->get('/admin/r/gm_licenses/' . $row['id']);

        return $web->post('/admin/gm-licencias/' . $row['id'] . '/' . $path, ['_token' => aifecToken($web)] + $data);
    };

    same(302, $post('revoke')->status);
    same('revoked', (string) $svc->find((int) $row['id'])['status']);
    same(false, $svc->verify((string) $row['license_key'], 'tienda-uno.test')['valid']);
    $post('reactivate');
    same('active', (string) $svc->find((int) $row['id'])['status']);
    same(true, $svc->verify((string) $row['license_key'], 'tienda-uno.test')['valid']);
    $post('extend', ['months' => '6']);
    same('2028-05-12', substr((string) $svc->find((int) $row['id'])['updates_until'], 0, 10), 'doce meses de la compra más seis');
    $post('domain/' . $domainId . '/remove');
    same(0, aifecOrderCount($app, 'gm_license_domains'));
    check(aifecOrderCount($app, 'audit', "action LIKE 'gm_license_%'") >= 4, 'las acciones quedan en el registro de actividad');

    $guest = adminClient($app, 'support');
    $guest->get('/admin/profile');
    same(403, $guest->post('/admin/gm-licencias/' . $row['id'] . '/revoke', ['_token' => aifecToken($guest)])->status);
    same('active', (string) $svc->find((int) $row['id'])['status']);
});

test('el dato tipado del producto sale en su ficha y la pestaña del panel lo guarda', function (): void {
    $app = gmLicShop();
    $id = gmLicProduct($app, 'Módulo', 3, 12);
    $extra = aifecCatalog($app)->product($id)['extra']['gm_license'];
    same('Licencia', $extra['label']);
    same('text', $extra['type']);
    same(true, $extra['public']);
    contains('3 dominios', (string) $extra['value']);
    contains('12 meses de actualizaciones', (string) $extra['value']);

    $plain = aifecProduct($app, ['name' => 'Taza']);
    check(!isset(aifecCatalog($app)->product($plain)['extra']['gm_license']), 'un producto sin licencia no lleva el dato');

    $form = $app->adminDefinitions()['catalog']['resources']['products']['form']($app, $id);
    same('Licences', $form['tabs']['gllicence']);
    same('domain', $form['fields']['gm_lic_kind']['default']);
    same(3, $form['fields']['gm_lic_domains']['default']);
    check(isset($form['fields']['price']), 'los campos del catálogo siguen ahí');

    $app->hooks()->fire('admin.resource.saved', 'products', $plain, ['gm_lic_kind' => 'domain', 'gm_lic_domains' => 5, 'gm_lic_months' => 24], null);
    same(['kind' => 'domain', 'max_domains' => 5, 'update_months' => 24], Products::get($app, $plain));
    check(isset(aifecCatalog($app)->product($plain)['extra']['gm_license']), 'ahora sí lo lleva');
    $app->hooks()->fire('admin.resource.saved', 'products', $plain, ['gm_lic_kind' => 'none'], null);
    same(null, Products::get($app, $plain));
    $app->hooks()->fire('admin.resource.saved', 'customers', $plain, ['gm_lic_kind' => 'domain'], null);
    same(null, Products::get($app, $plain), 'otro recurso no cuenta');
});

test('el NIF, el formato de la clave y el dominio se normalizan y se validan', function (): void {
    foreach (['12345678Z', '12345678z', '12.345.678-Z', 'X1234567L', 'B12345674', 'A23456783'] as $nif) {
        same(true, Nif::valid($nif), $nif);
    }
    foreach (['12345678A', '', '1234', 'Z9999999Z', 'B12345675', 'ABCDEFGHI'] as $nif) {
        same(false, Nif::valid($nif), 'no vale «' . $nif . '»');
    }
    $key = KeyFormat::generate();
    same(true, KeyFormat::looksValid($key));
    same($key, KeyFormat::normalize(' ' . strtolower($key) . "\n"));
    same(false, KeyFormat::looksValid('ABCDE-FGHIJ'));
    same(false, KeyFormat::looksValid(str_replace('A', '0', 'AAAAA-AAAAA-AAAAA-AAAAA-AAAAA')));
    same('tienda.test', KeyFormat::domain('HTTPS://WWW.Tienda.test:8443/ruta?x=1#y'));
    same('', KeyFormat::domain('no es un dominio'));
    same('', KeyFormat::domain('<script>'));
    same('localhost', KeyFormat::domain('localhost'));
    same('', KeyFormat::domain(str_repeat('a', 200) . '.test'));
});

test('gm-licencias se desactiva y la API deja de existir', function (): void {
    $app = gmLicShop();
    $r = $app->service('plugins')->disable('gm-licencias');
    same(true, $r['ok'], (string) $r['message']);
    $off = testApp(['modules' => gmLicActive(), 'settings' => ['modules.active' => $app->settings()->arr('modules.active')]]);
    $off->boot();
    check(!$off->modules()->isLoaded('gm-licencias'), 'no se carga');
    same(404, (new TestClient($off))->postJson('/api/gm/licencia/verificar', ['key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'domain' => 'a.test'])->status);
});

test('gm-licencias pasa el contrato AIFEC, también el ciclo de instalar y desinstalar', function (): void {
    $app = gmLicShop();
    $plugins = $app->service('plugins');
    $plugins->useTempBase(tmpDir());
    $report = $plugins->validate('gm-licencias');
    $errors = [];
    foreach ($report['checks'] as $check) {
        if (!$check['ok'] && $check['level'] === 'error') {
            $errors[] = $check['rule'] . ': ' . $check['message'] . ' ' . $check['file'] . ':' . $check['line'];
        }
    }
    same([], $errors);
    same(true, $report['ok']);
    same(true, $report['dynamic']);
});
