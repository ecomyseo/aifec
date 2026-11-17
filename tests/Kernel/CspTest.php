<?php
/**
 * AIFEC — pruebas de la política de seguridad de contenido (cabecera Content-Security-Policy).
 *
 * Lo que se comprueba aquí es lo que ve un navegador: la cabecera de cada página HTML, y que cada guion escrito dentro de una
 * página propia lleva su huella en esa cabecera (si no, el navegador no lo ejecutaría y se rompería sin un error en el servidor).
 *
 * Nace de una revisión de seguridad que pasó el usuario el 13/11/2026: «no vi las cabeceras Content-Security-Policy,
 * X-Frame-Options o frame-ancestors y Permissions-Policy».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Csp;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../modules/cms/tests/helpers.php';
require_once __DIR__ . '/../../modules/consent/tests/helpers.php';
require_once __DIR__ . '/../../modules/datalayer/tests/helpers.php';

/** La cabecera como directiva => fuentes. */
function cspParse(Response $r): array
{
    $out = [];
    foreach (explode(';', isset($r->headers['Content-Security-Policy']) ? (string) $r->headers['Content-Security-Policy'] : '') as $part) {
        $words = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($words) && $words !== []) {
            $out[array_shift($words)] = $words;
        }
    }

    return $out;
}

/** Los guiones escritos dentro de la página que un navegador ejecutaría (sin «src» y sin un tipo de datos). */
function cspInlineScripts(string $html): array
{
    $found = [];
    if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $all, PREG_SET_ORDER) > 0) {
        foreach ($all as $m) {
            if (stripos($m[1], 'src=') !== false || trim($m[2]) === '') {
                continue;
            }
            if (preg_match('/type="([^"]+)"/i', $m[1], $type) === 1 && !in_array(strtolower($type[1]), ['text/javascript', 'module'], true)) {
                continue;
            }
            $found[] = $m[2];
        }
    }

    return $found;
}

/** Comprueba que la página lleva la política y que todos sus guiones en línea tienen su huella en ella. Devuelve cuántos había. */
function cspCovers(Response $r, string $what): int
{
    $csp = cspParse($r);
    check(isset($csp['script-src']), $what . ': lleva política');
    $scripts = cspInlineScripts($r->body);
    foreach ($scripts as $js) {
        check(in_array(Csp::hash($js), $csp['script-src'], true), $what . ': el guion en línea «' . mb_substr(trim($js), 0, 40, 'UTF-8') . '…» tiene su huella en la cabecera');
    }
    notContains(' onclick="', $r->body, $what . ': sin manejadores escritos en la página');
    notContains(' onchange="', $r->body);
    notContains(' onsubmit="', $r->body);

    return count($scripts);
}

test('la política por defecto es estricta: guiones solo de la tienda, sin objetos, sin <base> ajeno y sin marcos de otra web', function (): void {
    $csp = new Csp();
    $header = $csp->header();

    contains("default-src 'self'", $header);
    contains("script-src 'self'", $header);
    contains("object-src 'none'", $header);
    contains("base-uri 'self'", $header);
    contains("frame-ancestors 'self'", $header);
    notContains("script-src 'self' 'unsafe-inline'", $header, 'un guion inyectado en la página no se ejecuta');
    notContains('upgrade-insecure-requests', $header, 'en http no se fuerza https');
    contains('; upgrade-insecure-requests', $csp->header(true));
    same(["'self'"], $csp->sources('script-src'));
});

test('la huella de un guion en línea es su sha256 en base64, y se añade una sola vez', function (): void {
    $csp = new Csp();
    $csp->inline('alert(1)')->inline('alert(1)');

    same("'sha256-" . base64_encode(hash('sha256', 'alert(1)', true)) . "'", Csp::hash('alert(1)'));
    same(["'self'", Csp::hash('alert(1)')], $csp->sources('script-src'));
});

test('add() y allow() solo aceptan fuentes con forma de fuente: nada de «;», espacios, http ni comodines sueltos', function (): void {
    $csp = new Csp();
    $csp->add('frame-src', 'https://mapas.example', 'https://mapas.example', "https://x.example; script-src *", 'http://inseguro.example', '*', 'javascript:', "'unsafe-eval'");
    $csp->add('mal nombre', 'https://x.example');
    $csp->allow('https://cdn.example');

    same(["'self'", 'https://mapas.example', "'unsafe-eval'", 'https://cdn.example'], $csp->sources('frame-src'));
    same(["'self'", 'https://cdn.example'], $csp->sources('script-src'));
    notContains('mal nombre', $csp->header());
    notContains('inseguro', $csp->header());
});

test('los orígenes de los ajustes: uno por línea, con https; lo demás se descarta', function (): void {
    same(['https://cdn.example.com', 'https://*.fotos.example', 'wss://vivo.example:8443'], Csp::origins("https://cdn.example.com/\nhttps://*.fotos.example, wss://vivo.example:8443\nhttp://no.example\njavascript:alert(1)\n'unsafe-inline'\nhttps://cdn.example.com"));
    same([], Csp::origins(''));
});

test('relajada: los guiones dejan de estar limitados y las huellas se quitan (con una huella el navegador ignora unsafe-inline)', function (): void {
    $csp = new Csp();
    $csp->inline('a()')->relax();

    same(["'self'", "'unsafe-inline'", 'https:'], $csp->sources('script-src'));
    contains("object-src 'none'", $csp->header(), 'lo demás sigue en pie');
    contains("frame-ancestors 'self'", $csp->header());
    same(true, in_array('https:', $csp->sources('connect-src'), true));
});

test('una página de la tienda lleva la política, y el guion de las cookies que va escrito en ella tiene su huella', function (): void {
    $app = consentApp(['modules' => ['cms']]);
    cmsInsertPage($app, ['title' => 'Quiénes somos']);

    $r = (new TestClient($app))->get('/quienes-somos');

    same(200, $r->status);
    $csp = cspParse($r);
    same(["'self'"], $csp['default-src']);
    same(["'none'"], $csp['object-src']);
    same(["'self'"], $csp['frame-ancestors']);
    same(true, in_array('https://www.youtube-nocookie.com', $csp['frame-src'], true), 'los vídeos incrustados');
    same(false, in_array("'unsafe-inline'", $csp['script-src'], true));
    same(1, cspCovers($r, 'página con aviso de cookies'), 'el valor por defecto de Consent Mode');
    same('SAMEORIGIN', $r->headers['X-Frame-Options'], 'las cabeceras de siempre siguen');
});

test('el guion de los formularios del contenido tiene su huella, y la copia de la caché sale con la misma cabecera', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Contacto', 'blocks' => [['type' => 'form', 'attrs' => ['form_id' => 'contact']]]]);
    $web = new TestClient($app);

    $r = $web->get('/contacto');

    same(200, $r->status);
    contains('__afCmsForms', $r->body);
    same(1, cspCovers($r, 'página con formulario'));
    $request = \Aifec\Kernel\Request::create('GET', '/contacto');
    $request->host = 'tienda.test';
    $request->secure = true;
    $cached = \Aifec\Kernel\PageCache::serve($app->site(), $request, $app->clock());
    check($cached !== null, 'la página está en la caché');
    same($r->headers['Content-Security-Policy'], $cached->headers['Content-Security-Policy'], 'la copia guardada lleva la política');
});

test('el panel lleva la política y su guion del tema claro u oscuro tiene su huella, en la entrada y dentro', function (): void {
    $app = testApp(['modules' => '*']);
    aifecAdmin($app);
    $web = new TestClient($app);

    $login = $web->get('/admin/login');
    same(200, $login->status);
    same(1, cspCovers($login, 'entrada del panel'));

    aifecLogin($web);
    foreach (['/admin', '/admin/orders', '/admin/settings', '/admin/r/users'] as $path) {
        $r = $web->get($path);
        same(200, $r->status, $path);
        same(1, cspCovers($r, $path), $path . ': solo el guion del tema');
    }
});

test('con una etiqueta de medición en el navegador la política se relaja sola; midiendo solo desde el servidor sigue estricta', function (): void {
    $strict = dlApp();
    $r = (new TestClient($strict))->get('/');
    same(false, in_array("'unsafe-inline'", cspParse($r)['script-src'], true), 'sin destinos en el navegador');
    cspCovers($r, 'portada sin etiquetas');

    $tags = dlApp(['settings' => ['datalayer.ga4_id' => 'G-ABCDEF1234']]);
    $r = (new TestClient($tags))->get('/');
    $csp = cspParse($r);
    same(true, in_array("'unsafe-inline'", $csp['script-src'], true), 'con GA4 los guiones no se limitan');
    same(true, in_array('https:', $csp['script-src'], true));
    same(0, count(array_filter($csp['script-src'], static function (string $s): bool {
        return strpos($s, "'sha256-") === 0;
    })), 'sin huellas: con una, el navegador ignoraría unsafe-inline');
    same(["'none'"], $csp['object-src'], 'lo demás sigue en pie');
});

test('los ajustes: apagada no sale la cabecera, relajada se relaja y los orígenes de más se añaden', function (): void {
    $off = cmsApp(['cms'], ['security.csp' => 'off']);
    cmsInsertPage($off, ['title' => 'Uno']);
    $r = (new TestClient($off))->get('/uno');
    same(200, $r->status);
    check(!isset($r->headers['Content-Security-Policy']), 'apagada');

    $more = cmsApp(['cms'], ['security.csp_sources' => "https://cdn.example.com\nhttp://no.example"]);
    cmsInsertPage($more, ['title' => 'Dos']);
    $csp = cspParse((new TestClient($more))->get('/dos'));
    same(true, in_array('https://cdn.example.com', $csp['script-src'], true));
    same(true, in_array('https://cdn.example.com', $csp['connect-src'], true));
    notContains('no.example', implode(' ', $csp['script-src']));

    $relaxed = cmsApp(['cms'], ['security.csp' => 'relaxed']);
    cmsInsertPage($relaxed, ['title' => 'Tres']);
    same(true, in_array("'unsafe-inline'", cspParse((new TestClient($relaxed))->get('/tres'))['script-src'], true));
});

test('una respuesta que no es una página (JSON) no lleva política, y quien ya puso la suya la conserva', function (): void {
    $app = cmsApp();
    $app->router()->get('/prueba.json', static function (): Response {
        return Response::json(['ok' => true]);
    });
    $app->router()->get('/propia', static function (): Response {
        $r = new Response('<p>hola</p>');
        $r->headers['Content-Security-Policy'] = "default-src 'none'";

        return $r;
    });
    $web = new TestClient($app);

    check(!isset($web->get('/prueba.json')->headers['Content-Security-Policy']), 'el JSON no lleva política');
    same("default-src 'none'", $web->get('/propia')->headers['Content-Security-Policy']);
});
