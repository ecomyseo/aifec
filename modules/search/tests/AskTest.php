<?php
/**
 * AIFEC — pruebas del buscador que entiende frases de punta a punta: lo que devuelve Search::ask() (lo entendido, los productos, la
 * respuesta y el total), que el precio se compare con el que cobra la tienda (con o sin impuestos), medidas y exclusiones,
 * preguntas sobre la tienda con su enlace, la página con sus fichas (y que quitar una cambie el resultado), la propuesta cuando no
 * sale nada, que /buscar.json y /buscar.md den lo mismo que la página, y las consultas que cuestan la página y las sugerencias.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

test('ask(): lo entendido, los productos, la respuesta y el total', function (): void {
    $app = searchApp();
    askShop($app);
    $result = searchService($app)->ask('sillas de oficina negras por menos de 200 €', ['ai' => false]);
    same(['query', 'lang', 'understood', 'products', 'answer', 'shop', 'total', 'relaxed', 'related', 'answer_kind'], array_keys($result));
    same(['filters', 'text', 'corrected', 'ai'], array_keys($result['understood']));
    same([], $result['related'], 'con resultados exactos no hay nada por significado');
    same('exact', $result['answer_kind']);
    same('sillas de oficina negras por menos de 200 €', $result['query']);
    same(['Silla de oficina agotada', 'Silla de oficina básica', 'Silla de oficina ergonómica'], askSorted($result), 'las tres negras de menos de 200 €');
    same(3, $result['total']);
    same(null, $result['shop']);
    same(null, $result['relaxed']);
    same(false, $result['understood']['ai']);
    $keys = array_map(static function (array $f): string {
        return $f['key'];
    }, $result['understood']['filters']);
    same(['price', 'attr:color', 'category'], $keys);
    check(!isset($result['understood']['filters'][0]['src']), 'las palabras de dónde salió cada ficha no salen al exterior');
    same('sillas de oficina negras', $result['understood']['filters'][0]['remove_query'], 'cada ficha dice cómo quedaría la frase sin ella');
});

test('ask(): cada producto lleva id, nombre, precio en céntimos y con formato, disponibilidad, dirección, imagen y lo que casa', function (): void {
    $app = searchApp();
    askShop($app);
    $result = searchService($app)->ask('sillas de oficina negras por menos de 200 €', ['ai' => false]);
    $byName = [];
    foreach ($result['products'] as $p) {
        $byName[$p['name']] = $p;
    }
    $ergo = $byName['Silla de oficina ergonómica'];
    same(['id', 'name', 'price', 'price_formatted', 'currency', 'on_sale', 'available', 'availability', 'url', 'image', 'summary', 'matches'], array_keys($ergo));
    same(15999, $ergo['price'], 'en céntimos, un entero');
    check(is_int($ergo['price']));
    same("159,99\u{a0}€", $ergo['price_formatted']);
    same('EUR', $ergo['currency']);
    same(['status', 'label'], array_keys($ergo['availability']));
    same('in_stock', $ergo['availability']['status']);
    same('out_of_stock', $byName['Silla de oficina agotada']['availability']['status']);
    same(true, $ergo['available']);
    same(false, $byName['Silla de oficina agotada']['available']);
    check(strpos($ergo['url'], 'https://tienda.test/producto/') === 0, 'la dirección, completa: ' . $ergo['url']);
    same([['name' => 'Color', 'value' => 'Negro']], $ergo['matches'], 'el color que se pidió, en la ficha');
});

test('ask(): la respuesta directa dice qué se entendió y cuántos hay', function (): void {
    $app = searchApp();
    askShop($app);
    $result = searchService($app)->ask('sillas de oficina negras por menos de 200 €', ['ai' => false]);
    same("3 resultados para sillas de oficina negras por menos de 200\u{a0}€", $result['answer']);
    $one = searchService($app)->ask('sillón rebajado', ['ai' => false]);
    same(1, $one['total']);
    same('1 resultado para sillón en oferta', $one['answer'], 'también dice lo que se entendió de la oferta');
    $plain = searchService($app)->ask('lámpara', ['ai' => false]);
    same('1 resultado para lámpara', $plain['answer']);
    $none = searchService($app)->ask('zzzzzz', ['ai' => false]);
    contains('No hemos encontrado nada que encaje con «zzzzzz»', $none['answer']);
    same(0, $none['total']);
});

test('barato ordena por precio; en stock quita lo agotado; rebajado deja lo que está en oferta', function (): void {
    $app = searchApp();
    askShop($app);
    $search = searchService($app);
    $cheap = $search->ask('sillas de oficina baratas', ['ai' => false]);
    same(['Silla de oficina agotada', 'Silla de oficina básica', 'Silla de oficina roja', 'Silla de oficina ergonómica', 'Silla de oficina premium'], askNames($cheap));
    $stock = $search->ask('silla de oficina en stock', ['ai' => false]);
    same(4, $stock['total'], 'la agotada no sale');
    notContains('Silla de oficina agotada', implode('|', askNames($stock)));
    same(['Sillón relax'], askNames($search->ask('rebajado', ['ai' => false])));
    $best = $search->ask('sillas de oficina más vendidas', ['ai' => false]);
    same('Silla de oficina básica', askNames($best)[0], 'la que más se vende, primero');
    $rated = $search->ask('sillas de oficina mejor valoradas', ['ai' => false]);
    same('Silla de oficina premium', askNames($rated)[0], 'la única valorada, primero');
});

test('medidas: el atributo numérico con su unidad, con igual, tope y mínimo', function (): void {
    $app = searchApp();
    askShop($app);
    $search = searchService($app);
    same(['Mesa de roble'], askNames($search->ask('mesa de 180 cm', ['ai' => false])));
    same(['Mesa auxiliar'], askNames($search->ask('mesa hasta 100 cm', ['ai' => false])));
    same(['Mesa de roble'], askNames($search->ask('mesa más de 100 centímetros', ['ai' => false])));
    same(['Silla de oficina básica'], askSorted($search->ask('silla de oficina hasta 8 kilos', ['ai' => false])));
    same([], askNames($search->ask('mesa de 500 cm', ['ai' => false])), 'ninguna mesa mide eso');
});

test('«sin cuero» saca de los resultados lo que lleve esa palabra', function (): void {
    $app = searchApp();
    askShop($app);
    $result = searchService($app)->ask('silla de oficina sin cuero', ['ai' => false]);
    same(['Silla de oficina agotada', 'Silla de oficina básica', 'Silla de oficina ergonómica', 'Silla de oficina roja'], askSorted($result));
    $with = searchService($app)->ask('silla de oficina', ['ai' => false]);
    same(5, $with['total']);
});

test('el precio se compara con el que cobra la tienda: precios con impuestos, enseñados con impuestos', function (): void {
    $app = searchApp();
    $a = aifecProduct($app, ['name' => 'Estante justo', 'price' => 20000]);
    $b = aifecProduct($app, ['name' => 'Estante pasado', 'price' => 20001]);
    searchService($app)->reindexNow();
    $result = searchService($app)->ask('estante menos de 200 €', ['ai' => false]);
    same(['Estante justo'], askNames($result), '200,00 € entra, 200,01 € no');
    same(20000, $result['products'][0]['price']);
    unset($a, $b);
});

test('el precio se compara con el que cobra la tienda: precios sin impuestos, enseñados con impuestos', function (): void {
    $app = searchApp(['tax.prices_include_tax' => false, 'tax.display_with_tax' => true]);
    aifecProduct($app, ['name' => 'Estante justo', 'price' => 16529]);
    aifecProduct($app, ['name' => 'Estante pasado', 'price' => 16530]);
    searchService($app)->reindexNow();
    $result = searchService($app)->ask('estante menos de 200 €', ['ai' => false]);
    same(['Estante justo'], askNames($result), 'lo escrito es la base, lo que cobra la tienda lleva el 21 %');
    same(20000, $result['products'][0]['price'], 'y lo que se enseña es el precio con impuestos');
    $more = searchService($app)->ask('estante más de 200 €', ['ai' => false]);
    same(['Estante justo', 'Estante pasado'], askSorted($more), '200,00 € cuenta como «más de 200»: es el corte exacto');
    same(['Estante pasado'], askNames(searchService($app)->ask('estante entre 200,01 y 210 €', ['ai' => false])));
});

test('el precio se compara con el que cobra la tienda: precios con impuestos, enseñados sin impuestos', function (): void {
    $app = searchApp(['tax.prices_include_tax' => true, 'tax.display_with_tax' => false]);
    aifecProduct($app, ['name' => 'Estante justo', 'price' => 24200]);
    aifecProduct($app, ['name' => 'Estante pasado', 'price' => 24201]);
    searchService($app)->reindexNow();
    $result = searchService($app)->ask('estante menos de 200 €', ['ai' => false]);
    same(['Estante justo'], askNames($result), '242,00 con impuestos son 200,00 sin ellos');
    same(20000, $result['products'][0]['price']);
});

test('preguntas sobre la tienda: la respuesta sale de sus ajustes y de sus páginas, con el enlace', function (): void {
    $app = searchApp(['assistant.returns_text' => 'Tienes 30 días para devolver tu pedido.', 'store.email' => 'tienda@ejemplo.test'], ['cms']);
    askShop($app);
    $now = $app->clock()->sql();
    foreach (['Política de devoluciones' => 'devoluciones', 'Envíos y entregas' => 'envios'] as $title => $slug) {
        $id = $app->db()->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $app->db()->insert('page_i18n', ['page_id' => $id, 'lang' => 'es', 'title' => $title, 'blocks' => '[]']);
        $app->slugs()->set('page', $id, 'es', $slug);
    }
    $search = searchService($app);
    $returns = $search->ask('devoluciones', ['ai' => false]);
    same('returns', $returns['shop']['topic']);
    same('Tienes 30 días para devolver tu pedido.', $returns['shop']['text']);
    same('/devoluciones', $returns['shop']['url'], 'con el enlace a su página');
    same('Tienes 30 días para devolver tu pedido.', $returns['answer']);
    $question = $search->ask('¿cuál es vuestra política de devoluciones?', ['ai' => false]);
    same('returns', $question['shop']['topic'], 'con signo de interrogación y alguna palabra suelta');
    $shipping = $search->ask('¿enviáis a Canarias?', ['ai' => false]);
    same('shipping', $shipping['shop']['topic']);
    same('/envios', $shipping['shop']['url']);
    $contact = $search->ask('teléfono y correo', ['ai' => false]);
    same('contact', $contact['shop']['topic']);
    contains('tienda@ejemplo.test', $contact['shop']['text']);
    // Una frase con productos no es una pregunta sobre la tienda.
    same(null, $search->ask('sillas con envío gratis', ['ai' => false])['shop']);
    same(null, $search->ask('silla de oficina negra', ['ai' => false])['shop']);
    // Una pregunta sin dato ni página no se inventa.
    $bare = searchApp();
    askShop($bare);
    same(null, searchService($bare)->ask('devoluciones', ['ai' => false])['shop']);
});

test('sin resultados: se propone quitar el filtro que más estorba y se enseña lo más parecido', function (): void {
    $app = searchApp();
    askShop($app);
    $result = searchService($app)->ask('sillas de oficina negras por menos de 5 €', ['ai' => false]);
    same(0, $result['total']);
    same([], $result['products']);
    check($result['relaxed'] !== null, 'hay una propuesta');
    same('price', $result['relaxed']['key']);
    same('sillas de oficina negras', $result['relaxed']['remove_query']);
    same(4, $result['relaxed']['total'], 'sin el precio salen cuatro');
    check(count($result['relaxed']['products']) >= 1 && count($result['relaxed']['products']) <= 4, 'y se enseñan algunas');
    // Sin filtros que quitar, no hay propuesta.
    same(null, searchService($app)->ask('zzzzzz', ['ai' => false])['relaxed']);
});

test('la página enseña las fichas de lo entendido y la respuesta directa', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=' . rawurlencode('sillas de oficina negras por menos de 200 €'));
    same(200, $r->status);
    contains('data-af-search-chip="price"', $r->body);
    contains('data-af-search-chip="attr:color"', $r->body);
    contains('data-af-search-chip="category"', $r->body);
    contains('Precio: menos de 200', $r->body);
    contains('Color: Negro', $r->body);
    contains('data-af-search-answer', $r->body);
    contains('3 resultados para', $r->body);
    same(['Silla de oficina ergonómica', 'Silla de oficina básica', 'Silla de oficina agotada'], askCardNames($r->body));
    same(1, substr_count($r->body, '<h1'));
    same(0, $r->cacheTtl);
});

test('al quitar una ficha de la página cambia el resultado', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $q = 'sillas de oficina negras por menos de 200 €';
    $before = $web->get('/buscar?q=' . rawurlencode($q));
    same(3, count(askCardNames($before->body)));
    $remove = askChipUrl($before->body, 'price');
    check($remove !== '', 'la ficha del precio tiene su enlace');
    $after = $web->get($remove);
    same(200, $after->status);
    $names = askCardNames($after->body);
    same(4, count($names), 'sin el precio, también sale la premium: ' . implode(', ', $names));
    check(in_array('Silla de oficina premium', $names, true));
    notContains('data-af-search-chip="price"', $after->body, 'y la ficha ya no está');
    $noColor = $web->get(askChipUrl($before->body, 'attr:color'));
    check(in_array('Silla de oficina roja', askCardNames($noColor->body), true) || strpos($noColor->body, 'Silla de oficina roja') !== false, 'sin el color sale también la roja que cuesta menos de 200 €');
});

test('sin resultados la página dice qué se entendió y propone quitar el filtro', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=' . rawurlencode('sillas de oficina negras por menos de 5 €'));
    same(200, $r->status);
    contains('data-af-search-nothing', $r->body);
    contains('data-af-search-relaxed="price"', $r->body);
    contains('data-af-search-chip="price"', $r->body, 'sigue enseñando lo entendido');
    contains('Quitar «Precio: menos de 5', $r->body);
    preg_match('#data-af-search-relaxed.*?href="([^"]*)"#s', $r->body, $m);
    $again = $web->get(html_entity_decode($m[1]));
    same(4, count(askCardNames($again->body)), 'el enlace de la propuesta lleva a los resultados sin ese filtro');
});

test('/buscar.json y /buscar.md dan lo mismo que la página, con noindex', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $q = 'sillas de oficina negras por menos de 200 €';
    $page = askCardNames($web->get('/buscar?q=' . rawurlencode($q))->body);
    $json = $web->get('/buscar.json?q=' . rawurlencode($q));
    same(200, $json->status);
    contains('json', $json->contentType());
    same('noindex', $json->headers['X-Robots-Tag']);
    $data = json_decode($json->body, true);
    same(['query', 'lang', 'understood', 'products', 'answer', 'shop', 'total', 'relaxed', 'related', 'answer_kind'], array_keys($data));
    same($page, array_map(static function (array $p): string {
        return $p['name'];
    }, $data['products']), 'los mismos productos y en el mismo orden que la página');
    same(3, $data['total']);
    $api = searchService($app)->ask($q, ['ai' => false]);
    same($api['products'], $data['products'], 'y lo mismo que Search::ask()');
    same($api['answer'], $data['answer']);
    $md = $web->get('/buscar.md?q=' . rawurlencode($q));
    same(200, $md->status);
    same('text/plain; charset=UTF-8', $md->contentType());
    same('noindex', $md->headers['X-Robots-Tag']);
    contains('# Resultados para sillas de oficina negras', $md->body);
    contains('3 resultados para', $md->body);
    foreach ($page as $name) {
        contains($name, $md->body);
    }
    contains('](https://tienda.test/producto/', $md->body, 'direcciones completas');
    contains('- Color: Negro', $md->body, 'lo que casa con lo pedido');
    same(200, $web->get('/buscar.json?q=')->status, 'sin frase tampoco falla');
    same([], json_decode($web->get('/buscar.json?q=')->body, true)['products']);
    $limit = json_decode($web->get('/buscar.json?q=' . rawurlencode('silla de oficina') . '&limit=2')->body, true);
    same(2, count($limit['products']), 'limit');
    same(5, $limit['total'], 'y el total sigue siendo el de verdad');
});

test('«Preguntar al asistente» solo sale si el sitio tiene asistente', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    notContains('data-af-search-ask', $web->get('/buscar?q=silla')->body);
    $with = searchApp([], ['assistant']);
    askShop($with);
    $page = (new TestClient($with))->get('/buscar?q=' . rawurlencode('silla roja'));
    contains('data-af-search-ask="silla roja"', $page->body, 'con la frase puesta');
    $nothing = (new TestClient($with))->get('/buscar?q=zzzzzz');
    contains('data-af-search-ask="zzzzzz"', $nothing->body, 'también cuando no sale nada');
});

test('la página de resultados cuesta pocas consultas, y las sugerencias no más de tres', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $url = '/buscar?q=' . rawurlencode('sillas de oficina negras por menos de 200 €');
    $web->get($url); // la primera vez calienta las cachés, como en una tienda en marcha
    $before = $app->db()->queries;
    $r = $web->get($url);
    $page = $app->db()->queries - $before;
    same(200, $r->status);
    echo 'CONSULTAS: la página de resultados cuesta ' . $page . " consultas con las cachés calientes\n";
    check($page <= 12, 'la página de resultados no pasa de 12 consultas y cuesta ' . $page);
    $s = $web->get('/buscar/sugerir.json?q=sil');
    same(200, $s->status);
    check(count(json_decode($s->body, true)['items']) > 0, 'y sugieren algo');
    $search = searchService($app);
    $search->suggest('mes');
    $before = $app->db()->queries;
    $items = $search->suggest('sil');
    $suggest = $app->db()->queries - $before;
    check(count($items) > 0);
    echo 'CONSULTAS: las sugerencias cuestan ' . $suggest . " consultas con las cachés calientes\n";
    check($suggest <= 3, 'las sugerencias no pasan de 3 consultas y cuestan ' . $suggest);
    $first = $app->db()->queries;
    $web->get('/buscar/sugerir.json?q=sil');
    echo 'CONSULTAS: con la petición HTTP entera, ' . ($app->db()->queries - $first) . " (incluye el arranque de la tienda)\n";
});

test('el panel cuenta qué filtros se entienden, sin guardar la frase ni quién busca', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $web->get('/buscar?q=' . rawurlencode('sillas de oficina negras por menos de 200 €'));
    $web->get('/buscar?q=' . rawurlencode('sillón rebajado'));
    $stats = searchService($app)->log()->filterStats(7);
    same(1, $stats['price']);
    same(1, $stats['attribute']);
    same(1, $stats['category']);
    same(1, $stats['sale']);
    foreach ($app->db()->select('SELECT * FROM {search_filters}') as $row) {
        same(['id', 'day', 'lang', 'kind', 'hits'], array_keys($row), 'solo el tipo y las veces: ninguna frase ni persona');
    }
    $admin = adminClient($app)->get('/admin/search');
    same(200, $admin->status);
    contains('Entendido', $admin->body);
});

test('una búsqueda que es solo texto sigue siendo la de siempre: relevancia, sin fichas', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=lampara');
    notContains('data-af-search-chip', $r->body);
    same(['Lámpara de pie'], askCardNames($r->body));
    contains('1 resultado para lampara', $r->body, 'con las palabras tal como las escribió quien busca');
    $typo = $web->get('/buscar?q=' . rawurlencode('sila negra'));
    contains('Mostrando resultados de silla', $typo->body, 'la errata sigue corrigiéndose, y avisando');
    contains('data-af-search-chip="attr:color"', $typo->body);
});

test('lo que escribe quien busca se escapa en las fichas y en el texto', function (): void {
    $app = searchApp();
    askShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=' . rawurlencode('<script>alert(1)</script> negra por menos de 200'));
    same(200, $r->status);
    notContains('<script>alert(1)</script>', $r->body);
    $json = $web->get('/buscar.json?q=' . rawurlencode('"><img src=x> negra'));
    same(200, $json->status);
    check(is_array(json_decode($json->body, true)));
});
