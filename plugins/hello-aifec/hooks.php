<?php
/**
 * AIFEC — Hello AIFEC: el dato «Hecho en» del producto, su campo en el panel y su hueco en la ficha.
 *
 * Es el plugin de ejemplo de la guía de desarrollo: enseña los tres puntos de extensión más usados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Plugins\HelloAifec\MadeIn;

return static function (App $app): void {
    // 1. Un dato tipado en el producto (filtro «product.extra»): lleva su etiqueta, su tipo y si es público.
    $app->hooks()->filter('product.extra', static function ($extra, $product) use ($app): array {
        $extra = is_array($extra) ? $extra : [];
        $country = (new MadeIn($app))->get(is_array($product) && isset($product['id']) ? (int) $product['id'] : 0);
        if ($country !== '') {
            $extra['made_in'] = ['label' => t('Made in'), 'value' => $country, 'type' => 'text', 'public' => true];
        }

        return $extra;
    });

    // 2. Un hueco en la ficha: el tema decide dónde pone «product.summary.after».
    $app->hooks()->slot('product.summary.after', static function ($product) use ($app): string {
        $country = (new MadeIn($app))->get(is_array($product) && isset($product['id']) ? (int) $product['id'] : 0);

        return $country === '' ? '' : $app->view()->render('hello-aifec::front/made-in', ['country' => $country]);
    });

    // 3. Su campo en el formulario del producto del panel (filtro «admin.definitions»). En el catálogo, `form` del recurso de
    //    productos es una FUNCIÓN (App $app, ?int $id) que devuelve el formulario, no un array: se envuelve la que había y se le
    //    añade el campo al resultado. No se guarda en la tabla de productos (`store` => false): lo guarda este plugin en la suya
    //    cuando el panel avisa de que se ha guardado un producto, y su valor sale ya puesto al abrir la ficha.
    $app->hooks()->filter('admin.definitions', static function ($definitions): array {
        $definitions = is_array($definitions) ? $definitions : [];
        $previous = isset($definitions['catalog']['resources']['products']['form']) ? $definitions['catalog']['resources']['products']['form'] : null;
        if ($previous === null) {
            return $definitions;
        }
        $definitions['catalog']['resources']['products']['form'] = static function (App $app, ?int $id) use ($previous): array {
            $form = is_callable($previous) ? call_user_func($previous, $app, $id) : (is_array($previous) ? $previous : []);
            $form = is_array($form) ? $form : [];
            $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : [];
            $fields['hello_aifec_made_in'] = [
                'type' => 'text', 'label' => 'Made in', 'hint' => 'Country where the product is made', 'store' => false,
                'default' => $id !== null && $id > 0 ? (new MadeIn($app))->own($id) : '',
            ];
            $form['fields'] = $fields;

            return $form;
        };

        return $definitions;
    });
    $app->hooks()->on('admin.resource.saved', static function ($resource, $id) use ($app): void {
        if ($resource !== 'products' || !is_numeric($id)) {
            return;
        }
        $form = $app->request()->arr('f');
        if (array_key_exists('hello_aifec_made_in', $form) && is_string($form['hello_aifec_made_in'])) {
            (new MadeIn($app))->set((int) $id, trim($form['hello_aifec_made_in']));
        }
    });
};
