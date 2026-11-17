<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Commerce\Totals;
use Aifec\Kernel\App;

/**
 * El carrito como lo necesita el navegador: líneas e importes ya formateados con money() además de los céntimos.
 *
 * Los importes que se enseñan siguen el ajuste «enseñar los precios con impuestos» (display_with_tax): con impuestos se
 * pinta el bruto de cada línea y sin ellos la base. Los céntimos «tal como se escriben» (unit_price) no se tocan.
 */
final class CartView
{
    /**
     * Una línea del resumen para el minicarrito o la respuesta de una acción.
     *
     * @param array<string,mixed> $line    línea de Cart::summary()['lines']
     * @param array<string,mixed> $summary el resumen al que pertenece
     *
     * @return array<string,mixed>
     */
    public static function line(App $app, array $line, array $summary): array
    {
        $currency = (string) $summary['currency'];
        $withTax = !empty($summary['display_with_tax']);
        $inclusive = !empty($summary['totals']['prices_include_tax']);
        $exempt = !empty($summary['totals']['tax_exempt']);
        $unit = Totals::tax((int) $line['unit_price'], (int) $line['tax_rate'], $inclusive, $exempt);
        $unitShown = $withTax ? $unit['gross'] : $unit['net'];
        $totalShown = $withTax ? (int) $line['gross'] : (int) $line['net'];

        return [
            'key' => $line['key'],
            'product_id' => $line['product_id'],
            'variant_id' => $line['variant_id'],
            'name' => $line['name'],
            'variant_label' => $line['variant_label'],
            'image' => $line['image'],
            'url' => $line['url'],
            'qty' => $line['qty'],
            'max_qty' => $line['max_qty'],
            'warnings' => $line['warnings'],
            'unit_price' => (int) $line['unit_price'],
            'unit_price_shown' => $unitShown,
            'unit_price_formatted' => $app->i18n()->money($unitShown, $currency),
            'total' => (int) $line['total'],
            'total_shown' => $totalShown,
            'total_formatted' => $app->i18n()->money($totalShown, $currency),
        ];
    }

    /**
     * El resumen para el minicarrito (GET summary.json): líneas, cantidad y totales.
     *
     * @param array<string,mixed> $summary
     *
     * @return array<string,mixed>
     */
    public static function mini(App $app, array $summary): array
    {
        $currency = (string) $summary['currency'];
        $withTax = !empty($summary['display_with_tax']);
        $inclusive = !empty($summary['totals']['prices_include_tax']);
        $exempt = !empty($summary['totals']['tax_exempt']);
        $lines = [];
        $itemsShown = 0;
        foreach ($summary['lines'] as $line) {
            $lines[] = self::line($app, $line, $summary);
            if (!empty($line['available'])) {
                $parts = Totals::tax((int) $line['subtotal'], (int) $line['tax_rate'], $inclusive, $exempt);
                $itemsShown += $withTax ? $parts['gross'] : $parts['net'];
            }
        }
        $totals = $summary['totals'];
        // El mismo total que enseña la página del carrito (sin envío si aún no se calcula y no se estima).
        $view = \Aifec\Modules\Orders\Front\TotalsView::forCartPage($app, $summary);

        return [
            'count' => (int) $summary['count'],
            'lines' => $lines,
            'totals' => [
                'items_total' => (int) $totals['items_total'],
                'items_total_formatted' => $app->i18n()->money($itemsShown, $currency),
                'total' => (int) $view['total'],
                'total_formatted' => (string) $view['total_formatted'],
                'shipping_pending' => (bool) $view['shipping_pending'],
            ],
            'currency' => $currency,
            'display_with_tax' => $withTax,
            'problems' => $summary['problems'],
        ];
    }
}
