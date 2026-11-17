<?php
/**
 * AIFEC — pruebas de la consulta: plurales, género, tildes, erratas, sinónimos, «todos los términos», «no tengo eso», existencias,
 * idiomas y los casos raros (vacío, solo palabras vacías, símbolos, emojis, comillas y «&»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('plurales y género: «sillas rojas» encuentra «Silla roja»', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    same([$ids['silla_roja']], searchIds($app, 'sillas rojas'));
    same([$ids['silla_roja']], searchIds($app, 'SILLA ROJA'));
    $azules = searchIds($app, 'sillas azules');
    same([$ids['silla_azul']], $azules, 'azules → azul');
    same([$ids['mesa']], searchIds($app, 'mesas de roble'), 'una palabra vacía («de») no estorba');
});

test('tildes: «sillon» encuentra «sillón» y al revés', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    same([$ids['sillon']], searchIds($app, 'sillon'));
    same([$ids['sillon']], searchIds($app, 'sillón'));
    same([$ids['sillon']], searchIds($app, 'SILLÓN'));
    same([$ids['sillon']], searchIds($app, 'sillones'), 'el plural de «sillón»');
    same([$ids['lampara']], searchIds($app, 'lampara'), 'sin tilde, «lámpara»');
});

test('erratas: «slla» se corrige a «silla» y lo dice en «corrected»', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $result = searchService($app)->query('slla');
    same('silla', $result['corrected']);
    $found = array_map('intval', $result['ids']);
    check(in_array($ids['silla_roja'], $found, true) && in_array($ids['silla_azul'], $found, true), 'encuentra las sillas');
    same(true, $result['confident'], 'una errata corregida de una palabra de cinco letras convence');

    $two = searchService($app)->query('slla roja');
    same('silla roja', $two['corrected'], 'solo se corrige la palabra que no existe');
    same([$ids['silla_roja']], array_map('intval', $two['ids']));

    $accent = searchService($app)->query('lampra');
    same('lámpara', $accent['corrected'], 'la palabra corregida se enseña con su tilde');
    same([$ids['lampara']], array_map('intval', $accent['ids']));
    same('orejero', searchService($app)->query('orejro')['corrected'], 'una letra que falta');
    same('orejero', searchService($app)->query('oerjero')['corrected'], 'dos letras cambiadas de sitio');

    same(null, searchService($app)->query('silla')['corrected'], 'lo que está bien escrito no se corrige');
    same(null, searchService($app)->query('zzzzzzzz')['corrected'], 'ni se inventa una corrección lejana');
});

test('un término corregido de menos de cuatro letras no convence', function (): void {
    $app = searchApp();
    aifecProduct($app, ['name' => 'Gel']);
    searchService($app)->reindexNow();
    $result = searchService($app)->query('gal');
    same('gel', $result['corrected']);
    same(false, $result['confident'], '«gal» → «gel»: casi seguro no era eso');
});

test('sinónimos en los dos sentidos', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $sofa = aifecProduct($app, ['name' => 'Sofá chaise longue']);
    searchService($app)->index('product', $sofa);
    searchSynonym($app, 'equal', null, 'sofá, sillón');

    $a = searchIds($app, 'sofa');
    sort($a);
    $expected = [$ids['sillon'], $sofa];
    sort($expected);
    same($expected, $a, '«sofa» encuentra el sofá y el sillón');
    $b = searchIds($app, 'sillon');
    sort($b);
    same($expected, $b, '«sillon» también');
});

test('sinónimos en un solo sentido, y con frases', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $butaca = aifecProduct($app, ['name' => 'Butaca de lectura']);
    $futon = aifecProduct($app, ['name' => 'Futón japonés']);
    searchService($app)->reindexNow();
    searchSynonym($app, 'oneway', 'butaca', 'sillón, silla');
    searchSynonym($app, 'oneway', 'sofa cama', 'futón');

    $found = searchIds($app, 'butaca');
    check(in_array($butaca, $found, true) && in_array($ids['sillon'], $found, true) && in_array($ids['silla_roja'], $found, true), '«butaca» encuentra la butaca, el sillón y las sillas');
    same($butaca, $found[0], 'lo que dice la palabra escrita pesa más que sus sinónimos');
    $back = searchIds($app, 'silla');
    check(!in_array($butaca, $back, true), 'pero «silla» no encuentra la butaca');
    same([$futon], searchIds($app, 'sofa cama'), 'una frase como origen');
    same([$futon], searchIds($app, 'un sofá cama'), 'dentro de una consulta más larga (la palabra vacía «un» no estorba)');
});

test('los sinónimos de un idioma no valen en otro, y los de «todos» valen en todos', function (): void {
    $app = searchApp(['i18n.languages' => ['es', 'en']]);
    $id = aifecProduct($app, ['name' => 'Butaca azul', 'i18n' => ['en' => ['name' => 'Blue armchair']]]);
    searchService($app)->index('product', $id);
    searchSynonym($app, 'equal', null, 'sillón, butaca', 'es');
    searchSynonym($app, 'equal', null, 'armchair, seat', '');
    same([$id], searchIds($app, 'sillón', ['lang' => 'es']), 'el de español, en español');
    same([], searchIds($app, 'sillón', ['lang' => 'en']), 'no en inglés');
    same([$id], searchIds($app, 'seat', ['lang' => 'en']), 'el de todos los idiomas, en inglés');
});

test('dos términos exigen los dos; si no hay nada así, se relaja a «casi todos»', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $strict = searchService($app)->query('silla roja');
    same([$ids['silla_roja']], array_map('intval', $strict['ids']));
    same(false, $strict['relaxed']);

    $loose = searchService($app)->query('silla roble');
    same(true, $loose['relaxed'], 'ninguna cosa es a la vez silla y de roble');
    $found = array_map('intval', $loose['ids']);
    check(in_array($ids['silla_roja'], $found, true) && in_array($ids['mesa'], $found, true), 'salen las sillas y la mesa de roble');

    $three = searchService($app)->query('silla roja grande');
    same(true, $three['relaxed'], 'tres términos, ninguna cosa los tiene todos');
    same($ids['silla_roja'], (int) $three['ids'][0], 'la que cumple dos de tres, primero');
});

test('«no tengo eso»: confident es falso con algo que no tiene que ver', function (): void {
    $app = searchApp();
    searchShop($app);
    $none = searchService($app)->query('zapatillas deportivas');
    same([], $none['ids']);
    same(0, $none['total']);
    same(false, $none['confident']);

    $weak = searchService($app)->query('silla zapatillas deportivas');
    same(false, $weak['confident'], 'una palabra suelta que casa y dos que no existen no convencen');

    $half = searchService($app)->query('mesa zapatillas');
    same(true, $half['relaxed'], 'con una palabra que sí y otra que no, se relaja');
    check($half['total'] > 0, 'y sale la mesa');
    same(false, $half['confident'], 'pero la mitad de la consulta sin encontrar no convence: «no tengo eso»');

    same(true, searchService($app)->query('silla roja')['confident'], 'una consulta que encaja bien, sí');
    same(true, searchService($app)->query('mesa')['confident']);
    same(true, searchService($app)->query('terraza')['confident'], 'aunque solo salga en la descripción');
});

test('el umbral min_score se puede cambiar en los ajustes', function (): void {
    $app = searchApp();
    searchShop($app);
    same(true, searchService($app)->query('terraza')['confident']);
    $app->settings()->set('search.min_score', 1.0);
    searchService($app)->forget();
    same(false, searchService($app)->query('terraza')['confident'], 'con el umbral al máximo, lo de la descripción ya no convence');
});

test('lo agotado va al final y, a igualdad, gana lo que más se vende', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $found = searchIds($app, 'silla');
    $position = array_flip($found);
    check($position[$ids['silla_roja']] < $position[$ids['silla_azul']], 'la azul está agotada: baja, aunque venda más');
    check($position[$ids['sillon']] < $position[$ids['silla_azul']], 'la agotada va detrás hasta de lo que solo casa por la categoría');
    same($ids['silla_azul'], $found[count($found) - 1], 'al final del todo');

    // Dos productos idénticos: gana el que más vende.
    $a = aifecProduct($app, ['name' => 'Estante nórdico', 'sales_count' => 1]);
    $b = aifecProduct($app, ['name' => 'Estante nórdico', 'sales_count' => 9]);
    searchService($app)->reindexNow();
    same([$b, $a], searchIds($app, 'estante'));
});

test('casos raros: vacío, solo palabras vacías, símbolos, emojis, comillas y «&» no rompen nada', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $search = searchService($app);
    foreach (['', '   ', 'de la', '!!!', '😀', '"', "'", '%', '_', '\\', '<script>', '& &', str_repeat('a', 500), "mesa\0", "\xff\xfe"] as $q) {
        $result = $search->query($q);
        check(is_array($result['ids']) && array_key_exists('confident', $result), 'consulta rara: ' . json_encode($q));
    }
    same([$ids['mesa']], searchIds($app, 'mesa "de" roble &'), 'comillas y «&» alrededor se ignoran');
    same([$ids['mesa']], searchIds($app, "mesa\u{00a0}roble"), 'un espacio duro');
    same([], searchIds($app, '%'), 'un % no es un comodín');
    same([], searchIds($app, '_____'), 'ni un guion bajo');
});

test('limit corta la lista y total dice cuántos había', function (): void {
    $app = searchApp();
    searchShop($app);
    $result = searchService($app)->query('silla', ['limit' => 1]);
    same(1, count($result['ids']));
    same(3, $result['total']);
    same(3, count(searchIds($app, 'silla', ['limit' => 9999])), 'un límite enorme se acota (el tope es 500) y devuelve todo lo que hay');
    same(1, count(searchIds($app, 'silla', ['limit' => 0])), 'un límite absurdo se queda en 1');
});

test('buscar en el idioma de la petición; uno que la tienda no tiene cae en el principal', function (): void {
    $app = searchApp(['i18n.languages' => ['es', 'en']]);
    $id = aifecProduct($app, ['name' => 'Silla roja', 'i18n' => ['en' => ['name' => 'Red chair']]]);
    searchService($app)->index('product', $id);
    $app->setLang('en');
    same([$id], searchIds($app, 'chair'), 'sin decir idioma, el de la petición');
    same([], searchIds($app, 'silla'));
    $app->setLang('es');
    same([$id], searchIds($app, 'silla'));
    same([$id], searchIds($app, 'silla', ['lang' => 'fr']), 'un idioma que la tienda no tiene: el principal');
});
