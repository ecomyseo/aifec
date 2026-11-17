<?php
/**
 * AIFEC — pruebas de los formularios: el de contacto de serie, el constructor del panel, la validación del servicio, el guardado
 * de entradas, el listado y la ficha de mensajes (con lo que escribe una persona escapado), marcar como leída, borrar y exportar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\Cms\Cms;
use Aifec\Modules\Cms\Forms;

require_once __DIR__ . '/helpers.php';

function cmsFormService(App $app): Cms
{
    $cms = $app->service('cms');
    check($cms instanceof Cms, 'el servicio cms es el del módulo');

    return $cms;
}

/**
 * @param array<string,mixed> $f
 */
function cmsSaveForm(TestClient $web, array $f, ?int $id = null): Response
{
    $web->get('/admin/r/forms/' . ($id !== null ? (string) $id : 'new'));
    $f += [
        'name' => 'Pedido especial', 'code' => 'pedido',
        'fields' => [
            ['label' => 'Tu nombre', 'key' => '', 'type' => 'text', 'required' => '1', 'options' => ''],
            ['label' => 'Modelo', 'key' => '', 'type' => 'select', 'required' => '0', 'options' => "Mesa\nSilla\n  Mesa  \n"],
            ['label' => 'Acepto', 'key' => '', 'type' => 'consent', 'required' => '0', 'options' => ''],
        ],
        'notify_to' => 'tienda@ejemplo.test', 'thanks' => 'Gracias por tu pedido',
    ];

    return $web->post('/admin/r/forms/' . ($id !== null ? $id . '/save' : 'save'), ['_token' => aifecToken($web), 'f' => $f]);
}

/**
 * Un formulario de pruebas con todos los tipos de campo, dado de alta directamente.
 */
function cmsAllTypesForm(App $app): int
{
    return $app->db()->insert('forms', [
        'code' => 'todo', 'name' => 'Todo', 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql(),
        'fields' => json_encode([
            ['key' => 'nombre', 'type' => 'text', 'label' => 'Nombre', 'required' => 1],
            ['key' => 'correo', 'type' => 'email', 'label' => 'Correo', 'required' => 0],
            ['key' => 'telefono', 'type' => 'tel', 'label' => 'Teléfono', 'required' => 0],
            ['key' => 'mensaje', 'type' => 'textarea', 'label' => 'Mensaje', 'required' => 0],
            ['key' => 'modelo', 'type' => 'select', 'label' => 'Modelo', 'required' => 0, 'options' => ['Mesa', 'Silla']],
            ['key' => 'boletin', 'type' => 'checkbox', 'label' => 'Boletín', 'required' => 0],
            ['key' => 'acepto', 'type' => 'consent', 'label' => 'Acepto', 'required' => 0],
        ]),
    ]);
}

test('el formulario de contacto viene de serie con sus cuatro campos y se lee por identificador y por código', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $form = $cms->form(1);
    check($form !== null, 'existe el formulario 1');
    same('contact', $form['code']);
    same('Contact', $form['name']);
    same(['name', 'email', 'message', 'consent'], array_map(static function (array $f): string {
        return $f['key'];
    }, $form['fields']));
    same(['text', 'email', 'textarea', 'consent'], array_map(static function (array $f): string {
        return $f['type'];
    }, $form['fields']));
    same([1, 1, 1, 1], array_map(static function (array $f): int {
        return $f['required'];
    }, $form['fields']));
    same($form, $cms->formByCode('contact'));
    foreach (['id', 'code', 'name', 'fields', 'settings'] as $key) {
        check(array_key_exists($key, $form), 'falta la clave ' . $key);
    }
    check(array_key_exists('notify_to', $form['settings']) && array_key_exists('thanks', $form['settings']), 'los ajustes');
    same(null, $cms->form(99999));
    same(null, $cms->formByCode('no-existe'));
});

test('el constructor del panel guarda los campos con su clave, las opciones y los ajustes, y vuelve a enseñarlos', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $r = cmsSaveForm($web, []);
    same(302, $r->status);
    $id = cmsLastId($app, 'forms');
    $form = cmsFormService($app)->form($id);
    same('pedido', $form['code']);
    same(['tu_nombre', 'modelo', 'acepto'], array_map(static function (array $f): string {
        return $f['key'];
    }, $form['fields']), 'la clave sale de la etiqueta');
    same(['Mesa', 'Silla'], $form['fields'][1]['options'], 'una opción por línea, sin repetir ni espacios');
    same(1, $form['fields'][2]['required'], 'la casilla de consentimiento siempre es obligatoria');
    same(0, $form['fields'][1]['required']);
    same('tienda@ejemplo.test', $form['settings']['notify_to']);
    same('Gracias por tu pedido', $form['settings']['thanks']);

    $screen = $web->get('/admin/r/forms/' . $id)->body;
    contains('value="Tu nombre"', $screen);
    contains('value="tu_nombre"', $screen);
    contains('tienda@ejemplo.test', $screen);
    contains('Gracias por tu pedido', $screen);
    contains('/admin/cms/entries/export?form_id=' . $id, $screen, 'el botón de exportar mensajes');
});

test('al volver a guardar un formulario la clave de un campo no cambia aunque cambie su etiqueta', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSaveForm($web, []);
    $id = cmsLastId($app, 'forms');
    cmsSaveForm($web, ['fields' => [
        ['label' => 'Nombre completo', 'key' => 'tu_nombre', 'type' => 'text', 'required' => '1', 'options' => ''],
        ['label' => 'Otro', 'key' => '', 'type' => 'text', 'required' => '0', 'options' => ''],
        ['label' => 'Tu nombre', 'key' => '', 'type' => 'text', 'required' => '0', 'options' => ''],
    ]], $id);
    same(['tu_nombre', 'otro', 'tu_nombre_2'], array_map(static function (array $f): string {
        return $f['key'];
    }, cmsFormService($app)->form($id)['fields']), 'la clave se conserva y las que se repiten reciben un número');
});

test('un formulario sin campos, con un código repetido o con un correo mal escrito no se guarda', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    same(422, cmsSaveForm($web, ['fields' => []])->status, 'sin campos');
    same(0, cmsLastId($app, 'forms') - 1, 'solo existe el de contacto');
    same(422, cmsSaveForm($web, ['code' => 'contact'])->status, 'el código ya existe');
    same(422, cmsSaveForm($web, ['code' => 'Con Espacios'])->status, 'el código no es válido');
    same(422, cmsSaveForm($web, ['notify_to' => 'esto-no-es-un-correo'])->status);
    same(1, cmsLastId($app, 'forms'));
    same(302, cmsSaveForm($web, ['notify_to' => ''])->status, 'el correo de aviso es opcional');
});

test('cleanFields deja los campos como se pintan: claves válidas y únicas, tipos conocidos y opciones solo en las listas', function (): void {
    $fields = Forms::cleanFields([
        ['label' => 'Teléfono móvil', 'type' => 'tel', 'required' => 1],
        ['label' => 'Teléfono móvil', 'type' => 'inventado', 'key' => 'Mal Clave!', 'options' => ['x']],
        ['label' => 'Aviso', 'type' => 'consent', 'key' => 'aviso', 'required' => 0],
        ['label' => 'Lista', 'type' => 'select', 'key' => 'lista', 'options' => "a\nb\n\na"],
        ['label' => '', 'type' => 'text'],
        'no es un campo',
        ['label' => '1234', 'type' => 'text'],
    ]);
    same('telefono_movil', $fields[0]['key']);
    same('telefono_movil_2', $fields[1]['key'], 'la segunda con el mismo nombre');
    same('text', $fields[1]['type'], 'un tipo desconocido pasa a texto');
    same([], $fields[1]['options'], 'solo una lista tiene opciones');
    same(1, $fields[2]['required'], 'el consentimiento siempre obligatorio');
    same(['a', 'b'], $fields[3]['options']);
    check(preg_match('/^[a-z][a-z0-9_]*$/', $fields[4]['key']) === 1, 'una etiqueta vacía también da una clave válida');
    same(6, count($fields), 'lo que no es un campo se descarta');
    check(preg_match('/^[a-z]/', $fields[5]['key']) === 1, 'la clave empieza por letra');
    same([], Forms::cleanFields('basura'));
});

test('la validación acepta lo correcto, limpia los caracteres de control y respeta tildes y emojis', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $form = $cms->formByCode('contact');
    list($clean, $errors) = $cms->formValidate($form, [
        'name' => "  Ana María \x01 ", 'email' => 'ana@cliente.test', 'message' => "Hola\r\nmundo \x07 ñandú 😀 <b>negrita</b>", 'consent' => 'on', 'otro' => 'se ignora',
    ]);
    same([], $errors);
    same('Ana María', $clean['name']);
    same("Hola\nmundo  ñandú 😀 <b>negrita</b>", $clean['message'], 'el texto se guarda tal cual: quien lo pinta lo escapa');
    same('1', $clean['consent']);
    same(['name', 'email', 'message', 'consent'], array_keys($clean), 'solo los campos del formulario');
});

test('la validación pide lo obligatorio y rechaza correos, teléfonos, opciones y textos que no valen', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $contact = $cms->formByCode('contact');
    list(, $errors) = $cms->formValidate($contact, []);
    same(['name', 'email', 'message', 'consent'], array_keys($errors), 'todo lo obligatorio');
    list(, $errors) = $cms->formValidate($contact, ['name' => 'Ana', 'email' => 'ana@', 'message' => 'Hola', 'consent' => '1']);
    same(['email'], array_keys($errors));
    list(, $errors) = $cms->formValidate($contact, ['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => 'Hola', 'consent' => '0']);
    same(['consent'], array_keys($errors), 'un 0 no es aceptar');
    list(, $errors) = $cms->formValidate($contact, ['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => 'Hola', 'consent' => '']);
    same(['consent'], array_keys($errors));
    list(, $errors) = $cms->formValidate($contact, ['name' => ['a', 'b'], 'email' => 'ana@cliente.test', 'message' => 'Hola', 'consent' => '1']);
    same(['name'], array_keys($errors), 'una lista donde va un texto');
    list($clean, $errors) = $cms->formValidate($contact, ['name' => str_repeat('a', 301), 'email' => 'ana@cliente.test', 'message' => 'Hola', 'consent' => '1']);
    same(['name'], array_keys($errors), 'demasiado largo');
    same(300, mb_strlen($clean['name']), 'y no se guarda más de lo que cabe');
    list(, $errors) = $cms->formValidate($contact, ['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => str_repeat('ñ', 5001), 'consent' => '1']);
    same(['message'], array_keys($errors));

    $app->service('cms');
    $all = $cms->form(cmsAllTypesForm($app));
    list($clean, $errors) = $cms->formValidate($all, ['nombre' => 'Ana', 'telefono' => '12ab', 'modelo' => 'Sofá', 'acepto' => '1']);
    same(['telefono', 'modelo'], array_keys($errors), 'teléfono con letras y opción que no existe');
    list($clean, $errors) = $cms->formValidate($all, ['nombre' => 'Ana', 'telefono' => '+34 999 000 001', 'modelo' => 'Mesa', 'boletin' => '1', 'acepto' => '1']);
    same([], $errors);
    same('1', $clean['boletin']);
    same('', $clean['correo'], 'lo que no se rellena queda vacío');
    list($clean) = $cms->formValidate($all, ['nombre' => 'Ana', 'acepto' => '1']);
    same('', $clean['boletin'], 'una casilla sin marcar no viaja');
});

test('formStore guarda la entrada como nueva y rechaza un formulario que no existe', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $id = $cms->formStore(1, ['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => 'Hola', 'consent' => '1'], '203.0.113.7');
    $row = $app->db()->row('SELECT * FROM {form_entries} WHERE id = ?', [$id]);
    same('new', $row['status']);
    same('203.0.113.7', $row['ip']);
    same('2026-11-12 12:00:00', $row['created_at']);
    same('Ana', json_decode($row['data'], true)['name']);
    $sinIp = $cms->formStore(1, ['name' => 'Luis'], '');
    same(null, $app->db()->value('SELECT ip FROM {form_entries} WHERE id = ?', [$sinIp]));
    throws(static function () use ($cms): void {
        $cms->formStore(99999, ['a' => 'b'], '');
    }, \InvalidArgumentException::class);
});

test('el listado de mensajes se filtra por formulario y estado, y la ficha enseña lo que escribió la persona escapado', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $web = adminClient($app);
    $other = cmsAllTypesForm($app);
    $a = $cms->formStore(1, ['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => '<script>alert(1)</script> & más "comillas"', 'consent' => '1'], '203.0.113.7');
    $cms->formStore($other, ['nombre' => 'Luis', 'acepto' => '1']);

    $list = $web->get('/admin/r/form_entries')->body;
    contains('Ana', $list);
    contains('Luis', $list);
    notContains('<script>alert(1)</script>', $list, 'el resumen del listado va escapado');
    contains('Contacto', $list, 'el nombre sembrado del formulario sale traducido');
    $onlyOther = $web->get('/admin/r/form_entries?f[form_id]=' . $other)->body;
    contains('Luis', $onlyOther);
    notContains('Ana', $onlyOther);
    $onlyNew = $web->get('/admin/r/form_entries?f[status]=read')->body;
    notContains('Ana', $onlyNew);

    $ficha = $web->get('/admin/r/form_entries/' . $a);
    same(200, $ficha->status);
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; más &quot;comillas&quot;', $ficha->body);
    notContains('<script>alert(1)</script>', $ficha->body);
    contains('203.0.113.7', $ficha->body);
    contains('ana@cliente.test', $ficha->body);
    contains('Nombre', $ficha->body, 'con la etiqueta de cada campo');
});

test('se puede marcar una entrada como leída, marcar varias a la vez y borrar', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $web = adminClient($app);
    $ids = [];
    for ($i = 1; $i <= 3; ++$i) {
        $ids[] = $cms->formStore(1, ['name' => 'Persona ' . $i, 'email' => 'p' . $i . '@cliente.test', 'message' => 'Hola', 'consent' => '1']);
    }
    $web->get('/admin/r/form_entries/' . $ids[0]);
    $r = $web->post('/admin/r/form_entries/' . $ids[0] . '/save', ['_token' => aifecToken($web), 'f' => ['status' => 'read']]);
    same(302, $r->status);
    same('read', (string) $app->db()->value('SELECT status FROM {form_entries} WHERE id = ?', [$ids[0]]));
    same('Persona 1', (string) json_decode((string) $app->db()->value('SELECT data FROM {form_entries} WHERE id = ?', [$ids[0]]), true)['name'], 'guardar el estado no toca los datos');

    $web->get('/admin/r/form_entries');
    $web->post('/admin/r/form_entries/bulk', ['_token' => aifecToken($web), 'action' => 'read', 'ids' => [(string) $ids[1], (string) $ids[2]]]);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {form_entries} WHERE status = 'new'"));

    $web->get('/admin/r/form_entries/' . $ids[1]);
    $web->post('/admin/r/form_entries/' . $ids[1] . '/delete', ['_token' => aifecToken($web)]);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {form_entries}'));
    same(404, $web->get('/admin/r/form_entries/' . $ids[1])->status);
    same(403, $web->get('/admin/r/form_entries/new')->status, 'los mensajes no se crean a mano');
});

test('exportar los mensajes da un CSV con una columna por campo y sin fórmulas que una hoja de cálculo ejecute', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $web = adminClient($app);
    $cms->formStore(1, ['name' => 'Ana', 'email' => 'ana@cliente.test', 'message' => '=HYPERLINK("http://x.test")', 'consent' => '1'], '203.0.113.7');
    $cms->formStore(1, ['name' => '+34999000001', 'email' => 'luis@cliente.test', 'message' => 'Línea 1' . "\n" . 'Línea 2; con punto y coma', 'consent' => '1']);
    $cms->formStore(cmsAllTypesForm($app), ['nombre' => 'Otra', 'acepto' => '1']);

    $r = $web->get('/admin/cms/entries/export?form_id=1');
    same(200, $r->status);
    contains('text/csv', $r->headers['Content-Type']);
    contains('attachment', $r->headers['Content-Disposition']);
    $csv = $r->body;
    same("\xEF\xBB\xBF", substr($csv, 0, 3), 'con marca de orden de bytes para que Excel lea las tildes');
    contains('Nombre', $csv);
    contains('ana@cliente.test', $csv);
    contains("'=HYPERLINK", $csv, 'una celda que empieza por = lleva una comilla delante');
    contains("'+34999000001", $csv);
    contains('Línea 2; con punto y coma', $csv);
    notContains('Otra', $csv, 'solo el formulario pedido');
    contains('Otra', $web->get('/admin/cms/entries/export')->body, 'sin filtro, todos');
});

test('un formulario con mensajes guardados no se borra; uno vacío sí', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSaveForm($web, []);
    $id = cmsLastId($app, 'forms');
    cmsFormService($app)->formStore($id, ['tu_nombre' => 'Ana', 'acepto' => '1']);
    $web->get('/admin/r/forms/' . $id);
    $web->post('/admin/r/forms/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {forms} WHERE id = ?', [$id]), 'con mensajes se queda');
    $app->db()->delete('form_entries', ['form_id' => $id]);
    $web->get('/admin/r/forms/' . $id);
    $web->post('/admin/r/forms/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {forms} WHERE id = ?', [$id]));
});

test('el listado de formularios cuenta los mensajes y los nuevos, con el nombre sembrado traducido', function (): void {
    $app = cmsApp();
    $cms = cmsFormService($app);
    $web = adminClient($app);
    $cms->formStore(1, ['name' => 'Ana']);
    $cms->formStore(1, ['name' => 'Luis']);
    $app->db()->update('form_entries', ['status' => 'read'], ['id' => 1]);
    $list = $web->get('/admin/r/forms')->body;
    contains('Contacto', $list);
    contains('contact', $list);
    contains('1 nuevo', $list);
    contains('Formularios', $list);
});

test('el inicio del panel avisa de los mensajes sin leer a quien puede verlos', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsFormService($app)->formStore(1, ['name' => 'Ana']);
    $home = $web->get('/admin');
    same(200, $home->status);
    contains('1 mensaje nuevo en los formularios', $home->body);
    contains('/admin/r/form_entries', $home->body);
    $app->db()->update('form_entries', ['status' => 'read'], ['id' => 1]);
    notContains('mensaje nuevo en los formularios', $web->get('/admin')->body);
});
