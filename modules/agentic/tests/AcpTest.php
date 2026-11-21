<?php
/**
 * AIFEC — contrato ACP y recorrido de compra sobre la caja de la tienda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/UcpKit.php';
require_once __DIR__ . '/../../../protocol/Acp.php';

/** Datos ficticios; la caja usa el catálogo y las tarifas reales de la aplicación de prueba. */
function acpTestBody(int $product): array
{
    return [
        'line_items' => [['id' => (string) $product]], 'currency' => 'eur', 'capabilities' => [],
        'buyer' => ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@cliente.test'],
        'fulfillment_details' => ['name' => 'Ana Ruiz', 'phone_number' => '999000201', 'address' => [
            'name' => 'Ana Ruiz', 'line_one' => 'Calle de Ejemplo 1', 'city' => 'Madrid', 'state' => 'Madrid', 'country' => 'ES', 'postal_code' => '28013',
        ]],
    ];
}

function acpTestCall(App $app, string $method, string $path, $body, string $key, string $idempotency = '', array $headers = []): Response
{
    $headers += ['api-version' => AifecCommerceAcp::VERSION];
    if ($key !== '') {
        $headers += ['authorization' => 'Bearer ' . $key];
    }
    if ($idempotency !== '') {
        $headers['idempotency-key'] = $idempotency;
    }
    if (is_array($body) && isset($body['capabilities']) && $body['capabilities'] === []) {
        $body['capabilities'] = new stdClass();
    }

    $response = ucpCall($app, $method, $path, $body, $headers);
    $evidence = __DIR__ . '/../../../tests/CommerceProtocols/evidence/Lumen/acp-responses.jsonl';
    $data = json_decode($response->body, true);
    if (is_array($data)) {
        file_put_contents($evidence, json_encode(['method' => $method, 'path' => $path, 'http_status' => $response->status, 'response' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }

    return $response;
}

test('ACP: el contrato portable conserva cantidades, entrega y credenciales sin aceptar precios del agente', function (): void {
    $body = acpTestBody(42);
    $body['line_items'][0]['quantity'] = 3;
    $body['line_items'][0]['unit_amount'] = 1;
    $body['selected_fulfillment_options'] = [['type' => 'shipping', 'option_id' => 'carrier_7', 'item_ids' => ['line_1']]];
    $body['discounts'] = ['codes' => ['EJEMPLO']];
    $ucp = AifecCommerceAcp::request($body, 'create');
    same([['item' => ['id' => '42'], 'quantity' => 3]], $ucp['line_items'], 'la tienda recibe el identificador y las unidades; nunca el precio');
    same('EUR', $ucp['currency'], 'la moneda se normaliza');
    same('Calle de Ejemplo 1', $ucp['fulfillment']['methods'][0]['destinations'][0]['street_address'], 'se traduce la dirección');
    same('Ruiz', $ucp['fulfillment']['methods'][0]['destinations'][0]['last_name'], 'se conserva el apellido');
    same('carrier_7', $ucp['fulfillment']['methods'][0]['groups'][0]['selected_option_id'], 'se elige la entrega');
    same(['EJEMPLO'], $ucp['discounts']['codes'], 'se conservan los cupones');
    $repeated = acpTestBody(42);
    $repeated['line_items'][] = ['id' => '42'];
    same(2, AifecCommerceAcp::request($repeated, 'create')['line_items'][0]['quantity'], 'dos artículos idénticos del esquema oficial se agrupan en dos unidades');
    $business = acpTestBody(42);
    $business['buyer']['company'] = ['name' => 'Empresa de Pruebas', 'tax_id' => 'TEST-NO-VALIDO', 'department' => 'Pruebas'];
    same($business['buyer']['company'], AifecCommerceAcp::request($business, 'create')['buyer']['company'], 'los datos fiscales de empresa se conservan sin inventar un DNI personal');
    foreach ([0, -1, 1.5, '2', true, 1000, null] as $quantity) {
        $bad = $body;
        $bad['line_items'][0]['quantity'] = $quantity;
        $caught = false;
        try {
            AifecCommerceAcp::request($bad, 'create');
        } catch (InvalidArgumentException $e) {
            $caught = true;
        }
        check($caught, 'se rechaza la cantidad sin convertirla: ' . json_encode($quantity));
    }
    $payment = ['payment_data' => ['handler_id' => 'handler_1', 'instrument' => ['type' => 'card', 'credential' => ['type' => 'PAYMENT_GATEWAY', 'token' => 'token-ficticio']]]];
    check(!isset(AifecCommerceAcp::request($payment, 'complete')['payment']), 'la tienda no entrega credenciales a pasarelas desde ACP');
});

test('ACP: la respuesta traduce el contrato completo y nunca devuelve un token de pago', function (): void {
    $ucp = [
        'id' => 'chk_ejemplo', 'status' => 'ready_for_complete', 'currency' => 'EUR',
        'line_items' => [['id' => 'line_1', 'item' => ['id' => '42', 'title' => 'Camiseta', 'price' => 2000], 'quantity' => 1, 'totals' => [['type' => 'total', 'amount' => 2000]]]],
        'totals' => [['type' => 'subtotal', 'amount' => 2000], ['type' => 'total', 'amount' => 2495]],
        'links' => [['type' => 'terms_of_service', 'url' => 'https://tienda.test/condiciones']],
        'messages' => [['type' => 'error', 'code' => 'payment_ineligible', 'severity' => 'recoverable', 'content' => 'Termina en la caja.']],
        'payment' => ['instruments' => [['credential' => ['token' => 'no-devolver-este-token']]]],
        'ucp' => ['payment_handlers' => []],
    ];
    $out = AifecCommerceAcp::response($ucp);
    same('2026-04-17', $out['protocol']['version'], 'versión ACP');
    same('ready_for_payment', $out['status'], 'estado ACP');
    same('eur', $out['currency'], 'moneda ACP');
    same(['id' => '42', 'name' => 'Camiseta', 'unit_amount' => 2000], $out['line_items'][0]['item'], 'artículo ACP');
    same('terms_of_use', $out['links'][0]['type'], 'enlace legal ACP');
    same('unsupported', $out['messages'][0]['code'], 'código de error ACP');
    same('plain', $out['messages'][0]['content_type'], 'formato de mensaje ACP');
    check(isset($out['totals'][0]['display_text']), 'todos los totales llevan su texto');
    check(strpos(json_encode($out), 'no-devolver-este-token') === false, 'las credenciales no se devuelven');
});

test('ACP: el descubrimiento estable anuncia únicamente el transporte y la compra implementados', function (): void {
    [$app] = ucpApp(['agentic.acp_enabled' => true]);
    $r = acpTestCall($app, 'GET', '/.well-known/acp.json', null, '');
    same(200, $r->status, 'el perfil de descubrimiento es público');
    $discovery = ucpJson($r);
    same('acp', $discovery['protocol']['name'], 'el protocolo');
    same(['2026-04-17'], $discovery['protocol']['supported_versions'], 'versiones realmente implementadas');
    same('https://tienda.test', $discovery['api_base_url'], 'base antes de checkout_sessions');
    same(['rest'], $discovery['transports'], 'solo REST');
    same(['checkout'], $discovery['capabilities']['services'], 'solo el servicio de compra ACP');
    same(['eur'], $discovery['capabilities']['supported_currencies'], 'moneda de la tienda');
});

test('ACP: autenticación, versión, idempotencia y aislamiento antes de mutar carritos', function (): void {
    [$app, $product] = ucpApp(['agentic.acp_enabled' => true]);
    $key = ucpKey($app, ['acp']);
    $path = '/checkout_sessions';
    $body = acpTestBody($product);
    same(401, acpTestCall($app, 'POST', $path, $body, 'incorrecta', 'auth')->status, 'sin clave válida no hay carrito');
    same(400, acpTestCall($app, 'POST', $path, $body, $key)->status, 'idempotencia obligatoria');
    same(400, acpTestCall($app, 'POST', $path, $body, $key, 'version', ['api-version' => '2025-01-01'])->status, 'versión no compatible');
    same(400, acpTestCall($app, 'POST', $path, '[]', $key, 'array')->status, 'una lista JSON no es una petición de compra');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {ucp_checkouts}'), 'los errores no crean sesiones');
    $r = acpTestCall($app, 'POST', $path, $body, $key, 'crear-una-vez');
    same(201, $r->status, 'se crea la sesión: ' . $r->body);
    $created = ucpJson($r);
    same('2026-04-17', $created['protocol']['version'], 'respuesta ACP');
    same(2000, $created['line_items'][0]['item']['unit_amount'], 'precio calculado por la tienda');
    same(2495, array_column($created['totals'], 'amount', 'type')['total'], 'total con un envío');
    $replay = acpTestCall($app, 'POST', $path, $body, $key, 'crear-una-vez');
    same($r->body, $replay->body, 'la repetición no cambia nada');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {ucp_checkouts}'), 'solo un carrito');
    $changed = $body;
    $changed['buyer']['first_name'] = 'Eva';
    same(422, acpTestCall($app, 'POST', $path, $changed, $key, 'crear-una-vez')->status, 'otra compra con la misma clave da conflicto ACP');
    $otherKey = ucpKey($app, ['acp']);
    same(404, acpTestCall($app, 'GET', $path . '/' . $created['id'], null, $otherKey)->status, 'otra plataforma no puede leer el comprador ni enumerar sesiones');
});

test('ACP: consultar, cambiar, escalar un pago no compatible y cancelar usa la misma caja', function (): void {
    [$app, $product] = ucpApp(['agentic.acp_enabled' => true, RedsysSettings::GOOGLEPAY => false]);
    $key = ucpKey($app, ['acp']);
    $r = acpTestCall($app, 'POST', '/checkout_sessions', acpTestBody($product), $key, 'crear');
    same(201, $r->status, 'crear: ' . $r->body);
    $created = ucpJson($r);
    $path = '/checkout_sessions/' . $created['id'];
    same('requires_escalation', $created['status'], 'sin pago directo se pide la caja');
    check(isset($created['continue_url']), 'hay enlace para el comprador');
    same(200, acpTestCall($app, 'GET', $path, null, $key)->status, 'consultar');
    $changed = acpTestCall($app, 'POST', $path, ['line_items' => [['id' => (string) $product, 'quantity' => 2]]], $key, 'cambiar');
    same(200, $changed->status, 'cambiar: ' . $changed->body);
    same(2, ucpJson($changed)['line_items'][0]['quantity'], 'dos unidades');
    $complete = acpTestCall($app, 'POST', $path . '/complete', ['payment_data' => ['provider' => 'stripe', 'token' => 'spt-ficticio-no-es-un-pago']], $key, 'completar');
    same(200, $complete->status, 'escalar sin fingir un cobro: ' . $complete->body);
    same('requires_escalation', ucpJson($complete)['status'], 'el token desconocido no crea pedido');
    same(0, count(ucpOrders($app)), 'no hay pedidos ni cargos');
    $canceled = acpTestCall($app, 'POST', $path . '/cancel', null, $key, 'cancelar');
    same(200, $canceled->status, 'cancelar');
    same('canceled', ucpJson($canceled)['status'], 'estado cancelado');
    same($canceled->body, acpTestCall($app, 'POST', $path . '/cancel', null, $key, 'cancelar')->body, 'repetir la misma cancelación recupera la respuesta original');
    same(405, acpTestCall($app, 'POST', $path . '/cancel', null, $key, 'cancelar-otra-vez')->status, 'una cancelación nueva sobre una compra cancelada da 405');
});
