<?php
/**
 * AIFEC — pruebas del panel de atributos y de la pantalla de sus valores: código único y bloqueado si está en uso, variantes solo en
 * tipos de lista, valores por idioma, orden, color, borrado seguro y permisos.
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
 * Campos del formulario de un atributo como los manda un navegador.
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function taxAttributeForm(array $over = []): array
{
    return $over + [
        'code' => 'color', 'name' => ['es' => 'Color'], 'type' => 'select', 'unit' => '', 'filterable' => '1', 'visible' => '1', 'variant' => '0', 'position' => '0',
    ];
}

/**
 * @param array<string,mixed> $form
 */
function taxSaveAttribute(TestClient $web, array $form, ?int $id = null): Response
{
    return taxPost($web, $id === null ? '/admin/r/attributes/new' : '/admin/r/attributes/' . $id, '/admin/r/attributes/' . ($id === null ? '' : $id . '/') . 'save', ['f' => $form]);
}

/**
 * Crea un atributo por el panel y devuelve su identificador.
 *
 * @param array<string,mixed> $over
 */
function taxMakeAttribute(TestClient $web, array $over = []): int
{
    $r = taxSaveAttribute($web, taxAttributeForm($over));
    same(302, $r->status, 'el atributo se crea');

    return (int) $web->app->db()->value('SELECT MAX(id) FROM {attributes}');
}

/**
 * Envía la tabla de valores de un atributo.
 *
 * @param array<int,array<string,mixed>> $rows
 */
function taxSaveValues(TestClient $web, int $attribute, array $rows): Response
{
    $url = '/admin/catalog/attributes/' . $attribute . '/values';

    return taxPost($web, $url, $url, ['f' => ['values' => $rows]]);
}

/**
 * Los valores de un atributo en su orden: código => posición.
 *
 * @return array<string,int>
 */
function taxValueCodes(App $app, int $attribute): array
{
    $out = [];
    foreach ($app->db()->select('SELECT code, position FROM {attribute_values} WHERE attribute_id = ? ORDER BY position, id', [$attribute]) as $row) {
        $out[(string) $row['code']] = (int) $row['position'];
    }

    return $out;
}

/**
 * @return array<string,string> código => etiqueta del idioma
 */
function taxValueLabels(App $app, int $attribute, string $lang): array
{
    $out = [];
    $sql = 'SELECT v.code, i.label FROM {attribute_values} v JOIN {attribute_value_i18n} i ON i.value_id = v.id WHERE v.attribute_id = ? AND i.lang = ?';
    foreach ($app->db()->select($sql, [$attribute, $lang]) as $row) {
        $out[(string) $row['code']] = (string) $row['label'];
    }
    ksort($out);

    return $out;
}

test('un atributo se da de alta con su nombre por idioma y el código tiene que ser único', function (): void {
    $app = taxApp([], true);
    $web = adminClient($app);
    $id = taxMakeAttribute($web, ['name' => ['es' => 'Color', 'en' => 'Colour'], 'unit' => '', 'position' => '2']);
    $row = $app->db()->row('SELECT * FROM {attributes} WHERE id = ?', [$id]);
    same('color', $row['code']);
    same('select', $row['type']);
    same(null, $row['unit'], 'una unidad vacía es «sin valor»');
    same('2', (string) $row['position']);
    $names = [];
    foreach ($app->db()->select('SELECT lang, name FROM {attribute_i18n} WHERE attribute_id = ?', [$id]) as $text) {
        $names[$text['lang']] = $text['name'];
    }
    ksort($names);
    same(['en' => 'Colour', 'es' => 'Color'], $names);

    $r = taxSaveAttribute($web, taxAttributeForm(['name' => ['es' => 'Otro color']]));
    same(422, $r->status, 'el mismo código no se admite');
    contains('Este valor ya está en uso', $r->body);
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {attributes}'));

    $r = taxSaveAttribute($web, taxAttributeForm(['name' => ['es' => 'Color claro'], 'unit' => 'cm']), $id);
    same(302, $r->status, 'al editar, el propio código no cuenta como repetido');
    same('cm', $app->db()->value('SELECT unit FROM {attributes} WHERE id = ?', [$id]));

    $r = taxSaveAttribute($web, taxAttributeForm(['code' => 'Con Espacios']));
    same(422, $r->status, 'el código es un slug');
});

test('«sirve para variantes» solo vale en los tipos de lista que se eligen de uno en uno', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    foreach (['text', 'number', 'bool', 'multiselect'] as $type) {
        $r = taxSaveAttribute($web, taxAttributeForm(['code' => 'a-' . $type, 'type' => $type, 'variant' => '1']));
        same(422, $r->status, 'el tipo ' . $type . ' no hace variantes');
        contains('Solo las listas de opciones y los colores pueden crear variantes', $r->body);
    }
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {attributes}'));

    foreach (['select', 'color'] as $type) {
        $r = taxSaveAttribute($web, taxAttributeForm(['code' => 'b-' . $type, 'type' => $type, 'variant' => '1']));
        same(302, $r->status, 'el tipo ' . $type . ' sí');
    }
    same(2, taxScalar($app, 'SELECT COUNT(*) FROM {attributes} WHERE variant = 1'));

    $r = taxSaveAttribute($web, taxAttributeForm(['code' => 'c-text', 'type' => 'text', 'variant' => '0']));
    same(302, $r->status, 'un texto sin variantes se guarda');
    $r = taxSaveAttribute($web, taxAttributeForm(['code' => 'd', 'type' => 'inventado']));
    same(422, $r->status, 'un tipo que no existe tampoco');
});

test('el código de un atributo que ya usan los productos no se puede cambiar', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $id = taxMakeAttribute($web);
    $locked = static function (string $html): bool {
        return preg_match('/<input\b[^>]*\breadonly\b[^>]*>/', (string) preg_replace('/^.*?(<input\b[^>]*name="f\[code\]"[^>]*>).*$/s', '$1', $html)) === 1;
    };
    $edit = $web->get('/admin/r/attributes/' . $id)->body;
    check(!$locked($edit), 'sin uso, el código se puede cambiar');

    aifecProduct($app, ['attributes' => ['color' => 'rojo']]);
    $edit = $web->get('/admin/r/attributes/' . $id)->body;
    check($locked($edit), 'con uso, el campo se bloquea');
    contains('su código no puede cambiar', $edit);

    $r = taxSaveAttribute($web, taxAttributeForm(['code' => 'colour']), $id);
    same(422, $r->status);
    contains('su código no puede cambiar', $r->body);
    same('color', $app->db()->value('SELECT code FROM {attributes} WHERE id = ?', [$id]), 'el código sigue igual');

    $r = taxSaveAttribute($web, taxAttributeForm(['name' => ['es' => 'Tono']]), $id);
    same(302, $r->status, 'lo demás sí se puede cambiar');
    same('Tono', $app->db()->value('SELECT name FROM {attribute_i18n} WHERE attribute_id = ?', [$id]));
});

test('los valores se guardan con su etiqueta por idioma, su código y su orden, y se pueden reordenar', function (): void {
    $app = taxApp([], true);
    $web = adminClient($app);
    $id = taxMakeAttribute($web, ['name' => ['es' => 'Color', 'en' => 'Colour']]);
    $screen = $web->get('/admin/catalog/attributes/' . $id . '/values');
    same(200, $screen->status);
    contains('data-af-repeater', $screen->body);
    contains('name="f[values][__i__][label_en]"', $screen->body, 'una columna de etiqueta por idioma');

    $r = taxSaveValues($web, $id, [
        ['id' => '', 'label_es' => 'Rojo', 'label_en' => 'Red', 'code' => '', 'color' => ''],
        ['id' => '', 'label_es' => 'Azul claro', 'label_en' => '', 'code' => 'azul-c', 'color' => ''],
        ['id' => '', 'label_es' => '', 'label_en' => '', 'code' => '', 'color' => ''],
    ]);
    same(302, $r->status);
    same(['rojo' => 0, 'azul-c' => 1], taxValueCodes($app, $id), 'el código vacío se saca de la etiqueta; la fila vacía no cuenta');
    same(['azul-c' => 'Azul claro', 'rojo' => 'Rojo'], taxValueLabels($app, $id, 'es'));
    same(['rojo' => 'Red'], taxValueLabels($app, $id, 'en'), 'sin etiqueta en inglés no hay traducción');
    contains('Valores guardados', $web->follow()->body);

    $ids = [];
    foreach ($app->db()->select('SELECT id, code FROM {attribute_values} WHERE attribute_id = ?', [$id]) as $row) {
        $ids[$row['code']] = (string) $row['id'];
    }
    $r = taxSaveValues($web, $id, [
        ['id' => $ids['azul-c'], 'label_es' => 'Azul claro', 'label_en' => 'Light blue', 'code' => 'azul-c', 'color' => ''],
        ['id' => $ids['rojo'], 'label_es' => 'Rojo', 'label_en' => 'Red', 'code' => 'rojo', 'color' => ''],
    ]);
    same(302, $r->status);
    same(['azul-c' => 0, 'rojo' => 1], taxValueCodes($app, $id), 'el orden de las filas es la posición');
    same($ids['azul-c'], (string) $app->db()->value('SELECT id FROM {attribute_values} WHERE code = ?', ['azul-c']), 'son los mismos valores, no copias');
    same(['azul-c' => 'Light blue', 'rojo' => 'Red'], taxValueLabels($app, $id, 'en'));

    $r = taxSaveValues($web, $id, [
        ['id' => $ids['azul-c'], 'label_es' => 'Azul claro', 'label_en' => '', 'code' => 'rojo', 'color' => ''],
        ['id' => $ids['rojo'], 'label_es' => 'Rojo', 'label_en' => 'Red', 'code' => 'azul-c', 'color' => ''],
    ]);
    same(302, $r->status, 'dos valores pueden intercambiar sus códigos');
    $codes = array_column($app->db()->select('SELECT id, code FROM {attribute_values} WHERE attribute_id = ?', [$id]), 'code', 'id');
    ksort($codes);
    $expected = [(int) $ids['azul-c'] => 'rojo', (int) $ids['rojo'] => 'azul-c'];
    ksort($expected);
    same($expected, $codes);
    same(['azul-c' => 'Red'], taxValueLabels($app, $id, 'en'), 'la etiqueta que se vacía quita su traducción, y las otras siguen a su valor');
});

test('los valores de un atributo de color guardan el color; en los demás no se guarda', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $color = taxMakeAttribute($web, ['code' => 'tono', 'type' => 'color']);
    same(200, $web->get('/admin/catalog/attributes/' . $color . '/values')->status);
    contains('type="color"', $web->last->body, 'la tabla pide el color');
    $r = taxSaveValues($web, $color, [['id' => '', 'label_es' => 'Rojo', 'code' => '', 'color' => '#FF0000']]);
    same(302, $r->status);
    same('#ff0000', $app->db()->value('SELECT color FROM {attribute_values} WHERE attribute_id = ?', [$color]));

    $talla = taxMakeAttribute($web, ['code' => 'talla', 'type' => 'select']);
    notContains('type="color"', $web->get('/admin/catalog/attributes/' . $talla . '/values')->body);
    taxSaveValues($web, $talla, [['id' => '', 'label_es' => 'M', 'code' => '', 'color' => '#00FF00']]);
    same(null, $app->db()->value('SELECT color FROM {attribute_values} WHERE attribute_id = ?', [$talla]), 'una talla no tiene color');
});

test('la tabla de valores avisa de lo que falta o se repite y no guarda nada', function (): void {
    $app = taxApp([], true);
    $web = adminClient($app);
    $id = taxMakeAttribute($web, ['name' => ['es' => 'Color', 'en' => 'Colour']]);
    taxSaveValues($web, $id, [['id' => '', 'label_es' => 'Rojo', 'label_en' => 'Red', 'code' => '']]);

    $r = taxSaveValues($web, $id, [['id' => '', 'label_es' => '', 'label_en' => 'Blue', 'code' => '']]);
    same(422, $r->status);
    contains('Escribe la etiqueta en el idioma principal', $r->body);
    same(['rojo' => 0], taxValueCodes($app, $id), 'no se guardó nada');

    $r = taxSaveValues($web, $id, [
        ['id' => '', 'label_es' => 'Rojo', 'label_en' => '', 'code' => 'x'],
        ['id' => '', 'label_es' => 'Verde', 'label_en' => '', 'code' => 'x'],
    ]);
    same(422, $r->status);
    contains('Otro valor ya tiene este código', $r->body);

    $r = taxSaveValues($web, $id, [['id' => '', 'label_es' => 'Rojo', 'label_en' => '', 'code' => 'Con Espacios']]);
    same(422, $r->status, 'el código es un slug');
    same(['rojo' => 0], taxValueCodes($app, $id));
});

test('no se borra un valor que usa un producto o una variante, y se dice por cuántos', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $id = aifecAttribute($app, 'talla', 'Talla', ['s', 'm', 'l']);
    aifecProduct($app, ['name' => 'Camiseta', 'attributes' => ['talla' => 'm']]);
    aifecProduct($app, ['name' => 'Jersey', 'variants' => [['sku' => 'J1', 'stock' => 2, 'values' => ['talla' => 's']]]]);
    $current = $app->db()->select('SELECT id, code FROM {attribute_values} WHERE attribute_id = ? ORDER BY position', [$id]);
    $byCode = [];
    foreach ($current as $row) {
        $byCode[$row['code']] = (string) $row['id'];
    }
    $row = static function (string $code, string $label) use ($byCode): array {
        return ['id' => $byCode[$code], 'label_es' => $label, 'code' => $code];
    };

    $r = taxSaveValues($web, $id, [$row('s', 'S'), $row('l', 'L')]);
    same(422, $r->status, 'quitar M, que usa un producto');
    contains('está en uso: 1 productos y 0 variantes', $r->body);
    $r = taxSaveValues($web, $id, [$row('m', 'M'), $row('l', 'L')]);
    same(422, $r->status, 'quitar S, que usa una variante');
    contains('está en uso: 0 productos y 1 variantes', $r->body);
    same(['s' => 0, 'm' => 1, 'l' => 2], taxValueCodes($app, $id), 'no se borró ninguno');

    $r = taxSaveValues($web, $id, [$row('s', 'S'), $row('m', 'M')]);
    same(302, $r->status, 'L no lo usa nadie y se borra');
    same(['s' => 0, 'm' => 1], taxValueCodes($app, $id));
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {attribute_value_i18n} WHERE value_id = ?', [(int) $byCode['l']]), 'con su etiqueta');
});

test('un atributo usado no se borra; uno libre se borra con sus valores y su sitio en los tipos', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $usado = aifecAttribute($app, 'talla', 'Talla', ['s', 'm']);
    aifecProduct($app, ['attributes' => ['talla' => 'm']]);
    $web->get('/admin/r/attributes');
    $r = $web->post('/admin/r/attributes/' . $usado . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('lo usa 1 producto o variante', $web->follow()->body);
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {attributes} WHERE id = ?', [$usado]));

    $libre = aifecAttribute($app, 'peso', 'Peso', ['ligero']);
    $family = $app->db()->insert('families', ['name' => 'Ropa', 'position' => 0]);
    $app->db()->insert('family_attributes', ['family_id' => $family, 'attribute_id' => $libre, 'position' => 0]);
    $web->get('/admin/r/attributes');
    $r = $web->post('/admin/r/attributes/' . $libre . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {attributes} WHERE id = ?', [$libre]));
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {attribute_i18n} WHERE attribute_id = ?', [$libre]), 'sin su nombre');
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {attribute_values} WHERE attribute_id = ?', [$libre]), 'sin sus valores');
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {family_attributes} WHERE attribute_id = ?', [$libre]), 'ni en el tipo de producto');
});

test('los atributos sin lista no tienen pantalla de valores, y las dos pantallas piden permiso', function (): void {
    $app = taxApp();
    $admin = adminClient($app);
    $texto = taxMakeAttribute($admin, ['code' => 'nota', 'type' => 'text']);
    $lista = taxMakeAttribute($admin, ['code' => 'talla', 'type' => 'select']);
    taxSaveValues($admin, $lista, [['id' => '', 'label_es' => 'M', 'code' => '']]);
    same(404, $admin->get('/admin/catalog/attributes/' . $texto . '/values')->status, 'un texto no tiene valores');
    same(404, $admin->get('/admin/catalog/attributes/99999/values')->status);
    contains('/admin/catalog/attributes/' . $lista . '/values', $admin->get('/admin/r/attributes/' . $lista)->body, 'la ficha enlaza a los valores');
    notContains('/values', $admin->get('/admin/r/attributes/' . $texto)->body);

    $support = adminClient($app, 'support');
    $token = aifecToken($support);
    same(403, $support->get('/admin/catalog/attributes/' . $lista . '/values')->status);
    same(403, $support->get('/admin/r/attributes')->status);
    same(403, $support->post('/admin/catalog/attributes/' . $lista . '/values', ['_token' => $token, 'f' => ['values' => []]])->status);

    $viewer = taxViewer($app);
    $token = aifecToken($viewer);
    $r = $viewer->get('/admin/catalog/attributes/' . $lista . '/values');
    same(200, $r->status, 'con «ver» se ven los valores');
    contains('<strong>M</strong>', $r->body);
    notContains('data-af-repeater', $r->body, 'pero no la tabla editable');
    same(403, $viewer->post('/admin/catalog/attributes/' . $lista . '/values', ['_token' => $token, 'f' => ['values' => [['id' => '', 'label_es' => 'X']]]])->status);
    same(['m' => 0], taxValueCodes($app, $lista), 'no se tocó');
});

test('un atributo con guion bajo en el código (como llegan de una importación) se puede abrir y guardar sin cambiar nada', function (): void {
    // 14/11/2026, en vivo (demo de calzado): «alto_tacon», importado y usado por productos (código de solo lectura), no se podía
    // guardar: «Usa solo minúsculas, números y guiones».
    $app = taxApp();
    $web = adminClient($app);
    $id = taxMakeAttribute($web, ['code' => 'alto_tacon', 'name' => ['es' => 'Alto de tacón']]);
    same('alto_tacon', (string) $app->db()->value('SELECT code FROM {attributes} WHERE id = ?', [$id]), 'se da de alta con su guion bajo');
    $again = taxSaveAttribute($web, taxAttributeForm(['code' => 'alto_tacon', 'name' => ['es' => 'Alto del tacón']]), $id);
    check(in_array($again->status, [302, 303], true), 'se guarda (' . $again->status . ')');
    same('Alto del tacón', inputValue($web->get('/admin/r/attributes/' . $id)->body, 'f[name][es]'), 'y el cambio queda guardado');
    $bad = taxSaveAttribute($web, taxAttributeForm(['code' => 'Alto Tacón']));
    same(422, $bad->status, 'mayúsculas, espacios o tildes siguen sin valer');
    contains('Usa solo minúsculas, números, guiones y guiones bajos', (string) $bad->body);
});

test('el listado de atributos enseña el tipo y cuántos valores tiene, y un nombre con «&» sale escapado', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $id = taxMakeAttribute($web, ['code' => 'talla', 'name' => ['es' => 'Talla & "corte"']]);
    taxSaveValues($web, $id, [['id' => '', 'label_es' => 'S'], ['id' => '', 'label_es' => 'M']]);
    $body = $web->get('/admin/r/attributes')->body;
    contains(e('Talla & "corte"'), $body);
    contains('Lista de opciones', $body, 'el tipo');
    contains('/admin/catalog/attributes/' . $id . '/values">2</a>', $body, 'el número de valores enlaza a su pantalla');
    same('Talla & "corte"', inputValue($web->get('/admin/r/attributes/' . $id)->body, 'f[name][es]'));
    // Cada fila lleva a su atributo: el enlace usaba el nombre del último filtro («/admin/r/type/2») y daba 404 (14/11/2026, en vivo).
    preg_match_all('#href="(/admin/r/[a-z0-9_-]+)/(\d+)"#', $body, $links, PREG_SET_ORDER);
    check($links !== [], 'las filas llevan enlace');
    foreach ($links as $link) {
        same('/admin/r/attributes', $link[1], 'la fila enlaza al recurso de atributos');
    }
    same(200, $web->get('/admin/r/attributes/' . $id)->status);
});
