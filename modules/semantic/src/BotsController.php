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
 * La pantalla «Bots and assistants» del panel: qué robots visitan la tienda, qué leen, desde qué asistentes llegan personas y
 * cuánto venden. Lee lo que ya sumó la tarea «semantic.visits».
 */
final class BotsController
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
        $app->auth()->requireCap('semantic.view');
        $report = BotsReport::build($app);
        $chart = BotsChart::svg($report['days'], t('Daily visits of robots and assistants, last 30 days'), t('Robots'), t('From assistants'));

        return Admin::page($app, 'semantic::admin/bots', [
            'report' => $report,
            'chart' => $chart,
            'classes' => BotsReport::classLabels(),
            'families' => BotsReport::familyLabels(),
            'robotsUrl' => $app->modules()->isLoaded('seo') ? $app->url()->plain('/admin/settings/seo') : '',
            'money' => static function (int $cents) use ($app, $report): string {
                return $app->i18n()->money($cents, $report['currency']);
            },
        ], [
            'title' => t('Bots and assistants'),
            'active' => '/admin/semantic/bots',
            'help' => Admin::helpText("«Robots and assistants» counts the search engines and AI assistants that visit your shop. «AIFEC audit» checks that they can read it well.\n" . 'The visits are read once an hour from the shop log: only the robots we know and the people who arrive from an assistant are counted.')
                . Admin::helpText('No IP address and no browser identification is stored.')
                . Admin::helpText('Orders are attributed when the visitor arrived from an assistant and then bought in the same session.'),
        ]);
    }
}
