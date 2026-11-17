<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

use Aifec\Commerce\Money;
use Aifec\Kernel\App;

/**
 * El dinero de una devolución: el importe que se propone y cómo se reparte en llamadas a Orders::refund().
 *
 * Importe propuesto = cada línea devuelta con sus impuestos y el descuento que ya lleva repartido (línea con impuestos del pedido,
 * prorrateada por unidades) más, solo en el desistimiento del pedido entero y con el ajuste returns.refund_shipping, el envío de
 * ida con sus impuestos. Nunca pasa de lo que queda por devolver del pedido. Los ajustes de importe que añada un plugin al pedido
 * no se reparten por líneas: si procede devolverlos, se hace desde la ficha del pedido.
 */
final class ReturnAmounts
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,mixed> $return la devolución con sus líneas (Returns::find)
     *
     * @return array{lines:array<int,int>,shipping:int,subtotal:int,total:int,remaining:int,pending:int}
     *         lines: id de return_items => importe; total: tope por lo que queda del pedido; pending: líneas sin devolver
     */
    public function propose(array $return): array
    {
        $out = ['lines' => [], 'shipping' => 0, 'subtotal' => 0, 'total' => 0, 'remaining' => 0, 'pending' => 0];
        $order = $this->app->has('orders') ? $this->app->service('orders')->find((int) $return['order_id']) : null;
        if ($order === null) {
            return $out;
        }
        $byId = [];
        foreach ($order['items'] as $item) {
            $byId[(int) $item['id']] = $item;
        }
        $sum = 0;
        foreach (isset($return['items']) && is_array($return['items']) ? $return['items'] : [] as $line) {
            if (!empty($line['refunded']) || !isset($byId[(int) $line['order_item_id']])) {
                continue;
            }
            $item = $byId[(int) $line['order_item_id']];
            $qty = max(1, (int) $item['qty']);
            $gross = (int) $item['line_net'] + (int) $item['line_tax'];
            $before = min($qty, max(0, (int) $item['refunded_qty']));
            $after = min($qty, $before + (int) $line['qty']);
            $amount = max(0, Money::divRound($gross * $after, $qty) - Money::divRound($gross * $before, $qty));
            $out['lines'][(int) $line['id']] = $amount;
            $sum += $amount;
            ++$out['pending'];
        }
        $shipping = 0;
        if ((string) $return['kind'] === ReturnPolicy::WITHDRAWAL && !empty($return['full_order']) && (int) $return['refunded_amount'] === 0
            && $this->app->settings()->bool('returns.refund_shipping', true)) {
            foreach ($order['items'] as $item) {
                if ((string) $item['kind'] === 'shipping') {
                    $shipping += (int) $item['line_net'] + (int) $item['line_tax'];
                }
            }
        }
        $remaining = max(0, (int) $order['paid_total'] - (int) $order['refunded_total']);
        $out['shipping'] = $shipping;
        $out['subtotal'] = $sum + $shipping;
        $out['remaining'] = $remaining;
        $out['total'] = min($sum + $shipping, $remaining);

        return $out;
    }

    /**
     * Cómo se reparte un importe final en llamadas a Orders::refund(): una sola si todas las líneas se reponen (o ninguna) y dos
     * si hay de las dos clases, porque la reposición de existencias es de todo o nada en cada llamada. El envío va con las líneas
     * que no se reponen. Con un importe editado hacia abajo, el reparto es proporcional a lo propuesto.
     *
     * @param array<string,mixed>                                                                 $return
     * @param array{lines:array<int,int>,shipping:int,subtotal:int,total:int,remaining:int,pending:int} $proposal
     *
     * @return array<int,array{items:array<int,int>,amount:int,restock:bool,return_item_ids:array<int,int>}>
     *
     * @throws \InvalidArgumentException si el importe no se puede repartir
     */
    public function split(array $return, array $proposal, int $amount): array
    {
        $groups = ['restock' => ['items' => [], 'sum' => 0, 'ids' => []], 'keep' => ['items' => [], 'sum' => 0, 'ids' => []]];
        foreach (isset($return['items']) && is_array($return['items']) ? $return['items'] : [] as $line) {
            if (!empty($line['refunded']) || !isset($proposal['lines'][(int) $line['id']])) {
                continue;
            }
            $key = !empty($line['restock']) ? 'restock' : 'keep';
            $groups[$key]['items'][(int) $line['order_item_id']] = (int) $line['qty'];
            $groups[$key]['sum'] += (int) $proposal['lines'][(int) $line['id']];
            $groups[$key]['ids'][] = (int) $line['id'];
        }
        $groups['keep']['sum'] += (int) $proposal['shipping'];
        $restock = $groups['restock'];
        $keep = $groups['keep'];
        $total = (int) $restock['sum'] + (int) $keep['sum'];

        if ($restock['items'] === [] || $keep['items'] === [] || (int) $restock['sum'] < 1 || (int) $keep['sum'] < 1) {
            $useRestock = $restock['items'] !== [] && ($keep['items'] === [] || (int) $restock['sum'] >= (int) $keep['sum']);
            $items = $restock['items'] + $keep['items'];

            return [['items' => $items, 'amount' => $amount, 'restock' => $useRestock, 'return_item_ids' => array_merge($restock['ids'], $keep['ids'])]];
        }
        $first = $total > 0 ? Money::divRound($amount * (int) $restock['sum'], $total) : 0;
        $second = $amount - $first;
        if ($first < 1 || $second < 1) {
            throw new \InvalidArgumentException('The amount is too low to split the refund');
        }

        // Primero lo que no se repone (lleva el envío): si la segunda falla, al repetir ya no hay que volver a devolver el envío.
        return [
            ['items' => $keep['items'], 'amount' => $second, 'restock' => false, 'return_item_ids' => $keep['ids']],
            ['items' => $restock['items'], 'amount' => $first, 'restock' => true, 'return_item_ids' => $restock['ids']],
        ];
    }
}
