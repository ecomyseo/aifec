<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La pantalla «AIFEC audit» del panel: la nota del sitio, sus comprobaciones, cómo van los productos y los 50 que peor
 * nota tienen. El cálculo de los productos no se hace aquí: lo hace el trabajo en segundo plano y esta pantalla lee el resultado.
 */
final class AuditController
{
    /** Productos que salen en la tabla de los peores. */
    private const WORST = 50;

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
        $app->auth()->requireCap('semantic.view');
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        $site = Audit::site($semantic);

        $missing = $request->str('missing');
        if (!isset(Audit::PRODUCT[$missing])) {
            $missing = '';
        }
        $labels = Audit::labels();
        $rows = [];
        $worst = AuditStore::worst($app, self::WORST, $missing);
        $names = $this->names(array_map(static function (array $row): int {
            return $row['id'];
        }, $worst));
        foreach ($worst as $row) {
            $first = isset($row['fails'][0]) ? $row['fails'][0] : '';
            $rows[] = [
                'id' => $row['id'],
                'name' => isset($names[$row['id']]) ? $names[$row['id']] : '#' . $row['id'],
                'score' => $row['score'],
                'url' => $app->url()->plain('/admin/r/products/' . $row['id']),
                'first' => $first !== '' && isset($labels[$first]) ? $labels[$first] : '',
                'fix' => $first !== '' ? $this->fixUrl($first, $row['id']) : '',
                'more' => max(0, count($row['fails']) - 1),
            ];
        }
        $options = [];
        foreach (array_keys(Audit::PRODUCT) as $id) {
            $options[$id] = $labels[$id];
        }

        $run = '<form method="post" action="' . e($app->url()->plain('/admin/semantic/audit/run')) . '" class="af-inline">'
            . Admin::tokenField($app)
            . '<button type="submit" class="af-btn">' . Admin::icon('refresh') . e(t('Measure the products now')) . '</button></form>';

        return Admin::page($app, 'semantic::admin/audit', [
            'site' => $site,
            'measured' => AuditStore::count($app),
            'last' => AuditStore::lastChecked($app),
            'bands' => AuditStore::distribution($app),
            'rows' => $rows,
            'options' => $options,
            'missing' => $missing,
            'action' => $app->url()->plain('/admin/semantic/audit'),
        ], [
            'title' => t('AIFEC audit'),
            'active' => '/admin/semantic/audit',
            'actions' => $run,
            'help' => Admin::helpText("«AIFEC audit» checks that search engines and AI assistants can read your shop well. «Robots and assistants» counts the ones that visit it.\nThe score goes from 0 to 100: each check that is not met takes away its weight.")
                . Admin::helpText('The site checks are measured every time you open this page; the products are measured in the background once a day and again each time one is saved.')
                . Admin::helpText('Open a check to go straight to where it is fixed.'),
        ]);
    }

    /** Pone en cola el cálculo de todos los productos. */
    public function run(Request $request, array $params): Response
    {
        $app = $this->app;
        $app->auth()->requireCap('semantic.view');
        if (AuditJobs::queueAll($app)) {
            Admin::flash($app, 'ok', t('The products will be measured in a few minutes.'));
        } else {
            Admin::flash($app, 'warn', t('The job queue is not active, so the products are measured only by the daily task.'));
        }

        return Response::redirect($app->url()->plain('/admin/semantic/audit') . '#tab=smproducts');
    }

    /**
     * Los nombres de los productos en el idioma principal.
     *
     * @param int[] $ids
     *
     * @return array<int,string>
     */
    private function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $params = [$this->app->defaultLang()];
        $in = $this->app->db()->in($ids, $params);
        $out = [];
        foreach ($this->app->db()->select('SELECT product_id, name FROM {product_i18n} WHERE lang = ? AND product_id IN (' . $in . ')', $params) as $row) {
            $out[(int) $row['product_id']] = (string) $row['name'];
        }

        return $out;
    }

    private function fixUrl(string $check, int $productId): string
    {
        return $check === 'alt' ? $this->app->url()->plain('/admin/media') : $this->app->url()->plain('/admin/r/products/' . $productId);
    }
}
