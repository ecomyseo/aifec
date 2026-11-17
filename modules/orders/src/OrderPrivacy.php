<?php
/**
 * AIFEC — pedidos y datos personales: lo que este módulo aporta a la copia de datos de un cliente y lo que borra
 * cuando el cliente pide que se le olvide.
 *
 * Se borra lo que la ley permite borrar y se conserva lo que obliga a guardar: los datos de facturación y de entrega de
 * un pedido que llegó a cobrarse (hay factura) se quedan; todo lo demás —carritos, correo de contacto, notas, dirección
 * de red, origen de la visita— se quita, y un pedido que nunca se cobró se queda sin ningún dato de la persona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Kernel\App;

final class OrderPrivacy
{
    /** Dominio reservado que no existe ni existirá: un correo así no llega a nadie. */
    private const NOBODY = '@anonymous.invalid';

    /**
     * Acción «customer.anonymised» (id del cliente y el correo que tenía).
     *
     * @return array{carts_deleted:int,carts_cleared:int,orders_cleared:int,orders_emptied:int}
     */
    public static function forget(App $app, int $customerId, string $email): array
    {
        $db = $app->db();
        $email = trim($email);
        $out = ['carts_deleted' => 0, 'carts_cleared' => 0, 'orders_cleared' => 0, 'orders_emptied' => 0];
        if ($customerId < 1 && $email === '') {
            return $out;
        }

        // Quién: sus filas por identificador y, además, las de invitado con ese mismo correo.
        list($whoCart, $paramsCart) = self::who($customerId, $email);
        list($whoOrder, $paramsOrder) = self::who($customerId, $email);

        // Carritos: los que no llegaron a pedido se borran; los que sí, se quedan sin nada de la persona.
        $out['carts_deleted'] = $db->run('DELETE FROM {carts} WHERE order_id IS NULL AND (' . $whoCart . ')', $paramsCart);
        $out['carts_cleared'] = $db->run(
            'UPDATE {carts} SET email = NULL, checkout = NULL, destination = NULL WHERE order_id IS NOT NULL AND (' . $whoCart . ')',
            $paramsCart
        );

        foreach ($db->select('SELECT id, paid_total, paid_at FROM {orders} WHERE ' . $whoOrder, $paramsOrder) as $order) {
            $id = (int) $order['id'];
            $changes = ['email' => 'deleted-' . $id . self::NOBODY, 'customer_note' => null, 'ip' => null, 'attribution' => null];
            if ((int) $order['paid_total'] === 0 && $order['paid_at'] === null) {
                // Nunca se cobró: no hay factura que conservar.
                $changes['billing'] = null;
                $changes['shipping'] = null;
                ++$out['orders_emptied'];
            } else {
                ++$out['orders_cleared'];
            }
            $db->update('orders', $changes, ['id' => $id]);
        }

        return $out;
    }

    /**
     * Filtro «customer.export» (datos, id del cliente): añade sus pedidos a la copia de sus datos.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function export(App $app, array $data, int $customerId): array
    {
        if ($customerId < 1) {
            return $data;
        }
        $db = $app->db();
        $orders = [];
        foreach ($db->select('SELECT * FROM {orders} WHERE customer_id = ? ORDER BY placed_at, id', [$customerId]) as $row) {
            $items = [];
            foreach ($db->select('SELECT name, variant_label, sku, qty, unit_price, line_total FROM {order_items} WHERE order_id = ? ORDER BY position, id', [(int) $row['id']]) as $item) {
                $items[] = [
                    'name' => (string) $item['name'], 'variant' => (string) $item['variant_label'], 'sku' => (string) $item['sku'],
                    'qty' => (int) $item['qty'], 'unit_price' => (int) $item['unit_price'], 'total' => (int) $item['line_total'],
                ];
            }
            $orders[] = [
                'number' => (string) $row['number'],
                'placed_at' => (string) $row['placed_at'],
                'status' => (string) $row['status'],
                'payment_status' => (string) $row['payment_status'],
                'payment_method' => (string) $row['payment_title'],
                'carrier' => (string) $row['carrier_title'],
                'currency' => (string) $row['currency'],
                'total' => (int) $row['total'],
                'email' => (string) $row['email'],
                'billing' => self::decode($row['billing']),
                'shipping' => self::decode($row['shipping']),
                'note' => (string) $row['customer_note'],
                'items' => $items,
            ];
        }
        $data['orders'] = $orders;

        return $data;
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function who(int $customerId, string $email): array
    {
        $parts = [];
        $params = [];
        if ($customerId > 0) {
            $parts[] = 'customer_id = ?';
            $params[] = $customerId;
        }
        if ($email !== '') {
            $parts[] = 'email = ?';
            $params[] = $email;
        }

        return [implode(' OR ', $parts), $params];
    }

    /**
     * @param mixed $json
     *
     * @return array<string,mixed>|null
     */
    private static function decode($json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : null;
    }
}
