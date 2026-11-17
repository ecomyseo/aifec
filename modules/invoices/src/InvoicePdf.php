<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Invoices;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Pdf\PdfException;
use Aifec\Pdf\PdfWriter;

/**
 * El PDF (A4) de una factura o de una rectificativa, hecho con el generador del núcleo (core/src/Pdf) a partir de los datos
 * congelados de la factura: nunca vuelve a mirar el pedido ni recalcula un importe.
 *
 * Lleva el logotipo si lo hay (JPEG o PNG), los datos del emisor y del receptor, el número y la fecha, la tabla de líneas
 * (descripción, unidades, precio sin impuestos, tipo e importe), los descuentos, la base y la cuota de cada tipo de impuesto, el
 * total, la forma de pago y el texto legal de los ajustes. Se pinta en el idioma del pedido. El resultado es el mismo cada vez
 * (la fecha de creación del documento es la de emisión).
 */
final class InvoicePdf
{
    /** Margen izquierdo y derecho, en milímetros. */
    private const MARGIN = 15.0;

    /**
     * @param array<string,mixed> $invoice la factura (Invoices::find), con data ya descodificado
     *
     * @return string los bytes del PDF
     */
    public static function render(App $app, array $invoice): string
    {
        $data = $invoice['data'];
        $lang = (string) $data['lang'];
        $i18n = $app->i18n();
        $tz = $app->site()->timezone();
        $currency = (string) $invoice['currency'];
        $isCredit = (string) $invoice['type'] === 'credit';
        $l = new InvoiceLabels($i18n, $lang);
        $money = static function (int $cents) use ($i18n, $currency, $lang): string {
            return $i18n->money($cents, $currency, $lang);
        };
        $date = static function (?string $sql) use ($i18n, $tz, $lang): string {
            $time = Clock::parse($sql);

            return $time > 0 ? $i18n->date($time, 'medium', $tz, $lang) : '';
        };

        $pdf = new PdfWriter('P', 'A4');
        $pdf->setMargins(self::MARGIN, 15.0, self::MARGIN, 22.0);
        $pdf->setCreationDate(max(0, Clock::parse((string) $invoice['issued_at'])));
        $pdf->setTitle(($isCredit ? $l->t('Credit note') : $l->t('Invoice')) . ' ' . (string) $invoice['full_number']);
        $pdf->setAuthor((string) $data['issuer']['name']);
        $pdf->onFooter(static function (PdfWriter $p) use ($l, $invoice): void {
            $p->setY(-14.0);
            $p->setFont('Helvetica', '', 8.0);
            $p->setTextColor(110, 110, 110);
            $p->cell(0, 5.0, (string) $invoice['full_number'] . ' · ' . $l->t('Page {page} of {pages}', ['page' => $p->pageNo(), 'pages' => '{nb}']), 'C');
        });
        $pdf->addPage();
        $width = $pdf->contentWidth();

        // ---------------------------------------------------------------- cabecera: logotipo y título
        $top = $pdf->getY();
        $drewLogo = self::logo($app, $pdf, $top);
        $pdf->setXY(self::MARGIN, $top);
        if (!$drewLogo) {
            $pdf->setFont('Helvetica', 'B', 14.0);
            $pdf->setTextColor(30, 30, 30);
            $pdf->cell($width / 2, 8.0, (string) $data['issuer']['name'], 'L', false, false, 0);
        }
        $pdf->setXY(self::MARGIN + $width / 2, $top);
        $pdf->setFont('Helvetica', 'B', 18.0);
        $pdf->setTextColor(30, 30, 30);
        $pdf->cell($width / 2, 9.0, $isCredit ? $l->t('Credit note') : $l->t('Invoice'), 'R', false, false, 2);
        $pdf->setX(self::MARGIN + $width / 2);
        $pdf->setFont('Helvetica', 'B', 11.0);
        $pdf->cell($width / 2, 6.0, $l->t('No. {number}', ['number' => (string) $invoice['full_number']]), 'R', false, false, 2);
        $pdf->setX(self::MARGIN + $width / 2);
        $pdf->setFont('Helvetica', '', 10.0);
        $pdf->cell($width / 2, 5.0, $l->t('Date: {date}', ['date' => $date((string) $invoice['issued_at'])]), 'R', false, false, 2);
        $pdf->setX(self::MARGIN + $width / 2);
        $pdf->cell($width / 2, 5.0, $l->t('Order {number}', ['number' => (string) $data['order']['number']]), 'R', false, false, 2);
        if ($isCredit && is_array($data['original'])) {
            $pdf->setX(self::MARGIN + $width / 2);
            $pdf->cell($width / 2, 5.0, $l->t('Corrects invoice {number} of {date}', ['number' => (string) $data['original']['full_number'], 'date' => $date((string) $data['original']['issued_at'])]), 'R', false, false, 2);
        }
        $pdf->setY(max($pdf->getY(), $top + 16.0) + 6.0);

        // ---------------------------------------------------------------- emisor y receptor
        $blockTop = $pdf->getY();
        $issuer = [];
        $issuer[] = (string) $data['issuer']['name'];
        foreach ($data['issuer']['address'] as $line) {
            $issuer[] = (string) $line;
        }
        if ((string) $data['issuer']['vat_id'] !== '') {
            $issuer[] = $l->t('Tax ID') . ': ' . (string) $data['issuer']['vat_id'];
        }
        foreach (['email', 'phone'] as $key) {
            if ((string) $data['issuer'][$key] !== '') {
                $issuer[] = (string) $data['issuer'][$key];
            }
        }
        $receiver = [];
        foreach ($data['receiver']['lines'] as $line) {
            $receiver[] = (string) $line;
        }
        if ((string) $data['receiver']['vat_id'] !== '') {
            $receiver[] = $l->t('Tax ID') . ': ' . (string) $data['receiver']['vat_id'];
        }
        $half = $width / 2 - 4.0;
        $leftEnd = self::block($pdf, self::MARGIN, $blockTop, $half, $l->t('Issued by'), $issuer);
        $rightEnd = self::block($pdf, self::MARGIN + $width / 2 + 4.0, $blockTop, $half, $l->t('Billed to'), $receiver);
        $pdf->setXY(self::MARGIN, max($leftEnd, $rightEnd) + 6.0);

        // ---------------------------------------------------------------- líneas
        $rows = [];
        foreach ($data['lines'] as $line) {
            $description = (string) $line['name'];
            if ((string) $line['variant_label'] !== '') {
                $description .= "\n" . (string) $line['variant_label'];
            }
            if ((string) $line['sku'] !== '') {
                $description .= "\n" . $l->t('SKU') . ': ' . (string) $line['sku'];
            }
            $rows[] = [$description, (string) (int) $line['qty'], $money((int) $line['unit_net']), InvoiceData::rate($app, (int) $line['tax_rate'], $lang), $money((int) $line['net'])];
        }
        $pdf->setFont('Helvetica', '', 9.0);
        $pdf->setTextColor(30, 30, 30);
        $pdf->setDrawColor(190, 190, 190);
        $pdf->table([
            ['label' => $l->t('Description'), 'width' => 78.0, 'align' => 'L'],
            ['label' => $l->t('Qty'), 'width' => 14.0, 'align' => 'R'],
            ['label' => $l->t('Price excl. tax'), 'width' => 32.0, 'align' => 'R'],
            ['label' => $l->t('Tax'), 'width' => 18.0, 'align' => 'R'],
            ['label' => $l->t('Amount excl. tax'), 'width' => 38.0, 'align' => 'R'],
        ], $rows, ['lineHeight' => 5.0, 'headerFill' => [235, 235, 235]]);
        $pdf->ln(4.0);

        // ---------------------------------------------------------------- descuentos
        if ($data['discounts'] !== []) {
            $pdf->setFont('Helvetica', 'I', 9.0);
            $pdf->setTextColor(90, 90, 90);
            foreach ($data['discounts'] as $discount) {
                $pdf->cell($width - 38.0, 5.0, $l->t('Discount applied (already deducted above)') . ': ' . (string) $discount['name'], 'R', false, false, 0);
                $pdf->cell(38.0, 5.0, $money(-(int) $discount['amount']), 'R', false, false, 1);
            }
            $pdf->ln(2.0);
        }

        // ---------------------------------------------------------------- base e impuesto de cada tipo
        $taxRows = [];
        foreach ($data['taxes'] as $tax) {
            $name = (string) $tax['name'] !== '' ? (string) $tax['name'] : InvoiceData::rate($app, (int) $tax['rate'], $lang);
            $taxRows[] = [$name, $money((int) $tax['base']), $money((int) $tax['amount']), $money((int) $tax['base'] + (int) $tax['amount'])];
        }
        $pdf->setFont('Helvetica', '', 9.0);
        $pdf->setTextColor(30, 30, 30);
        $pdf->table([
            ['label' => $l->t('Tax breakdown'), 'width' => 72.0, 'align' => 'L'],
            ['label' => $l->t('Taxable base'), 'width' => 36.0, 'align' => 'R'],
            ['label' => $l->t('Tax amount'), 'width' => 36.0, 'align' => 'R'],
            ['label' => $l->t('Total'), 'width' => 36.0, 'align' => 'R'],
        ], $taxRows, ['lineHeight' => 5.0, 'headerFill' => [245, 245, 245]]);
        $pdf->ln(4.0);

        // ---------------------------------------------------------------- total
        $pdf->setFont('Helvetica', '', 10.0);
        $pdf->cell($width - 60.0, 6.0, $l->t('Taxable base'), 'R', false, false, 0);
        $pdf->cell(60.0, 6.0, $money((int) $data['totals']['net']), 'R', false, false, 1);
        $pdf->cell($width - 60.0, 6.0, $l->t('Tax amount'), 'R', false, false, 0);
        $pdf->cell(60.0, 6.0, $money((int) $data['totals']['tax']), 'R', false, false, 1);
        $pdf->setFont('Helvetica', 'B', 13.0);
        $pdf->cell($width - 60.0, 9.0, $l->t('TOTAL'), 'R', false, false, 0);
        $pdf->cell(60.0, 9.0, $money((int) $data['totals']['total']), 'R', false, false, 1);
        $pdf->ln(3.0);

        // ---------------------------------------------------------------- forma de pago, devolución y texto legal
        $pdf->setFont('Helvetica', '', 9.0);
        $pdf->setTextColor(60, 60, 60);
        if ((string) $data['payment_title'] !== '') {
            $pdf->multiCell($width, 5.0, $l->t('Payment method') . ': ' . (string) $data['payment_title']);
        }
        if ($isCredit) {
            if ((string) $data['reason'] !== '') {
                $pdf->multiCell($width, 5.0, $l->t('Reason') . ': ' . (string) $data['reason']);
            }
            foreach ($data['returned'] as $item) {
                $pdf->multiCell($width, 5.0, $l->t('Returned') . ': ' . (int) $item['qty'] . ' × ' . (string) $item['name']);
            }
        }
        if ((string) $data['legal_text'] !== '') {
            $pdf->ln(3.0);
            $pdf->setFont('Helvetica', '', 8.0);
            $pdf->setTextColor(110, 110, 110);
            $pdf->multiCell($width, 4.0, (string) $data['legal_text']);
        }

        return $pdf->output();
    }

    /**
     * El logotipo de la tienda arriba a la izquierda, si hay uno en la biblioteca de medios y es JPEG o PNG. Devuelve si se pintó.
     */
    private static function logo(App $app, PdfWriter $pdf, float $top): bool
    {
        try {
            $id = $app->settings()->int('store.logo');
            if ($id < 1 || !$app->has('media')) {
                return false;
            }
            $media = $app->service('media')->get($id);
            if ($media === null || !in_array((string) $media['mime'], ['image/png', 'image/jpeg'], true)) {
                return false;
            }
            $path = rtrim($app->site()->mediaDir($app->publicDir()), '/') . '/' . ltrim((string) $media['path'], '/');
            if (!is_file($path)) {
                return false;
            }
            $pdf->image($path, self::MARGIN, $top, 0.0, 14.0);

            return true;
        } catch (PdfException $e) {
            return false;
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'invoices.logo');

            return false;
        }
    }

    /**
     * Un bloque de datos (emisor o receptor): un título en gris y debajo las líneas. Devuelve la Y donde acaba.
     *
     * @param string[] $lines
     */
    private static function block(PdfWriter $pdf, float $x, float $y, float $w, string $title, array $lines): float
    {
        $pdf->setXY($x, $y);
        $pdf->setFont('Helvetica', 'B', 8.0);
        $pdf->setTextColor(120, 120, 120);
        $pdf->cell($w, 4.5, mb_strtoupper($title, 'UTF-8'), 'L', false, false, 2);
        $pdf->setFont('Helvetica', '', 10.0);
        $pdf->setTextColor(30, 30, 30);
        foreach ($lines as $i => $line) {
            $pdf->setX($x);
            if ($i === 0) {
                $pdf->setFont('Helvetica', 'B', 10.0);
            }
            foreach ($pdf->wrapText($line, $w - 2.0) as $part) {
                $pdf->setX($x);
                $pdf->cell($w, 5.0, $part, 'L', false, false, 2);
            }
            if ($i === 0) {
                $pdf->setFont('Helvetica', '', 10.0);
            }
        }

        return $pdf->getY();
    }
}
