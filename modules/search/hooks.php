<?php
/**
 * AIFEC — buscador: publica el servicio «search», reindexa cuando cambian los productos, las existencias o los contenidos
 * (siempre encolando un trabajo único por entidad, nunca dentro de la petición del panel) y carga el guion de las sugerencias.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Search\Search;

return static function (App $app): void {
    $app->bind('search', static function (App $app): Search {
        return new Search($app);
    });

    /**
     * @return Search
     */
    $search = static function () use ($app): Search {
        /** @var Search $service */
        $service = $app->service('search');

        return $service;
    };

    // Lo que se guarda o se borra en el panel: un producto se reindexa él solo; lo que comparten muchos (categorías, marcas,
    // atributos, etiquetas) cambia el texto de todos los productos que lo llevan, y entonces se reindexa el tipo entero.
    $shared = ['categories', 'category', 'brands', 'brand', 'attributes', 'attribute', 'attribute_values', 'families', 'tags'];
    $changed = static function ($resource, $id = null) use ($search, $shared): void {
        $resource = (string) $resource;
        if ($resource === 'products' || $resource === 'product') {
            if (is_numeric($id) && (int) $id > 0) {
                $search()->queueIndex('product', (int) $id);
            }

            return;
        }
        if (in_array($resource, $shared, true)) {
            $search()->reindex('product');
        }
    };
    $app->hooks()->on('admin.resource.saved', $changed);
    $app->hooks()->on('admin.resource.deleted', $changed);

    // Las existencias cambian a menudo: muchos cambios seguidos del mismo producto son un solo trabajo.
    $app->hooks()->on('stock.changed', static function ($productId = 0) use ($search): void {
        $search()->queueIndex('product', (int) $productId);
    });

    // Las páginas y las entradas del módulo «cms».
    $app->hooks()->on('content.saved', static function ($type = '', $id = 0) use ($search): void {
        if ($type === 'page' || $type === 'post') {
            $search()->queueIndex((string) $type, (int) $id);
        } elseif ($type === 'term') {
            $search()->reindex('post');
        }
    });

    // El guion de las sugerencias: se engancha a cualquier <input type="search" name="q"> del tema. Las cadenas que enseña
    // llegan como atributos de datos, ya traducidas.
    $app->hooks()->slot('body.end', static function () use ($app): string {
        return '<script src="' . e($app->url()->asset('m', 'search', 'suggest.js')) . '" defer'
            . ' data-af-suggest-url="' . e($app->url()->route('search.suggest')) . '"'
            . ' data-af-suggest-label="' . e(t('Suggestions')) . '"></script>';
    });
};
