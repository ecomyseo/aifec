<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El asistente sin IA: el modo por reglas. Sin servicio «ai», contesta por reglas con lo que devuelven las herramientas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Las herramientas que se usaron para contestar el último mensaje de la conversación, en el registro del panel.
 *
 * @return array<int,array<string,mixed>>
 */
function assistantLastTools(App $app): array
{
    $rows = assistantRows($app, 'conversation_messages', "role = 'assistant'");
    $last = $rows[count($rows) - 1];

    return $last['tools'] === null ? [] : json_decode((string) $last['tools'], true);
}

/**
 * Un pedido hecho en este navegador, con el correo de la caja de pruebas (ana@cliente.test). Devuelve su número.
 */
function assistantOrder(App $app, TestClient $web, int $productId): string
{
    $placed = aifecOrderPlace($app, $web, [[$productId, 0, 1]]);
    check(!empty($placed['ok']), 'el pedido se crea');

    return (string) $placed['number'];
}

test('el saludo, el agradecimiento y la despedida se contestan solos, con respuestas rápidas que el propio asistente entiende', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    $hello = assistantSay($web, 'Hola');
    contains('Puedo ayudarte a encontrar productos', $hello['text']);
    same(['Devoluciones y reembolsos', 'Formas de pago', '¿Dónde está mi pedido?'], $hello['suggestions']);
    same([], $hello['cards']);
    contains('Puedo ayudarte', assistantSay($web, 'Buenos días')['text']);
    contains('Puedo ayudarte', assistantSay($web, 'hola, buenas tardes')['text']);
    contains('De nada', assistantSay($web, 'Muchas gracias')['text']);
    contains('Hasta pronto', assistantSay($web, 'Adiós')['text']);
    same(0, (int) assistantRows($app, 'conversations')[0]['unanswered'], 'nada de esto queda sin respuesta');

    foreach ($hello['suggestions'] as $chip) {
        $answer = assistantSay($web, $chip);
        check($answer['text'] !== '' && strpos($answer['text'], 'No te he entendido') === false, 'la respuesta rápida «' . $chip . '» se entiende');
    }
});

test('«busco una silla roja»: busca con las herramientas y enseña tarjetas con precio, disponibilidad y datos del catálogo', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    $r = assistantSay($web, 'Busco una silla roja');

    contains('«silla roja»', $r['text']);
    $card = null;
    foreach ($r['cards'] as $c) {
        if ($c['id'] === $ids['silla_roja']) {
            $card = $c;
        }
    }
    check($card !== null, 'la silla roja está entre las tarjetas');
    same('Silla roja', $card['name']);
    same(5990, $card['price_cents']);
    same(money(5990), $card['price']);
    same(true, $card['can_add']);
    contains('https://tienda.test/producto/', $card['url']);
    same(['Añadir el primero al carrito', 'Gastos de envío', 'Ver mi carrito'], $r['suggestions']);
    same([['name' => 'search_products', 'ok' => true, 'input' => ['query' => 'silla roja', 'limit' => 5]]], assistantLastTools($app));
});

test('la búsqueda entiende «tenéis…», un mensaje corto con solo lo que se busca y un precio máximo en euros', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    $tables = assistantSay($web, '¿Tenéis mesas?');
    $names = array_column($tables['cards'], 'name');
    check(in_array('Mesa de roble', $names, true), 'salen las mesas: ' . json_encode($names));

    $short = assistantSay($web, 'sillón orejero');
    check(in_array($ids['sillon'], array_column($short['cards'], 'id'), true), 'un mensaje corto es una búsqueda');

    $cheap = assistantSay($web, 'Busco sillas por menos de 60 euros');
    $found = array_column($cheap['cards'], 'id');
    check(in_array($ids['silla_roja'], $found, true), 'la de 59,90 € sí');
    check(!in_array($ids['silla_azul'], $found, true), 'la de 64,90 € no');
    foreach ($cheap['cards'] as $card) {
        check($card['price_cents'] <= 6000, $card['name'] . ' pasa del tope');
    }
    same(['name' => 'search_products', 'ok' => true, 'input' => ['query' => 'sillas', 'limit' => 5, 'max_price_cents' => 6000]], assistantLastTools($app)[0]);

    $decimals = assistantSay($web, 'quiero una mesa hasta 300,50 euros');
    same(30050, assistantLastTools($app)[0]['input']['max_price_cents']);
    check($decimals['cards'] !== [], 'y con decimales');
});

test('una búsqueda sin resultados lo dice, ofrece las categorías y deja la pregunta sin respuesta para el panel', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    $r = assistantSay($web, 'Busco xyzzy plugh');

    contains('No he encontrado nada para «xyzzy plugh»', $r['text']);
    same([], $r['cards']);
    check(in_array('Sillas', $r['suggestions'], true) || in_array('Mesas', $r['suggestions'], true), 'ofrece categorías: ' . json_encode($r['suggestions']));
    $user = assistantRows($app, 'conversation_messages', "role = 'user'")[0];
    same(1, (int) $user['unanswered']);
    same('busco xyzzy plugh', $user['phrase']);
    same(1, (int) assistantRows($app, 'conversations')[0]['unanswered']);
});

test('«envío a 28001» con el carrito lleno calcula el envío de ese carrito con la tarifa de la tienda', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($ids): void {
        $cart->add($ids['silla_roja'], 0, 2);
    });

    $r = assistantSay($web, '¿Cuánto cuesta el envío a 28001?');

    contains('Envío al 28001:', $r['text']);
    contains('Estándar: ' . money(495), $r['text']);
    same('shipping_quote', assistantLastTools($app)[0]['name']);
    same(['postcode' => '28001'], assistantLastTools($app)[0]['input']);
    same(['Ver mi carrito', 'Ir a la caja'], $r['suggestions']);
    contains('Envío al 35001:', assistantSay($web, 'envio a 35001')['text'], 'sin tilde y sin signos también');
    contains('Estándar', assistantSay($web, 'shipping to 28001')['text'], 'y en inglés');
});

test('sin carrito, el envío se calcula para el primer producto que se enseñó; sin nada que enviar lo pide', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    $nothing = assistantSay($web, 'envío a 28001');
    contains('Dime qué producto quieres enviar', $nothing['text']);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    same('postcode', $state['expect'], 'sigue esperando el código postal');

    assistantSay($web, 'Busco una silla roja');
    $r = assistantSay($web, 'envío a 28001');
    contains('Envío al 28001:', $r['text']);
    contains(money(495), $r['text']);
    $tools = assistantLastTools($app);
    same(['shipping_quote', 'shipping_quote'], array_column($tools, 'name'), 'primero probó con el carrito y después con el producto');
    same([false, true], array_column($tools, 'ok'));
});

test('«¿cuánto tarda el envío?» sin código postal lo pide y el siguiente mensaje, solo con el código, se entiende', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);
    assistantSay($web, 'Busco una mesa de roble');

    $ask = assistantSay($web, '¿Cuánto tarda el envío?');
    contains('¿Cuál es tu código postal?', $ask['text']);
    same('postcode', json_decode((string) assistantRows($app, 'conversations')[0]['state'], true)['expect']);

    $quote = assistantSay($web, '35001');
    contains('Envío al 35001:', $quote['text']);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['expect']), 'ya no espera nada');
    check(isset($state['cards']), 'recuerda las fichas que enseñó');

    $other = assistantSay($web, '28001');
    check(strpos($other['text'], 'Envío al') === false, 'un código suelto, sin haberlo pedido, no calcula nada');
});

test('CustomerData reconoce el correo y el código postal en una frase larga y escrita de cualquier manera', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart, App $app): void {
        $id = (int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'MES-001'");
        $cart->add($id, 0, 1);
    });

    $r = assistantSay($web, 'Hola, soy Ana, mi correo es ana@cliente.test y vivo en 35001 Las Palmas. ¿Cuánto cuesta el envío?');

    contains('Envío al 35001:', $r['text']);
    contains('Estándar', $r['text']);
});

test('pedido: con el número y el correo da el estado, el envío y el seguimiento; el correo no entra en el registro de herramientas', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $number = assistantOrder($app, $web, $ids['silla_roja']);
    $app->db()->update('orders', ['carrier_title' => 'Estándar', 'tracking_code' => 'TRK-77'], ['number' => $number]);
    $buyer = new TestClient($app);

    $r = assistantSay($buyer, 'Mi pedido ' . $number . ' con el correo Ana@Cliente.test, ¿cómo va?');

    contains('Pedido ' . $number . ':', $r['text']);
    contains('Pendiente de pago', $r['text']);
    contains('Enviado con Estándar.', $r['text']);
    contains('Código de seguimiento: TRK-77', $r['text']);
    $tool = assistantLastTools($app)[0];
    same('order_status', $tool['name']);
    same(['number' => $number, 'email' => '***'], $tool['input'], 'el correo no se guarda en el registro de herramientas');
    notContains('ana@cliente.test', strtolower((string) assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['tools']));
});

test('pedido: un correo que no coincide dice lo mismo que un número que no existe y no filtra nada; lo que falta se pide y se recuerda', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $number = assistantOrder($app, $web, $ids['silla_roja']);
    $app->db()->update('orders', ['tracking_code' => 'TRK-SECRETO'], ['number' => $number]);
    $other = new TestClient($app);

    $wrong = assistantSay($other, 'Mi pedido ' . $number . ' otra@cliente.test');
    $missing = assistantSay($other, 'Mi pedido 2099-999999 ana@cliente.test');
    contains('No encuentro ningún pedido con esos datos', $wrong['text']);
    notContains('TRK-SECRETO', $wrong['text']);
    same($wrong['text'], $missing['text'], 'la misma respuesta con un pedido que no existe');

    $ask = assistantSay($other, '¿Dónde está mi pedido?');
    contains('necesito el número de pedido y el correo', $ask['text']);
    $noEmail = assistantSay($other, 'mi pedido ' . $number);
    contains('¿Qué correo usaste en el pedido?', $noEmail['text']);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    same('order', $state['expect']);
    same($number, $state['order_number'], 'recuerda el número ya dicho');

    $done = assistantSay($other, 'ana@cliente.test');
    contains('Pedido ' . $number . ':', $done['text']);
    contains('Pedido ' . $number . ':', assistantSay($other, 'pedido ' . $number . ' ana@cliente.test')['text'], 'y se puede repetir');
});

test('devoluciones, pago, horario y contacto salen de store_info: con dato lo dicen; sin dato lo reconocen y lo dejan para el panel', function (): void {
    $app = assistantApp(['assistant.hours' => 'L-V 9:00 a 18:00', 'assistant.returns_text' => 'Aceptamos devoluciones durante 30 días.']);
    assistantShop($app);
    $web = new TestClient($app);

    same('Aceptamos devoluciones durante 30 días.', assistantSay($web, '¿Cuál es la política de devoluciones?')['text']);
    same('Puedes pagar con: Pasarela de pruebas.', assistantSay($web, '¿Qué formas de pago tenéis?')['text']);
    same('Nuestro horario: L-V 9:00 a 18:00', assistantSay($web, '¿Cuál es vuestro horario?')['text']);
    $contact = assistantSay($web, 'Quiero hablar con una persona');
    contains('Correo: tienda@ejemplo.test', $contact['text']);
    contains('Teléfono: 999000001', $contact['text']);
    same(0, (int) assistantRows($app, 'conversations')[0]['unanswered']);

    $bare = assistantApp();
    $webBare = new TestClient($bare);
    $returns = assistantSay($webBare, 'devoluciones');
    contains('No tengo a mano la política de devoluciones.', $returns['text']);
    contains('Escríbenos a tienda@ejemplo.test.', $returns['text']);
    contains('No tengo a mano el horario.', assistantSay($webBare, 'horario')['text']);
    same(2, (int) assistantRows($bare, 'conversations')[0]['unanswered'], 'las dos quedan marcadas');
});

test('el carrito: «ver mi carrito» vacío, «añade el primero» tras una búsqueda con el precio del catálogo, y «ir a la caja» lleva a la caja de la web', function (): void {
    $app = assistantApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    same('Tu carrito está vacío.', assistantSay($web, 'Ver mi carrito')['text']);
    $pay = assistantSay($web, 'Quiero pagar');
    contains('El carrito está vacío', $pay['text']);
    same([], $pay['actions']);

    assistantSay($web, 'Busco una silla roja');
    $add = assistantSay($web, 'Añade el primero al carrito');
    contains('Añadido a tu carrito: Silla roja.', $add['text']);
    same([['type' => 'cart_changed', 'count' => 1]], $add['actions']);
    same(['Ir a la caja', 'Ver mi carrito', 'Seguir mirando'], $add['suggestions'], 'tras añadir (P105)');
    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines));
    same($ids['silla_roja'], $lines[0]['product_id']);
    same(5990, $lines[0]['unit_price'], 'precio del catálogo');
    same(1, (int) assistantRows($app, 'conversations')[0]['cart_added']);

    $view = assistantSay($web, 'ver mi carrito');
    contains('1 × Silla roja — ' . money(5990), $view['text']);
    contains('Total:', $view['text']);

    $checkout = assistantSay($web, 'Ir a la caja');
    contains('Tu carrito está listo', $checkout['text']);
    same('checkout', $checkout['actions'][0]['type']);
    contains('https://tienda.test/finalizar-compra', $checkout['actions'][0]['url']);
    same(1, (int) assistantRows($app, 'conversations')[0]['checkout']);
});

test('«añade el primero» respeta la cantidad, no añade un producto con variantes (hay que elegir) y sin fichas pide buscar antes', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    contains('Dime qué producto añadir', assistantSay($web, 'añade el primero')['text']);
    same([], assistantCartLines($app, $web));

    assistantSay($web, 'Busco una mesa de roble');
    $two = assistantSay($web, 'añade 2 unidades del primero');
    contains('Añadido a tu carrito: Mesa de roble.', $two['text']);
    same(2, array_values(assistantCartLines($app, $web))[0]['qty']);

    $web2 = new TestClient($app);
    $found = assistantSay($web2, 'Busco el sofá nube');
    same(true, $found['cards'][0]['has_variants']);
    same(false, $found['cards'][0]['can_add'], 'una ficha con variantes no se añade con un botón');
    $try = assistantSay($web2, 'añade el primero');
    contains('«Sofá Nube» tiene varias opciones. ¿Cuál quieres?', $try['text']);
    same(['Talla S', 'Talla L'], $try['suggestions'], 'las opciones, como botones');
    same([], assistantCartLines($app, $web2), 'todavía no se ha añadido nada');
    $chosen = assistantSay($web2, 'la L');
    contains('Añadido a tu carrito: Sofá Nube.', $chosen['text']);
    same(59900, array_values(assistantCartLines($app, $web2))[0]['unit_price'], 'la variante elegida, con su precio');
});

test('lo que no entiende lo dice, ofrece opciones y queda en «sin respuesta» agrupado y sin datos personales', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    $r = assistantSay($web, '¿Qué tiempo hará mañana?');
    contains('No te he entendido del todo', $r['text']);
    same(['Devoluciones y reembolsos', 'Formas de pago', '¿Dónde está mi pedido?'], $r['suggestions']);
    same([], $r['cards']);
    assistantSay($web, '¿Qué tiempo hará mañana?');
    assistantSay($web, '¿Qué opináis de mi tele? ana@cliente.test 999345678');

    $rows = assistantRows($app, 'conversation_messages', "role = 'user'");
    same([1, 1, 1], array_map('intval', array_column($rows, 'unanswered')));
    same('¿qué tiempo hará mañana?', $rows[0]['phrase']);
    same($rows[0]['phrase'], $rows[1]['phrase'], 'la misma pregunta se agrupa');
    contains('…', $rows[2]['phrase']);
    notContains('ana@cliente.test', $rows[2]['phrase'], 'el correo no llega a la frase agrupada');
    notContains('999345678', $rows[2]['phrase'], 'ni el teléfono');
    same(3, (int) assistantRows($app, 'conversations')[0]['unanswered']);
    same('rules', assistantRows($app, 'conversations')[0]['mode']);
});

test('el modo por reglas funciona en inglés y sin tildes ni signos', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $web = new TestClient($app);

    contains('Puedo ayudarte', assistantSay($web, 'hello')['text']);
    contains('You can pay with', assistantSay($web, 'what are your payment methods?')['text'], 'a quien escribe en inglés se le contesta en inglés');
    contains('returns policy', assistantSay($web, 'return policy')['text'], 'y sigue en inglés con un mensaje corto');
    same('Your cart is empty.', assistantSay($web, 'show my cart')['text']);
    contains('What is your postcode?', assistantSay($web, 'how much is the shipping')['text']);
    $r = assistantSay($web, 'SILLA ROJA!!!');
    check($r['cards'] !== [], 'mayúsculas y signos no estorban');
});
