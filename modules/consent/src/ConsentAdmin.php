<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Consent;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Util\Csv;

/**
 * El consentimiento en el panel: el informe por semanas, la exportación del registro, el inventario de cookies y la pieza del inicio.
 */
final class ConsentAdmin
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/consent: aceptación, rechazo y configuración por semana.
     *
     * @param array<string,string> $params
     */
    public function report(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('consent.view');
        $report = new ConsentReport($this->app);
        $actions = '';
        if ($this->app->auth()->can('consent.view')) {
            $actions = '<a class="af-btn" href="' . e($this->app->url()->route('admin.consent.export')) . '">' . Admin::icon('download') . e(t('Export the log')) . '</a>';
        }

        return Admin::page($this->app, 'consent::admin/report', ['weeks' => $report->weeks(12), 'last' => $report->lastDays(30)], [
            'title' => t('Cookie consent'),
            'active' => '/admin/consent',
            'actions' => $actions,
            'help' => Admin::helpText("Every time a visitor decides, one line is saved: a random number from their cookie (it does not say who they are), the version of the policy, the categories, the date and the language. Nothing else.\nThe lines are kept for 5 years.\nIf you change the policy version in the settings, everybody is asked again."),
        ]);
    }

    /**
     * GET /admin/consent/export.csv: todo el registro.
     *
     * @param array<string,string> $params
     */
    public function export(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('consent.view');
        $report = new ConsentReport($this->app);
        $rows = $report->exportRows();
        Admin::audit($this->app, 'export', 'consent_log', '', count($rows) . ' rows');
        $csv = Csv::toString(ConsentReport::exportHeader(), $rows);
        $response = Response::text($csv, 200, 'text/csv');
        $response->headers['Content-Disposition'] = 'attachment; filename="consent-log-' . gmdate('Ymd', $this->app->clock()->now()) . '.csv"';

        return $response;
    }

    /** La pieza del inicio: cuántos aceptan, rechazan o configuran (últimos 30 días). */
    public static function widget(App $app): string
    {
        $last = (new ConsentReport($app))->lastDays(30);

        return $app->view()->render('consent::admin/widget', ['last' => $last]);
    }

    /**
     * Las cookies de serie no se borran (son las que pone AIFEC por sí mismo).
     *
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        return !empty($row['builtin']) ? t('The cookies that AIFEC sets by itself cannot be deleted') : null;
    }
}
