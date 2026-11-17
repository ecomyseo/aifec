<?php
/**
 * AIFEC — pruebas de las páginas de contenido en la tienda: la página con el tema (título, contenido, canónica, robots, plantillas,
 * migas, idiomas), su caché de página, la portada, un bloque de productos, un formulario dentro de una página y los enlaces legales
 * del pie.
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

require_once __DIR__ . '/helpers.php';

/** La copia de la caché de página de una dirección, tal como la serviría el servidor antes de arrancar la aplicación. */
function cmsFrontPageServe(App $app, string $uri): ?Response
{
    $request = Request::create('GET', $uri);
    $request->host = 'tienda.test';
    $request->secure = true;

    return PageCache::serve($app->site(), $request, $app->clock());
}

/**
 * Guarda el menú «legal» con una página, por el panel y como lo manda el navegador.
 */
function cmsFrontPageLegalMenu(TestClient $web, int $pageId): void
{
    $web->get('/admin/cms/menus/legal');
    $web->post('/admin/cms/menus/legal', ['_token' => aifecToken($web), 'items' => [
        ['type' => 'page', 'ref' => (string) $pageId, 'level' => '0', 'blank' => '0', 'labels' => ['es' => '', 'en' => '']],
    ]]);
}

test('una página publicada se ve con el tema: un solo h1, su contenido, su canónica y sus datos de cabecera', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Quiénes somos', 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Somos una tienda pequeña & familiar.']]]]);
    $web = new TestClient($app);
    $r = $web->get('/quienes-somos');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'), 'un solo h1');
    contains('<h1 class="af-title">Quiénes somos</h1>', $r->body);
    contains('Somos una tienda pequeña &amp; familiar.', $r->body);
    contains('<link rel="canonical" href="https://tienda.test/quienes-somos">', $r->body);
    contains('<title>Quiénes somos · ', $r->body);
    contains('index, follow', $r->body);
    contains('af-type-page', $r->body, 'el tipo de página llega al envoltorio del tema');
    same(600, $r->cacheTtl);
    check(in_array('page:' . $id, $r->cacheTags, true) && in_array('cms', $r->cacheTags, true), 'se invalida con su etiqueta y con «cms»');
    notContains('af-breadcrumbs', $r->body, 'sin página madre no hay migas');
});

test('la caché de página guarda la página: copia a la segunda y vuelve a generarse tras guardarla en el panel', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Quiénes somos']);
    $web = new TestClient($app);
    same(null, cmsFrontPageServe($app, '/quienes-somos'), 'antes de la primera visita no hay copia');
    $first = $web->get('/quienes-somos');
    same('MISS', $first->headers['X-Cache']);
    $copy = cmsFrontPageServe($app, '/quienes-somos');
    check($copy !== null, 'la segunda se sirve de la caché');
    same('HIT', $copy->headers['X-Cache']);
    contains('Texto de Quiénes somos', $copy->body);

    $admin = adminClient($app);
    $saved = cmsSavePage($admin, ['title' => ['es' => 'Quiénes somos', 'en' => ''], 'blocks' => ['es' => cmsBlocks('Texto nuevo'), 'en' => ''], 'status' => 'published'], $id);
    same(302, $saved->status);
    same(null, cmsFrontPageServe($app, '/quienes-somos'), 'guardarla en el panel vacía su copia');
    $again = $web->get('/quienes-somos');
    same('MISS', $again->headers['X-Cache']);
    contains('Texto nuevo', $again->body);
});

test('un borrador, una página programada y una que no existe dan 404; la programada se ve cuando llega su hora', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Borrador secreto', 'status' => 'draft']);
    cmsInsertPage($app, ['title' => 'Para más tarde', 'status' => 'scheduled', 'published_at' => '2026-11-12 14:00:00']);
    $web = new TestClient($app);
    same(404, $web->get('/borrador-secreto')->status);
    same(404, $web->get('/para-mas-tarde')->status, 'programada para dentro de dos horas');
    same(404, $web->get('/no-existe')->status);
    $app->clock()->advance(3 * 3600);
    same(200, $web->get('/para-mas-tarde')->status, 'ya es su hora');
});

test('una página marcada como «no indexar» lo dice en sus datos de cabecera', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Interna']);
    $app->db()->update('pages', ['noindex' => 1], ['id' => $id]);
    $app->invalidate(['cms']);
    $r = (new TestClient($app))->get('/interna');
    same(200, $r->status);
    contains('<meta name="robots" content="noindex, follow">', $r->body);
    notContains('index, follow, max-image-preview', $r->body);
});

test('las plantillas de página: «landing» sin título visible ni migas, «wide» sin límite de ancho y «default» con título', function (): void {
    $app = cmsApp();
    $madre = cmsInsertPage($app, ['title' => 'Madre']);
    cmsInsertPage($app, ['title' => 'Promoción', 'template' => 'landing', 'parent_id' => $madre]);
    cmsInsertPage($app, ['title' => 'Ancha', 'template' => 'wide']);
    cmsInsertPage($app, ['title' => 'Normal']);
    $web = new TestClient($app);

    $landing = $web->get('/promocion')->body;
    notContains('af-title', $landing, 'la plantilla landing no pinta el título');
    notContains('af-breadcrumbs', $landing, 'ni las migas, aunque tenga madre');
    same(1, substr_count($landing, '<h1'), 'pero sigue habiendo un solo h1 (para lectores de pantalla)');
    contains('<h1 class="af-sr">Promoción</h1>', $landing);
    contains('af-page-landing', $landing);
    contains('Texto de Promoción', $landing);

    $wide = $web->get('/ancha')->body;
    contains('af-prose af-prose-wide', $wide);
    contains('max-width:none', $wide);
    contains('<h1 class="af-title">Ancha</h1>', $wide);

    $default = $web->get('/normal')->body;
    notContains('max-width:none', $default, 'la plantilla por defecto conserva el ancho de lectura');
    contains('<h1 class="af-title">Normal</h1>', $default);
});

test('una landing con su propio h1 en el contenido no añade otro', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Gran oferta', 'template' => 'landing', 'blocks' => [['type' => 'heading', 'attrs' => ['level' => 1, 'html' => 'Todo al 30 %']]]]);
    $body = (new TestClient($app))->get('/gran-oferta')->body;
    same(1, substr_count($body, '<h1'));
    notContains('af-sr">Gran oferta', $body);
});

test('una página con página madre enseña las migas: portada, madres y ella misma sin enlace', function (): void {
    $app = cmsApp();
    $abuela = cmsInsertPage($app, ['title' => 'Empresa']);
    $madre = cmsInsertPage($app, ['title' => 'Equipo', 'parent_id' => $abuela]);
    cmsInsertPage($app, ['title' => 'Dirección', 'parent_id' => $madre, 'slug' => 'empresa/equipo/direccion']);
    $body = (new TestClient($app))->get('/empresa/equipo/direccion')->body;
    contains('<nav class="af-breadcrumbs"', $body);
    preg_match('#<nav class="af-breadcrumbs".*?</nav>#s', $body, $m);
    $nav = isset($m[0]) ? $m[0] : '';
    check(strpos($nav, 'Inicio') < strpos($nav, 'Empresa') && strpos($nav, 'Empresa') < strpos($nav, 'Equipo') && strpos($nav, 'Equipo') < strpos($nav, 'Dirección'), 'en orden: ' . $nav);
    contains('<li aria-current="page">Dirección</li>', $nav, 'la actual, sin enlace');
    contains('<a href="/empresa">Empresa</a>', $nav);
});

test('la traducción de una página se ve en /en/… con su título; sin el módulo seo no hay alternativas de idioma', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Quiénes somos', 'en' => 'About us']);
    $web = new TestClient($app);
    $en = $web->get('/en/about-us');
    same(200, $en->status);
    contains('<html lang="en"', $en->body);
    contains('<h1 class="af-title">About us</h1>', $en->body);
    contains('<link rel="canonical" href="https://tienda.test/en/about-us">', $en->body);
    contains('Texto de Quiénes somos', $en->body, 'el contenido sin traducir cae en el idioma principal');
    check(!$app->has('seo'), 'este sitio de prueba no tiene seo');
    notContains('<link rel="alternate" hreflang', $en->body, 'las alternativas de idioma las pone seo, no este módulo');
    contains('<h1 class="af-title">Quiénes somos</h1>', $web->get('/quienes-somos')->body);
});

test('la portada enseña la página marcada como portada y nada si no hay ninguna o no se ve', function (): void {
    $app = cmsApp();
    $web = new TestClient($app);
    notContains('data-section="cms.home"', $web->get('/')->body, 'sin página de portada no hay sección');

    $id = cmsInsertPage($app, ['title' => 'Bienvenida', 'status' => 'draft', 'is_home' => 1, 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Hola desde la portada']]]]);
    notContains('Hola desde la portada', $web->get('/')->body, 'un borrador no sale en la portada');

    $app->db()->update('pages', ['status' => 'published'], ['id' => $id]);
    $app->invalidate(['cms', 'page:' . $id]);
    $home = $web->get('/');
    same(200, $home->status);
    contains('data-section="cms.home"', $home->body);
    contains('Hola desde la portada', $home->body);
    same(1, substr_count($home->body, '<h1'), 'la portada sigue con un solo h1');
    check(in_array('cms', $home->cacheTags, true), 'la portada se renueva al guardar contenidos');
    contains('<link rel="canonical" href="https://tienda.test/">', $web->get('/bienvenida')->body, 'su propia dirección apunta a la portada como la buena');
});

test('la sección de la portada va con el orden 10: detrás de lo que pide ir antes y delante de lo que pide ir después', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Bienvenida', 'is_home' => 1, 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'MIDDLE-CMS']]]]);
    $app->hooks()->filter('home.sections', static function ($sections): array {
        $sections = is_array($sections) ? $sections : [];
        $sections[] = ['id' => 'test.before', 'order' => 5, 'html' => '<p>FIRST-TEST</p>'];
        $sections[] = ['id' => 'test.after', 'order' => 20, 'html' => '<p>LAST-TEST</p>'];

        return $sections;
    });
    $body = (new TestClient($app))->get('/')->body;
    $a = strpos($body, 'FIRST-TEST');
    $b = strpos($body, 'MIDDLE-CMS');
    $c = strpos($body, 'LAST-TEST');
    check($a !== false && $b !== false && $c !== false && $a < $b && $b < $c, 'orden de las secciones');
});

test('un bloque de productos dentro de una página pinta tarjetas si existe el catálogo y nada si no existe', function (): void {
    $blocks = [['type' => 'paragraph', 'attrs' => ['html' => 'Nuestros favoritos']], ['type' => 'products', 'attrs' => ['mode' => 'latest', 'limit' => 4]]];

    $app = cmsApp(['cms', 'catalog']);
    aifecProduct($app, ['name' => 'Mesa de roble']);
    cmsInsertPage($app, ['title' => 'Favoritos', 'blocks' => $blocks]);
    $r = (new TestClient($app))->get('/favoritos');
    same(200, $r->status);
    contains('af-product-card', $r->body);
    contains('Mesa de roble', $r->body);
    check(in_array('catalog', $r->cacheTags, true), 'la página se renueva cuando cambia el catálogo');

    $without = cmsApp(['cms']);
    cmsInsertPage($without, ['title' => 'Favoritos', 'blocks' => $blocks]);
    $r = (new TestClient($without))->get('/favoritos');
    same(200, $r->status);
    contains('Nuestros favoritos', $r->body);
    notContains('af-product-card', $r->body, 'sin catálogo, el bloque no pinta nada ni deja huecos');
});

test('una página con un formulario se guarda en la caché de página y su HTML no lleva nada personal', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Contacto', 'blocks' => [['type' => 'form', 'attrs' => ['form_id' => 'contact']]]]);
    $web = new TestClient($app);
    $r = $web->get('/contacto');
    same(200, $r->status);
    contains('data-af-form="contact"', $r->body);
    contains('action="/formulario/contact"', $r->body);
    contains('name="_mark" value=""', $r->body, 'la marca de tiempo no va en el HTML guardado');
    contains('name="website"', $r->body, 'el campo trampa');
    same('MISS', $r->headers['X-Cache']);
    check(cmsFrontPageServe($app, '/contacto') !== null, 'el formulario no impide guardarla en la caché');
    same([], $r->cookies, 'sin cookies: es la misma página para todo el mundo');

    contains('/marca', $r->body, 'el guion que pide la marca al enfocar el primer campo');
    same(1, substr_count($r->body, '<script>'), 'el guion va una sola vez');
});

test('una página sin formulario no lleva el guion de formularios', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Quiénes somos']);
    notContains('__afCmsForms', (new TestClient($app))->get('/quienes-somos')->body);
});

test('una página con un script en su contenido sale sin él: los bloques se saneaban al guardar y al pintar', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Peligro', 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Hola<script>alert(1)</script> <b onclick="x()">mundo</b>']]]]);
    $body = (new TestClient($app))->get('/peligro')->body;
    notContains('alert(1)', $body);
    notContains('onclick', $body);
    contains('mundo', $body);
});

test('las páginas legales del menú «legal» salen en el pie del tema, también en una página de contenido', function (): void {
    $app = cmsApp();
    $legal = cmsInsertPage($app, ['title' => 'Aviso legal']);
    cmsInsertPage($app, ['title' => 'Quiénes somos']);
    $admin = adminClient($app);
    cmsFrontPageLegalMenu($admin, $legal);

    $web = new TestClient($app);
    foreach (['/', '/quienes-somos'] as $uri) {
        $body = $web->get($uri)->body;
        same(1, preg_match('#<nav class="af-footer-legal".*?</nav>#s', $body, $m), 'el pie tiene su navegación legal en ' . $uri);
        contains('href="/aviso-legal"', $m[0]);
        contains('Aviso legal', $m[0]);
    }
    same(200, $web->get('/aviso-legal')->status, 'y el enlace lleva a una página que existe');
});

test('un dato con «&» y con «<» en el título de una página sale escapado en el título, la cabecera y las migas', function (): void {
    $app = cmsApp();
    $madre = cmsInsertPage($app, ['title' => 'Cocina & baño']);
    cmsInsertPage($app, ['title' => 'Mesas <b>nuevas</b>', 'parent_id' => $madre, 'slug' => 'mesas-nuevas', 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Con patas']]]]);
    $body = (new TestClient($app))->get('/mesas-nuevas')->body;
    contains('<h1 class="af-title">Mesas &lt;b&gt;nuevas&lt;/b&gt;</h1>', $body);
    contains('<li aria-current="page">Mesas &lt;b&gt;nuevas&lt;/b&gt;</li>', $body, 'también en las migas');
    notContains('<b>nuevas</b>', $body);
    contains('Cocina &amp; baño', $body);
});
