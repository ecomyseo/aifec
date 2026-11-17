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
use Aifec\Util\Str;

/**
 * Las entradas del blog en el panel: listado, formulario (contenido, publicación con imagen, categorías y etiquetas, y SEO),
 * guardado y borrado. Las usa el recurso «posts» de admin.php (ver Aifec\Admin\ResourceController y Aifec\Admin\Form).
 *
 * Las categorías se eligen entre las que existen; las etiquetas se escriben (separadas por comas) y las que no existen se crean.
 */
final class PostAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'columns' => [
                'title' => ['label' => 'Title', 'link' => true, 'sort' => true],
                'status' => ['label' => 'Status', 'type' => 'badge', 'map' => ContentAdmin::statusMap(), 'sort' => true],
                'published_at' => ['label' => 'Published', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
                'updated_at' => ['label' => 'Updated', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
            ],
            'search' => ['title'],
            'filters' => ['status' => ['label' => 'Status', 'options' => ContentAdmin::statusOptions()]],
            'sort' => ['updated_at', 'desc'],
            'per_page' => 25,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $categories = [];
        $tags = [];
        if ($id !== null) {
            foreach ($app->db()->select('SELECT t.id AS id, t.taxonomy AS taxonomy FROM {post_terms} pt JOIN {terms} t ON t.id = pt.term_id WHERE pt.post_id = ? ORDER BY t.position, t.id', [$id]) as $row) {
                if ($row['taxonomy'] === 'tag') {
                    $name = $app->db()->value('SELECT name FROM {term_i18n} WHERE term_id = ? AND lang = ?', [(int) $row['id'], $app->defaultLang()]);
                    if ($name !== null) {
                        $tags[] = (string) $name;
                    }
                } else {
                    $categories[] = (string) $row['id'];
                }
            }
        }
        $fields = [
            'title' => ['label' => 'Title', 'i18n' => true, 'rules' => 'required|max:191', 'tab' => 'cmmain'],
            'blocks' => ['type' => 'blocks', 'label' => 'Content', 'i18n' => true, 'tab' => 'cmmain'],
            'excerpt' => ['type' => 'textarea', 'label' => 'Excerpt', 'i18n' => true, 'rows' => 3, 'rules' => 'max:600', 'tab' => 'cmmain',
                'hint' => 'A short summary shown in the lists of posts. If you leave it empty it is taken from the content.'],
            'status' => ['type' => 'select', 'label' => 'Status', 'options' => ContentAdmin::statusOptions(), 'default' => 'draft', 'rules' => 'required', 'tab' => 'cmpublish',
                'hint' => 'A draft is not shown in the shop. A scheduled post appears by itself when its date arrives.'],
            'published_at' => ['type' => 'datetime', 'label' => 'Publication date', 'tab' => 'cmpublish', 'show_if' => ['status' => ['scheduled', 'published']],
                'hint' => 'Leave it empty to publish now.'],
            'media_id' => ['type' => 'image', 'label' => 'Featured image', 'tab' => 'cmpublish'],
            'categories' => ['type' => 'checks', 'label' => 'Categories', 'options' => self::termOptions($app, 'category'), 'translate' => false, 'store' => false,
                'default' => $categories, 'tab' => 'cmpublish', 'hint' => 'Create the categories in Categories, under Content.'],
            'tags' => ['type' => 'tags', 'label' => 'Tags', 'store' => false, 'default' => $tags, 'placeholder' => 'oak, kitchen', 'tab' => 'cmpublish',
                'hint' => 'Separated by commas. The ones that do not exist are created.'],
            'slug' => ['type' => 'slug', 'label' => 'Address', 'i18n' => true, 'store' => false, 'rules' => 'max:90', 'tab' => 'cmseo',
                'hint' => 'Leave it empty to take it from the title. If you change it, the old address keeps redirecting.'],
            'meta_title' => ['label' => 'Meta title', 'i18n' => true, 'rules' => 'max:191', 'tab' => 'cmseo', 'hint' => 'Around 60 characters.'],
            'meta_description' => ['type' => 'textarea', 'label' => 'Meta description', 'i18n' => true, 'rows' => 3, 'rules' => 'max:320', 'tab' => 'cmseo', 'hint' => 'Around 155 characters.'],
            'noindex' => ['type' => 'toggle', 'label' => 'Do not index this post', 'default' => 0, 'tab' => 'cmseo',
                'hint' => 'Search engines are asked not to list it.'],
        ];

        return ['tabs' => ['cmmain' => 'Content', 'cmpublish' => 'Publishing', 'cmseo' => 'SEO'], 'fields' => $fields];
    }

    /**
     * @return array<string,string> id => nombre en el idioma principal
     */
    public static function termOptions(App $app, string $taxonomy): array
    {
        $out = [];
        foreach ($app->db()->select('SELECT t.id AS id, x.name AS name FROM {terms} t JOIN {term_i18n} x ON x.term_id = t.id AND x.lang = ? WHERE t.taxonomy = ? ORDER BY t.position, x.name', [$app->defaultLang(), $taxonomy]) as $row) {
            $out[(string) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        return ContentAdmin::check($values, $current, $app);
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $db = $app->db();
        if ($current === null) {
            $admin = $app->auth()->adminId();
            if ($admin !== null) {
                $db->update('posts', ['author_id' => $admin], ['id' => $id]);
            }
        }
        $termIds = [];
        foreach (isset($values['categories']) && is_array($values['categories']) ? $values['categories'] : [] as $categoryId) {
            if ((int) $categoryId > 0 && $db->value("SELECT id FROM {terms} WHERE id = ? AND taxonomy = 'category'", [(int) $categoryId]) !== null) {
                $termIds[(int) $categoryId] = true;
            }
        }
        foreach (self::resolveTags(isset($values['tags']) && is_array($values['tags']) ? $values['tags'] : [], $app) as $tagId) {
            $termIds[$tagId] = true;
        }
        $db->transaction(function () use ($db, $id, $termIds): void {
            $db->delete('post_terms', ['post_id' => $id]);
            foreach (array_keys($termIds) as $termId) {
                $db->insert('post_terms', ['post_id' => $id, 'term_id' => (int) $termId]);
            }
        });
        ContentAdmin::finish('post', $id, [], $app);
    }

    /**
     * Identificadores de las etiquetas con esos nombres; las que no existen se crean (en el idioma principal).
     *
     * @param array<int,mixed> $names
     *
     * @return int[]
     */
    private static function resolveTags(array $names, App $app): array
    {
        $db = $app->db();
        $default = $app->defaultLang();
        $known = [];
        foreach ($db->select("SELECT t.id AS id, x.name AS name FROM {terms} t JOIN {term_i18n} x ON x.term_id = t.id AND x.lang = ? WHERE t.taxonomy = 'tag'", [$default]) as $row) {
            $known[mb_strtolower((string) $row['name'])] = (int) $row['id'];
        }
        $cms = new Cms($app);
        $ids = [];
        foreach ($names as $name) {
            $name = is_string($name) ? trim(strip_tags($name)) : '';
            if ($name === '' || count($ids) >= 20) {
                continue;
            }
            $name = mb_substr($name, 0, 80);
            $key = mb_strtolower($name);
            if (!isset($known[$key])) {
                $termId = $db->insert('terms', ['taxonomy' => 'tag', 'position' => 0]);
                $db->insert('term_i18n', ['term_id' => $termId, 'lang' => $default, 'name' => $name]);
                $slug = Str::slug($name, 90);
                $app->slugs()->set('term', $termId, $default, $cms->prefix('tag') . '/' . ($slug !== '' ? $slug : 'tag-' . $termId));
                $known[$key] = $termId;
            }
            $ids[$known[$key]] = $known[$key];
        }

        return array_values($ids);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $id = (int) $row['id'];
        $db = $app->db();
        $db->delete('post_terms', ['post_id' => $id]);
        $db->delete('revisions', ['entity' => 'post', 'entity_id' => $id]);
        $db->delete('menu_items', ['type' => 'post', 'ref' => $id]);
        $app->invalidate(['cms', 'post:' . $id]);
        $app->hooks()->fire('content.saved', 'post', $id);
    }

    /**
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        return ContentAdmin::side('post', $row, $app);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function bulk(): array
    {
        return [
            'publish' => ['label' => 'Publish', 'confirm' => 'Publish the selected posts?', 'run' => static function (array $ids, App $app): string {
                return ContentAdmin::bulkStatus('post', 'published', $ids, $app);
            }],
            'draft' => ['label' => 'Move to draft', 'run' => static function (array $ids, App $app): string {
                return ContentAdmin::bulkStatus('post', 'draft', $ids, $app);
            }],
        ];
    }
}
