<?php
/**
 * AIFEC — pruebas de la prueba del consentimiento: POST /consentimiento guarda lo justo y nada personal, solo desde la propia web,
 * sin duplicar, con límite de uso y con borrado a los cinco años; el inventario y la tabla de cookies de la política.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Consent\ConsentTasks;

require_once __DIR__ . '/helpers.php';

test('la decisión se apunta con lo justo: identificador al azar, versión, categorías, fecha e idioma', function (): void {
    $app = consentApp();
    $web = consentWeb($app);
    $r = consentPost($web, ['c' => 'sm', 'g' => '1', 'l' => 'es']);
    same(200, $r->status);
    same(['ok' => true, 'logged' => true], json_decode($r->body, true));
    $rows = consentRows($app);
    same(1, count($rows));
    same('9f2c4e1ab7d30586', $rows[0]['cookie_id']);
    same(1, (int) $rows[0]['policy_version']);
    same('sm', $rows[0]['categories']);
    same('custom', $rows[0]['action']);
    same(1, (int) $rows[0]['gpc']);
    same('es', $rows[0]['lang']);
    same($app->clock()->sql(), $rows[0]['created_at']);
});

test('el registro no tiene ningún campo personal: ni dirección IP, ni navegador, ni correo', function (): void {
    $app = consentApp();
    $columns = $app->db()->columns('consent_log');
    sort($columns);
    same(['action', 'categories', 'cookie_id', 'created_at', 'gpc', 'id', 'lang', 'policy_version'], $columns);
    $web = consentWeb($app);
    consentPost($web, [], ['user-agent' => 'Navegador/9.9 secreto', 'x-forwarded-for' => '203.0.113.9']);
    $dump = json_encode(consentRows($app));
    notContains('203.0.113', (string) $dump);
    notContains('secreto', (string) $dump);
    notContains('@', (string) $dump);
});

test('desde otro origen la prueba se rechaza con 403 y no se guarda nada', function (): void {
    $app = consentApp();
    $web = consentWeb($app);
    same(403, consentPost($web, [], ['sec-fetch-site' => 'cross-site'])->status);
    same(403, consentPost($web, [], ['sec-fetch-site' => 'same-site'])->status);
    same(403, consentPost($web, [], ['sec-fetch-site' => '', 'origin' => 'https://otra.test'])->status);
    same(0, count(consentRows($app)));
});

test('rechazar todo se apunta con las categorías vacías y la acción «none»; aceptar todo, «all»', function (): void {
    $app = consentApp();
    $web = consentWeb($app);
    consentPost($web, ['id' => 'aaaaaaaaaaaaaaaa', 'c' => '']);
    consentPost($web, ['id' => 'bbbbbbbbbbbbbbbb', 'c' => 'psm']);
    consentPost($web, ['id' => 'cccccccccccccccc', 'c' => 'ps']);
    $rows = consentRows($app);
    same(['', 'psm', 'ps'], array_column($rows, 'categories'));
    same(['none', 'all', 'custom'], array_column($rows, 'action'));
});

test('las letras raras se limpian y se ordenan; un identificador mal formado da 400', function (): void {
    $app = consentApp();
    $web = consentWeb($app);
    consentPost($web, ['c' => 'xmzsm<b>']);
    same('sm', consentRows($app)[0]['categories']);
    foreach (['', 'corto', 'ZZZZZZZZZZZZZZZZ', '9f2c4e1ab7d30586<script>', str_repeat('a', 40)] as $bad) {
        same(400, consentPost($web, ['id' => $bad])->status, 'identificador «' . $bad . '»');
    }
    same(1, count(consentRows($app)));
});

test('una decisión de otra versión de la política no se apunta (409)', function (): void {
    $app = consentApp(['settings' => ['consent.version' => 4]]);
    $web = consentWeb($app);
    $r = consentPost($web, ['v' => '3']);
    same(409, $r->status);
    same(0, count(consentRows($app)));
    same(200, consentPost($web, ['v' => '4'])->status);
    same(4, (int) consentRows($app)[0]['policy_version']);
});

test('la misma decisión repetida no se apunta dos veces; un cambio sí', function (): void {
    $app = consentApp();
    $web = consentWeb($app);
    consentPost($web, ['c' => 'sm']);
    $again = consentPost($web, ['c' => 'ms']);
    same(['ok' => true, 'logged' => false], json_decode($again->body, true));
    same(1, count(consentRows($app)));
    consentPost($web, ['c' => '']);
    consentPost($web, ['c' => 'sm']);
    same(3, count(consentRows($app)), 'rechazar y volver a aceptar son dos decisiones nuevas');
});

test('el idioma solo se guarda si es uno de los de la tienda', function (): void {
    $app = consentApp();
    $web = consentWeb($app);
    consentPost($web, ['id' => 'aaaaaaaaaaaaaaaa', 'l' => 'ES']);
    consentPost($web, ['id' => 'bbbbbbbbbbbbbbbb', 'l' => 'xx<script>']);
    consentPost($web, ['id' => 'cccccccccccccccc', 'l' => '']);
    same(['es', '', ''], array_column(consentRows($app), 'lang'));
});

test('con el registro apagado se contesta bien pero no se guarda nada', function (): void {
    $app = consentApp(['settings' => ['consent.log' => false]]);
    $r = consentPost(consentWeb($app));
    same(200, $r->status);
    same(['ok' => true, 'logged' => false], json_decode($r->body, true));
    same(0, count(consentRows($app)));
});

test('con el aviso apagado la ruta no existe (404)', function (): void {
    $app = consentApp(['settings' => ['consent.enabled' => false]]);
    same(404, consentPost(consentWeb($app))->status);
});

test('hay un límite de decisiones por hora y dirección: pasado el tope, 429', function (): void {
    $app = consentApp();
    $web = consentWeb($app);
    for ($i = 0; $i < 60; ++$i) {
        same(200, consentPost($web, ['id' => substr(md5((string) $i), 0, 16)])->status);
    }
    same(429, consentPost($web, ['id' => substr(md5('x'), 0, 16)])->status);
});

test('la tarea de limpieza borra las decisiones de más de cinco años y deja las demás', function (): void {
    $app = consentApp();
    $now = $app->clock()->now();
    $db = $app->db();
    $db->insert('consent_log', ['cookie_id' => 'aaaaaaaaaaaaaaaa', 'policy_version' => 1, 'categories' => 'm', 'action' => 'custom', 'gpc' => 0, 'lang' => 'es', 'created_at' => $app->clock()->sql($now - 1826 * 86400)]);
    $db->insert('consent_log', ['cookie_id' => 'bbbbbbbbbbbbbbbb', 'policy_version' => 1, 'categories' => 'm', 'action' => 'custom', 'gpc' => 0, 'lang' => 'es', 'created_at' => $app->clock()->sql($now - 1700 * 86400)]);
    $db->insert('consent_log', ['cookie_id' => 'cccccccccccccccc', 'policy_version' => 1, 'categories' => '', 'action' => 'none', 'gpc' => 0, 'lang' => 'es', 'created_at' => $app->clock()->sql($now)]);
    same('1 rows', ConsentTasks::purge($app));
    same(['bbbbbbbbbbbbbbbb', 'cccccccccccccccc'], array_column(consentRows($app), 'cookie_id'));
    same(null, ConsentTasks::purge($app), 'sin nada que borrar no dice nada');
    check(isset($app->modules()->definition('consent', 'jobs')['cron']['consent.purge']), 'la tarea está declarada');
});

test('el inventario trae las cookies de AIFEC con el sufijo de este sitio y se amplía con el filtro de otros módulos', function (): void {
    $app = consentApp();
    $suffix = $app->site()->cookieSuffix();
    $service = $app->service('consent');
    $names = array_column($service->cookies(), 'name');
    foreach (['af_consent_', 'af_k_', 'af_ck_', 'af_c_', 'af_u_', 'af_nc_', 'af_as_'] as $prefix) {
        check(in_array($prefix . $suffix, $names, true), 'declarada ' . $prefix . $suffix);
    }
    foreach ($names as $name) {
        notContains('*', $name);
    }
    $app->hooks()->filter('consent.cookies', static function (array $list): array {
        $list[] = ['name' => '_ga', 'provider' => 'Google', 'purpose' => 'Mide visitas', 'duration' => '2 años', 'category' => 'estadísticas'];
        $list[] = ['name' => '_fbp', 'provider' => 'Meta', 'purpose' => 'Anuncios', 'duration' => '3 meses', 'category' => 'marketing'];

        return $list;
    });
    same(['_ga'], array_column($service->cookiesOf('statistics'), 'name'));
    same(['_fbp'], array_column($service->cookiesOf('marketing'), 'name'));
    check(count($service->cookiesOf('necessary')) >= 7, 'las de serie son necesarias');
});

test('el bloque «Tabla de cookies» y el botón «Cambiar mis preferencias» existen y escapan lo que llevan', function (): void {
    $app = consentApp();
    check(isset($app->modules()->definition('consent', 'blocks')['consent-cookies']), 'bloque de la tabla');
    check(isset($app->modules()->definition('consent', 'blocks')['consent-preferences']), 'bloque del botón');
    $app->db()->insert('consent_cookies', ['name' => 'mi&cookie', 'provider' => 'A <b>B</b>', 'purpose' => 'Para "algo"', 'duration' => '1 año', 'category' => 'marketing', 'builtin' => 0, 'position' => 100]);
    $table = $app->service('consent')->cookieTable();
    contains('<table class="af-table">', $table);
    contains('mi&amp;cookie', $table);
    contains('A &lt;b&gt;B&lt;/b&gt;', $table);
    contains('Para &quot;algo&quot;', $table);
    contains('Marketing', $table);
    contains('af_k_' . $app->site()->cookieSuffix(), $table);
    $button = $app->service('consent')->preferencesButton();
    contains('data-af-consent-open', $button);
    contains('Cambiar mis preferencias de cookies', $button);
    contains('type="button"', $button);
});
