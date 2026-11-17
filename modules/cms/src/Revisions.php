<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Kernel\App;

/**
 * Versiones de páginas y entradas: cada guardado deja una (todos los idiomas a la vez, en la fila con lang «all») y se
 * conservan las 20 últimas de cada contenido. Restaurar vuelve a escribir esa versión y deja el contenido como borrador.
 */
final class Revisions
{
    /** Columnas de la fila principal que viajan en una versión. */
    private const MAIN = [
        'page' => ['template', 'parent_id', 'position', 'noindex', 'published_at', 'author_id'],
        'post' => ['media_id', 'noindex', 'published_at', 'author_id'],
    ];

    /** Columnas de los textos por idioma que viajan en una versión. */
    private const TEXTS = ['title', 'blocks', 'excerpt', 'meta_title', 'meta_description'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Guarda el estado actual de un contenido como una versión nueva y poda las antiguas. Devuelve su identificador, o 0 si el
     * contenido no existe.
     */
    public function record(string $type, int $id): int
    {
        if (!isset(self::MAIN[$type])) {
            return 0;
        }
        $db = $this->app->db();
        $row = $type === 'page' ? $db->row('SELECT * FROM {pages} WHERE id = ?', [$id]) : $db->row('SELECT * FROM {posts} WHERE id = ?', [$id]);
        if ($row === null) {
            return 0;
        }
        $main = ['status' => (string) $row['status']];
        foreach (self::MAIN[$type] as $column) {
            $main[$column] = array_key_exists($column, $row) ? $row[$column] : null;
        }
        $texts = [];
        $rows = $type === 'page' ? $db->select('SELECT * FROM {page_i18n} WHERE page_id = ?', [$id]) : $db->select('SELECT * FROM {post_i18n} WHERE post_id = ?', [$id]);
        foreach ($rows as $text) {
            $one = [];
            foreach (self::TEXTS as $column) {
                $one[$column] = isset($text[$column]) && $text[$column] !== null ? (string) $text[$column] : '';
            }
            $texts[(string) $text['lang']] = $one;
        }
        $data = ['main' => $main, 'i18n' => $texts];
        if ($type === 'post') {
            $data['terms'] = array_map('intval', $db->column('SELECT term_id FROM {post_terms} WHERE post_id = ? ORDER BY term_id', [$id]));
        }
        $admin = $this->app->auth()->adminId();
        $revision = $db->insert('revisions', [
            'entity' => $type,
            'entity_id' => $id,
            'lang' => 'all',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'user_id' => $admin,
            'created_at' => $this->app->clock()->sql(),
        ]);
        $this->prune($type, $id);

        return $revision;
    }

    /**
     * Las versiones de un contenido, las nuevas primero: [['id','created_at','user_name']].
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(string $type, int $id, int $limit = Cms::KEEP_REVISIONS): array
    {
        return $this->app->db()->select(
            'SELECT r.id AS id, r.created_at AS created_at, u.name AS user_name FROM {revisions} r LEFT JOIN {users} u ON u.id = r.user_id WHERE r.entity = ? AND r.entity_id = ? ORDER BY r.id DESC LIMIT ?',
            [$type, $id, max(1, $limit)]
        );
    }

    /**
     * Vuelve a escribir una versión sobre su contenido, que queda como borrador, y deja constancia con otra versión.
     *
     * @return array{type:string,id:int}|null null si la versión no existe o está rota
     */
    public function restore(int $revisionId): ?array
    {
        $db = $this->app->db();
        $row = $db->row('SELECT * FROM {revisions} WHERE id = ?', [$revisionId]);
        if ($row === null) {
            return null;
        }
        $type = (string) $row['entity'];
        $id = (int) $row['entity_id'];
        $data = json_decode((string) $row['data'], true);
        if (!isset(self::MAIN[$type]) || !is_array($data) || !isset($data['main']) || !is_array($data['main']) || !isset($data['i18n']) || !is_array($data['i18n'])) {
            return null;
        }
        $table = $type === 'page' ? 'pages' : 'posts';
        if ($db->value('SELECT id FROM {' . $table . '} WHERE id = ?', [$id]) === null) {
            return null;
        }

        $db->transaction(function () use ($db, $type, $id, $data, $table): void {
            $set = ['status' => 'draft', 'updated_at' => $this->app->clock()->sql()];
            foreach (self::MAIN[$type] as $column) {
                if (array_key_exists($column, $data['main'])) {
                    $set[$column] = $data['main'][$column];
                }
            }
            $db->update($table, $set, ['id' => $id]);
            $fk = $type === 'page' ? 'page_id' : 'post_id';
            $textTable = $type === 'page' ? 'page_i18n' : 'post_i18n';
            foreach ($data['i18n'] as $lang => $texts) {
                if (!is_string($lang) || !is_array($texts)) {
                    continue;
                }
                $values = [$fk => $id, 'lang' => $lang];
                foreach (self::TEXTS as $column) {
                    $values[$column] = isset($texts[$column]) && is_string($texts[$column]) ? $texts[$column] : '';
                }
                $db->upsert($textTable, $values, [$fk, 'lang']);
            }
            if ($type === 'post' && isset($data['terms']) && is_array($data['terms'])) {
                $db->delete('post_terms', ['post_id' => $id]);
                foreach (array_unique(array_map('intval', $data['terms'])) as $termId) {
                    if ($termId > 0 && $db->value('SELECT id FROM {terms} WHERE id = ?', [$termId]) !== null) {
                        $db->insert('post_terms', ['post_id' => $id, 'term_id' => $termId]);
                    }
                }
            }
        });
        $this->record($type, $id);
        $this->app->invalidate(['cms', $type . ':' . $id]);
        $this->app->hooks()->fire('content.saved', $type, $id);

        return ['type' => $type, 'id' => $id];
    }

    private function prune(string $type, int $id): void
    {
        $db = $this->app->db();
        $old = $db->column('SELECT id FROM {revisions} WHERE entity = ? AND entity_id = ? ORDER BY id DESC LIMIT 1000 OFFSET ?', [$type, $id, Cms::KEEP_REVISIONS]);
        if ($old === []) {
            return;
        }
        $params = [];
        $marks = $db->in(array_map('intval', $old), $params);
        $db->run('DELETE FROM {revisions} WHERE id IN (' . $marks . ')', $params);
    }
}
