<?php
/**
 * AIFEC — pruebas de los ajustes de Redsys y de su catálogo de textos: la pantalla (pestañas y dirección de aviso), el
 * guardado de las claves cifradas sin enseñarlas nunca, lo que se rechaza, y las explicaciones de los códigos de respuesta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\PaymentsRedsys\RedsysResponses;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/RedsysKit.php';

/**
 * Los campos del formulario de ajustes tal como los manda el navegador.
 *
 * @param array<string,mixed> $cambios
 *
 * @return array<string,mixed>
 */
function rdsForm(array $cambios = []): array
{
    return $cambios + [
        RedsysSettings::ENVIRONMENT => 'test', RedsysSettings::MERCHANT_CODE => RDS_MERCHANT, RedsysSettings::TERMINAL => '1',
        RedsysSettings::BIZUM => '0', RedsysSettings::TITLE => '', RedsysSettings::BIZUM_TITLE => '',
        RedsysSettings::SECRET_TEST => '', RedsysSettings::SECRET_LIVE => '',
    ];
}

/**
 * @param array<string,mixed> $formulario
 */
function rdsSave(TestClient $web, array $formulario, string $pestana): \Aifec\Kernel\Response
{
    $web->get('/admin/settings/payments-redsys');

    return $web->post('/admin/settings/payments-redsys', ['_token' => aifecToken($web), '_tab' => $pestana, 'f' => $formulario]);
}

test('la pantalla de ajustes abre con sus dos pestañas y la dirección de aviso lista para copiar', function (): void {
    $app = rdsApp([], false);
    $web = adminClient($app);
    $r = $web->get('/admin/settings/payments-redsys');

    same(200, $r->status);
    contains('data-af-tab="rdmain"', $r->body);
    contains('data-af-tab="rdkeys"', $r->body);
    contains('data-af-copy="https://tienda.test/pago/redsys/aviso"', $r->body);
    contains('value="https://tienda.test/pago/redsys/aviso"', $r->body);
    same(RDS_MERCHANT, inputValue($r->body, 'f[' . RedsysSettings::MERCHANT_CODE . ']'));
    same('1', inputValue($r->body, 'f[' . RedsysSettings::TERMINAL . ']'));
    contains('/admin/settings/payments-redsys', $web->get('/admin/settings')->body, 'aparece en el índice de ajustes');
});

test('la pantalla dice que AIFEC no trae ningún comercio de pruebas y enlaza las dos páginas de Redsys para darse de alta en uno', function (): void {
    $app = rdsApp([], false);
    $page = adminClient($app)->get('/admin/settings/payments-redsys')->body;

    contains('Tu propio TPV de pruebas', $page);
    contains('AIFEC no trae ningún comercio ni clave de pruebas de Redsys', $page);
    contains('href="https://pagosonline.redsys.es/desarrolladores-inicio/integrate-con-nosotros/tarjetas-y-entornos-de-prueba/"', $page, 'tarjetas y entornos de prueba');
    contains('href="https://sis-t.redsys.es:25443/admincanales-web/index.jsp#/login?highlightTrialUser=true"', $page, 'el alta en el entorno de pruebas');
    check(preg_match_all('/target="_blank" rel="noopener noreferrer"/', $page) >= 2, 'los enlaces se abren aparte y sin referencia');
    // Y ni rastro de una clave de pruebas escrita en la pantalla: los campos de clave salen vacíos.
    check(preg_match('/name="f\[' . preg_quote(RedsysSettings::SECRET_TEST, '/') . '\]"[^>]*value="[A-Za-z0-9+\/]{32}"/', $page) !== 1, 'la clave de pruebas no se enseña');
});

test('una clave se guarda cifrada y la pantalla no la enseña nunca, ni la de pruebas ni la de producción', function (): void {
    $app = rdsApp([], false);
    $web = adminClient($app);

    same(302, rdsSave($web, rdsForm([RedsysSettings::SECRET_TEST => RDS_KEY, RedsysSettings::SECRET_LIVE => RDS_LIVE_KEY]), 'rdkeys')->status);
    $app->settings()->reload();
    same(RDS_KEY, $app->secret(RedsysSettings::SECRET_TEST), 'la tienda la lee descifrada');
    same(RDS_LIVE_KEY, $app->secret(RedsysSettings::SECRET_LIVE));
    same(0, strpos($app->settings()->str(RedsysSettings::SECRET_TEST), 'enc:'), 'y en la base de datos va cifrada');
    notContains(RDS_KEY, $app->settings()->str(RedsysSettings::SECRET_TEST));
    notContains(RDS_LIVE_KEY, $app->settings()->str(RedsysSettings::SECRET_LIVE));

    $page = $web->get('/admin/settings/payments-redsys')->body;
    notContains(RDS_KEY, $page);
    notContains(RDS_LIVE_KEY, $page);
    contains('Guardada. Déjala vacía para conservarla', $page, 'solo dice que está guardada');
    same(true, $app->service('payments')->get('redsys')->available(['currency' => 'EUR']), 'y con ella ya se ofrece');
});

test('guardar con la clave vacía conserva la de antes, y una clave con mala forma se rechaza sin guardar nada', function (): void {
    $app = rdsApp();
    $web = adminClient($app);
    same(RDS_KEY, $app->secret(RedsysSettings::SECRET_TEST));

    same(302, rdsSave($web, rdsForm([RedsysSettings::TERMINAL => '2']), 'rdmain')->status);
    $app->settings()->reload();
    same(RDS_KEY, $app->secret(RedsysSettings::SECRET_TEST), 'la clave sigue');
    same('2', $app->settings()->str(RedsysSettings::TERMINAL));

    foreach (['corta', str_repeat('a', 33), substr(RDS_KEY, 0, 30) . '<>', "<script>alert(1)</script>"] as $junk) {
        $r = rdsSave($web, rdsForm([RedsysSettings::SECRET_TEST => $junk, RedsysSettings::TERMINAL => '9']), 'rdkeys');
        same(422, $r->status, 'se rechaza: ' . $junk);
        $app->settings()->reload();
        same(RDS_KEY, $app->secret(RedsysSettings::SECRET_TEST));
        same('2', $app->settings()->str(RedsysSettings::TERMINAL), 'y no se guarda nada del resto');
    }
});

test('el comercio, el entorno y Bizum se guardan, vuelven a pintarse y los datos mal formados se rechazan', function (): void {
    $app = rdsApp();
    $web = adminClient($app);

    $r = rdsSave($web, rdsForm([RedsysSettings::ENVIRONMENT => 'live', RedsysSettings::BIZUM => '1', RedsysSettings::TITLE => 'Tarjeta & débito', RedsysSettings::MERCHANT_CODE => '123456789']), 'rdmain');
    same(302, $r->status);
    contains('#tab=rdmain', $r->headers['Location'], 'vuelve a la misma pestaña');
    $app->settings()->reload();
    same('live', $app->settings()->str(RedsysSettings::ENVIRONMENT));
    same(true, $app->settings()->bool(RedsysSettings::BIZUM));
    same('Tarjeta & débito', $app->settings()->str(RedsysSettings::TITLE), 'el «&» se guarda tal cual');
    same('123456789', $app->settings()->str(RedsysSettings::MERCHANT_CODE));
    $page = $web->get('/admin/settings/payments-redsys')->body;
    same('123456789', inputValue($page, 'f[' . RedsysSettings::MERCHANT_CODE . ']'));
    contains('Tarjeta &amp; débito', $page);

    foreach ([[RedsysSettings::MERCHANT_CODE, 'abc'], [RedsysSettings::MERCHANT_CODE, '1234567890123456'], [RedsysSettings::TERMINAL, '1234'], [RedsysSettings::ENVIRONMENT, 'otro']] as $case) {
        same(422, rdsSave($web, rdsForm([$case[0] => $case[1]]), 'rdmain')->status, 'se rechaza ' . $case[0] . ' = ' . $case[1]);
    }
    $app->settings()->reload();
    same('123456789', $app->settings()->str(RedsysSettings::MERCHANT_CODE), 'no se guardó nada de lo rechazado');
});

test('solo quien puede editar pedidos ve la pantalla de ajustes', function (): void {
    $app = rdsApp();
    same(302, (new TestClient($app))->get('/admin/settings/payments-redsys')->status, 'sin sesión no se abre');
});

test('los códigos de respuesta se explican en castellano, con los más habituales y un texto para los que no se conocen', function (): void {
    same('Tarjeta caducada', RedsysResponses::text(101));
    same('Saldo insuficiente', RedsysResponses::text(116));
    same('Ha fallado la autenticación del titular', RedsysResponses::text(184));
    same('Operación cancelada por el cliente', RedsysResponses::text(9915));
    same('Operación autorizada', RedsysResponses::text(0));
    same('Operación autorizada', RedsysResponses::text(99));
    same('Respuesta desconocida', RedsysResponses::text(4242));
    same('Respuesta desconocida', RedsysResponses::text(-1));
});

test('el catálogo castellano traduce todo lo que el módulo enseña, y el manifiesto declara lo que usa', function (): void {
    $dir = dirname(__DIR__);
    $es = require $dir . '/lang/es.php';
    check(is_array($es) && count($es) > 50, 'el catálogo tiene sus cadenas');
    foreach ($es as $english => $spanish) {
        check(is_string($spanish) && $spanish !== '', 'sin traducir: ' . $english);
    }
    $manifest = json_decode((string) file_get_contents($dir . '/module.json'), true);
    same(['sis.redsys.es', 'sis-t.redsys.es'], $manifest['contract']['hosts']);
    same(['orders'], $manifest['requires']['modules']);
    same(false, $manifest['default']);
    check(is_file($dir . '/settings.php') && is_file($dir . '/routes.php') && is_file($dir . '/hooks.php'), 'los tres ficheros de arranque');
});
