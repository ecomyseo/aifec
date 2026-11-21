<?php
/**
 * AIFEC — carrito UCP y conversión a checkout con el mismo contenido.
 * @author Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);
namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class UcpCarts
{
    private $app;
    public function __construct(App $app) { $this->app = $app; }
    public function create(Request $request, array $params): Response { return $this->convert((new UcpController($this->app))->create($request, $params, 'cart')); }
    public function show(Request $request, array $params): Response { $this->check($request, $params); return $this->convert((new UcpController($this->app))->show($request, $params)); }
    public function update(Request $request, array $params): Response { $this->check($request, $params); return $this->convert((new UcpController($this->app))->update($request, $params)); }
    public function cancel(Request $request, array $params): Response { $this->check($request, $params); return $this->convert((new UcpController($this->app))->cancel($request, $params)); }

    private function check(Request $request, array $params): void
    {
        $row = (new UcpController($this->app))->load($params, $request);
        if ($row['kind'] !== 'cart') {
            throw new HttpError(410, 'This cart has been converted to checkout');
        }
    }

    private function convert(Response $response): Response
    {
        $body = json_decode($response->body, true);
        if (is_array($body) && isset($body['id'], $body['line_items'])) {
            unset($body['status'], $body['fulfillment'], $body['payment']);
            $body['ucp']['capabilities'] = [Ucp::CART => [['version' => Ucp::version($this->app)]], Ucp::DISCOUNT => [['version' => Ucp::version($this->app)]]];
            $response->body = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $response;
    }
}
