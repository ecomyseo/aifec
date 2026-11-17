<?php
/**
 * AIFEC — las cuentas públicas compartidas no cambian las credenciales de otros visitantes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
declare(strict_types=1);

use Aifec\Security\Passwords;

function publicIdentityWorld(string $role = 'demo_admin', bool $public = true): array
{
    $app = testApp(['config' => ['demo' => true, 'public_admin_demo' => $public]]);
    $web = adminClient($app);
    $id = (int) $app->db()->value('SELECT id FROM {users} WHERE email = ?', ['admin@tienda.test']);
    if ($role !== 'admin') {
        $app->db()->upsert('roles', ['code' => $role, 'name' => 'Perfil público ficticio', 'caps' => '["*"]', 'system' => 1], ['code']);
        $app->db()->update('users', ['role' => $role], ['id' => $id]);
    }
    $app->auth()->reset();
    return [$app, $web, $id];
}

function publicIdentityPost(TestClient $web, string $path, array $data = []): Aifec\Kernel\Response
{
    $web->get('/admin/profile');
    return $web->post('/admin/profile' . $path, ['_token' => aifecToken($web)] + $data);
}

test('la contraseña y el correo de las dos cuentas públicas no se cambian con un formulario válido', function (): void {
    foreach (['demo_admin', 'demo'] as $role) {
        [$app, $web, $id] = publicIdentityWorld($role);
        $before = $app->db()->row('SELECT * FROM {users} WHERE id = ?', [$id]);
        same(403, publicIdentityPost($web, '/password', ['current_password' => 'clave-larga-1234', 'password' => 'otra-clave-ficticia-999', 'password2' => 'otra-clave-ficticia-999'])->status);
        same(403, publicIdentityPost($web, '/data', ['name' => 'Otra persona', 'email' => 'otra@ejemplo.test', 'locale' => 'es', 'current_password' => 'clave-larga-1234'])->status);
        $after = $app->db()->row('SELECT * FROM {users} WHERE id = ?', [$id]);
        same($before, $after);
        check(Passwords::verify('clave-larga-1234', (string) $after['password']), 'la contraseña pública sigue funcionando');
        $screen = $web->get('/admin/profile');
        contains('credenciales fijas', $screen->body);
        contains('<fieldset disabled', $screen->body);
    }
});

test('la demo pública no activa el doble factor ni cierra las sesiones de otros visitantes', function (): void {
    [$app, $web, $id] = publicIdentityWorld();
    $before = $app->db()->select('SELECT id FROM {sessions} WHERE user_id = ?', [$id]);
    foreach (['/two-step/start', '/two-step/confirm', '/two-step/disable', '/two-step/codes', '/sessions/close'] as $path) {
        same(403, publicIdentityPost($web, $path, ['code' => '123456', 'current_password' => 'clave-larga-1234'])->status, $path);
    }
    same(null, $app->db()->value('SELECT totp_secret FROM {users} WHERE id = ?', [$id]));
    same(null, $app->session('admin')->get('totp_pending'));
    same($before, $app->db()->select('SELECT id FROM {sessions} WHERE user_id = ?', [$id]));
});

test('ni el comodín de la demo pública permite modificar personas o roles por el recurso del equipo', function (): void {
    [$app, $web, $id] = publicIdentityWorld();
    same(true, $app->auth()->can('team.view'));
    foreach (['team.edit', 'team.create', 'team.delete', 'team.*'] as $cap) { same(false, $app->auth()->can($cap), $cap); }
    same(200, $web->get('/admin/r/users')->status);
    $web->get('/admin/profile');
    $token = aifecToken($web);
    foreach (['/admin/r/users/' . $id . '/save', '/admin/r/users/' . $id . '/delete', '/admin/r/users/save', '/admin/r/users/bulk', '/admin/r/roles/1/save', '/admin/team/' . $id . '/two-step/remove'] as $path) {
        same(403, $web->post($path, ['_token' => $token, 'f' => ['name' => 'Otra persona', 'email' => 'otra@ejemplo.test', 'role' => 'demo_admin', 'locale' => 'es', 'active' => 0]])->status, $path);
    }
    same('admin@tienda.test', $app->db()->value('SELECT email FROM {users} WHERE id = ?', [$id]));
    same(1, (int) $app->db()->value('SELECT active FROM {users} WHERE id = ?', [$id]));
});

test('la cuenta compartida de una demo anterior queda protegida y un operador privado conserva su perfil', function (): void {
    [$app, $web] = publicIdentityWorld('demo', false);
    same(403, publicIdentityPost($web, '/password', ['current_password' => 'clave-larga-1234', 'password' => 'otra-clave-ficticia-999', 'password2' => 'otra-clave-ficticia-999'])->status);
    [$private, $operator, $id] = publicIdentityWorld('admin', false);
    same(303, publicIdentityPost($operator, '/password', ['current_password' => 'clave-larga-1234', 'password' => 'otra-clave-ficticia-999', 'password2' => 'otra-clave-ficticia-999'])->status);
    check(Passwords::verify('otra-clave-ficticia-999', (string) $private->db()->value('SELECT password FROM {users} WHERE id = ?', [$id])));
});

test('pedir la recuperación de una cuenta pública no crea fichas ni correo y mantiene la respuesta genérica', function (): void {
    foreach (['demo_admin', 'demo'] as $role) {
        [$app, $web] = publicIdentityWorld($role);
        $mailer = new class {
            public $sent = [];
            public function queue(string $template, string $to, array $data = [], array $options = []): int { $this->sent[] = $template; return count($this->sent); }
        };
        $app->bind('mailer', static function () use ($mailer) { return $mailer; });
        $before = (int) $app->db()->value('SELECT COUNT(*) FROM {tokens}');
        $response = $web->post('/admin/forgot', ['email' => 'admin@tienda.test']);
        same(200, $response->status);
        same($response->body, $web->post('/admin/forgot', ['email' => 'nadie@tienda.test'])->body);
        same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {tokens}'));
        same([], $mailer->sent);
    }
});

test('una ficha emitida antes no permite cambiar la contraseña de una cuenta pública', function (): void {
    foreach (['demo_admin', 'demo'] as $role) {
        [$app, $web, $id] = publicIdentityWorld($role);
        $token = str_repeat('ficticio', 5);
        $app->db()->insert('tokens', ['kind' => 'admin_reset', 'subject' => 'admin@tienda.test', 'hash' => hash('sha256', $token), 'data' => (string) $id,
            'created_at' => $app->clock()->sql(), 'expires_at' => $app->clock()->sql($app->clock()->now() + 3600)]);
        same(410, $web->get('/admin/reset/' . $token)->status);
        same(410, $web->post('/admin/reset/' . $token, ['password' => 'otra-clave-ficticia-999', 'password2' => 'otra-clave-ficticia-999'])->status);
        check(Passwords::verify('clave-larga-1234', (string) $app->db()->value('SELECT password FROM {users} WHERE id = ?', [$id])));
        same(null, $app->db()->value('SELECT used_at FROM {tokens} WHERE hash = ?', [hash('sha256', $token)]));
    }
});

test('la recuperación vuelve a comprobar usuario activo y correo actual antes de aceptar una ficha', function (): void {
    foreach (['inactive', 'new_email'] as $change) {
        [$app, $web, $id] = publicIdentityWorld('admin', false);
        $token = str_repeat('ficticio', 5);
        $app->db()->insert('tokens', ['kind' => 'admin_reset', 'subject' => 'admin@tienda.test', 'hash' => hash('sha256', $token), 'data' => (string) $id,
            'created_at' => $app->clock()->sql(), 'expires_at' => $app->clock()->sql($app->clock()->now() + 3600)]);
        $app->db()->update('users', $change === 'inactive' ? ['active' => 0] : ['email' => 'otra@ejemplo.test'], ['id' => $id]);
        same(410, $web->get('/admin/reset/' . $token)->status);
        same(410, $web->post('/admin/reset/' . $token, ['password' => 'otra-clave-ficticia-999', 'password2' => 'otra-clave-ficticia-999'])->status);
        check(Passwords::verify('clave-larga-1234', (string) $app->db()->value('SELECT password FROM {users} WHERE id = ?', [$id])));
    }
});
