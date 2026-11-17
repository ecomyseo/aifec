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

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Lo que piden los robots: /robots.txt, /sitemap.xml y los mapas por tipo. Se guardan una hora en la caché de página; al
 * cambiar los ajustes o guardar un contenido en el panel se vacían (hooks.php).
 */
final class SeoController
{
    /** Segundos que se guarda cada respuesta. */
    private const TTL = 3600;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function robots(Request $request, array $params): Response
    {
        return Response::text(Robots::build($this->app), 200, 'text/plain')->cacheable(self::TTL, ['seo', 'settings']);
    }

    /**
     * La clave de IndexNow, en texto plano y sin redirecciones; 404 si la tienda no tiene.
     *
     * @param array<string,string> $params
     */
    public function indexNowKey(Request $request, array $params): Response
    {
        $key = IndexNow::key($this->app);
        if ($key === '') {
            throw HttpError::notFound();
        }

        return Response::text($key, 200, 'text/plain');
    }

    /** @param array<string,string> $params */
    public function sitemapIndex(Request $request, array $params): Response
    {
        $xml = (new Sitemap($this->app))->index();

        return Response::text($xml, 200, 'application/xml')->cacheable(self::TTL, ['catalog', 'cms', 'seo', 'settings']);
    }

    /** @param array<string,string> $params type, n */
    public function sitemapPart(Request $request, array $params): Response
    {
        $xml = (new Sitemap($this->app))->part(isset($params['type']) ? (string) $params['type'] : '', isset($params['n']) ? (int) $params['n'] : 0);
        if ($xml === null) {
            throw HttpError::notFound();
        }

        return Response::text($xml, 200, 'application/xml')->cacheable(self::TTL, ['catalog', 'cms', 'seo', 'settings']);
    }
}
