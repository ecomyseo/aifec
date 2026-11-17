<?php
/**
 * AIFEC — pruebas de lo que rodea a la búsqueda por significado: las exclusiones («que no sea blanca», «not white»), listar solo con
 * los filtros cuando el texto no encuentra nada, las respuestas de la tienda con datos (envío, devoluciones), la frase que explica la
 * propuesta, la forma de /buscar.json y /buscar.md, el registro de cómo acabó cada búsqueda y el resumen del panel. Con una IA de
 * mentira, y cada fila de la tabla del encargo también sin IA.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Search\AiUnderstand;
use Aifec\Modules\Search\QueryLog;

require_once __DIR__ . '/MeaningKit.php';

// ------------------------------------------------------------------ fila 2: «lámpara para escritorio que no sea blanca»

test('fila 2 con IA: la lámpara para el escritorio que no sea blanca lista los flexos que no son blancos', function (): void {
    list($app, $fake) = meaningApp();
    $ids = meaningShop($app);
    $fake->replies['search.understand'] = json_encode([
        'category_id' => $ids['cat']['flexos'], 'exclude' => [['attribute' => 'color', 'value' => 'blanco']], 'text' => 'lampara escritorio',
    ]);
    $result = searchService($app)->ask('lampara para escritorio que no sea blanca');
    same(['Flexo LED negro', 'Flexo articulado'], meaningNames($result['products']), 'los flexos que no son blancos');
    same(2, $result['total']);
    same('exact', $result['answer_kind']);
    same('', $result['understood']['text'], 'el texto que no encontraba nada ya no se busca');
    same(true, $result['understood']['ai']);
    $types = array_map(static function (array $f): string {
        return $f['type'];
    }, $result['understood']['filters']);
    check(in_array('category', $types, true) && in_array('without', $types, true), implode(',', $types));
    contains('flexos', $result['answer']);
    same(1, $fake->askedTask('search.understand'), 'una llamada');
    same(0, $fake->askedTask('search.meaning'), 'y la búsqueda por significado no hizo falta');
    same([], $result['related']);
});

test('fila 2 sin IA: se queda como hoy, sin error: no hay resultados y la exclusión se entiende', function (): void {
    $app = searchApp();
    meaningShop($app);
    $result = searchService($app)->ask('lampara para escritorio que no sea blanca', ['ai' => false]);
    same(0, $result['total']);
    same([], $result['products']);
    same('none', $result['answer_kind']);
    same(['without:blanca'], array_map(static function (array $f): string {
        return $f['key'];
    }, $result['understood']['filters']), 'la exclusión sí se entiende por reglas');
    $page = (new TestClient($app))->get('/buscar?q=' . rawurlencode('lampara para escritorio que no sea blanca'));
    same(200, $page->status);
    contains('data-af-search-nothing', $page->body);
});

// ------------------------------------------------------------------ exclusiones

test('exclusiones por reglas en castellano: «que no sea blanca», «que no sean negras», «excepto», «salvo» y «sin»', function (): void {
    $app = searchApp();
    meaningShop($app);
    $asker = searchService($app)->asker();
    same(['blanca'], $asker->plan('silla que no sea blanca', 'es', false)['restrict']['without']);
    same(['negras'], $asker->plan('sillas que no sean negras', 'es', false)['restrict']['without']);
    same(['blanco'], $asker->plan('flexo excepto blanco', 'es', false)['restrict']['without']);
    same(['gris'], $asker->plan('flexo salvo gris', 'es', false)['restrict']['without']);
    same(['ruedas'], $asker->plan('silla sin ruedas', 'es', false)['restrict']['without'], 'lo de siempre sigue igual');
    same([], $asker->plan('silla que no sea ', 'es', false)['restrict']['without'], 'sin nada detrás no excluye');
});

test('exclusiones por reglas en inglés: «not white», «except», «that is not»', function (): void {
    $app = searchApp();
    meaningShop($app);
    $asker = searchService($app)->asker();
    same(['white'], $asker->plan('desk lamp not white', 'en', false)['restrict']['without']);
    same(['black'], $asker->plan('chair except black', 'en', false)['restrict']['without']);
    same(['white'], $asker->plan('chair that is not white', 'en', false)['restrict']['without']);
    same(['wheels'], $asker->plan('chair without wheels', 'en', false)['restrict']['without']);
});

test('la exclusión por reglas quita de verdad lo que lleva esa palabra', function (): void {
    $app = searchApp();
    meaningShop($app);
    $result = searchService($app)->ask('sillas de oficina que no sean blancas', ['ai' => false]);
    same(['Silla de oficina básica', 'Silla de oficina ergonómica'], meaningNames($result['products']));
    same('exact', $result['answer_kind']);
});

test('exclusiones por la IA: pares atributo/valor del vocabulario; lo que no existe, o lo mismo que se pide, se descarta', function (): void {
    list($app) = meaningApp();
    meaningShop($app);
    $voc = searchService($app)->asker()->vocabulary()->get('es');
    $clean = AiUnderstand::validate([
        'exclude' => [['attribute' => 'color', 'value' => 'blanco'], ['attribute' => 'color', 'value' => 'violeta'], ['attribute' => 'forma', 'value' => 'redonda'], 'x', ['attribute' => 5]],
    ], 'lampara no blanca', $voc);
    same([['attribute' => 'color', 'value' => 'blanco', 'label' => 'Blanco', 'name' => 'Color']], $clean['exclude']);
    $both = AiUnderstand::validate(['attributes' => [['attribute' => 'color', 'value' => 'blanco']], 'exclude' => [['attribute' => 'color', 'value' => 'blanco']]], 'x', $voc);
    same([], $both['exclude'], 'lo que se pide no se excluye a la vez');
    same(['color' => ['blanco']], $both['attrs']);
    same([], AiUnderstand::validate(['text' => 'x'], 'x', $voc)['exclude'], 'sin exclusiones, lista vacía');
});

test('la exclusión de la IA no se repite si las reglas ya la entendieron', function (): void {
    list($app, $fake) = meaningApp();
    $ids = meaningShop($app);
    $fake->replies['search.understand'] = json_encode(['category_id' => $ids['cat']['flexos'], 'exclude' => [['attribute' => 'color', 'value' => 'blanco']]]);
    $result = searchService($app)->ask('lampara para escritorio que no sea blanco');
    $without = array_filter($result['understood']['filters'], static function (array $f): bool {
        return $f['type'] === 'without';
    });
    same(1, count($without), 'una sola ficha de exclusión');
    same(['Flexo LED negro', 'Flexo articulado'], meaningNames($result['products']));
});

// ------------------------------------------------------------------ listar solo con los filtros cuando el texto no encuentra nada

test('si las palabras no dan nada fiable pero la frase dice de qué se habla, se lista con los filtros', function (): void {
    $app = searchApp();
    meaningShop($app);
    $result = searchService($app)->ask('sillas de oficina negras voladoras', ['ai' => false]);
    same(['Silla de oficina básica', 'Silla de oficina ergonómica'], meaningNames($result['products']));
    same(2, $result['total']);
    same('exact', $result['answer_kind']);
    same('', $result['understood']['text'], 'ya no se busca «voladoras»');
    contains('2 resultados para sillas de oficina con color negro', $result['answer'], 'la respuesta habla de lo que se filtró: ' . $result['answer']);
    notContains('voladoras', $result['answer']);
    $page = (new TestClient($app))->get('/buscar?q=' . rawurlencode('sillas de oficina negras voladoras'));
    $cards = askCardNames($page->body);
    sort($cards);
    same(['Silla de oficina básica', 'Silla de oficina ergonómica'], $cards, 'la página enseña lo mismo');
    notContains('data-af-search-nothing', $page->body);
});

test('sin nada que filtrar (solo un orden, un precio o una exclusión) no se lista el catálogo entero', function (): void {
    $app = searchApp();
    meaningShop($app);
    $search = searchService($app);
    same(0, $search->ask('zzzzzz barato', ['ai' => false])['total'], 'solo un orden');
    same(0, $search->ask('zzzzzz por menos de 200 €', ['ai' => false])['total'], 'solo un precio');
    same(0, $search->ask('zzzzzz sin cuero', ['ai' => false])['total'], 'solo una exclusión');
    same(0, $search->ask('zzzzzz en stock', ['ai' => false])['total'], 'solo existencias');
});

test('si el texto da un resultado fiable no se descarta: solo sale lo que lo lleva', function (): void {
    list($app, $fake) = meaningApp();
    meaningShop($app);
    $result = searchService($app)->ask('silla básica negra');
    same(['Silla de oficina básica'], meaningNames($result['products']), 'no las dos negras');
    same(0, count($fake->asked), 'y sin IA');
});

test('quitar un filtro para proponer no deja de lado un texto que no encuentra nada', function (): void {
    $app = searchApp();
    meaningShop($app);
    $result = searchService($app)->ask('zzzzzz negro por menos de 5 €', ['ai' => false]);
    same(0, $result['total']);
    same(null, $result['relaxed'], 'sin «zzzzzz» no hay nada que proponer: no se ignora');
    same('none', $result['answer_kind']);
});

// ------------------------------------------------------------------ fila 3: respuestas de la tienda con datos

test('fila 3: «¿cuánto tarda el envío?» dice el plazo y el «desde» de cada transportista, y el envío gratis', function (): void {
    list($app, $fake) = meaningApp([], ['shipping']);
    meaningShop($app);
    meaningCarriers($app);
    $result = searchService($app)->ask('¿cuánto tarda el envío?');
    same('shop', $result['answer_kind']);
    same('shipping', $result['shop']['topic']);
    contains('Sí, enviamos.', $result['answer']);
    contains("Correos: 2-4 días, desde 4,90\u{a0}€, gratis desde 60\u{a0}€", $result['answer'], 'plazo escrito, tarifa mínima y envío gratis');
    contains("MRW: 1 día, desde 6,50\u{a0}€", $result['answer'], 'plazo por días');
    notContains('Envío desde', $result['answer']);
    same(0, count($fake->asked));
});

test('fila 3: preguntar por un destino que no se sirve no inventa un plazo', function (): void {
    list($app) = meaningApp([], ['shipping']);
    meaningShop($app);
    meaningCarriers($app);
    $result = searchService($app)->ask('¿enviáis a Canarias?');
    same('shop', $result['answer_kind']);
    contains('Ahora mismo no enviamos a Canarias', $result['answer']);
    notContains('Correos', $result['answer']);
});

test('fila 3: sin transportistas no se inventa nada', function (): void {
    list($app) = meaningApp([], ['shipping']);
    meaningShop($app);
    $result = searchService($app)->ask('¿cuánto tarda el envío?', ['ai' => false]);
    same(null, $result['shop'], 'sin datos de envío ni página, no hay respuesta de tienda');
    notContains('Sí, enviamos', $result['answer']);
    $plain = searchApp();
    meaningShop($plain);
    same(null, searchService($plain)->ask('¿cuánto tarda el envío?', ['ai' => false])['shop'], 'ni siquiera sin el módulo de envíos');
});

test('un transportista gratis o sin plazo se dice sin inventar: «gratis» y sin plazo', function (): void {
    list($app) = meaningApp([], ['shipping']);
    meaningShop($app);
    require_once __DIR__ . '/../../shipping/tests/ShippingKit.php';
    $zone = shippingZoneId($app, 'Peninsula');
    $pickup = shippingAddCarrier($app, 'recogida', ['name' => 'Recogida en tienda', 'position' => 1]);
    shippingAddRate($app, $pickup, $zone, 'weight', 0, 0, 0);
    $text = searchService($app)->ask('¿cuánto tarda el envío?')['answer'];
    contains('Recogida en tienda: gratis', $text);
    notContains('desde gratis', $text);
});

test('devoluciones: con el módulo «returns» activo dice el plazo de sus ajustes; ninguna cifra que no salga de ellos', function (): void {
    $app = searchApp(['returns.days' => 14]);
    $app->bind('returns', static function (): stdClass {
        return new stdClass();
    });
    meaningShop($app);
    $result = searchService($app)->ask('devoluciones', ['ai' => false]);
    same('returns', $result['shop']['topic']);
    same('Puedes devolver un producto en 14 días.', $result['shop']['text']);
    $none = searchApp(['returns.days' => 14]);
    meaningShop($none);
    same(null, searchService($none)->ask('devoluciones', ['ai' => false])['shop'], 'sin el módulo, el ajuste suelto no cuenta ni se inventa nada');
});

test('devoluciones sin el módulo: enlaza la página legal de «Envíos y devoluciones» aunque su título no lo diga', function (): void {
    $app = searchApp(['assistant.returns_text' => 'Tienes 30 días para devolver tu pedido.'], ['cms']);
    meaningShop($app);
    $now = $app->clock()->sql();
    $id = $app->db()->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('page_i18n', ['page_id' => $id, 'lang' => 'es', 'title' => 'Información importante', 'blocks' => '[]']);
    $app->slugs()->set('page', $id, 'es', 'informacion-importante');
    $before = searchService($app)->ask('devoluciones', ['ai' => false])['shop'];
    same('', $before['url'], 'todavía no está en los ajustes de las páginas legales');
    $app->settings()->set('cms.legal', ['shipping-returns' => $id]);
    $result = searchService($app)->ask('devoluciones', ['ai' => false]);
    same('Tienes 30 días para devolver tu pedido.', $result['shop']['text']);
    same('/informacion-importante', $result['shop']['url'], 'con su enlace');
    same('/informacion-importante', searchService($app)->ask('envíos', ['ai' => false])['shop']['url'], 'y la de envíos, la misma página');
});

// ------------------------------------------------------------------ fila 4: la frase que explica la propuesta

test('fila 4: «silla de oficina negra por menos de 90 €» dice qué filtro sobra y cuántos hay sin él', function (): void {
    foreach ([true, false] as $withAi) {
        list($app, $fake) = $withAi ? meaningApp() : [searchApp(), null];
        meaningShop($app);
        $result = searchService($app)->ask('silla de oficina negra por menos de 90 €', $withAi ? [] : ['ai' => false]);
        same(0, $result['total']);
        same('relaxed', $result['answer_kind']);
        contains('No hay resultados para silla de oficina negra por menos de 90', $result['answer']);
        contains('Sin «Precio: menos de 90', $result['answer']);
        contains('hay 2.', $result['answer'], 'sin el tope de precio hay dos negras');
        same(2, $result['relaxed']['total']);
        same([], $result['related'], 'con una propuesta que hacer no se mezcla nada por significado');
        if ($fake !== null) {
            same(0, $fake->askedTask('search.meaning'), 'y no se gasta IA en buscar por significado');
        }
    }
});

test('fila 4: con un solo resultado sin el filtro, la frase va en singular de verdad', function (): void {
    $app = searchApp();
    meaningShop($app);
    $result = searchService($app)->ask('flexo gris por menos de 10 €', ['ai' => false]);
    same('relaxed', $result['answer_kind']);
    contains('hay 1.', $result['answer']);
});

// ------------------------------------------------------------------ fila 5: /buscar.json y /buscar.md

test('fila 5: /buscar.json da name, url y image absolutas, price en céntimos, price_formatted, currency, available y summary', function (): void {
    $app = searchApp();
    $ids = meaningShop($app);
    $web = new TestClient($app);
    $data = json_decode($web->get('/buscar.json?q=' . rawurlencode('silla de oficina'))->body, true);
    $byName = [];
    foreach ($data['products'] as $p) {
        $byName[$p['name']] = $p;
    }
    $ergo = $byName['Silla de oficina ergonómica'];
    foreach (['name', 'url', 'image', 'price', 'price_formatted', 'currency', 'available', 'summary'] as $key) {
        check(array_key_exists($key, $ergo), 'falta ' . $key);
    }
    same(15999, $ergo['price']);
    same("159,99\u{a0}€", $ergo['price_formatted']);
    same('EUR', $ergo['currency']);
    same(true, $ergo['available']);
    check(strpos($ergo['url'], 'https://tienda.test/producto/') === 0, $ergo['url']);
    check(is_string($ergo['image']) && strpos($ergo['image'], 'https://tienda.test/') === 0, 'imagen absoluta: ' . var_export($ergo['image'], true));
    check(mb_strlen($ergo['summary'], 'UTF-8') <= 200 && mb_strlen($ergo['summary'], 'UTF-8') > 100, 'resumen de hasta 200 caracteres: ' . mb_strlen($ergo['summary'], 'UTF-8'));
    check(strpos($ergo['summary'], '<') === false, 'en texto llano');
    same(null, $byName['Silla de oficina básica']['image'], 'sin imagen, null');
    same('', $byName['Silla de oficina básica']['summary'], 'sin resumen ni descripción, vacío');
    same(['query', 'lang', 'understood', 'products', 'answer', 'shop', 'total', 'relaxed', 'related', 'answer_kind'], array_keys($data));
    $cojin = json_decode($web->get('/buscar.json?q=' . rawurlencode('cojín lumbar'))->body, true)['products'][0];
    same('Accesorio blando para sentarse con comodidad', $cojin['summary']);
    same($ids['cojin'], $cojin['id']);
});

test('fila 5: lo relacionado lleva la misma forma que los productos, y /buscar.md lo enseña aparte con direcciones completas', function (): void {
    list($app, $fake) = meaningApp();
    meaningShop($app);
    $fake->replies['search.understand'] = '{"text": "espalda"}';
    $fake->replies['search.meaning'] = '{"terms": ["lumbar", "ergonómica"]}';
    $web = new TestClient($app);
    // /buscar.json no llama a la IA: usa lo que la ayuda de la página (significado.json) ya dejó guardado.
    same(true, json_decode($web->get('/buscar/significado.json?q=' . rawurlencode(MEANING_Q), ['x-requested-with' => 'fetch'])->body, true)['better']);
    $asked = count($fake->asked);
    $data = json_decode($web->get('/buscar.json?q=' . rawurlencode(MEANING_Q))->body, true);
    same(2, count($data['related']));
    same($asked, count($fake->asked), '/buscar.json no ha llamado a la IA');
    foreach ($data['related'] as $p) {
        foreach (['name', 'url', 'image', 'price', 'price_formatted', 'currency', 'available', 'summary'] as $key) {
            check(array_key_exists($key, $p), 'falta ' . $key);
        }
        check(strpos($p['url'], 'https://tienda.test/producto/') === 0, $p['url']);
    }
    $md = $web->get('/buscar.md?q=' . rawurlencode(MEANING_Q))->body;
    contains('No tenemos nada con esas palabras. Esto puede servirte:', $md);
    contains('## Productos relacionados', $md);
    contains('](https://tienda.test/producto/', $md);
    check(strpos($md, '## Productos') < strpos($md, '## Productos relacionados'), 'los relacionados van después y aparte');
    $products = substr($md, strpos($md, '## Productos'), strpos($md, '## Productos relacionados') - strpos($md, '## Productos'));
    notContains('Cojín lumbar', $products, 'ni uno entre los productos');
});

// ------------------------------------------------------------------ el registro y el panel

test('el registro apunta cómo acabó cada búsqueda y no guarda nada de quien busca', function (): void {
    list($app, $fake) = meaningApp();
    meaningShop($app);
    $fake->replies['search.understand'] = '{"text": "espalda"}';
    $fake->replies['search.meaning'] = '{"terms": ["lumbar"]}';
    $web = new TestClient($app);
    $web->get('/buscar?q=' . rawurlencode('silla de oficina'));
    $web->get('/buscar?q=' . rawurlencode('silla de oficina negra por menos de 90 €'));
    $web->get('/buscar?q=' . rawurlencode(MEANING_Q));
    // La página la apunta sin la IA; la ayuda que llega después (lo que pide el guion del hueco) corrige cómo acabó.
    $web->get('/buscar/significado.json?q=' . rawurlencode(MEANING_Q), ['x-requested-with' => 'fetch']);
    $fake->replies['search.meaning'] = '{"terms": []}';
    $web->get('/buscar?q=' . rawurlencode('zzzzzz'));
    $web->get('/buscar.json?q=' . rawurlencode('flexo'));
    $stats = searchService($app)->log()->outcomeStats(30);
    same(['exact' => 2, 'relaxed' => 1, 'related' => 1, 'none' => 1], $stats);
    same(['zzzzzz'], array_column(searchService($app)->log()->unanswered(30, 20), 'phrase'));
});

test('el registro: los 20 más repetidos sin nada, las anteriores sin dato y las preguntas de la tienda no cuentan', function (): void {
    $app = searchApp();
    $log = searchService($app)->log();
    for ($i = 1; $i <= 25; ++$i) {
        for ($n = 0; $n < $i; ++$n) {
            $log->record('consulta ' . $i, 'es', 0, true, 'none');
        }
    }
    $log->record('antigua', 'es', 0, true);
    $log->record('devoluciones', 'es', 0, true, 'shop');
    $log->record('rara', 'es', 0, true, 'inventado');
    $top = $log->unanswered(30, 20);
    same(20, count($top));
    same('consulta 25', $top[0]['phrase']);
    same(25, $top[0]['hits']);
    same('consulta 6', $top[19]['phrase'], 'solo las 20 más repetidas');
    $stats = $log->outcomeStats(30);
    same(325, $stats['none'], 'la suma de 1 a 25');
    same(0, $stats['exact'] + $stats['related'] + $stats['relaxed']);
    same('', (string) $app->db()->value('SELECT outcome FROM {search_log} WHERE phrase = ?', ['rara']), 'un valor que no es de la lista se guarda vacío');
    $app->clock()->advance(31 * 86400);
    same(0, $log->outcomeStats(30)['none'], 'los últimos 30 días');
});

test('el panel enseña cómo acabaron las búsquedas, las 20 sin nada y el aviso de los vectores si la IA está encendida', function (): void {
    list($app, $fake) = meaningApp();
    meaningShop($app);
    $log = searchService($app)->log();
    $log->record('lumbar', 'es', 0, true, 'related');
    $log->record('silla', 'es', 3, false, 'exact');
    $log->record('silla', 'es', 3, false, 'exact');
    $log->record('xyzzy', 'es', 0, true, 'none');
    $web = adminClient($app);
    $r = $web->get('/admin/search');
    same(200, $r->status);
    contains('data-af-search-outcomes', $r->body);
    preg_match('#data-af-search-outcome="exact">(\d+)<#', $r->body, $m);
    same('2', $m[1]);
    preg_match('#data-af-search-outcome="related">(\d+)<#', $r->body, $m);
    same('1', $m[1]);
    contains('data-af-search-unanswered', $r->body);
    contains('xyzzy', $r->body);
    contains('Enciende «Ordenar también por significado» para encontrar por significado.', $r->body, 'IA encendida y vectores apagados: aviso');
    contains('/admin/settings/search', $r->body, 'con su enlace');
    $app->settings()->set('search.vectors', true);
    notContains('data-af-search-meaning-hint', $web->get('/admin/search')->body, 'con los vectores encendidos, sin aviso');
    $app->settings()->set('search.vectors', false);
    $fake->on = false;
    notContains('data-af-search-meaning-hint', $web->get('/admin/search')->body, 'con la IA apagada, sin aviso');
    $plain = searchApp();
    notContains('data-af-search-meaning-hint', adminClient($plain)->get('/admin/search')->body, 'sin el servicio de IA, sin aviso');
});

test('el panel sin búsquedas no falla y no enseña la lista de las sin nada', function (): void {
    $app = searchApp();
    $r = adminClient($app)->get('/admin/search');
    same(200, $r->status);
    contains('data-af-search-outcomes', $r->body);
    notContains('data-af-search-unanswered', $r->body);
});

// ------------------------------------------------------------------ ajustes y traducciones

test('el ajuste del umbral existe, con su valor de serie, y todo lo nuevo está traducido', function (): void {
    $settings = require __DIR__ . '/../settings.php';
    $field = $settings['tabs']['srai']['fields']['search.meaning_threshold'];
    same(0.6, $field['default']);
    same(0, $field['min']);
    same(1, $field['max']);
    $es = require __DIR__ . '/../lang/es.php';
    foreach ([
        'We have nothing with those words. This may help you:', 'Related products', 'from {price}', 'free from {price}', '{min}-{max} days', '{n} day|{n} days',
        'There are no results for {what}. Without «{filter}» there is {n}.|There are no results for {what}. Without «{filter}» there are {n}.',
        'Turn on «Rank also by meaning» to find things by meaning.', 'Exact results', 'Related by meaning', 'Searches that ended with nothing',
        $field['label'], $field['hint'],
    ] as $key) {
        check(isset($es[$key]) && $es[$key] !== '' && $es[$key] !== $key, 'sin traducir: ' . $key);
    }
    same(false, isset($es['Shipping from {price}.']), 'lo que ya no se usa no se deja');
});
