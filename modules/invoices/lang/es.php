<?php
/**
 * AIFEC — catálogo castellano del módulo de facturas.
 * La clave es la cadena inglesa tal como está en el código; no se toca. El valor es la traducción.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    // ------------------------------------------------------------------ módulo, permisos, menú y ajustes
    'PDF invoices and credit notes with gapless numbering' => 'Facturas y facturas rectificativas en PDF con numeración correlativa sin huecos',
    'See and download the invoices, and export them' => 'Ver y descargar las facturas, y exportarlas',
    'Issue invoices by hand and change the invoice settings' => 'Emitir facturas a mano y cambiar los ajustes de facturación',
    'Invoices' => 'Facturas',
    'Invoice settings' => 'Ajustes de facturas',
    'Automatic invoices, numbering, legal text and the email attachment' => 'Facturas automáticas, numeración, texto legal y adjunto del correo',
    'The data of the issuer (legal name, tax ID, address) are the ones in Settings, Identity. They are copied into each invoice when it is issued: changing them later does not change the invoices that already exist.' => 'Los datos del emisor (razón social, NIF, dirección) son los de Ajustes, Identidad. Se copian en cada factura al emitirla: cambiarlos después no cambia las facturas que ya existen.',
    'Numbers: the counter of each series is consecutive and never skips a number. In the format, {series} is the series, {year} the year and {number:5} the counter with zeros up to 5 digits. Changing the format does not change the numbers that already exist.' => 'Números: el contador de cada serie es correlativo y nunca se salta un número. En el formato, {series} es la serie, {year} el año y {number:5} el contador con ceros hasta 5 cifras. Cambiar el formato no cambia los números que ya existen.',
    'Orders paid in test mode always use the TEST series.' => 'Los pedidos pagados en modo de pruebas usan siempre la serie TEST.',
    'Issue the invoice when an order is paid, and the credit note when money is refunded' => 'Emitir la factura cuando se paga un pedido, y la rectificativa cuando se devuelve dinero',
    'Attach the invoice to the order confirmation email' => 'Adjuntar la factura al correo de confirmación del pedido',
    'Numbering' => 'Numeración',
    'Invoice series' => 'Serie de las facturas',
    'Letters and digits only. Each series has its own counter.' => 'Solo letras y cifras. Cada serie tiene su propio contador.',
    'Invoice number format' => 'Formato del número de factura',
    'For example {series}{year}-{number:5} gives F2026-00001.' => 'Por ejemplo, {series}{year}-{number:5} da F2026-00001.',
    'Credit note series' => 'Serie de las rectificativas',
    'Credit note number format' => 'Formato del número de rectificativa',
    'Legal text' => 'Texto legal',
    'Text at the bottom of the invoice' => 'Texto al pie de la factura',
    'For example the commercial register data or the tax regime.' => 'Por ejemplo, los datos del registro mercantil o el régimen fiscal.',

    // ------------------------------------------------------------------ el panel
    'Invoices are issued by themselves when an order is paid and credit notes when money is refunded (you can turn that off in the settings).' => 'Las facturas se emiten solas cuando se paga un pedido y las rectificativas cuando se devuelve dinero (puedes apagarlo en los ajustes).',
    'The numbers are consecutive per series and are never reused. Orders paid in test mode use the TEST series and do not take a real number.' => 'Los números son correlativos en cada serie y nunca se reutilizan. Los pedidos pagados en modo de pruebas usan la serie TEST y no gastan un número real.',
    'The CSV has one row per invoice and tax type, with the amounts of credit notes in negative, ready for your accountant.' => 'El CSV lleva una fila por factura y tipo de impuesto, con los importes de las rectificativas en negativo, listo para tu gestoría.',
    'Export CSV' => 'Exportar CSV',
    'No invoices yet' => 'Todavía no hay facturas',
    'Invoices show up here when an order is paid, or when you issue one by hand.' => 'Las facturas salen aquí cuando se paga un pedido o cuando emites una a mano.',
    'Issue the invoice of an order' => 'Emitir la factura de un pedido',
    'Order number' => 'Número de pedido',
    'Issue' => 'Emitir',
    'There is no order with that number.' => 'No hay ningún pedido con ese número.',
    'An invoice cannot be issued for a cancelled order.' => 'No se puede emitir una factura de un pedido cancelado.',
    'Invoice {number} issued.' => 'Factura {number} emitida.',
    'The order already had invoice {number}.' => 'El pedido ya tenía la factura {number}.',
    'Series' => 'Serie',
    'Number' => 'Número',
    'Customer' => 'Cliente',
    'Date' => 'Fecha',
    'Order' => 'Pedido',
    'Type' => 'Tipo',
    'Total' => 'Total',
    'From' => 'Desde',
    'To' => 'Hasta',
    'Test' => 'Pruebas',
    'Country' => 'País',
    'Rate' => 'Tipo impositivo',

    // ------------------------------------------------------------------ la factura (PDF), en el idioma del pedido
    'Invoice' => 'Factura',
    'Credit note' => 'Factura rectificativa',
    'Invoice {number}' => 'Factura {number}',
    'Credit note {number}' => 'Rectificativa {number}',
    'This order has no invoice yet.' => 'Este pedido todavía no tiene factura.',
    'Issue the invoice' => 'Emitir la factura',
    'No. {number}' => 'N.º {number}',
    'Date: {date}' => 'Fecha: {date}',
    'Order {number}' => 'Pedido {number}',
    'Corrects invoice {number} of {date}' => 'Rectifica la factura {number} del {date}',
    'Refund for invoice {number}' => 'Devolución de la factura {number}',
    'Issued by' => 'Emitida por',
    'Billed to' => 'Facturada a',
    'Description' => 'Descripción',
    'SKU' => 'Referencia',
    'Qty' => 'Uds.',
    'Price excl. tax' => 'Precio sin impuestos',
    'Tax' => 'Impuesto',
    'Amount excl. tax' => 'Importe sin impuestos',
    'Discount applied (already deducted above)' => 'Descuento aplicado (ya descontado arriba)',
    'Tax breakdown' => 'Desglose de impuestos',
    'Taxable base' => 'Base imponible',
    'Tax amount' => 'Cuota',
    'TOTAL' => 'TOTAL',
    'Payment method' => 'Forma de pago',
    'Reason' => 'Motivo',
    'Returned' => 'Devuelto',
];
