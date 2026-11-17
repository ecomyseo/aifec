<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El panel de la API: las claves (se enseñan una sola vez) y los ajustes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Modules\Api\Keys;

require_once __DIR__ . '/helpers.php';

test('el panel declara el recurso, el menú y los permisos de la API; quien no puede, recibe 403', function (): void {
    $app = apiApp();
    $web = adminClient($app);

    $home = $web->get('/admin');
    contains('/admin/r/api_keys', $home->body);
    notContains('/admin/settings/api"', $home->body, 'los ajustes no engordan el menú: se llega desde la pantalla de ajustes');
    contains('/admin/settings/api', $web->get('/admin/settings')->body);
    $caps = Admin::capabilities($app);
    check(isset($caps['api.view'], $caps['api.edit']), 'los permisos están declarados');

    $list = $web->get('/admin/r/api_keys');
    same(200, $list->status);
    contains('Claves de la API', $list->body);
    same(200, $web->get('/admin/settings/api')->status);

    foreach (['support', 'editor'] as $role) {
        $other = adminClient($app, $role);
        foreach (['/admin/r/api_keys', '/admin/r/api_keys/new', '/admin/settings/api'] as $path) {
            same(403, $other->get($path)->status, $role . ' ' . $path);
        }
    }
    check((new TestClient($app))->get('/admin/r/api_keys')->status !== 200, 'sin sesión no se ve');
});

test('crear una clave: se genera con el prefijo, se guarda solo su resumen y se enseña una sola vez', function (): void {
    $app = apiApp();
    $web = adminClient($app);

    $new = $web->get('/admin/r/api_keys/new');
    same(200, $new->status);
    $saved = $web->post('/admin/r/api_keys/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Agente de compras & pruebas', 'scopes' => ['orders:create'], 'active' => '1']]);
    same(302, $saved->status, $saved->body);

    $row = $app->db()->row('SELECT * FROM {api_keys}');
    same('Agente de compras & pruebas', $row['name'], 'el nombre se guarda tal cual, con su «&»');
    same(['orders:create'], Keys::scopes($row['scopes']));
    same(1, (int) $row['active']);
    same(64, strlen($row['key_hash']));
    check($row['created_at'] !== null && $row['created_at'] !== '', 'con su fecha');

    $page = $web->follow();
    same(200, $page->status);
    preg_match('/aifec_api_[a-f0-9]{48}/', $page->body, $found);
    check(isset($found[0]), 'la clave sale en la pantalla siguiente');
    same(Keys::hash($found[0]), $row['key_hash'], 'y es la que corresponde al resumen guardado');
    contains('Copia esta clave ahora', $page->body);
    contains('Agente de compras &amp; pruebas', $page->body, 'el nombre sale escapado');
    notContains($found[0], $app->db()->value('SELECT scopes FROM {api_keys}') . $row['name'], 'la clave en claro no está en la base de datos');

    $again = $web->get('/admin/r/api_keys/' . $row['id']);
    notContains($found[0], $again->body, 'no se vuelve a enseñar');
    $list = $web->get('/admin/r/api_keys');
    notContains($found[0], $list->body);
    notContains((string) $row['key_hash'], $list->body, 'ni su resumen');
    contains('orders:create', $list->body);

    // La clave recién creada funciona.
    assistantShop($app);
    same(200, apiCall($app, 'GET', '/api/v1/store', null, ['authorization' => 'Bearer ' . $found[0]])->status);
    check($app->db()->value('SELECT last_used_at FROM {api_keys}') !== null, 'se apunta su uso');
    $audit = $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE entity = 'api_keys' AND action = 'create'");
    same(1, (int) $audit, 'queda en el registro de actividad');
});

test('editar una clave cambia el nombre, los ámbitos y si está activa, pero nunca su resumen; apagada, deja de valer', function (): void {
    $app = apiApp();
    $web = adminClient($app);
    $key = apiKey($app, ['orders:create'], 'Vieja');
    $row = $app->db()->row('SELECT * FROM {api_keys}');
    $web->get('/admin/r/api_keys/' . $row['id']);

    $saved = $web->post('/admin/r/api_keys/' . $row['id'] . '/save', [
        '_token' => aifecToken($web),
        'f' => ['name' => 'Nueva', 'scopes' => [], 'active' => '0', 'key_hash' => str_repeat('f', 64), 'created_at' => '2000-01-01 00:00:00'],
    ]);

    same(302, $saved->status, $saved->body);
    $after = $app->db()->row('SELECT * FROM {api_keys} WHERE id = ?', [$row['id']]);
    same('Nueva', $after['name']);
    same([], Keys::scopes($after['scopes']));
    same(0, (int) $after['active']);
    same($row['key_hash'], $after['key_hash'], 'el resumen no se toca aunque venga en el formulario');
    same($row['created_at'], $after['created_at']);
    same(401, apiCall($app, 'GET', '/api/v1/store', null, ['authorization' => 'Bearer ' . $key])->status, 'una clave apagada no vale');
});

test('las claves del ERP no salen en este listado ni se pueden cambiar o borrar desde aquí', function (): void {
    $app = apiApp();
    $web = adminClient($app);
    apiKey($app, [], 'Mi agente');
    $app->db()->insert('api_keys', ['name' => 'ERP: Conexión de pruebas', 'key_hash' => hash('sha256', 'aifec_erp_x'), 'scopes' => '["erp:orders"]', 'active' => 1, 'created_at' => $app->clock()->sql()]);
    $erpId = (int) $app->db()->value("SELECT id FROM {api_keys} WHERE name LIKE 'ERP:%'");

    $list = $web->get('/admin/r/api_keys');

    contains('Mi agente', $list->body);
    notContains('Conexión de pruebas', $list->body);
    $web->get('/admin/r/api_keys');
    $edit = $web->post('/admin/r/api_keys/' . $erpId . '/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Robada', 'scopes' => [], 'active' => '1']]);
    same(422, $edit->status);
    same('ERP: Conexión de pruebas', $app->db()->value('SELECT name FROM {api_keys} WHERE id = ?', [$erpId]));
    $web->get('/admin/r/api_keys');
    $web->post('/admin/r/api_keys/' . $erpId . '/delete', ['_token' => aifecToken($web)]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {api_keys} WHERE id = ?', [$erpId]), 'la clave del ERP sigue ahí');
});

test('los ajustes de la API se guardan en su pestaña y se leen donde se usan: el tope de pedidos y los orígenes', function (): void {
    $app = apiApp();
    $web = adminClient($app);

    $form = $web->get('/admin/settings/api');
    same(200, $form->status);
    foreach (['apgeneral', 'aplimits', 'aporders'] as $tab) {
        contains($tab, $form->body, 'la pestaña ' . $tab);
    }
    contains('api.max_order_cents', $form->body);
    $saved = $web->post('/admin/settings/api', [
        '_token' => aifecToken($web),
        'f' => ['api.enabled' => '1', 'api.cors_origins' => "https://app.ejemplo.test\nhttps://otra.ejemplo.test", 'api.rate_per_minute' => '50', 'api.rate_per_minute_key' => '500',
            'api.max_order_cents' => '120,50', 'api.max_orders_per_hour' => '3'],
    ]);

    same(302, $saved->status, $saved->body);
    same(12050, $app->settings()->int('api.max_order_cents'), 'el importe se guarda en céntimos');
    same(3, $app->settings()->int('api.max_orders_per_hour'));
    same(50, $app->settings()->int('api.rate_per_minute'));
    contains('https://otra.ejemplo.test', $app->settings()->str('api.cors_origins'));
});
