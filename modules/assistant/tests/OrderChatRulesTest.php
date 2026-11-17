<?php
/**
 * AIFEC — la compra dentro del chat en el modo por reglas (sin IA): el pedido de principio a fin, lo que pregunta y en qué
 * orden, el envío y el pago con botones, el pago en línea y el pago fuera de la web, dejar el pedido o cambiar un dato.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/OrderChatKit.php';

/**
 * Una tienda con la silla roja ya en el carrito de un navegador nuevo.
 *
 * @return array{0:\Aifec\Kernel\App,1:TestClient,2:array<string,int>}
 */
function chatRulesShop(array $ajustes = []): array
{
    $app = chatApp($ajustes);
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);

    return [$app, $web, $ids];
}

test('por reglas, de principio a fin: busca la silla, la añade, recoge los datos, elige el pago, enseña el resumen y con un «sí» hace el pedido con el total exacto', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    assistantSay($web, 'Quiero una silla roja');
    $add = assistantSay($web, 'Añade el primero al carrito');
    contains('Añadido a tu carrito: Silla roja.', $add['text']);
    $cartTotal = chatTotal($app, $web);
    same(5990 + 495, $cartTotal, 'la silla más el envío estándar');

    $start = assistantSay($web, 'Quiero hacer el pedido');
    contains('Hagamos tu pedido aquí mismo', $start['text']);
    same(0, count(chatOrders($app)));

    $data = assistantSay($web, CHAT_DATA);
    contains('he guardado tus datos', $data['text']);
    contains('Envío: Estándar, ' . money(495), $data['text'], 'con un solo transportista se elige solo y se dice');
    contains('¿Cómo quieres pagar?', $data['text']);
    same(['Transferencia bancaria', 'Pasarela de pruebas'], $data['suggestions'], 'las formas de pago, con botones');

    $review = assistantSay($web, 'Transferencia bancaria');
    contains('Este es tu pedido:', $review['text']);
    contains('1 × Silla roja — ' . money(5990), $review['text']);
    contains('Total: ' . money($cartTotal), $review['text']);
    contains('Calle Mayor 1 3ºB, 28013 Madrid', $review['text']);
    contains('Pago: Transferencia bancaria', $review['text']);
    contains('Al confirmar aceptas las condiciones de venta.', $review['text']);
    same(['Sí, confirmo', 'Cancelar'], $review['suggestions']);
    $card = chatAction($review, 'confirm_order');
    check($card !== null, 'la respuesta lleva la tarjeta con el botón de confirmar');
    same($cartTotal, $card['total_cents'], 'el total de la tarjeta es el del carrito, al céntimo');
    same(money($cartTotal), $card['total']);
    same(0, count(chatOrders($app)), 'todavía no hay pedido: falta que la persona confirme');

    $done = assistantSay($web, 'sí');
    contains('Tu pedido 2026-000001 está hecho. Total: ' . money($cartTotal), $done['text']);
    contains('IBAN: ES82 0000 0000 0000 0000 0000', $done['text'], 'las instrucciones de la transferencia, en el chat');
    contains('Concepto: 2026-000001', $done['text']);
    contains('Sillas del Sur', $done['text']);
    same(null, chatAction($done, 'pay'), 'una transferencia no lleva botón de pagar');
    $order = chatAction($done, 'order');
    contains('/pedido/2026-000001/', (string) $order['url'], 'siempre, el enlace a la página del pedido');

    $orders = chatOrders($app);
    same(1, count($orders));
    same($cartTotal, (int) $orders[0]['total'], 'el pedido lleva el total del carrito, al céntimo');
    same('assistant', $orders[0]['source']);
    same(['channel' => 'assistant', 'conversation_id' => (int) assistantRows($app, 'conversations')[0]['id']], json_decode((string) $orders[0]['attribution'], true));
    same('ana@correo.test', $orders[0]['email']);
    same('transfer', $orders[0]['payment_method']);
    $conversation = assistantRows($app, 'conversations')[0];
    same((int) $orders[0]['id'], (int) $conversation['order_id'], 'la conversación acaba en ese pedido');
    same(1, (int) $conversation['checkout']);
    same([], assistantCartLines($app, $web), 'el carrito queda vacío');
    same([['type' => 'cart_changed', 'count' => 0], ['type' => 'order', 'url' => $order['url']]], $done['actions']);
});

test('con dos transportistas el asistente ofrece los dos con su precio, con botones, y se elige por su nombre; el total cambia y sale el del carrito', function (): void {
    [$app, $web] = chatRulesShop();
    chatFast($app);

    assistantSay($web, 'Quiero hacer el pedido');
    $data = assistantSay($web, CHAT_DATA);
    contains('¿Cómo quieres que te lo enviemos?', $data['text']);
    contains('• Estándar: ' . money(495) . ' (24-48 h)', $data['text']);
    contains('• Urgente: ' . money(1295) . ' (24 h)', $data['text']);
    same(['Estándar', 'Urgente'], $data['suggestions']);
    notContains('¿Cómo quieres pagar?', $data['text'], 'primero el envío y después el pago');

    $pay = assistantSay($web, 'Urgente');
    contains('¿Cómo quieres pagar?', $pay['text']);
    $review = assistantSay($web, 'transferencia');
    $card = chatAction($review, 'confirm_order');
    same('Urgente', $card['shipping']['name']);
    same(money(1295), $card['shipping']['price']);
    same(5990 + 1295, $card['total_cents']);
    same($card['total_cents'], chatTotal($app, $web), 'el total de la tarjeta es el del carrito');

    assistantSay($web, 'sí');
    $order = chatOrders($app)[0];
    same(5990 + 1295, (int) $order['total']);
    same('Urgente', $order['carrier_title']);
});

test('el envío también se elige con «el segundo» o «el más barato»', function (): void {
    foreach ([['el segundo', 'Urgente', 1295], ['el más barato', 'Estándar', 495], ['el primero', 'Estándar', 495]] as $case) {
        [$app, $web] = chatRulesShop();
        chatFast($app);
        assistantSay($web, 'Quiero hacer el pedido');
        assistantSay($web, CHAT_DATA);
        $pay = assistantSay($web, $case[0]);
        contains('¿Cómo quieres pagar?', $pay['text'], $case[0]);
        $card = chatAction(assistantSay($web, 'transferencia'), 'confirm_order');
        same($case[1], $card['shipping']['name'], $case[0]);
        same(5990 + $case[2], $card['total_cents'], $case[0]);
    }
});

test('pregunta lo que falta y en su orden: con solo el nombre pide la calle, el código postal, la ciudad y el correo; los datos sueltos se van sumando', function (): void {
    [$app, $web] = chatRulesShop();

    assistantSay($web, 'Quiero hacer el pedido');
    $name = assistantSay($web, 'Ana Ruiz');
    contains('he guardado tus datos', $name['text']);
    contains('Todavía necesito: tu correo electrónico, la calle y el número, el código postal, la ciudad, un teléfono para la entrega.', $name['text'], 'primero el contacto y después la dirección, con el teléfono para el transporte');
    notContains('tu nombre', $name['text']);
    same([], chatOrders($app));

    $street = assistantSay($web, 'calle Mayor 1 3ºB, 28013 Madrid');
    contains('Todavía necesito: tu correo electrónico, un teléfono para la entrega.', $street['text']);
    notContains('tu nombre', $street['text']);

    $email = assistantSay($web, 'ana@correo.test');
    contains('Todavía necesito: un teléfono para la entrega.', $email['text'], 'sin teléfono no pasa al pago: lo exige el transporte');
    same('ana@correo.test', (string) chatDraft($app, $web)['email']);

    $phone = assistantSay($web, '999000001');
    contains('¿Cómo quieres pagar?', $phone['text'], 'con todo lo necesario pasa al pago');
});

test('entiende la misma frase en otro orden y sin comas', function (): void {
    foreach (['ana@correo.test 999000001 Ana Ruiz calle Mayor 1 28013 Madrid', '28013 Madrid, calle Mayor 1, ana@correo.test, 999000001, Ana Ruiz'] as $text) {
        [$app, $web] = chatRulesShop();
        assistantSay($web, 'Quiero hacer el pedido');
        $reply = assistantSay($web, $text);
        contains('¿Cómo quieres pagar?', $reply['text'], $text);
        $draft = chatDraft($app, $web);
        same('Ana', $draft['shipping']['first_name'], $text);
        same('Ruiz', $draft['shipping']['last_name']);
        same('28013', $draft['shipping']['postcode']);
        same('Madrid', $draft['shipping']['city']);
        same('Calle Mayor 1', $draft['shipping']['line1']);
    }
});

test('un código postal que no vale se avisa y no se guarda; el resto de los datos sí', function (): void {
    [$app, $web] = chatRulesShop();
    assistantSay($web, 'Quiero hacer el pedido');

    $reply = assistantSay($web, 'Ana Ruiz, calle Mayor 1, 00000 Madrid, ana@correo.test');
    contains('código postal', mb_strtolower($reply['text']), 'avisa de que ese código postal no vale');
    $draft = chatDraft($app, $web);
    same('ana@correo.test', $draft['email'], 'lo bueno se guarda');
    same('Ana', $draft['shipping']['first_name']);
    same('', $draft['shipping']['postcode'], 'lo malo no');
    contains('el código postal', $reply['text'], 'y vuelve a pedirlo');
});

test('con la pasarela en línea el pedido queda hecho y pendiente de pago, y se entrega el botón «Pagar ahora» hacia la dirección de pago', function (): void {
    [$app, $web] = chatRulesShop();
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    $review = assistantSay($web, 'Pasarela de pruebas');
    contains('Pago: Pasarela de pruebas', $review['text']);

    $done = assistantSay($web, 'sí, confirmo');
    contains('está hecho', $done['text']);
    contains('queda pendiente de pago', $done['text']);
    $pay = chatAction($done, 'pay');
    check($pay !== null, 'hay botón de pagar');
    $order = chatOrders($app)[0];
    same('https://pasarela.test/pagar/' . $order['number'], $pay['url'], 'la dirección de pago del pedido');
    same('get', $pay['method']);
    check(chatAction($done, 'order') !== null, 'y el enlace a la página del pedido');
    same('pending', $order['payment_status']);
    same('assistant', $order['source']);
    notContains('IBAN', $done['text']);
    same(1, count(aifecOrderPayment($app)->started), 'la pasarela recibió el pedido');
});

test('una pasarela que pide un formulario entrega el botón de pago con sus campos, para enviarlos con POST', function (): void {
    [$app, $web] = chatRulesShop();
    aifecOrderPayment($app)->action = \Aifec\Commerce\PaymentAction::form('https://pasarela.test/pagar', ['Ds_Signature' => 'firma-de-mentira', 'Ds_Order' => '0001']);
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    assistantSay($web, 'Pasarela de pruebas');
    $done = assistantSay($web, 'adelante');
    $pay = chatAction($done, 'pay');
    same('https://pasarela.test/pagar', $pay['url']);
    same('post', $pay['method']);
    same(['Ds_Signature' => 'firma-de-mentira', 'Ds_Order' => '0001'], $pay['fields']);
});

test('«quiero pagar» empieza el pedido en el chat y «ir a la caja» sigue llevando a la caja de la web', function (): void {
    [$app, $web, $ids] = chatRulesShop();

    $web2 = new TestClient($app);
    chatCart($app, $web2, $ids['silla_roja']);
    $pay = assistantSay($web2, 'Quiero pagar');
    contains('Hagamos tu pedido aquí mismo', $pay['text']);
    same(null, chatAction($pay, 'checkout'), 'no manda a la caja de la web');

    $page = assistantSay($web, 'Ir a la caja');
    contains('Tu carrito está listo', $page['text']);
    contains('https://tienda.test/finalizar-compra', chatAction($page, 'checkout')['url']);
    contains('Hacer mi pedido aquí', implode('|', $page['suggestions']), 'y ofrece hacerlo aquí');
});

test('con el carrito vacío «quiero hacer el pedido» no empieza nada y lo dice', function (): void {
    $app = chatApp();
    assistantShop($app);
    $web = new TestClient($app);

    $reply = assistantSay($web, 'Quiero hacer el pedido');
    contains('El carrito está vacío', $reply['text']);
    same([], $reply['actions']);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['flow']), 'no queda ningún pedido empezado');
});

test('«cancelar» deja el pedido, el carrito se queda como estaba y ya no se atienden los datos como un pedido', function (): void {
    [$app, $web] = chatRulesShop();
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    assistantSay($web, 'transferencia');

    $cancel = assistantSay($web, 'Cancelar');
    contains('no hago el pedido', $cancel['text']);
    same(1, count(assistantCartLines($app, $web)), 'el carrito sigue ahí');
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['flow']) && !isset($state['await']), 'ni pedido empezado ni espera de confirmación');

    assistantSay($web, 'sí');
    same([], chatOrders($app), 'un «sí» después de cancelar no hace nada');
});

test('un mensaje que no es de la compra, con un pedido a medias, se contesta y se ofrece continuar; «continuar» vuelve a lo que falta', function (): void {
    [$app, $web] = chatRulesShop();
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, 'Ana Ruiz');

    $returns = assistantSay($web, '¿Cómo son las devoluciones?');
    contains('No tengo a mano la política de devoluciones', $returns['text']);
    contains('Continuar con mi pedido', implode('|', $returns['suggestions']));

    $again = assistantSay($web, 'Continuar con mi pedido');
    contains('Todavía necesito', $again['text']);
    contains('la calle y el número', $again['text']);
    notContains('tu nombre', $again['text'], 'lo que ya dijo no se vuelve a pedir');
});

test('tras el resumen, «quiero cambiar la dirección» pide los datos nuevos; con ellos hay un resumen nuevo y el pedido sale con la dirección nueva', function (): void {
    [$app, $web] = chatRulesShop();
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, CHAT_DATA);
    assistantSay($web, 'transferencia');

    $change = assistantSay($web, 'Quiero cambiar la dirección');
    contains('Escríbeme los datos nuevos', $change['text']);
    $review = assistantSay($web, 'Luis Gil, calle Alta 2, 08001 Barcelona, 999000002, luis@correo.test');
    contains('Este es tu pedido:', $review['text']);
    contains('Calle Alta 2, 08001 Barcelona', $review['text']);
    notContains('Calle Mayor', $review['text']);

    assistantSay($web, 'confirmo');
    $order = chatOrders($app)[0];
    same('luis@correo.test', $order['email']);
    $shipping = json_decode((string) $order['billing'], true);
    same('Barcelona', $shipping['city']);
    same('08001', $shipping['postcode']);
});

test('sin pedido revisado, un «sí» no hace ningún pedido', function (): void {
    [$app, $web] = chatRulesShop();
    foreach (['sí', 'confirmo', 'adelante'] as $yes) {
        assistantSay($web, $yes);
    }
    same([], chatOrders($app));
    assistantSay($web, 'Quiero hacer el pedido');
    assistantSay($web, 'sí');
    same([], chatOrders($app), 'tampoco a mitad de recoger los datos');
});

test('con «assistant.checkout» apagado no hay compra en el chat: «quiero pagar» lleva a la caja y no sale ninguna herramienta de la caja', function (): void {
    $app = chatApp(['assistant.checkout' => false]);
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);

    $pay = assistantSay($web, 'Quiero pagar');
    contains('Tu carrito está listo', $pay['text']);
    same('checkout', $pay['actions'][0]['type']);
    $chips = assistantSay($web, 'Ver mi carrito')['suggestions'];
    same(['Ir a la caja', 'Gastos de envío'], $chips, 'sin la compra en el chat no se ofrece hacerla');
    foreach (assistantRows($app, 'conversation_messages', "role = 'assistant'") as $row) {
        notContains('checkout_status', (string) $row['tools']);
    }
});

test('por encima del tope «assistant.max_order_cents» el asistente no toma el pedido y lleva a la caja de la web', function (): void {
    [$app, $web] = chatRulesShop(['assistant.max_order_cents' => 5000]);

    $reply = assistantSay($web, 'Quiero hacer el pedido');
    contains('supera el importe que puedo tomar en el chat', $reply['text']);
    contains('https://tienda.test/finalizar-compra', chatAction($reply, 'checkout')['url']);
    same(null, chatAction($reply, 'confirm_order'));
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['flow']), 'no queda ningún pedido empezado');

    // Con el tope por encima del total el pedido se toma con normalidad.
    $app->settings()->setMany(['assistant.max_order_cents' => 7000]);
    $ok = assistantSay($web, 'Quiero hacer el pedido');
    contains('Hagamos tu pedido aquí mismo', $ok['text']);
});
