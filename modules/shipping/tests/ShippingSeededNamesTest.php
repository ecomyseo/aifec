<?php
/**
 * AIFEC — las zonas de envío de serie se guardan en inglés («Peninsula») y se traducen al listarlas: su formulario enseña lo mismo
 * que el listado, y guardar sin tocar el nombre lo deja en castellano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/ShippingKit.php';

/** Guarda el formulario de una zona con lo que enseñó el campo del nombre (o con otro nombre). */
function shseedSave(TestClient $web, int $id, ?string $name = null): Aifec\Kernel\Response
{
    $page = $web->get('/admin/r/shipping_zones/' . $id);
    $shown = inputValue($page->body, 'f[name]');
    check($shown !== null, 'el formulario tiene el nombre');

    return $web->post('/admin/r/shipping_zones/' . $id . '/save', ['_token' => aifecToken($web), 'f' => [
        'name' => $name !== null ? $name : (string) $shown, 'active' => '1', 'position' => '0',
        'locations' => [['country' => 'ES', 'region' => '', 'postcodes' => '']],
    ]]);
}

test('zonas: el formulario de una zona de serie enseña su nombre en castellano, igual que el listado', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $list = $web->get('/admin/shipping/zones')->body;
    foreach (['Península', 'Baleares', 'Canarias', 'Ceuta y Melilla'] as $name) {
        contains($name, $list);
    }
    $names = ['Peninsula' => 'Península', 'Balearic Islands' => 'Baleares', 'Canary Islands' => 'Canarias', 'Ceuta and Melilla' => 'Ceuta y Melilla'];
    foreach ($names as $english => $spanish) {
        $id = shippingZoneId($app, $english);
        same($spanish, inputValue($web->get('/admin/r/shipping_zones/' . $id)->body, 'f[name]'), $english);
    }
});

test('zonas: guardar sin tocar el nombre lo deja en castellano; uno cambiado a mano se respeta', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $id = shippingZoneId($app, 'Peninsula');
    same(302, shseedSave($web, $id)->status);
    same('Península', (string) $app->db()->value('SELECT name FROM {shipping_zones} WHERE id = ?', [$id]), 'se guarda lo que había en el campo');
    same('Península', inputValue($web->get('/admin/r/shipping_zones/' . $id)->body, 'f[name]'));
    contains('Península', $web->get('/admin/shipping/zones')->body);

    $canary = shippingZoneId($app, 'Canary Islands');
    same(302, shseedSave($web, $canary, 'Islas del Atlántico')->status);
    same('Islas del Atlántico', (string) $app->db()->value('SELECT name FROM {shipping_zones} WHERE id = ?', [$canary]));
    same('Islas del Atlántico', inputValue($web->get('/admin/r/shipping_zones/' . $canary)->body, 'f[name]'));
});

test('zonas: en un panel en inglés el campo enseña el inglés, y una zona propia se enseña tal cual', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $app->db()->update('users', ['locale' => 'en'], ['email' => 'admin@tienda.test']);
    $app->auth()->reset();
    same('Peninsula', inputValue($web->get('/admin/r/shipping_zones/' . shippingZoneId($app, 'Peninsula'))->body, 'f[name]'));
    $own = (int) $app->db()->insert('shipping_zones', ['name' => 'Zona Norte', 'active' => 1, 'position' => 9]);
    same('Zona Norte', inputValue($web->get('/admin/r/shipping_zones/' . $own)->body, 'f[name]'));
});

test('envío: la pestaña de probar se llama «Probar un envío» y el botón de dentro «Probar»', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $page = $web->get('/admin/shipping/test');
    same(200, $page->status);
    contains('>Probar un envío</a>', $page->body, 'la pestaña');
    notContains('>Pruebas</a>', $page->body);
    contains('Probar</button>', $page->body, 'el botón');
    $app->db()->update('users', ['locale' => 'en'], ['email' => 'admin@tienda.test']);
    $app->auth()->reset();
    contains('>Test a shipment</a>', $web->get('/admin/shipping/test')->body, 'en inglés, «Test a shipment»');
});
