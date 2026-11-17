<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * El documento OpenAPI (/api/openapi.json) y la página para quien programa (/api): qué es la API, cómo se compra con ella y
 * ejemplos con curl.
 */
final class DocsController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function openapi(Request $request, array $params): Response
    {
        $response = Response::json(OpenApi::build($this->app));
        $response->headers['Cache-Control'] = 'public, max-age=300';
        $response->headers['Access-Control-Allow-Origin'] = '*';
        unset($response->headers['Pragma'], $response->headers['Expires']);

        return $response;
    }

    /**
     * @param array<string,string> $params
     */
    public function toOpenapi(Request $request, array $params): Response
    {
        return Response::redirect($this->app->url()->to('/api/openapi.json', [], ''), 301);
    }

    /**
     * @param array<string,string> $params
     */
    public function toPage(Request $request, array $params): Response
    {
        return Response::redirect($this->app->url()->to('/api', [], ''), 301);
    }

    /**
     * @param array<string,string> $params
     */
    public function page(Request $request, array $params): Response
    {
        $app = $this->app;
        $url = $app->url();
        $rows = [];
        foreach (Routes::table($app) as $row) {
            if (!empty($row['root'])) {
                continue;
            }
            $rows[] = ['method' => (string) $row['method'], 'path' => Routes::openapiPath((string) $row['path']), 'summary' => (string) $row['summary'], 'kind' => (string) $row['kind']];
        }
        $meta = [
            'type' => 'page',
            'title' => t('API for programs and assistants'),
            'description' => t('Read the catalogue, prepare a cart and place an order from a program or an assistant.'),
            'canonical' => $url->abs('/api', [], ''),
            'breadcrumbs' => [],
            'body_class' => 'af-page-api',
        ];

        return $app->render('api::front/docs', [
            'page' => $meta,
            'rows' => $rows,
            'base' => $url->abs('/api/v1', [], ''),
            'openapi' => $url->abs('/api/openapi.json', [], ''),
            'guide' => explode("\n", OpenApi::guide()),
            'orders' => Checkout::enabled($app),
            // Con el código del comprador (lo normal), hacer un pedido no necesita clave: la página lo dice así, y que el
            // asistente no paga nada. Sin eso las IA contestan «no puedo comprar por ti» aunque sí puedan (16/11/2026).
            'by_code' => \Aifec\Modules\Orders\Checkout\BuyerCode::mode($app) === \Aifec\Modules\Orders\Checkout\BuyerCode::MODE_CODE,
        ]);
    }
}
