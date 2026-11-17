<?php
/**
 * AIFEC — pruebas de lo que se le dice al cliente de su factura (InvoiceNotice), igual en todas las tiendas: si la tienda no
 * factura con AIFEC (módulo apagado o sin emisión automática), que la hace su ERP y la manda por correo; si factura con AIFEC,
 * que va adjunta a la confirmación (encendido de serie) y que de verdad va. En la caja, en la página del pedido, en el correo y
 * en lo que contesta el asistente (14/11/2026, decisión del usuario).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\InvoiceNotice;

require_once __DIR__ . '/helpers.php';

const INVOICE_ERP = 'Las facturas las emitimos con nuestro programa de gestión (ERP) y te las enviamos por correo.';
const INVOICE_ATTACHED = 'Te enviamos la factura por correo, adjunta a la confirmación del pedido.';

/** El pedido pagado y lo que vería el cliente: [correo de confirmación, página del pedido, la caja]. */
function invoiceNoticeSurfaces(App $app): array
{
    $p = aifecProduct($app, ['stock' => 50]);
    $web = new TestClient($app);
    $id = aifecOrderPaid($app, $web, [[$p, 0, 1]]);
    $mails = aifecOrderMails($app, 'order_confirmed');
    check($mails !== [], 'sale el correo de confirmación');
    $number = (string) $app->service('orders')->find($id)['number'];
    $page = (new TestClient($app))->get('/pedido/' . $number . '/' . \Aifec\Modules\Orders\Orders::viewToken($app, $number));
    same(200, $page->status, 'la página del pedido');
    $cart = new TestClient($app);
    aifecCartRun($cart, static function ($c) use ($p): void {
        $c->add($p, 0, 1);
    });
    $checkout = $cart->get('/finalizar-compra');

    return [(string) end($mails)['html'], $page->body, $checkout->body];
}

test('sin el módulo de facturas: la caja, la página del pedido y el correo dicen que la factura la manda el ERP por correo', function (): void {
    $app = aifecOrderApp();
    same(InvoiceNotice::ERP, InvoiceNotice::mode($app));
    [$mail, $page, $checkout] = invoiceNoticeSurfaces($app);
    contains(INVOICE_ERP, $mail, 'el correo de confirmación');
    contains(INVOICE_ERP, $page, 'la página del pedido');
    contains(INVOICE_ERP, $checkout, 'la caja');
});

test('con facturas automáticas: la factura va adjunta a la confirmación (de serie) y el correo lo dice', function (): void {
    $app = invoicesApp();
    same(InvoiceNotice::ATTACHED, InvoiceNotice::mode($app));
    [$mail, $page, $checkout] = invoiceNoticeSurfaces($app);
    contains('Tu factura va adjunta a este correo.', $mail);
    contains(INVOICE_ATTACHED, $page);
    contains(INVOICE_ATTACHED, $checkout);
    $row = $app->db()->row("SELECT * FROM {mail_messages} WHERE template = 'order_confirmed' ORDER BY id DESC");
    check($row !== null, 'hay correo');
    $attachments = $app->hooks()->apply('order.mail_attachments', [], $app->service('orders')->find((int) $app->db()->value('SELECT MAX(id) FROM {orders}')), 'order_confirmed');
    same(1, count($attachments), 'el PDF de la factura va en la confirmación');
    same('%PDF', substr((string) $attachments[0]['content'], 0, 4));
});

test('con el módulo encendido pero sin emisión automática, factura el ERP: el aviso es el del ERP', function (): void {
    $app = invoicesApp(['settings' => ['invoices.auto' => false]]);
    same(InvoiceNotice::ERP, InvoiceNotice::mode($app));
    same(INVOICE_ERP, InvoiceNotice::text($app));
    $app->settings()->set('invoices.auto', true);
    $app->settings()->set('invoices.attach', false);
    same(InvoiceNotice::ACCOUNT, InvoiceNotice::mode($app), 'sin adjunto, se descarga del pedido y de la cuenta');
});
