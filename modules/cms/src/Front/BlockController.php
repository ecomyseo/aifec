<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Cms\Cms;

/**
 * GET /bloque/{id}: el HTML de UN bloque de una página o de una entrada, sin el resto de la página, para repintarlo
 * desde el navegador sin pedir la página entera (como la «Section Rendering API» de Shopify).
 *
 * El identificador es «p<página>-<n>» o «e<entrada>-<n>», con n la posición del bloque (desde 0) en el primer nivel.
 * 404 si la página no existe, no es pública (borrador, programada) o no tiene ese bloque. Nunca se indexa.
 */
final class BlockController
{
    /** Segundos que se guarda en la caché de página un bloque que no depende del visitante. */
    private const TTL = 600;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params id
     */
    public function show(Request $request, array $params): Response
    {
        $id = isset($params['id']) ? (string) $params['id'] : '';
        if (preg_match('/^([pe])(\d{1,10})-(\d{1,4})$/', $id, $m) !== 1 || !$this->app->has('cms')) {
            throw HttpError::notFound();
        }
        /** @var Cms $cms */
        $cms = $this->app->service('cms');
        $type = $m[1] === 'p' ? 'page' : 'post';
        $entityId = (int) $m[2];
        $content = $type === 'page' ? $cms->page($entityId) : $cms->post($entityId);
        $blocks = $content !== null && isset($content['blocks']) && is_array($content['blocks']) ? array_values($content['blocks']) : [];
        $index = (int) $m[3];
        if (!isset($blocks[$index]) || !is_array($blocks[$index])) {
            throw HttpError::notFound();
        }
        $block = $blocks[$index];
        $html = $cms->render([$block]);
        $response = Response::html($html)->header('X-Robots-Tag', 'noindex');
        if ($cms->blockCache()->hasDynamic([$block])) {
            // Un formulario o unas entradas: lo pinta cada visitante, no se guarda.
            return $response->header('Cache-Control', 'no-store, private');
        }

        return $response->cacheable(self::TTL, array_merge([$type . ':' . $entityId, 'cms'], Support::dependencyTags([$block])));
    }
}
