<?php
/**
 * AIFEC — pruebas de «Rellenar con IA» en el alta de un producto: con la IA encendida propone, a partir del nombre, una descripción
 * corta y una categoría que existe; nunca guarda nada; solo sale en el alta y solo a quien puede usar la IA.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../ai/tests/helpers.php';
require_once __DIR__ . '/AdminProductKitTest.php';

/**
 * Tienda con IA de mentira y dos categorías.
 *
 * @return array{0:App,1:TestClient,2:array<string,int>}
 */
function quickfillShop(array $settings = []): array
{
    $app = aiApp($settings, ['es']);
    $web = adminClient($app);
    $oficina = aifecCategory($app, 'Oficina');
    $sillas = aifecCategory($app, 'Sillas de oficina', $oficina);

    return [$app, $web, ['oficina' => $oficina, 'sillas' => $sillas]];
}

function quickfillAsk(TestClient $web, string $name): Response
{
    $web->get('/admin/r/products/new');

    return $web->post('/admin/catalog/products/quickfill', ['_token' => aifecToken($web), 'name' => $name], ['accept' => 'application/json']);
}

test('la tarjeta «Rellenar con IA» sale solo en el alta y con la IA encendida', function (): void {
    list($app, $web) = quickfillShop();
    $new = $web->get('/admin/r/products/new')->body;
    contains('data-af-quickfill="/admin/catalog/products/quickfill"', $new);
    contains('data-af-run', str_replace('data-af-quickfill-run', 'data-af-run', $new), 'el botón está');
    contains('Rellenar con IA', $new);
    contains('quickfill.js', $new);
    same(1, substr_count($new, 'data-af-text-summary='), 'una sola tarjeta');

    $id = prodMake($web, 'Ya existe');
    notContains('data-af-quickfill', $web->get('/admin/r/products/' . $id)->body, 'en un producto que ya existe no sale');

    $off = aiApp(['ai.enabled' => false], ['es']);
    notContains('data-af-quickfill', adminClient($off)->get('/admin/r/products/new')->body, 'con la IA apagada no sale');

    $app->db()->insert('roles', ['code' => 'catalogeditor', 'name' => 'Catalog editor', 'caps' => json_encode(['dashboard.view', 'catalog.view', 'catalog.edit']), 'system' => 0]);
    notContains('data-af-quickfill', adminClient($app, 'catalogeditor')->get('/admin/r/products/new')->body, 'sin permiso de IA no sale');

    $plain = prodApp();
    notContains('data-af-quickfill', adminClient($plain)->get('/admin/r/products/new')->body, 'sin el módulo de IA tampoco');
});

test('propone una descripción corta y una categoría que existe, como JSON, y no guarda nada', function (): void {
    list($app, $web, $cats) = quickfillShop();
    aiFake($app)->script(aiJson(['summary' => 'Silla ergonómica & ligera, ideal para la oficina.', 'category_id' => $cats['sillas']]));
    $r = quickfillAsk($web, 'Silla Ergo Pro');
    same(200, $r->status);
    contains('no-store', $r->headers['Cache-Control']);
    $data = $web->json();
    same(true, $data['ok']);
    same('Silla ergonómica & ligera, ideal para la oficina.', $data['summary'], 'texto plano, sin escapar: es JSON');
    same($cats['sillas'], $data['category_id']);
    same('Sillas de oficina', $data['category'], 'con el nombre sin los guiones del árbol');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products}'), 'una propuesta no crea nada');
    same(0, count(aiRows($app, 'ai_proposals')), 'ni queda como propuesta pendiente');

    $requests = aiFake($app)->requests;
    same(1, count($requests), 'una llamada');
    $sent = (string) $requests[0]->messages[0]->content;
    contains('Silla Ergo Pro', $sent, 'recibe el nombre');
    contains('Sillas de oficina', $sent, 'y las categorías que hay');
    contains((string) $cats['oficina'], $sent);
    same('economy', aiRows($app, 'ai_usage')[0]['tier'], 'con el nivel económico');
});

test('lo que la IA devuelve se limpia: sin etiquetas, a 300 caracteres y con una categoría que no existe descartada', function (): void {
    list($app, $web, $cats) = quickfillShop();
    aiFake($app)->script(aiJson(['summary' => '<b>Negrita</b><script>x</script> ' . str_repeat('a', 400), 'category_id' => 987654]));
    quickfillAsk($web, 'Algo');
    $data = $web->json();
    same(true, $data['ok']);
    notContains('<', $data['summary']);
    same(300, mb_strlen($data['summary'], 'UTF-8'));
    same(null, $data['category_id'], 'una categoría inventada se descarta');
    same('', $data['category']);

    aiFake($app)->script(aiJson(['summary' => 'Texto', 'category_id' => 0]));
    quickfillAsk($web, 'Algo');
    same(null, $web->json()['category_id'], '0 es «ninguna»');
    aiFake($app)->script(aiJson(['summary' => '', 'category_id' => $cats['oficina']]));
    quickfillAsk($web, 'Algo');
    $data = $web->json();
    same('', $data['summary']);
    same($cats['oficina'], $data['category_id'], 'una propuesta solo de categoría también vale');
});

test('sin nombre, con la IA apagada, sin permiso o con la IA caída, la respuesta es un error que se entiende y no filtra nada', function (): void {
    list($app, $web) = quickfillShop();
    $r = quickfillAsk($web, '   ');
    same(422, $r->status);
    same(false, $web->json()['ok']);
    contains('Escribe antes el nombre del producto.', $web->json()['message']);
    same(0, count(aiFake($app)->requests), 'sin nombre no se gasta ninguna llamada');

    $app->bind('ai.provider.economy', static function (): AiFailingProvider {
        return new AiFailingProvider('clave-de-mentira-123 rechazada');
    });
    $r = quickfillAsk($web, 'Silla');
    same(502, $r->status);
    same('La IA no ha podido ayudar ahora mismo.', $web->json()['message']);
    notContains('clave-de-mentira', $r->body, 'el motivo del proveedor no sale');

    $off = aiApp(['ai.enabled' => false], ['es']);
    $offWeb = adminClient($off);
    same(409, quickfillAsk($offWeb, 'Silla')->status);
    same(0, count(aiFake($off)->requests));

    $app->db()->insert('roles', ['code' => 'catalogeditor', 'name' => 'Catalog editor', 'caps' => json_encode(['dashboard.view', 'catalog.view', 'catalog.edit']), 'system' => 0]);
    $editor = adminClient($app, 'catalogeditor');
    same(409, quickfillAsk($editor, 'Silla')->status, 'sin permiso de IA, como si estuviera apagada');

    $app->db()->insert('roles', ['code' => 'catalogviewer', 'name' => 'Catalog viewer', 'caps' => json_encode(['dashboard.view', 'catalog.view', 'ai.use']), 'system' => 0]);
    $viewer = adminClient($app, 'catalogviewer');
    $viewer->get('/admin/r/products');
    same(403, $viewer->post('/admin/catalog/products/quickfill', ['_token' => aifecToken($viewer), 'name' => 'Silla'], ['accept' => 'application/json'])->status, 'quien no puede cambiar el catálogo, no');
});

test('sin la ficha de formulario no se acepta, y la respuesta no se puede guardar en una caché', function (): void {
    list($app, $web) = quickfillShop();
    $web->get('/admin/r/products/new');
    $r = $web->post('/admin/catalog/products/quickfill', ['name' => 'Silla'], ['accept' => 'application/json']);
    same(419, $r->status);
    same(0, count(aiFake($app)->requests));
    $r = quickfillAsk($web, 'Silla');
    contains('no-store', $r->headers['Cache-Control']);
});

test('el guion de «Rellenar con IA» va por delegación, copia solo lo marcado y no lleva textos propios', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/quickfill.js');
    contains("document.addEventListener('click'", $js, 'delegación sobre el documento');
    contains('window.Aifec.post', $js, 'usa la petición del panel, que ya manda la ficha');
    contains('data-af-quickfill-use', $js, 'una casilla por campo');
    contains('.checked', $js, 'solo lo marcado');
    contains('f[summary][', $js, 'la descripción corta en el idioma principal');
    contains('f[categories][]', $js);
    contains('__afArea', $js, 'y su editor de texto, si lo hay');
    notContains('document.write', $js);
    foreach (['The AI', 'Short description', 'Use the selected', 'Write the name'] as $english) {
        notContains($english, preg_replace('#/\*.*?\*/#s', '', $js) ?? '', 'sin cadenas sueltas: «' . $english . '» llega traducido en la tarjeta');
    }
});
