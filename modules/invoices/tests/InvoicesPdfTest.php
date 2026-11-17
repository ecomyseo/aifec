<?php
/**
 * AIFEC — pruebas del PDF de las facturas: se genera la primera vez y se guarda en la carpeta privada, su texto lleva lo que tiene
 * que llevar (número, NIF del emisor, total con coma decimal, tildes y euro) y las facturas largas siguen en otra página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el PDF existe, empieza por %PDF, acaba bien y se guarda en la carpeta privada del sitio sin rehacerse', function (): void {
    // Sin el adjunto (que lo pide al confirmar el pago): aquí se mira que el PDF se hace al pedirlo, no al emitir.
    $app = invoicesApp(['settings' => ['invoices.attach' => false]]);
    $p = aifecProduct($app, ['stock' => 50]);
    $invoices = $app->service('invoices');
    $invoice = $invoices->forOrder(invoicesOrder($app, [[$p, 0, 1]]))[0];
    same('', $invoice['pdf_path'], 'al emitir no se hace el PDF todavía');

    $path = $invoices->pdf($invoice['id']);

    check(is_file($path), 'el fichero existe');
    $bytes = (string) file_get_contents($path);
    same('%PDF-1.4', substr($bytes, 0, 8));
    same('%%EOF', substr($bytes, -5));
    pdfTestCheckXref($bytes);
    $stored = $invoices->find($invoice['id'])['pdf_path'];
    same('invoices/' . $invoice['id'] . '-F2026-00001.pdf', $stored);
    same(str_replace('\\', '/', $app->site()->path('private/' . $stored)), str_replace('\\', '/', $path));
    check(is_file($app->site()->path('private/invoices/.htaccess')), 'la carpeta se cierra al servidor web');

    $mtime = filemtime($path);
    same($path, $invoices->pdf($invoice['id']), 'la segunda vez devuelve el mismo fichero');
    same($mtime, filemtime($path), 'y no lo rehace');

    unlink($path);
    same($path, $invoices->pdf($invoice['id']), 'si el fichero se pierde, se vuelve a hacer en el mismo sitio');
    same($bytes, (string) file_get_contents($path), 'idéntico: sale de los datos congelados');

    throws(function () use ($invoices): void {
        $invoices->pdf(99999);
    }, \InvalidArgumentException::class);
});

test('el texto del PDF lleva el número, la fecha, el NIF del emisor, el receptor, las líneas, los impuestos y el total con coma decimal', function (): void {
    $app = invoicesApp();
    $p = aifecProduct($app, ['name' => 'Camiseta ñandú café', 'price' => 2000, 'sku' => 'CAM-1', 'stock' => 50]);
    $id = invoicesOrder($app, [[$p, 0, 1]]);
    $invoice = $app->service('invoices')->forOrder($id)[0];
    $order = $app->service('orders')->find($id);
    same(2495, $order['total'], '20,00 € de la camiseta y 4,95 € de envío');

    $text = invoicesPdfText((string) file_get_contents($app->service('invoices')->pdf($invoice['id'])));

    contains('Factura', $text);
    contains('F2026-00001', $text);
    contains('12 nov', $text);
    contains('2026', $text);
    contains('Pedido ' . $order['number'], $text);
    contains('Tienda de Pruebas S.L.', $text);
    contains('NIF: B12345678', $text);
    contains('Calle Mayor 1', $text);
    contains('35001 Las Palmas de Gran Canaria', $text);
    contains('Ana Ruiz', $text);
    contains('28001 Madrid', $text);
    contains('Camiseta ñandú café', $text);
    contains('CAM-1', $text);
    contains('Base imponible', $text);
    contains('IVA 21', $text);
    contains('24,95 €', $text, 'el total con coma decimal y el euro');
    contains('16,53 €', $text, 'la base de la camiseta (20,00 € con el IVA dentro)');
    contains('4,33 €', $text, 'la cuota de IVA');
    contains('Pasarela de pruebas', $text, 'la forma de pago');
    contains('Página 1 de 1', $text);
    notContains('{nb}', $text);
});

test('el texto legal de los ajustes sale al pie y la factura de un pedido en otro idioma se pinta en ese idioma', function (): void {
    $app = invoicesApp(['settings' => ['invoices.legal_text' => 'Inscrita en el Registro Mercantil de Las Palmas, tomo 1.', 'invoices.auto' => false]]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 50]);
    $invoices = $app->service('invoices');

    $es = $invoices->issue(invoicesOrder($app, [[$p, 0, 1]]));
    contains('Inscrita en el Registro Mercantil de Las Palmas, tomo 1.', invoicesPdfText((string) file_get_contents($invoices->pdf($es['id']))));
    contains('Base imponible', invoicesPdfText((string) file_get_contents($invoices->pdf($es['id']))));

    // Un pedido en inglés: las etiquetas fijas salen en inglés aunque el panel esté en castellano.
    $orderId = invoicesOrder($app, [[$p, 0, 1]]);
    $app->db()->update('orders', ['lang' => 'en'], ['id' => $orderId]);
    $en = $invoices->issue($orderId);
    same('en', $en['data']['lang']);
    $text = invoicesPdfText((string) file_get_contents($invoices->pdf($en['id'])));
    contains('Invoice', $text);
    contains('Taxable base', $text);
    contains('Payment method', $text);
    notContains('Base imponible', $text);
    notContains('Forma de pago', $text);
});

test('una factura con muchas líneas sigue en otra página, con la cabecera de la tabla repetida y el pie numerado', function (): void {
    $app = invoicesApp();
    $lines = [];
    for ($i = 1; $i <= 38; ++$i) {
        $lines[] = [aifecProduct($app, ['name' => 'Artículo número ' . $i, 'price' => 1000 + $i, 'stock' => 20]), 0, 1];
    }
    $id = invoicesOrder($app, $lines);
    $invoice = $app->service('invoices')->forOrder($id)[0];

    $bytes = (string) file_get_contents($app->service('invoices')->pdf($invoice['id']));
    $text = invoicesPdfText($bytes);

    contains('/Count 2', $bytes);
    contains('Página 1 de 2', $text);
    contains('Página 2 de 2', $text);
    contains('Artículo número 1', $text);
    contains('Artículo número 38', $text);
    same(2, substr_count($text, 'Precio sin'), 'la cabecera de la tabla se repite en la segunda página');
    contains(invoicesMoneyOf($app, $id), $text, 'el total del pedido sale al final');
});

/** El total de un pedido tal como lo escribe el PDF en castellano. */
function invoicesMoneyOf(\Aifec\Kernel\App $app, int $orderId): string
{
    $total = (int) $app->db()->value('SELECT total FROM {orders} WHERE id = ?', [$orderId]);

    return str_replace("\xC2\xA0", ' ', $app->i18n()->money($total, 'EUR', 'es'));
}
