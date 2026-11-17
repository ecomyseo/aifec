<?php
/**
 * AIFEC — los nombres de serie (papeles, zonas…) se guardan en inglés y se traducen al listarlos: al abrir su formulario, el campo
 * tiene que enseñar lo mismo que el listado.
 *
 * Fallo que cubre: el listado decía «Responsable de tienda» y, al abrir el papel, el campo traía «Store manager» (lo encontró quien
 * escribía el manual, 13/11/2026). Regla de Form: un campo de texto con 'seeded' => true enseña t(valor) y, al guardar, guarda
 * lo que haya en el campo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Form;
use Aifec\Kernel\App;

/** El papel de serie con ese código, tal como está en la base de datos. */
function seedRole(App $app, string $code): array
{
    $row = $app->db()->row('SELECT * FROM {roles} WHERE code = ?', [$code]);
    check($row !== null, 'el papel ' . $code . ' existe');

    return (array) $row;
}

/** Envía el formulario de un papel exactamente con lo que enseñó el campo del nombre. */
function seedSaveRole(TestClient $web, array $role, ?string $name = null): Aifec\Kernel\Response
{
    $page = $web->get('/admin/r/roles/' . $role['id']);
    $shown = inputValue($page->body, 'f[name]');
    check($shown !== null, 'el formulario tiene el campo del nombre');

    return $web->post('/admin/r/roles/' . $role['id'] . '/save', [
        '_token' => aifecToken($web), 'f' => ['name' => $name !== null ? $name : (string) $shown, 'code' => $role['code'], 'caps' => (array) json_decode((string) $role['caps'], true)],
    ]);
}

test('papeles: el formulario enseña el nombre de serie en castellano, igual que el listado', function (): void {
    $app = testApp();
    $web = adminClient($app);
    contains('Responsable de tienda', $web->get('/admin/r/roles')->body, 'el listado, traducido');
    $manager = seedRole($app, 'manager');
    same('Store manager', $manager['name'], 'en la base de datos sigue el inglés');
    same('Responsable de tienda', inputValue($web->get('/admin/r/roles/' . $manager['id'])->body, 'f[name]'));
    same('Administrador', inputValue($web->get('/admin/r/roles/' . seedRole($app, 'admin')['id'])->body, 'f[name]'));
    same('Atención al cliente', inputValue($web->get('/admin/r/roles/' . seedRole($app, 'support')['id'])->body, 'f[name]'));
    same('Editor', inputValue($web->get('/admin/r/roles/' . seedRole($app, 'editor')['id'])->body, 'f[name]'));
});

test('papeles: guardar sin tocar el nombre lo deja en castellano y ya no depende de la traducción', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $manager = seedRole($app, 'manager');
    same(302, seedSaveRole($web, $manager)->status);
    same('Responsable de tienda', seedRole($app, 'manager')['name'], 'se guarda lo que había en el campo');
    contains('Responsable de tienda', $web->get('/admin/r/roles')->body);
    same('Responsable de tienda', inputValue($web->get('/admin/r/roles/' . $manager['id'])->body, 'f[name]'), 'y el formulario lo sigue enseñando');
    same('manager', seedRole($app, 'manager')['code'], 'el código no cambia');
});

test('papeles: un nombre cambiado a mano se respeta, también uno propio sin traducción', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $manager = seedRole($app, 'manager');
    same(302, seedSaveRole($web, $manager, 'Jefa de almacén')->status);
    same('Jefa de almacén', seedRole($app, 'manager')['name']);
    same('Jefa de almacén', inputValue($web->get('/admin/r/roles/' . $manager['id'])->body, 'f[name]'));

    $web->get('/admin/r/roles/new');
    $web->post('/admin/r/roles/save', ['_token' => aifecToken($web), 'f' => ['name' => 'Almacén Ñandú', 'code' => 'almacen', 'caps' => ['dashboard.view']]]);
    $own = seedRole($app, 'almacen');
    same('Almacén Ñandú', inputValue($web->get('/admin/r/roles/' . $own['id'])->body, 'f[name]'), 'sin traducción, tal cual');
});

test('papeles: en un panel en inglés el campo enseña el inglés', function (): void {
    $app = testApp();
    $web = adminClient($app);
    $app->db()->update('users', ['locale' => 'en'], ['email' => 'admin@tienda.test']);
    $app->auth()->reset();
    same('Store manager', inputValue($web->get('/admin/r/roles/' . seedRole($app, 'manager')['id'])->body, 'f[name]'));
});

test('Form: solo un campo con seeded => true traduce el valor guardado; los demás lo enseñan tal cual', function (): void {
    $app = testApp();
    $app->boot();
    $form = new Form($app, ['fields' => [
        'name' => ['label' => 'Name', 'seeded' => true],
        'other' => ['label' => 'Other'],
        'free' => ['label' => 'Free', 'seeded' => true],
        'empty' => ['label' => 'Empty', 'seeded' => true],
    ]]);
    $html = $form->render(['name' => 'Store manager', 'other' => 'Store manager', 'free' => 'Un nombre propio & Cía', 'empty' => '']);
    same('Responsable de tienda', inputValue($html, 'f[name]'));
    same('Store manager', inputValue($html, 'f[other]'), 'sin seeded no se toca');
    same('Un nombre propio & Cía', inputValue($html, 'f[free]'), 'un valor sin traducción se queda como está');
    same('', inputValue($html, 'f[empty]'));
    $request = \Aifec\Kernel\Request::create('POST', '/x', ['f' => ['name' => 'Responsable de tienda', 'other' => 'x', 'free' => 'y', 'empty' => '']]);
    [$values] = $form->validate($request);
    same('Responsable de tienda', $values['name'], 'al guardar se guarda lo que había en el campo');
});
