<?php
/**
 * AIFEC — pruebas de la cabecera de la capa de datos: los destinos esperan su consentimiento, ningún guion de terceros se ejecuta
 * antes, los identificadores se comprueban, la caché de página no se rompe y el equipo con sesión no se mide.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;

require_once __DIR__ . '/helpers.php';

/** Los ajustes de todos los destinos, con identificadores de mentira pero con la forma de los de verdad. */
function dlAllDestinations(): array
{
    return [
        'datalayer.gtm_id' => 'GTM-ABC1234', 'datalayer.ga4_id' => 'G-ABCDE12345', 'datalayer.ads_id' => 'AW-123456789', 'datalayer.ads_label' => 'xYz_abc123',
        'datalayer.meta_pixel' => '123456789012345', 'datalayer.tiktok_pixel' => 'C1ABCDEF2GHIJ', 'datalayer.uet_id' => '12345678',
    ];
}

/** El HTML de la cabecera de la portada. */
function dlHead(string $html): string
{
    return (string) substr($html, 0, (int) strpos($html, '</head>'));
}

test('sin ningún destino la cabecera solo lleva la configuración y el guion con defer, sin nada de terceros', function (): void {
    $app = dlApp();
    $head = dlHead((new TestClient($app))->get('/')->body);
    contains('<script type="application/json" id="af-dl-config">', $head);
    contains('datalayer.js" defer', $head);
    foreach (['googletagmanager', 'facebook', 'tiktok', 'bat.bing', 'fbq(', 'gtag(\'config\''] as $third) {
        notContains($third, $head);
    }
    $config = json_decode((string) preg_replace('#^.*<script type="application/json" id="af-dl-config">(.*?)</script>.*$#s', '$1', $head), true);
    same(1, $config['v']);
    same(0, $config['off']);
    same('EUR', $config['cur']);
    same('ChatGPT', $config['ai']['chatgpt.com']);
});

test('cada destino sale como text/plain con su categoría: el navegador no ejecuta ni descarga nada hasta el consentimiento', function (): void {
    $app = dlApp(['settings' => dlAllDestinations()]);
    $head = dlHead((new TestClient($app))->get('/')->body);
    foreach (['GTM-ABC1234', 'G-ABCDE12345', 'AW-123456789', '123456789012345', 'C1ABCDEF2GHIJ', '12345678'] as $id) {
        contains($id, $head, 'sale el identificador ' . $id);
    }
    preg_match_all('#<script([^>]*)>#', $head, $tags);
    foreach ($tags[1] as $attrs) {
        if (strpos($attrs, 'application/json') !== false || strpos($attrs, 'datalayer.js') !== false || strpos($attrs, 'consent.js') !== false || strpos($attrs, '/assets/') !== false || trim($attrs) === '') {
            continue;
        }
        contains('type="text/plain"', $attrs, 'el guion «' . $attrs . '» espera su categoría');
        contains('data-consent="', $attrs);
        notContains(' src=', $attrs, 'y no descarga nada');
    }
});

test('ningún guion de terceros se ejecuta antes del consentimiento: fuera de los text/plain no hay ni una etiqueta de medición', function (): void {
    $app = dlApp(['settings' => dlAllDestinations()]);
    $html = (new TestClient($app))->get('/')->body;
    $live = (string) preg_replace('#<script type="text/plain"[^>]*>.*?</script>#s', '', $html);
    foreach (['fbq(', 'ttq.load', 'uetq', 'googletagmanager.com', 'connect.facebook.net', 'analytics.tiktok.com', 'bat.bing.com', 'gtm.start'] as $tag) {
        notContains($tag, $live, 'sin consentimiento no se ejecuta «' . $tag . '»');
    }
    contains('connect.facebook.net', $html, 'pero la etiqueta existe, esperando');
});

test('la categoría de cada destino se puede cambiar en los ajustes y se respeta', function (): void {
    $app = dlApp(['settings' => dlAllDestinations() + ['datalayer.meta_category' => 'estadísticas', 'datalayer.gtm_category' => 'marketing']]);
    $head = dlHead((new TestClient($app))->get('/')->body);
    preg_match('#<script type="text/plain" data-consent="([a-z]+)">!function\(f,b,e,v,n,t,s\)#', $head, $meta);
    same('statistics', $meta[1], 'Meta con la categoría elegida');
    preg_match('#<script type="text/plain" data-consent="([a-z]+)">\(function\(w,d,s,l,i\)#', $head, $gtm);
    same('marketing', $gtm[1]);
    preg_match('#<script type="text/plain" data-consent="([a-z]+)">\(function\(w,d,t,r,u\)#', $head, $uet);
    same('marketing', $uet[1], 'sin tocar, Microsoft sigue en marketing');
});

test('un identificador que no tiene la forma de los de verdad se ignora y no llega al JavaScript', function (): void {
    $app = dlApp(['settings' => [
        'datalayer.gtm_id' => "GTM-AB1</script><script>alert(1)", 'datalayer.ga4_id' => 'G-<b>', 'datalayer.meta_pixel' => '12ab', 'datalayer.tiktok_pixel' => "x');alert(1);('",
        'datalayer.uet_id' => '12', 'datalayer.ads_id' => 'AW-1', 'datalayer.ads_label' => 'a b',
    ]]);
    $html = (new TestClient($app))->get('/')->body;
    notContains('alert(1)', $html);
    notContains('googletagmanager', $html);
    notContains('fbq(', $html);
    notContains('ttq.load', $html);
    notContains('uetq', $html);
    $config = json_decode((string) preg_replace('#^.*<script type="application/json" id="af-dl-config">(.*?)</script>.*$#s', '$1', dlHead($html)), true);
    check(!isset($config['gtm']) && !isset($config['meta']) && !isset($config['tt']) && !isset($config['uet']) && !isset($config['ads']), 'ningún destino queda activo');
});

test('Google Analytics y Google Ads de la misma categoría comparten una sola descarga de gtag.js', function (): void {
    $app = dlApp(['settings' => ['datalayer.ga4_id' => 'G-ABCDE12345', 'datalayer.ads_id' => 'AW-123456789', 'datalayer.ads_label' => 'xYz_abc123', 'datalayer.ads_category' => 'statistics']]);
    $head = dlHead((new TestClient($app))->get('/')->body);
    same(1, substr_count($head, 'googletagmanager.com/gtag/js'), 'una sola descarga');
    contains("gtag('config','G-ABCDE12345')", $head);
    contains("gtag('config','AW-123456789')", $head);
});

test('el código propio de cada categoría sale esperando esa categoría y sin cerrar la etiqueta', function (): void {
    $app = dlApp(['settings' => ['datalayer.custom_statistics' => "window.x='</script><b>';", 'datalayer.custom_marketing' => 'window.y = 2;']]);
    $head = dlHead((new TestClient($app))->get('/')->body);
    contains('<script type="text/plain" data-consent="statistics">window.x=', $head);
    contains('<script type="text/plain" data-consent="marketing">window.y = 2;</script>', $head);
    notContains("'</script><b>'", $head);
});

test('la cabecera y los datos de la ficha son iguales para todos los visitantes y la página sigue en la caché', function (): void {
    $app = dlApp(['settings' => dlAllDestinations()]);
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 2000]);
    $url = (string) $app->service('catalog')->product($p)['url'];
    $anon = (new TestClient($app))->get($url);
    same(200, $anon->status);
    same([], $anon->cookies, 'la capa de datos no pone cookies desde el servidor');
    same(true, $anon->cacheTtl > 0, 'la ficha sigue siendo cacheable');
    $other = dlWeb($app, 'sm')->get($url, ['sec-gpc' => '1']);
    same($anon->body, $other->body, 'ni la decisión de cookies ni Global Privacy Control cambian el HTML');
    $request = Request::create('GET', $url);
    $request->host = 'tienda.test';
    $request->secure = true;
    $hit = PageCache::serve($app->site(), $request, $app->clock());
    check($hit !== null, 'la copia está en la caché de página');
    same('HIT', $hit->headers['X-Cache']);
});

test('con el módulo apagado no sale nada de la capa de datos', function (): void {
    $app = dlApp(['settings' => dlAllDestinations() + ['datalayer.enabled' => false]]);
    $p = aifecProduct($app, ['name' => 'Silla']);
    $html = (new TestClient($app))->get((string) $app->service('catalog')->product($p)['url'])->body;
    notContains('af-dl-config', $html);
    notContains('data-af-dl', $html);
    notContains('datalayer.js', $html);
    same([], dlEvents($html));
});

test('el equipo con el panel abierto no se mide, y solo él puede usar ?dl_debug=1', function (): void {
    $app = dlApp(['settings' => dlAllDestinations()]);
    $head = dlHead((new TestClient($app))->get('/?dl_debug=1')->body);
    notContains('"dbg"', $head, 'un visitante cualquiera no tiene depuración');
    $staff = adminClient($app);
    $page = $staff->get('/?dl_debug=1')->body;
    $config = json_decode((string) preg_replace('#^.*<script type="application/json" id="af-dl-config">(.*?)</script>.*$#s', '$1', dlHead($page)), true);
    same(1, $config['off'], 'el equipo queda fuera de la medición');
    same(1, $config['dbg']);
    notContains('connect.facebook.net', $page, 'y no se carga ninguna etiqueta');
    $app->settings()->set('datalayer.exclude_staff', false);
    $config = json_decode((string) preg_replace('#^.*<script type="application/json" id="af-dl-config">(.*?)</script>.*$#s', '$1', dlHead($staff->get('/')->body)), true);
    same(0, $config['off'], 'con la opción apagada el equipo se mide como los demás');
});

test('las cookies de los destinos encendidos salen en el inventario del consentimiento con su categoría', function (): void {
    $app = dlApp(['settings' => dlAllDestinations()]);
    $consent = $app->service('consent');
    $names = array_column($consent->cookies(), 'name');
    foreach (['_ga', '_gcl_au', '_fbp', '_ttp', '_uetvid', $app->cookieName('dl')] as $cookie) {
        check(in_array($cookie, $names, true), 'declarada ' . $cookie);
    }
    $marketing = array_column($consent->cookiesOf('marketing'), 'name');
    check(in_array('_fbp', $marketing, true), '_fbp es de marketing');
    check(in_array('_ga', array_column($consent->cookiesOf('statistics'), 'name'), true), '_ga es de estadísticas');
    $off = dlApp();
    check(!in_array('_fbp', array_column($off->service('consent')->cookies(), 'name'), true), 'sin Meta no se declara su cookie');
});

test('al cambiar los ajustes de medición se vacía la caché de página', function (): void {
    $app = dlApp();
    (new TestClient($app))->get('/');
    $request = Request::create('GET', '/');
    $request->host = 'tienda.test';
    $request->secure = true;
    check(PageCache::serve($app->site(), $request, $app->clock()) !== null, 'la copia existe');
    $app->hooks()->fire('settings.saved', 'datalayer', ['datalayer.gtm_id'], $app);
    same(null, PageCache::serve($app->site(), $request, $app->clock()), 'cambiar la medición vacía las páginas guardadas');
});
