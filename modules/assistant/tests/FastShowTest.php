<?php
/**
 * AIFEC — P085, las fichas las elige quien contesta: show_products, lo que enseña una búsqueda según acierte o no (exact, relaxed,
 * related, none), la búsqueda con Search::ask() y lo ya enseñado en la conversación. IA de mentira con llamadas programadas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/FastKit.php';

test('fila 1: el modelo busca el jamón y enseña solo las 3 fichas de las que habla, en el orden que dice', function (): void {
    [$app, $ids, $ai, $web, $jamones] = fastShop();
    $ai->script(
        assistantTool('search_products', ['query' => 'jamón ibérico de bellota', 'limit' => 5]),
        assistantTool('show_products', ['ids' => [$jamones[2], $jamones[0], $jamones[1]]]),
        assistantText('Te recomiendo la paleta, la reserva y la gran reserva.')
    );

    $r = assistantSay($web, 'Busco un jamón ibérico de bellota para regalar');

    same('Te recomiendo la paleta, la reserva y la gran reserva.', $r['text']);
    same([$jamones[2], $jamones[0], $jamones[1]], fastCardIds($r), 'las 3 que nombra, y no las 5 que salieron');
    $result = assistantRows($app, 'conversation_messages', "role = 'assistant'")[0];
    same(['search_products', 'show_products'], array_column(json_decode((string) $result['tools'], true), 'name'));
    same(3, count($ai->asked), 'la búsqueda, la elección de fichas y el texto');
});

test('sin llamar a show_products salen como mucho las 3 primeras de la última búsqueda, si esa búsqueda acertó', function (): void {
    [$app, $ids, $ai, $web, $jamones] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'jamón ibérico de bellota', 'limit' => 5]), assistantText('Aquí tienes unos jamones.'));

    $r = assistantSay($web, 'Busco un jamón ibérico de bellota');

    same(3, count($r['cards']), 'tres fichas aunque la búsqueda diera cinco');
    foreach (fastCardIds($r) as $id) {
        check(in_array($id, $jamones, true), 'son jamones de la búsqueda');
    }
});

test('fila 2: lo que no existe («limpieza para suelos de madera») no lleva fichas de relleno y la pregunta queda sin respuesta', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'limpieza para suelos de madera']), assistantText('No tenemos productos de limpieza para suelos de madera.'));

    $r = assistantSay($web, '¿Y algo de limpieza para suelos de madera?');

    same('No tenemos productos de limpieza para suelos de madera.', $r['text']);
    same([], $r['cards'], 'ni un jabón, ni una estantería');
    $result = assistantLastMessage($ai->asked[1])->toolResult['result'];
    contains('"answer_kind":"none"', $result, 'el modelo lee que no hubo acierto');
    contains('"items":[]', $result);
    $user = assistantRows($app, 'conversation_messages', "role = 'user'")[0];
    same(1, (int) $user['unanswered'], 'es contenido que falta en la tienda');
});

test('lo parecido (relaxed) llega al modelo marcado como «no exacto» y, sin show_products, no se enseña ninguna ficha', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'silla roja por menos de 10 euros']), assistantText('Con ese precio no hay nada, la más parecida es la silla roja.'));

    $r = assistantSay($web, 'Una silla roja por menos de 10 euros');

    same([], $r['cards'], 'lo que no acierta no se enseña por su cuenta');
    $result = assistantLastMessage($ai->asked[1])->toolResult['result'];
    contains('"answer_kind":"relaxed"', $result);
    contains('NOT exact matches', $result, 'y se le dice que no encaja');
    contains('Silla roja', $result);
    contains('never offer unrelated products', $ai->asked[0]['request']['tools'][0]->description, 'la descripción de la herramienta lo explica');
    contains('they do NOT match', $ai->asked[0]['request']['system'], 'y la instrucción del sistema también');
});

test('lo parecido sí se enseña cuando el modelo lo pide con show_products, porque entonces el texto lo presenta como tal', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(
        assistantTool('search_products', ['query' => 'silla roja por menos de 10 euros']),
        assistantTool('show_products', ['ids' => [$ids['silla_roja']]]),
        assistantText('No hay nada tan barato; lo más parecido es esta silla.')
    );

    $r = assistantSay($web, 'Una silla roja por menos de 10 euros');

    same([$ids['silla_roja']], fastCardIds($r));
});

test('show_products descarta los identificadores que no salieron de una herramienta de esta conversación', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(
        assistantTool('search_products', ['query' => 'mesa roble']),
        assistantTool('show_products', ['ids' => [999999, $ids['mesa'], $ids['lampara']]]),
        assistantText('Esta es la mesa.')
    );

    $r = assistantSay($web, 'Busco una mesa de roble');

    same([$ids['mesa']], fastCardIds($r), 'el inventado y la lámpara (que no salió de ninguna búsqueda) se descartan');
    $shown = assistantLastMessage($ai->asked[2])->toolResult['result'];
    contains('"shown":[' . $ids['mesa'] . ']', $shown);
    contains('"ignored":2', $shown, 'el modelo se entera de que dos se descartaron');
    $tools = json_decode((string) assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['tools'], true);
    same(['name' => 'show_products', 'ok' => true, 'input' => ['ids' => (string) $ids['mesa']]], $tools[1]);
});

test('un producto que salió en la conversación de otra persona no se puede enseñar en esta', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'mesa roble']), assistantText('La mesa de roble.'));
    assistantSay($web, 'Busco una mesa de roble');

    $other = new TestClient($app);
    $ai->script(assistantTool('show_products', ['ids' => [$ids['mesa']]]), assistantText('Aquí la tienes.'));
    $r = assistantSay($other, 'Enséñame la mesa de roble');

    same([], $r['cards'], 'esta conversación no ha visto la mesa en ninguna herramienta');
    contains('"ignored":1', assistantLastMessage($ai->asked[3])->toolResult['result']);
});

test('lo que salió en un mensaje anterior de la misma conversación sí se puede enseñar después', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'mesa roble']), assistantText('Hay una mesa de roble.'));
    assistantSay($web, 'Busco una mesa de roble');
    $ai->script(assistantTool('show_products', ['ids' => [$ids['mesa']]]), assistantText('Aquí la tienes.'));

    $r = assistantSay($web, 'Enséñamela');

    same([$ids['mesa']], fastCardIds($r));
});

test('show_products exige de 1 a 6 identificadores: cero o siete son un error que el modelo lee, y no se enseña nada', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(
        assistantTool('search_products', ['query' => 'silla']),
        assistantRound([['show_products', ['ids' => []]], ['show_products', ['ids' => [1, 2, 3, 4, 5, 6, 7]]], ['show_products', ['ids' => [1], 'price' => 1]]]),
        assistantText('Vale.')
    );

    $r = assistantSay($web, 'Busco una silla');

    same(3, count($r['cards']), 'sin una llamada buena a show_products, las tres primeras de la búsqueda');
    $log = json_decode((string) assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['tools'], true);
    same([true, false, false, false], array_column($log, 'ok'));
});

test('hablar de UN producto (get_product) pone su ficha junto a lo que se cuenta; de varios no elige la tienda: para eso está show_products', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantTool('get_product', ['id' => $ids['mesa']]), assistantText('Es una mesa de roble macizo.'));

    $r = assistantSay($web, 'Háblame de la mesa de roble');

    // 13/11/2026, el usuario: al preguntar por un producto quiere que se hable de él Y ver su ficha (foto, precio, opciones).
    same([$ids['mesa']], fastCardIds($r), 'la ficha acompaña a la descripción');
    $other = array_values(array_diff($ids, [$ids['mesa']]))[0];
    $ai->script(assistantTool('get_product', ['id' => $ids['mesa']]), assistantTool('get_product', ['id' => $other]), assistantText('Son dos productos distintos.'));
    same([], assistantSay($web, 'Y dime también de ese otro')['cards'], 'con dos productos mirados no se pone ninguna por su cuenta');
    $ai->script(assistantTool('show_products', ['ids' => [$ids['mesa']]]), assistantText('Aquí la tienes.'));
    same([$ids['mesa']], fastCardIds(assistantSay($web, 'Enséñamela')), 'lo que miró get_product cuenta como visto');
});

test('search_products usa el buscador de frases (Search::ask) y devuelve id, nombre, precio, disponibilidad, resumen de 160 y opciones por producto', function (): void {
    [$app, $ids, $ai, $web] = fastShop();

    $r = $app->service('tools')->call('search_products', ['query' => 'jamón ibérico de bellota', 'limit' => 5]);

    same('exact', $r['answer_kind']);
    same(5, count($r['items']));
    $item = $r['items'][0];
    foreach (['id', 'name', 'price_formatted', 'available', 'summary', 'options', 'answer_kind'] as $key) {
        check(array_key_exists($key, $item), 'el producto lleva «' . $key . '»');
    }
    same('exact', $item['answer_kind']);
    same(true, $item['available']);
    check(mb_strlen($item['summary'], 'UTF-8') <= 160, 'el resumen no pasa de 160 caracteres');
    contains('Curado', $item['summary']);
    same([], $item['options'], 'un producto sin variantes no tiene opciones');
    check(preg_match('/9[.,]?900|99,00/', $item['price_formatted']) === 1 || strpos($item['price_formatted'], '99') !== false, 'precio con formato: ' . $item['price_formatted']);
});

test('el buscador no gasta una segunda vez la IA: una frase larga se busca solo con reglas', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'busco algo bonito para regalar a mi madre por su cumpleaños']), assistantText('No hay nada de eso.'));

    assistantSay($web, 'Busco algo bonito para regalar a mi madre por su cumpleaños');

    same(['assistant.reply', 'assistant.reply'], array_column($ai->asked, 'task'), 'ninguna petición del buscador a la IA');
});

test('una opción con varias variantes sale en la búsqueda con el nombre de sus opciones', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    aifecAttribute($app, 'color', 'Color', ['negro' => 'Negro', 'rojo' => 'Rojo'], ['variant' => 1]);
    aifecProduct($app, ['name' => 'Zapatilla Plana', 'price' => 4900, 'variants' => [
        ['sku' => 'ZP-N', 'label' => 'Negro', 'price' => 4900, 'stock' => 4, 'values' => ['color' => 'negro']],
        ['sku' => 'ZP-R', 'label' => 'Rojo', 'price' => 4900, 'stock' => 4, 'values' => ['color' => 'rojo']],
    ]]);
    searchService($app)->reindexNow();

    $r = $app->service('tools')->call('search_products', ['query' => 'zapatilla plana']);

    same(['Color'], $r['items'][0]['options']);
});

test('lo que ya se enseñó en la conversación viaja al modelo como «ya enseñado» y la búsqueda sin acierto mira también ahí', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'mesa roble']), assistantText('Esta es la mesa.'), assistantText('Sigue siendo esta.'));
    assistantSay($web, 'Busco una mesa de roble');
    assistantSay($web, 'Gracias');
    $sent = assistantLastMessage($ai->asked[2])->content;
    contains('already shown', $sent);
    contains('Mesa de roble', $sent, 'con su nombre');
    contains('"id":' . $ids['mesa'], $sent, 'y su identificador');
    contains('<<<DATA already_shown', $sent, 'cercado como dato');

    // Una búsqueda que no da nada pero casa con lo ya enseñado: se devuelve lo ya enseñado.
    $conversation = assistantRows($app, 'conversations')[0];
    $state = json_decode((string) $conversation['state'], true);
    $state['shown'] = [['id' => $ids['lampara'], 'n' => 'Cojín lumbar ergonómico']];
    $app->db()->update('conversations', ['state' => json_encode($state)], ['id' => (int) $conversation['id']]);
    $ai->script(assistantTool('search_products', ['query' => 'cojin lumbar']), assistantText('Sí, tenemos el cojín que te enseñé.'));
    assistantSay($web, '¿Y un cojín lumbar?');
    $result = assistantLastMessage($ai->asked[4])->toolResult['result'];
    contains('"answer_kind":"exact"', $result, 'no se dice «no tenemos» de lo que ya se enseñó');
    contains('"already_shown":true', $result);
});
