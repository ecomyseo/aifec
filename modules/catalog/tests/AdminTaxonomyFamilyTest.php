<?php
/**
 * AIFEC — pruebas del panel de tipos de producto (familias): alta con sus atributos, orden que se conserva, borrado y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/AdminTaxonomyKitTest.php';

/**
 * @param array<string,mixed> $form
 */
function taxSaveFamily(TestClient $web, array $form, ?int $id = null): Response
{
    return taxPost($web, $id === null ? '/admin/r/families/new' : '/admin/r/families/' . $id, '/admin/r/families/' . ($id === null ? '' : $id . '/') . 'save', ['f' => $form]);
}

/**
 * Los atributos de una familia en su orden: identificador => posición.
 *
 * @return array<int,int>
 */
function taxFamilyMembers(App $app, int $family): array
{
    $out = [];
    foreach ($app->db()->select('SELECT attribute_id, position FROM {family_attributes} WHERE family_id = ? ORDER BY position', [$family]) as $row) {
        $out[(int) $row['attribute_id']] = (int) $row['position'];
    }

    return $out;
}

test('un tipo de producto se da de alta con los atributos que lo componen', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $talla = aifecAttribute($app, 'talla', 'Talla', ['s', 'm']);
    $color = aifecAttribute($app, 'color', 'Color', ['rojo']);
    aifecAttribute($app, 'peso', 'Peso', [], ['type' => 'number']);

    $r = taxSaveFamily($web, ['name' => 'Ropa', 'position' => '4', 'attributes' => [(string) $talla, (string) $color]]);
    same(302, $r->status);
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {families}');
    same('Ropa', $app->db()->value('SELECT name FROM {families} WHERE id = ?', [$id]));
    same('4', (string) $app->db()->value('SELECT position FROM {families} WHERE id = ?', [$id]));
    same([$talla => 0, $color => 1], taxFamilyMembers($app, $id), 'los atributos marcados, con su orden');

    $edit = $web->get('/admin/r/families/' . $id)->body;
    same(2, preg_match_all('/name="f\[attributes\]\[\]" value="\d+" checked/', $edit), 'al editar salen marcados');
    contains('Talla (talla)', $edit, 'cada casilla lleva el nombre y el código');
    contains('Peso (peso)', $edit, 'y salen también los que no están');
});

test('al editar, los atributos que ya estaban conservan su orden y los nuevos van al final', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $a = aifecAttribute($app, 'a', 'Alfa', ['x']);
    $b = aifecAttribute($app, 'b', 'Beta', ['x']);
    $c = aifecAttribute($app, 'c', 'Gamma', ['x']);
    taxSaveFamily($web, ['name' => 'Tipo', 'position' => '0', 'attributes' => [(string) $b, (string) $a]]);
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {families}');
    same([$b => 0, $a => 1], taxFamilyMembers($app, $id));

    $r = taxSaveFamily($web, ['name' => 'Tipo', 'position' => '0', 'attributes' => [(string) $a, (string) $c, (string) $b]], $id);
    same(302, $r->status);
    same([$b => 0, $a => 1, $c => 2], taxFamilyMembers($app, $id), 'B y A siguen en su orden; C, recién marcado, al final');

    taxSaveFamily($web, ['name' => 'Tipo', 'position' => '0', 'attributes' => [(string) $c]], $id);
    same([$c => 0], taxFamilyMembers($app, $id), 'desmarcar los quita');

    taxSaveFamily($web, ['name' => 'Tipo', 'position' => '0'], $id);
    same([], taxFamilyMembers($app, $id), 'sin ninguna casilla marcada, ninguno');
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {families}'));
});

test('el tipo de producto valida el nombre y no admite atributos que no existen', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $r = taxSaveFamily($web, ['name' => '', 'position' => '0']);
    same(422, $r->status);
    contains('Este campo es obligatorio', $r->body);
    $r = taxSaveFamily($web, ['name' => 'Ropa', 'position' => '0', 'attributes' => ['9999']]);
    same(422, $r->status, 'un atributo que no existe no vale');
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {families}'));
    $r = taxSaveFamily($web, ['name' => 'Ropa & "moda"', 'position' => '0']);
    same(302, $r->status);
    contains(e('Ropa & "moda"'), $web->get('/admin/r/families')->body, 'el nombre sale escapado');
});

test('un tipo con productos no se borra; uno libre se borra con sus atributos', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $talla = aifecAttribute($app, 'talla', 'Talla', ['s']);
    taxSaveFamily($web, ['name' => 'Ropa', 'position' => '0', 'attributes' => [(string) $talla]]);
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {families}');
    $product = aifecProduct($app);
    $app->db()->update('products', ['family_id' => $id], ['id' => $product]);

    $web->get('/admin/r/families');
    $r = $web->post('/admin/r/families/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('1 producto', $web->follow()->body, 'el mensaje dice cuántos productos tiene');
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {families} WHERE id = ?', [$id]));

    $app->db()->update('products', ['family_id' => null], ['id' => $product]);
    $web->get('/admin/r/families');
    $web->post('/admin/r/families/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {families} WHERE id = ?', [$id]));
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {family_attributes} WHERE family_id = ?', [$id]), 'sin sus atributos');
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {attributes} WHERE id = ?', [$talla]), 'el atributo no se borra');
});

test('los tipos de producto piden permiso para verse y para cambiarse', function (): void {
    $app = taxApp();
    $admin = adminClient($app);
    taxSaveFamily($admin, ['name' => 'Ropa', 'position' => '0']);
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {families}');

    $support = adminClient($app, 'support');
    $token = aifecToken($support);
    same(403, $support->get('/admin/r/families')->status);
    same(403, $support->post('/admin/r/families/save', ['_token' => $token, 'f' => ['name' => 'Nuevo', 'position' => '0']])->status);
    same(403, $support->post('/admin/r/families/' . $id . '/delete', ['_token' => $token])->status);

    $viewer = taxViewer($app);
    $token = aifecToken($viewer);
    $r = $viewer->get('/admin/r/families');
    same(200, $r->status);
    contains('Ropa', $r->body);
    notContains('/admin/r/families/new', $r->body);
    same(403, $viewer->post('/admin/r/families/' . $id . '/save', ['_token' => $token, 'f' => ['name' => 'Cambiado', 'position' => '0']])->status);
    same('Ropa', $app->db()->value('SELECT name FROM {families} WHERE id = ?', [$id]), 'no se tocó');
});
