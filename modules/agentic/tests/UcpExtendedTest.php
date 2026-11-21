<?php
/**
 * AIFEC — pruebas de continuidad, aislamiento y contratos de comercio.
 * @author Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

use Aifec\Modules\Agentic\UcpCheckouts;
use Aifec\Modules\Agentic\UcpSignatures;

require_once __DIR__ . '/UcpKit.php';

test('UCP: PUT persiste y una cantidad inválida conserva el carrito previo', function (): void {
    [$app, $p] = ucpApp();
    $created = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1)));
    $path = '/ucp/v1/checkout-sessions/' . $created['id'];
    same(200, ucpCall($app, 'PUT', $path, ucpBody($p, 3))->status, 'cambiar cantidad');
    same(3, ucpJson(ucpCall($app, 'GET', $path))['line_items'][0]['quantity'], 'persiste entre peticiones');
    $invalid = ucpBody($p);
    $invalid['line_items'][0]['quantity'] = 1.5;
    same(400, ucpCall($app, 'PUT', $path, $invalid)->status, 'no truncar fracciones');
    same(3, ucpJson(ucpCall($app, 'GET', $path))['line_items'][0]['quantity'], 'sin efectos parciales');
});

test('UCP: credenciales, dueño y cuerpos no válidos no filtran compras', function (): void {
    [$app, $p] = ucpApp();
    $a = ucpKey($app);
    $b = ucpKey($app);
    $h = ['authorization' => 'Bearer ' . $a];
    $created = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p), $h));
    $path = '/ucp/v1/checkout-sessions/' . $created['id'];
    same(404, ucpCall($app, 'GET', $path)->status, 'no es pública');
    same(404, ucpCall($app, 'GET', $path, null, ['authorization' => 'Bearer ' . $b])->status, 'otra plataforma tampoco');
    same(200, ucpCall($app, 'GET', $path, null, $h)->status, 'el dueño sí');
    same(401, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p), ['authorization' => 'Bearer invalid'])->status, 'credencial errónea no degrada a anónimo');
    same(400, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', '[]')->status, 'solo objetos JSON');
    same(415, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p), ['content-type' => 'text/plain'])->status, 'tipo correcto');
});

test('UCP: convertir carrito ignora líneas superpuestas y conserva el contenido', function (): void {
    [$app, $p] = ucpApp();
    $cart = ucpJson(ucpCall($app, 'POST', '/ucp/v1/carts', ucpBody($p, 2)));
    check(isset($cart['ucp']['capabilities']['dev.ucp.shopping.cart']), 'carrito real anunciado');
    check(!isset($cart['status']), 'el contrato de carrito no lleva estado de checkout');
    $converted = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ['cart_id' => $cart['id'], 'line_items' => [['item' => ['id' => (string) $p], 'quantity' => 8]]]);
    same(201, $converted->status, 'se convierte');
    same(2, ucpJson($converted)['line_items'][0]['quantity'], 'el contenido anterior manda');
    same(410, ucpCall($app, 'GET', '/ucp/v1/carts/' . $cart['id'])->status, 'el carrito ya convertido no se modifica');
});

test('UCP: cancelar y caducar invalidan también el enlace del navegador', function (): void {
    [$app, $p] = ucpApp();
    $created = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p)));
    $resume = parse_url($created['continue_url'], PHP_URL_PATH);
    ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $created['id'] . '/cancel', []);
    same(410, ucpCall($app, 'GET', $resume)->status, 'cancelada no recupera el carrito');
    $other = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p)));
    UcpCheckouts::update($app, $other['id'], ['created_at' => $app->clock()->sql($app->clock()->now() - 86401)]);
    same(410, ucpCall($app, 'GET', '/ucp/v1/checkout-sessions/' . $other['id'])->status, 'caduca la API');
    same(410, ucpCall($app, 'GET', parse_url($other['continue_url'], PHP_URL_PATH))->status, 'caduca el enlace');
});

test('UCP: la firma de respuesta se verifica con la clave pública del perfil', function (): void {
    [$app, $p] = ucpApp();
    $response = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p));
    $profile = ucpJson(ucpCall($app, 'GET', '/.well-known/ucp'));
    $jwk = $profile['keys'][0];
    $input = substr($response->headers['Signature-Input'], 5);
    $base = '"@status": ' . $response->status . "\n" . '"content-type": ' . $response->headers['Content-Type'] . "\n"
        . '"content-digest": ' . $response->headers['Content-Digest'] . "\n" . '"@signature-params": ' . $input;
    $signature = base64_decode(substr($response->headers['Signature'], 6, -1), true);
    $public = base64_decode(strtr($jwk['x'], '-_', '+/'), true);
    check(sodium_crypto_sign_verify_detached($signature, $base, $public), 'firma Ed25519 válida');
    check(!sodium_crypto_sign_verify_detached($signature, $base . 'changed', $public), 'un cambio la rompe');
    $older = ucpJson(ucpCall($app, 'GET', '/.well-known/ucp/2026-04-08'));
    same('2026-04-08', $older['ucp']['version'], 'perfil anterior disponible');
});

test('UCP: el catálogo comunica existencias y pedidos bajo reserva con datos nativos', function (): void {
    [$app, $product] = ucpApp();
    $lookup = static function () use ($app, $product): array {
        $response = ucpCall($app, 'POST', '/ucp/v1/catalog/lookup', ['ids' => [(string) $product]], ['ucp-agent' => '']);
        same(200, $response->status, 'consulta del catálogo');
        return ucpJson($response)['products'][0]['variants'][0]['availability'];
    };
    same(['available' => true, 'status' => 'in_stock'], $lookup(), 'producto disponible');
    $app->db()->update('products', ['stock' => 0], ['id' => $product]);
    same(['available' => false, 'status' => 'out_of_stock'], $lookup(), 'sin inventar existencias');
    $app->db()->update('products', ['backorders' => 'yes'], ['id' => $product]);
    same(['available' => true, 'status' => 'backorder'], $lookup(), 'permiso nativo de reserva');
});

test('UCP: la búsqueda reutiliza reglas de precio del buscador sin inferencia de pago', function (): void {
    require_once __DIR__ . '/../../search/tests/helpers.php';
    $app = searchApp(['agentic.ucp_enabled' => true, 'modules.active' => ['catalog', 'search', 'media', 'orders', 'agentic']], ['orders', 'agentic']);
    $cheap = aifecProduct($app, ['name' => 'Camiseta económica', 'price' => 500, 'stock' => 10]);
    aifecProduct($app, ['name' => 'Camiseta premium', 'price' => 2500, 'stock' => 10]);
    $app->service('search')->reindexNow('product');
    $response = ucpCall($app, 'POST', '/ucp/v1/catalog/search', ['query' => 'camiseta por menos de 10 €'], ['ucp-agent' => '']);
    same(200, $response->status, 'consulta válida');
    $products = ucpJson($response)['products'];
    same(1, count($products), 'se respeta el presupuesto expresado');
    same((string) $cheap, $products[0]['id'], 'solo la camiseta de cinco euros');
});

test('UCP: una pregunta coloquial recupera el producto y mantiene el presupuesto', function (): void {
    require_once __DIR__ . '/../../search/tests/helpers.php';
    $app = searchApp(['agentic.ucp_enabled' => true, 'modules.active' => ['catalog', 'search', 'media', 'orders', 'agentic']], ['orders', 'agentic']);
    $chair = aifecProduct($app, ['name' => 'Silla plegable de camping', 'price' => 1800, 'stock' => 7]);
    aifecProduct($app, ['name' => 'Silla premium de camping', 'price' => 6500, 'stock' => 7]);
    aifecProduct($app, ['name' => 'Mesa ligera de camping', 'price' => 1200, 'stock' => 7]);
    $paraglider = aifecProduct($app, ['name' => 'Parapente Céfiro Breeze', 'price' => 139000, 'stock' => 2]);
    $app->service('search')->reindexNow('product');
    $response = ucpCall($app, 'POST', '/ucp/v1/catalog/search',
        ['query' => '¿Tenéis alguna silla sencilla para camping?', 'filters' => ['price' => ['max' => 2500]]], ['ucp-agent' => '']);
    same(200, $response->status);
    same([(string) $chair], array_column(ucpJson($response)['products'], 'id'));
    foreach (['silla sencilla para camping', '¿Tenéis alguna silla sencilla para camping?'] as $query) {
        $missing = ucpCall($app, 'POST', '/ucp/v1/catalog/search', ['query' => $query, 'filters' => ['price' => ['max' => 1300]]], ['ucp-agent' => '']);
        same([], ucpJson($missing)['products'], 'una mesa de camping no satisface la consulta');
        $found = $app->service('search')->query($query, ['lang' => 'es']);
        check(in_array($chair, $found['ids'], true) && count($found['ids']) === 2, 'el buscador compartido conserva el producto principal');
        $asker = $app->service('search')->asker();
        $planned = $asker->run($asker->plan($query, 'es', false, false), ['price_max' => 2500], false, false);
        same([$chair], array_map('intval', array_column($planned['listing']['items'] ?? [], 'id')),
            'el buscador de API y MCP conserva el producto y el presupuesto');
    }
    $checklist = ['silla' => 2, 'silla sencilla para camping' => 2, '¿Tenéis alguna silla sencilla para camping?' => 2,
        'parapente' => 1, 'zxqvpfjkg' => 0, '' => 0];
    foreach ($checklist as $query => $count) {
        $res = ucpCall($app, 'POST', '/ucp/v1/catalog/search', ['query' => $query], ['ucp-agent' => '']);
        same(200, $res->status);
        $products = array_column(ucpJson($res)['products'], 'id');
        same($count, count($products), 'resultados controlados en ' . $query);
        if ($query === 'parapente') {
            same([(string) $paraglider], $products, 'parapente real del catálogo');
        }
    }
});

test('UCP: la búsqueda mantiene el uso pedido aunque el índice esté desactivado', function (): void {
    require_once __DIR__ . '/../../search/tests/helpers.php';
    $app = searchApp(['agentic.ucp_enabled' => true, 'modules.active' => ['catalog', 'media', 'orders', 'agentic']], ['orders', 'agentic']);
    check(!$app->has('search'), 'misma configuración de AIFEC raíz');
    $chair = aifecProduct($app, ['name' => 'Silla Altitud Bora 5', 'price' => 1800, 'stock' => 7]);
    aifecProduct($app, ['name' => 'Mesa de camping', 'price' => 1200, 'stock' => 7]);
    aifecProduct($app, ['name' => 'Silla Cima Aerodinámica', 'price' => 6500, 'stock' => 7]);
    foreach (['silla sencilla para camping', '¿Tenéis alguna silla sencilla para camping?'] as $query) {
        $listing = $app->service('catalog')->listing(['q' => $query, 'price_max' => 2500]);
        same([$chair], array_map('intval', array_column($listing['items'], 'id')), 'el catálogo compartido conserva el producto y el precio');
        $r = ucpCall($app, 'POST', '/ucp/v1/catalog/search',
            ['query' => $query, 'filters' => ['price' => ['max' => 2500]]], ['ucp-agent' => '']);
        same(200, $r->status);
        same([], ucpJson($r)['products'], 'una silla de parapente no satisface la consulta');
    }
    $camping = aifecProduct($app, ['name' => 'Silla plegable de camping', 'price' => 2400, 'stock' => 4]);
    $r = ucpCall($app, 'POST', '/ucp/v1/catalog/search', ['query' => '¿Tenéis alguna silla sencilla para camping?'], ['ucp-agent' => '']);
    same([(string) $camping], array_column(ucpJson($r)['products'], 'id'));
});
