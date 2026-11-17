<?php
/**
 * AIFEC — pruebas del instalador web, de punta a punta por el punto de entrada real (Front::run).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\SiteResolver;

require_once __DIR__ . '/support.php';

test('pantalla 1: con la carpeta de datos vacía cualquier dirección es el instalador y enseña las comprobaciones', function (): void {
    $i = aifecInstEmpty();
    foreach (['/', '/admin/login', '/producto/silla'] as $uri) {
        $r = aifecInstGet($i, $uri);
        same(200, $r->status, $uri);
        contains('Welcome to AIFEC', $r->body);
        contains('name="step" value="1"', $r->body);
    }
    $r = aifecInstGet($i, '/');
    contains('no-store', $r->headers['Cache-Control']);
    foreach (['php', 'pdo', 'mbstring', 'json', 'ctype', 'dom', 'crypto', 'images', 'intl', 'curl', 'fileinfo', 'zip', 'zlib', 'exif', 'ftp', 'data_dir', 'media_dir'] as $id) {
        contains('data-check="' . $id . '"', $r->body);
    }
    contains('af-badge-ok', $r->body);
    contains('PHP 7.4 or newer', $r->body);
    contains('rel="stylesheet" href="/assets/c/core/dev/admin/admin.css"', $r->body);
    notContains('<script', $r->body, 'sin JavaScript');
    same([], SiteResolver::all($i[1]), 'abrir el instalador no crea ningún sitio');
    check(!is_dir($i[1] . '/main'));

    // La hoja de estilo que enlaza se sirve de verdad, aunque aún no haya ningún sitio.
    same(200, aifecInstGet($i, '/assets/c/core/dev/admin/admin.css')->status);
});

test('el idioma sale de ?lang, de la cabecera del navegador o es inglés, y las dos lenguas están completas', function (): void {
    $i = aifecInstEmpty();
    contains('Welcome to AIFEC', aifecInstGet($i, '/')->body);
    contains('Te damos la bienvenida a AIFEC', aifecInstGet($i, '/?lang=es')->body);
    contains('Welcome to AIFEC', aifecInstGet($i, '/?lang=en', ['accept-language' => 'es-ES,es;q=0.9'])->body);
    contains('Te damos la bienvenida a AIFEC', aifecInstGet($i, '/', ['accept-language' => 'es-ES,es;q=0.9'])->body);
    contains('Welcome to AIFEC', aifecInstGet($i, '/?lang=xx')->body, 'un idioma inventado cae en inglés');
    $es = aifecInstGet($i, '/?lang=es')->body;
    contains('Requisitos', $es);
    contains('Continuar', $es);
    notContains('Requirements', $es);
});

test('pantalla 2 y 3: el idioma elegido y la base de datos viajan firmados de una pantalla a otra', function (): void {
    $i = aifecInstEmpty();
    $two = aifecInstPost($i, '/', ['step' => '1', 'lang' => 'es']);
    same(200, $two->status);
    contains('Base de datos', $two->body);
    contains('SQLite (no hay que configurar nada)', $two->body);
    contains('name="step" value="2"', $two->body);
    check(is_file($i[1] . '/install.key'), 'la clave temporal está en la carpeta de datos');
    contains('name="db_password" value=""', $two->body, 'el campo de la contraseña va siempre vacío: nunca vuelve al navegador');

    $three = aifecInstPost($i, '/', ['step' => '2', 'state' => aifecInstState($two->body), 'db_driver' => 'sqlite', 'go' => 'next']);
    same(200, $three->status);
    contains('Tu tienda', $three->body);
    contains('name="admin_email"', $three->body);
    contains('name="step" value="3"', $three->body);

    // «Atrás» vuelve a la pantalla anterior y conserva lo elegido.
    $back = aifecInstPost($i, '/', ['step' => '3', 'state' => aifecInstState($three->body), 'go' => 'back']);
    contains('name="step" value="2"', $back->body);
    contains('Base de datos', $back->body);
    $first = aifecInstPost($i, '/', ['step' => '2', 'state' => aifecInstState($back->body), 'go' => 'back']);
    contains('name="step" value="1"', $first->body);
    contains('Te damos la bienvenida', $first->body);
    same([], SiteResolver::all($i[1]));
});

test('pantalla 2: los errores salen junto al campo, el de conexión sin la contraseña, y no se avanza', function (): void {
    $i = aifecInstEmpty();
    $two = aifecInstPost($i, '/', ['step' => '1', 'lang' => 'en']);
    $state = aifecInstState($two->body);

    $bad = aifecInstPost($i, '/', ['step' => '2', 'state' => $state, 'go' => 'next', 'db_driver' => 'mysql', 'db_host' => 'localhost', 'db_port' => 'abc',
        'db_name' => '', 'db_user' => '', 'db_prefix' => 'AF-', 'db_password' => 'x']);
    same(422, $bad->status);
    contains('name="step" value="2"', $bad->body);
    foreach (['db_port', 'db_name', 'db_user', 'db_prefix'] as $field) {
        check(preg_match('/af-field-invalid">\s*<label class="af-label" for="af-' . $field . '"/', $bad->body) === 1, 'error junto a ' . $field);
    }
    contains('Type the name of an existing database', $bad->body);

    $down = aifecInstPost($i, '/', ['step' => '2', 'state' => $state, 'go' => 'next', 'db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => '1',
        'db_name' => 'tienda', 'db_user' => 'root', 'db_prefix' => 'af_', 'db_password' => 'sup3r-secreta']);
    same(422, $down->status, 'sin servidor de base de datos no se avanza');
    contains('name="step" value="2"', $down->body);
    notContains('sup3r-secreta', $down->body, 'el error no enseña la contraseña');
    notContains('sup3r-secreta', (string) file_get_contents($i[1] . '/install.key'), 'una contraseña que no ha conectado no se guarda');
    contains('af-alert-bad', $down->body);

    same(422, aifecInstPost($i, '/', ['step' => '2', 'state' => $state, 'go' => 'next', 'db_driver' => 'access'])->status, 'un tipo de base inventado no vale');
    same([], SiteResolver::all($i[1]));
});

test('pantalla 3 con datos malos: errores junto al campo, lo escrito se conserva (menos las contraseñas) y no se crea nada', function (): void {
    $i = aifecInstEmpty();
    $two = aifecInstPost($i, '/', ['step' => '1', 'lang' => 'es']);
    $three = aifecInstPost($i, '/', ['step' => '2', 'state' => aifecInstState($two->body), 'db_driver' => 'sqlite', 'go' => 'next']);
    $state = aifecInstState($three->body);

    $bad = aifecInstPost($i, '/', ['step' => '3', 'state' => $state, 'go' => 'next', 'shop_name' => '', 'shop_email' => 'no-es-correo',
        'admin_name' => 'Ana <b>', 'admin_email' => 'ana@', 'admin_password' => 'corta', 'admin_password2' => 'corta']);
    same(422, $bad->status);
    contains('name="step" value="3"', $bad->body);
    foreach (['shop_name', 'shop_email', 'admin_email', 'admin_password'] as $field) {
        check(preg_match('/af-field-invalid">\s*<label class="af-label" for="af-' . $field . '"/', $bad->body) === 1, 'error junto a ' . $field);
    }
    contains('Campo obligatorio', $bad->body);
    contains('Escribe un correo válido', $bad->body);
    contains('Usa al menos 10 caracteres', $bad->body);
    contains('value="Ana &lt;b&gt;"', $bad->body, 'lo escrito se conserva y va escapado');
    notContains('value="corta"', $bad->body, 'la contraseña no se devuelve al navegador');

    $mismatch = aifecInstPost($i, '/', ['step' => '3', 'state' => $state, 'go' => 'next', 'shop_name' => 'T', 'shop_email' => 'a@b.es',
        'admin_name' => 'Ana', 'admin_email' => 'ana@b.es', 'admin_password' => 'clave-larga-1234', 'admin_password2' => 'clave-larga-9999']);
    same(422, $mismatch->status);
    contains('Los dos valores no coinciden', $mismatch->body);

    same([], SiteResolver::all($i[1]), 'no hay ningún sitio');
    check(!is_dir($i[1] . '/main') && !is_file($i[1] . '/sites.php'), 'ni carpeta ni sites.php');
    contains('Welcome to AIFEC', aifecInstGet($i, '/?lang=en')->body, 'sigue siendo el instalador');
});

test('envío bueno en castellano: crea el sitio, enseña los dos enlaces y el instalador deja de existir', function (): void {
    $i = aifecInstEmpty();
    $done = aifecInstFlow($i, 'es');
    same(200, $done->status);
    contains('AIFEC está instalado', $done->body);
    contains('href="https://tienda.test/admin"', $done->body);
    contains('href="https://tienda.test/"', $done->body);
    contains('ana@tienda.test', $done->body);
    contains('Abrir el panel', $done->body);

    same([['id' => 'main', 'host' => '*', 'path' => '']], SiteResolver::all($i[1]));
    $config = require $i[1] . '/main/config.php';
    same('https://tienda.test', $config['url']);
    same('es', $config['locale']);
    same('Europe/Madrid', $config['timezone']);
    same(false, $config['demo']);
    same(64, strlen((string) $config['secret']));
    $db = aifecInstDb($i[1], 'main');
    same('Tienda & Co', json_decode((string) $db->value('SELECT v FROM {settings} WHERE k = ?', ['store.name']), true));
    same('hola@tienda.test', json_decode((string) $db->value('SELECT v FROM {settings} WHERE k = ?', ['store.email']), true));
    same('admin', $db->value('SELECT role FROM {users} WHERE email = ?', ['ana@tienda.test']));
    check(!is_file($i[1] . '/install.key'), 'la clave temporal se borra al terminar');
    check(glob($i[1] . '/install.key*') === [], 'sin restos de la clave');

    // El mismo sitio ya no es el instalador, ni con GET ni con un POST repetido.
    foreach ([aifecInstGet($i, '/'), aifecInstGet($i, '/?lang=es'), aifecInstPost($i, '/', ['step' => '1', 'lang' => 'es'])] as $r) {
        notContains('af-install-steps', $r->body, 'ya no es el instalador');
        notContains('Welcome to AIFEC', $r->body);
    }
    $login = aifecInstGet($i, '/admin/login');
    same(200, $login->status);
    contains('name="password"', $login->body);
    $enter = aifecInstPost($i, '/admin/login', ['email' => 'ana@tienda.test', 'password' => 'clave-larga-1234']);
    same(302, $enter->status, 'la persona que se acaba de dar de alta entra con su contraseña');
    $panel = aifecInstGet($i, '/admin', [], aifecInstCookies($enter));
    same(200, $panel->status);
    contains('Tienda &amp; Co', $panel->body);
});

test('envío bueno en inglés: mismas pantallas, mismo resultado, otro idioma y otra zona horaria', function (): void {
    $i = aifecInstEmpty();
    $done = aifecInstFlow($i, 'en');
    same(200, $done->status);
    contains('AIFEC is installed', $done->body);
    contains('Open the panel', $done->body);
    contains('Open the shop', $done->body);
    notContains('Abrir el panel', $done->body);
    $config = require $i[1] . '/main/config.php';
    same('en', $config['locale']);
    same('UTC', $config['timezone']);
    same(1, count(SiteResolver::all($i[1])));
});

test('un campo oculto manipulado, caducado o ausente vuelve a la pantalla 1 sin crear nada', function (): void {
    $i = aifecInstEmpty();
    $two = aifecInstPost($i, '/', ['step' => '1', 'lang' => 'es']);
    $state = aifecInstState($two->body);
    $payload = ['step' => '2', 'go' => 'next', 'db_driver' => 'sqlite'];

    $flipped = $state;
    $flipped[strlen($flipped) - 3] = $flipped[strlen($flipped) - 3] === 'A' ? 'B' : 'A';
    foreach ([$flipped, 'basura', '', substr($state, 0, 10), $state . 'x', strrev($state)] as $bad) {
        $r = aifecInstPost($i, '/', ['state' => $bad] + $payload);
        same(200, $r->status);
        contains('name="step" value="1"', $r->body, 'vuelve a la pantalla 1');
        contains('The form data expired or was changed', $r->body);
        contains('han caducado o se han modificado', aifecInstPost($i, '/', ['state' => $bad, 'lang' => 'es'] + $payload)->body);
        notContains('name="state"', $r->body);
    }
    $r = aifecInstPost($i, '/', ['step' => '3', 'state' => $flipped, 'go' => 'next', 'shop_name' => 'T', 'shop_email' => 'a@b.es',
        'admin_name' => 'Ana', 'admin_email' => 'ana@b.es', 'admin_password' => 'clave-larga-1234', 'admin_password2' => 'clave-larga-1234']);
    contains('name="step" value="1"', $r->body);
    same([], SiteResolver::all($i[1]), 'con la firma mala no se crea ningún sitio');

    // Una firma buena de otra instalación (otra clave temporal) tampoco vale.
    $other = aifecInstEmpty();
    $foreign = aifecInstState(aifecInstPost($other, '/', ['step' => '1', 'lang' => 'es'])->body);
    contains('name="step" value="1"', aifecInstPost($i, '/', ['state' => $foreign] + $payload)->body);

    // Con la clave temporal borrada, ni la firma buena vale.
    unlink($i[1] . '/install.key');
    contains('name="step" value="1"', aifecInstPost($i, '/', ['state' => $state] + $payload)->body);
});

test('solo se aceptan envíos del mismo origen', function (): void {
    $i = aifecInstEmpty();
    same(403, aifecInstPost($i, '/', ['step' => '1', 'lang' => 'es'], ['sec-fetch-site' => 'cross-site'])->status);
    same(403, aifecInstPost($i, '/', ['step' => '1', 'lang' => 'es'], ['origin' => 'https://malo.example'])->status);
    check(!is_file($i[1] . '/install.key'), 'un envío de otro origen no toca el disco');
    same(200, aifecInstPost($i, '/', ['step' => '1', 'lang' => 'es'], ['origin' => 'https://tienda.test'])->status);
});

test('si el servidor no cumple un requisito obligatorio no se deja seguir', function (): void {
    $file = tmpDir('aifec-inst-file') . '/soy-un-fichero';
    file_put_contents($file, 'x');
    $i = [aifecInstRoot(), $file . '/datos', tmpDir('aifec-inst-public')];
    $page = aifecInstGet($i, '/');
    same(200, $page->status);
    contains('af-badge-bad', $page->body);
    contains('Fix the items marked', $page->body);
    contains('disabled', $page->body, 'el botón de continuar está apagado');
    $post = aifecInstPost($i, '/', ['step' => '1', 'lang' => 'en']);
    contains('name="step" value="1"', $post->body);
    notContains('name="state"', $post->body);
    check(!file_exists($file . '/datos'), 'no se escribe nada');
});
