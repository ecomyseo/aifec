<?php
/**
 * AIFEC — buscador: la corrección de una errata que se le enseña a quien busca es una palabra de verdad, no un trozo de
 * una referencia. Visto en la tienda de muestra: «sila ergo» decía «Mostrando resultados para sil ergo» porque «SIL»
 * (de las referencias SIL-ERGO y SIL-BAS) estaba a la misma distancia que «silla».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('«sila ergo» se corrige a «silla ergo», no al trozo «sil» de las referencias', function (): void {
    $app = searchApp();
    $sillas = aifecCategory($app, 'Sillas de oficina');
    aifecProduct($app, ['name' => 'Silla Ergo Pro', 'sku' => 'SIL-ERGO', 'price' => 24900, 'stock' => 5, 'categories' => [$sillas]]);
    aifecProduct($app, ['name' => 'Silla Básica Home', 'sku' => 'SIL-BAS', 'price' => 8900, 'stock' => 5, 'categories' => [$sillas]]);
    aifecProduct($app, ['name' => 'Cojín Lumbar', 'sku' => 'TEX-LUM', 'price' => 2490, 'stock' => 5, 'summary' => 'Se sujeta a cualquier silla.']);
    $search = searchService($app);
    $search->reindexNow();

    $result = $search->query('sila ergo');
    same('silla ergo', $result['corrected'], 'lo que se enseña es la palabra, con sus letras');
    check((int) $result['total'] >= 1, 'y encuentra la silla');

    same('silla', $search->query('sila')['corrected']);
    same(null, $search->query('SIL-ERGO')['corrected'], 'buscar la referencia tal cual no se corrige');
    check((int) $search->query('SIL-ERGO')['total'] >= 1, 'y la encuentra');
});

test('lo que solo casa por la descripción larga no se mezcla con lo que casa por el nombre; si no hay otra cosa, sí sale', function (): void {
    $app = searchApp();
    $perfume = aifecProduct($app, ['name' => 'Perfume de lavanda', 'price' => 2990, 'stock' => 5]);
    $detergente = aifecProduct($app, ['name' => 'Detergente líquido', 'description' => '<p>Con un perfume suave que dura.</p>', 'price' => 590, 'stock' => 5]);
    $search = searchService($app);
    $search->reindexNow();
    same([$perfume], array_map('intval', $search->query('perfume')['ids']), 'solo el perfume, no el detergente que lo nombra');
    same([$detergente], array_map('intval', $search->query('suave')['ids']), 'sin nada mejor, lo de la descripción sí sale');
});

test('los sinónimos de serie: «colonia» encuentra un perfume, y apagados ya no', function (): void {
    $app = searchApp();
    $perfume = aifecProduct($app, ['name' => 'Perfume Sillage 100 ml', 'price' => 6400, 'stock' => 5]);
    $search = searchService($app);
    $search->reindexNow();
    same([$perfume], array_map('intval', $search->query('colonia')['ids']), 'colonia = perfume');
    same([$perfume], array_map('intval', $search->query('perfum')['ids']), 'una palabra a medias también (14/11/2026: «perfum» no daba nada)');
    $app->settings()->set('search.default_synonyms', false);
    $app->invalidate(['search']);
    $search->forget();
    same([], $search->query('colonia')['ids'], 'sin los de serie, no');
});

test('una palabra que solo comparte la raíz no se «corrige» a otra («colonia» no es «colorido»), y la frase del recuento dice lo corregido', function (): void {
    $app = searchApp();
    aifecProduct($app, ['name' => 'Camiseta de colorido estampado', 'price' => 1990, 'stock' => 5]);
    aifecProduct($app, ['name' => 'Zapatilla de pádel', 'price' => 4990, 'stock' => 5]);
    $search = searchService($app);
    $search->reindexNow();

    same(null, $search->query('colonia')['corrected'], 'colonia y colorido están a tres letras');
    same('zapatilla', $search->query('zapatila')['corrected'], 'una errata de verdad sí se corrige');
    check(!\Aifec\Modules\Search\Search::closeWord('taza', 'raza') && !\Aifec\Modules\Search\Search::closeWord('lampara', 'camara'), 'ni con otra primera letra («taza» no es «raza»)');
    check(\Aifec\Modules\Search\Search::closeWord('sila', 'silla') && !\Aifec\Modules\Search\Search::closeWord('colonia', 'colorido'), 'la comparación es por la palabra entera');

    $body = (new TestClient($app))->get('/buscar?q=zapatila')->body;
    contains('Resultados para zapatilla', $body);
    contains('1 resultado para zapatilla', $body, 'el recuento dice lo mismo que el título');
    notContains('resultado para zapatila', $body);
});
