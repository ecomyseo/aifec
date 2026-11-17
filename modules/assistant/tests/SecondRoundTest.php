<?php
/**
 * AIFEC — pruebas de la segunda ronda con cinco clientes (P088): que el asistente encuentre lo que encuentra el buscador de la tienda,
 * que no añada dos veces, que nunca conteste con una disculpa si el trabajo se hizo, que no pregunte lo que ya sabe, que entienda una
 * dirección corregida con su piso, que el resumen diga a dónde va y que compare y conteste de devoluciones con datos de verdad.
 * Cada prueba parte de una frase literal de las conversaciones reales (con sus erratas y en inglés).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Kernel\App;
use Aifec\Modules\Assistant\AiBrain;
use Aifec\Modules\Assistant\Answer;
use Aifec\Modules\Assistant\OrderReply;
use Aifec\Modules\Assistant\Prefs;
use Aifec\Modules\Assistant\RuleBrain;
use Aifec\Modules\Assistant\ShopTools;
use Aifec\Modules\Assistant\VariantPick;

require_once __DIR__ . '/SecondKit.php';
require_once __DIR__ . '/OrderChatKit.php';

/** El identificador de una variante de un producto por su etiqueta. */
function secondVariant(App $app, int $productId, string $label): int
{
    $p = $app->service('catalog')->product($productId, 'es');
    foreach (is_array($p) && isset($p['variants']) && is_array($p['variants']) ? $p['variants'] : [] as $variant) {
        if ((string) $variant['label'] === $label) {
            return (int) $variant['id'];
        }
    }

    return 0;
}

/**
 * Lo que la tienda le devolvió a la IA en la petición número $n (la última herramienta), ya leído.
 *
 * @return array<string,mixed>
 */
function secondResult(AssistantFakeAi $ai, int $n): array
{
    return secondJson(assistantLastMessage($ai->asked[$n])->toolResult['result']);
}

/**
 * Una tienda con la compra en el chat, la IA de mentira y la silla roja ya en el carrito de un navegador nuevo.
 *
 * @return array{0:App,1:AssistantFakeAi,2:TestClient,3:array<string,int>}
 */
function secondAiShop(): array
{
    $app = chatApp();
    $ids = assistantShop($app);
    $ai = assistantAi($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);

    return [$app, $ai, $web, $ids];
}

/** La IA recorre las herramientas de la caja hasta el resumen. */
function secondToReview(AssistantFakeAi $ai): void
{
    $ai->script(
        assistantRound([['checkout_status', []], ['checkout_set_customer', ['text' => CHAT_DATA]], ['checkout_set_payment', ['method' => 'transfer']], ['checkout_review', []]]),
        assistantText('Aquí tienes el resumen. Confírmalo si es correcto.')
    );
}

/**
 * Los nombres de las fichas de una respuesta.
 *
 * @param array<string,mixed> $r
 *
 * @return string[]
 */
function secondCardNames(array $r): array
{
    return array_values(array_map('strval', array_column($r['cards'], 'name')));
}

/**
 * Quita las marcas de la valla de datos para leer el JSON que le llegó al modelo.
 */
function secondJson(string $fenced): array
{
    $start = strpos($fenced, '{');
    $end = strrpos($fenced, '}');
    $decoded = $start !== false && $end !== false ? json_decode(substr($fenced, $start, $end - $start + 1), true) : null;

    return is_array($decoded) ? $decoded : [];
}

// ================================================================== 1. buscar como busca la tienda

test('Hanna: «Hi! I\'m looking for a desk lamp, something under 80 euros» encuentra el flexo: sin acierto con reglas, el buscador usa su IA para entender el inglés', function (): void {
    [$app, $ids, $ai, $web, $flexos] = secondLampShop();
    $spy = secondSpy($app);
    $ai->searchScript(json_encode(['category_id' => $flexos, 'price_max_eur' => 80]));
    $ai->script(assistantTool('search_products', ['query' => 'desk lamp', 'max_price_cents' => 8000]), assistantText('Here are some options.'));

    $r = assistantSay($web, "Hi! I'm looking for a desk lamp, something under 80 euros. Could you show me some options?");

    $result = secondResult($ai, 1);
    same('exact', $result['answer_kind'], 'el buscador de la tienda acierta con esa frase');
    $names = array_column($result['items'], 'name');
    check(in_array('Flexo LED Arco', $names, true), 'el flexo de 59,90 € está: ' . json_encode($names));
    check(!in_array('Lámpara de Pie Faro', $names, true), 'y no la lámpara de pie de 129 €');
    same(2, count($spy->calls), 'primero con reglas y, como no salió nada, otra vez con la IA del buscador');
    same(false, $spy->calls[0]['options']['ai']);
    same(true, $spy->calls[1]['options']['ai']);
    check(in_array('Flexo LED Arco', secondCardNames($r), true), 'y la ficha del flexo sale con su precio');
    same('Here are some options.', $r['text']);
});

test('con el modelo al mando, el buscador llama a su propia IA una sola vez por respuesta: en las demás búsquedas solo usa lo guardado', function (): void {
    // 13/11/2026, en vivo: «soy hombre» → cuatro búsquedas, cada una con su llamada de pago a la IA del buscador (1-2 s); se
    // agotó el tiempo del turno, el modelo no llegó a escribir y la respuesta cayó a las reglas con zapatillas al azar.
    [$app, $ids, $ai, $web, $flexos] = secondLampShop();
    $spy = secondSpy($app);
    $ai->searchScript(json_encode(['category_id' => $flexos, 'price_max_eur' => 80]));
    $ai->script(
        assistantTool('search_products', ['query' => 'desk lamp']),
        assistantTool('search_products', ['query' => 'reading light']),
        assistantTool('search_products', ['query' => 'study lamp']),
        assistantText('Here are some options.')
    );

    $r = assistantSay($web, 'Hi! I need a lamp to study at my desk. Could you show me some options?');

    same('Here are some options.', $r['text'], 'el modelo llega a escribir');
    $byQuery = [];
    foreach ($spy->calls as $call) {
        $byQuery[(string) $call['query']][] = $call['options']['ai'];
    }
    same([false, true], $byQuery['desk lamp'], 'la primera búsqueda que no acierta puede preguntar a la IA del buscador');
    same([false, 'cached'], $byQuery['reading light'], 'la segunda, solo lo guardado');
    same([false, 'cached'], $byQuery['study lamp'], 'y la tercera');
    same(1, count($ai->searchAsked), 'una llamada de pago del buscador en toda la respuesta');
    foreach ([1, 2] as $n) {
        check(!array_key_exists('ai_used', secondResult($ai, $n)), 'la marca interna no llega al modelo (búsqueda ' . $n . ')');
    }
    // La tercera búsqueda gasta la última ronda: su resultado va en la petición de cierre, como datos en el mensaje del cliente.
    $closing = assistantLastMessage($ai->asked[3]);
    same('user', $closing->role);
    contains('tool_search_products', $closing->content);
    notContains('ai_used', $closing->content, 'la marca interna tampoco llega al modelo en el cierre');
});

test('Hanna, segunda frase: «Do you have any table lamps or reading lamps for a desk, under 80 euros?» tampoco acaba en «no tenemos»', function (): void {
    [$app, $ids, $ai, $web, $flexos] = secondLampShop();
    $ai->searchScript(json_encode(['category_id' => $flexos, 'price_max_eur' => 80]));
    $ai->script(assistantTool('search_products', ['query' => 'table lamps reading lamps desk', 'max_price_cents' => 8000]), assistantText('These flexos are under 80 euros.'));

    $r = assistantSay($web, 'Thanks. Do you have any table lamps or reading lamps for a desk, under 80 euros?');

    $result = secondResult($ai, 1);
    same('exact', $result['answer_kind']);
    check(count($result['items']) >= 1, 'hay productos: no se puede decir que no hay lámparas');
    check($r['cards'] !== [], 'y se enseñan');
    notContains('We don\'t have', $r['text']);
});

test('Lucía: «lanpara pa estudiar barata» enseña el flexo de 29,90 € el primero, no la lámpara de pie de 129 €', function (): void {
    [$app, $ids, $ai, $web, $flexos] = secondLampShop();
    $ai->searchScript(json_encode(['category_id' => $flexos, 'sort' => 'price_asc']));
    $ai->script(assistantTool('search_products', ['query' => 'lanpara pa estudiar barata']), assistantText('Te enseño los flexos, empezando por el más barato.'));

    $r = assistantSay($web, 'lanpara pa estudiar barata');

    $result = secondResult($ai, 1);
    same('exact', $result['answer_kind']);
    same('Flexo Básico Estudio', $result['items'][0]['name'], 'el más barato va primero');
    $prices = array_column($result['items'], 'price_formatted');
    same(money(2990), $prices[0]);
    same('Flexo Básico Estudio', $r['cards'][0]['name'], 'y la primera ficha es la del más barato');
});

test('«lo más barato» ordena por precio aunque nadie lo entienda como orden: sin IA, con reglas, el más barato sale primero', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->on = false;

    $r = assistantSay($web, 'flexo mas barato');

    same('Flexo Básico Estudio', $r['cards'][0]['name'], 'por reglas también pasa por Search::ask() y se ordena por precio');
    same(2990, $r['cards'][0]['price_cents']);
});

test('Search::ask() se llama cuando el atajo no encuentra y, con la IA del buscador, solo después de que las reglas no hayan encontrado nada', function (): void {
    [$app, $ids, $ai, $web, $flexos] = secondLampShop();
    $spy = secondSpy($app);
    $ai->searchScript(json_encode(['category_id' => $flexos]));
    $tools = $app->service('tools');

    $none = $tools->call('search_products', ['query' => 'desk lamp']);

    same(2, count($spy->calls), 'dos peticiones: reglas y reglas con IA');
    same(1, $spy->withAi(), 'solo una con la IA');
    same('exact', $none['answer_kind']);
    same(1, count($ai->searchAsked), 'y la IA del buscador se preguntó una vez');
});

test('Search::ask() no gasta IA cuando acierta con confianza: «flexo» sale con una sola llamada y sin IA', function (): void {
    [$app, $ids, $ai] = secondLampShop();
    $spy = secondSpy($app);

    $r = $app->service('tools')->call('search_products', ['query' => 'flexo']);

    same('exact', $r['answer_kind']);
    same(1, count($spy->calls));
    same(false, $spy->calls[0]['options']['ai']);
    same(0, count($ai->searchAsked), 'la IA del buscador ni se toca');
});

test('sin IA no hay segunda llamada: una frase sin acierto pregunta una vez al buscador y dice que no hay nada', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->on = false;
    $spy = secondSpy($app);

    $r = assistantSay($web, 'lampara voladora de cristal');

    same(1, count($spy->calls), 'por reglas también pasa por Search::ask(), una vez');
    contains('No he encontrado nada', $r['text']);
});

test('«no tenemos» no puede salir si Search::ask() devolvió productos: aunque el modelo resuma mal la frase, la herramienta devuelve lo que ask() encontró', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $canned = searchService($app)->ask('flexo', ['lang' => 'es', 'limit' => 5, 'ai' => false]);
    $spy = secondSpy($app);
    $spy->force = static function (string $q) use ($canned): ?array {
        return $q === 'xyzzy' ? $canned : null;
    };

    $r = $app->service('tools')->call('search_products', ['query' => 'xyzzy']);

    same('exact', $r['answer_kind'], 'ask() devolvió productos: no es «none»');
    check(count($r['items']) >= 1 && $r['items'][0]['name'] !== '', 'y la herramienta los entrega');
});

test('si el modelo resume mal lo que pidió el cliente, la búsqueda vuelve a intentarlo con las palabras del propio cliente', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantTool('search_products', ['query' => 'zzzqx']), assistantText('Mira.'));

    assistantSay($web, 'flexo');

    $result = secondResult($ai, 1);
    same('exact', $result['answer_kind'], 'con las palabras del cliente ask() acierta');
    check(in_array('Flexo LED Arco', array_column($result['items'], 'name'), true));
});

test('con la IA caída la búsqueda no revienta: sigue por reglas y dice que no ha encontrado nada', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->fail = true;

    $r = assistantSay($web, 'I am looking for a desk lamp please');

    contains('I found nothing', $r['text'], 'se contesta en inglés y sin excepción');
});

test('en inglés y por reglas: «I am looking for a flexo under 80 euros» busca con el tope de precio y enseña solo lo que lo cumple', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->on = false;

    $r = assistantSay($web, 'I am looking for a flexo under 80 euros');

    $names = secondCardNames($r);
    check(in_array('Flexo LED Arco', $names, true) && in_array('Flexo Básico Estudio', $names, true), 'los dos flexos: ' . json_encode($names));
    foreach ($r['cards'] as $card) {
        check($card['price_cents'] <= 8000, 'ninguno pasa de 80 €');
    }
});

// ================================================================== 2. no añadir dos veces

test('Marta: «Sí, la quiero en color negro. Añádela al carrito.» añade la silla negra UNA vez, sin preguntar el color que ya dijo', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['silla_color']]), assistantText('Hecho, la silla negra está en tu carrito.'));

    $r = assistantSay($web, 'Sí, la quiero en color negro. Añádela al carrito.');

    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines), 'una línea');
    same(1, (int) $lines[0]['qty'], 'una unidad');
    same('Negro', $lines[0]['variant_label'], 'la negra');
    $result = secondResult($ai, 1);
    same(true, $result['ok']);
    same('Negro', $result['variant']);
    check(!in_array('Gris', $r['suggestions'], true) && !in_array('Negro', $r['suggestions'], true), 'ni botones de color: ya está elegido');
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['pick']), 'y no queda nada por elegir');
});

test('Marta, después de que el modelo repita la llamada con la variante negra en la misma ronda: sigue habiendo una sola silla', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $black = secondVariant($app, $ids['silla_color'], 'Negro');
    check($black > 0, 'existe la variante');
    $ai->script(
        assistantRound([['cart_add', ['product_id' => $ids['silla_color']]], ['cart_add', ['product_id' => $ids['silla_color'], 'variant_id' => $black]]]),
        assistantText('Añadida.')
    );

    assistantSay($web, 'Añade la silla Ergo Pro en negro');

    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines));
    same(1, (int) $lines[0]['qty'], 'la segunda llamada de la misma línea no suma otra unidad');
});

test('dos llamadas iguales a cart_add en un mismo turno: una sola unidad, y a la segunda se le dice que ya estaba', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantRound([['cart_add', ['product_id' => $ids['silla_roja']]], ['cart_add', ['product_id' => $ids['silla_roja']]]]), assistantText('Hecho.'));

    assistantSay($web, 'Añade la silla roja');

    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines));
    same(1, (int) $lines[0]['qty']);
    $log = json_decode((string) assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['tools'], true);
    same([false, true], array_map(static function (array $e): bool {
        return !empty($e['duplicate']);
    }, array_slice($log, 0, 2)), 'el registro marca la repetida');
});

test('añadir un producto con colores sin decir cuál NO añade nada, pregunta y ofrece los colores; elegir «Negro, por favor.» añade UNA vez sin pasar por la IA', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['silla_color']]), assistantText('¿En qué color la quieres, negro o gris?'));

    $r = assistantSay($web, 'Quiero comprar una silla de oficina ergonómica');

    same([], array_values(assistantCartLines($app, $web)), 'cero líneas en el carrito');
    same(['Negro', 'Gris'], $r['suggestions'], 'pregunta con botones');
    $asked = count($ai->asked);

    $added = assistantSay($web, 'Negro, por favor.');

    same($asked, count($ai->asked), 'elegir no pasa por la IA');
    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines));
    same(1, (int) $lines[0]['qty'], 'una sola unidad');
    same('Negro', $lines[0]['variant_label']);
    contains('Añadido a tu carrito: Silla Ergo Pro', $added['text']);
});

test('un botón de color que se quedó de antes desaparece cuando se añade: el cliente no puede pulsar «Negro» y añadir otra', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $black = secondVariant($app, $ids['silla_color'], 'Negro');
    $ai->script(
        assistantRound([['cart_add', ['product_id' => $ids['silla_color']]]]),
        assistantRound([['cart_add', ['product_id' => $ids['silla_color'], 'variant_id' => $black]]]),
        assistantText('Añadida la negra.')
    );

    $r = assistantSay($web, 'Añade la silla Ergo Pro');

    same(1, count(assistantCartLines($app, $web)));
    check(!in_array('Negro', $r['suggestions'], true) && !in_array('Gris', $r['suggestions'], true), 'sin botones de color tras añadir: ' . json_encode($r['suggestions']));
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['pick']), 'ni opción pendiente en el estado');
});

test('«la gris», «negra» y «talla M» se resuelven contra las opciones del producto, también con el género cambiado', function (): void {
    $pick = [
        'id' => 1, 'n' => 'Silla Ergo Pro', 'q' => 1, 's' => [],
        'v' => [['id' => 11, 'l' => 'Negro', 'o' => ['Negro']], ['id' => 12, 'l' => 'Gris', 'o' => ['Gris']]],
    ];
    same(['status' => 'one', 'variant' => 12], array_intersect_key(VariantPick::resolve($pick, 'la quiero la gris'), ['status' => 1, 'variant' => 1]));
    same(11, VariantPick::resolve($pick, 'la quiero negra')['variant'], '«negra» es «Negro»');
    same(11, VariantPick::resolve($pick, 'quiero dos sillas negras')['variant'], 'y «negras»');
    $shirt = [
        'id' => 2, 'n' => 'Camiseta', 'q' => 1, 's' => [],
        'v' => [['id' => 21, 'l' => 'Negro M', 'o' => ['Negro', 'M']], ['id' => 22, 'l' => 'Negro L', 'o' => ['Negro', 'L']], ['id' => 23, 'l' => 'Rojo M', 'o' => ['Rojo', 'M']]],
    ];
    same(21, VariantPick::resolve($shirt, 'negra en talla M')['variant']);
    same('none', VariantPick::resolve($shirt, 'hola que tal')['status']);
});

test('«Quiero 2» son 2 unidades; «añádela» tras un «¿la añado?» es 1', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 2]), assistantText('Dos sillas.'));
    assistantSay($web, 'Quiero 2 sillas rojas');
    $lines = array_values(assistantCartLines($app, $web));
    same(2, (int) $lines[0]['qty']);

    [$app2, $ids2, $ai2, $web2] = secondLampShop();
    $ai2->script(assistantText('¿La añado al carrito?'), assistantTool('cart_add', ['product_id' => $ids2['silla_roja']]), assistantText('Añadida.'));
    assistantSay($web2, 'Cuéntame de la silla roja');
    assistantSay($web2, 'Añádela');
    $one = array_values(assistantCartLines($app2, $web2));
    same(1, (int) $one[0]['qty'], 'una unidad');
});

test('el botón de una ficha con variantes que se coló sin elegir pregunta cuál y no añade nada', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $assistant = $app->service('assistant');
    $conversation = $assistant->conversations()->create('es');

    $r = $assistant->addToCart((int) $conversation['id'], $ids['silla_color']);

    contains('Silla Ergo Pro', $r['text']);
    same([], $r['actions'], 'el carrito no cambia');
});

// ================================================================== 3. nunca una disculpa si el trabajo se hizo

test('la petición a la IA lleva un tope de salida de al menos 1.024 tokens: los modelos piensan dentro de él', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantTool('list_categories', []), assistantText('Hay varias.'));

    assistantSay($web, '¿Qué categorías tenéis?');

    check(count($ai->asked) >= 2, 'hubo peticiones');
    foreach ($ai->asked as $ask) {
        check($ask['request']['max_tokens'] >= 1024, 'max_tokens = ' . $ask['request']['max_tokens']);
    }
    check(AiBrain::MAX_TOKENS >= 1024);
});

test('texto final vacío después de cart_add: la respuesta se compone con lo que hicieron las herramientas, sin la frase de disculpa', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $black = secondVariant($app, $ids['silla_color'], 'Negro');
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['silla_color'], 'variant_id' => $black]), assistantText('   '));

    $r = assistantSay($web, 'Añádeme la silla Ergo Pro negra');

    contains('Añadido a tu carrito: Silla Ergo Pro (Negro).', $r['text']);
    contains('Total: ' . money(24900 + 495), $r['text'], 'con el envío que ya trae el carrito');
    notContains('no he podido escribir', $r['text']);
    same(['Ir a la caja', 'Ver mi carrito', 'Seguir mirando'], $r['suggestions'], 'tras añadir (P105)');
    same([['type' => 'cart_changed', 'count' => 1]], $r['actions']);
    same(1, count(assistantCartLines($app, $web)));
});

test('la IA lanza una excepción justo después de cart_add (Íñigo: «Pues quiero comprar el cojín lumbar»): se compone la respuesta y el carrito no se toca otra vez', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['silla_roja']]), new AiException('Gemini returned an empty answer (finishReason MAX_TOKENS)', true));

    $r = assistantSay($web, 'Pues quiero comprar la silla roja. Lo quiero contra reembolso.');

    contains('Añadido a tu carrito: Silla roja.', $r['text']);
    notContains('no he podido', $r['text']);
    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines));
    same(1, (int) $lines[0]['qty'], 'una sola unidad: no se repite por reglas');
});

test('las rondas agotadas después de cart_add también se contestan con lo hecho', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(
        assistantTool('cart_add', ['product_id' => $ids['silla_roja']]),
        assistantTool('list_categories', []),
        assistantTool('list_categories', []),
        assistantTool('list_categories', [])
    );

    $r = assistantSay($web, 'Añade la silla roja y dime las categorías');

    contains('Añadido a tu carrito: Silla roja.', $r['text']);
    same(1, count(assistantCartLines($app, $web)));
});

test('con varias unidades la respuesta compuesta lo dice: «Añadido a tu carrito: 2 × Silla roja.»', function (): void {
    [$app, $ids, $ai, $web] = secondLampShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 2]), assistantText(''));

    $r = assistantSay($web, 'Ponme dos sillas rojas');

    contains('Añadido a tu carrito: 2 × Silla roja.', $r['text']);
});

test('con un pedido en marcha, la respuesta compuesta sigue con lo que falta: lo hecho y la pregunta siguiente', function (): void {
    [$app, $ai, $web, $ids] = fastChatShop();
    $ai->script(assistantRound([['cart_add', ['product_id' => $ids['mesa']]], ['checkout_set_customer', ['text' => CHAT_DATA]]]), assistantText(''));

    $r = assistantSay($web, 'Añade también la mesa de roble y haz mi pedido');

    contains('Añadido a tu carrito: Mesa de roble.', $r['text']);
    contains('¿Cómo quieres pagar?', $r['text'], 'y sigue por donde iba el pedido');
    notContains('no he podido', $r['text']);
});

test('la frase de disculpa ya no existe en el módulo: ni en el código ni en el catálogo castellano', function (): void {
    $root = dirname(__DIR__);
    $needle = 'could not ' . 'write the rest';
    $files = array_merge(glob($root . '/src/*.php') ?: [], glob($root . '/src/*/*.php') ?: [], [$root . '/lang/es.php'], glob($root . '/views/*/*.php') ?: [], glob($root . '/assets/*') ?: []);
    check(count($files) > 10, 'se miran los ficheros del módulo');
    foreach ($files as $file) {
        notContains($needle, (string) file_get_contents($file), basename($file));
        notContains('no he podido escribir el resto', (string) file_get_contents($file), basename($file));
    }
});

// ================================================================== 4. no preguntar lo que ya se sabe

test('Paco: «…Avenida del Ejemplo 12, 2.º B, 28013 Madrid (España)» guarda la ciudad y el piso: a la IA se le dice que no falta nada y no pregunta la ciudad', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantText('Datos guardados.'));

    assistantSay($web, 'Vale, pues tramitamos el pedido. Me llamo Francisco Gil Mora, mi email es paco.gil@ejemplo.test, mi teléfono 999000002 y la dirección es Avenida del Ejemplo 12, 2.º B, 28013 Madrid (España).');

    $note = assistantLastMessage($ai->asked[0])->content;
    contains('Still missing: nothing.', $note);
    $draft = json_encode(chatDraft($app, $web), JSON_UNESCAPED_UNICODE);
    contains('"city":"Madrid"', (string) $draft);
    contains('2º B', (string) $draft, 'el piso en la segunda línea');
});

test('Lucía: «estandar» dicho antes de tener dirección se apunta y se aplica al guardar la dirección, diciéndolo', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    chatFast($app);
    $ai = assistantAi($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    $ai->script(assistantText('Vale.'), assistantText('He puesto el envío estándar, como pediste.'));

    assistantSay($web, 'si el arco y el estandar');
    assistantSay($web, CHAT_DATA);

    $note = assistantLastMessage($ai->asked[1])->content;
    contains('has already set it: Estándar', $note);
    same(['ship' => 'standard'], Prefs::detect(RuleBrain::norm('si el arco y el estandar. calle falsa 123'), false));
    same(['ship' => 'standard'], Prefs::detect(RuleBrain::norm('Quiero el envío estándar, por favor'), false));
});

test('el envío ya elegido con las herramientas queda apuntado: si después la IA no llega a un texto, la tienda no vuelve a preguntarlo', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $fast = chatFast($app);
    $ai = assistantAi($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    $ai->script(assistantRound([['checkout_set_customer', ['text' => CHAT_DATA]]]), assistantText('Datos guardados. ¿Qué envío prefieres?'));
    assistantSay($web, 'Quiero comprar la silla. ' . CHAT_DATA);
    $ai->script(assistantRound([['checkout_set_shipping', ['carrier_id' => $fast]]]), assistantText('He puesto el urgente.'));
    assistantSay($web, 'Urgente');
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    same(1, (int) $state['flow']['ship'], 'el envío elegido queda apuntado');

    $ai->script(assistantText('   '));
    $r = assistantSay($web, 'Transferencia bancaria');

    notContains('¿Cómo quieres que te lo enviemos?', $r['text'], 'el envío ya está elegido: no se vuelve a preguntar');
    contains('Este es tu pedido:', $r['text']);
    contains('Envío: Urgente', $r['text']);
});

test('los datos que faltan se piden todos en un mensaje: «Me falta tu correo, la calle, el código postal y la ciudad», no de uno en uno', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');

    $r = assistantSay($web, 'Lucía Navarro Sanz');

    contains('tu correo', $r['text']);
    contains('la calle y el número', $r['text']);
    contains('el código postal', $r['text']);
    contains('la ciudad', $r['text'], 'todo junto, en un solo mensaje');
});

test('con la IA, la instrucción pide no volver a preguntar lo que la tienda ya sabe y pedir lo que falte en un solo mensaje', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantText('Hola.'));

    assistantSay($web, 'Hola');

    $system = $ai->asked[0]['request']['system'];
    contains('Never ask again for what the shop already knows', $system);
    contains('in ONE message', $system);
    contains('Never add the same product twice', $system);
});

test('lo que se dice del envío y del pago en el mismo mensaje que los datos se aplica: «Envío estándar, por favor» no se vuelve a preguntar', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    chatFast($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, 'Ana Ruiz, calle Mayor 1 3ºB, 28013 Madrid (España), 999000001, ana@correo.test');

    $r = assistantSay($web, 'Envío estándar, por favor');

    notContains('¿Cómo quieres que te lo enviemos?', $r['text']);
    contains('¿Cómo quieres pagar?', $r['text'], 'ya pasa al pago');
});

// ================================================================== 5. entender una dirección

test('corregir la dirección por reglas («uy la direccion esta mal, es Plaza Inventada 7, 1.º, 29001 Málaga») cambia la dirección y el nombre sigue siendo el de antes', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    assistantSay($web, 'Transferencia bancaria');

    $r = assistantSay($web, 'uy la direccion esta mal, es Plaza Inventada 7, 1.º, 29001 Málaga');

    $draft = json_encode(chatDraft($app, $web), JSON_UNESCAPED_UNICODE);
    contains('"first_name":"Ana"', (string) $draft);
    contains('"last_name":"Ruiz"', (string) $draft);
    notContains('Uy', (string) $draft, 'el pedido no queda a nombre de «Uy La Direccion Esta Mal»');
    contains('Plaza Inventada 7', (string) $draft);
    contains('1º', (string) $draft, 'con su piso');
    contains('Entregar en: Ana Ruiz, Plaza Inventada 7 1º, 29001 Málaga', $r['text']);
});

test('el piso y la puerta («3.º A») llegan al resumen y al pedido de verdad, no solo a la lectura del texto', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    $data = assistantSay($web, 'Marta Ruiz Peña, marta.ruiz@ejemplo.test, teléfono 999000001, Calle de la Muestra 5, 3.º A, 46001 Valencia (España)');
    $pay = assistantSay($web, 'Transferencia bancaria');

    contains('Entregar en: Marta Ruiz Peña, Calle de la Muestra 5 3º A, 46001 Valencia', $pay['text']);
    chatConfirm($web);
    $orders = chatOrders($app);
    same(1, count($orders));
    $address = json_encode(assistantRows($app, 'orders')[0], JSON_UNESCAPED_UNICODE);
    contains('3º A', (string) $address, 'el pedido lleva el piso');
    unset($data);
});

test('con IA, tras un cambio de dirección con el resumen delante se vuelve a enseñar el resumen con la dirección nueva y el envío', function (): void {
    [$app, $ai, $web] = secondAiShop();
    secondToReview($ai);
    assistantSay($web, 'Quiero pedir la silla, mis datos los tienes en el siguiente mensaje');
    $ai->script(assistantText('He actualizado la dirección.'));

    $r = assistantSay($web, 'uy la direccion esta mal, es Plaza Inventada 7, 1.º, 29001 Málaga');

    contains('He actualizado la dirección.', $r['text']);
    contains('Ahora va a Plaza Inventada 7 1º, 29001 Málaga', $r['text'], 'la dirección nueva');
    contains('Envío Estándar: ' . money(495), $r['text'], 'y el envío recalculado');
    $card = chatAction($r, 'confirm_order');
    check($card !== null, 'con su tarjeta nueva para confirmar');
    contains('Ana Ruiz', (string) $card['name'], 'el nombre no cambió');
    same(['Plaza Inventada 7 1º', '29001 Málaga', 'ES'], $card['address']);
    $confirmed = assistantSay($web, 'sí');
    same(1, count(chatOrders($app)), 'y el «sí» confirma el pedido con la dirección de ahora');
    unset($confirmed);
});

test('OrderReply::withDelivery: el resumen sin dirección la recibe con nombre y calle completos; si ya la dice, no se repite', function (): void {
    $card = ['type' => 'confirm_order', 'name' => 'Lucía Navarro Sanz', 'address' => ['Plaza Inventada 7 1º', '29001 Málaga', 'ES'], 'shipping' => ['name' => 'Estándar', 'price' => '4,95 €']];
    $a = new Answer();
    $a->text = 'Este es tu resumen.';
    $a->actions[] = $card;
    OrderReply::withDelivery($a, false);
    same("Este es tu resumen.\nEntregar en: Lucía Navarro Sanz, Plaza Inventada 7 1º, 29001 Málaga, ES", $a->text);

    OrderReply::withDelivery($a, false);
    same(1, substr_count($a->text, 'Entregar en'), 'no se repite');

    $b = new Answer();
    $b->text = 'Hecho.';
    $b->actions[] = $card;
    OrderReply::withDelivery($b, true);
    contains('Ahora va a Plaza Inventada 7 1º, 29001 Málaga, ES. Envío Estándar: 4,95 €', $b->text, 'con la dirección nueva y su envío');

    $c = new Answer();
    $c->text = 'Sin tarjeta.';
    OrderReply::withDelivery($c, true);
    same('Sin tarjeta.', $c->text, 'sin tarjeta no se añade nada');
});

test('a Lucía le piden el resumen otra vez («a ver el resumen otra vez, q direccion sale»): el resumen que escribe el modelo lleva «Entregar en»', function (): void {
    [$app, $ai, $web] = secondAiShop();
    secondToReview($ai);
    assistantSay($web, 'Quiero pedir la silla, mis datos los tienes en el siguiente mensaje');
    $ai->script(assistantRound([['checkout_review', []]]), assistantText('Este es tu resumen, con el total que ves.'));

    $r = assistantSay($web, 'a ver el resumen otra vez porfa, q direccion sale');

    contains('Entregar en: Ana Ruiz, Calle Mayor 1 3ºB, 28013 Madrid', $r['text']);
    check(chatAction($r, 'confirm_order') !== null);
});

test('al cambiar de dirección por reglas se dice por qué se vuelve a preguntar el envío si el que había elegido ya no llega', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $fast = shippingAddCarrier($app, 'fastonly', ['name' => 'Urgente Península', 'delay' => '24 h']);
    shippingAddRate($app, $fast, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 1295);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    assistantSay($web, 'Urgente Península');
    assistantSay($web, 'Transferencia bancaria');

    $r = assistantSay($web, 'Mi nueva dirección es calle Triana 4, 35001 Las Palmas de Gran Canaria');

    contains('no llega a tu código postal', $r['text']);
    contains('Urgente Península', $r['text']);
});

// ================================================================== 7. comparar y devoluciones

test('compare_products devuelve precio, existencias y en qué se diferencian, con los datos de la ficha y no adjetivos', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $tools = $app->service('tools');

    $r = $tools->call('compare_products', ['ids' => [$ids['silla_roja'], $ids['silla_azul']]]);

    same(2, count($r['products']));
    same('Silla roja', $r['products'][0]['name']);
    same(5990, $r['products'][0]['price_cents']);
    same(true, $r['products'][0]['available']);
    same(false, $r['products'][1]['available'], 'la azul está agotada');
    same($ids['silla_roja'], $r['cheapest_id']);
    $names = array_column($r['differences'], 'name');
    check(in_array('Color', $names, true), 'se diferencian en el color: ' . json_encode($names));
    check(count($r['differences']) <= 3, 'como mucho tres diferencias');
    $values = array_column($r['differences'][0]['values'], 'value');
    same(['Rojo', 'Azul'], $values);
});

test('compare_products con un solo producto o ninguno que exista es un error limpio', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $tools = $app->service('tools');

    check(isset($tools->call('compare_products', ['ids' => [$ids['silla_roja'], 987654]])['error']), 'uno que no existe: no hay nada que comparar');
    check(isset($tools->call('compare_products', ['ids' => [$ids['silla_roja']]])['error']), 'con uno solo, el esquema lo rechaza');
    check(isset($tools->call('compare_products', ['ids' => [1, 2, 3, 4, 5]])['error']), 'y con cinco también');
});

test('Paco: «¿Me puedes comparar las dos sillas?» con IA: el modelo recibe los datos de las dos y su respuesta sale con las fichas', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $ai = assistantAi($app);
    $web = new TestClient($app);
    $ai->script(
        assistantTool('compare_products', ['ids' => [$ids['silla_roja'], $ids['silla_azul']]]),
        assistantTool('show_products', ['ids' => [$ids['silla_roja'], $ids['silla_azul']]]),
        assistantText('La roja cuesta menos y la azul está agotada.')
    );

    $r = assistantSay($web, 'Hola, buenas. Busco sillas de oficina para un despacho pequeño. ¿Me puedes comparar las dos sillas de oficina que tenéis?');

    $data = secondJson(assistantLastMessage($ai->asked[1])->toolResult['result']);
    same('Silla roja', $data['products'][0]['name']);
    same(5990, $data['products'][0]['price_cents'], 'el precio sale de la herramienta');
    same('Color', $data['differences'][0]['name']);
    same(2, count($r['cards']));
});

test('por reglas, «compara las dos» enseña el precio y la diferencia de cada una, sacados de la ficha', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);
    assistantSay($web, 'silla');

    $r = assistantSay($web, 'compara las dos primeras');

    contains('Así se comparan:', $r['text']);
    contains('Silla roja — ' . money(5990), $r['text']);
    contains('Color — ', $r['text'], 'en lo que se diferencian');
    contains('Silla roja: Rojo', $r['text']);
    contains('Silla azul: Azul', $r['text']);
    check(count($r['cards']) >= 2, 'con sus fichas');
});

test('devoluciones con el módulo activo: el plazo es el de su ajuste y el enlace, su página con el dominio', function (): void {
    $app = assistantApp(['modules.active' => ['assistant', 'search', 'cms', 'returns', 'orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs']], ['modules' => ['cms', 'returns']]);
    assistantShop($app);
    $app->settings()->set('returns.days', 21);

    $r = $app->service('tools')->call('store_info', ['topic' => 'returns']);

    contains('21 días', $r['returns']);
    same(21, $r['returns_days']);
    check(strpos($r['returns_url'], 'http') === 0 && strpos($r['returns_url'], '/desistimiento') !== false, 'la página del módulo, absoluta: ' . $r['returns_url']);
    check(!isset($r['returns_note']), 'no hace falta avisar de que falta la política');

    $web = new TestClient($app);
    $say = assistantSay($web, '¿puedo devolverla?');
    contains('21 días', $say['text']);
    contains('Más detalles: http', $say['text']);
    contains('/desistimiento', $say['text']);
    notContains('(/returns)', $say['text'], 'ni un enlace relativo inventado');
});

test('devoluciones sin el módulo ni política publicada: dice que lo pregunte a la tienda, con su contacto, y no inventa un plazo ni un enlace', function (): void {
    $app = assistantApp(['store.email' => 'tienda@ejemplo.test']);
    assistantShop($app);

    $r = $app->service('tools')->call('store_info', ['topic' => 'returns']);

    same('', $r['returns']);
    check(!isset($r['returns_days']) && !isset($r['returns_url']), 'ni plazo ni enlace');
    contains('ask the shop', $r['returns_note']);
    $web = new TestClient($app);
    $say = assistantSay($web, '¿puedo devolverla?');
    contains('No tengo a mano la política de devoluciones.', $say['text']);
    contains('tienda@ejemplo.test', $say['text'], 'con la forma de preguntar');
    notContains('30 días', $say['text']);
    notContains('/returns', $say['text']);
});

test('la instrucción pide citar solo el plazo y el enlace que salen de store_info, y comparar con compare_products', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $ai = assistantAi($app);
    $ai->script(assistantText('Hola.'));
    assistantSay(new TestClient($app), 'Hola');

    $system = $ai->asked[0]['request']['system'];
    contains('returns_days and returns_url', $system);
    contains('Never write a link or a deadline that did not come out of a tool', $system);
    contains('compare_products', $system);
    contains('only after those searches returned answer_kind none and after looking at the categories', $system, 'no se niega lo que no se ha buscado (P105)');
});

test('wantsCheapest entiende «barata», «más barato» y «cheapest» y no «baratija» ni «caro»', function (): void {
    foreach (['lanpara pa estudiar barata', 'el más barato', 'the cheapest lamp', 'cheap desk lamp', 'opciones economicas'] as $yes) {
        same(true, ShopTools::wantsCheapest($yes), $yes);
    }
    foreach (['lámpara cara', 'baratija', 'flexo led', ''] as $no) {
        same(false, ShopTools::wantsCheapest($no), $no);
    }
});

test('con el modelo al mando, una búsqueda con pocos resultados trae además los que casan sin una de sus palabras, como candidatos', function (): void {
    // 13/11/2026, en vivo: «soy hombre» tras «boda elegante». El modelo buscó «zapato elegante hombre», el buscador exige todas las
    // palabras y solo salieron unas zapatillas de casa cuya ficha dice «elegante»; el zapato de vestir, que no lo dice, no llegó.
    $app = assistantApp();
    assistantShop($app);
    $casa = aifecProduct($app, ['name' => 'Zapato de casa Grixo', 'summary' => 'Elegante y cálido, para hombre', 'price' => 2695]);
    $vestir = aifecProduct($app, ['name' => 'Zapato de vestir Executive', 'summary' => 'Derby marino para hombre', 'price' => 8995]);
    searchService($app)->reindexNow();
    $ai = assistantAi($app);
    $ai->script(
        assistantTool('search_products', ['query' => 'zapato elegante hombre']),
        assistantRound([['show_products', ['ids' => [$vestir]]]], 'Para una boda, el zapato de vestir Executive.')
    );
    $r = assistantSay(new TestClient($app), 'Busco unos zapatos para una boda, soy hombre');

    same(2, count($ai->asked), 'escribió y eligió la ficha en la misma vuelta: no se le pregunta otra vez');
    same('Para una boda, el zapato de vestir Executive.', $r['text']);
    $result = secondResult($ai, 1);
    same([$casa], array_column($result['items'], 'id'), 'lo exacto es lo que dice las tres palabras');
    same([$vestir], array_column($result['nearby'], 'id'), 'y como candidato llega el que casa sin una de ellas');
    same('elegante', $result['nearby'][0]['without'], 'dice qué palabra sobraba');
    same(money(8995), $result['nearby'][0]['price_formatted'], 'con su precio');
    same(['available', 'id', 'name', 'price_formatted', 'summary', 'without'], (static function (array $row): array {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    })($result['nearby'][0]), 'en corto: lo justo para decidir');
    contains('Not exact matches', $result['nearby_note']);
    same([$vestir], array_column($r['cards'], 'id'), 'el modelo puede elegir un candidato con show_products: su ficha sale');

    // Sin que nadie lo elija, un candidato no sale como ficha de serie ni cuenta como «encontrado».
    $ai->script(assistantTool('search_products', ['query' => 'zapato elegante hombre']), assistantText('Tenemos este zapato de casa.'));
    $again = assistantSay(new TestClient($app), 'Busco unos zapatos elegantes de hombre');
    same([$casa], array_column($again['cards'], 'id'), 'las fichas de serie son solo las exactas');

    // Los candidatos salen por turnos, uno de cada palabra quitada: una variante con muchos resultados no deja fuera a las demás.
    foreach (['Zapato de fiesta dorado', 'Zapato de fiesta rojo', 'Zapato de fiesta negro', 'Zapato de fiesta azul', 'Zapato de fiesta verde', 'Zapato de fiesta lila', 'Zapato de fiesta gris'] as $name) {
        aifecProduct($app, ['name' => $name, 'summary' => 'Elegante, para mujer', 'price' => 6995]);
    }
    searchService($app)->reindexNow();
    $wide = (new ShopTools($app))->searchProducts(['query' => 'zapato elegante hombre', 'limit' => 8], ['widen' => true]);
    same(6, count($wide['nearby']), 'como mucho seis candidatos (había ocho)');
    check(in_array($vestir, array_column($wide['nearby'], 'id'), true), 'el zapato de vestir sigue entre ellos aunque «zapato elegante» traiga siete de mujer');
    $by = array_count_values(array_column($wide['nearby'], 'without'));
    check(isset($by['hombre'], $by['elegante']) && $by['hombre'] <= 5, 'hay candidatos de las dos variantes (' . json_encode($by) . ')');

    // Fuera del asistente con IA (las reglas, una llamada directa) la búsqueda no se ensancha.
    $plain = (new ShopTools($app))->searchProducts(['query' => 'zapato elegante hombre'], []);
    check(!isset($plain['nearby']), 'sin el modelo al mando no hay candidatos');
    // Con dos palabras no se ensancha (quitar una deja una sola palabra suelta), y con tres resultados exactos tampoco hace falta.
    $two = (new ShopTools($app))->searchProducts(['query' => 'zapato casa'], ['widen' => true]);
    check(!isset($two['nearby']), 'una consulta de dos palabras no se ensancha');
});
