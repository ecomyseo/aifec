<?php
/**
 * AIFEC — P085, rápido y a la vista: la respuesta por partes (stream=1), el texto con formato de la ventana, lo que tarda cada
 * respuesta (ai_ms, tools_ms, rounds y el panel), el nivel de calidad, el historial recortado, y las funciones puras (importes y datos
 * enmascarados del texto, preferencias, opciones).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Assistant\Admin\AssistantAdminController;
use Aifec\Modules\Assistant\Prefs;
use Aifec\Modules\Assistant\TextGuard;
use Aifec\Modules\Assistant\VariantPick;

require_once __DIR__ . '/FastKit.php';

function fastJsPath(): string
{
    return dirname(__DIR__) . '/assets/assistant.js';
}

/**
 * Lo que devuelve el conversor de formato de assistant.js (con Node), o null si Node no está en esta máquina.
 *
 * @return array<int,mixed>|null
 */
function fastFormatCases(): ?array
{
    static $node = null;
    if ($node === null) {
        $out = [];
        $code = 1;
        @exec('node --version 2>&1', $out, $code);
        $node = $code === 0 ? 'node' : '';
    }
    if ($node === '') {
        return null;
    }
    $output = [];
    $code = 1;
    exec('node ' . escapeshellarg(__DIR__ . '/format_harness.js') . ' ' . escapeshellarg(fastJsPath()) . ' 2>&1', $output, $code);
    $data = json_decode(implode("\n", $output), true);
    check($code === 0 && is_array($data), 'el arnés de Node responde: ' . implode(' | ', $output));

    return is_array($data) ? $data : null;
}

// ------------------------------------------------------------------ la respuesta por partes

test('stream=1: las líneas llegan en orden (el estado de cada herramienta y, al final, «done») y «done» es la misma respuesta que sin stream', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantRound([['search_products', ['query' => 'silla roja']], ['cart_add', ['product_id' => $ids['silla_roja']]]]), assistantText('Aquí tienes la silla roja.'));

    $stream = assistantStream($web, 'Busco una silla roja y añádela');

    $lines = $stream['lines'];
    check(count($lines) >= 3, 'al menos dos estados y la respuesta');
    same(['status' => 'Buscando en la tienda…'], $lines[0], 'primero, lo que hace');
    same(['status' => 'Actualizando tu carrito…'], $lines[1], 'después, lo siguiente');
    $last = $lines[count($lines) - 1];
    check(isset($last['done']), 'la última línea es «done»');
    foreach (array_slice($lines, 0, -1) as $line) {
        check(isset($line['status']) && count($line) === 1, 'todas las demás son un estado');
    }
    check(substr($stream['raw'], -1) === "\n", 'cada línea acaba en salto de línea');

    // Lo mismo, sin stream, en otra tienda igual.
    [$app2, $ids2, $ai2, $web2] = fastShop();
    $ai2->script(assistantRound([['search_products', ['query' => 'silla roja']], ['cart_add', ['product_id' => $ids2['silla_roja']]]]), assistantText('Aquí tienes la silla roja.'));
    $plain = assistantSay($web2, 'Busco una silla roja y añádela');
    $strip = static function (array $answer): array {
        // La numeración de las fotos de prueba es global: se compara lo que ve la persona, no el nombre del fichero.
        foreach ($answer['cards'] as $i => $card) {
            unset($answer['cards'][$i]['image']);
        }

        return $answer;
    };
    same($strip($plain), $strip($last['done']), 'la respuesta de «done» es la de siempre');
});

test('sin stream (o con stream a 0) la respuesta es el JSON entero de siempre y no se escribe nada por partes', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantText('Hola, soy el asistente.'), assistantText('Otra vez.'));

    ob_start();
    $web->postJson('/asistente/mensaje', ['message' => 'Hola']);
    $written = (string) ob_get_clean();
    $plain = $web->last;

    same('', $written, 'nada escrito directamente');
    contains('application/json', $plain->contentType());
    same('Hola, soy el asistente.', $web->json()['text']);
    check(!isset($plain->headers['X-Accel-Buffering']), 'sin la cabecera de las partes');

    ob_start();
    $web->postJson('/asistente/mensaje', ['message' => 'Otra', 'stream' => 0]);
    $written = (string) ob_get_clean();
    same('', $written);
    same('Otra vez.', $web->json()['text']);
});

test('stream=1: cabeceras de partes sin caché ni búfer, el cuerpo lo escribe el núcleo y la cookie de la conversación nueva sigue funcionando', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $ai->script(assistantText('Hola.'), assistantText('Ya me conoces.'));

    $stream = assistantStream($web, 'Hola');

    $response = $stream['response'];
    contains('application/x-ndjson', $response->contentType());
    same('no', $response->headers['X-Accel-Buffering']);
    contains('no-store', $response->headers['Cache-Control']);
    check($response->writer === null && $response->body !== '', 'el cuerpo no lo escribe el controlador: lo escribe el núcleo, después de las cabeceras');
    $name = $app->cookieName('as');
    check(isset($response->cookies[$name]), 'la cookie de la conversación nueva va en la respuesta');
    check(isset($web->cookies[$name]), 'y el navegador la guarda');
    same('Hola.', $stream['lines'][count($stream['lines']) - 1]['done']['text']);

    $again = assistantStream($web, 'Otra vez');
    same(1, count(assistantRows($app, 'conversations')), 'el segundo mensaje sigue en la misma conversación');
    same('Ya me conoces.', $again['lines'][count($again['lines']) - 1]['done']['text']);
});

test('stream=1 en modo por reglas: el estado de la búsqueda y la respuesta; y si la IA cae, la respuesta llega igual', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    $rules = assistantStream($web, 'Busco una mesa de roble');

    same(['status' => 'Buscando en la tienda…'], $rules['lines'][0]);
    contains('Estos son los que mejor encajan', $rules['lines'][count($rules['lines']) - 1]['done']['text']);

    $ai = assistantAi($app);
    $ai->fail = true;
    $down = assistantStream($web, 'Hola');
    contains('Puedo ayudarte', $down['lines'][count($down['lines']) - 1]['done']['text'], 'la IA caída no deja la ventana colgada');
});

test('un mensaje vacío o un exceso de mensajes con stream=1 sigue contestando con el JSON de siempre y su código', function (): void {
    [$app, $ids, $ai, $web] = fastShop();

    ob_start();
    $empty = $web->postJson('/asistente/mensaje', ['message' => '   ', 'stream' => 1]);
    $written = (string) ob_get_clean();

    same(400, $empty->status);
    same('', $written, 'un error no se escribe por partes');
    contains('application/json', $empty->contentType());
});

// ------------------------------------------------------------------ el texto con formato

test('el conversor de formato convierte **negrita**, listas y saltos de línea, y deja como texto cualquier otra marca', function (): void {
    $cases = fastFormatCases();
    if ($cases === null) {
        check(true, 'Node no está en esta máquina: no se puede ejecutar el conversor');

        return;
    }
    same([['type' => 'p', 'lines' => [[['bold' => true, 'text' => 'negrita'], ['bold' => false, 'text' => ' y texto']]]]], $cases[0]);
    same([['type' => 'list', 'items' => [[['bold' => false, 'text' => 'uno']], [['bold' => false, 'text' => 'dos']]]]], $cases[1], 'lista con guiones');
    same('list', $cases[2][0]['type'], 'y con asteriscos');
    same([['bold' => false, 'text' => 'dos '], ['bold' => true, 'text' => 'x']], $cases[2][0]['items'][1], 'con negrita dentro');
    same(2, count($cases[3][0]['lines']), 'dos líneas, un párrafo con salto');
    same(2, count($cases[4]), 'una línea en blanco separa párrafos');
    same([], $cases[9], 'vacío no da nada');
    same([], $cases[10], 'null tampoco');
    $mixed = $cases[11];
    same(['p', 'list', 'p'], array_column($mixed, 'type'), 'párrafo, lista y párrafo');
    same(['bold' => true, 'text' => '64,85 euros'], $mixed[0]['lines'][0][1]);
});

test('el texto malicioso no se convierte en nada: <img onerror>, [x](javascript:…) y HTML dentro de la negrita quedan como texto', function (): void {
    $cases = fastFormatCases();
    if ($cases === null) {
        check(true, 'Node no está en esta máquina');

        return;
    }
    same([['bold' => false, 'text' => '<img src=x onerror=alert(1)>']], $cases[5][0]['lines'][0], 'una etiqueta es texto');
    same([['bold' => false, 'text' => '[pulsa aqui](javascript:alert(1))']], $cases[6][0]['lines'][0], 'un enlace con javascript: es texto: los enlaces solo salen de las acciones del servidor');
    same([['bold' => true, 'text' => '<b>x</b>']], $cases[7][0]['lines'][0], 'el HTML dentro de la negrita es el texto de la negrita');
    foreach ($cases as $blocks) {
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            check(in_array($block['type'], ['p', 'list'], true), 'solo hay párrafos y listas: ' . $block['type']);
        }
    }
    $js = (string) file_get_contents(fastJsPath());
    contains('document.createTextNode', $js, 'el texto se pinta con nodos de texto');
    notContains('innerHTML', $js);
    notContains('insertAdjacentHTML', $js);
    notContains('outerHTML', $js);
});

test('el guion pasa node --check, pinta el texto del asistente con el conversor, lee la respuesta por partes y aplica las etiquetas traducidas', function (): void {
    $out = [];
    $code = 1;
    @exec('node --check ' . escapeshellarg(fastJsPath()) . ' 2>&1', $out, $code);
    if ($code === 127 || ($code !== 0 && strpos(implode(' ', $out), 'not recognized') !== false)) {
        check(true, 'Node no está en esta máquina');
    } else {
        same(0, $code, 'node --check: ' . implode(' | ', $out));
    }
    $js = (string) file_get_contents(fastJsPath());
    contains("if (kind === 'is-bot')", $js, 'el texto del asistente lleva formato; el del cliente, no');
    contains('window.AifecAssistantFormat.parse(content)', $js);
    contains('response.body.getReader()', $js, 'lectura por partes');
    contains('application/x-ndjson', $js);
    contains('payload.stream = 1', $js);
    contains("action.type === 'labels'", $js, 'las etiquetas traducidas');
    contains('canStream()', $js, 'si el navegador no puede, se pide la respuesta entera');
    same(1, substr_count($js, 'window.fetch('), 'sigue habiendo un solo sitio que habla con el servidor');
    check(preg_match('/slice\(0, 8\)/', $js) === 1, 'hasta ocho botones cuando hay que elegir una opción');
});

// ------------------------------------------------------------------ cuánto tarda cada respuesta

test('cada respuesta guarda cuánto tardó la IA, cuánto las herramientas y cuántas rondas hubo', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $timer = assistantTimer($app);
    $app->hooks()->filter('assistant.tools', static function (array $tools) use ($timer): array {
        $tools['wait_probe'] = [
            'description' => 'Una herramienta de pruebas que tarda 700 ms.',
            'schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'handler' => static function () use ($timer): array {
                $timer->advance(700);

                return ['ok' => true];
            },
            'writes' => false,
        ];

        return $tools;
    });
    $ai = assistantAi($app);
    $ai->timer = $timer;
    $ai->delays = [300, 500];
    $ai->script(assistantTool('wait_probe', []), assistantText('Hecho.'));
    $web = new TestClient($app);

    assistantSay($web, 'Haz la prueba');

    $row = assistantRows($app, 'conversation_messages', "role = 'assistant'")[0];
    same(800, (int) $row['ai_ms'], 'la IA: 300 + 500');
    same(700, (int) $row['tools_ms'], 'las herramientas: 700');
    same(1, (int) $row['rounds'], 'una ronda de herramientas');
    $user = assistantRows($app, 'conversation_messages', "role = 'user'")[0];
    same(null, $user['ai_ms'], 'los mensajes del cliente no llevan tiempos');
});

test('una respuesta por reglas no usa la IA: ai_ms queda vacío, y no cuenta en las estadísticas', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    assistantSay($web, 'Busco una mesa de roble');

    $row = assistantRows($app, 'conversation_messages', "role = 'assistant'")[0];
    same(null, $row['ai_ms']);
    same(0, (int) $row['rounds']);
    check($row['tools_ms'] !== null, 'las herramientas sí se miden');
});

test('el panel enseña la mediana y el percentil 90 de los últimos 7 días, solo de las respuestas con IA', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = adminClient($app);
    $conversation = (new \Aifec\Modules\Assistant\Conversations($app))->create('es')['id'];
    $now = $app->clock()->sql();
    $old = $app->clock()->sql($app->clock()->now() - 8 * 86400);
    $insert = static function (?int $ai, ?int $tools, string $when) use ($app, $conversation): void {
        $app->db()->insert('conversation_messages', ['conversation_id' => $conversation, 'role' => 'assistant', 'content' => 'x', 'ai_ms' => $ai, 'tools_ms' => $tools, 'rounds' => 1, 'created_at' => $when]);
    };
    foreach ([1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000] as $total) {
        $insert($total - 200, 200, $now);
    }
    $insert(90000, 0, $old);
    $insert(null, 500, $now);

    $page = $web->get('/admin/assistant');

    contains('Tiempo de respuesta, últimos 7 días: mediana 5,5 s, percentil 90 9,0 s (10 respuestas con IA).', $page->body);
});

test('sin respuestas con IA el panel no enseña tiempos, y las cuentas de la mediana y del percentil son las de siempre', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = adminClient($app);
    notContains('Tiempo de respuesta', $web->get('/admin/assistant')->body);

    same(['n' => 0, 'median' => 0, 'p90' => 0], AssistantAdminController::summary([]));
    same(['n' => 1, 'median' => 700, 'p90' => 700], AssistantAdminController::summary([700]));
    same(['n' => 4, 'median' => 2500, 'p90' => 4000], AssistantAdminController::summary([4000, 1000, 3000, 2000]), 'con una cantidad par, la media de las dos del medio');
    same(['n' => 5, 'median' => 3000, 'p90' => 5000], AssistantAdminController::summary([5000, 1000, 3000, 2000, 4000]));
    $twenty = range(1, 20);
    same(18, AssistantAdminController::summary($twenty)['p90'], 'el percentil 90 de 20 valores es el 18.º');
});

test('el ajuste «assistant.quality» es un desplegable con «normal» de serie, y la calidad alta pide la respuesta final al modelo estándar', function (): void {
    $settings = require dirname(__DIR__) . '/settings.php';
    $field = $settings['tabs']['asgeneral']['fields']['assistant.quality'];
    same('select', $field['type']);
    same('normal', $field['default']);
    same(['normal', 'high'], array_keys($field['options']));

    foreach ([['normal', ['economy', 'economy']], ['high', ['economy', 'standard']]] as [$quality, $tiers]) {
        $app = assistantApp(['assistant.quality' => $quality]);
        assistantShop($app);
        $ai = assistantAi($app);
        $ai->script(assistantTool('list_categories', []), assistantText('Hay varias.'));
        assistantSay(new TestClient($app), '¿Qué categorías hay?');
        same($tiers, array_column($ai->asked, 'tier'), 'calidad «' . $quality . '»');
    }
});

test('la IA pide 1.024 tokens de tope (piensa dentro de él) y la instrucción pide 60 palabras', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $ai = assistantAi($app);
    $ai->script(assistantTool('list_categories', []), assistantText('Hay varias.'));

    assistantSay(new TestClient($app), '¿Qué categorías hay?');

    foreach ($ai->asked as $ask) {
        same(1024, $ask['request']['max_tokens']);
    }
    contains('at most 40 words', $ai->asked[0]['request']['system']);
});

test('el resultado de una ronda anterior viaja en una línea y solo el de la última ronda entero; lo que solo sirve para pintar no llega al modelo', function (): void {
    [$app, $ids, $ai, $web, $jamones] = fastShop();
    $ai->script(assistantTool('search_products', ['query' => 'jamón ibérico de bellota']), assistantTool('list_categories', []), assistantText('Listo.'));

    assistantSay($web, 'Busco jamón ibérico de bellota');

    same(3, count($ai->asked));
    $second = $ai->asked[1]['request']['messages'];
    $search = $second[count($second) - 1]->toolResult['result'];
    notContains('"url"', $search, 'sin direcciones de ficha');
    notContains('"image"', $search, 'ni de imagen');
    contains('"price_formatted"', $search);
    $third = $ai->asked[2]['request']['messages'];
    $old = $third[count($third) - 3]->toolResult['result'];
    $last = $third[count($third) - 1]->toolResult['result'];
    contains('search_products (earlier in this answer)', $old, 'la ronda anterior, en una línea');
    contains('kind=exact', $old);
    contains('#' . $jamones[0] . ' Jamón ibérico de bellota Reserva', $old, 'con lo que hace falta para seguir: id y nombre');
    check(strlen($old) < strlen($search) / 2 + 200, 'mucho más corta que el resultado entero');
    contains('tool_list_categories', $last, 'la última ronda, entera');
    notContains('earlier in this answer', $last);
});

// ------------------------------------------------------------------ funciones puras

test('los importes de un texto se leen en céntimos, con los formatos de castellano e inglés', function (): void {
    same([5990], TextGuard::amounts('La silla cuesta 59,90 €.'));
    same([5990], TextGuard::amounts('It costs €59.90 today'));
    same([150000], TextGuard::amounts('Son 1.500 euros en total'));
    same([100], TextGuard::amounts('Solo 1,00 € hoy'));
    same([4995], TextGuard::amounts('Precio: EUR 49,95'));
    same([6485], TextGuard::amounts("Total: 64,85\u{a0}€"));
    same([], TextGuard::amounts('Quedan 3 unidades y llega en 24-48 h'), 'una cifra suelta no es un importe');
    same([5990, 495], TextGuard::amounts('La silla 59,90 € y el envío 4,95 €'));
    same(6485, TextGuard::parse('64,85'));
    same(6485, TextGuard::parse('64.85'));
    same(100000, TextGuard::parse('1.000'));
    same(null, TextGuard::parse('abc'));
});

test('una frase con un importe que no salió de ninguna herramienta se quita; las demás se quedan', function (): void {
    $text = 'La silla cuesta 1,00 € hoy. Es muy cómoda. El envío son 4,95 €.';

    same('Es muy cómoda. El envío son 4,95 €.', TextGuard::dropUnknownAmounts($text, [495]));
    same($text, TextGuard::dropUnknownAmounts($text, [100, 495]));
    same('', TextGuard::dropUnknownAmounts('Solo 2,00 € en total.', []));
    same("Línea buena.\nOtra buena.", TextGuard::dropUnknownAmounts("Línea buena.\n- Cuesta 9,99 €\nOtra buena.", []), 'también en las listas');
    same('Quedan 3 y llegan en 48 h.', TextGuard::dropUnknownAmounts('Quedan 3 y llegan en 48 h.', []), 'sin importes no hay nada que comprobar');
    $collected = TextGuard::collect(['price' => '59,90 €', 'price_cents' => 5990, 'cart' => ['total_cents' => 6485, 'shipping' => ['price' => '4,95 €']], 'name' => 'Silla']);
    sort($collected);
    same([495, 5990, 6485], $collected, 'los importes de «_cents» y los que van escritos con formato');
});

test('una línea con un dato enmascarado se quita, la negrita no, y el resto del texto queda como estaba', function (): void {
    same("Hola\nAdiós", TextGuard::stripMasked("Hola\nEntregar en C*** d*** M*** 5***\nAdiós"));
    same("Hola\nAdiós", TextGuard::stripMasked("Hola\nCorreo: a***@correo.test\nAdiós"));
    same('Es **importante** que lo sepas', TextGuard::stripMasked('Es **importante** que lo sepas'), 'dos asteriscos son negrita, no un dato enmascarado');
    same('', TextGuard::stripMasked('Teléfono ***001'));
    same('Sin nada raro.', TextGuard::stripMasked('Sin nada raro.'));
});

test('las preferencias de envío y de pago se leen del mensaje; una pregunta no cuenta; el más rápido y el más barato se eligen por plazo y por precio', function (): void {
    same(['ship' => 'fastest', 'pay' => 'transfer'], Prefs::detect(\Aifec\Modules\Assistant\RuleBrain::norm('Quiero el envío más rápido y pagar por transferencia'), false));
    same(['ship' => 'cheapest'], Prefs::detect(\Aifec\Modules\Assistant\RuleBrain::norm('Mándamelo por el envío más barato'), false));
    same(['pay' => 'cod'], Prefs::detect('contra reembolso', false));
    same(['ship' => 'fastest'], Prefs::detect('el mas rapido', false, 'carrier'), 'eligiendo el envío, «el más rápido» basta');
    same([], Prefs::detect('el mas rapido', false), 'sin hablar de envío, no es una preferencia de envío');
    same([], Prefs::detect(\Aifec\Modules\Assistant\RuleBrain::norm('¿Aceptáis tarjeta?'), true), 'una pregunta no cuenta');
    same(['pay' => 'card'], Prefs::detect('quiero pagar con tarjeta', false));
    $options = [
        ['id' => 1, 'name' => 'Estándar', 'delay' => '24-48 h', 'price_cents' => 495],
        ['id' => 2, 'name' => 'Urgente', 'delay' => '24 h', 'price_cents' => 1295],
        ['id' => 3, 'name' => 'Económico', 'delay' => '3-5 días', 'price_cents' => 295],
    ];
    same(2, Prefs::shipping('fastest', $options)['id'], '24 h gana a 24-48 h');
    same(3, Prefs::shipping('cheapest', $options)['id']);
    same(null, Prefs::shipping('fastest', []));
    $pays = [['id' => 'transfer', 'name' => 'Transferencia bancaria', 'kind' => 'offline'], ['id' => 'gw', 'name' => 'Pasarela de pruebas', 'kind' => 'redirect'], ['id' => 'cod', 'name' => 'Contra reembolso', 'kind' => 'offline']];
    same('cod', Prefs::payment('cod', $pays)['id']);
    same('transfer', Prefs::payment('transfer', $pays)['id']);
    same('gw', Prefs::payment('card', $pays)['id'], 'tarjeta es pagar en línea');
    same(null, Prefs::payment('bizum', $pays));
});

test('elegir una opción: una variante, varias por afinar, una agotada o nada de eso', function (): void {
    $need = [
        'product_id' => 7, 'name' => 'Camiseta',
        'options' => [['name' => 'Color', 'values' => [['label' => 'Negro', 'available' => true], ['label' => 'Rojo', 'available' => true], ['label' => 'Azul', 'available' => false]]]],
        'variants' => [
            ['id' => 11, 'label' => 'Negro', 'available' => true, 'value_labels' => ['Negro']],
            ['id' => 12, 'label' => 'Rojo', 'available' => true, 'value_labels' => ['Rojo']],
            ['id' => 13, 'label' => 'Azul', 'available' => false, 'value_labels' => ['Azul']],
        ],
    ];
    $pick = VariantPick::fromNeed($need, 2);
    same(7, $pick['id']);
    same(2, $pick['q']);
    same(['Negro', 'Rojo'], VariantPick::chips($pick), 'solo las que se pueden comprar');
    same(['Azul'], $pick['s']);
    same('one', VariantPick::resolve($pick, 'en negro por favor')['status']);
    same(11, VariantPick::resolve($pick, 'el NEGRO')['variant']);
    same(12, VariantPick::resolve($pick, 'Rojo')['variant']);
    same('sold_out', VariantPick::resolve($pick, 'en azul')['status']);
    same('none', VariantPick::resolve($pick, '¿cuánto cuesta el envío?')['status']);
    same('none', VariantPick::resolve($pick, 'sí')['status']);
    same(null, VariantPick::fromNeed(['product_id' => 7, 'variants' => [['id' => 13, 'available' => false]]], 1), 'si todo está agotado no hay nada que elegir');
});
