<?php
/**
 * AIFEC — pruebas del buscador con el módulo de contenidos: las páginas y las entradas publicadas se indexan y se buscan por su
 * tipo (sin mezclarse con los productos), y lo que se despublica sale del índice.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';

/**
 * @param string[] $paragraphs
 *
 * @return array<int,array<string,mixed>>
 */
function searchParagraphs(array $paragraphs): array
{
    $blocks = [];
    foreach ($paragraphs as $text) {
        $blocks[] = ['type' => 'paragraph', 'attrs' => ['html' => $text]];
    }

    return $blocks;
}

test('las páginas y las entradas publicadas se buscan por su tipo y no se mezclan con los productos', function (): void {
    $app = searchApp([], ['cms']);
    $product = aifecProduct($app, ['name' => 'Mesa de roble']);
    $page = cmsInsertPage($app, ['title' => 'Política de devoluciones', 'blocks' => searchParagraphs(['Puedes devolver tu mesa en 30 días.'])]);
    $post = cmsInsertPost($app, ['title' => 'Cómo cuidar una mesa de roble', 'excerpt' => 'Consejos sencillos', 'blocks' => searchParagraphs(['Usa un paño húmedo.'])]);
    $draft = cmsInsertPage($app, ['title' => 'Borrador secreto', 'status' => 'draft']);
    same(4, searchService($app)->reindexNow(), 'un producto, dos páginas y una entrada');

    same([$page], searchIds($app, 'devoluciones', ['type' => 'page']));
    same([$page], searchIds($app, 'devolver', ['type' => 'page']), 'por el texto de los bloques');
    same([$post], searchIds($app, 'paño', ['type' => 'post']));
    same([$post], searchIds($app, 'consejos', ['type' => 'post']), 'por el extracto');
    same([$product], searchIds($app, 'mesa'), 'los productos no se mezclan con lo demás');
    same([$page], searchIds($app, 'mesa', ['type' => 'page']), 'la mesa que se devuelve');
    same([$post], searchIds($app, 'mesa', ['type' => 'post']));
    same([], searchIds($app, 'secreto', ['type' => 'page']), 'un borrador no se indexa');
    same(0, count(array_filter(searchDocs($app, 'page'), static function (array $doc) use ($draft): bool {
        return (int) $doc['entity_id'] === $draft;
    })));
    searchCheckTerms($app);
});

test('al guardar un contenido se reindexa (sin el módulo de tareas, en el momento) y al despublicarlo sale', function (): void {
    $app = searchApp([], ['cms']);
    $page = cmsInsertPage($app, ['title' => 'Envíos y plazos', 'status' => 'draft']);
    $app->hooks()->fire('content.saved', 'page', $page);
    same([], searchIds($app, 'envios', ['type' => 'page']), 'un borrador no sale');

    $app->db()->update('pages', ['status' => 'published'], ['id' => $page]);
    $app->invalidate(['cms', 'page:' . $page]);
    $app->hooks()->fire('content.saved', 'page', $page);
    same([$page], searchIds($app, 'envios', ['type' => 'page']));

    $app->db()->update('pages', ['status' => 'draft'], ['id' => $page]);
    $app->invalidate(['cms', 'page:' . $page]);
    $app->hooks()->fire('content.saved', 'page', $page);
    same([], searchIds($app, 'envios', ['type' => 'page']), 'despublicada, fuera');
    same([], searchDocs($app, 'page'));
    searchCheckTerms($app);
});

test('con el módulo de tareas, guardar un contenido encola un trabajo único', function (): void {
    $app = searchApp([], ['cms', 'jobs']);
    $post = cmsInsertPost($app, ['title' => 'Novedades de otoño']);
    for ($i = 0; $i < 3; ++$i) {
        $app->hooks()->fire('content.saved', 'post', $post);
    }
    same(1, searchJobCount($app, 'search.index', 'pending'));
    searchWork($app);
    same([$post], searchIds($app, 'otoño', ['type' => 'post']));
});
