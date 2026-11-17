<?php
/**
 * AIFEC — pruebas del panel de cupones: listado y filtros, alta de cada tipo, validaciones, límites, código generado,
 * columna de usos, borrado, permisos y los campos que solo salen con el catálogo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Schema;
use Aifec\Db\TableDef;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/CouponsKit.php';

/**
 * Campos de un formulario de cupón como los manda un navegador (los interruptores siempre viajan, 0 o 1).
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function couponsForm(array $over = []): array
{
    return $over + [
        'code' => 'VERANO', 'kind' => 'percent', 'percent' => '10,5', 'amount' => '', 'active' => '1', 'note' => '',
        'starts_at' => '', 'ends_at' => '', 'max_uses' => '0', 'max_uses_per_customer' => '0', 'min_subtotal' => '',
        'first_order_only' => '0', 'exclude_sale' => '0', 'combinable' => '0', 'customer_id' => '',
    ];
}

/**
 * Abre la pantalla de alta o de edición y envía el formulario, con la ficha de esa página.
 *
 * @param array<string,mixed> $form
 */
function couponsSave(TestClient $web, array $form, ?int $id = null): Response
{
    $web->get($id === null ? '/admin/r/coupons/new' : '/admin/r/coupons/' . $id);

    return $web->post('/admin/r/coupons/' . ($id === null ? '' : $id . '/') . 'save', ['_token' => aifecToken($web), 'f' => $form]);
}

/** @return array<string,mixed>|null */
function couponsByCode(App $app, string $code): ?array
{
    return $app->db()->row('SELECT * FROM {coupons} WHERE code = ?', [$code]);
}

test('el listado se abre con permiso; sin él, y con solo «ver», no se entra a crear ni a cambiar', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['code' => 'AAA']);
    $web = adminClient($app);
    $r = $web->get('/admin/r/coupons');
    same(200, $r->status);
    contains('Cupones', $r->body);
    contains('<strong>AAA</strong>', $r->body);
    contains('/admin/r/coupons/new', $r->body, 'quien puede editar ve el botón de crear');

    same(403, adminClient($app, 'support')->get('/admin/r/coupons')->status);
    $editor = adminClient($app, 'editor');
    $editorToken = aifecToken($editor);   // la ficha de la página de entrada: las páginas de error no la llevan
    same(403, $editor->get('/admin/r/coupons')->status);
    same(403, $editor->get('/admin/r/coupons/new')->status);
    same(403, $editor->post('/admin/r/coupons/save', ['_token' => $editorToken, 'f' => couponsForm()])->status);
    same(0, couponsCount($app, 'coupons', 'code = ?', ['VERANO']), 'y no se creó nada');

    $app->db()->insert('roles', ['code' => 'viewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'coupons.view']), 'system' => 0]);
    $viewer = adminClient($app, 'viewer');
    $r = $viewer->get('/admin/r/coupons');
    same(200, $r->status, 'con «ver» se ve el listado');
    contains('AAA', $r->body);
    notContains('<strong>AAA</strong>', $r->body, 'sin permiso para editar, el código no es un enlace a la ficha');
    notContains('/admin/r/coupons/new', $r->body, 'pero no el botón de crear');
    $viewerToken = aifecToken($viewer);
    same(403, $viewer->get('/admin/r/coupons/new')->status);
    same(403, $viewer->get('/admin/r/coupons/' . $id)->status);
    same(403, $viewer->post('/admin/r/coupons/' . $id . '/save', ['_token' => $viewerToken, 'f' => couponsForm(['code' => 'CAMBIADO'])])->status);
    same(403, $viewer->post('/admin/r/coupons/' . $id . '/delete', ['_token' => $viewerToken])->status);
    same('AAA', couponsRow($app, $id)['code'], 'no se tocó');
});

test('el listado enseña el valor, los usos y la vigencia, y filtra por tipo y por estado', function (): void {
    $app = couponsTestApp();
    couponsMake($app, ['code' => 'AAA', 'ends_at' => '2027-01-01 00:00:00']);
    couponsMake($app, ['code' => 'BBB', 'kind' => 'fixed', 'amount' => 500, 'ends_at' => '2026-10-01 00:00:00']);
    couponsMake($app, ['code' => 'CCC', 'kind' => 'free_shipping', 'max_uses' => 2, 'uses' => 2]);
    couponsMake($app, ['code' => 'DDD', 'percent' => 1050, 'active' => 0]);
    couponsMake($app, ['code' => 'EEE', 'starts_at' => '2026-12-01 00:00:00']);
    $web = adminClient($app);

    $body = $web->get('/admin/r/coupons')->body;
    contains('10 %', $body);
    contains('10,5 %', $body, 'el porcentaje con decimales');
    contains('5,00', $body, 'el importe fijo en euros');
    contains('Envío gratis', $body);
    contains('2 / 2', $body);
    contains('<span class="af-badge af-badge-warn">Agotado</span>', $body, 'la insignia de agotado (el texto también está en el filtro)');
    contains('<span class="af-badge af-badge-bad">Caducado</span>', $body, 'la insignia de caducado');
    contains('Desde el', $body, 'una vigencia con solo inicio');
    contains('Hasta el', $body, 'una vigencia con solo fin');

    $found = static function (string $query) use ($web): string {
        $body = $web->get('/admin/r/coupons?' . $query)->body;
        $codes = '';
        foreach (['AAA', 'BBB', 'CCC', 'DDD', 'EEE'] as $code) {
            $codes .= strpos($body, '<strong>' . $code . '</strong>') !== false ? $code : '';
        }

        return $codes;
    };
    same('AAABBBCCCDDDEEE', $found(''), 'sin filtro, todos');
    same('BBB', $found('f[kind]=fixed'), 'por tipo: importe fijo');
    same('CCC', $found('f[kind]=free_shipping'), 'por tipo: envío gratis');
    same('AAADDDEEE', $found('f[kind]=percent'), 'por tipo: porcentaje');
    same('AAA', $found('f[status]=valid'), 'vigente: activo, empezado, sin caducar y con usos libres');
    same('BBB', $found('f[status]=expired'), 'caducado');
    same('CCC', $found('f[status]=used_up'), 'agotado');
    same('BBB', $found('f[kind]=fixed&f[status]=expired'), 'los dos filtros a la vez');
    same('', $found('f[kind]=fixed&f[status]=valid'), 'sin coincidencias');
    same('CCC', $found('q=CCC'), 'búsqueda por código');
    same('AAABBBCCCDDDEEE', $found('sort=uses&dir=desc'), 'se puede ordenar por usos');
});

test('el alta de cada tipo guarda su valor en el formato de la base: 10,5 % son 1050 y 5,00 € son 500', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);

    $r = couponsSave($web, couponsForm(['code' => ' verano ', 'kind' => 'percent', 'percent' => '10,5']));
    same(302, $r->status);
    $row = couponsByCode($app, 'VERANO');
    check($row !== null, 'el código se guarda sin espacios y en mayúsculas');
    same(1050, (int) $row['percent']);
    same(0, (int) $row['amount']);
    same('percent', $row['kind']);
    same(1, (int) $row['active']);
    same(0, (int) $row['uses']);
    contains('Creado', $web->follow()->body);

    $r = couponsSave($web, couponsForm(['code' => 'cinco', 'kind' => 'fixed', 'percent' => '0', 'amount' => '5,00']));
    same(302, $r->status);
    $row = couponsByCode($app, 'CINCO');
    check($row !== null);
    same(500, (int) $row['amount']);
    same(0, (int) $row['percent'], 'un fijo no guarda porcentaje');
    same('fixed', $row['kind']);

    $r = couponsSave($web, couponsForm(['code' => 'ENVIO', 'kind' => 'free_shipping', 'percent' => '12', 'amount' => '3,00']));
    same(302, $r->status);
    $row = couponsByCode($app, 'ENVIO');
    check($row !== null);
    same('free_shipping', $row['kind']);
    same(0, (int) $row['percent'], 'el envío gratis no guarda ni porcentaje ni importe aunque lleguen');
    same(0, (int) $row['amount']);
    same(3, couponsCount($app, 'coupons'));

    // Lo guardado es lo que luego sirve el servicio.
    $svc = couponsService($app);
    same(['kind' => 'percent', 'percent' => 1050, 'code' => 'VERANO', 'label' => 'Cupón VERANO'], $svc->check('verano', couponsCart())['discount']);
    same(['kind' => 'fixed', 'amount' => 500, 'code' => 'CINCO', 'label' => 'Cupón CINCO'], $svc->check('cinco', couponsCart())['discount']);
});

test('los límites se guardan con la hora de la tienda pasada a UTC, y al reabrir salen como se escribieron', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);
    $r = couponsSave($web, couponsForm([
        'code' => 'LIMITES', 'starts_at' => '2026-12-01T10:00', 'ends_at' => '2026-12-31T23:59', 'max_uses' => '100', 'max_uses_per_customer' => '2',
        'min_subtotal' => '50,00', 'first_order_only' => '1', 'exclude_sale' => '1', 'combinable' => '1', 'customer_id' => '5',
        'note' => 'Rebajas <b> & más', 'active' => '0',
    ]));
    same(302, $r->status);
    $row = couponsByCode($app, 'LIMITES');
    check($row !== null);
    $id = (int) $row['id'];
    same('2026-12-01 09:00:00', $row['starts_at'], 'diciembre en Madrid es UTC+1');
    same('2026-12-31 22:59:00', $row['ends_at']);
    same(100, (int) $row['max_uses']);
    same(2, (int) $row['max_uses_per_customer']);
    same(5000, (int) $row['min_subtotal']);
    same(1, (int) $row['first_order_only']);
    same(1, (int) $row['exclude_sale']);
    same(1, (int) $row['combinable']);
    same(5, (int) $row['customer_id']);
    same(0, (int) $row['active']);
    same('Rebajas <b> & más', $row['note']);

    $body = $web->get('/admin/r/coupons/' . $id)->body;
    same('LIMITES', inputValue($body, 'f[code]'));
    same('10,5', inputValue($body, 'f[percent]'), 'el porcentaje se pinta como se escribió');
    same('100', inputValue($body, 'f[max_uses]'));
    same('50,00', inputValue($body, 'f[min_subtotal]'));
    same('2026-12-01T10:00', inputValue($body, 'f[starts_at]'), 'la hora vuelve a la zona de la tienda');
    contains('Rebajas &lt;b&gt; &amp; más', $body, 'la nota sale escapada');
    notContains('Rebajas <b>', $body);
    contains('cpmain', $body);
    contains('cplimits', $body);

    // Quitar el mínimo y las fechas los deja sin límite.
    $r = couponsSave($web, couponsForm(['code' => 'LIMITES', 'min_subtotal' => '', 'starts_at' => '', 'ends_at' => '', 'customer_id' => '']), $id);
    same(302, $r->status);
    $row = couponsRow($app, $id);
    same(0, (int) $row['min_subtotal']);
    same(null, $row['starts_at']);
    same(null, $row['ends_at']);
    same(null, $row['customer_id']);
    same(1, couponsCount($app, 'coupons'), 'se editó, no se creó otro');
});

test('un código repetido, también en minúsculas, no se guarda; editar con su propio código sí', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);
    $id = (int) couponsMake($app, ['code' => 'VERANO']);

    $r = couponsSave($web, couponsForm(['code' => 'VERANO']));
    same(422, $r->status, 'exactamente igual');
    $r = couponsSave($web, couponsForm(['code' => 'verano']));
    same(422, $r->status, 'en minúsculas es el mismo código');
    contains('Otro cupón ya tiene este código', $r->body);
    same(1, couponsCount($app, 'coupons'));

    couponsMake($app, ['code' => 'INVIERNO']);
    $r = couponsSave($web, couponsForm(['code' => 'invierno']), $id);
    same(422, $r->status, 'tampoco se puede pisar el de otro cupón al editar');
    contains('Otro cupón ya tiene este código', $r->body);
    same('VERANO', couponsRow($app, $id)['code']);

    $r = couponsSave($web, couponsForm(['code' => 'verano', 'percent' => '15']), $id);
    same(302, $r->status, 'con el suyo no choca');
    same(1500, (int) couponsRow($app, $id)['percent']);
});

test('un código con espacios o símbolos raros se rechaza; con eñe, guiones y guion bajo vale', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);
    foreach (['dos palabras', 'a&b', 'rebajas!', '<b>', str_repeat('X', 41)] as $bad) {
        $r = couponsSave($web, couponsForm(['code' => $bad]));
        same(422, $r->status, 'rechaza «' . $bad . '»');
    }
    same(0, couponsCount($app, 'coupons'));

    $r = couponsSave($web, couponsForm(['code' => 'otoño-2026_x']));
    same(302, $r->status);
    check(couponsByCode($app, 'OTOÑO-2026_X') !== null, 'la eñe se pasa a mayúsculas');
});

test('el porcentaje va de 0,01 a 100 y el importe de un fijo tiene que ser mayor que cero', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);
    $msgPercent = 'Escribe un porcentaje entre 0,01 y 100';
    foreach (['0', '0,001', '100,01', '150', '-5', ''] as $bad) {
        $r = couponsSave($web, couponsForm(['code' => 'MAL', 'percent' => $bad]));
        same(422, $r->status, 'porcentaje «' . $bad . '»');
        contains($msgPercent, $r->body);
    }
    $r = couponsSave($web, couponsForm(['code' => 'MAL', 'percent' => 'abc']));
    same(422, $r->status, 'un texto no es un porcentaje');

    $r = couponsSave($web, couponsForm(['code' => 'MINIMO', 'percent' => '0,01']));
    same(302, $r->status, 'el mínimo vale');
    same(1, (int) couponsRow($app, (int) couponsByCode($app, 'MINIMO')['id'])['percent']);
    $r = couponsSave($web, couponsForm(['code' => 'TOPE', 'percent' => '100']));
    same(302, $r->status, 'el máximo vale');
    same(10000, (int) couponsByCode($app, 'TOPE')['percent']);

    $msgAmount = 'Escribe un importe mayor que cero';
    foreach (['0', '0,00', '-3', ''] as $bad) {
        $r = couponsSave($web, couponsForm(['code' => 'MAL', 'kind' => 'fixed', 'percent' => '', 'amount' => $bad]));
        same(422, $r->status, 'importe «' . $bad . '»');
        contains($msgAmount, $r->body);
    }
    $r = couponsSave($web, couponsForm(['code' => 'FIJO', 'kind' => 'fixed', 'percent' => '', 'amount' => '12,50']));
    same(302, $r->status);
    same(1250, (int) couponsByCode($app, 'FIJO')['amount']);
    same(0, couponsCount($app, 'coupons', 'code = ?', ['MAL']), 'ninguno de los malos se guardó');
});

test('un fin anterior o igual al inicio da error; uno posterior se guarda', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);
    $r = couponsSave($web, couponsForm(['code' => 'REVES', 'starts_at' => '2026-12-10T10:00', 'ends_at' => '2026-12-01T10:00']));
    same(422, $r->status, 'fechas al revés');
    contains('El fin tiene que ser posterior al inicio', $r->body);
    $r = couponsSave($web, couponsForm(['code' => 'REVES', 'starts_at' => '2026-12-10T10:00', 'ends_at' => '2026-12-10T10:00']));
    same(422, $r->status, 'el mismo instante tampoco');
    same(0, couponsCount($app, 'coupons'));

    $r = couponsSave($web, couponsForm(['code' => 'BIEN', 'starts_at' => '2026-12-01T10:00', 'ends_at' => '2026-12-02T10:00']));
    same(302, $r->status);
    $r = couponsSave($web, couponsForm(['code' => 'SOLOFIN', 'ends_at' => '2026-12-02T10:00']));
    same(302, $r->status, 'solo con fin también');
});

test('«generar» rellena el alta con un código al azar libre y sin él el campo sale vacío', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);
    $plain = $web->get('/admin/r/coupons/new');
    same(200, $plain->status);
    same('', inputValue($plain->body, 'f[code]'), 'sin pedirlo, vacío');
    contains('/admin/r/coupons/new?generate=1', $plain->body, 'el botón-enlace de generar');

    $codes = [];
    for ($i = 0; $i < 5; ++$i) {
        $r = $web->get('/admin/r/coupons/new?generate=1');
        same(200, $r->status);
        $code = (string) inputValue($r->body, 'f[code]');
        same(1, preg_match('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $code), 'formato de «' . $code . '»');
        $codes[$code] = true;
    }
    check(count($codes) > 1, 'no sale siempre el mismo');

    // El código generado se puede guardar tal cual.
    $web->get('/admin/r/coupons/new?generate=1');
    $generated = (string) inputValue($web->last->body, 'f[code]');
    $r = $web->post('/admin/r/coupons/save', ['_token' => aifecToken($web), 'f' => couponsForm(['code' => $generated])]);
    same(302, $r->status);
    check(couponsByCode($app, $generated) !== null);

    // En la edición no hay botón de generar.
    $id = (int) couponsByCode($app, $generated)['id'];
    notContains('generate=1', $web->get('/admin/r/coupons/' . $id)->body);
});

test('la columna derecha de la edición enseña los últimos diez usos, con su dato escapado', function (): void {
    $app = couponsTestApp();
    $id = couponsMake($app, ['max_uses' => 20, 'uses' => 12]);
    $otro = couponsMake($app, ['code' => 'OTRO']);
    for ($n = 1; $n <= 12; ++$n) {
        couponsUse($app, $id, 100 + $n, null, sprintf('uso%02d@tienda.test', $n), 1000 + $n, sprintf('2026-11-%02d 10:00:00', $n));
    }
    couponsUse($app, $id, 500, 3, 'o&co@tienda.test', 2599, '2026-11-12 11:00:00');
    couponsUse($app, $otro, 600, null, 'ajeno@tienda.test', 1, '2026-11-12 11:30:00');
    $web = adminClient($app);

    $body = $web->get('/admin/r/coupons/' . $id)->body;
    contains('Usos', $body);
    contains('12 / 20', $body);
    contains('o&amp;co@tienda.test', $body, 'el correo con «&» sale escapado');
    notContains('o&co@tienda.test', $body);
    contains('#500', $body, 'sin módulo de pedidos se enseña el identificador');
    contains('25,99', $body, 'el importe en euros');
    contains('uso12@tienda.test', $body);
    contains('uso04@tienda.test', $body, 'el décimo más reciente');
    notContains('uso03@tienda.test', $body, 'los más antiguos no salen');
    notContains('uso01@tienda.test', $body);
    notContains('ajeno@tienda.test', $body, 'ni los usos de otro cupón');

    $sin = couponsMake($app, ['code' => 'NUEVO']);
    contains('Nadie ha usado todavía este cupón.', $web->get('/admin/r/coupons/' . $sin)->body);
    notContains('Nadie ha usado', $web->get('/admin/r/coupons/new')->body, 'en el alta no hay columna de usos');
});

test('con el módulo de pedidos la columna de usos enseña el número del pedido', function (): void {
    $app = couponsTestApp();
    couponsMakeOrdersTable($app);
    $id = couponsMake($app);
    $order = $app->db()->insert('orders', ['number' => '2026-000042', 'customer_id' => 3, 'email' => 'ana@tienda.test', 'payment_status' => 'paid']);
    couponsUse($app, $id, $order, 3, 'ana@tienda.test', 1500);
    $body = adminClient($app)->get('/admin/r/coupons/' . $id)->body;
    contains('2026-000042', $body);
});

test('un cupón sin usos se borra; uno ya usado no, y el aviso lo dice', function (): void {
    $app = couponsTestApp();
    $libre = couponsMake($app, ['code' => 'LIBRE']);
    $usado = couponsMake($app, ['code' => 'USADO', 'uses' => 1]);
    couponsUse($app, $usado, 1, null, 'a@tienda.test');
    $web = adminClient($app);

    $web->get('/admin/r/coupons');
    $r = $web->post('/admin/r/coupons/' . $libre . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(0, couponsCount($app, 'coupons', 'id = ?', [$libre]), 'el que no se había usado se borra');

    $web->get('/admin/r/coupons');
    $r = $web->post('/admin/r/coupons/' . $usado . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(1, couponsCount($app, 'coupons', 'id = ?', [$usado]), 'el usado se queda');
    same(1, couponsCount($app, 'coupon_uses'), 'con su historial');
    contains('ya se ha usado y no se puede borrar', $web->follow()->body);
});

test('sin catálogo no hay campos de productos ni de categorías; con él salen y guardan listas de números', function (): void {
    $app = couponsTestApp();
    $web = adminClient($app);
    $body = $web->get('/admin/r/coupons/new')->body;
    notContains('name="f[products]"', $body);
    notContains('f[categories]', $body);
    $r = couponsSave($web, couponsForm(['code' => 'SINCAT']));
    same(302, $r->status);
    same(null, couponsByCode($app, 'SINCAT')['products'], 'sin el campo la columna queda como estaba');

    // Con el servicio de catálogo (aquí un objeto de pega) y una tabla de categorías mínima.
    $app->bind('catalog', static function (): \stdClass {
        return new \stdClass();
    });
    (new Schema($app->db()))->create('category_i18n', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('category_id');
        $t->string('lang', 8);
        $t->string('name', 191);
    });
    $app->db()->insert('category_i18n', ['category_id' => 10, 'lang' => 'es', 'name' => 'Sillas']);
    $app->db()->insert('category_i18n', ['category_id' => 20, 'lang' => 'es', 'name' => 'Mesas & más']);
    $app->db()->insert('category_i18n', ['category_id' => 30, 'lang' => 'en', 'name' => 'Only English']);

    $body = $web->get('/admin/r/coupons/new')->body;
    contains('name="f[products]"', $body);
    contains('name="f[categories][]"', $body);
    contains('value="10"', $body);
    contains('Mesas &amp; más', $body, 'el nombre de la categoría sale escapado');
    notContains('Only English', $body, 'solo las del idioma principal');

    $r = couponsSave($web, couponsForm(['code' => 'CONCAT', 'products' => '1, 2, x, 2, -4, 0', 'categories' => ['10']]));
    same(302, $r->status);
    $row = couponsByCode($app, 'CONCAT');
    check($row !== null);
    same([1, 2], json_decode((string) $row['products'], true), 'solo números positivos y sin repetir');
    same([10], json_decode((string) $row['categories'], true));

    // Lo guardado limita de verdad lo que vale en el carrito.
    $r = couponsService($app)->check('CONCAT', couponsCart());
    same(['p1', 'p2'], $r['discount']['keys'], 'producto 1 y 2, y categoría 10: p1 entra por ambas y p2 por el producto 2');

    // Vaciar las listas quita el límite.
    $r = couponsSave($web, couponsForm(['code' => 'CONCAT', 'products' => '']), (int) $row['id']);
    same(302, $r->status);
    same([], json_decode((string) couponsRow($app, (int) $row['id'])['products'], true));
    same([], json_decode((string) couponsRow($app, (int) $row['id'])['categories'], true), 'sin marcar ninguna categoría tampoco hay límite');
});
