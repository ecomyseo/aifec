<?php
/**
 * AIFEC — límites de la memoria del buscador frente a los hechos del catálogo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('una intención aprendida conserva el plan original de precio, medidas, exclusiones y existencias', function (): void {
    $app = searchApp();
    $ids = askShop($app);
    $search = searchService($app);
    $query = 'mesa hasta 100 cm por menos de 100 € sin cuero en stock';
    $original = $search->asker()->plan($query, 'es', false);
    $seen = null;
    $app->hooks()->filter('search.plan.text', static function ($text, array $plan) use (&$seen): string {
        $seen = $plan;
        return 'mesa';
    });
    $learned = $search->asker()->plan($query, 'es', false);
    same($original, $seen, 'la memoria recibe la consulta y sus condiciones originales');
    same('mesa', $learned['text']);
    unset($original['text'], $learned['text']);
    same($original, $learned, 'ninguna condición cambia al ampliar el texto');
    $actual = $search->ask($query, ['ai' => false, 'log' => false]);
    same([$ids['mesa_corta']], array_column($actual['products'], 'id'), 'el catálogo ejecuta precio, medida y exclusiones después del aprendizaje');
    same('exact', $actual['answer_kind']);
});

test('la memoria no sustituye el plan por un objeto ni borra la consulta con texto vacío o inválido', function (): void {
    $app = searchApp();
    askShop($app);
    $search = searchService($app);
    $original = $search->asker()->plan('mesa auxiliar', 'es', false);
    check($original['text'] !== '');
    $override = null;
    $app->hooks()->filter('search.plan.text', static function () use (&$override) {
        return $override;
    });
    foreach ([['text' => 'mesa', 'catalog' => []], '', str_repeat('x', 201), "\xC3\x28", false] as $bad) {
        $override = $bad;
        same($original, $search->asker()->plan('mesa auxiliar', 'es', false), 'una propuesta inválida deja el plan intacto');
    }
});

test('la clasificación aprendida reordena coincidencias reales sin alterar total ni confianza', function (): void {
    $app = searchApp();
    searchShop($app);
    $search = searchService($app);
    $before = $search->query('mesa');
    check(count($before['ids']) >= 2);
    $app->hooks()->filter('search.result.ids', static function (array $ids, string $query, string $lang, string $type): array {
        same('mesa', $query);
        same('es', $lang);
        same('product', $type);
        return array_reverse($ids);
    });
    $search->forget();
    $after = $search->query('mesa');
    same(array_reverse($before['ids']), $after['ids']);
    unset($before['ids'], $after['ids']);
    same($before, $after, 'solo cambia el orden de resultados ya encontrados');
});

test('una clasificación no puede añadir productos, repetirlos, perderlos ni convertirlos en cadenas', function (): void {
    $app = searchApp();
    searchShop($app);
    $search = searchService($app);
    $before = $search->query('mesa');
    check(count($before['ids']) >= 2);
    $override = null;
    $app->hooks()->filter('search.result.ids', static function () use (&$override) {
        return $override;
    });
    $invented = $before['ids'];
    $invented[0] = 99999999;
    foreach ([$invented, array_fill(0, count($before['ids']), $before['ids'][0]), array_slice($before['ids'], 1), array_map('strval', $before['ids']), null] as $bad) {
        $override = $bad;
        $search->forget();
        same($before, $search->query('mesa'), 'el buscador rechaza una lista distinta del conjunto real');
    }
});

test('la memoria no adelanta un producto agotado a costa de la disponibilidad original', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $search = searchService($app);
    $before = $search->query('silla');
    check(in_array($ids['silla_roja'], $before['ids'], true));
    check(in_array($ids['silla_azul'], $before['ids'], true));
    $app->hooks()->filter('search.result.ids', static function (array $found) use ($ids): array {
        return array_values(array_merge([$ids['silla_azul']], array_diff($found, [$ids['silla_azul']])));
    });
    $search->forget();
    same($before, $search->query('silla'));
});

test('una referencia exacta conserva su posición aunque una regla quiera poner otro producto antes', function (): void {
    $app = searchApp();
    $exact = aifecProduct($app, ['name' => 'Pieza REF-040', 'sku' => 'REF-040']);
    aifecProduct($app, ['name' => 'Accesorio REF-040', 'sku' => 'REF-041']);
    $search = searchService($app);
    $search->reindexNow();
    $before = $search->query('REF-040');
    check(count($before['ids']) >= 2, 'también hay una coincidencia por nombre');
    same($exact, $before['ids'][0]);
    $app->hooks()->filter('search.result.ids', static function (array $ids): array {
        return array_reverse($ids);
    });
    $search->forget();
    same($before, $search->query('REF-040'));
});

test('la intención aprendida sigue respetando el presupuesto y la exclusión de cuero con stock real', function (): void {
    $app = searchApp();
    askShop($app);
    $app->hooks()->filter('search.plan.text', static function (): string { return 'silla'; });
    $result = searchService($app)->ask('silla de oficina por menos de 200 € sin cuero en stock', ['ai' => false, 'log' => false]);
    check($result['products'] !== [], 'hay alternativas que cumplen las condiciones');
    foreach ($result['products'] as $product) {
        check($product['available'], 'no entra un producto agotado');
        check((int) $product['price'] < 20000, 'se mantiene el precio máximo');
        check(strpos(strtolower($product['name']), 'premium') === false, 'el producto excluido no vuelve por el aprendizaje');
    }
});
