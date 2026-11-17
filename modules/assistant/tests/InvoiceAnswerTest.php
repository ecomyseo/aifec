<?php
/**
 * AIFEC — «¿me dais factura?»: el asistente (y la API y el MCP, que leen store_info) dicen cómo llega la factura en esta tienda,
 * igual que la caja y el correo (InvoiceNotice, 14/11/2026). Sin IA también.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('store_info dice cómo llega la factura, y sin el módulo de facturas es por correo desde el ERP', function (): void {
    $app = assistantApp();
    assistantShop($app);
    $r = $app->service('tools')->call('store_info', ['topic' => 'all']);
    same('Las facturas las emitimos con nuestro programa de gestión (ERP) y te las enviamos por correo.', (string) ($r['invoices'] ?? ''));
    $p = $app->service('tools')->call('store_info', ['topic' => 'payment']);
    check(isset($p['invoices']), 'también al preguntar por el pago');
});

test('sin IA, «¿me dais factura?» y «cómo recibo la factura» se contestan con lo que hace la tienda', function (): void {
    $app = assistantApp();
    assistantShop($app);
    foreach (['¿Me dais factura?', 'como recibo la factura', 'do you send invoices?'] as $question) {
        $web = new TestClient($app);
        $r = assistantSay($web, $question);
        contains('ERP', (string) $r['text'], $question);
    }
    $web = new TestClient($app);
    $r = assistantSay($web, '¿Qué formas de pago aceptáis?');
    notContains('ERP', (string) $r['text'], 'preguntar por el pago no contesta la factura');
});
