<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations\Scopes;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Modules\Translations\AiGate;
use Aifec\Modules\Translations\Scope;

/**
 * Las páginas y las entradas del blog: título, extracto y textos para buscadores, que se escriben por el servicio público del módulo
 * de IA (propuestas que aplica el filtro «ai.apply» del módulo de contenidos). El cuerpo (los bloques) se cuenta pero la IA no lo
 * rellena: el módulo de contenidos no da todavía un servicio para guardarlo, así que se traduce en el editor de la página.
 */
final class PagesScope implements Scope
{
    /** Campos que la IA puede rellenar, en el orden en que se proponen (el título primero: crea la fila del idioma). */
    public const FIELDS = ['title', 'excerpt', 'meta_title', 'meta_description'];

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function code(): string
    {
        return 'pages';
    }

    public function label(): string
    {
        return t('Pages and posts');
    }

    public function describe(): string
    {
        return t('Title, excerpt and texts for search engines; the body is translated in the page editor');
    }

    public function entities(): array
    {
        return ['page', 'post'];
    }

    public function sourceLang(): string
    {
        return $this->app->defaultLang();
    }

    public function brief(): string
    {
        return 'Content pages and blog posts of an online shop: titles, excerpts and texts for search engines.';
    }

    public function blocked(string $lang): string
    {
        if ($lang === $this->app->defaultLang()) {
            return t('This is the main language: the other languages are translated from it');
        }

        return AiGate::installed($this->app) ? '' : t('It needs the AI module');
    }

    public function coverage(string $lang): array
    {
        $out = ['total' => 0, 'translated' => 0, 'proposed' => 0, 'missing' => 0];
        if ($lang === $this->app->defaultLang()) {
            return $out;
        }
        foreach (['page', 'post'] as $entity) {
            $rows = $this->rowsOf($entity, $lang);
            $pending = $this->pending($entity, $lang);
            foreach ($rows['source'] as $id => $source) {
                foreach (array_merge(self::FIELDS, ['body']) as $field) {
                    if (trim((string) $source[$field]) === '') {
                        continue;
                    }
                    ++$out['total'];
                    $target = isset($rows['target'][$id]) ? $rows['target'][$id] : [];
                    if (isset($target[$field]) && trim((string) $target[$field]) !== '') {
                        ++$out['translated'];
                    } elseif (isset($pending[$id][$field])) {
                        ++$out['proposed'];
                    } elseif ($field !== 'body') {
                        ++$out['missing'];
                    }
                }
            }
        }

        return $out;
    }

    public function next(string $lang, string $after, int $maxUnits, int $maxChars): array
    {
        // Cursor «page:12» o «post:5»: primero todas las páginas, luego las entradas.
        $phase = strpos($after, 'post:') === 0 ? 'post' : 'page';
        $pos = strpos($after, ':');
        $last = $pos === false ? 0 : (int) substr($after, $pos + 1);
        $cursor = $after;
        $units = [];
        $chars = 0;
        foreach (['page', 'post'] as $entity) {
            if ($entity === 'page' && $phase === 'post') {
                continue;
            }
            $from = $entity === $phase ? $last : 0;
            $rows = $this->rowsOf($entity, $lang);
            $pending = $this->pending($entity, $lang);
            foreach ($rows['source'] as $id => $source) {
                if ($id <= $from) {
                    continue;
                }
                $target = isset($rows['target'][$id]) ? $rows['target'][$id] : [];
                $mine = [];
                $size = 0;
                foreach (self::FIELDS as $field) {
                    $text = (string) $source[$field];
                    if (trim($text) === '' || (isset($target[$field]) && trim((string) $target[$field]) !== '') || isset($pending[$id][$field])) {
                        continue;
                    }
                    $mine[] = ['key' => $entity . ':' . $id . ':' . $field, 'text' => $text, 'format' => 'text'];
                    $size += strlen($text);
                }
                if ($mine !== [] && $units !== [] && (count($units) + count($mine) > $maxUnits || $chars + $size > $maxChars)) {
                    return ['units' => $units, 'cursor' => $cursor, 'done' => false];
                }
                foreach ($mine as $unit) {
                    $units[] = $unit;
                }
                $chars += $size;
                $cursor = $entity . ':' . $id;
            }
        }

        return ['units' => $units, 'cursor' => $cursor, 'done' => true];
    }

    public function save(string $lang, array $texts, array $units, bool $publish, int $jobId): array
    {
        if (!AiGate::installed($this->app)) {
            return array_keys($texts);
        }
        $ai = $this->app->service('ai');
        $failed = [];
        // Por entidad y en el orden de FIELDS: el título se aplica antes que lo demás.
        $grouped = [];
        foreach ($texts as $key => $text) {
            $parts = explode(':', (string) $key);
            if (count($parts) !== 3) {
                $failed[] = (string) $key;
                continue;
            }
            $grouped[$parts[0] . ':' . $parts[1]][$parts[2]] = ['key' => (string) $key, 'text' => $text];
        }
        foreach ($grouped as $target => $fields) {
            [$entity, $id] = explode(':', $target);
            foreach (self::FIELDS as $field) {
                if (!isset($fields[$field])) {
                    continue;
                }
                try {
                    $proposalId = (int) $ai->propose($entity, (int) $id, $field, $lang, $fields[$field]['text'], ['current' => '', 'task' => 'translations.pages']);
                    if ($publish) {
                        $ai->proposals()->approve($proposalId);
                    }
                } catch (\Throwable $e) {
                    $this->app->log()->exception($e, 'translations:pages');
                    $failed[] = $fields[$field]['key'];
                }
            }
        }

        return $failed;
    }

    public function approveAll(string $lang, int $max = 1000): int
    {
        if (!AiGate::installed($this->app) || !$this->app->db()->tableExists('ai_proposals')) {
            return 0;
        }
        $rows = $this->app->db()->select(
            "SELECT id, field FROM {ai_proposals} WHERE status = 'pending' AND (entity = 'page' OR entity = 'post') AND lang = ? ORDER BY entity, entity_id, id",
            [$lang]
        );
        usort($rows, static function (array $a, array $b): int {
            return ((string) $a['field'] === 'title' ? 0 : 1) <=> ((string) $b['field'] === 'title' ? 0 : 1) ?: (int) $a['id'] <=> (int) $b['id'];
        });
        $proposals = $this->app->service('ai')->proposals();
        $approved = 0;
        foreach ($rows as $row) {
            if ($approved >= $max) {
                break;
            }
            if (in_array((string) $row['field'], self::FIELDS, true) && $proposals->approve((int) $row['id']) === 'approved') {
                ++$approved;
            }
        }

        return $approved;
    }

    public function approvalNeedsAi(): bool
    {
        return true;
    }

    public function viewUrl(string $lang): string
    {
        return $this->app->url()->plain('/admin/translations/' . $lang . '/pages');
    }

    public function rows(string $lang, int $page, int $perPage): ?array
    {
        $list = [];
        foreach (['page', 'post'] as $entity) {
            $rows = $this->rowsOf($entity, $lang);
            $pending = $this->pending($entity, $lang);
            foreach ($rows['source'] as $id => $source) {
                $target = isset($rows['target'][$id]) ? $rows['target'][$id] : [];
                $fields = [];
                foreach (array_merge(self::FIELDS, ['body']) as $field) {
                    if (trim((string) $source[$field]) === '') {
                        $fields[$field] = 'none';
                    } elseif (isset($target[$field]) && trim((string) $target[$field]) !== '') {
                        $fields[$field] = 'ok';
                    } else {
                        $fields[$field] = isset($pending[$id][$field]) ? 'proposed' : 'missing';
                    }
                }
                $list[] = [
                    'label' => (string) $source['title'],
                    'url' => Admin::resourceUrl($this->app, $entity === 'page' ? 'pages' : 'posts', $id),
                    'fields' => $fields,
                ];
            }
        }

        return [
            'fields' => ['title' => t('Title'), 'excerpt' => t('Excerpt'), 'meta_title' => t('Search title'), 'meta_description' => t('Search description'), 'body' => t('Body')],
            'rows' => array_slice($list, max(0, ($page - 1) * $perPage), $perPage),
            'total' => count($list),
        ];
    }

    public function tokensPerUnit(): array
    {
        return [40, 50];
    }

    // ------------------------------------------------------------------ interior

    /**
     * Los textos de las páginas (o de las entradas) en el idioma principal y en el de destino. Cada consulta va escrita entera.
     *
     * @return array{source:array<int,array<string,string>>,target:array<int,array<string,string>>}
     */
    private function rowsOf(string $entity, string $lang): array
    {
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $rows = $entity === 'page'
            ? $db->select('SELECT page_id AS id, lang, title, excerpt, meta_title, meta_description, blocks FROM {page_i18n} WHERE lang = ? OR lang = ? ORDER BY page_id', [$default, $lang])
            : $db->select('SELECT post_id AS id, lang, title, excerpt, meta_title, meta_description, blocks FROM {post_i18n} WHERE lang = ? OR lang = ? ORDER BY post_id', [$default, $lang]);
        $out = ['source' => [], 'target' => []];
        foreach ($rows as $row) {
            $blocks = isset($row['blocks']) && $row['blocks'] !== null ? trim((string) $row['blocks']) : '';
            $clean = [
                'title' => (string) $row['title'],
                'excerpt' => isset($row['excerpt']) && $row['excerpt'] !== null ? (string) $row['excerpt'] : '',
                'meta_title' => isset($row['meta_title']) && $row['meta_title'] !== null ? (string) $row['meta_title'] : '',
                'meta_description' => isset($row['meta_description']) && $row['meta_description'] !== null ? (string) $row['meta_description'] : '',
                'body' => $blocks === '[]' || $blocks === 'null' ? '' : $blocks,
            ];
            $out[(string) $row['lang'] === $default ? 'source' : 'target'][(int) $row['id']] = $clean;
        }

        return $out;
    }

    /**
     * @return array<int,array<string,bool>>
     */
    private function pending(string $entity, string $lang): array
    {
        $db = $this->app->db();
        if (!$db->tableExists('ai_proposals')) {
            return [];
        }
        $out = [];
        foreach ($db->select("SELECT entity_id, field FROM {ai_proposals} WHERE status = 'pending' AND entity = ? AND lang = ?", [$entity, $lang]) as $row) {
            $out[(int) $row['entity_id']][(string) $row['field']] = true;
        }

        return $out;
    }
}
