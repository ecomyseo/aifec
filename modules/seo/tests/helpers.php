<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Ayudas de las pruebas del módulo SEO. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   seoApp(array $modules, array $settings, bool $dosIdiomas): App   sitio de prueba con «seo» (y lo que se pida), arrancado
 *   seoServe(App, string $uri): ?Response                            la copia de la caché de página, como la serviría el servidor
 *   seoBulkProducts(App, int $n): void                               $n productos publicados insertados directamente (para los tramos del mapa)
 *   seoCheck(array $audit, string $id): array                        una comprobación del resultado de la auditoría
 *   seoTitle(string $html): string                                   el texto de <title>
 *   seoGroupOf(string $robots, string $agent): array                  las reglas del grupo de robots.txt que nombra a ese agente
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../cms/tests/helpers.php';

if (!function_exists('seoApp')) {
    /**
     * @param string[]            $modules
     * @param array<string,mixed> $settings
     */
    function seoApp(array $modules = ['seo'], array $settings = [], bool $twoLangs = false): App
    {
        $settings = ($twoLangs ? ['i18n.languages' => ['es', 'en']] : []) + $settings;
        $options = ['modules' => $modules];
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    /** La copia de la caché de página de una dirección, tal como la serviría el servidor antes de arrancar la aplicación. */
    function seoServe(App $app, string $uri): ?Response
    {
        $request = Request::create('GET', $uri);
        $request->host = 'tienda.test';
        $request->secure = true;

        return PageCache::serve($app->site(), $request, $app->clock());
    }

    /** Productos publicados con su nombre y su dirección, insertados sin pasar por el catálogo (son muchos). */
    function seoBulkProducts(App $app, int $n): void
    {
        $db = $app->db();
        $now = $app->clock()->sql();
        $db->transaction(static function ($db) use ($n, $now): void {
            for ($i = 1; $i <= $n; $i++) {
                $id = $db->insert('products', ['status' => 'published', 'visibility' => 'everywhere', 'created_at' => $now, 'updated_at' => $now]);
                $db->insert('product_i18n', ['product_id' => $id, 'lang' => 'es', 'name' => 'Producto ' . $i]);
                $db->insert('slugs', ['lang' => 'es', 'path' => 'producto/producto-' . $i, 'type' => 'product', 'entity_id' => $id, 'canonical' => 1]);
            }
        });
    }

    /**
     * @param array{checks:array<int,array<string,mixed>>,total:int} $audit
     *
     * @return array<string,mixed>
     */
    function seoCheck(array $audit, string $id): array
    {
        foreach ($audit['checks'] as $check) {
            if ($check['id'] === $id) {
                return $check;
            }
        }
        throw new \LogicException('No check ' . $id . ' in the audit');
    }

    /**
     * Las líneas de un grupo de robots.txt que empieza en el «User-agent» dado: sus reglas, hasta el siguiente grupo.
     *
     * @return string[] líneas de regla (Allow, Disallow…) del grupo que contiene ese agente
     */
    function seoGroupOf(string $robots, string $agent): array
    {
        $groups = preg_split('/\n\s*\n/', trim($robots)) ?: [];
        foreach ($groups as $group) {
            $lines = array_map('trim', explode("\n", $group));
            if (in_array('User-agent: ' . $agent, $lines, true)) {
                return array_values(array_filter($lines, static function (string $l): bool {
                    return $l !== '' && $l[0] !== '#' && stripos($l, 'user-agent:') !== 0;
                }));
            }
        }

        return [];
    }

    function seoTitle(string $html): string
    {
        return preg_match('#<title>(.*?)</title>#s', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }
}
