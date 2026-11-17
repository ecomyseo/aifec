<?php
/**
 * AIFEC — pruebas de lo que las facturas ponen en el módulo de pedidos: la tarjeta «Facturas» de la ficha del pedido en el
 * panel (hueco admin.order.side) y el PDF adjunto al correo de confirmación (filtro order.mail_attachments).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la ficha del pedido enseña la tarjeta de facturas: sin factura ofrece emitirla y, emitida, vuelve a la ficha con el PDF', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $number = (string) $app->service('orders')->find($id)['number'];
    $web = adminClient($app);

    $page = $web->get('/admin/orders/' . $id);
    same(200, $page->status);
    contains('data-af-invoices', $page->body);
    contains('Este pedido todavía no tiene factura.', $page->body);
    contains('Emitir la factura', $page->body);
    same($number, inputValue((string) substr($page->body, (int) strpos($page->body, 'data-af-invoices')), 'order'));
    same('order', inputValue((string) substr($page->body, (int) strpos($page->body, 'data-af-invoices')), 'back'));

    $r = $web->post('/admin/invoices/issue', ['_token' => aifecToken($web), 'order' => $number, 'back' => 'order']);
    same(302, $r->status);
    check(substr((string) $r->headers['Location'], -strlen('/admin/orders/' . $id)) === '/admin/orders/' . $id, 'vuelve a la ficha del pedido: ' . (string) $r->headers['Location']);
    $after = $web->follow()->body;
    contains('Factura F2026-00001 emitida.', $after);
    $invoice = $app->service('invoices')->forOrder($id)[0];
    $card = (string) substr($after, (int) strpos($after, 'data-af-invoices'));
    contains('/admin/invoices/' . (int) $invoice['id'] . '/pdf', $card);
    contains('Factura F2026-00001</a>', $card);
    notContains('Emitir la factura', $after);
    notContains('Este pedido todavía no tiene factura.', $after);

    // Sin «back» se vuelve al listado, como desde el formulario del listado.
    $token = aifecToken($web);
    $r = $web->post('/admin/invoices/issue', ['_token' => $token, 'order' => $number]);
    check(substr((string) $r->headers['Location'], -strlen('/admin/invoices')) === '/admin/invoices', 'vuelve al listado: ' . (string) $r->headers['Location']);
    // Y un pedido que no existe no lleva a ninguna ficha aunque se pida.
    $r = $web->post('/admin/invoices/issue', ['_token' => $token, 'order' => 'NO-EXISTE', 'back' => 'order']);
    check(substr((string) $r->headers['Location'], -strlen('/admin/invoices')) === '/admin/invoices', 'sin pedido, al listado: ' . (string) $r->headers['Location']);
});

test('la tarjeta enseña la factura y su rectificativa con fecha e importe, y un pedido cancelado sin factura no ofrece emitirla', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $app->service('orders')->refund($id, 500, [], 'Parcial', false, false);
    $cancelled = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $app->service('orders')->cancel((int) $cancelled['id'], 'No lo quiere');
    $web = adminClient($app);

    $body = $web->get('/admin/orders/' . $id)->body;
    $card = (string) substr($body, (int) strpos($body, 'data-af-invoices'));
    $invoices = $app->service('invoices')->forOrder($id);
    same(2, count($invoices));
    contains('Factura F2026-00001</a>', $card);
    contains('Rectificativa R2026-00001</a>', $card);
    contains('/admin/invoices/' . (int) $invoices[0]['id'] . '/pdf', $card);
    contains('/admin/invoices/' . (int) $invoices[1]['id'] . '/pdf', $card);
    contains(money((int) $invoices[0]['total'], 'EUR'), html_entity_decode($card, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    contains('2026', $card, 'lleva la fecha de emisión');
    notContains('Emitir la factura', $card);

    $body = $web->get('/admin/orders/' . (int) $cancelled['id'])->body;
    contains('Este pedido todavía no tiene factura.', $body);
    notContains('Emitir la factura', $body);
});

test('quien solo puede ver facturas ve la tarjeta sin el botón, y quien no puede verlas no ve la tarjeta', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);

    $support = adminClient($app, 'support');
    $body = $support->get('/admin/orders/' . $id)->body;
    contains('data-af-invoices', $body);
    contains('Este pedido todavía no tiene factura.', $body);
    notContains('Emitir la factura', $body);

    $app->db()->insert('roles', ['code' => 'almacen', 'name' => 'Almacén', 'caps' => json_encode(['dashboard.view', 'orders.view']), 'system' => 0]);
    $warehouse = adminClient($app, 'almacen');
    $page = $warehouse->get('/admin/orders/' . $id);
    same(200, $page->status);
    notContains('data-af-invoices', $page->body);
    notContains('Este pedido todavía no tiene factura.', $page->body);
});

test('el correo de confirmación sale con la factura en PDF adjunta cuando el ajuste lo pide, y sin adjunto cuando no', function (): void {
    $app = invoicesApp(['settings' => ['invoices.attach' => true]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);

    $mails = aifecOrderMails($app, 'order_confirmed');
    same(1, count($mails));
    $envelope = json_decode((string) $mails[0]['data'], true);
    same(1, count($envelope['attachments']), 'un adjunto: la factura');
    same('application/pdf', $envelope['attachments'][0]['mime']);
    contains('F2026-00001', (string) $envelope['attachments'][0]['name']);
    same(1, count($app->service('invoices')->forOrder($id)), 'la factura del adjunto es la del pedido, no otra');
    // Los demás correos del pedido no llevan la factura.
    $app->service('orders')->setStatus($id, 'completed');
    foreach (aifecOrderMails($app) as $mail) {
        if ((string) $mail['template'] !== 'order_confirmed') {
            same([], json_decode((string) $mail['data'], true)['attachments'], 'sin adjuntos: ' . (string) $mail['template']);
        }
    }

    $off = invoicesApp(['settings' => ['invoices.attach' => false]]);
    $p = aifecProduct($off, ['stock' => 50]);
    invoicesOrder($off, [[$p, 0, 1]]);
    same([], json_decode((string) aifecOrderMails($off, 'order_confirmed')[0]['data'], true)['attachments']);
});
