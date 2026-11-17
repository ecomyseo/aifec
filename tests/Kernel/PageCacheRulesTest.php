<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../Perf/helpers.php';
require_once __DIR__ . '/../../modules/catalog/tests/helpers.php';
require_once __DIR__ . '/../../modules/orders/tests/helpers.php';
require_once __DIR__ . '/../../modules/cms/tests/helpers.php';

/** La copia que serviría el servidor a un visitante con estas cookies (antes de arrancar la aplicación). */
function pcrServe(App $app, string $uri, array $cookies = []): ?Response
{
    $request = Request::create('GET', $uri, [], $cookies, ['sec-fetch-site' => 'same-origin', 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0)']);
    $request->host = 'tienda.test';
    $request->secure = true;

    return PageCache::serve($app->site(), $request, $app->clock());
}

/** Tienda con carrito, páginas de contenido (una con el formulario de contacto) y cuentas de cliente. */
function pcrShop(): App
{
    $app = aifecCartApp(['modules' => ['cms', 'customers', 'mail'], 'settings' => ['mail.mode' => 'log', 'store.email' => 'tienda@ejemplo.test']]);
    cmsInsertPage($app, ['title' => 'Quiénes somos', 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Somos una tienda pequeña & familiar.']]]]);
    cmsInsertPage($app, ['title' => 'Contacto', 'blocks' => [['type' => 'form', 'attrs' => ['form_id' => 'contact']]]]);

    return $app;
}

test('un visitante con carrito y cookie de sesión de cliente (sin cuenta abierta) recibe la copia como cualquier otro', function (): void {
    $app = pcrShop();
    $p = aifecProduct($app, ['name' => 'Silla Ñandú', 'stock' => 5]);
    $anon = new TestClient($app);
    same('MISS', $anon->get('/quienes-somos')->headers['X-Cache']);

    $buyer = new TestClient($app);
    // Sin JavaScript y con un error: deja un aviso en la sesión (cookie de sesión de cliente) y la marca «nc».
    $buyer->post('/carrito/add', ['product_id' => 999999, 'qty' => 1]);
    check(isset($buyer->cookies[$app->cookieName('c')]), 'tiene sesión de cliente');
    check(isset($buyer->cookies[$app->cookieName('nc')]), 'y la marca de «pendiente» mientras no vea el aviso');
    $buyer->follow();
    check(!isset($buyer->cookies[$app->cookieName('nc')]), 'visto el aviso, la marca se va');
    $buyer->post('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    check(isset($buyer->cookies[$app->cookieName('k')]), 'y carrito');

    $hit = pcrServe($app, '/quienes-somos', $buyer->cookies);
    check($hit !== null, 'con carrito y sesión sale la copia');
    same('HIT', $hit->headers['X-Cache']);
    same(PageCache::key($app->site(), Request::create('GET', '/quienes-somos')), PageCache::key($app->site(), Request::create('GET', '/quienes-somos', [], $buyer->cookies)), 'la misma copia que para quien no tiene nada');
});

test('con la marca de cuenta abierta («u») no hay copia, y una persona con cuenta nunca recibe ni deja la de otra', function (): void {
    $app = pcrShop();
    $now = $app->clock()->sql();
    $app->db()->insert('customers', [
        'email' => 'ana@cliente.test', 'password' => \Aifec\Security\Passwords::hash('clave-larga-1'), 'guest' => 0, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
        'group_id' => 1, 'locale' => 'es', 'active' => 1, 'marketing' => 0, 'orders_count' => 0, 'total_spent' => 0,
        'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $anon = new TestClient($app);
    $copy = $anon->get('/quienes-somos');
    same('MISS', $copy->headers['X-Cache']);

    $ana = new TestClient($app);
    $ana->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    check(isset($ana->cookies[$app->cookieName('u')]), 'ha entrado (marca «u»)');
    same(null, PageCache::key($app->site(), Request::create('GET', '/quienes-somos', [], $ana->cookies)), 'sin clave de caché');
    same(null, pcrServe($app, '/quienes-somos', $ana->cookies), 'no recibe la copia de todos');

    $app->invalidate(['cms']);
    same(null, pcrServe($app, '/quienes-somos'), 'sin copia antes de su visita');
    $hers = $ana->get('/quienes-somos');
    same(200, $hers->status);
    check($hers->headers['X-Cache'] !== 'MISS', 'su página no se guarda para los demás');
    same(null, pcrServe($app, '/quienes-somos'), 'y no queda ninguna copia hecha con su visita');
    $again = $anon->get('/quienes-somos');
    same('MISS', $again->headers['X-Cache']);
    notContains('Ana', $again->body, 'la copia de todos no lleva su nombre');
});

test('con la marca «nc» no hay copia, y se quita al pintar los errores del formulario enviado sin JavaScript', function (): void {
    $app = pcrShop();
    $anon = new TestClient($app);
    same('MISS', $anon->get('/contacto')->headers['X-Cache']);
    $web = new TestClient($app);
    $r = $web->post('/formulario/contact', ['f' => ['name' => '', 'email' => 'ana@cliente.test', 'message' => 'Hola & adiós', 'consent' => ''], 'website' => '', '_mark' => ''], ['referer' => 'https://tienda.test/contacto']);
    same(302, $r->status);
    $nc = $app->cookieName('nc');
    check(isset($r->cookies[$nc]), 'el envío con errores pone la marca');
    same(false, $r->cookies[$nc]['options']['httponly'], 'legible: es una marca, no un secreto');
    check((int) $r->cookies[$nc]['options']['expires'] <= $app->clock()->now() + 600, 'de vida corta');
    same(null, pcrServe($app, '/contacto', $web->cookies), 'con la marca no sale la copia');

    $page = $web->follow();
    contains('aria-invalid="true"', $page->body, 'los errores se pintan');
    contains('Hola &amp; adiós', $page->body, 'con lo que había escrito');
    check(!isset($web->cookies[$nc]), 'pintados los errores, la marca se quita');
    $copy = pcrServe($app, '/contacto', $web->cookies);
    check($copy !== null, 'y vuelve a recibir la copia de todos');
    notContains('aria-invalid="true"', $copy->body, 'que no lleva errores de nadie');
});

test('el aviso de «añadido al carrito» sin JavaScript sigue saliendo, y el de error también', function (): void {
    $app = pcrShop();
    $p = aifecProduct($app, ['name' => 'Mesa Roble & Haya', 'stock' => 5]);
    $web = new TestClient($app);
    $r = $web->post('/carrito/add', ['product_id' => $p, 'qty' => 2]);
    check($r->isRedirect(), 'sin JavaScript se vuelve al carrito');
    $cart = $web->follow();
    same(200, $cart->status);
    contains('Mesa Roble &amp; Haya', $cart->body, 'el carrito enseña lo añadido');
    check(!isset($web->cookies[$app->cookieName('nc')]), 'un añadido bueno no deja nada pendiente');

    $web->post('/carrito/add', ['product_id' => $p, 'qty' => 0]);
    check(isset($web->cookies[$app->cookieName('nc')]), 'un error deja la marca');
    $again = $web->follow();
    contains($app->i18n()->t('Enter a valid quantity'), $again->body, 'y el aviso sale en el carrito');
    check(!isset($web->cookies[$app->cookieName('nc')]), 'y la marca se quita al verlo');
});

test('el carrito, la caja, la cuenta, la página del pedido y el resumen del carrito no se guardan nunca', function (): void {
    $app = pcrShop();
    $p = aifecProduct($app, ['name' => 'Lámpara', 'stock' => 5]);
    $web = new TestClient($app);
    $web->post('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    foreach (['/carrito', '/finalizar-compra', '/mi-cuenta/entrar', '/pedido/AF-1/abc', '/carrito/summary.json'] as $uri) {
        $r = $web->get($uri);
        same(0, $r->cacheTtl, $uri . ' no se marca como guardable');
        check(!isset($r->headers['X-Cache']) || $r->headers['X-Cache'] !== 'MISS', $uri . ' no se guarda');
        same(null, pcrServe($app, $uri), $uri . ' no tiene copia');
    }
});

test('la marca «nc» también vale para los avisos de un solo uso (flash) y para las claves apuntadas', function (): void {
    $app = testApp(['root' => perfRoot(), 'settings' => ['modules.active' => ['alfa', 'beta']]]);
    $nc = $app->cookieName('nc');
    $web = new TestClient($app);
    $r = $web->get('/beta/flash');
    check(isset($r->cookies[$nc]), 'un aviso pendiente pone la marca');
    $r = $web->get('/alfa/1');
    same([], array_intersect_key($r->cookies, [$nc => 1]), 'una página que no mira la sesión no la toca');
    $r = $web->get('/beta/leer');
    contains('Guardado', $r->body);
    same('', $r->cookies[$nc]['value'], 'leído el aviso, se borra la marca');

    // Una clave apuntada con pendingForNextPage(): la marca dura hasta que alguien la borra de la sesión.
    $app->router()->get('/apuntar', static function (Request $q, App $app): string {
        $app->session('customer')->set('mi_estado', ['x' => 1]);
        $app->pendingForNextPage('mi_estado');

        return 'apuntado';
    });
    $app->router()->get('/consumir', static function (Request $q, App $app): string {
        $app->session('customer')->forget('mi_estado');

        return 'consumido';
    });
    $app->router()->get('/mirar', static function (Request $q, App $app): string {
        return (string) json_encode($app->session('customer')->get('mi_estado'));
    });
    check(isset($web->get('/apuntar')->cookies[$nc]), 'apuntar pone la marca');
    $look = $web->get('/mirar');
    check(!isset($look->cookies[$nc]), 'mirar sin consumir la deja');
    check(isset($web->cookies[$nc]), 'sigue puesta');
    same('', $web->get('/consumir')->cookies[$nc]['value'], 'consumir la quita');
});
