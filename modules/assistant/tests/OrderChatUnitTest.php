<?php
/**
 * AIFEC — las piezas de la compra en el chat por separado: qué es una afirmación clara, cómo se enmascara un dato personal, cuándo
 * vale una confirmación esperada y lo que hace cada herramienta de la caja con su entrada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Assistant\Affirmation;
use Aifec\Modules\Assistant\CheckoutTurn;
use Aifec\Modules\Assistant\Masker;
use Aifec\Modules\Assistant\ToolRunner;

require_once __DIR__ . '/OrderChatKit.php';

/**
 * Llama a una herramienta de la caja dentro de una petición de ese navegador, con su carrito y, si se da, su CheckoutTurn.
 *
 * @param array<string,mixed> $input
 *
 * @return array<string,mixed>
 */
function chatCall(TestClient $web, string $tool, array $input = [], ?CheckoutTurn $turn = null): array
{
    $out = aifecCartRun($web, static function ($cart, App $app) use ($tool, $input, $turn): array {
        return $app->service('tools')->call($tool, $input, ['cart' => $cart, 'checkout_turn' => $turn, 'conversation_id' => 1]);
    });

    return is_array($out) ? $out : [];
}

/**
 * Una tienda con la silla roja en el carrito de un navegador nuevo.
 *
 * @return array{0:App,1:TestClient}
 */
function chatToolShop(): array
{
    $app = chatApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    chatCart($app, $web, $ids['silla_roja']);

    return [$app, $web];
}

test('afirmación clara: «sí», «confirmo», «adelante», «vale, pídelo» y sus variantes, con o sin signos, mayúsculas, cortesía o dos frases juntas', function (): void {
    chatApp();
    foreach (['sí', 'Si', 'SÍ.', 'sí!', 'confirmo', 'Confirmo.', 'lo confirmo', 'adelante', 'vale', 'Vale, pídelo', 'vale pidelo', 'pídelo', 'haz el pedido', 'hazlo',
        'de acuerdo', 'ok', 'OK, gracias', 'sí por favor', 'Sí, por favor', 'sí, confirmo', 'si confirmo', 'perfecto', 'está bien', 'estoy de acuerdo', 'dale',
        'yes', 'Yes please', 'go ahead', 'confirm', 'I confirm', 'place the order', 'okay', 'sure'] as $text) {
        same(true, Affirmation::isClear($text), '«' . $text . '» es una afirmación clara');
    }
});

test('no es una afirmación clara: lo que lleva algo más, lo que dice que no, lo que pregunta y lo vacío', function (): void {
    chatApp();
    foreach (['', '   ', '...', 'no', 'No, gracias', 'sí, pero cambia la dirección', 'sí pero quiero otra silla', 'no confirmo', 'no, no lo confirmo', '¿lo confirmo?',
        'confirma tú la dirección', 'quiero cambiar el pago', 'cuánto cuesta el envío', 'sí quiero saber si hay garantía', 'Ana Ruiz', 'pídelo mañana', 'hola',
        'sí sí sí sí sí sí sí sí sí', 'yes but change the address', 'not yet', str_repeat('sí ', 12)] as $text) {
        same(false, Affirmation::isClear($text), '«' . $text . '» no lo es');
    }
});

test('enmascarar: el correo, el teléfono, el NIF, el nombre y la calle', function (): void {
    same('a***@correo.test', Masker::email('ana@correo.test'));
    same('***', Masker::email('sin-arroba'));
    same('***001', Masker::phone('999000001'));
    same('***001', Masker::phone('+34 999 000 001'));
    same('***', Masker::phone(''));
    same('***78Z', Masker::id('12345678Z'));
    same('A*** R***', Masker::words('Ana Ruiz'));
    same('C*** M*** 1*** 3***', Masker::words('Calle Mayor 1 3ºB'));
    same('Á*** Ñ***', Masker::words('Álvaro Ñúñez'), 'con tildes y eñes');
    same('', Masker::words('   '));
    same([
        'name' => 'A*** R***', 'line1' => 'C*** M*** 1***', 'line2' => '3***', 'postcode' => '28013', 'city' => 'Madrid', 'country' => 'ES', 'phone' => '***001',
    ], Masker::address(['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'line2' => '3ºB', 'postcode' => '28013', 'city' => 'Madrid', 'country' => 'ES', 'phone' => '999000001']));
});

test('enmascarar un texto: los datos con forma de dato, los que se sabe que son personales y, si se pide, las calles; lo demás se deja', function (): void {
    same('Mi correo es a***@correo.test y mi teléfono ***001', Masker::text('Mi correo es ana@correo.test y mi teléfono 999 000 001'));
    same('Soy A*** R*** y vivo en Madrid', Masker::text('Soy Ana Ruiz y vivo en Madrid', ['Ana Ruiz']));
    same('Anabel pide una silla', Masker::text('Anabel pide una silla', ['Ana']), 'un nombre conocido solo se tapa como palabra entera');
    same('Vivo en calle ***, 28013 Madrid', Masker::text('Vivo en calle Mayor 1 3ºB, 28013 Madrid', [], true));
    same('Vivo en calle Mayor 1', Masker::text('Vivo en calle Mayor 1'), 'las calles no se tocan si no se pide');
    same('NIF ***78Z', Masker::text('NIF 12345678Z'));
    same('Valor $1 y \\2 con A*** R***', Masker::text('Valor $1 y \\2 con Ana Ruiz', ['Ana Ruiz']), 'los símbolos de una sustitución no rompen nada');
    same('', Masker::text(''));
});

test('el mensaje que se guarda: uno normal se queda como está y uno con datos de contacto se enmascara entero', function (): void {
    foreach (['Busco una silla roja', 'Mi pedido es el 2026-000123', 'Zapatillas para paseo marítimo', '¿Cuánto cuesta el envío a 28013?', 'Hola & adiós «gracias» 😀'] as $text) {
        same($text, Masker::forStorage($text), $text);
    }
    same('A*** R***, c*** M*** 1*** 3***, 28013 Madrid, ***001, a***@correo.test', Masker::forStorage(CHAT_DATA));
    same('Mi NIF es ***78Z', Masker::forStorage('Mi NIF es 12345678Z'));
    $masked = Masker::forStorage('ana@correo.test 999000001 Ana Ruiz calle Mayor 1 28013 Madrid');
    foreach (['ana@correo.test', '999000001', 'Ruiz', 'Mayor'] as $secret) {
        notContains($secret, $masked);
    }
});

test('la confirmación esperada: se arma con la ficha y el total, caduca a la media hora, se gasta una vez y una ficha mal formada no vale', function (): void {
    $token = str_repeat('ab', 32);
    $turn = new CheckoutTurn(null, 1000);
    same(false, $turn->armed());
    same(false, $turn->confirm(), 'sin nada esperando no se puede confirmar');
    same(false, $turn->confirmed());

    $turn->arm($token, 6485);
    same(true, $turn->armed());
    same($token, $turn->token());
    same(6485, $turn->total());
    same(false, $turn->confirmed(), 'armar no es confirmar');
    same(true, $turn->confirm());
    same(true, $turn->confirmed());
    $turn->consume();
    same(false, $turn->armed());
    same(false, $turn->confirmed(), 'gastada, no vale otra vez');
    same('', $turn->token());

    $saved = ['token' => $token, 'total' => 6485, 'at' => 1000];
    same($saved, (new CheckoutTurn($saved, 1000 + CheckoutTurn::TTL))->await(), 'justo en el límite todavía vale');
    same(true, (new CheckoutTurn($saved, 1000 + CheckoutTurn::TTL))->armed());
    same(false, (new CheckoutTurn($saved, 1001 + CheckoutTurn::TTL))->armed(), 'un segundo después, no');
    $late = new CheckoutTurn($saved, 1001 + CheckoutTurn::TTL);
    same(false, $late->confirm());

    foreach ([['token' => 'corto', 'total' => 1, 'at' => 1000], ['token' => $token], ['token' => 5, 'total' => 1, 'at' => 1], ['token' => $token, 'total' => 'x', 'at' => 1], []] as $bad) {
        same(null, (new CheckoutTurn($bad, 1000))->await(), 'una ficha mal formada se descarta');
    }
    $armed = new CheckoutTurn($saved, 1000);
    $armed->disarm();
    same(null, $armed->await());
});

test('checkout_status: lo que falta en orden, el resumen del carrito y lo guardado con los datos personales enmascarados', function (): void {
    [$app, $web] = chatToolShop();

    $empty = chatCall(new TestClient($app), 'checkout_status');
    same(['error'], array_keys($empty), 'con el carrito vacío no hay nada de que hablar');
    contains('carrito está vacío', $empty['error']);

    $status = chatCall($web, 'checkout_status');
    same(false, $status['ready']);
    same(['contact', 'address', 'payment'], $status['missing']);
    same(['email', 'name', 'street', 'postcode', 'city', 'phone'], $status['missing_fields']);
    same(['count' => 1, 'total' => money(6485), 'total_cents' => 6485], $status['cart']);
    same('', $status['email']);
    same(false, $status['over_limit']);

    chatCall($web, 'checkout_set_customer', ['text' => CHAT_DATA]);
    $after = chatCall($web, 'checkout_status');
    same(['payment'], $after['missing']);
    same('a***@correo.test', $after['email']);
    same('A*** R***', $after['address']['name']);
    same('C*** M*** 1***', $after['address']['line1']);
    same('3***', $after['address']['line2']);
    same('***001', $after['address']['phone']);
    same('28013', $after['address']['postcode']);
    same('Madrid', $after['address']['city']);
    same('Estándar', $after['shipping']['name']);
    same(null, $after['payment']);
    $json = (string) json_encode($after, JSON_UNESCAPED_UNICODE);
    foreach (['ana@correo.test', 'Ruiz', 'Calle Mayor', '999000001'] as $secret) {
        notContains($secret, $json);
    }
});

test('checkout_set_customer: entiende el texto libre, guarda lo que vale, devuelve lo entendido enmascarado y lo que falta; sin datos da un error', function (): void {
    [$app, $web] = chatToolShop();

    $none = chatCall($web, 'checkout_set_customer', ['text' => 'hola, quiero la silla']);
    same(['error'], array_keys($none));
    $nothing = chatCall($web, 'checkout_set_customer', []);
    same(['error'], array_keys($nothing), 'sin texto ni campos tampoco');

    $partial = chatCall($web, 'checkout_set_customer', ['text' => 'Soy Ana Ruiz y mi correo es ana@correo.test']);
    same('A***', $partial['understood']['first_name']);
    same('a***@correo.test', $partial['understood']['email']);
    same(['contact', 'address', 'payment'] === $partial['missing'] ? [] : [], [], 'se devuelve lo que falta');
    same(['street', 'postcode', 'city', 'phone'], $partial['missing_fields']);
    check(in_array('email', $partial['saved'], true), 'se guardó el correo');
    same('ana@correo.test', chatDraft($app, $web)['email']);

    $fields = chatCall($web, 'checkout_set_customer', ['line1' => 'Calle Mayor 1', 'postcode' => '28013', 'city' => 'Madrid', 'phone' => '999000013']);
    same([], $fields['missing_fields'], 'con campos sueltos también');
    same(['payment'], $fields['missing']);
    same('Calle Mayor 1', chatDraft($app, $web)['shipping']['line1']);

    $bad = chatCall($web, 'checkout_set_customer', ['postcode' => 'ABCDE']);
    check(isset($bad['errors']['shipping.postcode']), 'un código postal que no vale se avisa');
    same('28013', chatDraft($app, $web)['shipping']['postcode'], 'y no se guarda');
});

test('checkout_set_customer: una calle nueva sin piso borra el piso de la dirección anterior', function (): void {
    [$app, $web] = chatToolShop();
    chatCall($web, 'checkout_set_customer', ['text' => CHAT_DATA]);
    same('3ºB', chatDraft($app, $web)['shipping']['line2']);

    chatCall($web, 'checkout_set_customer', ['text' => 'calle Alta 2, 08001 Barcelona']);

    $shipping = chatDraft($app, $web)['shipping'];
    same('Calle Alta 2', $shipping['line1']);
    same('', $shipping['line2'], 'el piso de la calle anterior ya no vale');
    same('Ana', $shipping['first_name'], 'lo que no se cambió se queda');
});

test('envío y pago: las opciones salen del carrito, elegir una que no existe da un error y no se guarda, y el envío necesita antes la dirección', function (): void {
    [$app, $web] = chatToolShop();
    chatFast($app);

    $early = chatCall($web, 'checkout_shipping_options');
    contains('dirección de entrega', $early['error']);
    chatCall($web, 'checkout_set_customer', ['text' => CHAT_DATA]);

    $shipping = chatCall($web, 'checkout_shipping_options');
    same(['Estándar', 'Urgente'], array_column($shipping['options'], 'name'));
    same([money(495), money(1295)], array_column($shipping['options'], 'price'));
    same([495, 1295], array_column($shipping['options'], 'price_cents'));
    same(true, $shipping['needs_shipping']);
    $fast = (int) $shipping['options'][1]['id'];
    $set = chatCall($web, 'checkout_set_shipping', ['carrier_id' => $fast]);
    same(true, $set['ok']);
    same(5990 + 1295, $set['total_cents'], 'el total sale del carrito');
    $wrong = chatCall($web, 'checkout_set_shipping', ['carrier_id' => 9999]);
    same(['error'], array_keys($wrong));
    same(5990 + 1295, chatTotal($app, $web), 'no se cambió nada');

    $payment = chatCall($web, 'checkout_payment_options');
    same(['transfer', 'gw'], array_column($payment['options'], 'id'));
    same(['offline', 'test'], array_column($payment['options'], 'kind'), 'la transferencia se paga fuera y la pasarela de pruebas en línea');
    $setPay = chatCall($web, 'checkout_set_payment', ['method' => 'transfer']);
    same('Transferencia bancaria', $setPay['payment']['name']);
    $badPay = chatCall($web, 'checkout_set_payment', ['method' => 'bitcoin']);
    same(['error'], array_keys($badPay));
    $invalid = chatCall($web, 'checkout_set_payment', ['method' => 'transfer', 'fee' => 0]);
    contains('input.fee is not accepted', $invalid['error'], 'ninguna herramienta acepta un importe');
});

test('checkout_review: sin datos dice qué falta y no deja nada esperando; con todo, el pedido exacto y la conversación esperando confirmación', function (): void {
    [$app, $web] = chatToolShop();
    $turn = new CheckoutTurn(null, 1000);

    $notReady = chatCall($web, 'checkout_review', [], $turn);
    same(false, $notReady['ready']);
    same(['contact', 'address', 'payment'], $notReady['missing']);
    same(false, $turn->armed(), 'sin lo necesario no se arma nada');

    chatCall($web, 'checkout_set_customer', ['text' => CHAT_DATA], $turn);
    chatCall($web, 'checkout_set_payment', ['method' => 'transfer'], $turn);
    $review = chatCall($web, 'checkout_review', [], $turn);

    same(true, $review['ready']);
    same(true, $review['awaiting_confirmation']);
    same(6485, $review['total_cents']);
    same(money(6485), $review['total']);
    same('EUR', $review['currency']);
    same(1, count($review['lines']));
    same(495, $review['shipping']['price']);
    same('transfer', $review['payment']['id']);
    same(true, $turn->armed());
    same(6485, $turn->total());
    check(!array_key_exists('confirm_token', $review), 'la ficha no sale en lo que recibe el modelo');
    notContains($turn->token(), (string) json_encode($review));
    same('Ana Ruiz', $turn->card['name'], 'la tarjeta para la persona lleva su nombre entero');
    same('A*** R***', $review['address']['name'], 'y lo que recibe el modelo, enmascarado');
});

test('checkout_review: cambiar un dato después anula la espera; la API y el MCP (sin conversación) no arman nada ni pueden confirmar', function (): void {
    [$app, $web] = chatToolShop();
    $turn = new CheckoutTurn(null, 1000);
    chatCall($web, 'checkout_set_customer', ['text' => CHAT_DATA], $turn);
    chatCall($web, 'checkout_set_payment', ['method' => 'transfer'], $turn);
    chatCall($web, 'checkout_review', [], $turn);
    same(true, $turn->armed());

    chatCall($web, 'checkout_set_payment', ['method' => 'gw'], $turn);
    same(false, $turn->armed(), 'elegir otro pago anula la espera');

    $noTurn = chatCall($web, 'checkout_review', []);
    same(true, $noTurn['ready']);
    same(false, $noTurn['awaiting_confirmation'], 'sin conversación nadie puede confirmar');
    $place = chatCall($web, 'checkout_place', []);
    same(['error'], array_keys($place));
    same([], chatOrders($app));
});

test('checkout_place: sin confirmación de la persona (sin espera, esperando sin confirmar o caducada) da un error y no crea ningún pedido', function (): void {
    [$app, $web] = chatToolShop();
    chatCall($web, 'checkout_set_customer', ['text' => CHAT_DATA]);
    chatCall($web, 'checkout_set_payment', ['method' => 'transfer']);
    $turn = new CheckoutTurn(null, 1000);
    chatCall($web, 'checkout_review', [], $turn);
    $token = $turn->token();

    same(['error'], array_keys(chatCall($web, 'checkout_place', [], $turn)), 'esperando pero sin confirmar');
    same(true, $turn->armed(), 'el intento no gasta la espera');
    same(['error'], array_keys(chatCall($web, 'checkout_place', [], new CheckoutTurn(null, 1000))), 'sin espera');
    $late = new CheckoutTurn(['token' => $token, 'total' => 6485, 'at' => 1000], 1000 + CheckoutTurn::TTL + 5);
    $late->confirm();
    same(['error'], array_keys(chatCall($web, 'checkout_place', [], $late)), 'caducada');
    same(['error'], array_keys(chatCall($web, 'checkout_place', ['token' => $token], $turn)), 'la herramienta no acepta la ficha del modelo');
    same([], chatOrders($app));

    $turn->confirm();
    $placed = chatCall($web, 'checkout_place', [], $turn);
    same(true, $placed['ok']);
    same(6485, $placed['total_cents']);
    same(false, $turn->armed(), 'y la espera se gasta');
    same(1, count(chatOrders($app)));
});

test('el registro de herramientas no guarda datos personales: el texto del cliente, el correo, el teléfono y la dirección salen como «***»', function (): void {
    same([
        'text' => '***', 'email' => '***', 'phone' => '***', 'first_name' => '***', 'last_name' => '***', 'line1' => '***', 'line2' => '***', 'vat_id' => '***',
        'postcode' => '28013', 'city' => 'Madrid', 'carrier_id' => 2, 'method' => 'transfer', 'query' => 'silla',
    ], ToolRunner::redact([
        'text' => CHAT_DATA, 'email' => 'ana@correo.test', 'phone' => '999000001', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'line2' => '3ºB',
        'vat_id' => '12345678Z', 'postcode' => '28013', 'city' => 'Madrid', 'carrier_id' => 2, 'method' => 'transfer', 'query' => 'silla',
    ]));
});

test('el servicio assistant: confirm() sin nada esperando devuelve null, también para una conversación que no existe lanza, y no se crea nada', function (): void {
    $app = chatApp();
    assistantShop($app);
    $web = new TestClient($app);
    assistantSay($web, 'Hola');
    $assistant = $app->service('assistant');

    same(null, $assistant->confirm((int) assistantRows($app, 'conversations')[0]['id']));
    throws(static function () use ($assistant): void {
        $assistant->confirm(999);
    }, \InvalidArgumentException::class);
    same([], chatOrders($app));
    same(true, $assistant->checkoutEnabled());
});
