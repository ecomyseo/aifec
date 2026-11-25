<?php
/**
 * AIFEC — catálogo: publica el servicio «catalog», sella los filtros de precio y de disponibilidad, vacía la caché al guardar y
 * pone la tienda: quién pinta la ficha, las categorías y las marcas, y lo que el catálogo aporta a la portada y al menú.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Admin\ProductAdmin;
use Aifec\Modules\Catalog\Admin\ProductHooks;
use Aifec\Modules\Catalog\Admin\TaxonomyHooks;
use Aifec\Modules\Catalog\Catalog;
use Aifec\Modules\Catalog\Front\HomeSections;
use Aifec\Modules\Catalog\Front\ListingController;
use Aifec\Modules\Catalog\Front\MenuItems;
use Aifec\Modules\Catalog\Front\ProductController;
use Aifec\Modules\Catalog\Front\Variants;
use Aifec\Modules\Catalog\ProductData;

return static function (App $app): void {
    // El precio y la disponibilidad son dinero: solo un módulo con la capacidad «pricing» concedida puede cambiarlos.
    $app->hooks()->seal('product.price', 'pricing');
    $app->hooks()->seal('product.availability', 'pricing');

    $app->bind('catalog', static function (App $app): Catalog {
        return new Catalog($app);
    });

    // Al guardar o borrar algo en el panel, se vacía lo que la caché guardó de ello: un producto, o todo el catálogo
    // cuando cambia lo que comparten varios (categorías, marcas, atributos, familias, etiquetas, variantes).
    $flush = static function (string $resource, $id = null) use ($app): void {
        if ($resource === 'products' || $resource === 'product') {
            if (is_numeric($id) && (int) $id > 0) {
                $app->invalidate(['product:' . (int) $id]);
            } else {
                $app->invalidate(['catalog']);
            }

            return;
        }
        $shared = ['categories', 'category', 'brands', 'brand', 'attributes', 'attribute', 'attribute_values', 'families', 'tags', 'variants', 'product_variants'];
        if (in_array($resource, $shared, true)) {
            $app->invalidate(['catalog']);
        }
    };
    $app->hooks()->on('admin.resource.saved', $flush);
    $app->hooks()->on('admin.resource.deleted', $flush);

    // La tienda: quién pinta cada entidad con dirección amigable, y lo que el catálogo pone en la portada y en el menú principal.
    $app->entity('product', [ProductController::class, 'show']);
    $app->entity('category', [ListingController::class, 'category']);
    $app->entity('brand', [ListingController::class, 'brand']);
    // Las insignias de la ficha («Oferta», «Nuevo», «Agotado» y las de los plugins) van en el hueco product.gallery.before, que
    // todos los temas ponen DENTRO del contenedor de la imagen principal (posicionado): así la insignia se pinta sobre la foto
    // y nunca encima del título ni de la barra de anuncios. En la tarjeta las pinta la plantilla del tema (partial «partials/badges»).
    // La de «Oferta» no se repite en la ficha: el bloque de precio ya enseña el porcentaje.
    $app->hooks()->slot('product.gallery.before', static function ($product) use ($app): string {
        if (!is_array($product) || !isset($product['badge_list']) || !is_array($product['badge_list'])) {
            return '';
        }
        $product['badge_list'] = array_values(array_filter($product['badge_list'], static function ($badge): bool {
            return is_array($badge) && (!isset($badge['code']) || $badge['code'] !== 'sale');
        }));

        // La pieza «partials/badges» es del tema (el tema base la trae y los demás la heredan).
        return $product['badge_list'] === [] || !$app->view()->exists('partials/badges') ? '' : $app->view()->render('partials/badges', ['product' => $product, 'tag' => 'p', 'class' => 'af-product-badges']);
    }, 5);
    // Debajo del precio de la ficha: la base sin impuestos, el tipo y el total con ellos (en todos los temas, que ponen este hueco).
    // Un producto con variantes de precios distintos cambia el precio al elegir: la línea solo sale cuando el precio es uno.
    $app->hooks()->slot('product.price.after', static function ($product = null) use ($app): string {
        if (!is_array($product) || !isset($product['price']) || !is_array($product['price']) || !empty($product['price']['from'])) {
            return '';
        }
        $detail = \Aifec\Modules\Catalog\PriceDetail::of($app, $product['price']);
        if ($detail === null || $detail['gross'] <= 0) {
            return '';
        }

        return '<p class="af-small af-muted" data-af-price-detail>' . e(\Aifec\Modules\Catalog\PriceDetail::line($app, $detail)) . '</p>';
    }, 5);
    $app->hooks()->filter('home.sections', [HomeSections::class, 'add'], 20);
    $app->hooks()->filter('menu.items', [MenuItems::class, 'add'], 20);

    // El panel: órdenes del buscador (Ctrl+K), avisos del escritorio y recálculo de direcciones al cambiar un prefijo en
    // los ajustes. Van aquí y no en las rutas porque también tienen que oírse desde la consola (`setting:set`).
    $app->hooks()->filter('admin.palette', [TaxonomyHooks::class, 'palette']);
    $app->hooks()->filter('admin.palette', [ProductHooks::class, 'palette']);
    $app->hooks()->filter('admin.status', [ProductAdmin::class, 'status']);
    $app->hooks()->on('settings.saved', [TaxonomyHooks::class, 'settingsSaved']);

    // Comprar desde la tarjeta de un listado (elegir combinación y unidades). Su hoja va DENTRO de la página (un kilobyte,
    // sin otra petición y sin parpadeo) y su guion, diferido, al final. Con el ajuste apagado no sale nada.
    $app->hooks()->slot('head', static function () use ($app): string {
        return ProductData::cardOptions($app) ? Variants::cardStyle() : '';
    });
    $app->hooks()->slot('body.end', static function () use ($app): string {
        return ProductData::cardOptions($app) ? '<script src="' . e($app->url()->asset('m', 'catalog', 'card-options.js')) . '" defer></script>' : '';
    });
    // P100: la hoja de las piezas comunes (confianza, relacionados, envío gratis, ancho de la descripción) solo en la ficha, el
    // carrito y la portada, y el guion del «Seguir comprando» solo en el listado de una categoría y en el carrito.
    // Va aquí y no en una clase aparte: es un fichero menos que cargar en cada página (presupuesto de ficheros de la ficha).
    $app->hooks()->slot('head', static function ($page = null) use ($app): string {
        $type = is_array($page) && isset($page['type']) && is_string($page['type']) ? $page['type'] : '';
        $out = '';
        if (in_array($type, ['product', 'cart', 'home'], true)) {
            $file = __DIR__ . '/assets/store.css';
            $css = is_file($file) ? trim((string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file))) : '';
            // Va dentro de <style>: nada que pueda cerrarlo.
            if ($css !== '' && stripos($css, '</style') === false) {
                $out .= '<style data-af-store-style>' . str_replace(["\r\n", "\n"], '', $css) . '</style>';
            }
        }
        if (in_array($type, ['category', 'cart'], true)) {
            $out .= '<script src="' . e($app->url()->asset('m', 'catalog', 'continue.js')) . '" defer></script>';
        }

        return $out;
    });
    // Encender o apagar esa compra cambia todas las páginas con tarjetas que la caché tenga guardadas.
    $app->hooks()->on('settings.saved', static function (string $module, array $keys, App $application): void {
        if (array_intersect(['catalog.card_options', 'catalog.card_options_max'], $keys) !== []) {
            $application->invalidate(['catalog']);
        }
    });
};
