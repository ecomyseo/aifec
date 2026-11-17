<?php
/**
 * AIFEC — GM coming soon: productos «próximamente» (visibles, no comprables), su insignia, su «avísame» y su pestaña del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\Proximamente\Availability;
use Gm\Proximamente\Guard;
use Gm\Proximamente\ProductFields;
use Gm\Proximamente\Soon;

return static function (App $app): void {
    $hooks = $app->hooks();

    // La disponibilidad: filtro sellado, entra con la capacidad «pricing» que el comerciante concede. Con ella el producto no se
    // puede comprar de verdad (purchasable = false: ni botón, ni carrito, ni caja) y no enseña precio (show_price = false). Sin ella,
    // sigue sin poder comprarse (lo impide el guardián de abajo), pero no cambia su etiqueta ni su estado a la vista.
    $hooks->filter('product.availability', static function ($availability, $context) use ($app) {
        return Availability::filter($availability, $context, $app);
    });

    // La insignia de la tarjeta: «Próximamente» en lugar de «Agotado». No es un filtro sellado: no toca precio ni existencias.
    $hooks->filter('product.badges', static function ($badges, $product = [], $where = 'card') use ($app) {
        return Availability::badges($badges, $product, $where, $app);
    });

    // Que no se pueda comprar: el formulario de la tienda (antes de que llegue al carrito) y cualquier otra entrada.
    $hooks->filter('request.start', [Guard::class, 'early']);
    $hooks->on('cart.changed', static function ($summary) use ($app): void {
        Guard::changed($summary, $app);
    });

    // La ficha: la insignia junto al precio y el formulario «avísame» donde iría la compra.
    $hooks->slot('product.price.after', static function ($product) use ($app): string {
        if (!is_array($product) || !isset($product['id']) || !Soon::isSoon($app, (int) $product['id'])) {
            return '';
        }
        $label = trim($app->settings()->str('gm_proximamente.badge', ''));

        return $app->view()->render('gm-proximamente::front/badge', ['label' => $label !== '' ? $label : t('Coming soon')]);
    });
    $hooks->slot('product.buy.after', static function ($product) use ($app): string {
        if (!is_array($product) || !isset($product['id']) || !Soon::isSoon($app, (int) $product['id'])) {
            return '';
        }
        $intro = trim($app->settings()->str('gm_proximamente.intro', ''));

        return $app->view()->render('gm-proximamente::front/form', [
            'product_id' => (int) $product['id'], 'intro' => $intro !== '' ? $intro : t('Leave your email and we will write to you once, the day it is available.'),
        ]);
    });

    // El panel: su pestaña en la ficha del producto y su guardado.
    $hooks->filter('admin.definitions', [ProductFields::class, 'definitions']);
    $hooks->on('admin.resource.saved', static function ($resource, $id, $values = []) use ($app): void {
        ProductFields::saved($resource, $id, $values, $app);
    });
};
