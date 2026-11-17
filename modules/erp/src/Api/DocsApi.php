<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Erp\OpenApi;
use Aifec\Modules\Erp\Routes;

/**
 * Las dos páginas públicas de la API: el documento OpenAPI y una página de ayuda con ejemplos de curl.
 */
final class DocsApi
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function openapi(Request $request, array $params): Response
    {
        $response = Response::json(OpenApi::build($this->app));
        $response->headers['Cache-Control'] = 'public, max-age=300';

        return $response;
    }

    /** @param array<string,string> $params */
    public function help(Request $request, array $params): Response
    {
        $base = $this->app->url()->origin() . $this->app->url()->plain(Routes::PREFIX);
        $rows = '';
        foreach (Routes::table($this->app) as $route) {
            $scope = $route['scope'] === null ? t('public') : (string) $route['scope'];
            $rows .= '<tr><td><code>' . e((string) $route['method']) . '</code></td><td><code>' . e(Routes::openapiPath((string) $route['path'])) . '</code></td><td>'
                . e(t((string) $route['summary'])) . '</td><td><code>' . e($scope) . '</code></td></tr>';
        }
        $html = '<!doctype html><html lang="' . e($this->app->lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . e(t('ERP API')) . '</title></head><body style="font-family:system-ui,sans-serif;max-width:60rem;margin:2rem auto;padding:0 1rem;line-height:1.5">'
            . '<h1>' . e(t('ERP API')) . '</h1>'
            . '<p>' . e(t('Connect any ERP with a key from the panel (ERP, Connections). Amounts are integers in cents and also decimal text. Dates are ISO 8601 in UTC.')) . '</p>'
            . '<p><a href="' . e($base . '/openapi.json') . '">openapi.json</a></p>'
            . '<h2>' . e(t('First call')) . '</h2><pre style="background:#f4f4f4;padding:1rem;overflow:auto">curl -H "Authorization: Bearer YOUR_KEY" "' . e($base) . '/orders?limit=10"</pre>'
            . '<h2>' . e(t('Confirm an order')) . '</h2><pre style="background:#f4f4f4;padding:1rem;overflow:auto">curl -X POST -H "Authorization: Bearer YOUR_KEY" -H "Idempotency-Key: order-0001-ack" \\
  -H "Content-Type: application/json" -d \'{"external_id":"SO-1001"}\' "' . e($base) . '/orders/ORDER_NUMBER/ack"</pre>'
            . '<h2>' . e(t('Update stock')) . '</h2><pre style="background:#f4f4f4;padding:1rem;overflow:auto">curl -X POST -H "Authorization: Bearer YOUR_KEY" -H "Content-Type: application/json" \\
  -d \'{"items":[{"sku":"A-1","stock":12},{"sku":"B-2","delta":-3}]}\' "' . e($base) . '/stock"</pre>'
            . '<h2>' . e(t('Routes')) . '</h2><table border="1" cellpadding="6" style="border-collapse:collapse"><thead><tr><th>' . e(t('Method')) . '</th><th>' . e(t('Path')) . '</th><th>'
            . e(t('What it does')) . '</th><th>' . e(t('Scope')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></body></html>';
        $response = Response::html($html);
        $response->headers['Cache-Control'] = 'public, max-age=300';

        return $response;
    }
}
