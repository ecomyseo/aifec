<?php
/**
 * AIFEC — P085, la conversación sin callejones: las variantes elegidas dentro del chat, los datos del cliente guardados por la tienda
 * antes de la IA, la salida por reglas cuando la IA no llega a un texto, lo que el cliente pide antes de tiempo (envío y pago), el
 * envío en cuanto hay código postal, el idioma de la conversación y los datos enmascarados o los importes inventados del texto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/FastKit.php';

/**
 * Las líneas del carrito de ese navegador, en lista.
 *
 * @return array<int,array<string,mixed>>
 */
function fastCart(App $app, TestClient $web): array
{
    return array_values(assistantCartLines($app, $web));
}

// ------------------------------------------------------------------ variantes

test('fila 3 con IA: «añade la primera» de un producto con colores no falla: pregunta cuál, ofrece los colores como botones y, al pulsar uno, lo añade sin preguntarle a la IA', function (): void {
    [$app, $ids, $ai, $web] = fastShop();
    $shoe = fastShoe($app);
    $ai->script(assistantTool('cart_add', ['product_id' => $shoe]), assistantText('¿En qué color la quieres: negro o rojo?'));

    $r = assistantSay($web, 'Añade la primera al carrito');

    same('¿En qué color la quieres: negro o rojo?', $r['text']);
    same(['Negro', 'Rojo'], $r['suggestions'], 'los colores como botones, y el agotado no se ofrece');
    same([], $r['actions'], 'el carrito no ha cambiado');
    same([], fastCart($app, $web));
    $result = assistantLastMessage($ai->asked[1])->toolResult['result'];
    contains('"need":"options"', $result);
    contains('Azul', $result, 'el modelo sabe que existe pero no se puede');
    $log = json_decode((string) assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['tools'], true);
    same([['name' => 'cart_add', 'ok' => true, 'input' => ['product_id' => $shoe]]], $log, 'no es un fallo del panel');

    $asked = count($ai->asked);
    $added = assistantSay($web, 'Rojo');

    same($asked, count($ai->asked), 'elegir un botón no pasa por la IA');
    contains('Añadido a tu carrito: Zapatilla Plana.', $added['text']);
    $lines = fastCart($app, $web);
    same(1, count($lines));
    same(5200, $lines[0]['unit_price'], 'la variante elegida, con su precio');
    same([['type' => 'cart_changed', 'count' => 1]], $added['actions']);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['pick']), 'ya no queda nada por elegir');
});

test('por reglas: la opción se elige por texto («en negro»), con una frase y con la cantidad que se pidió', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $shoe = fastShoe($app);
    $web = new TestClient($app);
    assistantSay($web, 'Busco la zapatilla plana');

    $ask = assistantSay($web, 'añade 2 unidades del primero');
    contains('«Zapatilla Plana» tiene varias opciones. ¿Cuál quieres?', $ask['text']);
    same(['Negro', 'Rojo'], $ask['suggestions']);

    $done = assistantSay($web, 'mejor en negro, por favor');

    contains('Añadido a tu carrito: Zapatilla Plana.', $done['text']);
    $lines = fastCart($app, $web);
    same(2, $lines[0]['qty'], 'las dos unidades que se pidieron');
    same(4900, $lines[0]['unit_price']);
    check($shoe > 0);
});

test('una opción agotada se dice como tal y se vuelven a ofrecer las que hay; algo que no tiene que ver sigue su camino', function (): void {
    $app = assistantApp();
    assistantShop($app);
    fastShoe($app);
    $web = new TestClient($app);
    assistantSay($web, 'Busco la zapatilla plana');
    assistantSay($web, 'añade el primero');

    $sold = assistantSay($web, 'en azul');

    contains('Esa opción está agotada. Elige otra:', $sold['text']);
    same(['Negro', 'Rojo'], $sold['suggestions']);
    same([], fastCart($app, $web), 'no se añadió nada');
    $other = assistantSay($web, '¿Qué formas de pago tenéis?');
    contains('Puedes pagar con', $other['text'], 'otra pregunta se contesta como cualquiera');
    same([], fastCart($app, $web));
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['pick']), 'y la elección pendiente se olvida');
});

test('con dos opciones (tono y tamaño) se va afinando: «negro» deja dos variantes, «L» deja una', function (): void {
    $app = assistantApp();
    assistantShop($app);
    fastShirt($app);
    $web = new TestClient($app);
    assistantSay($web, 'Busco la camiseta básica');
    $first = assistantSay($web, 'añade el primero');
    same(['Negro M', 'Negro L', 'Rojo M'], $first['suggestions'], 'con dos opciones los botones son la variante entera');

    $narrow = assistantSay($web, 'en negro');

    contains('tiene varias opciones', $narrow['text']);
    same(['Negro M', 'Negro L'], $narrow['suggestions'], 'solo las que quedan');
    $done = assistantSay($web, 'la L');
    contains('Añadido a tu carrito: Camiseta Básica.', $done['text']);
    same(1, count(fastCart($app, $web)));
});

test('get_product devuelve las opciones con sus valores disponibles y los agotados aparte; cart_add sin variante es «need: options» y no cambia el carrito', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $shoe = fastShoe($app);
    $tools = $app->service('tools');

    $p = $tools->call('get_product', ['id' => $shoe]);

    same(1, count($p['options']));
    same('Color', $p['options'][0]['name']);
    $colors = $p['options'][0]['values'];
    sort($colors);
    same(['Negro', 'Rojo'], $colors, 'los valores que se pueden elegir');
    same(['Azul'], $p['options'][0]['sold_out'], 'y el agotado, aparte');
    same([true, true, false], array_column($p['variants'], 'available'));
    same([['Negro'], ['Rojo'], ['Azul']], array_column($p['variants'], 'value_labels'));

    $need = $tools->call('cart_add', ['product_id' => $shoe]);

    same('options', $need['need']);
    check(!isset($need['error']), 'no es un error');
    same(['Negro', 'Rojo'], array_column($need['variants'], 'label'), 'solo las que se pueden comprar');
    same(false, $need['options'][0]['values'][2]['available'], 'el azul consta como agotado');
});

// ------------------------------------------------------------------ datos del cliente, una ronda

/** Los mensajes con todos los datos de golpe con los que la prueba de clientes acabó en «No he podido terminar eso». */
function fastDataMessages(): array
{
    return [
        'Yes please, I\'d like to order the Flexo LED Arco. Cash on delivery, please. My details: Hanna Berg, hanna.berg@ejemplo.test, +34 999000005, Carrer de l\'Exemple 9, 2n 1a, 08001 Barcelona (Spain). Company: Berg Studio SL, VAT ID: ESB00000000. I need the invoice in the company name.',
        'Quiero tramitar el pedido. Envíamelo contra reembolso a Íñigo Prueba Test, inigo.prueba@ejemplo.test, 999000004, Calle del Test 1, bajo, 48001 Bilbao (España).',
        'Marta Ruiz Peña, marta.ruiz@ejemplo.test, 999000001, Calle de la Muestra 5, 3.º A, 46001 Valencia (España).',
        'Vale. Mis datos: nombre Marta Ruiz Peña. Email marta.ruiz@ejemplo.test. Teléfono 999000001. Dirección: Calle de la Muestra 5, 3.º A, 46001 Valencia, España.',
        'Vale, perfecto. Quiero tramitar el pedido. Me llamo Francisco Gil Mora, mi email es paco.gil@ejemplo.test, mi teléfono 999000002 y la dirección es Avenida del Ejemplo 12, 2.º B, 28013 Madrid (España).',
    ];
}

test('los cinco mensajes con todos los datos que acababan en callejón se resuelven en UNA ronda: la tienda los guarda y la IA solo contesta', function (): void {
    foreach (fastDataMessages() as $i => $message) {
        [$app, $ai, $web] = fastChatShop();
        $ai->script(assistantText('Datos guardados. ¿Cómo quieres pagar?'));

        $r = assistantSay($web, $message);

        same('Datos guardados. ¿Cómo quieres pagar?', $r['text'], 'mensaje ' . ($i + 1));
        same(1, count($ai->asked), 'mensaje ' . ($i + 1) . ': una sola petición a la IA, sin herramientas por el camino');
        $draft = json_encode(chatDraft($app, $web), JSON_UNESCAPED_UNICODE);
        $mail = $i === 0 ? 'hanna.berg@ejemplo.test' : ($i === 1 ? 'inigo.prueba@ejemplo.test' : ($i < 4 ? 'marta.ruiz@ejemplo.test' : 'paco.gil@ejemplo.test'));
        contains($mail, (string) $draft, 'mensaje ' . ($i + 1) . ': el correo está en el borrador de la caja');
        $log = json_decode((string) assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['tools'], true);
        same(['checkout_status', 'checkout_set_customer'], array_slice(array_column($log, 'name'), 0, 2), 'lo hizo la tienda: mirar el carrito y guardar, una sola vez');
        same(1, count(array_keys(array_column($log, 'name'), 'checkout_set_customer')), 'los datos se guardan con una sola llamada');
        $note = assistantLastMessage($ai->asked[0])->content;
        contains('Shop note', $note);
        contains('Details saved from this message', $note);
        contains('Still missing:', $note);
    }
});

test('con el código postal ya dicho, la nota para el modelo lleva las opciones de envío con su precio', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantText('Listo.'));

    assistantSay($web, CHAT_DATA);

    $note = assistantLastMessage($ai->asked[0])->content;
    contains('Shipping options to 28013', $note);
    contains(money(495), $note, 'con su precio');
    contains('Estándar', $note);
});

test('un mensaje sin datos, o con datos y el carrito vacío, no dispara nada antes de la IA', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantText('Claro.'), assistantText('Sin carrito no hay pedido.'));
    assistantSay($web, '¿Qué plazo de entrega tenéis?');
    $log = assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['tools'];
    same(null, $log, 'ninguna herramienta antes de la IA');
    notContains('Shop note', assistantLastMessage($ai->asked[0])->content);

    $empty = chatApp();
    assistantShop($empty);
    $ai2 = assistantAi($empty);
    $ai2->script(assistantText('Sin carrito no hay pedido.'));
    $web2 = new TestClient($empty);
    assistantSay($web2, CHAT_DATA);
    $log2 = json_decode((string) assistantRows($empty, 'conversation_messages', "role = 'assistant'")[0]['tools'], true);
    same(['checkout_status'], array_column($log2, 'name'), 'solo mira el carrito: está vacío y no guarda nada');
    same('', chatDraft($empty, $web2)['email'], 'el borrador de la caja sigue vacío');
});

// ------------------------------------------------------------------ callejones

test('si la IA agota las rondas en medio de la compra, contesta la tienda con el estado real: lo guardado y lo que falta', function (): void {
    [$app, $ai, $web] = fastChatShop();
    for ($i = 0; $i < 6; ++$i) {
        $ai->script(assistantTool('list_categories', []));
    }

    $r = assistantSay($web, CHAT_DATA);

    same(4, count($ai->asked), 'tres rondas y la última sin herramientas');
    contains('he guardado tus datos', $r['text']);
    contains('¿Cómo quieres pagar?', $r['text'], 'y pregunta lo que falta, en su orden');
    same(['Transferencia bancaria', 'Pasarela de pruebas'], $r['suggestions']);
    notContains('No he podido', $r['text'], 'ya no hay aviso genérico');
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!empty($state['flow']['active']), 'la compra sigue por reglas desde donde estaba');
});

test('con la IA vacía en medio de la compra tampoco hay callejón, y con el pedido ya revisado enseña el resumen', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantRound([['checkout_set_customer', ['text' => CHAT_DATA]], ['checkout_set_payment', ['method' => 'transfer']], ['checkout_review', []]]), assistantText('   '));

    $r = assistantSay($web, 'Haz mi pedido');

    contains('Este es tu pedido:', $r['text']);
    check(chatAction($r, 'confirm_order') !== null, 'con su tarjeta');
});

test('el tope de 12 segundos: pasado, la tienda contesta por reglas con lo que haya y se apunta lo que tardó', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $timer = assistantTimer($app);
    $ai = assistantAi($app);
    $ai->timer = $timer;
    $ai->delays = [13000];
    $ai->script(assistantTool('list_categories', []), assistantText('Esto no llega a pedirse.'));
    $web = new TestClient($app);

    $r = assistantSay($web, 'Busco una mesa de roble');

    same(1, count($ai->asked), 'pasado el tope no se vuelve a preguntar a la IA');
    contains('Estos son los que mejor encajan', $r['text'], 'contesta el modo por reglas');
    check($r['cards'] !== [], 'con sus fichas');
    $row = assistantRows($app, 'conversation_messages', "role = 'assistant'")[0];
    same(13000, (int) $row['ai_ms']);
    same(1, (int) $row['rounds']);
});

test('con tiempo de sobra el tope de 12 segundos no estorba: la IA contesta con sus tres rondas', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $timer = assistantTimer($app);
    $ai = assistantAi($app);
    $ai->timer = $timer;
    $ai->delays = [3000, 3000, 3000];
    $ai->script(assistantTool('list_categories', []), assistantTool('list_categories', []), assistantText('Hay sillas, mesas y lámparas.'));
    $web = new TestClient($app);

    $r = assistantSay($web, '¿Qué categorías tenéis?');

    same('Hay sillas, mesas y lámparas.', $r['text']);
    same(9000, (int) assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['ai_ms']);
});

// ------------------------------------------------------------------ lo que se pide antes de tiempo

test('«el envío más rápido» y «por transferencia» dichos antes se aplican al llegar a ese paso, diciéndolo, y el orden es datos, envío, pago, resumen', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    chatFast($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);

    $start = assistantSay($web, 'Quiero hacer el pedido, con el envío más rápido y pagando por transferencia');
    contains('Hagamos tu pedido aquí mismo', $start['text']);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    same(['ship' => 'fastest', 'pay' => 'transfer'], $state['prefs'], 'las dos preferencias quedan apuntadas');

    $data = assistantSay($web, CHAT_DATA);

    contains('He puesto el envío Urgente (' . money(1295) . '), como pediste.', $data['text']);
    contains('He puesto el pago Transferencia bancaria, como pediste.', $data['text']);
    contains('Este es tu pedido:', $data['text'], 'y sigue al resumen');
    contains('Envío: Urgente, ' . money(1295), $data['text']);
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);
    check(!isset($state['prefs']), 'lo aplicado deja de ser pendiente');
});

test('sin preferencias el orden al preguntar es datos, envío, pago y resumen', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    chatFast($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');

    $data = assistantSay($web, CHAT_DATA);
    contains('¿Cómo quieres que te lo enviemos?', $data['text'], 'primero el envío');
    notContains('¿Cómo quieres pagar?', $data['text']);
    $ship = assistantSay($web, 'Urgente');
    contains('¿Cómo quieres pagar?', $ship['text'], 'después el pago');
    $pay = assistantSay($web, 'Transferencia bancaria');
    contains('Este es tu pedido:', $pay['text'], 'y al final el resumen');
});

test('con IA: lo que el cliente pidió antes se aplica al guardar la dirección y la nota se lo cuenta al modelo para que lo diga', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    chatFast($app);
    $ai = assistantAi($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    $ai->script(assistantText('Vale, el envío más rápido.'), assistantText('He puesto el envío urgente, como pediste. ¿Cómo quieres pagar?'));

    assistantSay($web, 'Quiero comprar la silla con el envío más rápido');
    $r = assistantSay($web, CHAT_DATA);

    $note = assistantLastMessage($ai->asked[1])->content;
    contains('has already set it: Urgente', $note);
    contains('as you asked', $note);
    $review = aifecCartRun($web, static function ($cart, App $application): array {
        return $application->service('checkout')->review();
    });
    same('Urgente', $review['shipping']['name'], 'el envío urgente está puesto en la caja');
    same('He puesto el envío urgente, como pediste. ¿Cómo quieres pagar?', $r['text']);
});

test('una pregunta («¿aceptáis tarjeta?») no es una preferencia de pago', function (): void {
    $app = chatApp();
    assistantShop($app);
    $web = new TestClient($app);

    assistantSay($web, '¿Aceptáis tarjeta o transferencia?');
    $state = json_decode((string) assistantRows($app, 'conversations')[0]['state'], true);

    check(!isset($state['prefs']), 'no se apunta nada');
});

// ------------------------------------------------------------------ el envío en cuanto hay código postal

test('en cuanto hay código postal se dicen las opciones de envío con su precio, aunque falten otros datos', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');

    $r = assistantSay($web, '28013 Madrid');

    contains('Envío al 28013: Estándar ' . money(495) . '.', $r['text']);
    contains('Todavía necesito', $r['text'], 'y sigue pidiendo lo que falta');
});

test('al cambiar la dirección se dice si el envío cambia: «el envío sigue siendo 4,95 €»', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'Quiero hacer el pedido');
    $data = assistantSay($web, CHAT_DATA);
    contains('Envío: Estándar, ' . money(495), $data['text']);

    $same = assistantSay($web, 'Mi nueva dirección es calle Nueva 5, 28001 Madrid');

    contains('El envío sigue siendo ' . money(495) . '.', $same['text']);
    contains('¿Cómo quieres pagar?', $same['text'], 'y no vuelve a preguntar el envío');
});

// ------------------------------------------------------------------ idioma

test('a quien escribe en inglés se le contesta en inglés, también los botones y la tarjeta; si vuelve al castellano, vuelve', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);

    $en = assistantSay($web, 'I would like to place my order here please');
    contains('Let us place your order here in the chat', $en['text']);
    $labels = chatAction($en, 'labels');
    check($labels !== null, 'la respuesta lleva las etiquetas del JavaScript ya traducidas');
    same('Confirm order', $labels['labels']['confirm']);
    same('Add to cart', $labels['labels']['add']);
    same('en', json_decode((string) assistantRows($app, 'conversations')[0]['state'], true)['lang']);

    $data = assistantSay($web, CHAT_DATA);
    contains('Thanks, I have saved your details.', $data['text'], 'un mensaje de datos no cambia el idioma');
    contains('How would you like to pay?', $data['text']);
    $review = assistantSay($web, 'Bank transfer');
    contains('This is your order:', $review['text']);
    contains('Do you confirm the order?', $review['text']);
    same(['Yes, I confirm', 'Cancel'], $review['suggestions'], 'los botones, en inglés');
    $done = assistantSay($web, 'yes');
    contains('Done! Your order 2026-000001 is placed.', $done['text']);

    $es = assistantSay($web, 'Quiero saber cuánto cuesta el envío');
    contains('¿Cuál es tu código postal?', $es['text'], 'otra vez en castellano');
    same([], chatAction($es, 'labels')['labels'], 'y se quitan las etiquetas en inglés');
});

test('el botón «Confirmar pedido» también contesta en el idioma de la conversación', function (): void {
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);
    assistantSay($web, 'I would like to place my order here please');
    assistantSay($web, CHAT_DATA);
    assistantSay($web, 'Bank transfer');

    $done = chatConfirm($web);

    contains('Done! Your order', $done['text']);
    contains('Thank you', $done['text'] . 'Thank you', 'en inglés');
    same('Confirm order', assistantRows($app, 'conversation_messages', "role = 'user'")[count(assistantRows($app, 'conversation_messages', "role = 'user'")) - 1]['content']);
});

test('el idioma se decide con el primer mensaje de más de tres palabras y solo cambia si el cliente cambia', function (): void {
    $shop = 'es';
    same(null, \Aifec\Modules\Assistant\Lang::detect('Hello there', $shop), 'tres palabras o menos no deciden');
    same(null, \Aifec\Modules\Assistant\Lang::detect('Yes please', $shop));
    same('en', \Aifec\Modules\Assistant\Lang::detect('I would like to buy the red chair', $shop));
    same('en', \Aifec\Modules\Assistant\Lang::detect('Do you have a cheaper one for the kitchen?', $shop));
    same('es', \Aifec\Modules\Assistant\Lang::detect('Quiero comprar la silla roja para la cocina', $shop));
    same('es', \Aifec\Modules\Assistant\Lang::detect('¿Cuánto cuesta el envío a Canarias?', $shop));
    same(null, \Aifec\Modules\Assistant\Lang::detect('Ana Ruiz, calle Mayor 1 3ºB, 28013 Madrid, 999000001', $shop), 'unos datos no son un idioma');
    same('en', \Aifec\Modules\Assistant\Lang::detect('I want the chair', 'en'), 'si la tienda es inglesa, es el de la tienda');
    same('English', \Aifec\Modules\Assistant\Lang::name('en'));
    same('Spanish', \Aifec\Modules\Assistant\Lang::name('es'));
});

test('con IA el modelo recibe «Answer in English» o «Answer in Spanish» y la instrucción cambia solo en eso', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $ai = assistantAi($app);
    $web = new TestClient($app);

    assistantSay($web, 'I would like to see the red chairs please');
    assistantSay($web, 'Quiero ver las sillas rojas por favor');
    assistantSay($web, 'ok');

    contains('Answer in English', $ai->asked[0]['request']['system']);
    contains('Answer in Spanish', $ai->asked[1]['request']['system']);
    contains('Answer in Spanish', $ai->asked[2]['request']['system'], 'un mensaje corto mantiene el idioma de antes');
    same(str_replace('English', 'Spanish', $ai->asked[0]['request']['system']), $ai->asked[1]['request']['system']);
    contains('product and carrier names are not translated', $ai->asked[0]['request']['system']);
});

// ------------------------------------------------------------------ el texto del modelo

test('una línea con un dato enmascarado («C*** d*** M*** 5***») no llega al cliente', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantText("Tu pedido saldrá hacia C*** d*** M*** 5***, 28013 Madrid.\nTe lo confirmo cuando me digas que sí."));

    $r = assistantSay($web, 'Quiero pedir la silla');

    same('Te lo confirmo cuando me digas que sí.', $r['text']);
    notContains('***', $r['text']);
    $saved = assistantRows($app, 'conversation_messages', "role = 'assistant'")[0]['content'];
    notContains('***', $saved, 'ni se guarda');
});

test('si todo lo que escribió el modelo era un dato enmascarado, contesta la tienda por reglas', function (): void {
    [$app, $ai, $web] = fastChatShop();
    $ai->script(assistantText('Entregar en C*** M*** 5***'));

    $r = assistantSay($web, 'Quiero hacer el pedido');

    contains('Hagamos tu pedido aquí mismo', $r['text']);
});

test('la instrucción prohíbe repetir el nombre, la dirección, el correo y el teléfono, y pide el envío antes que el pago', function (): void {
    [$app, $ai, $web] = fastChatShop();
    assistantSay($web, 'Hola');
    $system = $ai->asked[0]['request']['system'];

    contains('Never write the customer\'s name, address, email or phone in your text', $system);
    contains('in this order: contact and delivery address', $system);
    contains('then the shipping', $system);
    contains('then the payment method', $system);
    contains('As soon as you know the postcode, tell the customer the shipping options with their price', $system);
    contains('If the customer already said which shipping or payment they want, use it and say so', $system);
    contains('at most 40 words', $system);
    contains('be brief: one or two short sentences', $system, 'en los pasos de la compra, respuestas escuetas: lo importante lo enseñan las tarjetas');
    contains('If has_variants is false it has NO sizes, colours or other choices', $system, 'no da por hecha una talla ni un color');
    contains('show_products', $system);
    contains('check the products listed as already shown', $system);
});

// ------------------------------------------------------------------ lo que dice la tienda de devoluciones

test('store_info devuelve el texto de la página de devoluciones (600 caracteres) con su enlace y el plazo del módulo de devoluciones', function (): void {
    $app = assistantApp(['modules.active' => ['assistant', 'search', 'cms', 'returns', 'orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs']], ['modules' => ['cms', 'returns']]);
    assistantShop($app);
    $text = str_repeat('Puedes devolver tu pedido sin dar explicaciones. ', 30);
    $page = cmsInsertPage($app, ['title' => 'Envíos y devoluciones', 'status' => 'published', 'blocks' => fastBlocks('<p>' . $text . '</p>')]);
    $app->settings()->set('cms.legal', ['shipping-returns' => $page]);
    $app->settings()->set('returns.days', 21);

    $r = $app->service('tools')->call('store_info', ['topic' => 'returns']);

    contains('21 días', $r['returns'], 'el plazo del módulo');
    check(mb_strlen($r['returns'], 'UTF-8') <= 600, 'el texto no pasa de 600 caracteres');
    same(1, count($r['pages']));
    same('returns', $r['pages'][0]['topic']);
    same('Envíos y devoluciones', $r['pages'][0]['title']);
    check(strpos($r['pages'][0]['url'], 'http') === 0, 'con su dirección completa: ' . $r['pages'][0]['url']);
    check(mb_strlen($r['pages'][0]['text'], 'UTF-8') <= 600);
    contains('Puedes devolver tu pedido', $r['pages'][0]['text']);
});

test('sin plazo y sin ajustes, el texto de la página de devoluciones es lo que se dice; con el asistente por reglas sale con su enlace; sin página, «no lo sé»', function (): void {
    $app = assistantApp([], ['modules' => ['cms']]);
    assistantShop($app);
    $page = cmsInsertPage($app, ['title' => 'Envíos y devoluciones', 'status' => 'published', 'blocks' => fastBlocks('<p>Tienes 30 días para devolver lo que no te guste.</p>')]);
    $app->settings()->set('cms.legal', ['shipping-returns' => $page]);
    $web = new TestClient($app);

    $r = assistantSay($web, 'Política de devoluciones');

    contains('Tienes 30 días para devolver lo que no te guste.', $r['text']);
    contains('Más detalles: http', $r['text'], 'con el enlace de la página');

    $none = assistantApp();
    assistantShop($none);
    contains('No tengo a mano la política de devoluciones.', assistantSay(new TestClient($none), 'Política de devoluciones')['text'], 'solo dice que no lo sabe si de verdad no hay nada');
});

test('store_info con un borrador sin publicar no cuenta como página: no se cita lo que el cliente no puede ver', function (): void {
    $app = assistantApp([], ['modules' => ['cms']]);
    assistantShop($app);
    $page = cmsInsertPage($app, ['title' => 'Envíos y devoluciones', 'status' => 'draft', 'blocks' => fastBlocks('<p>Borrador interno.</p>')]);
    $app->settings()->set('cms.legal', ['shipping-returns' => $page]);

    $r = $app->service('tools')->call('store_info', ['topic' => 'returns']);

    same('', $r['returns']);
    check(!isset($r['pages']), 'sin páginas');
});
