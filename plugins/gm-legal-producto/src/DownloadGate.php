<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\LegalProducto;

use Aifec\Kernel\App;

/**
 * La puerta de las descargas: un contenido digital no se entrega hasta que el cliente ha aceptado la renuncia al desistimiento.
 *
 * Es el filtro sellado «order.download.allowed» (capacidad «orders», que el comerciante concede). La casilla de la caja guarda lo que
 * el cliente contestó (`gm_legal_orders.waiver`): 1 = la aceptó, 0 = no. Solo se frena cuando contestó que no. Un pedido del que
 * no se preguntó nada (hecho antes de activar el plugin, o con la renuncia apagada en los ajustes) se entrega como siempre.
 */
final class DownloadGate
{
    /**
     * @param mixed $allowed lo que decidió antes otro plugin (true, o su array)
     * @param mixed $order   el pedido completo
     * @param mixed $line    la línea del pedido
     * @param mixed $file    el fichero
     *
     * @return mixed
     */
    public static function filter($allowed, $order, $line, $file, App $app)
    {
        if (is_array($allowed) && array_key_exists('allowed', $allowed) && empty($allowed['allowed'])) {
            return $allowed;
        }
        $open = ['allowed' => true, 'reason' => ''];
        if (!is_array($order) || !isset($order['id']) || !$app->settings()->bool('gm_legal_producto.waiver_enabled', true)) {
            return $open;
        }
        $row = $app->db()->row('SELECT waiver, created_at FROM {gm_legal_orders} WHERE order_id = ?', [(int) $order['id']]);
        if ($row === null || $row['waiver'] === null || (int) $row['waiver'] === 1) {
            return $open;
        }
        // Sin la petición de empezar ya, el cliente conserva el desistimiento: la descarga queda retenida hasta que pasan los días
        // de plazo (14 de serie) desde el pedido, y entonces se entrega sola.
        $release = self::releaseAt($app, (string) $row['created_at']);
        if ($app->clock()->now() >= $release) {
            return $open;
        }

        return ['allowed' => false, 'reason' => t('The download is held while your right of withdrawal lasts. It is released on {date}, or earlier if you write to the shop and ask for it to start now.', ['date' => date('Y-m-d', $release)])];
    }

    /** El momento (marca de tiempo) en que acaba el plazo de desistimiento de un pedido hecho en esa fecha. */
    public static function releaseAt(App $app, string $createdAt): int
    {
        $days = max(0, min(60, (int) $app->settings()->get('gm_legal_producto.hold_days', 14)));
        $start = strtotime($createdAt . ' UTC');

        return ($start === false ? $app->clock()->now() : $start) + $days * 86400;
    }
}
