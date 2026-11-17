<?php
/**
 * AIFEC — todos los listados de recursos del panel, con todos los módulos: cada enlace de fila lleva a su propio recurso y abre.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

test('cada listado de recurso abre, y sus filas enlazan a su propio recurso (nunca al nombre de una columna o de un filtro)', function (): void {
    // 14/11/2026, en vivo: en los listados con textos por idioma, los bucles de columnas y filtros pisaban el nombre del recurso y
    // las filas de «Atributos» enlazaban a «/admin/r/type/2» y las de otro listado a «/admin/r/status/1»: 404 al pulsar.
    $app = testApp(['modules' => '*']);
    aifecProduct($app, ['name' => 'Silla de prueba', 'price' => 1000]);
    $web = adminClient($app);
    $names = [];
    foreach ($app->adminDefinitions() as $definition) {
        foreach (isset($definition['resources']) && is_array($definition['resources']) ? $definition['resources'] : [] as $name => $resource) {
            $names[(string) $name] = true;
        }
    }
    check(count($names) >= 5, 'hay recursos declarados (' . count($names) . ')');
    $opened = 0;
    foreach (array_keys($names) as $name) {
        $list = $web->get('/admin/r/' . $name);
        if ($list->status === 403 || $list->status === 302 || $list->status === 303) {
            continue;
        }
        same(200, $list->status, 'el listado /admin/r/' . $name . ' abre');
        ++$opened;
        preg_match_all('#href="/admin/r/([a-z0-9_-]+)/(\d+)"#', (string) $list->body, $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            check(isset($names[$link[1]]), 'en /admin/r/' . $name . ' una fila enlaza a «/admin/r/' . $link[1] . '», que no es un recurso');
        }
    }
    check($opened >= 5, 'se han abierto los listados (' . $opened . ')');
});

test('en los listados con textos por idioma, el enlace de la fila es el del recurso aunque tenga columnas y filtros', function (): void {
    $app = testApp(['modules' => '*']);
    $id = aifecProduct($app, ['name' => 'Mesa de prueba', 'price' => 2000]);
    $web = adminClient($app);
    foreach (['/admin/r/products', '/admin/r/products?f[status]=active'] as $path) {
        $body = (string) $web->get($path)->body;
        contains('href="/admin/r/products/' . $id . '"', $body, 'la fila del producto en ' . $path);
    }
});
