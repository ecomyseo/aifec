<?php
/**
 * AIFEC — un agente que pone en «company» el sitio de entrega (una oficina, una asociación) puede quitarla con «company: ""» y la
 * caja le dice qué hacer (14/11/2026: dos agentes de compra se quedaron sin poder seguir: «Escribe el NIF para la factura» y
 * ninguna forma de quitar la empresa).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('con una empresa sin NIF la revisión explica qué hacer, y «company: ""» la quita y deja seguir', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $sid = mcpSession($app);
    mcpTool($app, 'cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 1], $sid);
    mcpTool($app, 'checkout_set_customer', ['email' => 'teo@agente.test', 'first_name' => 'Teo', 'last_name' => 'Sánchez', 'phone' => '999100018',
        'company' => 'Asociación Ejemplo', 'line1' => 'Calle de la Escuela 2', 'postcode' => '30001', 'city' => 'Murcia', 'country' => 'ES'], $sid);
    $blocked = (string) json_encode(mcpTool($app, 'checkout_review', [], $sid)['structuredContent'], JSON_UNESCAPED_UNICODE);
    contains('segunda línea de la dirección', $blocked, 'dice qué hacer si es solo el sitio de entrega');

    $fixed = mcpTool($app, 'checkout_set_customer', ['company' => '', 'line2' => 'Asociación Ejemplo'], $sid);
    same(false, $fixed['isError'], json_encode($fixed));
    $options = mcpTool($app, 'checkout_shipping_options', [], $sid)['structuredContent']['options'];
    mcpTool($app, 'checkout_set_shipping', ['carrier_id' => $options[0]['id']], $sid);
    mcpTool($app, 'checkout_set_payment', ['method' => 'transfer'], $sid);
    $review = mcpTool($app, 'checkout_review', [], $sid)['structuredContent'];
    same(true, $review['ready'], 'sin la empresa, el pedido está listo: ' . json_encode($review, JSON_UNESCAPED_UNICODE));
});
