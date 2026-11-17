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
use Aifec\Modules\Cms\BlockCache;
use Aifec\Modules\Cms\Cms;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../modules/catalog/tests/helpers.php';
require_once __DIR__ . '/../../modules/cms/tests/helpers.php';

/** Tienda con contenido y catálogo, con dos productos: [app, idA, idB]. */
function blocksShop(): array
{
    $app = cmsApp(['cms', 'catalog'], [], false);
    $a = aifecProduct($app, ['name' => 'Silla Ñandú & Cía', 'price' => 4999]);
    $b = aifecProduct($app, ['name' => 'Mesa "Roble"', 'price' => 12000]);

    return [$app, $a, $b];
}

/**
 * Unos bloques con de todo: títulos repetidos con índice, columnas, productos sueltos y en lista, uno que no existe.
 *
 * @return array<int,array<string,mixed>>
 */
function blocksSample(int $a, int $b): array
{
    return [
        ['type' => 'toc', 'attrs' => ['max_level' => 3]],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'html' => 'Nuestras sillas']],
        ['type' => 'paragraph', 'attrs' => ['html' => 'Texto con <strong>negrita</strong> & tildes: canción, ñu.']],
        ['type' => 'columns', 'children' => [
            ['type' => 'column', 'children' => [['type' => 'product', 'attrs' => ['id' => $a]]]],
            ['type' => 'column', 'children' => [['type' => 'paragraph', 'attrs' => ['html' => 'Al lado 🙂']]]],
        ]],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'html' => 'Nuestras sillas']],
        ['type' => 'product', 'attrs' => ['id' => 987654]],
        ['type' => 'product', 'attrs' => ['id' => $b]],
        ['type' => 'products', 'attrs' => ['mode' => 'manual', 'ids' => [$a, $b], 'limit' => 4, 'columns' => 2]],
        ['type' => 'paragraph', 'attrs' => ['html' => 'Fin.']],
    ];
}

function blocksCms(App $app): Cms
{
    $app->boot();
    $cms = $app->service('cms');
    check($cms instanceof Cms, 'el servicio cms');

    return $cms;
}

test('el HTML de una página de bloques es el mismo con la caché de bloques que pintándolo todo de nuevo', function (): void {
    [$app, $a, $b] = blocksShop();
    $blocks = blocksSample($a, $b);
    $cms = blocksCms($app);
    $fresh = $cms->renderFresh($blocks);
    contains('Silla Ñandú &amp; Cía', $fresh, 'la referencia pinta los productos');
    same($fresh, $cms->render($blocks), 'primera vez (esqueleto nuevo)');
    same($fresh, $cms->render($blocks), 'segunda vez (de la caché)');
    $next = blocksCms(perfNext($app));
    same($fresh, $next->render($blocks), 'otra petición');
    same([], $cms->renderFresh([]) === '' ? [] : ['no vacío'], 'sin bloques, nada');
    same('', $next->render([]));
    same($cms->renderFresh([['type' => 'product', 'attrs' => ['id' => 987654]]]), $next->render([['type' => 'product', 'attrs' => ['id' => 987654]]]), 'un bloque que no pinta nada no deja su envoltorio');
});

test('la segunda visita a una página de bloques no vuelve a pintar ninguno', function (): void {
    [$app, $a, $b] = blocksShop();
    $blocks = blocksSample($a, $b);
    $first = blocksCms($app)->blockCache();
    blocksCms($app)->render($blocks);
    check($first->painted() > 0 && $first->skeletons() === 1, 'la primera vez se pinta');
    $next = blocksCms(perfNext($app));
    $html = $next->render($blocks);
    same(0, $next->blockCache()->painted(), 'ningún bloque repintado');
    same(0, $next->blockCache()->skeletons(), 'ni el esqueleto');
    contains('Mesa &quot;Roble&quot;', $html);
});

test('cambiar un producto invalida solo el bloque que lo enseña', function (): void {
    [$app, $a, $b] = blocksShop();
    $blocks = [
        ['type' => 'paragraph', 'attrs' => ['html' => 'Intro']],
        ['type' => 'product', 'attrs' => ['id' => $a]],
        ['type' => 'product', 'attrs' => ['id' => $b]],
    ];
    blocksCms($app)->render($blocks);
    $app->db()->update('product_i18n', ['name' => 'Silla renovada'], ['product_id' => $a]);
    $app->invalidate(['product:' . $a]);
    $next = blocksCms(perfNext($app));
    $html = $next->render($blocks);
    same(1, $next->blockCache()->painted(), 'solo se repinta el bloque del producto cambiado');
    same(0, $next->blockCache()->skeletons(), 'el resto de la página sale de la caché');
    contains('Silla renovada', $html);
    contains('Mesa &quot;Roble&quot;', $html);
    same(['cms-blocks', 'catalog', 'settings', 'product:' . $a], BlockCache::tags('product', ['id' => $a]));
    same(['cms-blocks', 'catalog', 'settings', 'product:3', 'product:4'], BlockCache::tags('products', ['ids' => [3, 4, 0]]));
    same(['cms-blocks'], BlockCache::tags('paragraph', []));
});

test('un bloque que depende del visitante (formulario) o de la hora (entradas) se pinta en cada petición', function (): void {
    $app = cmsApp(['cms'], [], false);
    cmsInsertPost($app, ['title' => 'Entrada uno']);
    $blocks = [['type' => 'form', 'attrs' => ['form_id' => 'contact']], ['type' => 'posts', 'attrs' => ['limit' => 3]], ['type' => 'paragraph', 'attrs' => ['html' => 'Fijo']]];
    $cms = blocksCms($app);
    same($cms->renderFresh($blocks), $cms->render($blocks));
    $next = blocksCms(perfNext($app));
    $html = $next->render($blocks);
    same(2, $next->blockCache()->painted(), 'el formulario y las entradas, siempre');
    same(0, $next->blockCache()->skeletons(), 'el párrafo no');
    contains('Entrada uno', $html);
    contains('af-form-block', $html);
    check($next->blockCache()->isDynamic('form') && $next->blockCache()->isDynamic('posts') && !$next->blockCache()->isDynamic('product'), 'tipos dinámicos');
    check($next->blockCache()->hasDynamic([['type' => 'group', 'children' => [['type' => 'form', 'attrs' => []]]]]), 'también dentro de un grupo');
});

test('un plugin puede declarar dinámico otro tipo de bloque con el filtro «cms.block.dynamic»', function (): void {
    [$app, $a] = blocksShop();
    $blocks = [['type' => 'product', 'attrs' => ['id' => $a]]];
    blocksCms($app)->render($blocks);
    $next = perfNext($app);
    $next->boot();
    $next->hooks()->filter('cms.block.dynamic', static function ($types): array {
        return array_merge(is_array($types) ? $types : [], ['product']);
    });
    $cms = blocksCms($next);
    $cms->render($blocks);
    $cms->render($blocks);
    same(2, $cms->blockCache()->painted(), 'declarado dinámico, no se guarda');
});

test('GET /bloque/{id} da solo ese bloque, sin indexar; 404 si no existe, si no es público o si el id no vale', function (): void {
    [$app, $a] = blocksShop();
    $page = cmsInsertPage($app, ['title' => 'Bloques', 'blocks' => [
        ['type' => 'paragraph', 'attrs' => ['html' => 'Primero']],
        ['type' => 'product', 'attrs' => ['id' => $a]],
        ['type' => 'paragraph', 'attrs' => ['html' => 'Tercero & último']],
        ['type' => 'form', 'attrs' => ['form_id' => 'contact']],
    ]]);
    $draft = cmsInsertPage($app, ['title' => 'Borrador', 'status' => 'draft']);
    $post = cmsInsertPost($app, ['title' => 'Entrada con bloque']);
    $web = new TestClient($app);

    $r = $web->get('/bloque/p' . $page . '-2');
    same(200, $r->status);
    contains('Tercero &amp; último', $r->body);
    notContains('Primero', $r->body, 'solo ese bloque');
    notContains('<html', $r->body, 'sin el resto de la página');
    same('noindex', $r->headers['X-Robots-Tag']);
    check($r->cacheTtl > 0 && in_array('page:' . $page, $r->cacheTags, true), 'se guarda y se invalida con su página');

    $product = $web->get('/bloque/p' . $page . '-1');
    contains('Silla Ñandú &amp; Cía', $product->body);
    check(in_array('product:' . $a, $product->cacheTags, true), 'y con el producto que enseña');

    $form = $web->get('/bloque/p' . $page . '-3');
    same(200, $form->status);
    same(0, $form->cacheTtl, 'un formulario no se guarda');
    same('no-store, private', $form->headers['Cache-Control']);

    same(200, $web->get('/bloque/e' . $post . '-0')->status, 'también de una entrada');
    same(404, $web->get('/bloque/p' . $page . '-9')->status, 'un bloque que no existe');
    same(404, $web->get('/bloque/p' . $draft . '-0')->status, 'una página que no es pública');
    same(404, $web->get('/bloque/p999999-0')->status, 'una página que no existe');
    same(404, $web->get('/bloque/x1-0')->status, 'un identificador que no vale');
});

test('con la cuenta abierta (la marca «u») un bloque de productos ni sale de la copia compartida ni la escribe', function (): void {
    [$app, $a] = blocksShop();
    $page = cmsInsertPage($app, ['title' => 'Bloques', 'blocks' => [['type' => 'product', 'attrs' => ['id' => $a]]]]);
    $uri = '/bloque/p' . $page . '-0';
    $visit = static function (bool $account) use ($app, $uri): int {
        $next = perfNext($app);
        $r = perfGet($next, $uri, $account ? [$next->cookieName('u') => '1'] : []);
        same(200, $r->status);
        contains('Silla Ñandú &amp; Cía', $r->body);

        return blocksCms($next)->blockCache()->painted();
    };

    check($visit(true) > 0, 'con la cuenta abierta se pinta');
    check($visit(false) > 0, 'y no dejó copia: el visitante sin cuenta que llega después lo tiene que pintar él');
    same(0, $visit(false), 'el siguiente sin cuenta ya recibe la copia');
    check($visit(true) > 0, 'quien tiene la cuenta abierta no la recibe: lo suyo se pinta para él (un plugin puede darle otro precio)');
    same(0, $visit(false), 'y lo pintado para él no ha pisado la copia de los demás');
});
