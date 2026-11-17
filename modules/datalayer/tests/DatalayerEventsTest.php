<?php
/**
 * AIFEC — pruebas de los eventos de comercio: ficha, carrito, caja y compra con sus importes contra el pedido, en céntimos; la compra
 * una sola vez, el correo resumido solo con consentimiento de marketing y los eventos propios de AIFEC.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Datalayer\EventBuilder;
use Aifec\Modules\Datalayer\UserData;

require_once __DIR__ . '/helpers.php';

test('los importes salen del decimal exacto: sin errores de coma flotante en el JSON', function (): void {
    foreach ([[1, '0.01'], [7, '0.07'], [10, '0.1'], [100, '1'], [1999, '19.99'], [2999, '29.99'], [100001, '1000.01'], [0, '0']] as $case) {
        same($case[1], (string) json_encode(EventBuilder::num($case[0])), 'céntimos ' . $case[0]);
    }
    same(1999, dlCents(EventBuilder::num(1999)));
});

test('view_item: la ficha lleva su producto con precio sin impuestos, impuestos aparte y marca y categoría', function (): void {
    $app = dlApp();
    $brand = aifecBrand($app, 'Sillas SA');
    $cat = aifecCategory($app, 'Sillas');
    $p = aifecProduct($app, ['name' => 'Silla "Ergo" & co', 'price' => 12100, 'sku' => 'ERGO-1', 'tax_class_id' => 'standard', 'brand_id' => $brand, 'categories' => [$cat]]);
    $html = (new TestClient($app))->get((string) $app->service('catalog')->product($p)['url'])->body;
    $event = dlEvent(dlEvents($html), 'view_item');
    check($event !== null, 'la ficha tiene su evento');
    $price = $app->service('catalog')->product($p)['price'];
    same((int) $price['net'], dlCents($event['ecommerce']['value']));
    same('EUR', $event['ecommerce']['currency']);
    $item = $event['ecommerce']['items'][0];
    same((string) $p, $item['item_id']);
    same('Silla "Ergo" & co', $item['item_name']);
    same((int) $price['net'], dlCents($item['price']));
    same(1, $item['quantity']);
    same('Sillas SA', $item['item_brand']);
    same('Sillas', $item['item_category']);
    same(10000, dlCents($item['price']), '121,00 € con IVA del 21 % son 100,00 € sin él');
    notContains('Silla "Ergo"', $html, 'el nombre va escapado dentro del JSON');
});

test('view_item con la opción de impuestos incluidos lleva el precio con IVA', function (): void {
    $app = dlApp(['settings' => ['datalayer.value_with_tax' => true]]);
    $p = aifecProduct($app, ['name' => 'Silla', 'price' => 12100, 'tax_class_id' => 'standard']);
    $event = dlEvent(dlEvents((new TestClient($app))->get((string) $app->service('catalog')->product($p)['url'])->body), 'view_item');
    same(12100, dlCents($event['ecommerce']['value']));
});

test('view_cart y begin_checkout salen con las líneas del carrito, su valor en céntimos y las opciones de envío y pago', function (): void {
    $app = dlApp();
    $web = dlWeb($app);
    $a = aifecProduct($app, ['name' => 'Silla', 'price' => 2000, 'tax_class_id' => 'standard']);
    $b = aifecProduct($app, ['name' => 'Cojín', 'price' => 1000, 'tax_class_id' => 'reduced']);
    aifecCartRun($web, static function ($cart) use ($a, $b): void {
        $cart->add($a, 0, 2);
        $cart->add($b, 0, 1);
        $cart->setPaymentMethod('gw');
    });
    $summary = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $page = $web->get($app->url()->route('cart'))->body;
    $view = dlEvent(dlEvents($page), 'view_cart');
    check($view !== null, 'el carrito tiene su evento');
    $net = 0;
    foreach ($summary['lines'] as $line) {
        $net += (int) $line['net'];
    }
    same($net, dlCents($view['ecommerce']['value']), 'el valor es la suma sin impuestos de las líneas');
    same(2, count($view['ecommerce']['items']));
    same(2, $view['ecommerce']['items'][0]['quantity']);
    same(array_keys($summary['lines']), array_keys($view['keys']), 'cada línea se enlaza con su clave');
    $co = $web->get($app->url()->route('checkout'))->body;
    $begin = dlEvent(dlEvents($co), 'begin_checkout');
    check($begin !== null, 'la caja tiene su evento');
    same($net, dlCents($begin['ecommerce']['value']));
    check(isset($begin['payment']['gw']), 'la forma de pago por su identificador');
    check($begin['shipping'] !== [], 'y el envío');
});

test('un carrito vacío no empuja ni view_cart ni begin_checkout', function (): void {
    $app = dlApp();
    $web = dlWeb($app);
    same([], dlEvents($web->get($app->url()->route('cart'))->body));
});

test('purchase: id de transacción, valor, impuestos, envío y artículos cuadran en céntimos con el pedido', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $order = $app->service('orders')->find($pedido['id']);
    $events = dlEvents(dlThanks($web, $pedido)->body);
    $purchase = dlEvent($events, 'purchase');
    check($purchase !== null, 'la página del pedido empuja la compra');
    $e = $purchase['ecommerce'];
    same($pedido['number'], $e['transaction_id']);
    same('EUR', $e['currency']);
    same((int) $order['total_tax'], dlCents($e['tax']), 'impuestos');
    $shipping = 0;
    $products = 0;
    foreach ($order['items'] as $row) {
        if ($row['kind'] === 'shipping') {
            $shipping += (int) $row['line_net'];
        } elseif ($row['kind'] === 'product') {
            $products += (int) $row['line_net'];
        }
    }
    same($shipping, dlCents($e['shipping']), 'envío sin impuestos');
    same($products, dlCents($e['value']), 'valor: los artículos sin impuestos');
    same((int) $order['total_net'], $products + $shipping, 'y con el envío es la base del pedido');
    same((int) $order['total'], $products + $shipping + (int) $order['total_tax'], 'el total del pedido cuadra con las partes del evento');
    same(2, count($e['items']));
    same('2', (string) $e['items'][0]['quantity']);
    same(1, $e['items'][1]['quantity']);
    $sum = 0;
    foreach ($e['items'] as $item) {
        $sum += dlCents($item['price']) * $item['quantity'];
    }
    same($products, $sum, 'precio por cantidad de los artículos = valor');
    same('purchase_' . $pedido['number'], $purchase['event_id']);
});

test('purchase con la opción de impuestos incluidos: valor con IVA, impuestos aparte', function (): void {
    $app = dlApp(['settings' => ['datalayer.value_with_tax' => true]]);
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $order = $app->service('orders')->find($pedido['id']);
    $e = dlEvent(dlEvents(dlThanks($web, $pedido)->body), 'purchase')['ecommerce'];
    $products = 0;
    $shipping = 0;
    foreach ($order['items'] as $row) {
        if ($row['kind'] === 'product') {
            $products += (int) $row['line_net'] + (int) $row['line_tax'];
        } elseif ($row['kind'] === 'shipping') {
            $shipping += (int) $row['line_net'] + (int) $row['line_tax'];
        }
    }
    same($products, dlCents($e['value']));
    same($shipping, dlCents($e['shipping']));
    same((int) $order['total'], dlCents($e['value']) + dlCents($e['shipping']), 'con impuestos, valor + envío es el total que pagó');
});

test('purchase lleva el cupón del pedido', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $app->db()->update('orders', ['coupons' => json_encode([['code' => 'VERANO', 'amount' => 500], ['code' => 'AMIGOS', 'amount' => 100]])], ['id' => $pedido['id']]);
    $e = dlEvent(dlEvents(dlThanks($web, $pedido)->body), 'purchase')['ecommerce'];
    same('VERANO,AMIGOS', $e['coupon']);
});

test('purchase se empuja una sola vez por pedido aunque se recargue la página', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    same(1, count(dlEvents(dlThanks($web, $pedido)->body)), 'la primera vez sale');
    same([], dlEvents(dlThanks($web, $pedido)->body), 'al recargar no sale');
    same([], dlEvents(dlThanks(dlWeb($app), $pedido)->body), 'ni desde otro navegador con el mismo enlace');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {datalayer_orders} WHERE order_id = ? AND browser_at IS NOT NULL', [$pedido['id']]), 'la marca está en el pedido');
});

test('una compra que espera a la pasarela no cuenta: sale cuando se confirma el pago, y solo entonces', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web, false);
    same([], dlEvents(dlThanks($web, $pedido)->body), 'sin pagar, la compra no se mide');
    same([], dlEvents(dlThanks($web, $pedido)->body));
    $order = $app->service('orders')->find($pedido['id']);
    $app->service('orders')->markPaid($pedido['id'], 'gw', 'PAY-X', (int) $order['total']);
    same(1, count(dlEvents(dlThanks($web, $pedido)->body)), 'confirmado el pago, sale una vez');
    same([], dlEvents(dlThanks($web, $pedido)->body));
});

test('un pedido anterior al módulo (sin marca) no se mide jamás', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $app->db()->delete('datalayer_orders', ['order_id' => $pedido['id']]);
    same([], dlEvents(dlThanks($web, $pedido)->body));
});

test('un pedido de pruebas de la pasarela no cuenta como compra', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $app->db()->update('orders', ['test' => 1], ['id' => $pedido['id']]);
    same([], dlEvents(dlThanks($web, $pedido)->body));
});

test('sin consentimiento de marketing no hay correo ni teléfono resumidos; con él, sí, con el resumen del dato normalizado', function (): void {
    $app = dlApp(['settings' => ['datalayer.ads_enhanced' => true, 'datalayer.ads_id' => 'AW-123456789', 'datalayer.ads_label' => 'xYz_abc123']]);
    $none = dlWeb($app, 's');
    $pedido = dlOrder($app, $none, true, ['email' => 'Ana.Ruiz@Cliente.test']);
    $page = dlThanks($none, $pedido)->body;
    $purchase = dlEvent(dlEvents($page), 'purchase');
    check(!isset($purchase['user_data']), 'con solo estadísticas no hay user_data');
    notContains(hash('sha256', 'ana.ruiz@cliente.test'), $page);
    notContains('ana.ruiz@cliente.test', $page);

    $with = dlWeb($app, 'sm');
    $pedido2 = dlOrder($app, $with, true, ['email' => 'Ana.Ruiz@Cliente.test']);
    $purchase = dlEvent(dlEvents(dlThanks($with, $pedido2)->body), 'purchase');
    same(hash('sha256', 'ana.ruiz@cliente.test'), $purchase['user_data']['sha256_email_address']);
    same(hash('sha256', '999345678'), $purchase['user_data']['sha256_phone_number']);
    notContains('Ana.Ruiz', json_encode($purchase));

    $gpc = dlWeb($app, 'sm');
    $pedido3 = dlOrder($app, $gpc, true, ['email' => 'otra@cliente.test']);
    $purchase = dlEvent(dlEvents($gpc->get($pedido3['url'], ['sec-gpc' => '1'])->body), 'purchase');
    check(!isset($purchase['user_data']), 'con Global Privacy Control tampoco');
});

test('las conversiones mejoradas apagadas no llevan ningún resumen aunque haya marketing', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    check(!isset(dlEvent(dlEvents(dlThanks($web, $pedido)->body), 'purchase')['user_data']), 'de serie no se manda ningún resumen');
});

test('el correo y el teléfono se normalizan como piden Google y Meta antes de resumirse', function (): void {
    same('ana.ruiz@cliente.test', UserData::normalizeEmail('  Ana.Ruiz@Cliente.TEST '));
    // La regla de Gmail (sin puntos ni «+etiqueta», googlemail = gmail) necesita su dominio: la parte de antes, de mentira.
    same('pruebaficticiaaifec0000@gmail.com', UserData::normalizeEmail('Prueba.Ficticia.AIFEC.0000+tienda@GoogleMail.com'));
    same('', UserData::normalizeEmail('no es un correo'));
    same('', UserData::normalizeEmail(''));
    same('999345678', UserData::normalizePhone('999 34-56 78'));
    same('+34999345678', UserData::normalizePhone('+34 999 345 678'));
    same('+34999345678', UserData::normalizePhone('0034 999 345 678'));
    same('', UserData::normalizePhone('123'));
    same('', UserData::normalizePhone('1234567890123456'));
    same(hash('sha256', 'ana@cliente.test'), UserData::emailHash('ANA@cliente.test'));
    same('', UserData::emailHash('basura'));
    same('', UserData::phoneHash(''));
});

test('un pedido hecho por el asistente lleva assistant_order con su id y el origen de IA de la visita', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $app->db()->update('orders', ['source' => 'assistant', 'attribution' => json_encode(['channel' => 'assistant', 'source' => 'ChatGPT'])], ['id' => $pedido['id']]);
    $app->db()->update('datalayer_orders', ['assistant' => 1, 'ai_source' => 'ChatGPT'], ['order_id' => $pedido['id']]);
    $events = dlEvents(dlThanks($web, $pedido)->body);
    $assistant = dlEvent($events, 'assistant_order');
    check($assistant !== null, 'sale assistant_order');
    same($pedido['number'], $assistant['transaction_id']);
    same('assistant_purchase_' . $pedido['number'], $assistant['event_id']);
    same('ChatGPT', dlEvent($events, 'purchase')['ai_source']);
    same(dlCents(dlEvent($events, 'purchase')['ecommerce']['value']), dlCents($assistant['value']));
});

test('el origen de IA y el canal del pedido se apuntan al hacerlo: un pedido normal no es del asistente', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $mark = $app->db()->row('SELECT * FROM {datalayer_orders} WHERE order_id = ?', [$pedido['id']]);
    same(0, (int) $mark['assistant']);
    same(null, $mark['ai_source']);
    $events = dlEvents(dlThanks($web, $pedido)->body);
    same(null, dlEvent($events, 'assistant_order'));
});

test('al hacer el pedido se apunta la decisión de cookies del visitante y nada más allá de las estadísticas y el marketing', function (): void {
    $app = dlApp();
    $a = dlOrder($app, dlWeb($app, 'psm'));
    $b = dlOrder($app, dlWeb($app, ''));
    $c = dlOrder($app, dlWeb($app));
    $d = dlOrder($app, dlWeb($app, 'p'));
    $consent = static function (int $id) use ($app): string {
        return (string) $app->db()->value('SELECT consent FROM {datalayer_orders} WHERE order_id = ?', [$id]);
    };
    same('sm', $consent($a['id']));
    same('', $consent($b['id']));
    same('', $consent($c['id']), 'sin decisión no hay nada concedido');
    same('', $consent($d['id']), 'preferencias no cuenta para medir');
});

test('el reembolso lleva el importe devuelto y su propio identificador, no el del pedido', function (): void {
    $app = dlApp();
    $web = dlWeb($app, 'sm');
    $pedido = dlOrder($app, $web);
    $order = $app->service('orders')->find($pedido['id']);
    $builder = $app->service('datalayer')->builder();
    $event = $builder->refund($order, ['id' => 7, 'amount' => 1234]);
    same('refund', $event['event']);
    same($pedido['number'], $event['ecommerce']['transaction_id']);
    same(1234, dlCents($event['ecommerce']['value']));
    same('refund_' . $pedido['number'] . '_7', $event['event_id']);
});

test('las piezas que enganchan la capa de datos siguen en las plantillas: ficha, carrito, caja y pedido', function (): void {
    $root = dirname(__DIR__, 2);
    foreach ([
        'catalog/views/front/product.php' => "hook('product.bottom'", 'orders/views/front/cart.php' => "hook('cart.after'",
        'orders/views/front/checkout.php' => "hook('checkout.after'", 'orders/views/front/order.php' => "hook('order.thanks'",
    ] as $file => $needle) {
        contains($needle, (string) file_get_contents($root . '/' . $file), $file . ' conserva su hueco');
    }
    $app = dlApp();
    foreach (['head', 'product.bottom', 'cart.after', 'checkout.after', 'order.thanks'] as $slot) {
        check($app->hooks()->has($slot), 'el hueco «' . $slot . '» tiene quien lo pinte');
    }
});
