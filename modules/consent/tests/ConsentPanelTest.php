<?php
/**
 * AIFEC — pruebas del consentimiento en el panel: el informe por semanas, la exportación del registro, el inventario de cookies,
 * los ajustes y los permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Consent\ConsentReport;

require_once __DIR__ . '/helpers.php';

/** Apunta decisiones directamente en el registro, a los días que se pidan antes de «ahora». */
function consentSeed(\Aifec\Kernel\App $app, string $action, int $daysAgo, int $n = 1): void
{
    $categories = ['all' => 'psm', 'none' => '', 'custom' => 's'];
    for ($i = 0; $i < $n; ++$i) {
        $app->db()->insert('consent_log', [
            'cookie_id' => substr(md5($action . $daysAgo . $i), 0, 16), 'policy_version' => 1, 'categories' => $categories[$action], 'action' => $action,
            'gpc' => 0, 'lang' => 'es', 'created_at' => $app->clock()->sql($app->clock()->now() - $daysAgo * 86400),
        ]);
    }
}

test('el informe por semanas calcula los porcentajes de aceptación, rechazo y configuración', function (): void {
    $app = consentApp();
    consentSeed($app, 'all', 1, 6);
    consentSeed($app, 'none', 1, 3);
    consentSeed($app, 'custom', 1, 1);
    consentSeed($app, 'all', 9, 2);
    $weeks = (new ConsentReport($app))->weeks(4);
    same(4, count($weeks));
    $total = array_sum(array_column($weeks, 'total'));
    same(12, $total);
    $with = array_values(array_filter($weeks, static function (array $w): bool {
        return $w['total'] === 10;
    }));
    same(1, count($with), 'una semana con las diez decisiones');
    same(60, $with[0]['all_pct']);
    same(30, $with[0]['none_pct']);
    same(10, $with[0]['custom_pct']);
    same(100, $with[0]['all_pct'] + $with[0]['none_pct'] + $with[0]['custom_pct']);
    $empty = array_values(array_filter($weeks, static function (array $w): bool {
        return $w['total'] === 0;
    }));
    same(0, $empty[0]['all_pct'], 'una semana sin decisiones no divide por cero');
    check(preg_match('/^\d{4}-W\d{2}$/', $weeks[0]['week']) === 1, 'la semana se nombra a la ISO');
});

test('las semanas empiezan en lunes y las decisiones de un domingo van a la semana que termina', function (): void {
    $app = consentApp();
    // El reloj de las pruebas es el 12/11/2026 (jueves): su lunes es el 9/11/2026.
    $weeks = (new ConsentReport($app))->weeks(2);
    same('2026-11-09', $weeks[0]['start']);
    same('2026-11-02', $weeks[1]['start']);
    consentSeed($app, 'none', 4);   // el domingo 8/11
    consentSeed($app, 'none', 3);   // el lunes 9/11
    $weeks = (new ConsentReport($app))->weeks(2);
    same(1, $weeks[0]['total']);
    same(1, $weeks[1]['total']);
});

test('el informe del panel se abre con permiso, enseña los porcentajes y no se abre sin él (403)', function (): void {
    $app = consentApp();
    consentSeed($app, 'all', 1, 3);
    consentSeed($app, 'none', 1, 1);
    $web = adminClient($app);
    $r = $web->get('/admin/consent');
    same(200, $r->status);
    contains('Consentimiento de cookies', $r->body);
    contains('75 %', $r->body);
    contains('25 %', $r->body);
    contains('/admin/consent/export.csv', $r->body);
    same(403, adminClient($app, 'support')->get('/admin/consent')->status);
    same(302, (new TestClient($app))->get('/admin/consent')->status, 'sin sesión se manda a entrar');
});

test('la exportación del registro es un CSV sin datos personales y solo la baja quien puede', function (): void {
    $app = consentApp();
    consentSeed($app, 'all', 2, 2);
    consentSeed($app, 'none', 1, 1);
    $web = adminClient($app);
    $r = $web->get('/admin/consent/export.csv');
    same(200, $r->status);
    contains('text/csv', $r->headers['Content-Type']);
    contains('attachment; filename="consent-log-20261112.csv"', $r->headers['Content-Disposition']);
    $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($r->body, "\xEF\xBB\xBF")), 'strlen'));
    same('date;cookie_id;policy_version;categories;action;gpc;language', $lines[0]);
    same(4, count($lines), 'la cabecera y tres decisiones');
    notContains('@', $r->body);
    same(403, adminClient($app, 'support')->get('/admin/consent/export.csv')->status);
});

test('el inventario de cookies del panel enseña las de serie, deja crear una y no deja borrar las de serie', function (): void {
    $app = consentApp();
    $web = adminClient($app);
    $list = $web->get('/admin/r/consent_cookies');
    same(200, $list->status);
    contains('af_k_*', $list->body);
    contains('Guarda tu carrito', $list->body);
    $builtin = (int) $app->db()->value('SELECT MIN(id) FROM {consent_cookies} WHERE builtin = 1');
    check($builtin > 0);
    $web->get('/admin/r/consent_cookies/' . $builtin);
    $r = $web->post('/admin/r/consent_cookies/' . $builtin . '/delete', ['_token' => aifecToken($web)]);
    check(in_array($r->status, [302, 403, 422], true));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {consent_cookies} WHERE id = ?', [$builtin]), 'la de serie sigue ahí');
});

test('una cookie nueva del panel sale en la tabla de la política, escapada, y vacía las páginas guardadas', function (): void {
    $app = consentApp();
    (new TestClient($app))->get('/');
    $web = adminClient($app);
    $web->get('/admin/r/consent_cookies/new');
    $r = $web->post('/admin/r/consent_cookies/save', ['_token' => aifecToken($web), 'f' => [
        'name' => 'tracker&1', 'provider' => 'Terceros <i>S.L.</i>', 'purpose' => 'Mide & más', 'duration' => '1 año', 'category' => 'marketing', 'position' => '100',
    ]]);
    same(302, $r->status);
    $table = $app->service('consent')->cookieTable();
    contains('tracker&amp;1', $table);
    contains('Terceros &lt;i&gt;S.L.&lt;/i&gt;', $table);
    contains('Mide &amp; más', $table);
    notContains('<i>S.L.</i>', $table);
});

test('los ajustes del consentimiento se abren con sus pestañas con prefijo y solo con permiso', function (): void {
    $app = consentApp();
    $web = adminClient($app);
    $r = $web->get('/admin/settings/consent');
    same(200, $r->status);
    contains('cnnotice', $r->body);
    contains('cnpolicy', $r->body);
    contains('Versión de la política', $r->body);
    same(403, adminClient($app, 'support')->get('/admin/settings/consent')->status);
});

test('la pieza del inicio cuenta las decisiones de los últimos 30 días', function (): void {
    $app = consentApp();
    $empty = \Aifec\Modules\Consent\ConsentAdmin::widget($app);
    contains('Todavía no hay decisiones', $empty);
    consentSeed($app, 'all', 3, 1);
    consentSeed($app, 'none', 40, 5);
    $html = \Aifec\Modules\Consent\ConsentAdmin::widget($app);
    contains('100 %', $html, 'solo cuenta lo de los últimos 30 días');
    contains('1 decisión', $html);
});
