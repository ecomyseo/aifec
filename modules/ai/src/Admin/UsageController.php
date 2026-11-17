<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Ai\AiAdmin;
use Aifec\Modules\Ai\Usage;

/**
 * La pantalla «AI usage»: gasto estimado de los últimos 30 días por día y por tarea, en tabla y en una gráfica que dibuja el servidor.
 */
final class UsageController
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
        $app->auth()->requireCap('ai.view');
        $days = Usage::byDay($app, 30);
        $tasks = Usage::byTask($app, 30);
        foreach ($tasks as $i => $row) {
            $tasks[$i]['label'] = self::taskLabel((string) $row['task']);
        }
        $total = 0;
        $calls = 0;
        foreach ($days as $day) {
            $total += (int) $day['cost_micro'];
            $calls += (int) $day['calls'];
        }

        return Admin::page($app, 'ai::admin/usage', [
            'days' => array_reverse($days),
            'tasks' => $tasks,
            'chart' => Usage::chart($app, $days),
            'spentToday' => Usage::amount($app, Usage::spentMicro($app)),
            'limit' => Usage::amount($app, Usage::limitMicro($app)),
            'spentMonth' => Usage::amount($app, Usage::spentMonthMicro($app)),
            'monthLimit' => Usage::limitMonthMicro($app) > 0 ? Usage::amount($app, Usage::limitMonthMicro($app)) : t('No monthly cap'),
            'tokensToday' => Usage::tokens($app),
            'tokenLimit' => Usage::limitTokens($app),
            'limitReason' => Usage::limitReason($app),
            'total' => Usage::amount($app, $total),
            'calls' => $calls,
            'pricesMissing' => AiAdmin::pricesMissing($app),
            'settingsUrl' => $app->auth()->can('ai.edit') ? $app->url()->plain('/admin/settings/ai') : '',
            'cents' => static function (int $micro) use ($app): string {
                return Usage::amount($app, $micro);
            },
        ], [
            'title' => t('AI usage'),
            'active' => '/admin/ai/usage',
            'help' => Admin::helpText("The «AI» menu is your writing helper: it drafts texts for you to approve and never talks to customers. The chat that answers customers is «Assistant»; «Copilot» does tasks in this panel for you.
The cost is an estimate: tokens used times the price per million tokens you set in the AI settings.\nThe day is counted in UTC. The daily cap stops the AI when the estimate of the day reaches it."),
        ]);
    }

    /**
     * Lo que hace cada tarea, con palabras del panel (14/11/2026: «assistant.reply», «search.understand» no los entendía la dueña).
     * Una tarea que no está aquí (la de un plugin) sale con su código.
     */
    public static function taskLabel(string $task): string
    {
        $labels = [
            'assistant.reply' => t('Assistant: replies to customers'),
            'connection.test' => t('AI connection test'),
            'search.understand' => t('Search: understanding what is searched'),
            'search.meaning' => t('Search by meaning'),
            'search.expand' => t('Search: extra words for each product'),
            'copilot.understand' => t('Copilot: understanding your requests'),
            'catalog.product_fill' => t('Products: filling in data'),
            'catalog.variants_text' => t('Products: texts of the variants'),
            'product.description' => t('Product description'),
            'product.summary' => t('Product summary'),
            'product.facts' => t('Product key facts'),
            'product.faqs' => t('Product questions and answers'),
            'product.meta_title' => t('Title for search engines'),
            'product.meta_description' => t('Description for search engines'),
            'product.translate' => t('Product translation'),
            'page.meta_title' => t('Page: title for search engines'),
            'page.meta_description' => t('Page: description for search engines'),
            'media.alt' => t('Image descriptions'),
            'reviews.ask' => t('Reviews'),
            'theme.palette' => t('Theme colours'),
            'semantic.feeds' => t('Product feeds'),
        ];
        if (isset($labels[$task])) {
            return $labels[$task];
        }

        return strpos($task, 'translations.') === 0 ? t('Translations') : $task;
    }
}
