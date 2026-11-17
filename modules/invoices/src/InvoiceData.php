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

use Aifec\Commerce\Money;
use Aifec\Geo\Address;
use Aifec\Kernel\App;

/**
 * Lo que se congela en una factura: emisor, receptor, líneas, descuentos, impuestos por tipo y totales.
 *
 * Los importes salen de los datos congelados del pedido (line_net, line_tax, taxes, total_net, total_tax, total): aquí no se
 * recalcula ningún impuesto. Lo único que se calcula es el precio unitario sin impuestos que se enseña (línea / unidades, solo
 * para leer) y, en una rectificativa, el reparto del importe devuelto entre los tipos de impuesto del pedido en proporción a
 * lo que cada tipo pesaba en él (Money::allocate: no se pierde ni un céntimo).
 *
 * Los textos que se generan aquí (el concepto de una rectificativa) se escriben ya en el idioma del pedido y se congelan; las
 * etiquetas fijas del PDF se traducen al pintarlo.
 */
final class InvoiceData
{
    /**
     * La factura de un pedido.
     *
     * @param array<string,mixed> $order el pedido completo (Orders::find)
     *
     * @return array<string,mixed>
     */
    public static function invoice(App $app, array $order): array
    {
        $lines = [];
        $discounts = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            $kind = (string) $item['kind'];
            $extra = isset($item['data']) && is_array($item['data']) ? $item['data'] : [];
            if ($kind === 'discount' && !empty($extra['informational'])) {
                // Ya repartido en las líneas de producto: se enseña, no suma.
                $discounts[] = ['name' => (string) $item['name'], 'amount' => -(int) $item['line_total']];
                continue;
            }
            $qty = max(1, (int) $item['qty']);
            $lines[] = [
                'kind' => $kind, 'name' => (string) $item['name'], 'variant_label' => (string) ($item['variant_label'] !== null ? $item['variant_label'] : ''),
                'sku' => (string) ($item['sku'] !== null ? $item['sku'] : ''), 'qty' => $qty, 'unit_net' => Money::divRound((int) $item['line_net'], $qty),
                'tax_rate' => (int) $item['tax_rate'], 'tax_name' => (string) ($item['tax_name'] !== null ? $item['tax_name'] : ''),
                'net' => (int) $item['line_net'], 'tax' => (int) $item['line_tax'], 'total' => (int) $item['line_total'],
            ];
        }
        $taxes = [];
        foreach (isset($order['taxes']) && is_array($order['taxes']) ? $order['taxes'] : [] as $tax) {
            $taxes[] = ['name' => (string) $tax['name'], 'rate' => (int) $tax['rate'], 'base' => (int) $tax['base'], 'amount' => (int) $tax['amount']];
        }

        return self::common($app, $order) + [
            'lines' => $lines,
            'discounts' => $discounts,
            'taxes' => $taxes,
            'totals' => [
                'net' => (int) $order['total_net'], 'tax' => (int) $order['total_tax'], 'total' => (int) $order['total'],
                'items_total' => (int) $order['items_total'], 'discount_total' => (int) $order['discount_total'],
                'shipping_total' => (int) $order['shipping_total'], 'fee_total' => (int) $order['fee_total'],
            ],
            'original' => null,
            'reason' => '',
            'returned' => [],
        ];
    }

    /**
     * La rectificativa de una devolución: una línea por tipo de impuesto del pedido con el importe devuelto en negativo.
     *
     * @param array<string,mixed> $order    el pedido completo
     * @param array<string,mixed> $refund   la devolución (una de order['refunds'])
     * @param array<string,mixed> $original la factura que rectifica (Invoices::find)
     *
     * @return array<string,mixed>
     */
    public static function credit(App $app, array $order, array $refund, array $original): array
    {
        $lang = self::lang($order);
        $i18n = $app->i18n();
        $amount = (int) $refund['amount'];
        $groups = [];
        foreach (isset($order['taxes']) && is_array($order['taxes']) ? $order['taxes'] : [] as $tax) {
            $groups[] = ['name' => (string) $tax['name'], 'rate' => (int) $tax['rate'], 'weight' => max(0, (int) $tax['base'] + (int) $tax['amount'])];
        }
        $weights = [];
        foreach ($groups as $i => $group) {
            $weights[$i] = $group['weight'];
        }
        if ($groups === [] || array_sum($weights) < 1) {
            $groups = [['name' => '', 'rate' => 0, 'weight' => 1]];
            $weights = [0 => 1];
        }
        $gross = Money::allocate($amount, $weights);

        $lines = [];
        $taxes = [];
        $net = 0;
        $tax = 0;
        $concept = $i18n->t('Refund for invoice {number}', ['number' => (string) $original['full_number']], $lang);
        foreach ($groups as $i => $group) {
            if ($gross[$i] === 0 && count($groups) > 1) {
                continue;
            }
            list($base, $quota) = Money::split($gross[$i], $group['rate']);
            $lines[] = [
                'kind' => 'refund', 'name' => count($groups) > 1 ? $concept . ' (' . self::rate($app, $group['rate'], $lang) . ')' : $concept,
                'variant_label' => '', 'sku' => '', 'qty' => 1, 'unit_net' => -$base, 'tax_rate' => $group['rate'], 'tax_name' => $group['name'],
                'net' => -$base, 'tax' => -$quota, 'total' => -$gross[$i],
            ];
            $taxes[] = ['name' => $group['name'], 'rate' => $group['rate'], 'base' => -$base, 'amount' => -$quota];
            $net += $base;
            $tax += $quota;
        }

        $returned = [];
        $byId = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            $byId[(int) $item['id']] = $item;
        }
        foreach (isset($refund['items']) && is_array($refund['items']) ? $refund['items'] : [] as $itemId => $qty) {
            if (isset($byId[(int) $itemId]) && (int) $qty > 0) {
                $returned[] = ['name' => (string) $byId[(int) $itemId]['name'], 'qty' => (int) $qty];
            }
        }

        return self::common($app, $order) + [
            'lines' => $lines,
            'discounts' => [],
            'taxes' => $taxes,
            'totals' => ['net' => -$net, 'tax' => -$tax, 'total' => -$amount, 'items_total' => 0, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0],
            'original' => ['id' => (int) $original['id'], 'full_number' => (string) $original['full_number'], 'issued_at' => (string) $original['issued_at']],
            'reason' => trim((string) ($refund['reason'] !== null ? $refund['reason'] : '')),
            'returned' => $returned,
        ];
    }

    /** El idioma del pedido: el de la factura. */
    public static function lang(array $order): string
    {
        $lang = isset($order['lang']) ? strtolower(substr((string) $order['lang'], 0, 2)) : '';

        return preg_match('/^[a-z]{2}$/', $lang) === 1 ? $lang : 'es';
    }

    /** «21 %», «10,5 %»: un tipo en centésimas de punto, con su idioma. */
    public static function rate(App $app, int $rate, string $lang): string
    {
        $decimals = $rate % 100 === 0 ? 0 : ($rate % 10 === 0 ? 1 : 2);

        return $app->i18n()->number($rate / 100, $decimals, $lang) . ' %';
    }

    /**
     * Lo común a facturas y rectificativas: idioma, emisor (los datos de la tienda de hoy, congelados), receptor (los de facturación
     * del pedido), datos del pedido y el texto legal de los ajustes.
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    private static function common(App $app, array $order): array
    {
        $settings = $app->settings();
        $lang = self::lang($order);
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $name = trim((isset($billing['first_name']) ? (string) $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? (string) $billing['last_name'] : ''));
        $company = isset($billing['company']) ? trim((string) $billing['company']) : '';
        $issuerName = trim($settings->str('store.legal_name'));
        if ($issuerName === '') {
            $issuerName = trim($settings->str('store.name', 'AIFEC'));
        }
        $addressLines = [];
        foreach (preg_split('/\R/u', $settings->str('store.address')) ?: [] as $line) {
            if (trim($line) !== '') {
                $addressLines[] = trim($line);
            }
        }

        return [
            'lang' => $lang,
            'issuer' => [
                'name' => $issuerName, 'vat_id' => trim($settings->str('store.vat_id')), 'address' => $addressLines,
                'email' => trim($settings->str('store.email')), 'phone' => trim($settings->str('store.phone')),
            ],
            'receiver' => [
                'name' => $company !== '' ? $company : $name, 'vat_id' => isset($billing['vat_id']) ? trim((string) $billing['vat_id']) : '',
                'lines' => Address::lines($billing, $lang, ['tax_id' => false, 'phone' => false]), 'email' => (string) $order['email'],
                'country' => isset($billing['country']) ? strtoupper(trim((string) $billing['country'])) : '',
            ],
            'order' => [
                'number' => (string) $order['number'], 'placed_at' => (string) $order['placed_at'],
                'paid_at' => $order['paid_at'] !== null ? (string) $order['paid_at'] : null, 'currency' => (string) $order['currency'],
                'prices_include_tax' => !empty($order['prices_include_tax']),
            ],
            'payment_title' => (string) ($order['payment_title'] !== null ? $order['payment_title'] : ''),
            'legal_text' => trim($settings->str('invoices.legal_text')),
        ];
    }
}
