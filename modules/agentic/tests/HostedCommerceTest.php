<?php
/** Comprueba que el pago pasa al escaparate y la sesión recoge el pedido de ese mismo carrito. */
declare(strict_types=1);
require_once __DIR__ . '/UcpKit.php';
require_once __DIR__ . '/../../orders/tests/FrontKit.php';

test('ACP y UCP entregan el carrito a la caja y recogen su pedido confirmado', function (): void {
    foreach (['ACP', 'UCP'] as $protocol) {
        [$app, $product] = ucpApp(['agentic.acp_enabled' => true]);
        $gateway = new AifecOrderPayment('gw');
        $app->service('payments')->register($gateway);
        $key = ucpKey($app);
        $headers = ['authorization' => 'Bearer ' . $key, 'idempotency-key' => 'hosted-create', 'api-version' => '2026-04-17'];
        $base = $protocol === 'ACP' ? '/checkout_sessions' : '/ucp/v1/checkout-sessions';
        $body = $protocol === 'ACP' ? [
            'currency' => 'eur', 'capabilities' => new stdClass(),
            'line_items' => [['id' => (string) $product, 'quantity' => 1]],
            'buyer' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@cliente.test'],
            'fulfillment_details' => ['name' => 'Ana Ruiz', 'phone_number' => '999345678', 'address' => [
                'name' => 'Ana Ruiz', 'line_one' => 'Calle Mayor 1', 'postal_code' => '28001', 'city' => 'Madrid', 'state' => '28', 'country' => 'ES',
            ]],
        ] : ucpBody($product);
        $create = ucpCall($app, 'POST', $base, $body, $headers);
        same(201, $create->status, $protocol . ' crea la sesión: ' . $create->body);
        $view = ucpJson($create);
        same('requires_escalation', $view['status'], $protocol . ' lleva el pago a la tienda');
        $complete = ucpCall($app, 'POST', $base . '/' . $view['id'] . '/complete', [], ['idempotency-key' => 'hosted-complete'] + $headers);
        same('requires_escalation', ucpJson($complete)['status'], $protocol . ' confirma en la caja');
        same(0, count(ucpOrders($app)), 'la API jamás crea un pedido al pedir el pago');
        $handoff = new TestClient($app);
        $resume = $handoff->get((string) parse_url($view['continue_url'], PHP_URL_PATH));
        check($resume->isRedirect(), 'el navegador sigue a la caja');
        $checkout = $handoff->follow();
        same(200, $checkout->status, 'la caja se abre con el carrito original');
        check(isset($handoff->cookies[$app->cookieName('k')]), 'la caja recibe la ficha del carrito');
        $form = frontForm($handoff, frontFill(['payment_method' => 'gw']), $checkout->body);
        $placed = $handoff->post('/finalizar-compra/place', $form);
        check($placed->isRedirect(), 'el formulario nativo crea el pedido');
        $orders = ucpOrders($app);
        same(1, count($orders), 'un pedido del carrito preparado');
        $app->service('orders')->markPaid((int) $orders[0]['id'], 'gw', 'sandbox-hosted-confirmed', (int) $orders[0]['total'], [], true);
        $final = ucpJson(ucpCall($app, 'GET', $base . '/' . $view['id'], null, $headers));
        same('completed', $final['status'], 'el protocolo confirma después de la pasarela');
        check(isset($final['order']['permalink_url']), 'el pedido anuncia su enlace');
    }
});

test('El feed ACP sirve el catálogo público y anuncia el pago en el comercio', function (): void {
    [$app, $product] = ucpApp(['agentic.acp_enabled' => true]);
    $id = aifecProduct($app, ['name' => 'Silla de roble', 'price' => 1200, 'images' => 2, 'stock' => 5]);
    $response = ucpCall($app, 'GET', '/acp/feed.jsonl');
    same(200, $response->status, 'catálogo público');
    $response->drain();
    $rows = array_map(static function (string $line): array { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, explode("\n", trim($response->body)));
    $row = array_column($rows, null, 'item_id')[(string) $id];
    same('Silla de roble', $row['title']);
    same('in_stock', $row['availability']);
    same(false, $row['is_eligible_checkout']);
    check((bool) filter_var($row['image_url'], FILTER_VALIDATE_URL), 'la imagen es pública');
});
