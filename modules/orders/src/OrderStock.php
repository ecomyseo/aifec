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

use Aifec\Kernel\App;

/**
 * Las existencias de un pedido: devolverlas al cancelar o fallar el cobro, y volver a quitarlas si llega un cobro tarde.
 *
 * Al crear el pedido se descuentan (lo hace el creador del pedido); desde entonces el pedido «las tiene». La cuenta se
 * lleva en el historial con apuntaciones de tipo «system»: la última dice si están devueltas (stock_released) o no
 * (stock_held, o ninguna). Quien llama a release() ya ha ganado la transición de estado con una sentencia condicionada,
 * así que dos peticiones a la vez no devuelven dos veces; la cuenta del historial es la segunda red.
 */
final class OrderStock
{
    /** @var App */
    private $app;

    /** @var OrderLog */
    private $log;

    public function __construct(App $app, OrderLog $log)
    {
        $this->app = $app;
        $this->log = $log;
    }

    /** ¿Tiene el pedido las existencias descontadas? Sin ninguna apuntación, sí. */
    public function held(int $orderId): bool
    {
        $last = $this->app->db()->value(
            "SELECT to_value FROM {order_history} WHERE order_id = ? AND kind = 'system' AND to_value IN ('stock_held', 'stock_released') ORDER BY id DESC LIMIT 1",
            [$orderId]
        );

        return $last === null || (string) $last === 'stock_held';
    }

    /**
     * Devuelve las existencias del pedido, una sola vez. False si ya estaban devueltas.
     *
     * @param array<string,mixed> $order fila de orders
     */
    public function release(array $order, string $reason, ?int $userId = null): bool
    {
        $id = (int) $order['id'];
        if (!$this->held($id)) {
            return false;
        }
        $catalog = $this->app->service('catalog');
        foreach ($this->lines($id) as $line) {
            $qty = (int) $line['qty'];
            if ($qty < 1) {
                continue;
            }
            try {
                $ok = $catalog->adjustStock((int) $line['product_id'], (int) $line['variant_id'], $qty, $reason, (string) $order['number']);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'orders.stock.release');
                $ok = false;
            }
            if (!$ok) {
                $this->app->log()->warning('Stock could not be returned', ['order' => $id, 'product' => (int) $line['product_id']]);
            }
        }
        $this->log->add($id, 'system', 'stock_held', 'stock_released', t('Stock returned'), false, $userId);

        return true;
    }

    /**
     * Vuelve a descontar las existencias (llegó un cobro de un pedido que ya las había devuelto). Todo o nada: si falta
     * algo, lo que se había descontado se devuelve y se dan los nombres de lo que no hay.
     *
     * @param array<string,mixed> $order fila de orders
     *
     * @return string[] nombres de las líneas sin existencias; vacío = todo descontado
     */
    public function take(array $order, string $reason): array
    {
        $id = (int) $order['id'];
        $catalog = $this->app->service('catalog');
        $taken = [];
        $missing = [];
        foreach ($this->lines($id) as $line) {
            $qty = (int) $line['qty'];
            if ($qty < 1) {
                continue;
            }
            try {
                $ok = $catalog->adjustStock((int) $line['product_id'], (int) $line['variant_id'], -$qty, $reason, (string) $order['number']);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'orders.stock.take');
                $ok = false;
            }
            if ($ok) {
                $taken[] = $line;
            } else {
                $missing[] = (string) $line['name'];
            }
        }
        if ($missing !== []) {
            foreach ($taken as $line) {
                $catalog->adjustStock((int) $line['product_id'], (int) $line['variant_id'], (int) $line['qty'], $reason, (string) $order['number']);
            }

            return $missing;
        }
        $this->log->add($id, 'system', 'stock_released', 'stock_held', t('Stock taken again'));

        return [];
    }

    /**
     * Devuelve al almacén lo devuelto por el cliente (una devolución con reposición).
     *
     * @param array<string,mixed> $order fila de orders
     * @param array<int,int>      $items order_item_id => unidades
     */
    public function restockItems(array $order, array $items, string $reason): void
    {
        $catalog = $this->app->service('catalog');
        foreach ($this->lines((int) $order['id']) as $line) {
            $qty = isset($items[(int) $line['id']]) ? (int) $items[(int) $line['id']] : 0;
            if ($qty < 1) {
                continue;
            }
            try {
                $catalog->adjustStock((int) $line['product_id'], (int) $line['variant_id'], $qty, $reason, (string) $order['number']);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'orders.stock.restock');
            }
        }
    }

    /**
     * Las líneas de producto del pedido.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lines(int $orderId): array
    {
        return $this->app->db()->select(
            "SELECT id, product_id, variant_id, qty, name FROM {order_items} WHERE order_id = ? AND kind = 'product' AND product_id IS NOT NULL ORDER BY position, id",
            [$orderId]
        );
    }
}
