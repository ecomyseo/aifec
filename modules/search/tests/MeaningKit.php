<?php
/**
 * AIFEC — ayudas de las pruebas del buscador por significado. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   meaningApp(array $settings = [], array $more = []): array
 *       [App, FakeAi]: el sitio con el buscador, el catálogo y una IA de mentira (más los módulos que se pidan).
 *   meaningShop(App $app): array
 *       La tienda de la demo: sillas de oficina, flexos y un cojín lumbar; ya indexada. Devuelve los identificadores por clave
 *       (ergo, basica, blanca, cojin, flexo_blanco, flexo_negro, flexo_gris) y, bajo «cat», los de las categorías. Ningún texto
 *       lleva «espalda», «lámpara» ni «escritorio»: con esas palabras lo literal no encuentra nada, como en la demo real.
 *   meaningVectors(App $app, FakeAi $fake, array $ids): void
 *       Pone a cada producto un vector de tres dimensiones con parecidos conocidos con la consulta «algo para la espalda»
 *       (cojín 0,99; silla ergonómica 0,75; silla premium de mentira 0,50; el resto 0) y se lo da también a esa consulta.
 *   meaningCarriers(App $app): void
 *       Dos transportistas de prueba con tarifa para la península: Correos (plazo escrito, gratis desde 60 €) y MRW (un día).
 *   meaningNames(array $products): array
 *       Los nombres de una lista de productos de ask(), ordenados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Search\Vector;

require_once __DIR__ . '/helpers.php';

if (!defined('MEANING_Q')) {
    /** La frase de la demo: ninguna de sus palabras está en el catálogo. */
    define('MEANING_Q', 'algo para la espalda');
}

if (!function_exists('meaningApp')) {
    /**
     * @param array<string,mixed> $settings
     * @param string[]            $more
     *
     * @return array{0:App,1:FakeAi}
     */
    function meaningApp(array $settings = [], array $more = []): array
    {
        $app = searchApp($settings, $more);
        $fake = new FakeAi();
        $app->bind('ai', static function () use ($fake): FakeAi {
            return $fake;
        });

        return [$app, $fake];
    }

    /**
     * @return array<string,mixed>
     */
    function meaningShop(App $app): array
    {
        $oficina = aifecCategory($app, 'Sillas de oficina');
        $flexos = aifecCategory($app, 'Flexos');
        $accesorios = aifecCategory($app, 'Accesorios');
        aifecAttribute($app, 'color', 'Color', ['negro' => 'Negro', 'blanco' => 'Blanco', 'gris' => 'Gris']);

        $ids = [];
        $ids['ergo'] = aifecProduct($app, [
            'name' => 'Silla de oficina ergonómica', 'price' => 15999, 'categories' => [$oficina], 'attributes' => ['color' => 'negro'], 'sales_count' => 9, 'images' => 1,
            'summary' => '<p>Una silla pensada para pasar muchas horas sentado: asiento ancho, brazos regulables, ruedas silenciosas, mecanismo basculante '
                . 'con cuatro posiciones y un tapizado transpirable que se mantiene fresco en verano y abrigado en invierno, con garantía de cinco años.</p>',
        ]);
        $ids['basica'] = aifecProduct($app, ['name' => 'Silla de oficina básica', 'price' => 9900, 'categories' => [$oficina], 'attributes' => ['color' => 'negro']]);
        $ids['blanca'] = aifecProduct($app, ['name' => 'Silla de oficina blanca', 'price' => 12000, 'categories' => [$oficina], 'attributes' => ['color' => 'blanco']]);
        $ids['cojin'] = aifecProduct($app, [
            'name' => 'Cojín lumbar', 'price' => 2500, 'categories' => [$accesorios], 'summary' => 'Accesorio blando para sentarse con comodidad',
        ]);
        $ids['flexo_blanco'] = aifecProduct($app, ['name' => 'Flexo LED blanco', 'price' => 3500, 'categories' => [$flexos], 'attributes' => ['color' => 'blanco']]);
        $ids['flexo_negro'] = aifecProduct($app, ['name' => 'Flexo LED negro', 'price' => 4500, 'categories' => [$flexos], 'attributes' => ['color' => 'negro']]);
        $ids['flexo_gris'] = aifecProduct($app, ['name' => 'Flexo articulado', 'price' => 5200, 'categories' => [$flexos], 'attributes' => ['color' => 'gris']]);
        searchService($app)->reindexNow();
        $ids['cat'] = ['oficina' => $oficina, 'flexos' => $flexos, 'accesorios' => $accesorios];

        return $ids;
    }

    /**
     * @param array<string,mixed> $ids lo que devuelve meaningShop()
     */
    function meaningVectors(App $app, FakeAi $fake, array $ids): void
    {
        $vectors = [
            $ids['cojin'] => [0.9, 0.1, 0.0],
            $ids['ergo'] => [0.75, 0.66, 0.0],
            $ids['basica'] => [0.5, 0.5, 0.7],
        ];
        foreach (['ergo', 'basica', 'blanca', 'cojin', 'flexo_blanco', 'flexo_negro', 'flexo_gris'] as $key) {
            $vector = isset($vectors[$ids[$key]]) ? $vectors[$ids[$key]] : [0.0, 0.0, 1.0];
            $app->db()->run(
                "UPDATE {search_docs} SET vector = ? WHERE type = 'product' AND entity_id = ?",
                [base64_encode(Vector::pack(Vector::normalize($vector))), $ids[$key]]
            );
        }
        $fake->vectors['algo para la espalda'] = [1.0, 0.0, 0.0];
    }

    function meaningCarriers(App $app): void
    {
        require_once __DIR__ . '/../../shipping/tests/ShippingKit.php';
        $zone = shippingZoneId($app, 'Peninsula');
        $correos = shippingAddCarrier($app, 'correos', ['name' => 'Correos', 'delay' => '2-4 días', 'free_from' => 6000, 'position' => 1]);
        shippingAddRate($app, $correos, $zone, 'weight', 0, 0, 490);
        $mrw = shippingAddCarrier($app, 'mrw', ['name' => 'MRW', 'min_days' => 1, 'max_days' => 1, 'position' => 2]);
        shippingAddRate($app, $mrw, $zone, 'weight', 0, 0, 650);
    }

    /**
     * @param array<int,array<string,mixed>> $products
     *
     * @return string[]
     */
    function meaningNames(array $products): array
    {
        $names = array_map(static function (array $p): string {
            return (string) $p['name'];
        }, $products);
        sort($names);

        return $names;
    }
}
