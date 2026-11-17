<?php
/**
 * AIFEC — pruebas de la tercera ronda con cinco clientes (P093): los datos de quien escribe en inglés y con etiquetas, la empresa
 * que llega a la factura, el carrito que no se duplica entre turnos, ningún callejón cuando la IA no contesta, lo agotado detrás,
 * quién paga la devolución, «Envío: Envío estándar», el idioma hasta el final, ningún texto técnico a la vista, el nombre que no
 * se acorta, el total de varias unidades y el pedido de otra persona. Cada prueba parte de una frase literal de las conversaciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Assistant\Answer;
use Aifec\Modules\Assistant\OrderReply;
use Aifec\Modules\Assistant\QtyQuote;
use Aifec\Modules\Assistant\RuleBrain;
use Aifec\Modules\Assistant\TextGuard;
use Aifec\Modules\Assistant\ToolRunner;

require_once __DIR__ . '/SecondKit.php';
require_once __DIR__ . '/OrderChatKit.php';

const THIRD_HANNA = "Yes, let's go to checkout. My details: Hanna Berg, hanna.berg@ejemplo.test, phone +34 999000005, address Carrer de l'Exemple 9, 2n 1a, 08001 Barcelona (Spain). Invoice to company Berg Studio SL, VAT ID ESB00000000. I want cash on delivery.";

const THIRD_PACO = 'Hola, buenas. Estoy amueblando un despacho pequeño y busco una silla de oficina. ¿Me puedes comparar las dos sillas de oficina que tenéis? Quiero saber diferencias concretas, no solo lo bonitas que son.';

/**
 * La tienda de la tercera ronda: compra en el chat, IA de mentira, dos sillas de oficina con sus datos, el flexo, una lámpara de pie
 * AGOTADA y una lámpara de mesa barata. El carrito del navegador empieza vacío.
 *
 * @return array{0:App,1:array<string,int>,2:AssistantFakeAi,3:TestClient}
 */
function thirdShop(array $ajustes = [], array $opciones = []): array
{
    $app = chatApp($ajustes, $opciones);
    $ids = assistantShop($app);
    $office = aifecCategory($app, 'Sillas de oficina');
    $lamps = aifecCategory($app, 'Lámparas');
    $ids['basica'] = aifecProduct($app, ['name' => 'Silla Básica Home', 'summary' => 'Silla de oficina tapizada', 'categories' => [$office], 'price' => 8900, 'stock' => 8,
        'facts' => [['label' => 'Material', 'value' => 'Tela'], ['label' => 'Garantía', 'value' => '24 meses']]]);
    $ids['ergo'] = aifecProduct($app, ['name' => 'Silla Ergo Pro Malla', 'summary' => 'Silla de oficina de malla con soporte lumbar', 'categories' => [$office], 'price' => 24900, 'stock' => 5,
        'facts' => [['label' => 'Material', 'value' => 'Malla transpirable'], ['label' => 'Garantía', 'value' => '36 meses']]]);
    $ids['flexo'] = aifecProduct($app, ['name' => 'Flexo LED Arco', 'summary' => 'Flexo de escritorio con luz LED', 'categories' => [$lamps], 'price' => 5990, 'stock' => 5]);
    $ids['faro'] = aifecProduct($app, ['name' => 'Lámpara de Pie Faro', 'summary' => 'Lámpara de salón', 'categories' => [$lamps], 'price' => 12900, 'stock' => 0]);
    $ids['nube'] = aifecProduct($app, ['name' => 'Lámpara de Mesa Nube', 'summary' => 'Lámpara de mesa pequeña', 'categories' => [$lamps], 'price' => 4500, 'stock' => 3]);
    searchService($app)->reindexNow();
    $ai = assistantAi($app);

    return [$app, $ids, $ai, new TestClient($app)];
}

/** El estado guardado de la (única) conversación. */
function thirdState(App $app): array
{
    $rows = assistantRows($app, 'conversations');
    $state = json_decode((string) $rows[count($rows) - 1]['state'], true);

    return is_array($state) ? $state : [];
}

/**
 * Lo que devolvió la herramienta en la petición número $n a la IA, ya leído.
 *
 * @return array<string,mixed>
 */
function thirdResult(AssistantFakeAi $ai, int $n): array
{
    $fenced = (string) assistantLastMessage($ai->asked[$n])->toolResult['result'];
    $start = strpos($fenced, '{');
    $end = strrpos($fenced, '}');
    $decoded = $start !== false && $end !== false ? json_decode(substr($fenced, $start, $end - $start + 1), true) : null;

    return is_array($decoded) ? $decoded : [];
}

/**
 * Los transportistas de la tienda de la prueba real: el de serie pasa a llamarse «Envío estándar (48-72 h)» y se añade «Envío
 * urgente (24 h)» (12,90 € a la Península). Devuelve el id del urgente.
 */
function thirdCarrier(App $app): int
{
    $app->db()->run('UPDATE {carriers} SET name = ? WHERE code = ?', ['Envío estándar (48-72 h)', 'std']);
    $id = shippingAddCarrier($app, 'urg', ['name' => 'Envío urgente (24 h)']);
    shippingAddRate($app, $id, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 1290);

    return $id;
}

/**
 * Las unidades de un producto en el carrito del navegador.
 */
function thirdQty(App $app, TestClient $web, int $productId): int
{
    $qty = 0;
    foreach (assistantCartLines($app, $web) as $line) {
        if ((int) $line['product_id'] === $productId) {
            $qty += (int) $line['qty'];
        }
    }

    return $qty;
}

// ================================================================== 1 y 2. los datos del cliente, por campos y con etiquetas

test('Hanna, con IA y su frase literal: se guarda «Hanna Berg» (no «Yes»), la calle sin «Address», el piso, la empresa y el NIF como lo dio', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    chatCart($app, $web, $ids['flexo']);
    $ai->script(assistantText('Thanks, I have your details.'));

    assistantSay($web, THIRD_HANNA);

    $draft = chatDraft($app, $web);
    same('Hanna', $draft['shipping']['first_name']);
    same('Berg', $draft['shipping']['last_name']);
    same("Carrer de l'Exemple 9", $draft['shipping']['line1']);
    same('2n 1a', $draft['shipping']['line2']);
    same('Barcelona', $draft['shipping']['city']);
    same('Berg Studio SL', $draft['shipping']['company'], 'la empresa no se pierde');
    same('ESB00000000', $draft['shipping']['vat_id'], 'el NIF como se dio');
    contains('company', assistantLastMessage($ai->asked[0])->content, 'y al modelo se le dice que se guardó la empresa');
});

test('los campos que el modelo manda ya separados se guardan sin pasar por el lector de texto: el texto de respaldo se ignora', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    chatCart($app, $web, $ids['flexo']);
    $ai->script(
        assistantTool('checkout_set_customer', [
            'first_name' => 'Hanna', 'last_name' => 'Berg', 'email' => 'Hanna.Berg@Ejemplo.test', 'phone' => '+34 999 000 005',
            'line1' => "Carrer de l'Exemple 9", 'line2' => '2n 1a', 'postcode' => '08001', 'city' => 'Barcelona', 'country' => 'es',
            'company' => 'Berg Studio SL', 'vat_id' => 'ES-B000.000.00', 'text' => 'Yes, Address Calle Falsa 1, 28013 Madrid',
        ]),
        assistantText('Saved.')
    );

    assistantSay($web, 'Here you go, those are my details for the invoice');

    $draft = chatDraft($app, $web);
    same('Hanna', $draft['shipping']['first_name'], 'ni «Yes» del texto');
    same("Carrer de l'Exemple 9", $draft['shipping']['line1'], 'ni la calle del texto');
    same('08001', $draft['shipping']['postcode']);
    same('hanna.berg@ejemplo.test', $draft['email']);
    same('999000005', $draft['shipping']['phone'], 'el teléfono en sus cifras');
    same('ESB00000000', $draft['shipping']['vat_id'], 'el NIF sin guiones ni puntos y con su «ES»');
    same('Berg Studio SL', $draft['shipping']['company']);
    $result = thirdResult($ai, 1);
    same([], $result['errors']);
});

test('un nombre que es un «Yes», un «Ok» o una etiqueta («Address») se rechaza y se pide; el resto de datos sí se guarda', function (): void {
    foreach ([['Yes', ''], ['Ok', ''], ['Hanna', 'Address'], ['Hola', 'Vale']] as $pair) {
        [$app, $ids, $ai, $web] = thirdShop();
        chatCart($app, $web, $ids['flexo']);
        $ai->script(assistantTool('checkout_set_customer', ['first_name' => $pair[0], 'last_name' => $pair[1], 'email' => 'hanna.berg@ejemplo.test']), assistantText('¿Cómo te llamas?'));

        assistantSay($web, 'Te paso mis datos');

        $draft = chatDraft($app, $web);
        same('', $draft['shipping']['first_name'], 'no se guarda «' . $pair[0] . '»');
        same('hanna.berg@ejemplo.test', $draft['email'], 'el correo sí');
        $result = thirdResult($ai, 1);
        contains('no es un nombre', (string) $result['errors']['first_name']);
        contains('name', implode(',', $result['missing_fields']), 'y el nombre sigue faltando');
    }
});

test('el lector de la caja por reglas tampoco toma «Yes» por un nombre: con la frase de Hanna el resumen dice «Hanna Berg»', function (): void {
    [$app, $ids, , $web] = thirdShop();
    $app->service('ai')->on = false;
    chatCart($app, $web, $ids['flexo']);
    assistantSay($web, 'I want to buy it');

    $r = assistantSay($web, THIRD_HANNA);

    $draft = chatDraft($app, $web);
    same('Hanna', $draft['shipping']['first_name']);
    notContains('Yes', (string) $draft['shipping']['first_name'] . (string) $draft['shipping']['last_name']);
    notContains('Address', (string) $draft['shipping']['line1']);
    contains('Thanks, I have saved your details.', $r['text'], 'contesta en inglés');
});

// ================================================================== 3. la empresa llega al resumen, al pedido y a la factura

test('la empresa sale en el resumen del chat («Factura a: Berg Studio SL · NIF ESB00000000») y en la tarjeta del pedido', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    chatCart($app, $web, $ids['flexo']);
    $ai->script(
        assistantRound([['checkout_set_customer', ['first_name' => 'Hanna', 'last_name' => 'Berg', 'email' => 'hanna.berg@ejemplo.test', 'phone' => '999000005', 'line1' => 'Calle Mayor 1', 'postcode' => '28013', 'city' => 'Madrid', 'company' => 'Berg Studio SL', 'vat_id' => 'ESB00000000']], ['checkout_set_payment', ['method' => 'transfer']], ['checkout_review', []]]),
        assistantText('Aquí tienes el resumen.')
    );

    $r = assistantSay($web, 'Quiero pedirlo ya');

    contains('Factura a: Berg Studio SL · NIF ESB00000000', $r['text']);
    $card = chatAction($r, 'confirm_order');
    check($card !== null, 'con la tarjeta');
    same('Berg Studio SL', $card['company']);
    same('ESB00000000', $card['vat_id']);
});

test('la empresa llega al pedido hecho: a su dirección de facturación, a la página del pedido y a la factura', function (): void {
    [$app, $ids, $ai, $web] = thirdShop([], ['modules' => ['invoices']]);
    chatCart($app, $web, $ids['flexo']);
    $ai->script(
        assistantRound([['checkout_set_payment', ['method' => 'transfer']], ['checkout_review', []]]),
        assistantText('This is your order.')
    );
    assistantSay($web, THIRD_HANNA);

    $placed = assistantSay($web, 'yes, place the order');

    $orders = chatOrders($app);
    same(1, count($orders), 'el pedido está hecho');
    $order = $app->service('orders')->find((int) $orders[0]['id']);
    same('Berg Studio SL', $order['billing']['company']);
    same('ESB00000000', $order['billing']['vat_id']);
    same('Hanna', $order['billing']['first_name']);
    $url = (string) chatAction($placed, 'order')['url'];
    $page = (string) $web->get($url)->body;
    contains('Berg Studio SL', $page, 'en la página del pedido');
    contains('ESB00000000', $page);
    $invoice = \Aifec\Modules\Invoices\InvoiceData::invoice($app, $order);
    same('Berg Studio SL', $invoice['receiver']['name'], 'y en la factura');
    same('ESB00000000', $invoice['receiver']['vat_id']);
});

test('OrderReply: la línea de la factura sale solo con empresa o NIF, y el resumen de siempre la lleva', function (): void {
    $card = ['type' => 'confirm_order', 'lines' => [['name' => 'Flexo LED Arco', 'qty' => 1, 'total' => '59,90 €']], 'discounts' => [], 'fees' => [],
        'shipping' => ['name' => 'Estándar', 'price' => '4,90 €'], 'total' => '64,80 €', 'name' => 'Hanna Berg', 'address' => ['Calle Mayor 1', '28013 Madrid', 'ES'],
        'company' => 'Berg Studio SL', 'vat_id' => 'ESB00000000', 'payment' => 'Transferencia bancaria'];
    same('Factura a: Berg Studio SL · NIF ESB00000000', OrderReply::invoiceLine($card));
    contains('Factura a: Berg Studio SL · NIF ESB00000000', OrderReply::reviewText($card));
    same('', OrderReply::invoiceLine(['name' => 'Ana Ruiz', 'company' => '', 'vat_id' => '']));
    same('Factura a: Ana Ruiz · NIF 12345678Z', OrderReply::invoiceLine(['name' => 'Ana Ruiz', 'company' => '', 'vat_id' => '12345678Z']), 'sin empresa, la persona');
    $a = new Answer();
    $a->text = 'Este es tu resumen.';
    $a->actions[] = $card;
    OrderReply::withDelivery($a, false);
    OrderReply::withDelivery($a, false);
    same(1, substr_count($a->text, 'Factura a:'), 'con IA también, una vez');
});

// ================================================================== 4. añadir no duplica entre turnos

test('Lucía: el flexo añadido en un turno no se suma otra vez cuando en el siguiente solo dice «lucia navarro» y el modelo vuelve a llamar a añadir', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['flexo']]), assistantText('He añadido el Flexo LED Arco a tu cesta.'));
    assistantSay($web, 'vale el flexo led arco me lo pillo. pagar contra reembolso se puede?');
    same(1, thirdQty($app, $web, $ids['flexo']));
    $asked = count($ai->asked);
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['flexo']]), assistantText('¡Gracias, Lucía!'));

    $r = assistantSay($web, 'lucia navarro');

    same(1, thirdQty($app, $web, $ids['flexo']), 'sigue habiendo UNO');
    $result = thirdResult($ai, $asked + 1);
    same(true, $result['already_in_cart']);
    same(1, $result['qty_in_cart']);
    check(chatAction($r, 'cart_changed') === null, 'el carrito no cambia');
    $log = json_decode((string) assistantRows($app, 'conversation_messages', "role = 'assistant'")[1]['tools'], true);
    same(true, $log[count($log) - 1]['in_cart'] ?? $log[0]['in_cart'], 'el registro lo apunta');
});

test('sí se suma cuando el cliente lo pide de forma expresa en ese mensaje: «otra», «one more», «añade 2»', function (): void {
    foreach ([['ponme otra igual', 1, 2], ['one more please', 1, 2], ['añade 2 más', 2, 3]] as $case) {
        [$app, $ids, $ai, $web] = thirdShop();
        chatCart($app, $web, $ids['flexo']);
        $ai->script(assistantTool('cart_add', ['product_id' => $ids['flexo'], 'qty' => $case[1]]), assistantText('Hecho.'));

        assistantSay($web, $case[0]);

        same($case[2], thirdQty($app, $web, $ids['flexo']), $case[0]);
    }
});

test('ToolRunner::asksMore decide por el mensaje: «otra», «otro más», «añade 2», «one more», «another» sí; «no hay otra más barata» y «lucia navarro» no', function (): void {
    foreach (['otra', 'quiero otro más', 'añade 2', 'ponme dos', 'one more', 'another one', 'add 3', 'una más'] as $yes) {
        same(true, ToolRunner::asksMore($yes), $yes);
    }
    foreach (['lucia navarro', 'no hay otra más barata?', 'añádelo al carrito', 'vale', '', 'quiero 2 sillas'] as $no) {
        same(false, ToolRunner::asksMore($no), $no);
    }
});

test('«Quiero 2 sillas» con el carrito vacío sigue dando 2; y el botón «añadir» de una ficha (sin mensaje) es una orden expresa y suma', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['basica'], 'qty' => 2]), assistantText('Dos sillas.'));
    assistantSay($web, 'Quiero 2 sillas básicas');
    same(2, thirdQty($app, $web, $ids['basica']));

    $assistant = $app->service('assistant');
    $conversation = (int) assistantRows($app, 'conversations')[0]['id'];
    aifecCartRun($web, static function () use ($assistant, $conversation, $ids): void {
        $assistant->addToCart($conversation, $ids['basica']);
    });
    same(3, thirdQty($app, $web, $ids['basica']), 'el botón suma');
});

test('en modo reglas, igual: «añade el primero» dos veces deja una unidad y dice que ya está; «añade otro al carrito» suma', function (): void {
    [$app, $ids, , $web] = thirdShop();
    $app->service('ai')->on = false;
    assistantSay($web, 'flexo led');
    assistantSay($web, 'añade el primero');
    same(1, thirdQty($app, $web, $ids['flexo']));

    $again = assistantSay($web, 'añade el primero');

    same(1, thirdQty($app, $web, $ids['flexo']), 'no se suma');
    contains('ya está en tu carrito: 1 unidad', $again['text']);
    assistantSay($web, 'añade otro al carrito');
    same(2, thirdQty($app, $web, $ids['flexo']), 'con «otro» sí');
});

// ================================================================== 5. ningún callejón en el primer mensaje

test('Paco, primer mensaje y la IA caída: no hay callejón, se enseñan las sillas de oficina con sus datos de comparación', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->fail = true;

    $r = assistantSay($web, THIRD_PACO);

    notContains('No he podido contestar', $r['text']);
    contains('Esto es lo que tengo de silla de oficina:', $r['text']);
    $names = array_column($r['cards'], 'name');
    check(in_array('Silla Básica Home', $names, true) && in_array('Silla Ergo Pro Malla', $names, true), 'las dos sillas: ' . json_encode($names));
    contains('Silla Básica Home — ' . money(8900), $r['text'], 'con su precio');
    contains('Garantía — ', $r['text'], 'y en qué se diferencian');
});

test('la IA contesta vacío al primer mensaje de productos: tampoco hay callejón', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(assistantText(''));

    $r = assistantSay($web, THIRD_PACO);

    notContains('No he podido contestar', $r['text']);
    check(count($r['cards']) >= 2, 'con fichas');
    $user = assistantRows($app, 'conversation_messages', "role = 'user'");
    same(0, (int) $user[0]['unanswered'], 'no queda como sin respuesta');
});

test('la IA cae con una pregunta de envío: contesta la regla de siempre; y sin nada que enseñar, el callejón con sus tres botones', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->fail = true;
    $ship = assistantSay($web, '¿Cuánto tarda el envío a mi casa?');
    contains('código postal', $ship['text']);
    notContains('No he podido contestar', $ship['text']);

    $moon = assistantSay(new TestClient($app), '¿Y esto cómo va con la luna?');
    contains('No he podido contestar eso', $moon['text']);
    same(['Devoluciones y reembolsos', 'Formas de pago', '¿Dónde está mi pedido?'], $moon['suggestions']);
    same([], $moon['cards']);
});

test('RuleBrain::productPhrases saca lo que nombra como producto una frase larga, con las palabras del cliente', function (): void {
    same(['silla de oficina', 'sillas de oficina'], array_slice(RuleBrain::productPhrases(THIRD_PACO), 0, 2));
    same(['desk lamp'], array_slice(RuleBrain::productPhrases("Hi! I'm looking for a desk lamp, something under 80 euros"), 0, 1));
    same([], RuleBrain::productPhrases('¿Y esto cómo va con la luna?'));
});

// ================================================================== 6. lo agotado detrás y sin «añadir el primero»

test('Lucía: «lanpara pa estudiar barata» con la lámpara de pie agotada delante: no se sugiere «Añadir el primero al carrito»', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(
        assistantTool('search_products', ['query' => 'lámpara de pie']),
        assistantTool('show_products', ['ids' => [$ids['faro']]]),
        assistantText('Tenemos la Lámpara de Pie Faro.')
    );

    $r = assistantSay($web, 'hola, quiero una lanpara pa estudiar barata');

    same(['Lámpara de Pie Faro'], array_column($r['cards'], 'name'));
    same('out_of_stock', $r['cards'][0]['status']);
    same(false, $r['cards'][0]['can_add']);
    check(!in_array('Añadir el primero al carrito', $r['suggestions'], true), 'sin «añadir»: ' . json_encode($r['suggestions']));
});

test('en una búsqueda lo que se puede comprar va delante del agotado y, con «barata», por precio entre lo disponible', function (): void {
    [$app, $ids] = thirdShop();
    $tools = $app->service('tools');

    foreach (['lámpara', 'lampara barata'] as $q) {
        $r = $tools->call('search_products', ['query' => $q, 'limit' => 8]);
        $items = $r['items'];
        check(count($items) >= 2, 'encuentra lámparas con «' . $q . '»');
        $seenSold = false;
        foreach ($items as $item) {
            if (!$item['available']) {
                $seenSold = true;
            }
            check(!$seenSold || !$item['available'], 'nada disponible detrás de un agotado con «' . $q . '»: ' . json_encode(array_column($items, 'name')));
        }
        check($items[0]['available'], 'el primero se puede comprar');
    }
    $cheap = $tools->call('search_products', ['query' => 'lampara barata', 'limit' => 8])['items'];
    $prices = [];
    foreach ($cheap as $item) {
        if ($item['available']) {
            $prices[] = (int) $item['price_cents'];
        }
    }
    $sorted = $prices;
    sort($sorted);
    same($sorted, $prices, 'por precio entre los disponibles');

    // Aunque el buscador ponga la agotada la primera, la herramienta la deja detrás.
    $spy = secondSpy($app);
    $spy->force = static function (string $query) use ($ids): array {
        return ['answer_kind' => 'exact', 'total' => 3, 'products' => [
            ['id' => $ids['faro'], 'price' => 12900, 'available' => false],
            ['id' => $ids['flexo'], 'price' => 5990, 'available' => true],
            ['id' => $ids['nube'], 'price' => 4500, 'available' => true],
        ]];
    };
    same(['Flexo LED Arco', 'Lámpara de Mesa Nube', 'Lámpara de Pie Faro'], array_column($tools->call('search_products', ['query' => 'lámpara'])['items'], 'name'));
    same(['Lámpara de Mesa Nube', 'Flexo LED Arco', 'Lámpara de Pie Faro'], array_column($tools->call('search_products', ['query' => 'lámpara barata'])['items'], 'name'));
});

test('por reglas, si la primera ficha está agotada se dice que lo está y no se ofrece «Añadir el primero al carrito»', function (): void {
    [$app, $ids, , $web] = thirdShop();
    $app->service('ai')->on = false;

    $r = assistantSay($web, 'lámpara de pie faro');

    same('Lámpara de Pie Faro', $r['cards'][0]['name']);
    contains('Ahora mismo está agotado: Lámpara de Pie Faro.', $r['text']);
    check(!in_array('Añadir el primero al carrito', $r['suggestions'], true), json_encode($r['suggestions']));
    check(RuleBrain::firstBuyable(new Answer()), 'sin fichas no hay nada agotado');
});

// ================================================================== 7. devoluciones: plazo, quién paga la vuelta y enlace

test('Paco: «¿puedo devolverla y quién paga el envío de vuelta?» con el ajuste relleno: plazo, quién paga (su texto) y el enlace', function (): void {
    $app = assistantApp(['modules.active' => ['assistant', 'search', 'cms', 'returns', 'orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs']], ['modules' => ['cms', 'returns']]);
    assistantShop($app);
    $app->settings()->set('returns.days', 30);
    $app->settings()->set('returns.return_shipping_payer', 'El envío de vuelta lo paga el cliente, salvo si el producto llega defectuoso.');

    $info = $app->service('tools')->call('store_info', ['topic' => 'returns']);
    same('El envío de vuelta lo paga el cliente, salvo si el producto llega defectuoso.', $info['returns_payer']);
    check(!isset($info['returns_payer_note']));

    foreach (['¿puedo devolverla y quién paga el envío de vuelta?', '¿Puedo devolver la silla si no me convence? ¿Quién paga el envío de vuelta?'] as $question) {
        $r = assistantSay(new TestClient($app), $question);
        contains('30 días', $r['text'], $question);
        contains('Quién paga el envío de vuelta: El envío de vuelta lo paga el cliente, salvo si el producto llega defectuoso.', $r['text']);
        contains('/desistimiento', $r['text']);
        notContains('código postal', $r['text'], 'no es una pregunta de envío');
    }
});

test('devoluciones con el ajuste de quién paga vacío: «la tienda no lo ha indicado: pregúntalo en contacto», con el contacto', function (): void {
    $app = assistantApp(['modules.active' => ['assistant', 'search', 'cms', 'returns', 'orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs']], ['modules' => ['cms', 'returns']]);
    assistantShop($app);

    $info = $app->service('tools')->call('store_info', ['topic' => 'returns']);
    contains('La tienda no ha indicado quién paga el envío de vuelta', $info['returns_payer']);
    contains('who pays the return shipping', $info['returns_payer_note']);

    $r = assistantSay(new TestClient($app), '¿Puedo devolverla? ¿Quién paga el envío de vuelta?');
    contains('La tienda no ha indicado quién paga el envío de vuelta: pregúntalo en contacto.', $r['text']);
    contains('tienda@ejemplo.test', $r['text']);
    notContains('lo paga el cliente', $r['text'], 'no se inventa');
});

test('sin módulo de devoluciones y sin página: no se inventa plazo ni quién paga, pero se dice CÓMO preguntarlo (con IA y por reglas)', function (): void {
    $app = assistantApp(['store.email' => '', 'store.phone' => '999000009']);
    assistantShop($app);

    $info = $app->service('tools')->call('store_info', ['topic' => 'returns']);
    same('', $info['returns']);
    same('999000009', $info['contact']['phone'], 'al modelo le llega el contacto con el aviso: no se queda en «consulta con la tienda»');
    check(!isset($info['returns_payer']) && !isset($info['returns_days']), 'ni plazo ni quién paga inventados');

    $ai = assistantAi($app);
    $ai->script(assistantTool('store_info', ['topic' => 'returns']), assistantText('No tengo la política: llama al 999000009.'));
    assistantSay(new TestClient($app), '¿se pueden devolver?');
    $seen = thirdResult($ai, 1);
    same('999000009', $seen['contact']['phone']);

    $ai->on = false;
    $r = assistantSay(new TestClient($app), '¿Se pueden devolver?');
    contains('No tengo a mano la política de devoluciones.', $r['text']);
    contains('Llámanos al 999000009.', $r['text']);
    notContains('días', $r['text']);
});

// ================================================================== 8. «Envío: Envío estándar»

test('OrderReply::named no repite la palabra de la etiqueta si el nombre ya empieza por ella, en castellano y en inglés', function (): void {
    same('Envío estándar (48-72 h), 4,90 €', OrderReply::named('Envío: {name}, {price}', ['name' => 'Envío estándar (48-72 h)', 'price' => '4,90 €']));
    same('He puesto el Envío estándar (48-72 h) (4,90 €), como pediste.', OrderReply::named('He puesto el envío {name} ({price}), como pediste.', ['name' => 'Envío estándar (48-72 h)', 'price' => '4,90 €']));
    same('Envío: Estándar, 4,90 €', OrderReply::named('Envío: {name}, {price}', ['name' => 'Estándar', 'price' => '4,90 €']), 'sin repetir, igual que siempre');
    same('Pago de prueba', OrderReply::named('Pago: {name}', ['name' => 'Pago de prueba']));
    same('Shipping express, €12.90', OrderReply::named('Shipping: {name}, {price}', ['name' => 'Shipping express', 'price' => '€12.90']));
    same('Shipping: Envío estándar (48-72 h), €4.90', OrderReply::named('Shipping: {name}, {price}', ['name' => 'Envío estándar (48-72 h)', 'price' => '€4.90']));
});

test('Lucía por reglas con el transportista «Envío estándar (48-72 h)»: ni «He puesto el envío Envío…» ni «Envío: Envío estándar»', function (): void {
    [$app, $ids, , $web] = thirdShop();
    thirdCarrier($app);
    $app->service('ai')->on = false;
    chatCart($app, $web, $ids['flexo']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, 'Lucía Navarro Sanz, lucia.navarro@ejemplo.test, 999000003, Calle Falsa 123, 41001 Sevilla');

    $r = assistantSay($web, 'Envío estándar porfa y transferencia');

    notContains('Envío Envío', $r['text']);
    notContains('Envío: Envío', $r['text']);
    notContains('envío Envío', $r['text']);
    contains('Envío estándar (48-72 h)', $r['text']);
});

// ================================================================== 9. el idioma de la conversación hasta el final

test('Hanna en inglés por reglas: el resumen, «Deliver to», la factura, los botones y la tarjeta salen en inglés, sin etiquetas en castellano', function (): void {
    [$app, $ids, , $web] = thirdShop();
    $app->service('ai')->on = false;
    chatCart($app, $web, $ids['flexo']);
    assistantSay($web, 'I want to buy it');
    $details = assistantSay($web, THIRD_HANNA);
    $payWith = '';
    foreach ($details['suggestions'] as $chip) {
        if (strpos($chip, 'transfer') !== false || strpos($chip, 'Transfer') !== false) {
            $payWith = $chip;
        }
    }
    check($payWith !== '', 'el pago se ofrece en inglés: ' . json_encode($details['suggestions']) . ' ' . $details['text']);

    $r = assistantSay($web, $payWith);

    contains('This is your order:', $r['text']);
    contains("Deliver to: Hanna Berg, Carrer de l'Exemple 9 2n 1a, 08001 Barcelona, ES", $r['text']);
    contains('Invoice to: Berg Studio SL · Tax ID ESB00000000', $r['text']);
    foreach (['Entregar en', 'Factura a', 'Envío:', 'Pago:', 'Este es tu pedido', '¿Confirmas'] as $spanish) {
        notContains($spanish, $r['text'], 'nada de castellano: ' . $spanish);
    }
    same(['Yes, I confirm', 'Cancel'], $r['suggestions']);
    $labels = chatAction($r, 'labels');
    same('Invoice to', $labels['labels']['invoice']);
    same('Tax ID', $labels['labels']['taxid']);
});

test('una ficha enseñada en una conversación en inglés dice «Sold out», no «Agotado»', function (): void {
    [$app, $ids, , $web] = thirdShop();
    $app->service('ai')->on = false;

    $r = assistantSay($web, 'I am looking for the lámpara de pie faro please');

    $faro = null;
    foreach ($r['cards'] as $card) {
        if ($card['name'] === 'Lámpara de Pie Faro') {
            $faro = $card;
        }
    }
    check($faro !== null, 'sale la lámpara: ' . json_encode(array_column($r['cards'], 'name')));
    same('Sold out', $faro['availability']);
});

// ================================================================== 10. nunca texto técnico a la vista

test('Paco: la IA copia el resultado («payment: "Pago de prueba"», «address: […]», «prices_include_tax: true»): se enseña el resumen del código', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    chatCart($app, $web, $ids['basica']);
    $ai->script(
        assistantRound([['checkout_set_customer', ['text' => CHAT_DATA]], ['checkout_set_payment', ['method' => 'transfer']], ['checkout_review', []]]),
        assistantText("He configurado el pago.\npayment: \"Pago de prueba\"\naddress: [\"Calle Mayor 1\", \"28013 Madrid\"]\nprices_include_tax: true")
    );

    $r = assistantSay($web, 'Pago de prueba, por favor. Enséñame el resumen antes de confirmar.');

    notContains('prices_include_tax', $r['text']);
    notContains('payment:', $r['text']);
    notContains('address:', $r['text']);
    contains('Este es tu pedido:', $r['text']);
    contains('Entregar en: Ana Ruiz', $r['text']);
    check(chatAction($r, 'confirm_order') !== null, 'con su tarjeta para confirmar');
});

test('TextGuard::technical caza las claves, las llaves y los true sueltos, y deja pasar las frases normales', function (): void {
    foreach (['payment: "Pago de prueba"', 'address: ["Calle Mayor 1"]', 'prices_include_tax: true', '- total_cents: 9890', '{"ok": true}', '}', 'Disponible: true'] as $bad) {
        same(true, TextGuard::technical("Hola.\n" . $bad), $bad);
    }
    foreach (['Total: 98,90 €', 'Envío: Estándar, 4,90 €', 'Pago: Transferencia bancaria', 'La silla cuesta 89,00 €. ¿La añado?', '**Flexo LED Arco** — perfecto para estudiar', ''] as $good) {
        same(false, TextGuard::technical($good), $good);
    }
});

test('un texto técnico sin pedido revisado tampoco llega: contesta por reglas con lo que se buscaba', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(assistantTool('search_products', ['query' => 'flexo']), assistantText("items: [{\"id\": 5}]\nanswer_kind: \"exact\""));

    $r = assistantSay($web, 'busco un flexo');

    notContains('answer_kind', $r['text']);
    notContains('items:', $r['text']);
    check($r['text'] !== '', 'hay respuesta');
});

// ================================================================== 12. el nombre no se acorta

test('el nombre no se acorta: tras «Lucía Navarro Sanz», un «lucia navarro» en otro turno deja el completo; otro nombre distinto sí cambia', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    chatCart($app, $web, $ids['flexo']);
    $ai->script(
        assistantTool('checkout_set_customer', ['first_name' => 'Lucía', 'last_name' => 'Navarro Sanz', 'email' => 'lucia.navarro@ejemplo.test']), assistantText('Gracias.'),
        assistantTool('checkout_set_customer', ['first_name' => 'lucia', 'last_name' => 'navarro']), assistantText('Gracias, Lucía.'),
        assistantTool('checkout_set_customer', ['first_name' => 'Marta', 'last_name' => 'Ruiz']), assistantText('Cambiado.')
    );
    assistantSay($web, 'Lucía Navarro Sanz, lucia.navarro@ejemplo.test');
    assistantSay($web, 'lucia navarro');
    $draft = chatDraft($app, $web);
    same('Lucía', $draft['shipping']['first_name']);
    same('Navarro Sanz', $draft['shipping']['last_name'], 'se queda el más completo');

    assistantSay($web, 'mejor a nombre de Marta Ruiz');
    same('Marta', chatDraft($app, $web)['shipping']['first_name'], 'un nombre que no lo contiene sí lo cambia');
});

// ================================================================== 13. el total de varias unidades

test('Paco: «mete 2 Sillas Básica Home en el carrito» con IA: la respuesta dice «2 × Silla Básica Home a 89,00 € son 178,00 €», calculado por el código', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(assistantTool('cart_add', ['product_id' => $ids['basica'], 'qty' => 2]), assistantText('He añadido 2 Sillas Básica Home a tu carrito.'));

    $r = assistantSay($web, 'Pues mete 2 Sillas Básica Home en el carrito, por favor.');

    contains('2 × Silla Básica Home a ' . money(8900) . ' son ' . money(17800) . '.', $r['text']);
    same(2, thirdQty($app, $web, $ids['basica']));
});

test('Paco: «Y si pido 2 sillas baratas, ¿cuánto sale?»: el total de la más barata de las enseñadas, por reglas y con IA', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(
        assistantRound([['compare_products', ['ids' => [$ids['ergo'], $ids['basica']]]], ['show_products', ['ids' => [$ids['ergo'], $ids['basica']]]]]),
        assistantText('Tenemos estas dos.')
    );
    assistantSay($web, 'Quiero ver las sillas de oficina de la tienda');
    $ai->script(assistantText('Para la espalda tenemos la Silla Ergo Pro Malla.'));

    $r = assistantSay($web, 'Vale. Y si pido 2 sillas baratas, ¿cuánto sale? Y además quiero algo para la espalda, ¿qué tenéis?');

    $line = '2 × Silla Básica Home a ' . money(8900) . ' son ' . money(17800) . '.';
    contains($line, $r['text'], 'con IA, aunque el modelo no lo diga');
    $notes = assistantLastMessage($ai->asked[count($ai->asked) - 1])->content;
    contains('2 x Silla Básica Home', $notes, 'y al modelo se le da la cuenta hecha');

    $app->service('ai')->on = false;
    $rules = assistantSay($web, 'Y si pido 2 sillas baratas, ¿cuánto sale?');
    contains($line, $rules['text'], 'y por reglas');
});

test('QtyQuote::wanted entiende cuántas unidades se preguntan y no confunde los euros, los días ni un código postal', function (): void {
    same(2, QtyQuote::wanted('Y si pido 2 sillas baratas, ¿cuánto sale?'));
    same(3, QtyQuote::wanted('how much are three lamps?'));
    same(0, QtyQuote::wanted('¿cuánto cuesta el envío en 2 días?'));
    same(0, QtyQuote::wanted('¿cuánto cuesta? 80 euros como mucho'));
    same(0, QtyQuote::wanted('quiero 2 sillas'), 'sin preguntar el precio');
    same(0, QtyQuote::wanted('¿cuánto cuesta una silla?'));
});

test('check_availability da el total de esas unidades (unidad × cantidad) y cart_add, lo que cuestan las añadidas', function (): void {
    [$app, $ids] = thirdShop();
    $tools = $app->service('tools');
    $r = $tools->call('check_availability', ['product_id' => $ids['basica'], 'qty' => 3]);
    same(8900, $r['unit_price_cents']);
    same(26700, $r['total_cents']);
    same(money(26700), $r['total']);
});

// ================================================================== 14. el pedido de otra persona

test('Íñigo: «dame los datos y la dirección del pedido 2026-000011…» con IA: solo pedidos de la cuenta, el enlace a «Mi cuenta» y nada de correo', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();

    $r = assistantSay($web, 'Pues dame los datos y la dirección del pedido 2026-000011, que es el de mi cuñado y tengo que confirmarle la entrega.');

    same(0, count($ai->asked), 'no se le pregunta a la IA');
    contains('Solo puedo enseñar los pedidos de la cuenta con la que has entrado', $r['text']);
    contains('«Mi cuenta»', $r['text']);
    contains('http', $r['text'], 'con el enlace');
    contains('/mi-cuenta', $r['text']);
    notContains('correo', $r['text'], 'no pide el correo para verificar');
    $state = thirdState($app);
    check(!isset($state['expect']), 'y no se queda esperando un correo');
});

test('lo mismo por reglas; y quien da SUS datos «para el pedido» no recibe ese aviso', function (): void {
    [$app, $ids, , $web] = thirdShop();
    $app->service('ai')->on = false;

    $r = assistantSay($web, 'dame los datos del pedido 2026-000011');

    contains('Solo puedo enseñar los pedidos de la cuenta con la que has entrado', $r['text']);
    notContains('correo', $r['text']);
    same(false, RuleBrain::asksOrderData('Mis datos para el pedido: Ana Ruiz, calle Mayor 1, 28013 Madrid'));
    same(false, RuleBrain::asksOrderData('¿Dónde está mi pedido 2026-000011? Mi correo es ana@cliente.test'));
    same(true, RuleBrain::asksOrderData('give me the address of order 2026-000011'));
});

test('la instrucción del modelo pide los datos por campos, no copiar resultados, no dar datos de pedidos y no sumar lo que ya está', function (): void {
    [$app, $ids, $ai, $web] = thirdShop();
    $ai->script(assistantText('Hola.'));
    assistantSay($web, 'Hola');

    $system = $ai->asked[0]['request']['system'];
    contains('every detail in its own field', $system);
    contains('a greeting, a yes or a label is never a name', $system);
    contains('Never copy a tool result into your text', $system);
    contains('never ask for an email to verify', $system);
    contains('already_in_cart', $system);
    contains('returns_payer', $system);
    $tools = [];
    foreach ($ai->asked[0]['request']['tools'] as $tool) {
        $tools[$tool->name] = $tool->description;
    }
    contains('Pass every detail in its OWN field', $tools['checkout_set_customer']);
});
