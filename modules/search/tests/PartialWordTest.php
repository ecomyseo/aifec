<?php
/**
 * AIFEC — buscador: una palabra a medio escribir encuentra lo que toca (ronda 3, 14/11/2026: «perfum» no daba nada teniendo
 * perfumes, porque la corrección se comparaba solo con la forma guardada, «perfumes», a dos letras, y no con la raíz «perfume»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('«perfum» encuentra los perfumes aunque la forma guardada sea «perfumes»', function (): void {
    $app = searchApp();
    $a = aifecProduct($app, ['name' => 'Perfumes de nicho Ambre Tabac', 'summary' => 'Perfumes orientales.', 'price' => 12000, 'stock' => 5]);
    $b = aifecProduct($app, ['name' => 'Perfumes Oud intenso', 'summary' => 'Perfumes de autor.', 'price' => 9000, 'stock' => 5]);
    aifecProduct($app, ['name' => 'Perfil de aluminio', 'price' => 900, 'stock' => 5]);
    $search = searchService($app);
    $search->reindexNow();
    $r = $search->query('perfum');
    same('perfumes', $r['corrected']);
    $ids = array_map('intval', $r['ids']);
    sort($ids);
    same([$a, $b], $ids, 'los dos perfumes, y no el perfil');
});

test('«una mochila o una botella»: salen las dos (la búsqueda relajada, con la «o» de por medio)', function (): void {
    $app = searchApp();
    $bottle = aifecProduct($app, ['name' => 'Botella térmica Stamina', 'price' => 1036, 'stock' => 5]);
    $bag = aifecProduct($app, ['name' => 'Mochila de montaña 30 l', 'price' => 4990, 'stock' => 5]);
    aifecProduct($app, ['name' => 'Silla plegable', 'price' => 2990, 'stock' => 5]);
    $search = searchService($app);
    $search->reindexNow();
    $r = $search->query('una mochila o una botella');
    $ids = array_map('intval', $r['ids']);
    sort($ids);
    $want = [$bottle, $bag];
    sort($want);
    same($want, $ids);
});
