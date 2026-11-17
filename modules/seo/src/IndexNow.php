<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Seo;

use Aifec\Http\HttpRequest;
use Aifec\Kernel\App;

/**
 * IndexNow: avisar a Bing (y al resto de buscadores del protocolo) de las fichas de producto, en vez de esperar a que las
 * descubran. ChatGPT se apoya en parte en el índice de Bing para buscar; sin esto contestaba «Cache miss» con la tienda
 * recién abierta (17/11/2026). La clave se publica en /indexnow.txt, en la raíz, para que valga para todas las direcciones.
 */
final class IndexNow
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** Lo más que admite el protocolo en un envío. */
    public const MAX_URLS = 10000;

    /** La clave de la tienda, o '' si no hay; con $create, se crea y se guarda si falta. */
    public static function key(App $app, bool $create = false): string
    {
        $key = trim($app->settings()->str('seo.indexnow_key', ''));
        if ($key === '' && $create) {
            $key = bin2hex(random_bytes(16));
            $app->settings()->set('seo.indexnow_key', $key);
        }

        return preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key) === 1 ? $key : '';
    }

    /** Dónde se publica la clave. */
    public static function keyLocation(App $app): string
    {
        return $app->url()->abs('/indexnow.txt', [], '');
    }

    /**
     * Las direcciones de las fichas de producto, en todos los idiomas: las mismas del mapa del sitio de productos.
     *
     * @return string[]
     */
    public static function productUrls(App $app): array
    {
        $sitemap = new Sitemap($app);
        $urls = [];
        for ($n = 1, $parts = $sitemap->parts('products'); $n <= $parts; $n++) {
            $xml = (string) $sitemap->part('products', $n);
            if (preg_match_all('#<loc>([^<]+)</loc>#', $xml, $m) > 0) {
                foreach ($m[1] as $loc) {
                    $urls[] = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            if (preg_match_all('#hreflang="[^"]*" href="([^"]+)"#', $xml, $m) > 0) {
                foreach ($m[1] as $loc) {
                    $urls[] = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS);
    }

    /**
     * Manda las direcciones. Devuelve el código de respuesta (200 o 202 = recibido) y cuántas se mandaron.
     *
     * @param string[] $urls
     *
     * @return array{status:int,count:int}
     */
    public static function submit(App $app, array $urls): array
    {
        $urls = array_slice(array_values(array_unique(array_filter($urls, 'is_string'))), 0, self::MAX_URLS);
        if ($urls === []) {
            return ['status' => 0, 'count' => 0];
        }
        $key = self::key($app, true);
        $host = (string) parse_url($app->url()->abs('/', [], ''), PHP_URL_HOST);
        $response = $app->http()->send(HttpRequest::postJson(self::ENDPOINT, [
            'host' => $host,
            'key' => $key,
            'keyLocation' => self::keyLocation($app),
            'urlList' => $urls,
        ], ['Content-Type' => 'application/json; charset=utf-8'], 30.0));

        return ['status' => (int) $response->status, 'count' => count($urls)];
    }
}
