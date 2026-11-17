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

use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Erp\ApiException;
use Aifec\Modules\Erp\Present;
use Aifec\Modules\Invoices\Invoices;

/**
 * Facturas para el ERP (solo si el módulo de facturas está cargado): lista, ficha y PDF.
 */
final class InvoicesApi extends ApiBase
{
    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $limit = $this->limit($request);
        $after = $this->cursor($request);
        $ids = array_map('intval', $this->app->db()->column('SELECT id FROM {invoices} WHERE id > ? ORDER BY id ASC LIMIT ?', [$after, $limit + 1]));

        return $this->page($request, $ids, $limit, $after, function (int $id): ?array {
            $invoice = $this->invoices()->find($id);

            return $invoice === null ? null : Present::invoice($this->app, $invoice);
        });
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        return $this->ok(Present::invoice($this->app, $this->find((string) $params['number'])));
    }

    /** @param array<string,string> $params */
    public function pdf(Request $request, array $params): Response
    {
        $invoice = $this->find((string) $params['number']);
        try {
            $path = $this->invoices()->pdf((int) $invoice['id']);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'erp.invoice.pdf');
            throw new ApiException(500, 'pdf_unavailable', 'The PDF could not be made');
        }

        return Response::file($path, (string) $invoice['full_number'] . '.pdf', 'application/pdf');
    }

    /**
     * @return array<string,mixed>
     */
    private function find(string $number): array
    {
        $invoice = $this->invoices()->findByNumber($number);
        if ($invoice === null) {
            throw ApiException::notFound('invoice_not_found', 'There is no invoice with that number');
        }

        return $invoice;
    }

    private function invoices(): Invoices
    {
        $invoices = $this->app->service('invoices');
        if (!$invoices instanceof Invoices) {
            throw new \LogicException('The invoices service is not available');
        }

        return $invoices;
    }
}
