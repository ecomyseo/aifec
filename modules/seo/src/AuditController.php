<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Seo;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La pantalla «Auditoría SEO» del panel: la tabla de problemas con su número y los enlaces para arreglarlos.
 */
final class AuditController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('seo.view');
        $result = Audit::run($app);
        $checks = [];
        foreach ($result['checks'] as $check) {
            $check['label'] = Audit::label((string) $check['id']);
            $checks[] = $check;
        }
        $actions = $app->auth()->can('seo.edit')
            ? '<a class="af-btn" href="' . e($app->url()->plain('/admin/settings/seo')) . '">' . Admin::icon('settings') . e(t('SEO settings')) . '</a>'
            : '';

        return Admin::page($app, 'seo::admin/audit', [
            'checks' => $checks,
            'total' => $result['total'],
            'closed' => SeoAdmin::isClosed($app),
            'sitemap' => $app->url()->abs('/sitemap.xml', [], ''),
            'robots' => $app->url()->abs('/robots.txt', [], ''),
        ], [
            'title' => t('SEO audit'),
            'active' => '/admin/seo/audit',
            'actions' => $actions,
            'help' => Admin::helpText('The audit looks at the published products, categories, pages and posts in the main language of the shop.')
                . Admin::helpText('Open a problem to see some of the items and go straight to the one you want to fix.')
                . Admin::helpText('The result is kept for 10 minutes: after fixing something, wait a little before checking again.'),
        ]);
    }
}
