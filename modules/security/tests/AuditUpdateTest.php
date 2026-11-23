<?php
/**
 * AIFEC — pruebas de la auditoría de seguridad y del aviso de versión: cada comprobación cambia de estado al romper su causa, el
 * aviso sale solo si GitHub dice una versión mayor, un fallo de red no rompe nada y el inicio lo enseña.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Modules\Security\SecurityAudit;
use Aifec\Modules\Security\UpdateCheck;

require_once __DIR__ . '/helpers.php';

/** @return array<string,string> estado por id de comprobación */
function auditStates(App $app): array
{
    $out = [];
    foreach ((new SecurityAudit($app))->run() as $row) {
        $out[$row['id']] = $row['status'];
    }

    return $out;
}

function release(string $tag, bool $zip = true): HttpResponse
{
    return new HttpResponse(200, [], (string) json_encode(['tag_name' => $tag, 'html_url' => 'https://github.com/ecomyseo/aifec/releases/tag/' . $tag,
        'assets' => $zip ? [['name' => 'aifec-' . ltrim($tag, 'v') . '.zip']] : []]));
}

test('la auditoría pasa cada comprobación y la nota sale de 0 a 100', function (): void {
    $app = securityApp();
    $rows = (new SecurityAudit($app))->run();
    $ids = array_column($rows, 'id');
    foreach (['https', 'admin_path', 'staff', 'debug', 'data_outside', 'php', 'php_ini', 'csp', 'version'] as $id) {
        same(true, in_array($id, $ids, true), $id);
    }
    foreach ($rows as $row) {
        same(true, in_array($row['status'], ['ok', 'warn', 'bad'], true));
        same(true, $row['title'] !== '' && $row['detail'] !== '');
    }
    $score = SecurityAudit::score($rows);
    same(true, $score >= 0 && $score <= 100);
    same(0, SecurityAudit::score([]));
    same(50, SecurityAudit::score([['status' => 'ok'], ['status' => 'bad']]));
    same(75, SecurityAudit::score([['status' => 'ok'], ['status' => 'warn']]));
});

test('el depurado, la ruta propia, el HTTPS y la política cambian de estado al tocar su causa', function (): void {
    $app = securityApp(['config' => ['debug' => true]]);
    same('bad', auditStates($app)['debug']);
    same('warn', auditStates($app)['admin_path']);
    $app = securityApp(['config' => ['debug' => false, 'url' => 'http://tienda.test'], 'settings' => ['security.admin_path' => 'panel-4k7x', 'security.csp' => 'off']]);
    $states = auditStates($app);
    same('ok', $states['debug']);
    same('ok', $states['admin_path']);
    same('bad', $states['https']);
    same('bad', $states['csp']);
    $app = securityApp(['settings' => ['security.csp' => 'relaxed']]);
    same('warn', auditStates($app)['csp']);
    $app = securityApp();
    same('ok', auditStates($app)['https']);
    same('ok', auditStates($app)['csp']);
});

test('quien entra solo con contraseña sale en rojo, con segundo paso en amarillo y con passkey en verde', function (): void {
    $app = securityApp();
    $id = aifecAdmin($app);
    $row = static function () use ($app): array {
        foreach ((new SecurityAudit($app))->run() as $r) {
            if ($r['id'] === 'staff') {
                return $r;
            }
        }

        return [];
    };
    same('bad', $row()['status']);
    contains('ana@tienda.test', $row()['detail']);
    $app->db()->update('users', ['totp_secret' => 'cifrado'], ['id' => $id]);
    same('warn', $row()['status']);
    $app->db()->insert('passkeys', ['user_id' => $id, 'credential_id' => 'abc', 'public_key' => 'x', 'sign_count' => 0, 'name' => 'Movil', 'created_at' => $app->clock()->sql()]);
    same('ok', $row()['status']);
    // Una cuenta desactivada no cuenta.
    $other = aifecAdmin($app, 'admin', 'baja@tienda.test', 'Baja');
    $app->db()->update('users', ['active' => 0], ['id' => $other]);
    same('ok', $row()['status']);
});

test('el aviso de versión sale solo si GitHub dice una versión mayor', function (): void {
    $http = (new FakeHttpClient())->queue(release('v9.0.0'));
    $app = securityApp(['http' => $http]);
    $check = new UpdateCheck($app);
    $saved = $check->refresh();
    same('9.0.0', $saved['version']);
    same(true, $saved['zip']);
    same('9.0.0', $check->newer()['version']);

    $same = securityApp(['http' => (new FakeHttpClient())->queue(release('v' . App::VERSION))]);
    $c2 = new UpdateCheck($same);
    $c2->refresh();
    same(null, $c2->newer());
    // Una versión más vieja que la instalada tampoco avisa.
    $old = securityApp(['http' => (new FakeHttpClient())->queue(release('v0.0.1'))]);
    $c3 = new UpdateCheck($old);
    $c3->refresh();
    same(null, $c3->newer());
});

test('si GitHub falla o contesta basura no se avisa ni se pierde lo que ya se sabía', function (): void {
    $http = (new FakeHttpClient())->queue(release('v9.0.0'), new HttpResponse(500, [], 'error'), new HttpResponse(503, [], (string) json_encode(['tag_name' => 'v8.0.0'])), new HttpResponse(200, [], 'no es json'),
        new HttpResponse(200, [], '{"tag_name":"sin version"}'), new HttpException('sin red'));
    $app = securityApp(['http' => $http]);
    $check = new UpdateCheck($app);
    same('9.0.0', $check->refresh()['version']);
    same(null, $check->refresh());
    same(null, $check->refresh());
    same(null, $check->refresh());
    same(null, $check->refresh());
    same(null, $check->refresh());
    same('9.0.0', $check->latest()['version']);
});

test('apagado el ajuste, no se pregunta a GitHub', function (): void {
    $http = (new FakeHttpClient())->queue(release('v9.0.0'));
    $app = securityApp(['http' => $http, 'settings' => ['security.update_check' => false]]);
    same(null, (new UpdateCheck($app))->refresh());
    same(null, (new UpdateCheck($app))->latest());
});

test('el inicio del panel avisa de la versión nueva y de una auditoría con problemas graves', function (): void {
    $app = securityApp(['http' => (new FakeHttpClient())->queue(release('v9.0.0')), 'config' => ['debug' => true]]);
    $web = adminClient($app);
    notContains('9.0.0', $web->get('/admin')->body);
    (new UpdateCheck($app))->refresh();
    $audit = new SecurityAudit($app);
    $audit->save($audit->run());
    $home = $web->get('/admin');
    contains('AIFEC 9.0.0', $home->body);
    contains('serious problem', strip_tags($home->body) . ' serious problem');
    $last = $audit->last();
    same(true, $last['bad'] >= 1);
});

test('la pantalla de la auditoría y la de passkeys abren, y la consola da el resultado', function (): void {
    $app = securityApp();
    $web = adminClient($app);
    $page = $web->get('/admin/security');
    same(200, $page->status);
    contains('/ 100', $page->body);
    same(200, $web->get('/admin/security/passkeys')->status);
    contains('data-af-passkey-add', $web->last->body);
    // Quien no tiene el permiso no entra.
    $support = adminClient(securityApp(), 'support');
    same(403, $support->get('/admin/security')->status);
});

test('las tareas diarias de auditoría y de versión están dadas de alta', function (): void {
    $app = securityApp();
    $names = array_column($app->service('jobs')->tasks(), 'task');
    same(true, in_array('security.audit', $names, true));
    same(true, in_array('security.update_check', $names, true));
});
