<?php
/**
 * AIFEC — pruebas del recorrido de las passkeys por HTTP: alta con la sesión abierta, entrada sin contraseña, y los rechazos
 * (firma de otra clave, desafío gastado, contador repetido, cuenta desactivada, passkeys apagadas, otro origen).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Da de alta una passkey por el recorrido real del panel y devuelve la clave y el id del usuario. */
function pkRegister(\Aifec\Kernel\App $app, string $name = 'Mi móvil'): array
{
    $web = adminClient($app);
    $web->headers['x-csrf-token'] = aifecToken($web);
    $web->postJson('/admin/passkeys/options');
    $options = $web->json();
    $challenge = $options['publicKey']['challenge'];
    $key = FakePasskey::create();
    $body = $key->registration($challenge, 'https://tienda.test', 'tienda.test') + ['name' => $name];
    $res = $web->postJson('/admin/passkeys/register', $body);
    same(200, $res->status);

    return [$key, (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'"), $web];
}

/** Entra con la passkey desde un navegador sin sesión. */
function pkLogin(\Aifec\Kernel\App $app, FakePasskey $key, int $count, ?string $origin = null, ?TestClient $web = null): TestClient
{
    $web = $web ?: new TestClient($app);
    $web->postJson('/admin/passkey/options');
    $challenge = $web->json()['publicKey']['challenge'] ?? 'sin-desafio';
    $web->postJson('/admin/passkey/login', $key->assertion($challenge, $origin ?: 'https://tienda.test', 'tienda.test', $count));

    return $web;
}

test('una passkey se da de alta desde el panel y queda guardada solo con su clave pública', function (): void {
    $app = securityApp();
    [$key, $userId] = pkRegister($app);
    $row = $app->db()->row('SELECT * FROM {passkeys} WHERE user_id = ?', [$userId]);
    same($key->id(), $row['credential_id']);
    same('Mi móvil', $row['name']);
    contains('BEGIN PUBLIC KEY', $row['public_key']);
    notContains('PRIVATE', $row['public_key']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'passkey.add'"));
});

test('con la passkey se entra al panel sin contraseña y queda apuntado', function (): void {
    $app = securityApp();
    [$key, $userId] = pkRegister($app);
    $web = pkLogin($app, $key, 1);
    same(200, $web->last->status);
    $json = $web->json();
    same(true, $json['ok']);
    contains('/admin', $json['redirect']);
    same(200, $web->get('/admin')->status);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'login.passkey' AND user_id = ?", [$userId]));
    same(1, (int) $app->db()->value('SELECT sign_count FROM {passkeys} WHERE user_id = ?', [$userId]));
    same(false, $app->db()->value('SELECT last_used_at FROM {passkeys} WHERE user_id = ?', [$userId]) === null);
});

test('la firma de otra clave, un contador repetido y un desafío gastado no dejan entrar', function (): void {
    $app = securityApp();
    [$key] = pkRegister($app);
    $stranger = FakePasskey::create();
    $stranger->credentialId = $key->credentialId; // dice ser la misma credencial pero firma con otra clave
    $web = pkLogin($app, $stranger, 1);
    same(422, $web->last->status);
    same(302, $web->get('/admin/security')->status);

    $ok = pkLogin($app, $key, 3);
    same(200, $ok->last->status);
    // Repetir el mismo contador es una clave copiada.
    $again = pkLogin($app, $key, 3);
    same(422, $again->last->status);

    // Un desafío solo vale una vez: la segunda respuesta con el mismo desafío falla.
    $web2 = new TestClient($app);
    $web2->postJson('/admin/passkey/options');
    $challenge = $web2->json()['publicKey']['challenge'];
    $web2->postJson('/admin/passkey/login', $key->assertion($challenge, 'https://tienda.test', 'tienda.test', 4));
    same(200, $web2->last->status);
    $web3 = new TestClient($app);
    $web3->postJson('/admin/passkey/login', $key->assertion($challenge, 'https://tienda.test', 'tienda.test', 5));
    same(422, $web3->last->status);
    // Y en la misma sesión, repetir el desafío ya gastado tampoco vale (alguien que graba la respuesta y la repite).
    $web4 = new TestClient($app);
    $web4->postJson('/admin/passkey/options');
    $again = $web4->json()['publicKey']['challenge'];
    $web4->postJson('/admin/passkey/login', $key->assertion($again, 'https://tienda.test', 'tienda.test', 6));
    same(200, $web4->last->status);
    $web4->postJson('/admin/passkey/login', $key->assertion($again, 'https://tienda.test', 'tienda.test', 7));
    same(422, $web4->last->status);
});

test('desde otro origen la firma no vale y una cuenta desactivada no entra', function (): void {
    $app = securityApp();
    [$key, $userId] = pkRegister($app);
    $web = pkLogin($app, $key, 1, 'https://falsa.test');
    same(422, $web->last->status);
    $app->db()->update('users', ['active' => 0], ['id' => $userId]);
    $web = pkLogin($app, $key, 2);
    same(422, $web->last->status);
    $web = new TestClient($app);
    $web->get('/admin');
    same(302, $web->last->status);
});

test('con las passkeys apagadas no hay entrada ni botón', function (): void {
    $app = securityApp(['settings' => ['security.passkeys' => false]]);
    $web = new TestClient($app);
    same(404, $web->postJson('/admin/passkey/options')->status);
    notContains('af-passkey-login', $web->get('/admin/login')->body);
});

test('la pantalla de acceso lleva el botón de passkey y la entrada desde otro sitio se rechaza', function (): void {
    $app = securityApp();
    $web = new TestClient($app);
    $login = $web->get('/admin/login');
    contains('af-passkey-login', $login->body);
    contains('passkey.js', $login->body);
    $web->headers['sec-fetch-site'] = 'cross-site';
    same(403, $web->postJson('/admin/passkey/options')->status);
});

test('tras diez fallos desde una dirección la entrada con passkey espera', function (): void {
    $app = securityApp();
    [$key] = pkRegister($app);
    $stranger = FakePasskey::create();
    $stranger->credentialId = $key->credentialId;
    for ($i = 1; $i <= 10; ++$i) {
        same(422, pkLogin($app, $stranger, $i)->last->status);
    }
    same(429, pkLogin($app, $key, 99)->last->status);
});

test('se borra una passkey propia y no la de otra persona', function (): void {
    $app = securityApp();
    [$key, $userId, $web] = pkRegister($app);
    $id = (int) $app->db()->value('SELECT id FROM {passkeys} WHERE user_id = ?', [$userId]);
    // La de otra persona no se toca.
    $other = aifecAdmin($app, 'admin', 'otra@tienda.test', 'Otra');
    $app->db()->insert('passkeys', ['user_id' => $other, 'credential_id' => 'ajena', 'public_key' => 'x', 'sign_count' => 0, 'name' => 'Ajena', 'created_at' => $app->clock()->sql()]);
    $foreign = (int) $app->db()->value("SELECT id FROM {passkeys} WHERE credential_id = 'ajena'");
    $web->get('/admin/security/passkeys');
    $token = aifecToken($web);
    $web->post('/admin/passkeys/delete', ['id' => $foreign, '_token' => $token]);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {passkeys} WHERE credential_id = 'ajena'"));
    $web->post('/admin/passkeys/delete', ['id' => $id, '_token' => $token]);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {passkeys} WHERE id = ?', [$id]));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'passkey.delete'"));
});
