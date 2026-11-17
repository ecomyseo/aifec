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

/**
 * La exportación para la gestoría: una fila por factura y por tipo de impuesto (base, cuota y total de ese tipo), con los
 * importes de las rectificativas en negativo. Separador «;», decimales con coma y sin separador de millares, fechas «2026-11-12»
 * y una marca de orden de bytes UTF-8 para que la hoja de cálculo respete las tildes.
 *
 * Una celda de texto que empieza por «=», «+», «-» o «@» se escribe con un apóstrofo delante (una hoja de cálculo no ejecuta
 * fórmulas); los importes y los números, que sí pueden empezar por «-», no se tocan.
 */
final class InvoiceExport
{
    /**
     * @param array<int,array<string,mixed>> $rows filas de la lista del panel: full_number, type, issued_at, order_number, currency, data
     */
    public static function csv(App $app, array $rows): string
    {
        $tz = new \DateTimeZone($app->site()->timezone());
        $header = [
            t('Number'), t('Type'), t('Date'), t('Order'), t('Customer'), t('Tax ID'), t('Country'), t('Tax'), t('Rate'),
            t('Taxable base'), t('Tax amount'), t('Total'), t('Currency'),
        ];
        $out = fopen('php://memory', 'w+b');
        if ($out === false) {
            throw new \RuntimeException('Cannot open memory stream');
        }
        self::put($out, $header);
        foreach ($rows as $row) {
            $data = isset($row['data']) && is_string($row['data']) ? json_decode($row['data'], true) : (isset($row['data']) && is_array($row['data']) ? $row['data'] : []);
            $data = is_array($data) ? $data : [];
            $receiver = isset($data['receiver']) && is_array($data['receiver']) ? $data['receiver'] : [];
            $time = Clock::parse((string) $row['issued_at']);
            $date = $time > 0 ? (new \DateTimeImmutable('@' . $time))->setTimezone($tz)->format('Y-m-d') : '';
            $taxes = isset($data['taxes']) && is_array($data['taxes']) && $data['taxes'] !== [] ? $data['taxes'] : [['name' => '', 'rate' => 0, 'base' => (int) $row['net'], 'amount' => (int) $row['tax']]];
            foreach ($taxes as $tax) {
                self::put($out, [
                    (string) $row['full_number'], (string) $row['type'] === 'credit' ? t('Credit note') : t('Invoice'), $date, (string) $row['order_number'],
                    isset($receiver['name']) ? (string) $receiver['name'] : '', isset($receiver['vat_id']) ? (string) $receiver['vat_id'] : '',
                    isset($receiver['country']) ? (string) $receiver['country'] : '', (string) $tax['name'], self::decimal((int) $tax['rate']),
                    self::decimal((int) $tax['base']), self::decimal((int) $tax['amount']), self::decimal((int) $tax['base'] + (int) $tax['amount']),
                    (string) $row['currency'],
                ]);
            }
        }
        rewind($out);
        $text = (string) stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF" . $text;
    }

    /** Céntimos (o centésimas) → «1234,56» (con signo si es negativo). */
    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . intdiv($abs, 100) . ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param resource           $out
     * @param array<int,string>  $cells
     */
    private static function put($out, array $cells): void
    {
        $safe = [];
        foreach ($cells as $cell) {
            $cell = (string) $cell;
            $number = preg_match('/^-?\d+(?:,\d+)?$/', $cell) === 1;
            $safe[] = !$number && $cell !== '' && strpos('=+-@', $cell[0]) !== false ? "'" . $cell : $cell;
        }
        fputcsv($out, $safe, ';', '"', '');
    }
}
