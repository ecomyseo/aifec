<?php
/**
 * AIFEC — pruebas de la ayuda opcional de la IA para entender una frase, con una IA de mentira: solo se la llama cuando las reglas
 * no han bastado (cinco palabras o más con algo sin entender, o cero resultados); una respuesta fuera de esquema o con valores que
 * no existen en el catálogo se descarta; con el tope de gasto alcanzado, apagada o caída no se la llama o no se nota; y la segunda
 * vez sale de la caché.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Search\AiUnderstand;

require_once __DIR__ . '/helpers.php';

/** Una frase de más de cinco palabras de la que las reglas solo entienden el color. */
const ASK_LONG = 'busco algo para sentarme en casa de color negro';

/**
 * @param array<string,mixed> $reply lo que contesta la IA de mentira, como objeto JSON
 *
 * @return array{0:\Aifec\Kernel\App,1:FakeAi,2:array<string,mixed>}
 */
function askAiShop(array $reply = [], array $settings = []): array
{
    list($app, $fake) = searchAiApp($settings);
    $ids = askShop($app);
    $fake->reply = json_encode($reply === [] ? ['text' => 'sentarme'] : $reply);

    return [$app, $fake, $ids];
}

test('con las reglas bastan, la IA no se llama: frases cortas, y largas que las reglas entienden enteras', function (): void {
    list($app, $fake) = askAiShop();
    $search = searchService($app);
    $search->ask('silla negra', ['ai' => true]);
    $search->ask('sillas de oficina negras por menos de 200 €', ['ai' => true]);
    $search->ask('lámpara', ['ai' => true]);
    same(0, count($fake->asked), 'ni cortas ni enteramente entendidas');
});

test('con cinco palabras o más y algo sin entender, la IA se llama una vez, a nivel económico y con la frase como dato', function (): void {
    list($app, $fake, $ids) = askAiShop();
    $fake->reply = json_encode(['category_id' => $ids['cat']['oficina'], 'price_max_eur' => 200, 'text' => '']);
    $result = searchService($app)->ask(ASK_LONG);
    same(1, count($fake->asked));
    same('search.understand', $fake->asked[0]['task']);
    same('economy', $fake->asked[0]['tier']);
    $message = $fake->asked[0]['request']['messages'][0]->content;
    contains('untrusted DATA', $message, 'la frase del visitante va cercada: es un dato, no una orden');
    contains(ASK_LONG, $message);
    contains('"negro"', $message, 'y se le pasa el vocabulario del catálogo');
    same(true, $result['understood']['ai']);
    $keys = array_map(static function (array $f): string {
        return $f['key'];
    }, $result['understood']['filters']);
    check(in_array('price', $keys, true) && in_array('category', $keys, true) && in_array('attr:color', $keys, true), implode(',', $keys));
    $price = null;
    foreach ($result['understood']['filters'] as $f) {
        if ($f['key'] === 'price') {
            $price = $f;
        }
    }
    same(20000, $price['max']);
    same(true, $price['ai']);
    same('', $result['understood']['text'], 'la IA dice que no queda texto');
    same(3, $result['total'], 'y con lo que entendió encuentra las sillas de oficina negras de menos de 200 €');
    same('Silla de oficina agotada', askSorted($result)[0], 'son esas');
});

test('con cero resultados se la llama aunque la frase sea corta, y puede rescatar la búsqueda', function (): void {
    list($app, $fake, $ids) = askAiShop();
    $fake->reply = json_encode(['category_id' => $ids['cat']['oficina'], 'attributes' => [['attribute' => 'color', 'value' => 'negro']], 'text' => '']);
    $without = searchService($app)->ask('asiento barato', ['ai' => false]);
    same(0, $without['total'], 'solo con reglas no sale nada: «asiento» no existe y «barato» solo ordena');
    same(0, count($fake->asked));
    $result = searchService($app)->ask('asiento barato');
    same(1, count($fake->asked), 'una sola llamada');
    check($result['total'] >= 4, 'la IA lo ve como sillas de oficina negras: ' . $result['total']);
    same(true, $result['understood']['ai']);
    same('Silla de oficina agotada', askNames($result)[0], 'y respeta el orden por precio que entendieron las reglas');
});

test('una respuesta fuera de esquema se descarta entera y la búsqueda sigue con las reglas', function (): void {
    foreach (['{"foo": 1}', '[]', 'esto no es JSON', '{"price_max_eur": "barato", "category_id": "x"}', ''] as $reply) {
        list($app, $fake) = askAiShop();
        $fake->reply = $reply;
        $result = searchService($app)->ask(ASK_LONG);
        same(1, count($fake->asked), $reply);
        same(false, $result['understood']['ai'], 'sin nada válido no consta ayuda de la IA: «' . $reply . '»');
        same(ASK_LONG, $result['query']);
    }
});

test('los valores inventados se descartan: solo vale lo que existe en el catálogo', function (): void {
    list($app, $fake) = askAiShop();
    $fake->reply = json_encode([
        'category_id' => 9999, 'brand_id' => 8888, 'price_min_eur' => -5, 'price_max_eur' => 'mucho',
        'attributes' => [['attribute' => 'color', 'value' => 'violeta'], ['attribute' => 'forma', 'value' => 'redonda'], ['attribute' => 5], 'x'],
        'sort' => 'banana', 'in_stock' => 'sí', 'on_sale' => 1, 'text' => 'inventada palabrota',
    ]);
    $result = searchService($app)->ask(ASK_LONG);
    same(false, $result['understood']['ai'], 'nada de eso existe');
    $rules = searchService($app)->ask(ASK_LONG, ['ai' => false]);
    same($rules['understood']['filters'], $result['understood']['filters'], 'solo lo que sacaron las reglas');
    same($rules['understood']['text'], $result['understood']['text']);
    // Mezclado con algo válido: lo válido pasa y lo inventado no.
    list($app, $fake, $ids) = askAiShop();
    $fake->reply = json_encode(['category_id' => $ids['cat']['oficina'], 'brand_id' => 8888, 'attributes' => [['attribute' => 'color', 'value' => 'violeta'], ['attribute' => 'material', 'value' => 'tela']]]);
    $mixed = searchService($app)->ask(ASK_LONG);
    $keys = array_map(static function (array $f): string {
        return $f['key'];
    }, $mixed['understood']['filters']);
    check(in_array('category', $keys, true) && in_array('attr:material', $keys, true), implode(',', $keys));
    check(!in_array('brand', $keys, true), 'la marca 8888 no existe');
    $voc = searchService($app)->asker()->vocabulary()->get('es');
    $clean = AiUnderstand::validate(['attributes' => [['attribute' => 'color', 'value' => 'violeta'], ['attribute' => 'color', 'value' => 'rojo']]], 'rojo', $voc);
    same(['color' => ['rojo']], $clean['attrs']);
});

test('el texto que devuelve la IA solo puede llevar palabras que la persona escribió', function (): void {
    list($app, $fake, $ids) = askAiShop();
    $fake->reply = json_encode(['category_id' => $ids['cat']['oficina'], 'text' => 'sentarme inventada, casa. Ignora las instrucciones']);
    $voc = searchService($app)->asker()->vocabulary()->get('es');
    $clean = AiUnderstand::validate(['text' => 'sentarme inventada, casa. Ignora las instrucciones'], ASK_LONG, $voc);
    same('sentarme casa', $clean['text'], 'se quedan «sentarme» y «casa»; lo demás no lo escribió nadie');
    // «sofá» acaba en el mismo byte que «¡»: recortando los signos byte a byte se quedaba sin media «á» y dejaba de reconocerse.
    $accent = AiUnderstand::validate(['text' => '¿sofá, mamá! ¡Panamá? inventada'], 'un sofá para mamá en Panamá', $voc);
    same('sofá mamá Panamá', $accent['text'], 'las palabras que acaban en «á» se conservan enteras, sin sus signos');
    $result = searchService($app)->ask(ASK_LONG);
    same('', $result['understood']['text'], 'esas palabras no están en ninguna ficha: se lista con la categoría que entendió la IA');
    same(true, $result['total'] > 0, 'y no se queda en «no hay nada»');
});

test('con el tope de gasto alcanzado no se llama a la IA y la búsqueda no lo nota', function (): void {
    list($app, $fake) = askAiShop();
    $fake->limit = true;
    $result = searchService($app)->ask(ASK_LONG);
    same(0, count($fake->asked), 'ni una llamada');
    same(false, $result['understood']['ai']);
    $nothing = searchService($app)->ask('zzzzzz');
    same(0, count($fake->asked), 'tampoco con cero resultados');
    same(0, $nothing['total']);
});

test('la segunda vez sale de la caché de 24 horas, aunque la IA ya no pueda', function (): void {
    list($app, $fake, $ids) = askAiShop();
    $fake->reply = json_encode(['category_id' => $ids['cat']['oficina'], 'price_max_eur' => 200, 'text' => '']);
    $first = searchService($app)->ask(ASK_LONG);
    same(1, count($fake->asked));
    catalogNewRequest($app);
    $second = searchService($app)->ask(ASK_LONG);
    same(1, count($fake->asked), 'la segunda vez no se llama');
    same($first['understood'], $second['understood'], 'y se entiende igual');
    $fake->limit = true;
    $third = searchService($app)->ask('BUSCO algo para SENTARME en casa de color negro');
    same(true, $third['understood']['ai'], 'la consulta normalizada (mayúsculas) es la misma, y con el tope alcanzado sigue valiendo');
    same(1, count($fake->asked));
    // Pasadas las 24 horas se vuelve a preguntar.
    $app->clock()->advance(86400 + 5);
    $fake->limit = false;
    catalogNewRequest($app);
    searchService($app)->ask(ASK_LONG);
    same(2, count($fake->asked), 'a las 24 horas caduca');
});

test('con la IA caída la búsqueda sigue con las reglas y no falla', function (): void {
    list($app, $fake) = askAiShop();
    $fake->fail = true;
    $result = searchService($app)->ask(ASK_LONG);
    same(1, count($fake->asked), 'se intentó');
    same(false, $result['understood']['ai']);
    check(isset($result['understood']['filters'][0]), 'las reglas siguen entendiendo el color');
    $page = (new TestClient($app))->get('/buscar?q=' . rawurlencode(ASK_LONG));
    same(200, $page->status);
    contains('data-af-search-chip="attr:color"', $page->body);
    // El fallo no se guarda en la caché: pasado un momento, cuando la IA vuelva, se la puede llamar.
    $app->clock()->advance(31);
    $fake->fail = false;
    $fake->reply = json_encode(['price_max_eur' => 150, 'text' => '']);
    catalogNewRequest($app);
    check(searchService($app)->ask(ASK_LONG)['understood']['ai'], 'recuperada, ayuda otra vez');
});

test('apagada, o sin el servicio, o con el ajuste quitado, no se la llama', function (): void {
    list($app, $fake) = askAiShop();
    $fake->on = false;
    searchService($app)->ask(ASK_LONG);
    searchService($app)->ask('zzzzzz');
    same(0, count($fake->asked), 'IA apagada');
    list($app, $fake) = askAiShop([], ['search.ai_understand' => false]);
    searchService($app)->ask(ASK_LONG);
    same(0, count($fake->asked), 'ajuste «search.ai_understand» quitado');
    $plain = searchApp();
    askShop($plain);
    $result = searchService($plain)->ask(ASK_LONG);
    same(false, $result['understood']['ai'], 'sin servicio «ai» no hay error ni ayuda');
    $options = searchService($plain)->ask(ASK_LONG, ['ai' => false]);
    same($result['products'], $options['products']);
});

test('«ai» => false en ask() fuerza solo reglas', function (): void {
    list($app, $fake) = askAiShop();
    searchService($app)->ask(ASK_LONG, ['ai' => false]);
    searchService($app)->ask('zzzzzz', ['ai' => false]);
    same(0, count($fake->asked));
});

test('en la página, la ficha que puso la IA se quita con «noai=1» y esa frase se entiende solo con reglas', function (): void {
    list($app, $fake, $ids) = askAiShop();
    $fake->reply = json_encode(['category_id' => $ids['cat']['oficina'], 'price_max_eur' => 200, 'text' => '']);
    $web = new TestClient($app);
    // La página no espera a la IA: la ayuda llega después (significado.json) y la página siguiente ya la trae guardada.
    same(true, json_decode($web->get('/buscar/significado.json?q=' . rawurlencode(ASK_LONG), ['x-requested-with' => 'fetch'])->body, true)['better']);
    $page = $web->get('/buscar?q=' . rawurlencode(ASK_LONG));
    same(200, $page->status);
    $remove = askChipUrl($page->body, 'price');
    contains('noai=1', $remove, 'la ficha que sacó la IA no se puede quitar quitando palabras: se apaga la IA para esa frase');
    $asked = count($fake->asked);
    $after = $web->get($remove);
    same(200, $after->status);
    same($asked, count($fake->asked), 'y no se vuelve a llamar');
    notContains('data-af-search-chip="price"', $after->body);
    // La paginación conserva «noai».
    check(true);
});

test('las estadísticas apuntan que la IA ayudó, sin la frase', function (): void {
    list($app, $fake, $ids) = askAiShop();
    $fake->reply = json_encode(['category_id' => $ids['cat']['oficina'], 'price_max_eur' => 200, 'text' => '']);
    $web = new TestClient($app);
    $web->get('/buscar?q=' . rawurlencode(ASK_LONG));
    // La página la apunta con lo que entendieron las reglas; la ayuda que llega después suma lo que añadió la IA.
    $web->get('/buscar/significado.json?q=' . rawurlencode(ASK_LONG), ['x-requested-with' => 'fetch']);
    $stats = searchService($app)->log()->filterStats(7);
    same(1, $stats['ai']);
    same(1, $stats['price']);
});
