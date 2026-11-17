<?php
/**
 * AIFEC — GM licences: emite las licencias al pagar, las revoca al devolver, las enseña al cliente y añade su pestaña al producto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\Licencias\Licenses;
use Gm\Licencias\Mails;
use Gm\Licencias\ProductFields;
use Gm\Licencias\Products;

return static function (App $app): void {
    $hooks = $app->hooks();

    // El servicio: $app->service('gm_licenses') (la API, la zona de cliente y el panel lo usan).
    $app->bind('gm_licenses', static function (App $app): Licenses {
        return new Licenses($app);
    });

    // Las claves van dentro del correo de confirmación del pedido (bloques), no en un correo aparte.
    $hooks->filter('order.mail.blocks', static function ($blocks, $order = [], $template = '') use ($app) {
        return Mails::blocks($blocks, $order, $template, $app);
    });

    // Al pagar (la acción dispara una sola vez por pedido, y emitir es idempotente): una licencia por unidad. Las claves ya salieron en
    // el correo del pedido; solo si no cupieron en él (muchas unidades) o ese correo no se compuso, se manda el correo propio.
    $hooks->on('order.paid', static function ($order) use ($app): void {
        if (!is_array($order) || !isset($order['id'])) {
            return;
        }
        $created = $app->service('gm_licenses')->issueForOrder($order);
        $created = array_merge(Mails::takePending((int) $order['id']), $created);
        if ($created !== []) {
            Mails::issued($app, $order, $created);
        }
    });

    // Devolver el pedido revoca sus licencias (todas si se devuelve entero; las unidades devueltas si es parcial). Cancelarlo, todas.
    $hooks->on('order.refunded', static function ($order, $refund = []) use ($app): void {
        if (!is_array($order) || !isset($order['id'])) {
            return;
        }
        $licenses = $app->service('gm_licenses');
        if (isset($order['payment_status']) && $order['payment_status'] === 'refunded') {
            $licenses->revokeForOrder((int) $order['id'], t('Order refunded'));
        } elseif (is_array($refund) && isset($refund['items']) && is_array($refund['items'])) {
            $licenses->revokeItems((int) $order['id'], $refund['items'], t('Order refunded'));
        }
    });
    $hooks->on('order.cancelled', static function ($order) use ($app): void {
        if (is_array($order) && isset($order['id'])) {
            $app->service('gm_licenses')->revokeForOrder((int) $order['id'], t('Order cancelled'));
        }
    });

    // El dato tipado del producto: lleva su etiqueta, su tipo y si es público.
    $hooks->filter('product.extra', static function ($extra, $product) use ($app): array {
        $extra = is_array($extra) ? $extra : [];
        $config = is_array($product) && isset($product['id']) ? Products::get($app, (int) $product['id']) : null;
        if ($config !== null) {
            $extra['gm_license'] = ['label' => t('Licence'), 'value' => Products::describe($config), 'type' => 'text', 'public' => true];
        }

        return $extra;
    });

    // Su pestaña en la ficha del producto del panel y su guardado.
    $hooks->filter('admin.definitions', [ProductFields::class, 'definitions']);
    $hooks->on('admin.resource.saved', static function ($resource, $id, $values = []) use ($app): void {
        ProductFields::saved($resource, $id, $values, $app);
    });

    // El cliente las ve en su zona (un recuadro en el resumen y su propia página) y en la página del pedido.
    $hooks->slot('account.dashboard', static function ($customer) use ($app): string {
        if (!is_array($customer) || !isset($customer['id'])) {
            return '';
        }
        $count = count($app->service('gm_licenses')->forCustomer($customer));

        return $count > 0 && $app->router()->has('gm.licenses.account') ? $app->view()->render('gm-licencias::front/dashboard', ['count' => $count]) : '';
    });
    $hooks->slot('order.thanks', static function ($order) use ($app): string {
        if (!is_array($order) || !isset($order['id'])) {
            return '';
        }
        $rows = $app->service('gm_licenses')->forOrder((int) $order['id']);

        return $rows === [] ? '' : $app->view()->render('gm-licencias::front/thanks', ['licenses' => $rows]);
    });
};
