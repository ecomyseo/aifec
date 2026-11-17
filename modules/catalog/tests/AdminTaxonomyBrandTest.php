<?php
/**
 * AIFEC — pruebas del panel de marcas: alta con su dirección, cambio de dirección con redirección, borrado, validaciones y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/AdminTaxonomyKitTest.php';

/**
 * Campos del formulario de una marca como los manda un navegador.
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function taxBrandForm(array $over = []): array
{
    return $over + ['name' => 'Ikea', 'slug' => '', 'description' => '', 'website' => '', 'media_id' => '', 'active' => '1', 'position' => '0'];
}

/**
 * @param array<string,mixed> $form
 */
function taxSaveBrand(TestClient $web, array $form, ?int $id = null): Response
{
    return taxPost($web, $id === null ? '/admin/r/brands/new' : '/admin/r/brands/' . $id, '/admin/r/brands/' . ($id === null ? '' : $id . '/') . 'save', ['f' => $form]);
}

/** @return array<string,mixed>|null */
function taxBrandRow(App $app, int $id): ?array
{
    return $app->db()->row('SELECT * FROM {brands} WHERE id = ?', [$id]);
}

test('una marca se da de alta con su dirección y lo vacío queda sin valor', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $r = taxSaveBrand($web, taxBrandForm(['name' => 'Ikea & Co', 'position' => '3']));
    same(302, $r->status);
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {brands}');
    $row = taxBrandRow($app, $id);
    same('Ikea & Co', $row['name'], 'el nombre se guarda tal cual');
    same(null, $row['website'], 'una web vacía es «sin valor»');
    same(null, $row['description']);
    same('3', (string) $row['position']);
    same('1', (string) $row['active']);
    same('marca/ikea-co', $app->slugs()->path('brand', $id, 'es'));
    same('/marca/ikea-co', $app->url()->entity('brand', $id));

    $list = $web->get('/admin/r/brands')->body;
    contains(e('Ikea & Co'), $list, 'el listado lo escapa');
    check(preg_match('#<td class="af-num af-hide-sm" data-label="[^"]+">0</td>#', $list) === 1, 'sin productos (la celda lleva el nombre de su columna)');
});

test('cambiar la dirección de una marca deja la antigua redirigiendo', function (): void {
    $app = taxApp();
    $app->entity('brand', static function (Request $request, App $app, array $params): Response {
        return Response::text('marca ' . $params['id']);
    });
    $web = adminClient($app);
    taxSaveBrand($web, taxBrandForm(['website' => 'https://ikea.example']));
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {brands}');
    same('https://ikea.example', taxBrandRow($app, $id)['website']);

    $r = taxSaveBrand($web, taxBrandForm(['name' => 'Ikea Home', 'slug' => 'ikea-home', 'description' => '<p>Muebles</p>']), $id);
    same(302, $r->status);
    same('marca/ikea-home', $app->slugs()->path('brand', $id, 'es'));
    same('<p>Muebles</p>', taxBrandRow($app, $id)['description']);
    $front = new TestClient($app);
    $old = $front->get('/marca/ikea');
    same(301, $old->status, 'la antigua redirige');
    contains('/marca/ikea-home', $old->headers['Location']);
    same(200, $front->get('/marca/ikea-home')->status);

    $edit = $web->get('/admin/r/brands/' . $id)->body;
    contains('value="ikea-home"', $edit, 'la ficha enseña la dirección sin el prefijo');
});

test('no se borra una marca con productos; sin ellos se borra con su dirección', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    taxSaveBrand($web, taxBrandForm());
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {brands}');
    $product = aifecProduct($app, ['brand_id' => $id]);

    $web->get('/admin/r/brands');
    $r = $web->post('/admin/r/brands/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('1 producto', $web->follow()->body, 'el mensaje dice cuántos productos tiene');
    check(taxBrandRow($app, $id) !== null, 'sigue ahí');

    $app->db()->update('products', ['brand_id' => null], ['id' => $product]);
    $web->get('/admin/r/brands');
    $r = $web->post('/admin/r/brands/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(null, taxBrandRow($app, $id), 'se borró');
    same(null, $app->slugs()->path('brand', $id, 'es'));
    same(0, taxScalar($app, "SELECT COUNT(*) FROM {slugs} WHERE type = 'brand' AND entity_id = ?", [$id]), 'y su dirección');
});

test('la marca valida el nombre, la web y la dirección', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $r = taxSaveBrand($web, taxBrandForm(['name' => '']));
    same(422, $r->status);
    contains('Este campo es obligatorio', $r->body);
    $r = taxSaveBrand($web, taxBrandForm(['website' => 'ftp://nada']));
    same(422, $r->status, 'la web tiene que ser http o https');
    $r = taxSaveBrand($web, taxBrandForm(['slug' => 'Con Espacios']));
    same(422, $r->status);
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {brands}'), 'no se guardó ninguna');

    taxSaveBrand($web, taxBrandForm(['name' => 'Ikea']));
    taxSaveBrand($web, taxBrandForm(['name' => 'Ikea']));
    $ids = $app->db()->column('SELECT id FROM {brands} ORDER BY id');
    same('marca/ikea', $app->slugs()->path('brand', (int) $ids[0], 'es'));
    same('marca/ikea-2', $app->slugs()->path('brand', (int) $ids[1], 'es'), 'dos marcas con el mismo nombre no comparten dirección');
});

test('las marcas piden permiso para verse y para cambiarse', function (): void {
    $app = taxApp();
    $admin = adminClient($app);
    taxSaveBrand($admin, taxBrandForm());
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {brands}');

    $support = adminClient($app, 'support');
    $token = aifecToken($support);
    same(403, $support->get('/admin/r/brands')->status);
    same(403, $support->get('/admin/r/brands/new')->status);
    same(403, $support->post('/admin/r/brands/save', ['_token' => $token, 'f' => taxBrandForm(['name' => 'Nueva'])])->status);
    same(403, $support->post('/admin/r/brands/' . $id . '/delete', ['_token' => $token])->status);

    $viewer = taxViewer($app);
    $token = aifecToken($viewer);
    $r = $viewer->get('/admin/r/brands');
    same(200, $r->status);
    contains('Ikea', $r->body);
    notContains('/admin/r/brands/new', $r->body);
    same(403, $viewer->get('/admin/r/brands/new')->status);
    same(403, $viewer->post('/admin/r/brands/' . $id . '/save', ['_token' => $token, 'f' => taxBrandForm(['name' => 'Cambiada'])])->status);
    same('Ikea', taxBrandRow($app, $id)['name'], 'no se tocó');
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {brands}'));
});
