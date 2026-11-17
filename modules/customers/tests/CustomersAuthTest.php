<?php
/**
 * AIFEC — pruebas de entrada de clientes: contraseña, límite de intentos, formatos heredados, restablecer, enlace de entrada y verificación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Security\Passwords;

require_once __DIR__ . '/CustomersKit.php';

test('entrar con la contraseña buena abre la sesión y con la mala no', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $seen = [];
    $app->hooks()->on('customer.login', static function (array $customer) use (&$seen): void {
        $seen[] = $customer['id'];
    });

    same(null, $service->login('ana@cliente.test', 'clave-mala-999'));
    same(null, $app->auth()->customerId());
    same(1, customersCount($app, 'login_attempts', "guard = 'customer' AND ok = 0"));

    same($id, $service->login(' ANA@cliente.test ', 'clave-larga-1'));
    same($id, $app->auth()->customerId());
    $current = $service->current();
    check($current !== null && $current['email'] === 'ana@cliente.test', 'current() devuelve al cliente');
    check(!array_key_exists('password', (array) $current), 'sin contraseña');
    same('2026-11-12 12:00:00', customersRow($app, $id)['last_login_at']);
    same([$id], $seen);
    same(0, customersCount($app, 'login_attempts', "guard = 'customer' AND ok = 0"), 'tras entrar bien los fallos dejan de contar');

    $service->logout();
    same(null, $app->auth()->customerId());
    same(null, $service->current());
    same(null, $service->login('', ''));
});

test('un invitado no entra con contraseña, ni una cuenta inactiva, ni una que no existe', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    customersMakeGuest($app, 'invitado@cliente.test');
    // Aunque a un invitado le hubieran puesto un resumen de contraseña, no es una cuenta.
    customersMakeGuest($app, 'raro@cliente.test', ['password' => customersHash('clave-larga-1')]);
    customersMakeAccount($app, 'apagada@cliente.test', 'clave-larga-1', ['active' => 0]);

    same(null, $service->login('invitado@cliente.test', 'clave-larga-1'));
    same(null, $service->login('raro@cliente.test', 'clave-larga-1'));
    same(null, $service->login('apagada@cliente.test', 'clave-larga-1'));
    same(null, $service->login('nadie@cliente.test', 'clave-larga-1'));
    same(null, $app->auth()->customerId());
    same(4, customersCount($app, 'login_attempts', "guard = 'customer' AND ok = 0"), 'todos cuentan como fallo');
});

test('cinco fallos bloquean la cuenta y a los 15 minutos se puede otra vez', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    for ($i = 0; $i < 5; ++$i) {
        same(null, $service->login('ana@cliente.test', 'mala-' . $i));
    }
    same(true, $service->isLocked('ana@cliente.test'));
    same(null, $service->login('ana@cliente.test', 'clave-larga-1'), 'bloqueada: ni la buena entra');
    same(5, customersCount($app, 'login_attempts', "guard = 'customer' AND ok = 0"), 'un intento bloqueado no suma');

    $app->clock()->advance(14 * 60);
    same(null, $service->login('ana@cliente.test', 'clave-larga-1'), 'a los 14 minutos sigue bloqueada');
    $app->clock()->advance(2 * 60);
    same(false, $service->isLocked('ana@cliente.test'));
    same($id, $service->login('ana@cliente.test', 'clave-larga-1'), 'pasados los 15 minutos entra');
});

test('veinte fallos desde una misma dirección bloquean esa dirección, y una cuenta que no existe también se cuenta', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    $now = $app->clock()->sql();
    for ($i = 0; $i < 20; ++$i) {
        $app->db()->insert('login_attempts', ['guard' => 'customer', 'identifier' => 'otro' . $i . '@x.test', 'ip' => '127.0.0.1', 'ok' => 0, 'created_at' => $now]);
    }
    same(null, $service->login('ana@cliente.test', 'clave-larga-1'), 'la dirección está agotada aunque esta cuenta no haya fallado');
    same(true, $service->isLocked('ana@cliente.test'));

    // Otra aplicación limpia: cinco fallos contra un correo inexistente también bloquean (no se distingue si existe).
    [$app2] = customersTestApp();
    $service2 = customersService($app2);
    for ($i = 0; $i < 5; ++$i) {
        same(null, $service2->login('fantasma@cliente.test', 'mala-' . $i));
    }
    same(true, $service2->isLocked('fantasma@cliente.test'));
    same(false, $service2->isLocked('ana@cliente.test'));
});

test('una contraseña con formato de WordPress entra y queda regrabada con el algoritmo actual', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $md5 = customersMakeAccount($app, 'md5@cliente.test', 'x', ['password' => md5('secreto-123')]);
    $prehashed = base64_encode(hash_hmac('sha384', 'secreto-123', 'wp-sha384', true));
    $modern = customersMakeAccount($app, 'wp@cliente.test', 'x', ['password' => '$wp' . password_hash($prehashed, PASSWORD_BCRYPT, ['cost' => 4])]);

    same(null, $service->login('md5@cliente.test', 'otra-clave'));
    same($md5, $service->login('md5@cliente.test', 'secreto-123'));
    $hash = (string) customersRow($app, $md5)['password'];
    check($hash !== md5('secreto-123'), 'ya no es el md5');
    check(Passwords::verify('secreto-123', $hash) && !Passwords::needsRehash($hash), 'regrabada con el algoritmo actual');

    same($modern, $service->login('wp@cliente.test', 'secreto-123'));
    $hash = (string) customersRow($app, $modern)['password'];
    check(strpos($hash, '$wp') !== 0 && !Passwords::needsRehash($hash), 'el formato nuevo de WordPress también se regraba');
    $service->logout();
    same($modern, $service->login('wp@cliente.test', 'secreto-123'), 'y con la regrabada sigue entrando');
});

test('una contraseña antigua de PrestaShop entra con la clave de cookie del ajuste secreto', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ps@cliente.test', 'x', ['password' => md5('CLAVE-DE-COOKIE' . 'secreto-123')]);

    same(null, $service->login('ps@cliente.test', 'secreto-123'), 'sin la clave de la tienda antigua no se puede comprobar');
    $app->setSecret('customers.legacy_cookie_key', 'CLAVE-DE-COOKIE');
    same(null, $service->login('ps@cliente.test', 'otra-clave'));
    same($id, $service->login('ps@cliente.test', 'secreto-123'));
    $hash = (string) customersRow($app, $id)['password'];
    check(Passwords::verify('secreto-123', $hash) && !Passwords::needsRehash($hash), 'queda regrabada');
});

test('restablecer: enlace de un solo uso que caduca a la hora, y respuesta idéntica con un correo inexistente', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    customersMakeSession($app, $id);

    $service->requestReset('ANA@cliente.test');
    same(1, count($transport->sent));
    same(['ana@cliente.test'], $transport->sent[0]->recipients());
    contains('Elige una contraseña nueva', $transport->sent[0]->getSubject());
    $token = customersMailToken($transport);
    $stored = (string) $app->db()->value("SELECT hash FROM {tokens} WHERE kind = 'customer_reset'");
    same(hash('sha256', $token), $stored, 'se guarda el resumen, no la ficha');
    notContains($token, json_encode($app->db()->select('SELECT * FROM {tokens}')) ?: '');

    throws(function () use ($service, $token): void {
        $service->resetPassword($token, 'corta');
    }, \InvalidArgumentException::class);
    same(true, $service->resetPassword($token, 'nueva-clave-99'), 'una contraseña corta no gastó la ficha');
    same(false, $service->resetPassword($token, 'otra-clave-123'), 'la ficha solo vale una vez');
    same(null, $service->login('ana@cliente.test', 'clave-larga-1'));
    same($id, $service->login('ana@cliente.test', 'nueva-clave-99'));
    same(0, customersCount($app, 'sessions', "guard = 'customer' AND user_id = ?", [$id]), 'quien tuviera sesión se queda fuera');

    // Caduca a la hora.
    $service->requestReset('ana@cliente.test');
    $late = customersMailToken($transport);
    $app->clock()->advance(3601);
    same(false, $service->resetPassword($late, 'tercera-clave-1'));
    $service->requestReset('ana@cliente.test');
    $fresh = customersMailToken($transport);
    $app->clock()->advance(3599);
    same(true, $service->resetPassword($fresh, 'cuarta-clave-12'), 'justo antes de la hora aún vale');

    // Un correo que no existe: misma respuesta (nada, sin excepción) y ningún correo ni ficha.
    $mails = count($transport->sent);
    $tokens = customersCount($app, 'tokens');
    $service->requestReset('nadie@cliente.test');
    $service->requestReset('esto-no-es-un-correo');
    $service->requestReset('');
    same($mails, count($transport->sent));
    same($tokens, customersCount($app, 'tokens'));
    same(false, $service->resetPassword('no-existe-esta-ficha-' . str_repeat('a', 30), 'clave-larga-1'));
});

test('restablecer no sirve para un invitado ni para una cuenta apagada, y manda pocos correos seguidos', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    customersMakeGuest($app, 'invitado@cliente.test');
    customersMakeAccount($app, 'apagada@cliente.test', 'clave-larga-1', ['active' => 0]);
    $service->requestReset('invitado@cliente.test');
    $service->requestReset('apagada@cliente.test');
    same(0, count($transport->sent));

    customersMakeAccount($app, 'ana@cliente.test');
    for ($i = 0; $i < 6; ++$i) {
        $service->requestReset('ana@cliente.test');
    }
    same(3, count($transport->sent), 'como mucho tres en diez minutos');
    $app->clock()->advance(601);
    $service->requestReset('ana@cliente.test');
    same(4, count($transport->sent));
});

test('el enlace de entrada entra una vez, caduca a los 15 minutos y se apaga con el ajuste', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test');

    $service->requestMagicLink('ana@cliente.test');
    same(1, count($transport->sent));
    contains('Tu enlace de acceso', $transport->sent[0]->getSubject());
    $token = customersMailToken($transport);
    same($id, $service->loginWithToken($token));
    same($id, $app->auth()->customerId());
    same('2026-11-12 12:00:00', customersRow($app, $id)['last_login_at']);
    $service->logout();
    same(null, $service->loginWithToken($token), 'solo una vez');
    same(null, $app->auth()->customerId());

    $service->requestMagicLink('ana@cliente.test');
    $late = customersMailToken($transport);
    $app->clock()->advance(901);
    same(null, $service->loginWithToken($late), 'caduca a los 15 minutos');

    $service->requestMagicLink('ana@cliente.test');
    $fresh = customersMailToken($transport);
    $app->settings()->set('customers.magic_link', false);
    same(null, $service->loginWithToken($fresh), 'con el ajuste apagado ni la ficha buena vale');
    $mails = count($transport->sent);
    $service->requestMagicLink('ana@cliente.test');
    same($mails, count($transport->sent), 'ni se manda');
    $app->settings()->set('customers.magic_link', true);
    same($id, $service->loginWithToken($fresh), 'al encenderlo vuelve a valer (no se había gastado)');

    same(null, $service->loginWithToken('esto-no-es-una-ficha-' . str_repeat('b', 30)));
    same(null, $service->loginWithToken(''));
});

test('el enlace de entrada de un cliente cuyo correo cambió o que se apagó ya no vale', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test');
    $service->requestMagicLink('ana@cliente.test');
    $token = customersMailToken($transport);
    $app->db()->update('customers', ['email' => 'otro@cliente.test'], ['id' => $id]);
    same(null, $service->loginWithToken($token), 'la ficha era para otro correo');

    $app->db()->update('customers', ['email' => 'ana@cliente.test', 'active' => 0], ['id' => $id]);
    same(null, $service->loginWithToken($token), 'una cuenta apagada no entra');
});

test('verificar el correo: el enlace se usa una vez y no vale si el correo cambió', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $id = $service->register(['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    $token = customersMailToken($transport);
    same(false, $service->isVerified($id));
    same(true, $service->verify($token));
    same(true, $service->isVerified($id));
    same(false, $service->verify($token), 'solo una vez');
    same(false, $service->verify('x'));

    // Ya verificada: pedir otro enlace no manda nada.
    $mails = count($transport->sent);
    $service->sendVerification($id);
    same($mails, count($transport->sent));

    // Con un correo nuevo, el enlace antiguo no vale y llega uno nuevo.
    $service->update($id, ['email' => 'nueva@cliente.test']);
    same($mails + 1, count($transport->sent));
    same(['nueva@cliente.test'], $transport->sent[$mails]->recipients());
    $new = customersMailToken($transport);
    same(false, $service->isVerified($id));
    $old = (int) $app->db()->value("SELECT id FROM {tokens} WHERE kind = 'customer_verify' ORDER BY id");
    check($old > 0, 'queda la ficha antigua');
    $app->db()->update('tokens', ['used_at' => null], ['id' => $old]);
    same(false, $service->verify($token), 'la ficha del correo antiguo no vale con el correo nuevo');
    same(true, $service->verify($new));
    same(true, $service->isVerified($id));

    // Caduca a los siete días.
    $other = customersMakeAccount($app, 'otra@cliente.test', 'clave-larga-1', ['email_verified_at' => null]);
    $service->sendVerification($other);
    $late = customersMailToken($transport);
    $app->clock()->advance(7 * 86400 + 1);
    same(false, $service->verify($late));
    same(false, $service->isVerified($other));
});
