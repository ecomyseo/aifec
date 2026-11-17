<?php
/**
 * AIFEC — GM AI notice: el aviso de textos redactados con IA en la ficha, en las páginas y en el pie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\AvisoIa\Notice;

return static function (App $app): void {
    $hooks = $app->hooks();

    // La ficha del producto: bajo el resumen y/o al final, según el ajuste. El tema decide dónde pinta cada hueco.
    $hooks->slot('product.summary.after', static function ($product) use ($app): string {
        return Notice::product($app, is_array($product) ? $product : [], 'summary');
    });
    $hooks->slot('product.bottom', static function ($product) use ($app): string {
        return Notice::product($app, is_array($product) ? $product : [], 'bottom');
    });

    // Las páginas del CMS no tienen hueco propio: se apunta qué página es (filtro «page.meta», que corre en el <head>) y el
    // hueco del pie, que corre después, pinta el aviso encima. Es un dato de esta petición, no un estado entre peticiones.
    $hooks->filter('page.meta', static function ($page) use ($app) {
        if (is_array($page) && isset($page['entity']['type'], $page['entity']['id'])) {
            $app->request()->setAttr('gm_aviso_ia.entity', [(string) $page['entity']['type'], (int) $page['entity']['id']]);
        }

        return $page;
    });
    $hooks->slot('footer.before', static function () use ($app): string {
        $entity = $app->request()->attr('gm_aviso_ia.entity');
        $html = is_array($entity) ? Notice::page($app, (string) $entity[0], (int) $entity[1]) : '';

        return $html . Notice::footer($app);
    });

    // Las páginas ya guardadas en la caché llevan el aviso viejo: al cambiar los ajustes de este plugin se vacía.
    $hooks->on('settings.saved', static function ($module) use ($app): void {
        if ($module === 'gm-aviso-ia') {
            $app->cache()->flush();
        }
    });
};
