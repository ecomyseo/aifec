<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Proximamente;

use Aifec\Kernel\App;

/**
 * El filtro sellado «product.availability» (capacidad «pricing»): un producto «próximamente» no se puede comprar, y se ve con su
 * etiqueta. Es el caso legítimo de la capacidad: cambia lo que la ficha dice de la disponibilidad, a la vista y con nombre.
 */
final class Availability
{
    /**
     * @param mixed $availability
     * @param mixed $context      ['id', 'variant_id', 'sku', 'kind', …]
     *
     * @return mixed
     */
    public static function filter($availability, $context, App $app)
    {
        if (!is_array($availability) || !is_array($context) || !isset($context['id']) || !Soon::isSoon($app, (int) $context['id'])) {
            return $availability;
        }
        $label = trim($app->settings()->str('gm_proximamente.badge', ''));
        $availability['status'] = 'out_of_stock';
        $availability['qty'] = 0;
        $availability['label'] = $label !== '' ? $label : t('Coming soon');
        $availability['delivery_days'] = null;
        // De verdad no comprable: sin botón en la ficha ni en la tarjeta, y el carrito y la caja lo rechazan en el servidor. Sin precio
        // en ningún sitio, y para las máquinas (datos estructurados) es una reserva: PreOrder.
        $availability['purchasable'] = false;
        $availability['show_price'] = false;
        $availability['schema'] = 'PreOrder';

        return $availability;
    }

    /**
     * El filtro «product.badges»: en la tarjeta, «Próximamente» en lugar de «Agotado» (y sin la de oferta). En la ficha ya está la
     * insignia propia del plugin junto al precio, así que no se repite.
     *
     * @param mixed $badges
     * @param mixed $product la tarjeta o la ficha, con su «id»
     * @param mixed $where   'card' o 'page'
     *
     * @return mixed
     */
    public static function badges($badges, $product, $where, App $app)
    {
        if (!is_array($badges) || !is_array($product) || !isset($product['id']) || !Soon::isSoon($app, (int) $product['id'])) {
            return $badges;
        }
        $out = [];
        foreach ($badges as $badge) {
            if (is_array($badge) && isset($badge['code']) && in_array($badge['code'], ['out', 'sale'], true)) {
                continue;
            }
            $out[] = $badge;
        }
        if ($where === 'card') {
            $label = trim($app->settings()->str('gm_proximamente.badge', ''));
            $out[] = ['code' => 'gm-soon', 'label' => $label !== '' ? $label : t('Coming soon'), 'kind' => 'info'];
        }

        return $out;
    }
}
