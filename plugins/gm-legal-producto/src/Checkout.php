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
 * La caja: los textos legales de lo que hay en el carrito y, si hay contenido digital o servicios, la casilla de la petición de que
 * empiecen ya (con su pérdida o su pago proporcional del desistimiento), justo encima del botón de pagar.
 *
 * La casilla NO impide comprar: es una petición del cliente. Se guarda lo que decidió, marcada o no, con el texto exacto que vio, el tipo
 * de pedido y la fecha: la prueba de que se le preguntó y qué contestó. No se guarda su IP.
 */
final class Checkout
{
    public const FIELD = 'gm_legal_waiver';

    /** Lo que hay en el carrito que lleva desistimiento especial: 'download', 'service', 'both' o '' si nada. */
    public const DOWNLOAD = 'download';
    public const SERVICE = 'service';
    public const BOTH = 'both';

    /**
     * El hueco «checkout.review.after»: los textos legales del carrito.
     *
     * @param mixed $summary el carrito con precios
     */
    public static function slot(App $app, $summary): string
    {
        $productIds = self::productIds($summary);
        if ($productIds === [] || !$app->settings()->bool('gm_legal_producto.checkout_blocks', true)) {
            return '';
        }
        $blocks = Blocks::view((new Blocks($app))->forProducts($productIds));
        if ($blocks === []) {
            return '';
        }

        return $app->view()->render('gm-legal-producto::front/checkout', ['blocks' => $blocks, 'waiver' => false, 'field' => self::FIELD, 'waiver_text' => '']);
    }

    /**
     * El hueco «checkout.review.before_pay»: la casilla, encima del botón de pagar. Solo si el carrito lleva una descarga o un servicio.
     *
     * @param mixed $summary el carrito con precios
     */
    public static function waiverSlot(App $app, $summary): string
    {
        if (!$app->settings()->bool('gm_legal_producto.waiver_enabled', true)) {
            return '';
        }
        $kind = self::kindOf($app, self::productIds($summary));
        if ($kind === '') {
            return '';
        }

        return $app->view()->render('gm-legal-producto::front/checkout', ['blocks' => [], 'waiver' => true, 'field' => self::FIELD, 'waiver_text' => self::text($app, $kind)]);
    }

    /**
     * El texto de la casilla según lo que lleva el carrito. El del comerciante (ajuste) vale para las descargas; los servicios y la mezcla
     * llevan el suyo, porque la ley dice cosas distintas: una descarga empezada pierde el desistimiento y un servicio empezado se paga a
     * prorrata.
     */
    public static function text(App $app, string $kind): string
    {
        if ($kind === self::SERVICE) {
            return t('I ask for the service to start now. If I withdraw after it has started, I will pay the proportional part of what has been provided.');
        }
        if ($kind === self::BOTH) {
            return t('I ask for the download and the service to start now. I understand that, once the download starts, I lose my right of withdrawal on it and that, if I withdraw from the service after it has started, I will pay the proportional part of what has been provided.');
        }
        $custom = trim($app->settings()->str('gm_legal_producto.waiver_text', ''));

        return $custom !== '' ? $custom : t('I ask for the download to start now and I understand that, once it starts, I lose my right of withdrawal.');
    }

    /**
     * La acción «order.placed»: apunta qué contestó el cliente a la casilla, con el texto que vio. Sin su IP.
     *
     * @param mixed $order
     */
    public static function placed($order, App $app): void
    {
        if (!is_array($order) || !isset($order['id']) || !$app->settings()->bool('gm_legal_producto.waiver_enabled', true)) {
            return;
        }
        $productIds = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            if (is_array($item) && isset($item['kind'], $item['product_id']) && $item['kind'] === 'product') {
                $productIds[] = (int) $item['product_id'];
            }
        }
        $kind = self::kindOf($app, $productIds);
        if ($kind === '') {
            return;
        }
        $granted = $app->request()->bool(self::FIELD);
        $version = trim($app->settings()->str('gm_legal_producto.version', ''));
        $app->db()->upsert('gm_legal_orders', [
            'order_id' => (int) $order['id'], 'waiver' => $granted ? 1 : 0, 'version' => substr($version, 0, 40),
            'kind' => $kind, 'text' => self::text($app, $kind), 'created_at' => $app->clock()->sql(),
        ], ['order_id']);
    }

    /**
     * ¿Qué lleva el carrito o el pedido: una descarga, un servicio, las dos cosas o nada que pida la casilla?
     *
     * @param int[] $productIds
     */
    public static function kindOf(App $app, array $productIds): string
    {
        $download = false;
        $service = false;
        foreach (array_unique($productIds) as $id) {
            $product = $app->service('catalog')->product((int) $id, null, true);
            $kind = is_array($product) && isset($product['kind']) ? (string) $product['kind'] : '';
            $download = $download || $kind === 'digital';
            $service = $service || $kind === 'service';
        }
        if ($download && $service) {
            return self::BOTH;
        }

        return $download ? self::DOWNLOAD : ($service ? self::SERVICE : '');
    }

    /**
     * @param mixed $summary el carrito con precios
     *
     * @return int[]
     */
    private static function productIds($summary): array
    {
        $lines = is_array($summary) && isset($summary['lines']) && is_array($summary['lines']) ? $summary['lines'] : [];
        $productIds = [];
        foreach ($lines as $line) {
            if (is_array($line) && isset($line['product_id'])) {
                $productIds[] = (int) $line['product_id'];
            }
        }

        return $productIds;
    }
}
