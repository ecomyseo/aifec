<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Sites\DemoAdministration;
use Aifec\Modules\Sites\DemoGuard;
use Aifec\Modules\Sites\Demos;
use Aifec\Modules\Sites\Owners;

require_once __DIR__ . '/helpers.php';

/** @return array<string,mixed> */
function publicAdministrationWorld(): array
{
    $w = aifecSitesFresh();
    $created = $w['demos']->create(['package' => 'minimo', 'slug' => 'administracion', 'public_admin' => true]);
    check(!empty($created['ok']), 'se crea la tienda de administración: ' . (string) ($created['error'] ?? ''));
    $w['id'] = 'demo-administracion';
    $w['demo'] = aifecSitesDemoApp($w, $w['id']);

    return $w;
}

test('la demo de administración tiene dos accesos y no recibe cuentas ni claves privadas del maestro', function (): void {
    $w = publicAdministrationWorld();
    $demo = $w['demo'];
    same(true, $demo->site()->get('public_admin_demo', false));
    same(false, (bool) $demo->site()->get('master', false));
    same('fake', $demo->settings()->str('ai.economy.provider'));
    same('', $demo->secret('ai.economy.key'));
    $legalPages = (new \Aifec\Modules\Cms\Legal($demo))->status();
    same(5, count(array_filter($legalPages, static function (array $page): bool { return $page['status'] === 'published'; })), 'las cinco páginas legales también existen en la nueva demostración');
    same(2, (int) $demo->db()->value('SELECT COUNT(*) FROM {users}'));
    same(DemoAdministration::ROLE, $demo->db()->value('SELECT role FROM {users} WHERE email = ?', [DemoAdministration::email($demo->site()->url())]));
    same('Administrador de demostración', $demo->db()->value('SELECT name FROM {roles} WHERE code = ?', [DemoAdministration::ROLE]));
    same('demo', $demo->db()->value('SELECT role FROM {users} WHERE email = ?', [DemoGuard::email($demo->site()->url())]));
    same(0, (new Owners($w['master']))->syncSite($w['id'])['owners']);
    same(2, (int) $demo->db()->value('SELECT COUNT(*) FROM {users}'), 'no se copian cuentas del operador');
    $w['master']->settings()->set(Demos::SHOW_PANEL, true);
    $page = aifecSitesFront($w, '/demos');
    contains('Demo de superadministrador', $page->body);
    contains('Demo de usuario de prueba', $page->body);
    contains('admin-demo%40tienda.test', $page->body);
    contains('demo%40tienda.test', $page->body);
});

test('el administrador público configura su tienda y el usuario de prueba conserva permisos limitados', function (): void {
    $w = publicAdministrationWorld();
    $demo = $w['demo'];
    $full = new TestClient($demo);
    same(302, aifecLogin($full, DemoAdministration::email($demo->site()->url()), DemoGuard::DEFAULT_PASSWORD)->status);
    foreach (['/admin', '/admin/settings/core', '/admin/settings/ai', '/admin/settings/assistant'] as $path) {
        same(200, $full->get($path)->status, $path);
    }
    contains('tienda de pruebas aislada', $full->get('/admin')->body);
    foreach (['plugins.edit', 'sites.manage', 'appearance.create', 'tools.restore', 'tools.backup'] as $cap) {
        same(false, $demo->auth()->can($cap), 'no puede ejecutar ' . $cap . ' ni con el comodín');
    }
    $limited = new TestClient($demo);
    same(302, aifecLogin($limited, DemoGuard::email($demo->site()->url()), DemoGuard::DEFAULT_PASSWORD)->status);
    same(403, $limited->get('/admin/settings/core')->status);
    same(403, $limited->get('/admin/r/users')->status);
    same(200, $limited->get('/admin/settings/ai')->status, 'la IA se puede evaluar también con el usuario limitado');
    $master = new TestClient($w['master']);
    same(422, aifecLogin($master, DemoAdministration::email($demo->site()->url()), DemoGuard::DEFAULT_PASSWORD)->status, 'la cuenta pública no entra al maestro');
});

test('el restablecimiento conserva el perfil de administración y reconstruye sus dos cuentas públicas', function (): void {
    $w = publicAdministrationWorld();
    aifecSitesRelease($w['demo']);
    $reset = $w['demos']->reset($w['id']);
    check(!empty($reset['ok']), 'se restablece la demo completa');
    $demo = aifecSitesDemoApp($w, $w['id']);
    same(true, $demo->site()->get('public_admin_demo', false));
    same(2, (int) $demo->db()->value('SELECT COUNT(*) FROM {users}'));
    same('', $demo->secret('ai.economy.key'));
    same('fake', $demo->settings()->str('ai.economy.provider'));
});

test('cambiar ajustes o aprobar una prueba en una demo nunca envía correo ni ofrece cobros externos', function (): void {
    $w = publicAdministrationWorld();
    $demo = $w['demo'];
    $demo->settings()->set('mail.mode', 'live');
    $demo->settings()->set('mail.system_always', true);
    $id = $demo->service('mailer')->queue('mail_test', 'prueba@example.com', [], ['approved' => true, 'now' => true]);
    same('held', $demo->db()->value('SELECT status FROM {mail_messages} WHERE id = ?', [$id]));
    $demo->service('payments')->register(aifecFakePayment('paypal'));
    same(null, $demo->service('payments')->get('paypal'));
    $options = $demo->service('payments')->available(['total' => 1210, 'currency' => 'EUR', 'country' => 'ES', 'needs_shipping' => false, 'customer_id' => 0, 'lang' => 'es']);
    same(['simulated'], array_map(static function ($method): string { return $method->code(); }, $options));
});

test('cambiar el proveedor de IA en el formulario de una demo descarta la clave guardada', function (): void {
    $w = publicAdministrationWorld();
    $demo = $w['demo'];
    $demo->settings()->set('ai.economy.provider', 'gemini');
    $demo->setSecret('ai.economy.key', 'clave-de-mentira');
    $web = new TestClient($demo);
    aifecLogin($web, DemoAdministration::email($demo->site()->url()), DemoGuard::DEFAULT_PASSWORD);
    $web->get('/admin/settings/ai');
    $saved = $web->post('/admin/settings/ai', ['_token' => aifecToken($web), 'f' => [
        'ai.economy.provider' => 'openai', 'ai.economy.base_url' => 'https://proveedor.example.com/v1',
        'ai.daily_limit_cents' => '2,00', 'ai.daily_limit_calls' => '200', 'ai.timeout' => '30',
    ]]);
    same(302, $saved->status, 'se guarda el formulario');
    same('', $demo->secret('ai.economy.key'), 'la clave anterior no acompaña al nuevo proveedor');
    same('openai', $demo->settings()->str('ai.economy.provider'));
});
