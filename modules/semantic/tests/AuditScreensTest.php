<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Semantic\Audit;

require_once __DIR__ . '/helpers.php';

/*
 * La pantalla «Auditoría AIFEC» del panel, la pieza del inicio y la nota en la ficha de producto.
 */

test('la auditoría la ve quien tiene el permiso, a los demás les da 403 y sin sesión lleva al acceso', function (): void {
    $app = semanticApp();
    same(200, adminClient($app)->get('/admin/semantic/audit')->status);

    $other = semanticApp();
    same(403, adminClient($other, 'support')->get('/admin/semantic/audit')->status);

    $anonymous = new TestClient(semanticApp());
    same(302, $anonymous->get('/admin/semantic/audit')->status);
});

test('el menú del grupo de inteligencia artificial lleva las dos pantallas', function (): void {
    $app = semanticApp();
    $web = adminClient($app);
    $page = $web->get('/admin')->body;

    contains('href="/admin/semantic/audit"', $page);
    contains('Auditoría AIFEC', $page);
    contains('href="/admin/semantic/bots"', $page);
    contains('Robots y asistentes', $page);
});

test('la pantalla enseña la nota del sitio y cada comprobación que falla con su enlace', function (): void {
    $app = semanticApp();
    $web = adminClient($app);
    $r = $web->get('/admin/semantic/audit');
    $audit = $app->service('semantic')->audit();

    contains('Nota AIFEC de la tienda', $r->body);
    contains('>' . $audit['score'] . '<', $r->body, 'la nota, en grande');
    contains('La política de devoluciones está definida', $r->body);
    contains('href="/admin/settings/semantic#tab=smreturns"', $r->body, 'enlace al ajuste que lo arregla');
    contains('data-af-tabs', $r->body, 'cada apartado en su pestaña');
});

test('sin productos medidos la pantalla lo dice y ofrece medirlos', function (): void {
    $app = semanticApp();
    aifecProduct($app);
    $web = adminClient($app);
    $body = $web->get('/admin/semantic/audit')->body;

    contains('Los productos todavía no se han medido', $body);
    contains('Medir los productos ahora', $body);
    contains('action="/admin/semantic/audit/run"', $body);
});

test('la tabla de los peores empieza por el de menor nota, con lo primero que le falta y el enlace a su ficha', function (): void {
    $app = semanticApp();
    $vacio = aifecProduct($app, ['name' => 'Producto vacío', 'slug' => 'vacio']);
    $medio = aifecProduct($app, ['name' => 'Producto medio', 'slug' => 'medio', 'gtin' => '8412345678905', 'summary' => 'Resumen', 'price' => 1000]);
    $bueno = auditProductFull($app);
    Audit::batch($app, 0);
    $body = adminClient($app)->get('/admin/semantic/audit')->body;

    $a = strpos($body, 'Producto vacío');
    $b = strpos($body, 'Producto medio');
    $c = strpos($body, 'Producto casi perfecto');
    check($a !== false && $b !== false && $c !== false, 'salen los tres');
    check($a < $b && $b < $c, 'del peor al mejor');
    contains('href="/admin/r/products/' . $vacio . '"', $body);
    contains('href="/admin/r/products/' . $medio . '"', $body);
    contains('href="/admin/r/products/' . $bueno . '"', $body);
    $fila = (string) substr($body, (int) $a, (int) strpos($body, '</tr>', (int) $a) - (int) $a);
    contains('Tiene código de barras, o marca y referencia de fabricante', $fila, 'lo primero que le falta al vacío es lo que más pesa');
    contains('y 10 más', $fila, 'y cuántas cosas más le faltan');
    contains('Nota de 0 a 49', $body, 'la distribución por tramos');
});

test('el filtro «qué falta» deja solo los productos que no cumplen esa comprobación', function (): void {
    $app = semanticApp();
    aifecProduct($app, ['name' => 'Sin código', 'slug' => 'sin-codigo']);
    aifecProduct($app, ['name' => 'Con código', 'slug' => 'con-codigo', 'gtin' => '8412345678905']);
    Audit::batch($app, 0);
    $web = adminClient($app);

    $todos = $web->get('/admin/semantic/audit')->body;
    contains('Sin código', $todos);
    contains('Con código', $todos);

    $filtrado = $web->get('/admin/semantic/audit?missing=identifier')->body;
    contains('Sin código', $filtrado);
    notContains('Con código', $filtrado);
    contains('value="identifier" selected', $filtrado, 'el filtro queda elegido');

    $raro = $web->get('/admin/semantic/audit?missing=no-existe');
    same(200, $raro->status, 'un filtro que no existe no rompe nada');
    contains('Con código', $raro->body);
});

test('un nombre de producto con etiquetas y «&» sale escapado', function (): void {
    $app = semanticApp();
    aifecProduct($app, ['name' => 'Mesa <b>&</b> Cía', 'slug' => 'mesa-cia']);
    Audit::batch($app, 0);
    $body = adminClient($app)->get('/admin/semantic/audit')->body;

    contains('Mesa &lt;b&gt;&amp;&lt;/b&gt; Cía', $body);
    notContains('Mesa <b>&</b> Cía', $body);
});

test('la pieza del inicio enseña la nota del sitio y los tres fallos que más se repiten', function (): void {
    $app = semanticApp();
    for ($i = 1; $i <= 3; ++$i) {
        aifecProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'producto-' . $i, 'gtin' => '8412345678905']);
    }
    Audit::batch($app, 0);
    $web = adminClient($app);
    $body = $web->get('/admin')->body;

    contains('Preparación para las máquinas', $body);
    contains('Lo que más falta en los productos', $body);
    contains('Su descripción tiene 300 caracteres o más', $body);
    contains('3 productos', $body, 'cuántos productos lo incumplen');
    notContains('Tiene código de barras, o marca y referencia de fabricante', $body, 'lo que todos cumplen no sale');
});

test('la ficha de producto del panel lleva en la columna derecha su nota y lo que falta, con enlaces', function (): void {
    $app = semanticApp();
    $id = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    $body = adminClient($app)->get('/admin/r/products/' . $id)->body;

    contains('Nota AIFEC', $body);
    contains('Lo que falta', $body);
    contains('Tiene un resumen', $body);
    contains('href="/admin/r/products/' . $id . '"', $body);

    $nuevo = adminClient(semanticApp())->get('/admin/r/products/new')->body;
    notContains('Nota AIFEC', $nuevo, 'un producto nuevo todavía no tiene nota');
});

test('«Medir los productos ahora» pone el cálculo en la cola y avisa; sin cola avisa de que solo corre la tarea diaria', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    aifecProduct($app);
    $web = adminClient($app);
    $web->get('/admin/semantic/audit');

    $r = $web->post('/admin/semantic/audit/run', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('Los productos se medirán en unos minutos', $web->follow()->body);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'semantic.audit' AND status = 'pending'"));

    $sin = semanticApp();
    $web2 = adminClient($sin);
    $web2->get('/admin/semantic/audit');
    same(302, $web2->post('/admin/semantic/audit/run', ['_token' => aifecToken($web2)])->status);
    contains('solo se miden con la tarea diaria', $web2->follow()->body);

    $forbidden = adminClient(semanticApp(), 'support');
    same(403, $forbidden->post('/admin/semantic/audit/run', ['_token' => aifecToken($forbidden)])->status, 'sin el permiso no se encola nada');
});

/** Un producto casi perfecto (le falta solo el plazo de entrega), para la tabla de los peores. */
function auditProductFull(\Aifec\Kernel\App $app): int
{
    $brand = aifecBrand($app, 'Maderas Norte');
    $category = aifecCategory($app, 'Sillas');

    return aifecProduct($app, [
        'name' => 'Producto casi perfecto', 'slug' => 'casi-perfecto', 'price' => 12900, 'brand_id' => $brand, 'gtin' => '8412345678905',
        'categories' => [$category], 'images' => [aifecMedia($app, 'Foto de frente')], 'summary' => 'Una silla.',
        'description' => str_repeat('Silla de roble macizo con acabado natural. ', 9),
        'facts' => [['label' => 'Material', 'value' => 'Roble'], ['label' => 'Altura', 'value' => '85 cm'], ['label' => 'Peso', 'value' => '5 kg']],
        'faqs' => [['question' => '¿Se monta fácil?', 'answer' => 'Sí.']], 'weight' => 5000, 'length' => 450, 'width' => 450, 'height' => 850,
    ]);
}
