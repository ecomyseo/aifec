<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Invoices\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Table;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Invoices\InvoiceExport;
use Aifec\Modules\Invoices\Invoices;

/**
 * Las facturas en el panel: listado (con filtro por fechas, serie y tipo), descarga del PDF, exportación CSV para la gestoría y
 * emisión a mano de la factura de un pedido. No escribe en la tabla por su cuenta: todo pasa por el servicio «invoices», que es
 * quien reparte los números.
 */
final class InvoicesController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function invoices(): Invoices
    {
        /** @var Invoices $invoices */
        $invoices = $this->app->service('invoices');

        return $invoices;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $auth = $app->auth();
        $auth->requireCap('invoices.view');
        $table = new Table($app, $this->listConfig());
        $actions = '<a class="af-btn" href="' . e($app->url()->plain('/admin/invoices/export.csv', $request->query)) . '">' . Admin::icon('download') . e(t('Export CSV')) . '</a>';

        return Admin::page($app, 'invoices::admin/list', [
            'table' => $table->render($request),
            'canIssue' => $auth->can('invoices.edit'),
            'issueUrl' => $app->url()->plain('/admin/invoices/issue'),
        ], [
            'title' => t('Invoices'),
            'actions' => $actions,
            'active' => '/admin/invoices',
            'help' => Admin::helpText("Invoices are issued by themselves when an order is paid and credit notes when money is refunded (you can turn that off in the settings).\nThe numbers are consecutive per series and are never reused. Orders paid in test mode use the TEST series and do not take a real number.\nThe CSV has one row per invoice and tax type, with the amounts of credit notes in negative, ready for your accountant."),
        ]);
    }

    /** El mismo listado, con los mismos filtros, en CSV: una fila por factura y tipo de impuesto. */
    public function export(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('invoices.view');
        $table = new Table($app, $this->listConfig());
        $info = $table->query($request, true);
        Admin::audit($app, 'export', 'invoices', '', $info['total'] . ' rows');
        $response = Response::text(InvoiceExport::csv($app, $info['rows']), 200, 'text/csv');
        $response->headers['Content-Disposition'] = 'attachment; filename="invoices-' . gmdate('Ymd', $app->clock()->now()) . '.csv"';
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /** @param array<string,string> $params id */
    public function download(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('invoices.view');
        $invoice = $this->invoices()->find((int) $params['id']);
        if ($invoice === null) {
            throw HttpError::notFound();
        }
        $path = $this->invoices()->pdf((int) $invoice['id']);
        $response = Response::file($path, (string) $invoice['full_number'] . '.pdf', 'application/pdf');
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }

    /**
     * POST issue: emite a mano la factura de un pedido (por su número o su identificador) y vuelve al listado con un aviso.
     *
     * @param array<string,string> $params
     */
    public function issue(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('invoices.edit');
        $reference = $request->str('order');
        $back = Response::redirect($app->url()->plain('/admin/invoices'));
        $orderId = 0;
        if ($reference !== '') {
            $found = $app->db()->value('SELECT id FROM {orders} WHERE number = ?', [$reference]);
            if ($found === null && ctype_digit($reference)) {
                $found = $app->db()->value('SELECT id FROM {orders} WHERE id = ?', [(int) $reference]);
            }
            $orderId = $found !== null ? (int) $found : 0;
        }
        if ($orderId < 1) {
            Admin::flash($app, 'bad', t('There is no order with that number.'));

            return $back;
        }
        // Emitida desde la ficha del pedido: se vuelve a ella.
        if ($request->str('back') === 'order') {
            $back = Response::redirect($app->url()->plain('/admin/orders/' . $orderId));
        }
        try {
            $before = $this->invoices()->forOrder($orderId);
            $invoice = $this->invoices()->issue($orderId);
        } catch (\InvalidArgumentException $e) {
            Admin::flash($app, 'bad', t('An invoice cannot be issued for a cancelled order.'));

            return $back;
        }
        if ($before === []) {
            Admin::audit($app, 'issue', 'invoice', (string) $invoice['id'], 'Invoice issued by hand');
            Admin::flash($app, 'ok', t('Invoice {number} issued.', ['number' => (string) $invoice['full_number']]));
        } else {
            Admin::flash($app, 'info', t('The order already had invoice {number}.', ['number' => (string) $invoice['full_number']]));
        }

        return $back;
    }

    /**
     * @return array<string,mixed>
     */
    private function listConfig(): array
    {
        $app = $this->app;

        return [
            'from' => '{invoices} i INNER JOIN {orders} o ON o.id = i.order_id',
            'select' => 'i.id, i.full_number, i.type, i.series, i.issued_at, i.order_id, i.net, i.tax, i.total, i.currency, i.test, i.data, o.number AS order_number, o.email',
            'key' => 'id',
            'key_sql' => 'i.id',
            'search' => ['i.full_number', 'o.number', 'o.email'],
            'filters' => [
                'series' => ['label' => 'Series', 'column' => 'i.series', 'raw' => true, 'options' => static function (App $app): array {
                    $options = [];
                    foreach ($app->db()->column('SELECT DISTINCT series FROM {invoices} ORDER BY series') as $series) {
                        $options[(string) $series] = (string) $series;
                    }

                    return $options;
                }],
                'type' => ['label' => 'Type', 'column' => 'i.type', 'options' => ['invoice' => 'Invoice', 'credit' => 'Credit note']],
                'from' => ['label' => 'From', 'type' => 'date_from', 'column' => 'i.issued_at'],
                'to' => ['label' => 'To', 'type' => 'date_to', 'column' => 'i.issued_at'],
            ],
            'sort' => ['i.id', 'desc'],
            'per_page' => 25,
            'empty' => ['title' => 'No invoices yet', 'text' => 'Invoices show up here when an order is paid, or when you issue one by hand.'],
            'row_url' => static function (array $row) use ($app): string {
                return $app->url()->plain('/admin/invoices/' . (int) $row['id'] . '/pdf');
            },
            'columns' => [
                'full_number' => ['label' => 'Number', 'link' => true, 'sort' => 'i.id', 'render' => static function (array $row, App $app): string {
                    $html = '<span class="af-nowrap">' . e((string) $row['full_number']) . '</span>';
                    if (!empty($row['test'])) {
                        $html .= ' ' . Admin::badge(t('Test'), 'warn');
                    }

                    return $html;
                }],
                'type' => ['label' => 'Type', 'type' => 'badge', 'map' => ['invoice' => ['Invoice', 'ok'], 'credit' => ['Credit note', 'info']]],
                'issued_at' => ['label' => 'Date', 'type' => 'datetime', 'sort' => 'i.issued_at', 'hide_sm' => true],
                'order_number' => ['label' => 'Order', 'render' => static function (array $row, App $app): string {
                    return '<a class="af-nowrap" href="' . e($app->url()->plain('/admin/orders/' . (int) $row['order_id'])) . '">' . e((string) $row['order_number']) . '</a>';
                }],
                'customer' => ['label' => 'Customer', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    $data = json_decode((string) $row['data'], true);
                    $name = is_array($data) && isset($data['receiver']['name']) ? (string) $data['receiver']['name'] : '';

                    return ($name !== '' ? '<strong>' . e($name) . '</strong><br>' : '') . '<span class="af-small af-muted">' . e((string) $row['email']) . '</span>';
                }],
                'net' => ['label' => 'Taxable base', 'type' => 'money', 'currency_field' => 'currency', 'sort' => 'i.net', 'hide_sm' => true],
                'tax' => ['label' => 'Tax amount', 'type' => 'money', 'currency_field' => 'currency', 'sort' => 'i.tax', 'hide_sm' => true],
                'total' => ['label' => 'Total', 'type' => 'money', 'currency_field' => 'currency', 'sort' => 'i.total'],
            ],
        ];
    }
}
