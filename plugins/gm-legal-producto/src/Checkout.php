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
 * La caja: los textos legales de lo que hay en el carrito y, si hay contenido digital, la casilla de la renuncia al desistimiento.
 *
 * La casilla NO impide comprar: es una petición del cliente («quiero la descarga ya y pierdo el desistimiento»). Se guarda lo que
 * decidió, marcada o no, con `customers->consent(…, 'withdrawal_waiver', …)`: la prueba de que se le preguntó y qué contestó.
 */
final class Checkout
{
    public const FIELD = 'gm_legal_waiver';

    /**
     * El hueco «checkout.review.after»: los textos legales del carrito y la casilla de la renuncia.
     *
     * @param mixed $summary el carrito con precios
     */
    public static function slot(App $app, $summary): string
    {
        $lines = is_array($summary) && isset($summary['lines']) && is_array($summary['lines']) ? $summary['lines'] : [];
        $productIds = [];
        foreach ($lines as $line) {
            if (is_array($line) && isset($line['product_id'])) {
                $productIds[] = (int) $line['product_id'];
            }
        }
        if ($productIds === []) {
            return '';
        }
        $settings = $app->settings();
        $blocks = $settings->bool('gm_legal_producto.checkout_blocks', true) ? Blocks::view((new Blocks($app))->forProducts($productIds)) : [];
        $waiver = $settings->bool('gm_legal_producto.waiver_enabled', true) && self::hasDigital($app, $productIds);
        if ($blocks === [] && !$waiver) {
            return '';
        }
        $text = trim($settings->str('gm_legal_producto.waiver_text', ''));

        return $app->view()->render('gm-legal-producto::front/checkout', [
            'blocks' => $blocks, 'waiver' => $waiver, 'field' => self::FIELD,
            'waiver_text' => $text !== '' ? $text : t('I ask for the download to start now and I understand that, once it starts, I lose my right of withdrawal.'),
        ]);
    }

    /**
     * La acción «order.placed»: si el pedido lleva contenido digital, apunta qué contestó el cliente a la casilla.
     *
     * @param mixed $order
     */
    public static function placed($order, App $app): void
    {
        if (!is_array($order) || !isset($order['id']) || !$app->settings()->bool('gm_legal_producto.waiver_enabled', true)) {
            return;
        }
        $digital = false;
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if (is_array($item) && isset($item['kind']) && $item['kind'] === 'product' && !empty($item['is_digital'])) {
                $digital = true;
            }
        }
        if (!$digital) {
            return;
        }
        $granted = $app->request()->bool(self::FIELD);
        $version = trim($app->settings()->str('gm_legal_producto.version', ''));
        $customerId = isset($order['customer_id']) && $order['customer_id'] !== null ? (int) $order['customer_id'] : null;
        $app->service('customers')->consent($customerId, (string) $order['email'], 'withdrawal_waiver', $granted, $version, 'order:' . (string) $order['number']);
        $app->db()->upsert('gm_legal_orders', ['order_id' => (int) $order['id'], 'waiver' => $granted ? 1 : 0, 'version' => substr($version, 0, 40), 'created_at' => $app->clock()->sql()], ['order_id']);
    }

    /**
     * ¿Alguno de estos productos es digital?
     *
     * @param int[] $productIds
     */
    private static function hasDigital(App $app, array $productIds): bool
    {
        foreach (array_unique($productIds) as $id) {
            $product = $app->service('catalog')->product($id, null, true);
            if (is_array($product) && isset($product['kind']) && $product['kind'] === 'digital') {
                return true;
            }
        }

        return false;
    }
}
