<?php
/**
 * AIFEC — pruebas del consentimiento en la tienda: la cookie de la decisión, el aviso, la caché de página, el bloqueo de guiones de
 * terceros, Consent Mode v2, Global Privacy Control, la versión de la política y el peso del guion.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Modules\Consent\ConsentCookie;
use Aifec\Modules\Consent\ConsentService;

require_once __DIR__ . '/helpers.php';

/** La copia de la caché de página de «/», tal como la serviría el servidor antes de arrancar, con o sin cookies. */
function consentCached(\Aifec\Kernel\App $app, array $cookies = []): ?\Aifec\Kernel\Response
{
    $request = Request::create('GET', '/', [], $cookies);
    $request->host = 'tienda.test';
    $request->secure = true;

    return PageCache::serve($app->site(), $request, $app->clock());
}

function consentService(\Aifec\Kernel\App $app): ConsentService
{
    /** @var ConsentService $service */
    $service = $app->service('consent');

    return $service;
}

test('la cookie de la decisión se escribe con versión, letras, fecha e identificador y se lee igual', function (): void {
    $value = ConsentCookie::build(3, ['marketing', 'statistics', 'statistics'], 1731500000, '9f2c4e1ab7d30586');
    same('3.sm.1731500000.9f2c4e1ab7d30586', $value, 'las letras salen en orden y sin repetir');
    $parsed = ConsentCookie::parse($value, 3);
    check($parsed !== null);
    same(['statistics', 'marketing'], $parsed['categories']);
    same('sm', $parsed['letters']);
    same(1731500000, $parsed['time']);
    same('9f2c4e1ab7d30586', $parsed['id']);
});

test('una decisión de otra versión de la política no cuenta', function (): void {
    same(null, ConsentCookie::parse('2.sm.1731500000.9f2c4e1ab7d30586', 3));
    check(ConsentCookie::parse('3.sm.1731500000.9f2c4e1ab7d30586', 3) !== null);
});

test('una cookie mal formada se descarta entera', function (): void {
    foreach (['', 'basura', '3.sm.1731500000', '3.xyz.1731500000.9f2c4e1ab7d30586', '3.sm.17315.corta', '3.sm.1731500000.9F2C4E1AB7D30586',
        '3.smpp.1731500000.9f2c4e1ab7d30586', '3.sm.1731500000.9f2c4e1ab7d30586.extra', str_repeat('3', 200), '-1.sm.1731500000.9f2c4e1ab7d30586'] as $raw) {
        same(null, ConsentCookie::parse($raw, 3), 'se descarta «' . substr($raw, 0, 30) . '»');
    }
    same(null, ConsentCookie::parse(null, 3));
});

test('rechazar todo es una decisión válida y no permite nada; la categoría necesaria siempre vale', function (): void {
    $none = ConsentCookie::parse('1..1731500000.9f2c4e1ab7d30586', 1);
    check($none !== null);
    same([], $none['categories']);
    same(true, ConsentCookie::allows($none, 'necessary'));
    same(false, ConsentCookie::allows($none, 'statistics'));
    same(true, ConsentCookie::allows(null, 'necessary'), 'sin decisión, la necesaria vale');
    same(false, ConsentCookie::allows(null, 'marketing'), 'sin decisión, nada más vale');
    $some = ConsentCookie::parse('1.p.1731500000.9f2c4e1ab7d30586', 1);
    same(true, ConsentCookie::allows($some, 'preferences'));
    same(false, ConsentCookie::allows($some, 'marketing'));
});

test('las categorías se reconocen con palabras en castellano, tildes y mayúsculas; lo desconocido cuenta como marketing', function (): void {
    same('statistics', ConsentService::normalize('estadísticas'));
    same('statistics', ConsentService::normalize(' Estadisticas '));
    same('preferences', ConsentService::normalize('PREFERENCIAS'));
    same('marketing', ConsentService::normalize('Marketing'));
    same('necessary', ConsentService::normalize('necesarias'));
    same('marketing', ConsentService::normalize('algo-raro'), 'lo que no se reconoce se bloquea como marketing');
    same('marketing', ConsentService::normalize(''));
});

test('un guion con dirección sale como text/plain con su categoría y sin src: el navegador no descarga nada', function (): void {
    $app = consentApp();
    $html = consentService($app)->script('estadísticas', 'https://analiticas.tienda.test/a.js', ['async' => true, 'id' => 'ga']);
    contains('type="text/plain"', $html);
    contains('data-consent="statistics"', $html);
    contains('data-src="https://analiticas.tienda.test/a.js"', $html);
    contains(' async', $html);
    contains('id="ga"', $html);
    notContains(' src=', $html, 'el guion no lleva src: no se descarga');
    $mine = consentService($app)->script('marketing', '/assets/m.js');
    contains('data-src="/assets/m.js"', $mine);
});

test('un guion con código sale como text/plain y no puede cerrar su etiqueta; con un comentario HTML no sale', function (): void {
    $app = consentApp();
    $html = consentService($app)->script('marketing', "window.x = '</script><b>hola</b>';");
    contains('type="text/plain" data-consent="marketing"', $html);
    same(1, substr_count(strtolower($html), '</script'), 'solo el cierre propio');
    contains('<\\/script', $html);
    same('', consentService($app)->script('marketing', 'a(); <!-- <script> b();'), 'un código con «<!--» no se imprime');
});

test('los atributos extra de un guion se limpian: sin on…, sin type, src ni data-consent, y con valores escapados', function (): void {
    $app = consentApp();
    $html = consentService($app)->script('marketing', 'https://t.tienda.test/p.js', [
        'onload' => 'robar()', 'type' => 'module', 'src' => 'https://otra.tienda.test/x.js', 'data-consent' => 'necessary', 'data-id' => 'a"b<c', 'bad name' => '1', 'defer' => true,
    ]);
    notContains('onload', $html);
    notContains('module', $html);
    notContains('otra.tienda.test', $html);
    notContains('bad name', $html);
    contains('data-consent="marketing"', $html);
    contains('data-id="a&quot;b&lt;c"', $html);
    contains(' defer', $html);
});

test('un guion de la categoría necesaria sale normal (no hace falta consentimiento)', function (): void {
    $app = consentApp();
    contains('<script src="https://t.tienda.test/p.js"></script>', consentService($app)->script('necessary', 'https://t.tienda.test/p.js'));
    notContains('text/plain', consentService($app)->script('necessary', 'a();'));
});

test('el ayudante de plantilla consent_script() da lo mismo que el servicio', function (): void {
    $app = consentApp();
    same(consentService($app)->script('statistics', 'https://t.tienda.test/p.js'), consent_script('statistics', 'https://t.tienda.test/p.js'));
    same(consentService($app)->iframe('marketing', 'https://video.tienda.test/e/1'), consent_iframe('marketing', 'https://video.tienda.test/e/1'));
});

test('un marco no lleva src hasta que se acepta su categoría y solo admite http o https', function (): void {
    $app = consentApp();
    $html = consentService($app)->iframe('marketing', 'https://video.tienda.test/e/1?a=1&b=2', ['title' => 'Vídeo & más', 'width' => '560']);
    contains('data-consent="marketing"', $html);
    contains('data-src="https://video.tienda.test/e/1?a=1&amp;b=2"', $html);
    contains('title="Vídeo &amp; más"', $html);
    notContains(' src=', $html);
    same('', consentService($app)->iframe('marketing', 'javascript:alert(1)'));
    same('', consentService($app)->iframe('marketing', 'data:text/html,hola'));
    same('', consentService($app)->iframe('marketing', 'https://x"onload="a()'));
});

test('la tienda enseña el aviso con Aceptar y Rechazar iguales de visibles, Configurar y la segunda capa por categorías', function (): void {
    $app = consentApp();
    $r = (new TestClient($app))->get('/');
    same(200, $r->status);
    $notice = consentNotice($r->body);
    contains('data-af-consent', $notice);
    preg_match('/<button[^>]*data-act="accept"[^>]*>/', $notice, $accept);
    preg_match('/<button[^>]*data-act="reject"[^>]*>/', $notice, $reject);
    check($accept !== [] && $reject !== [], 'hay botón de aceptar y de rechazar en la primera capa');
    same(str_replace('accept', 'X', $accept[0]), str_replace('reject', 'X', $reject[0]), 'mismo aspecto y mismo esfuerzo');
    contains('Rechazar', $notice);
    contains('Aceptar', $notice);
    contains('Configurar', $notice);
    contains('data-cat="p"', $notice);
    contains('data-cat="s"', $notice);
    contains('data-cat="m"', $notice);
    contains('role="dialog" aria-modal="true"', $notice);
    contains('Estadísticas', $notice);
    contains('af_consent_' . $app->site()->cookieSuffix(), $notice);
    notContains('<script src=', $notice, 'el aviso no carga nada de terceros');
});

test('la segunda capa lista las cookies de cada categoría con sus datos', function (): void {
    $app = consentApp();
    $notice = consentNotice((new TestClient($app))->get('/')->body);
    contains('af_k_' . $app->site()->cookieSuffix(), $notice);
    contains('Guarda tu carrito', $notice);
    contains('30 días', $notice);
    contains('Necesarias', $notice);
});

test('el HTML del aviso es idéntico con y sin cookie de decisión: nada de la decisión se pinta en el servidor', function (): void {
    $app = consentApp();
    $anon = (new TestClient($app))->get('/');
    $accepted = consentWeb($app, 'psm')->get('/');
    $rejected = consentWeb($app, '')->get('/');
    same(200, $accepted->status);
    same(consentNotice($anon->body), consentNotice($accepted->body));
    same(consentNotice($anon->body), consentNotice($rejected->body));
    same($anon->body, $accepted->body, 'la página entera es la misma');
    same($anon->body, $rejected->body);
});

test('la página con el aviso sigue siendo cacheable: se guarda, se sirve a quien ya decidió y a quien no, y no pone cookies', function (): void {
    $app = consentApp();
    $first = (new TestClient($app))->get('/');
    same([], $first->cookies, 'el aviso no pone ninguna cookie desde el servidor');
    same(true, $first->cacheTtl > 0, 'la página sigue marcada como cacheable');
    $anon = consentCached($app);
    check($anon !== null, 'la copia está guardada');
    same('HIT', $anon->headers['X-Cache']);
    $decided = consentCached($app, [$app->cookieName('consent') => consentCookie($app, 'sm')]);
    check($decided !== null, 'quien ya decidió recibe la misma copia');
    same($anon->body, $decided->body);
});

test('la cabecera pone Consent Mode v2 en denied antes de cualquier etiqueta, con los siete valores y el guion con defer', function (): void {
    $app = consentApp();
    $html = (new TestClient($app))->get('/')->body;
    $head = (string) substr($html, 0, (int) strpos($html, '</head>'));
    contains("gtag(\"consent\",\"default\",{", $head);
    foreach (['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage', 'functionality_storage', 'personalization_storage', 'security_storage'] as $key) {
        contains($key . ':', $head);
    }
    contains('security_storage:"granted"', $head);
    contains('wait_for_update', $head);
    contains('globalPrivacyControl', $head);
    $default = (int) strpos($head, 'gtag("consent","default"');
    $script = (int) strpos($head, 'consent.js');
    check($default > 0 && $script > $default, 'el valor por defecto va antes que el guion');
    contains('consent.js" defer', $head);
    contains('"' . $app->cookieName('consent') . '"', $head);
});

test('el valor por defecto de la cabecera no cambia según quien visita (mismo HTML para todos)', function (): void {
    $app = consentApp();
    $a = (new TestClient($app))->get('/')->body;
    $b = consentWeb($app, 'psm')->get('/', ['sec-gpc' => '1'])->body;
    same($a, $b, 'ni la cookie ni la cabecera de Global Privacy Control cambian el HTML');
});

test('al cambiar la versión de la política el HTML lleva la nueva y la caché de página se vacía', function (): void {
    $app = consentApp();
    (new TestClient($app))->get('/');
    check(consentCached($app) !== null, 'la copia existe antes');
    $app->settings()->set('consent.version', 2);
    $app->hooks()->fire('settings.saved', 'consent', ['consent.version'], $app);
    same(null, consentCached($app), 'al guardar los ajustes se vacían las páginas guardadas');
    $notice = consentNotice((new TestClient($app))->get('/')->body);
    contains('data-version="2"', $notice);
    contains(',2);', (new TestClient($app))->get('/')->body, 'la cabecera también lleva la versión');
    $old = consentService($app)->decision(Request::create('GET', '/', [], [$app->cookieName('consent') => consentCookie($app, 'sm', 1)]));
    same(null, $old, 'una decisión de la versión 1 ya no vale');
    check(consentService($app)->decision(Request::create('GET', '/', [], [$app->cookieName('consent') => consentCookie($app, 'sm', 2)])) !== null);
});

test('con el aviso apagado no sale ni el aviso ni la cabecera', function (): void {
    $app = consentApp(['settings' => ['consent.enabled' => false]]);
    $html = (new TestClient($app))->get('/')->body;
    notContains('data-af-consent', $html);
    notContains('consent.js', $html);
    notContains('gtag("consent"', $html);
});

test('los textos y el enlace de la política vienen de los ajustes y salen escapados; un enlace peligroso no sale', function (): void {
    $app = consentApp(['settings' => ['consent.title' => 'Galletas & <b>más</b>', 'consent.message' => 'Usamos "cookies" & <i>cosas</i>', 'consent.policy_url' => '/politica-de-cookies']]);
    $notice = consentNotice((new TestClient($app))->get('/')->body);
    contains('Galletas &amp; &lt;b&gt;más&lt;/b&gt;', $notice);
    contains('Usamos &quot;cookies&quot; &amp; &lt;i&gt;cosas&lt;/i&gt;', $notice);
    contains('href="/politica-de-cookies"', $notice);
    foreach (['javascript:alert(1)', '//otra.tienda.test/x', 'data:text/html,x', 'politica'] as $bad) {
        $app->settings()->set('consent.policy_url', $bad);
        notContains('Política de cookies', consentNotice((new TestClient($app))->get('/')->body), 'no sale el enlace «' . $bad . '»');
    }
});

test('el servidor lee la decisión de la cookie: sin decisión solo la necesaria, con decisión lo concedido', function (): void {
    $app = consentApp();
    $service = consentService($app);
    $none = Request::create('GET', '/');
    same(true, $service->allows('necessary', $none));
    same(false, $service->allows('statistics', $none));
    $accepted = Request::create('GET', '/', [], [$app->cookieName('consent') => consentCookie($app, 'sm')]);
    same(true, $service->allows('statistics', $accepted));
    same(true, $service->allows('estadísticas', $accepted), 'también con la palabra en castellano');
    same(true, $service->allows('marketing', $accepted));
    same(false, $service->allows('preferences', $accepted));
});

test('Global Privacy Control deniega el marketing sin preguntar aunque la cookie lo conceda, y no toca las demás', function (): void {
    $app = consentApp();
    $service = consentService($app);
    $request = Request::create('GET', '/', [], [$app->cookieName('consent') => consentCookie($app, 'psm')], ['sec-gpc' => '1']);
    same(false, $service->allows('marketing', $request));
    same(true, $service->allows('statistics', $request));
    same(true, $service->allows('preferences', $request));
    $plain = Request::create('GET', '/', [], [$app->cookieName('consent') => consentCookie($app, 'psm')]);
    same(true, $service->allows('marketing', $plain));
});

test('el guion consent.js pesa 6 KB o menos, no trae cadenas visibles y entiende la señal de Global Privacy Control', function (): void {
    $file = dirname(__DIR__) . '/assets/consent.js';
    $js = (string) file_get_contents($file);
    check(strlen($js) <= 6144, 'consent.js pesa ' . strlen($js) . ' bytes (tope 6144)');
    check($js !== '' && strpos($js, 'globalPrivacyControl') !== false, 'respeta Global Privacy Control');
    foreach (['Accept', 'Reject', 'Aceptar', 'Rechazar', 'Configure', 'cookies'] as $word) {
        check(preg_match('/[\'"][^\'"]*' . $word . '[^\'"]*[\'"]/', $js) !== 1, 'sin cadenas visibles con «' . $word . '»');
    }
    foreach (['http://', 'https://', 'import ', 'require('] as $bad) {
        notContains($bad, $js);
    }
    contains("'consent', 'update'", $js);
    contains('aifec:consent', $js);
});

test('el guion activa por categoría: copia los atributos, usa data-src y recarga si se retira un permiso', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/consent.js');
    contains('data-src', $js);
    contains('data-consent', $js);
    contains('createElement(\'script\')', $js);
    contains('reload()', $js);
    contains('Escape', $js);
});

test('la altura del aviso que se publica para los demás va siempre con unidad: un «0» a secas rompe el calc() de quien la usa', function (): void {
    // El botón del asistente y los avisos de la tienda se colocan en móvil con «bottom: calc(… + var(--af-consent-h, 0px))».
    // Un «0» sin unidad dentro de ese calc() invalida la declaración entera: al cerrar el aviso de cookies, el botón del
    // asistente desaparecía de la pantalla hasta la página siguiente (13/11/2026, visto en la demo de calzado en móvil).
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/consent.js');
    check(preg_match("/setProperty\\('--af-consent-h',([^;]+)\\);/", $js, $m) === 1, 'el guion publica la altura del aviso');
    contains("'px'", $m[1], 'con el aviso abierto, en píxeles');
    contains("'0px'", $m[1], 'con el aviso cerrado, cero píxeles');
    same(0, preg_match("/:\\s*'0'\\s*$/", trim($m[1])), 'nunca un cero sin unidad');
});
