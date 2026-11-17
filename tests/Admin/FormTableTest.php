<?php
/**
 * AIFEC — pruebas del validador, de los formularios y los listados declarativos, y de un recurso completo con
 * textos por idioma y dirección amigable.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Admin\Form;
use Aifec\Admin\Table;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Validation\Validator;

test('validador: limpia, convierte y explica cada fallo', function (): void {
    $app = testApp();
    $app->boot();
    $v = new Validator($app->db());
    list($values, $errors) = $v->validate(
        ['a' => ' 12 ', 'm' => '1.234,56', 'e' => ' X@Y.es', 'r' => 'ab|c', 'b' => 'on', 'n' => '', 'l' => ['x', 'y'], 'j' => '{"a":1}', 'dt' => '2026-11-12T13:30', 'd' => '3.5'],
        ['a' => 'required|int|min:10', 'm' => 'money', 'e' => 'email', 'r' => 'regex:/^ab\|c$/|max:5', 'b' => 'bool', 'n' => 'nullable|int', 'l' => 'array|in:x,y|max:2', 'j' => 'json', 'dt' => 'datetime', 'd' => 'decimal', 'off' => 'bool', 'none' => 'array']
    );
    same([], $errors);
    same(['a' => 12, 'm' => 123456, 'e' => 'x@y.es', 'r' => 'ab|c', 'b' => 1, 'n' => null, 'l' => ['x', 'y'], 'j' => ['a' => 1], 'dt' => '2026-11-12 13:30:00', 'd' => 3.5, 'off' => 0, 'none' => []], $values);

    list(, $errors) = $v->validate(
        ['a' => '9', 'e' => 'no', 'd' => '2026-02-30', 'l' => ['x', 'z'], 's' => 'Con Espacios', 'u' => 'ftp://x', 'c' => 'rojo', 'p' => '12', 'dec' => '1,5', 'same2' => 'b', 'len' => 'abcd', 'arr' => 'x'],
        ['a' => 'int|min:10', 'e' => 'email', 'd' => 'date', 'l' => 'array|in:x,y', 's' => 'slug', 'u' => 'url', 'c' => 'color', 'p' => 'phone', 'dec' => 'decimal', 'same2' => 'same:a', 'len' => 'len:3', 'req' => 'required', 'arr' => 'int']
    );
    same(['a', 'e', 'd', 'l', 's', 'u', 'c', 'p', 'dec', 'same2', 'len', 'req', 'arr'], array_keys($errors), 'cada campo malo tiene su error');
    same('El mínimo es 10', $errors['a']);
    same('Este campo es obligatorio', $errors['req']);

    same(1250, Validator::toCents('12,50'));
    same(1250, Validator::toCents('12.50'));
    same(123456, Validator::toCents('1.234,56'));
    same(123456, Validator::toCents('1,234.56'));
    same(123400, Validator::toCents('1.234'), 'un punto con tres cifras detrás es de millares');
    same(50, Validator::toCents('0,5'));
    same(-310, Validator::toCents('-3,10'));
    same(1250, Validator::toCents('12,5 €'));
    same(1999, Validator::toCents('19,99'), 'sin errores de coma flotante');
    same(null, Validator::toCents('doce'));
    same(null, Validator::toCents(''));

    $app->db()->insert('settings', ['k' => 'uno', 'v' => '1']);
    $problem = null;
    $v->field('uno', ['unique:settings,k'], [], $problem);
    same('Este valor ya está en uso', $problem);
    $v->field('uno', ['unique:settings,k,uno,k'], [], $problem);
    same(null, $problem, 'la propia fila no cuenta');
    $v->field('dos', ['exists:settings,k'], [], $problem);
    same('Elige una de las opciones', $problem);
    throws(function () use ($v): void {
        $error = null;
        $v->field('x', ['inventada'], [], $error);
    }, \LogicException::class);
});

/**
 * @return array<string,mixed>
 */
function aifecFormDefinition(): array
{
    return [
        'tabs' => ['ttmain' => 'General', 'ttmore' => 'Settings'],
        'fields' => [
            'title' => ['label' => 'Name', 'rules' => 'required|max:60', 'i18n' => true],
            'price' => ['type' => 'money', 'label' => 'Currency'],
            'rate' => ['type' => 'percent', 'label' => 'Role', 'scale' => 100],
            'weight' => ['type' => 'number', 'label' => 'Phone', 'decimal' => true],
            'kind' => ['type' => 'select', 'label' => 'Code', 'options' => ['a' => 'Yes', 'b' => 'No'], 'rules' => 'required'],
            'flags' => ['type' => 'checks', 'label' => 'Permissions', 'options' => ['x' => 'Yes', 'y' => 'No']],
            'labels' => ['type' => 'tags', 'label' => 'Languages'],
            'active' => ['type' => 'toggle', 'label' => 'Can sign in', 'tab' => 'ttmore', 'default' => 1],
            'starts_at' => ['type' => 'datetime', 'label' => 'When', 'tab' => 'ttmore'],
            'body' => ['type' => 'richtext', 'label' => 'Content', 'tab' => 'ttmore'],
            'lines' => ['type' => 'repeater', 'label' => 'Changes', 'tab' => 'ttmore', 'fields' => [
                'label' => ['label' => 'Name', 'rules' => 'max:20'],
                'qty' => ['type' => 'number', 'label' => 'Id'],
            ]],
            'secret' => ['type' => 'password', 'label' => 'Password', 'tab' => 'ttmore'],
            'hidden_one' => ['label' => 'Team', 'cap' => 'nobody.can', 'tab' => 'ttmore'],
            'note' => ['type' => 'html', 'label' => '', 'html' => '<p class="nota">hola</p>', 'tab' => 'ttmore'],
        ],
    ];
}

test('formulario: lee lo que manda un navegador y lo deja listo para guardar', function (): void {
    $app = testApp(['settings' => ['i18n.languages' => ['es', 'en']]]);
    $app->boot();
    $form = new Form($app, aifecFormDefinition());
    $post = ['f' => [
        'title' => ['es' => ' Silla & mesa ', 'en' => ''],
        'price' => '12,50', 'rate' => '21', 'weight' => '1.250', 'kind' => 'b',
        'flags' => ['x'], 'labels' => 'rojo, azul,, rojo',
        'active' => '0',
        'starts_at' => '2026-11-12T13:30',
        'body' => '<p onclick="x()">Hola <strong>mundo</strong><script>alert(1)</script></p>',
        'lines' => [['label' => 'A', 'qty' => '2'], ['label' => '', 'qty' => ''], ['label' => 'B', 'qty' => '3']],
        'secret' => '',
        'hidden_one' => 'colado',
    ]];
    list($values, $errors) = $form->validate(Request::create('POST', '/x', $post));
    same([], $errors);
    same(['es' => 'Silla & mesa', 'en' => ''], $values['title']);
    same(1250, $values['price']);
    same(2100, $values['rate'], 'el porcentaje se guarda en centésimas enteras');
    same(1.25, $values['weight'], 'un número de <input type=number> llega con punto decimal');
    same('b', $values['kind']);
    same(['x'], $values['flags']);
    same(['rojo', 'azul'], $values['labels']);
    same(0, $values['active']);
    same('2026-11-12 12:30:00', $values['starts_at'], 'la hora local de Madrid se guarda en UTC');
    notContains('script', $values['body']);
    notContains('onclick', $values['body']);
    contains('<strong>mundo</strong>', $values['body']);
    same([['label' => 'A', 'qty' => 2], ['label' => 'B', 'qty' => 3]], $values['lines'], 'las filas vacías se descartan');
    check(!array_key_exists('secret', $values), 'una contraseña vacía no se toca');
    check(!array_key_exists('hidden_one', $values), 'un campo que la persona no puede ver no se lee aunque llegue');

    $row = $form->store($values);
    check(!array_key_exists('title', $row), 'los textos por idioma no van a la tabla principal');
    same('["x"]', $row['flags']);
    same('["rojo","azul"]', $row['labels']);
    same('[{"label":"A","qty":2},{"label":"B","qty":3}]', $row['lines']);
    $back = $form->load($row + ['id' => 7]);
    same(['x'], $back['flags']);
    same(2100, $back['rate']);

    // Casilla desmarcada = no viaja; interruptor encendido = viaja el 0 oculto y el 1.
    list($values, $errors) = $form->validate(Request::create('POST', '/x', ['f' => ['title' => ['es' => 'X'], 'kind' => 'a', 'active' => '1']]));
    same([], $errors);
    same([], $values['flags']);
    same(1, $values['active']);
    same(null, $values['price']);

    list(, $errors) = $form->validate(Request::create('POST', '/x', ['f' => [
        'title' => ['es' => '', 'en' => 'Chair'], 'kind' => 'zz', 'flags' => ['x', 'q'], 'price' => 'caro', 'weight' => '1,5',
        'lines' => [['label' => 'A', 'qty' => 'dos']], 'starts_at' => 'mañana',
    ]]));
    same(['title', 'price', 'weight', 'kind', 'flags', 'starts_at', 'lines'], array_keys($errors));
    contains('ES:', $errors['title'], 'dice en qué idioma falta');
    contains('Fila 1', $errors['lines']);
});

test('formulario: se pinta con pestañas, idiomas, valores escapados y errores en su pestaña', function (): void {
    $app = testApp(['settings' => ['i18n.languages' => ['es', 'en']]]);
    $app->boot();
    $form = new Form($app, aifecFormDefinition());
    $values = ['title' => ['es' => 'Silla "alta" & <b>', 'en' => 'Chair'], 'price' => 1999, 'rate' => 2150, 'weight' => 1.25, 'kind' => 'b', 'flags' => ['y'],
        'labels' => ['rojo', 'azul'], 'active' => 0, 'starts_at' => '2026-11-12 12:30:00', 'lines' => [['label' => 'A', 'qty' => 2]], 'secret' => 'resumen-guardado'];
    $html = $form->render($values, [], ['action' => '/guardar', 'cancel' => '/volver', 'delete' => '/borrar', 'tab' => 'ttmore']);

    contains('<form class="af-form" method="post" action="/guardar" novalidate', $html);
    contains('name="_token" value="', $html);
    contains('data-af-tab="ttmain" aria-selected="false"', $html);
    contains('data-af-tab="ttmore" aria-selected="true"', $html, 'se abre la pestaña pedida');
    contains('data-af-panel="ttmain" hidden', $html);
    contains('value="Silla &quot;alta&quot; &amp; &lt;b&gt;"', $html, 'los valores salen escapados');
    same('Silla "alta" & <b>', inputValue($html, 'f[title][es]'));
    same('Chair', inputValue($html, 'f[title][en]'));
    contains('data-af-lang="en"', $html);
    same('19,99', inputValue($html, 'f[price]'));
    same('21,5', inputValue($html, 'f[rate]'));
    same('1.25', inputValue($html, 'f[weight]'));
    contains('<option value="b" selected>No</option>', $html);
    contains('name="f[flags][]" value="y" checked', $html);
    notContains('value="x" checked', $html);
    contains('value="rojo, azul"', $html);
    contains('<input type="hidden" name="f[active]" value="0"><input type="checkbox" id="f-active" name="f[active]" value="1">', $html, 'apagado: sin «checked»');
    contains('value="2026-11-12T13:30"', $html, 'la fecha se enseña en la hora de Madrid');
    same('A', inputValue($html, 'f[lines][0][label]'));
    contains('name="f[lines][__i__][qty]"', $html, 'lleva la plantilla para añadir filas');
    notContains('resumen-guardado', $html, 'una contraseña guardada nunca vuelve a la pantalla');
    contains('Guardada. Déjala vacía para conservarla', $html);
    notContains('hidden_one', $html);
    contains('<p class="nota">hola</p>', $html);
    contains('formaction="/borrar"', $html);
    contains('href="/volver"', $html);
    contains('Nombre<span class="af-req"', $html, 'las etiquetas salen traducidas y lo obligatorio, marcado');

    $html = $form->render($values, ['kind' => 'Elige una de las opciones', 'starts_at' => 'Mal'], ['tab' => 'ttmore']);
    contains('data-af-tab="ttmain" aria-selected="true"', $html, 'con errores se abre la primera pestaña que los tiene');
    contains('Hay 2 campos que revisar', $html);
    contains('<span class="af-badge af-badge-bad">1</span>', $html);
    contains('af-field-invalid', $html);

    $single = new Form(testApp(), ['fields' => ['title' => ['label' => 'Name', 'i18n' => true]]]);
    $html = $single->renderFields(['title' => ['es' => 'Uno']]);
    same('Uno', inputValue($html, 'f[title][es]'));
    notContains('data-af-lang=', $html, 'con un solo idioma no hay selector');
    notContains('af-tabs', $html);
});

/** Tabla de pega con 60 filas para las pruebas de listados. */
function aifecThings(App $app): void
{
    $app->boot();
    (new Schema($app->db()))->create('t_things', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 80);
        $t->integer('price');
        $t->boolean('active')->default(1);
        $t->string('kind', 10);
        $t->datetime('created_at');
    });
    for ($i = 1; $i <= 60; $i++) {
        $app->db()->insert('t_things', ['name' => $i === 7 ? 'Oferta 100% algodón' : ($i === 8 ? 'Oferta 100 hilos' : 'Cosa ' . $i), 'price' => $i * 100 + 99, 'active' => $i % 2, 'kind' => $i <= 20 ? 'a' : 'b', 'created_at' => '2026-11-' . sprintf('%02d', ($i % 28) + 1) . ' 10:00:00']);
    }
}

/**
 * @return array<string,mixed>
 */
function aifecThingsConfig(App $app): array
{
    return [
        'from' => 't_things',
        'columns' => [
            'name' => ['label' => 'Name', 'sort' => true, 'link' => true],
            'price' => ['label' => 'Currency', 'type' => 'money', 'sort' => true],
            'active' => ['label' => 'Can sign in', 'type' => 'bool'],
            'kind' => ['label' => 'Code', 'type' => 'badge', 'map' => ['a' => ['Yes', 'ok'], 'b' => ['No', 'warn']]],
            'created_at' => ['label' => 'When', 'type' => 'datetime', 'sort' => true],
        ],
        'search' => ['name'],
        'filters' => ['kind' => ['label' => 'Code', 'options' => ['a' => 'Yes', 'b' => 'No']], 'active' => ['label' => 'Can sign in', 'options' => ['1' => 'Yes', '0' => 'No']]],
        'row_url' => static function (array $row) use ($app): string {
            return $app->url()->plain('/admin/t/' . $row['id']);
        },
        'bulk' => ['delete' => ['label' => 'Delete', 'confirm' => 'Delete this? It cannot be undone.']],
        'bulk_url' => '/admin/t/bulk',
    ];
}

test('listado: busca, filtra, ordena y pagina solo con lo permitido', function (): void {
    $app = testApp();
    aifecThings($app);
    $table = new Table($app, aifecThingsConfig($app));
    $q = static function (string $query) use ($table): array {
        return $table->query(Request::create('GET', '/admin/t' . ($query !== '' ? '?' . $query : '')));
    };
    $d = $q('');
    same([60, 1, 3, 25], [$d['total'], $d['page'], $d['pages'], count($d['rows'])]);
    same(60, (int) $d['rows'][0]['id'], 'por defecto, lo último primero');
    same([1, 25], [$d['from'], $d['to']]);

    same(1, $q('q=' . rawurlencode('100%'))['total'], 'el «%» que escribe la persona es un «%», no un comodín');
    same(2, $q('q=oferta')['total']);
    same(1, $q('q=42')['total'] >= 1 ? 1 : 0, 'un número también busca por identificador');
    same(20, $q('f[kind]=a')['total']);
    same(10, $q('f[kind]=a&f[active]=1')['total']);
    same(60, $q('f[kind]=zzz')['total'], 'un valor de filtro que no existe se ignora');
    same(60, $q('f[otro]=1')['total'], 'un filtro que no está declarado se ignora');

    $d = $q('sort=price&dir=asc');
    same(1, (int) $d['rows'][0]['id']);
    $d = $q('sort=' . rawurlencode('id; DROP TABLE af_t_things') . '&dir=asc');
    same(60, (int) $d['rows'][0]['id'], 'una columna de orden que no está en la lista no llega a la consulta');
    same(60, (int) $app->db()->value('SELECT COUNT(*) FROM {t_things}'));
    same([3, 10], [$q('p=3')['page'], count($q('p=3')['rows'])]);
    same(3, $q('p=99')['page'], 'una página que no existe cae en la última');
});

test('listado: se pinta con enlaces que conservan los filtros, acciones en bloque y estados vacíos', function (): void {
    $app = testApp();
    aifecAdmin($app);
    aifecThings($app);
    $app->router()->get('/admin/t', static function (Request $r, App $app) {
        return Admin::html($app, (new Table($app, aifecThingsConfig($app)))->render($r), ['title' => 'Cosas']);
    })->middleware('admin');
    $app->router()->get('/admin/t/export', static function (Request $r, App $app) {
        return (new Table($app, aifecThingsConfig($app)))->csv($r, 'cosas de "prueba".csv');
    })->middleware('admin');
    $web = new TestClient($app);
    aifecLogin($web);

    $r = $web->get('/admin/t?f[kind]=b&q=cosa');
    same(200, $r->status);
    contains('1–25 de 40', $r->body);
    contains('Página 1 de 2', $r->body);
    contains('href="/admin/t/60"', $r->body);
    contains('60,99', $r->body, 'los importes salen con el formato del idioma');
    contains('af-badge af-badge-warn', $r->body);
    contains('<option value="b" selected>', $r->body);
    contains('value="cosa"', $r->body);
    check(preg_match('#href="(/admin/t\?[^"]*p=2[^"]*)"#', $r->body, $m) === 1, 'hay enlace a la página siguiente');
    contains('kind', $m[1], 'y conserva el filtro');
    contains('q=cosa', $m[1]);
    check(preg_match('#<form method="post" action="/admin/t/bulk" id="(af-bulk-\d+)"#', $r->body, $m) === 1);
    contains('name="ids[]" value="60" form="' . $m[1] . '"', $r->body, 'las casillas pertenecen al formulario de acciones en bloque');
    contains('data-confirm="¿Borrar esto? No se puede deshacer."', $r->body);

    contains('Sin resultados', $web->get('/admin/t?q=nadaquever')->body);
    $app->db()->run('DELETE FROM {t_things}');
    contains('Todavía no hay nada', $web->get('/admin/t')->body);

    aifecThings2($app);
    $r = $web->get('/admin/t/export?q=uno');
    same(200, $r->status);
    contains('attachment; filename="cosas-de--prueba-.csv"', $r->headers['Content-Disposition']);
    $lines = explode("\n", trim($r->body));
    contains('Nombre;Moneda', $lines[0]);
    contains('"Uno; con punto y coma";12,50', $lines[1]);
});

function aifecThings2(App $app): void
{
    $app->db()->insert('t_things', ['name' => 'Uno; con punto y coma', 'price' => 1250, 'active' => 1, 'kind' => 'a', 'created_at' => '2026-11-01 10:00:00']);
}

test('recurso completo: textos por idioma, dirección amigable, reglas propias, caché y borrado', function (): void {
    $app = testApp(['settings' => ['i18n.languages' => ['es', 'en']]]);
    aifecAdmin($app);
    $schema = new Schema($app->db());
    $schema->create('t_pages', static function (TableDef $t): void {
        $t->id();
        $t->string('status', 12)->default('draft');
        $t->string('kind', 12);
        $t->text('tags')->nullable();
        $t->timestamps();
    });
    $schema->create('t_page_texts', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('page_id');
        $t->string('lang', 8);
        $t->string('title', 120)->nullable();
        $t->text('body')->nullable();
        $t->unique(['page_id', 'lang']);
    });
    $saved = [];
    $app->hooks()->on('admin.resource.saved', static function (string $name, int $id) use (&$saved): void {
        $saved[] = $name . ':' . $id;
    });
    $app->hooks()->filter('admin.definitions', static function (array $definitions): array {
        $definitions['testmod'] = [
            'caps' => ['testmod.view' => 'See the dashboard', 'testmod.edit' => 'Change the settings'],
            'menu' => [['group' => 'content', 'items' => [['label' => 'Content', 'resource' => 'tpages', 'cap' => 'testmod.view']]]],
            'resources' => ['tpages' => [
                'table' => 't_pages', 'title' => 'Content', 'singular' => 'Entry', 'label_field' => 'title', 'scope' => ['kind' => 'page'], 'tags' => ['cms'],
                'i18n' => ['table' => 't_page_texts', 'fk' => 'page_id', 'lang' => 'lang'],
                'slug' => ['entity' => 'tpage', 'field' => 'slug', 'from' => 'title', 'prefix' => ['es' => 'info', 'en' => 'about']],
                'list' => ['columns' => ['id' => ['label' => 'Id', 'sort' => true], 'title' => ['label' => 'Name', 'sort' => true, 'link' => true], 'status' => ['label' => 'Code']],
                    'search' => ['title'], 'filters' => ['status' => ['label' => 'Code', 'options' => ['draft' => 'No', 'published' => 'Yes']]]],
                'form' => ['fields' => [
                    'title' => ['label' => 'Name', 'rules' => 'required|max:120', 'i18n' => true],
                    'slug' => ['type' => 'text', 'label' => 'Addresses', 'i18n' => true, 'store' => false],
                    'body' => ['type' => 'richtext', 'label' => 'Content', 'i18n' => true],
                    'status' => ['type' => 'select', 'label' => 'Code', 'options' => ['draft' => 'No', 'published' => 'Yes'], 'default' => 'draft'],
                    'tags' => ['type' => 'tags', 'label' => 'Languages'],
                ]],
                'before_save' => static function (array $values, ?array $current, App $app) {
                    return isset($values['title']['es']) && $values['title']['es'] === 'Prohibido' ? 'Ese título no vale' : $values;
                },
                'before_delete' => static function (array $row): ?string {
                    return $row['status'] === 'published' ? 'Despublícala antes' : null;
                },
            ]],
        ];

        return $definitions;
    });
    $app->boot();
    $app->entity('tpage', static function (Request $r, App $app, array $p) {
        return \Aifec\Kernel\Response::html('PAGINA ' . $p['id']);
    });
    // Una fila de otro tipo en la misma tabla: el recurso no debe verla ni tocarla.
    $other = $app->db()->insert('t_pages', ['status' => 'draft', 'kind' => 'post', 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);

    $web = new TestClient($app);
    aifecLogin($web);
    $r = $web->get('/admin/r/tpages');
    same(200, $r->status);
    contains('Todavía no hay nada', $r->body, 'la fila del otro tipo no se lista');
    contains('/admin/r/tpages', $web->get('/admin')->body, 'el menú enseña el recurso');
    $web->get('/admin/r/tpages/new');
    $token = aifecToken($web);
    $version = $app->cache()->tagVersion('cms');

    $r = $web->post('/admin/r/tpages/save', ['_token' => $token, 'f' => ['title' => ['es' => 'Prohibido', 'en' => ''], 'status' => 'draft']]);
    same(422, $r->status);
    contains('Ese título no vale', $r->body);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {t_pages}'), 'no se ha guardado nada');

    $r = $web->post('/admin/r/tpages/save', ['_token' => $token, 'f' => [
        'title' => ['es' => 'Envíos y devoluciones', 'en' => 'Shipping & returns'], 'slug' => ['es' => '', 'en' => ''],
        'body' => ['es' => '<p>Hola</p>', 'en' => '<p>Hello</p>'], 'status' => 'published', 'tags' => 'legal, envíos',
    ]]);
    same(302, $r->status);
    $id = (int) $app->db()->value("SELECT id FROM {t_pages} WHERE kind = 'page'");
    same('/admin/r/tpages/' . $id, $r->headers['Location']);
    same('page', $app->db()->value('SELECT kind FROM {t_pages} WHERE id = ?', [$id]), 'el ámbito del recurso se fuerza al guardar');
    same(['Envíos y devoluciones', 'Shipping & returns'], $app->db()->column('SELECT title FROM {t_page_texts} WHERE page_id = ? ORDER BY lang DESC', [$id]));
    same('info/envios-y-devoluciones', $app->slugs()->path('tpage', $id, 'es'));
    same('about/shipping-returns', $app->slugs()->path('tpage', $id, 'en'));
    same(['tpages:' . $id], $saved);
    check($app->cache()->tagVersion('cms') > $version, 'se invalida la caché de sus etiquetas');
    same('PAGINA ' . $id, $web->get('/info/envios-y-devoluciones')->body);
    same('PAGINA ' . $id, $web->get('/en/about/shipping-returns')->body);

    $r = $web->get('/admin/r/tpages/' . $id);
    same(200, $r->status);
    same('Shipping & returns', inputValue($r->body, 'f[title][en]'));
    same('envios-y-devoluciones', inputValue($r->body, 'f[slug][es]'));
    contains('value="legal, envíos"', $r->body);
    contains('href="/info/envios-y-devoluciones"', $r->body, 'botón para verla en la tienda');
    contains('<h1>Envíos y devoluciones</h1>', $r->body);

    // Cambio de dirección: la antigua redirige a la nueva.
    $r = $web->post('/admin/r/tpages/' . $id . '/save', ['_token' => $token, 'f' => [
        'title' => ['es' => 'Envíos y devoluciones', 'en' => 'Shipping & returns'], 'slug' => ['es' => 'Envíos', 'en' => 'shipping-returns'],
        'body' => ['es' => '<p>Hola</p>', 'en' => ''], 'status' => 'published', 'tags' => '',
    ]]);
    same(302, $r->status);
    same('info/envios', $app->slugs()->path('tpage', $id, 'es'));
    $r = $web->get('/info/envios-y-devoluciones');
    same(301, $r->status);
    same('/info/envios', $r->headers['Location']);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {t_page_texts} WHERE page_id = ?', [$id]), 'se actualiza, no se duplica');

    // Otra página con el mismo título no le quita la dirección a la primera.
    $web->post('/admin/r/tpages/save', ['_token' => $token, 'f' => ['title' => ['es' => 'Envíos', 'en' => ''], 'slug' => ['es' => '', 'en' => ''], 'status' => 'draft']]);
    $second = (int) $app->db()->value("SELECT MAX(id) FROM {t_pages} WHERE kind = 'page'");
    same('info/envios-2', $app->slugs()->path('tpage', $second, 'es'));

    $web->get('/admin/r/tpages/search.json?q=' . rawurlencode('zzz'));
    same([], $web->json()['items']);
    $web->get('/admin/r/tpages/search.json?q=devol');
    same([['id' => $id, 'label' => 'Envíos y devoluciones']], array_map(static function (array $i): array {
        return ['id' => (int) $i['id'], 'label' => $i['label']];
    }, $web->json()['items']), 'el buscador encuentra por el título, que vive en la tabla de textos');
    $r = $web->get('/admin/r/tpages?q=devol&sort=title&dir=asc&f[status]=published');
    same(200, $r->status);
    contains('<strong>Envíos y devoluciones</strong>', $r->body, 'el listado enseña, busca, filtra y ordena por columnas traducidas');
    contains('1–1 de 1', $r->body);
    contains('1–2 de 2', $web->get('/admin/r/tpages?sort=id')->body);
    same(404, $web->get('/admin/r/tpages/' . $other)->status, 'la fila de otro tipo no se abre desde este recurso');
    same(404, $web->post('/admin/r/tpages/' . $other . '/delete', ['_token' => $token])->status);

    $r = $web->post('/admin/r/tpages/' . $id . '/delete', ['_token' => $token]);
    same(302, $r->status);
    contains('Despublícala antes', $web->follow()->body);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {t_pages} WHERE id = ?', [$id]));
    $web->post('/admin/r/tpages/' . $second . '/delete', ['_token' => $token]);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {t_pages} WHERE id = ?', [$second]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {t_page_texts} WHERE page_id = ?', [$second]), 'se lleva sus textos');
    same(null, $app->slugs()->path('tpage', $second, 'es'), 'y su dirección');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {t_pages} WHERE id = ?', [$other]), 'la fila ajena sigue intacta');
});
