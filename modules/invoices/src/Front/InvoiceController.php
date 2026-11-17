<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Invoices\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Invoices\Invoices;

/**
 * La descarga de una factura por la tienda: GET /factura/{número completo}/{ficha del pedido}.
 *
 * La ficha del pedido (la misma que da acceso a su página sin cuenta) es la que manda. Una factura que no existe y una ficha que no
 * es la de su pedido dan el mismo 404, así nadie averigua qué números existen. El PDF sale en línea, sin ninguna caché y con la
 * cabecera para que no se indexe.
 */
final class InvoiceController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params number y secret
     */
    public function download(Request $request, array $params): Response
    {
        /** @var Invoices $invoices */
        $invoices = $this->app->service('invoices');
        $invoice = $invoices->findByNumber(isset($params['number']) ? (string) $params['number'] : '');
        if ($invoice === null) {
            throw HttpError::notFound();
        }
        $number = $this->app->db()->value('SELECT number FROM {orders} WHERE id = ?', [(int) $invoice['order_id']]);
        $secret = isset($params['secret']) ? (string) $params['secret'] : '';
        if ($number === null || $this->app->service('orders')->findBySecret((string) $number, $secret) === null) {
            throw HttpError::notFound();
        }
        try {
            $path = $invoices->pdf((int) $invoice['id']);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'invoices.pdf');
            throw HttpError::notFound();
        }
        $response = Response::file($path, (string) $invoice['full_number'] . '.pdf', 'application/pdf', true);
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }
}
