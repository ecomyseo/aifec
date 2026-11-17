<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/*
 * Ayudas de las pruebas del módulo de datos para máquinas. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   semanticApp(array $modules, array $settings): App     sitio de prueba con «semantic» y lo que se pida, arrancado
 *   semanticLd(string $html): array                       los bloques ld+json de una página, ya descodificados (falla si uno no es JSON)
 *   semanticFind(array $objects, string $type): ?array    el primer objeto de ese «@type»
 *   semanticFeed(App $app, string $file): string          el contenido de un feed ya generado, leído de su fichero
 *   semanticBuildFeeds(App $app): void                    ejecuta el trabajo que rehace los feeds
 */

use Aifec\Kernel\App;
use Aifec\Modules\Semantic\SemanticJobs;

require_once __DIR__ . '/../../catalog/tests/helpers.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';
require_once __DIR__ . '/../../shipping/tests/ShippingKit.php';

if (!function_exists('semanticApp')) {
    /**
     * @param string[]            $modules
     * @param array<string,mixed> $settings
     */
    function semanticApp(array $modules = ['semantic', 'catalog', 'media'], array $settings = []): App
    {
        $options = ['modules' => $modules];
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function semanticLd(string $html): array
    {
        $objects = [];
        if (preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m) > 0) {
            foreach ($m[1] as $json) {
                $data = json_decode($json, true);
                check(is_array($data), 'cada bloque ld+json es JSON válido: ' . substr($json, 0, 120));
                $objects[] = $data;
            }
        }

        return $objects;
    }

    /**
     * @param array<int,array<string,mixed>> $objects
     *
     * @return array<string,mixed>|null
     */
    function semanticFind(array $objects, string $type): ?array
    {
        foreach ($objects as $object) {
            if (isset($object['@type']) && $object['@type'] === $type) {
                return $object;
            }
        }

        return null;
    }

    function semanticBuildFeeds(App $app): void
    {
        SemanticJobs::feeds([], $app);
    }

    function semanticFeed(App $app, string $file): string
    {
        $path = $app->site()->path('feeds') . '/' . $file;
        check(is_file($path), 'el feed ' . $file . ' existe');

        return (string) file_get_contents($path);
    }

    /** Un producto con imagen, listo para salir en los feeds. */
    function semanticProduct(App $app, array $extra = []): int
    {
        return aifecProduct($app, $extra + ['name' => 'Silla de roble', 'slug' => 'silla', 'price' => 1999, 'images' => 2]);
    }
}
