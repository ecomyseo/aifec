<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Search;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La pantalla del buscador en el panel: las búsquedas sin resultado y las más frecuentes (7 o 30 días), con el botón «crear
 * sinónimo», y el estado del índice con su botón «Reindexar». Los sinónimos son un recurso aparte y los ajustes, los del módulo.
 */
final class SearchAdminController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function search(): Search
    {
        /** @var Search $search */
        $search = $this->app->service('search');

        return $search;
    }

    /**
     * @param array<string,string> $params
     */
    public function overview(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('search.view');
        $days = $request->int('days', 7) === 30 ? 30 : 7;
        $db = $this->app->db();
        $search = $this->search();

        $counts = [];
        foreach ($db->select('SELECT type, COUNT(*) AS n FROM {search_docs} GROUP BY type ORDER BY type') as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }
        $last = $db->value('SELECT MAX(indexed_at) FROM {search_docs}');
        $pending = 0;
        if ($db->tableExists('jobs')) {
            $pending = (int) $db->value("SELECT COUNT(*) FROM {jobs} WHERE type IN ('search.index', 'search.reindex', 'search.expand') AND status IN ('pending', 'running')");
        }

        return Admin::page($this->app, 'search::admin/overview', [
            'days' => $days,
            'missed' => $search->log()->top($days, true, 50),
            'popular' => $search->log()->top($days, false, 50),
            'filters' => $search->log()->filterStats($days),
            // Cómo acabaron las búsquedas de los últimos 30 días, y las que acabaron sin nada que enseñar más repetidas.
            'outcomes' => $search->log()->outcomeStats(30),
            'unanswered' => $search->log()->unanswered(30, 20),
            'meaningHint' => $this->meaningHint(),
            'synonyms' => trim($this->app->settings()->str('search.synonyms', '')),
            'counts' => $counts,
            'last' => $last !== null ? (string) $last : null,
            'pending' => $pending,
            'canEdit' => $this->app->auth()->can('search.edit'),
            'synonymUrl' => static function (App $app, string $phrase): string {
                return Admin::resourceUrl($app, 'synonyms', 'new', ['source' => $phrase]);
            },
        ], [
            'title' => t('Search'),
            'active' => '/admin/search',
            'help' => Admin::helpText("The searches that found nothing are the shop's best list of what customers want and cannot find.\nCreate a synonym when customers use a different word for something you do sell.\nThe log keeps only the day, the language, the words and how many times they were searched: never who searched."),
        ]);
    }

    /** ¿La IA está encendida y los vectores de significado apagados? Entonces hay una mejora a un clic. */
    private function meaningHint(): bool
    {
        if ($this->app->settings()->bool('search.vectors', false) || !$this->app->has('ai')) {
            return false;
        }
        try {
            $ai = $this->app->service('ai');

            return is_callable([$ai, 'enabled']) && (bool) $ai->enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * POST /admin/search/reindex
     *
     * @param array<string,string> $params
     */
    public function reindex(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('search.edit');
        $queued = $this->app->has('jobs');
        $result = $this->search()->reindex();
        Admin::audit($this->app, 'search.reindex', 'search', '', 'Search index rebuilt');
        if ($queued) {
            Admin::flash($this->app, 'ok', t('The index is being rebuilt in the background.'));
        } else {
            Admin::flash($this->app, 'ok', tn('{n} item reindexed', '{n} items reindexed', $result));
        }

        return Response::redirect($this->app->url()->plain('/admin/search') . '#tab=srindex');
    }
}
