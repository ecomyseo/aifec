<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La ruta de las descargas de lo comprado: GET /descarga/{token} (nombre «order.download»). La ficha es la del enlace
 * del correo; sin ella, caducada o agotada, el cliente recibe un 404 o un 403 sin más explicación.
 */
final class OrderController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params token
     */
    public function download(Request $request, array $params): Response
    {
        return $this->app->service('orders')->downloads()->serve(isset($params['token']) ? (string) $params['token'] : '');
    }
}
