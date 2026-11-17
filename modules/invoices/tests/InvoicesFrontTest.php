<?php
/**
 * AIFEC — pruebas de las facturas en la tienda: la descarga con la ficha del pedido, los enlaces de la página del pedido y de la
 * zona de cliente, y el adjunto del correo de confirmación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Invoices\Front\InvoiceLinks;

require_once __DIR__ . '/helpers.php';

test('la descarga con la ficha buena sirve el PDF en línea, sin caché y sin indexar', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $invoice = $app->service('invoices')->forOrder($id)[0];
    $web = new TestClient($app);

    $r = $web->get(invoicesDownload($app, $invoice));

    same(200, $r->status);
    same('application/pdf', $r->headers['Content-Type']);
    contains('inline', $r->headers['Content-Disposition']);
    contains('F2026-00001.pdf', $r->headers['Content-Disposition']);
    contains('no-store', $r->headers['Cache-Control']);
    contains('noindex', $r->headers['X-Robots-Tag']);
    check($r->file !== null && is_file($r->file), 'se sirve un fichero de verdad');
    same('%PDF', substr((string) file_get_contents($r->file), 0, 4));
    same('', $r->body, 'el cuerpo va por el fichero');
});

test('con una ficha mala, otra ficha, un número inventado o la ficha de otro pedido, la descarga da 404', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $a = invoicesOrder($app, [[$p, 0, 1]]);
    $b = invoicesOrder($app, [[$p, 0, 1]]);
    $invoiceA = $app->service('invoices')->forOrder($a)[0];
    $invoiceB = $app->service('invoices')->forOrder($b)[0];
    $web = new TestClient($app);

    same(404, $web->get(invoicesDownload($app, $invoiceA, 'ficha-mala'))->status, 'ficha mala');
    same(404, $web->get('/factura/' . $invoiceA['full_number'] . '/' . str_repeat('a', 48))->status);
    same(404, $web->get(invoicesDownload($app, $invoiceA, $app->service('orders')->viewTokenOf((string) $app->service('orders')->find($b)['number'])))->status, 'la ficha de otro pedido');
    same(404, $web->get('/factura/F2026-99999/' . $app->service('orders')->viewTokenOf((string) $app->service('orders')->find($a)['number']))->status, 'número inventado');
    same(200, $web->get(invoicesDownload($app, $invoiceB))->status, 'con la suya sí');
});

test('la página del pedido enseña el enlace de descarga de la factura y de sus rectificativas', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $order = $app->service('orders')->find($id);
    $token = $app->service('orders')->viewTokenOf((string) $order['number']);
    $web = new TestClient($app);

    $page = $web->get('/pedido/' . $order['number'] . '/' . $token);
    same(200, $page->status);
    contains('/factura/F2026-00001/' . $token, $page->body);
    contains('Factura F2026-00001', $page->body);

    $app->service('orders')->refund($id, 500, [], 'Devolución parcial', false, false);
    $again = (new TestClient($app))->get('/pedido/' . $order['number'] . '/' . $token);
    contains('/factura/R2026-00001/' . $token, $again->body);
    contains('Rectificativa R2026-00001', $again->body);

    $link = $app->service('invoices')->url($app->service('invoices')->forOrder($id)[1]);
    same('/factura/R2026-00001/' . $token, $link);
    same('https://tienda.test/factura/R2026-00001/' . $token, $app->service('invoices')->url($app->service('invoices')->forOrder($id)[1], true));
});

test('la zona de cliente enseña las facturas del cliente y no las de otro', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['stock' => 50]);
    $mine = invoicesOrder($app, [[$p, 0, 1]], ['email' => 'ana@cliente.test']);
    invoicesOrder($app, [[$p, 0, 1]], ['email' => 'luis@cliente.test']);
    $customerId = (int) $app->service('orders')->find($mine)['customer_id'];
    check($customerId > 0, 'el pedido tiene cliente');

    $html = InvoiceLinks::forCustomer($app, ['id' => $customerId]);

    contains('Factura F2026-00001', $html);
    notContains('F2026-00002', $html);
    same('', InvoiceLinks::forCustomer($app, ['id' => 99999]), 'sin facturas no pinta nada');
    contains('Factura F2026-00001', $app->hooks()->render('account.dashboard', ['id' => $customerId]), 'y está enganchado al hueco de la zona de cliente');
});

test('con invoices.attach el correo de confirmación lleva el PDF de la factura; sin él, o en otro correo, no lleva nada', function (): void {
    $app = invoicesApp(['settings' => ['invoices.attach' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $order = $app->service('orders')->find($id);

    same([], $app->hooks()->apply('order.mail_attachments', [], $order, 'order_confirmed'), 'apagado: nada');
    $app->settings()->set('invoices.attach', true);
    $app->settings()->set('invoices.auto', false);
    same([], $app->hooks()->apply('order.mail_attachments', [], $order, 'order_confirmed'), 'sin emisión automática factura el ERP: AIFEC no emite ni adjunta');
    $app->settings()->set('invoices.auto', true);

    same([], $app->hooks()->apply('order.mail_attachments', [], $order, 'order_shipped'), 'otro correo: nada');
    $attachments = $app->hooks()->apply('order.mail_attachments', [], $order, 'order_confirmed');
    same(1, count($attachments));
    same('F2026-00001.pdf', $attachments[0]['name']);
    same('application/pdf', $attachments[0]['mime']);
    same('%PDF', substr($attachments[0]['content'], 0, 4));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {invoices}'), 'la factura se emitió al pedir el adjunto, una sola vez');

    $prior = [['name' => 'otro.txt', 'content' => 'x', 'mime' => 'text/plain']];
    same(2, count($app->hooks()->apply('order.mail_attachments', $prior, $order, 'order_confirmed')), 'respeta los adjuntos que ya había');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {invoices}'));
});
