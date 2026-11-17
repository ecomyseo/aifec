<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Util\Csv;

/**
 * «Ventas › Informes» (/admin/reports) y sus exportaciones CSV. Permiso: ver pedidos (orders.view). Solo lee: no cambia nada
 * ni manda nada a nadie. Las cifras, en ReportsAdmin.
 */
final class ReportsController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.view');
        $period = $this->period($request);
        $data = ReportsAdmin::data($app, $period);

        return Admin::page($app, 'orders::admin/reports', $data + [
            'period' => $period,
            'query' => ['period' => $period['key']] + ($period['key'] === 'custom' ? ['from' => $period['from'], 'to' => $period['to']] : []),
        ], [
            'title' => t('Reports'),
            'crumbs' => [[t('Orders'), $app->url()->plain('/admin/orders')]],
            'active' => '/admin/reports',
            'help' => Admin::helpText("Only paid orders count, by the day they were paid, in the time zone of the shop. Test orders do not count.\nAmounts in different currencies are never added together: each currency goes on its own line.\nA period longer than two years is cut to the last two years."),
        ]);
    }

    /** @param array<string,string> $params */
    public function export(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('orders.view');
        $table = $request->str('table');
        $period = $this->period($request);
        $csv = in_array($table, ReportsAdmin::TABLES, true) ? ReportsAdmin::csv($app, $table, $period) : null;
        if ($csv === null) {
            throw HttpError::notFound();
        }
        Admin::audit($app, 'export', 'reports', $table, $period['from'] . ' / ' . $period['to']);
        $response = Response::text("\xEF\xBB\xBF" . Csv::toString($csv[0], $csv[1], ';'), 200, 'text/csv');
        $response->headers['Content-Disposition'] = 'attachment; filename="report-' . $table . '-' . $period['from'] . '-' . $period['to'] . '.csv"';
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    /**
     * @return array{key:string,from:string,to:string,start:string,end:string,days:int,clipped:bool,invalid:bool}
     */
    private function period(Request $request): array
    {
        return ReportsAdmin::period($this->app, $request->str('period', '30d'), $request->str('from'), $request->str('to'));
    }
}
