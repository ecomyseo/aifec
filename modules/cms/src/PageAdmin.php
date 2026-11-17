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
 * Las páginas en el panel: listado, formulario (contenido, publicación y SEO), guardado y borrado. Las usa el recurso «pages»
 * de admin.php (ver Aifec\Admin\ResourceController y Aifec\Admin\Form). La regla de estado y de quién publica está en ContentAdmin.
 */
final class PageAdmin
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
                'template' => ['label' => 'Template', 'hide_sm' => true],
                'is_home' => ['label' => 'Home page', 'type' => 'bool', 'hide_sm' => true],
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
        $fields = [
            'title' => ['label' => 'Title', 'i18n' => true, 'rules' => 'required|max:191', 'tab' => 'cmmain'],
            'blocks' => ['type' => 'blocks', 'label' => 'Content', 'i18n' => true, 'tab' => 'cmmain'],
            'excerpt' => ['type' => 'textarea', 'label' => 'Excerpt', 'i18n' => true, 'rows' => 3, 'rules' => 'max:600', 'tab' => 'cmmain',
                'hint' => 'A short summary. If you leave it empty it is taken from the content.'],
            'status' => ['type' => 'select', 'label' => 'Status', 'options' => ContentAdmin::statusOptions(), 'default' => 'draft', 'rules' => 'required', 'tab' => 'cmpublish',
                'hint' => 'A draft is not shown in the shop. A scheduled page appears by itself when its date arrives.'],
            'published_at' => ['type' => 'datetime', 'label' => 'Publication date', 'tab' => 'cmpublish', 'show_if' => ['status' => ['scheduled', 'published']],
                'hint' => 'Leave it empty to publish now.'],
            'template' => ['type' => 'select', 'label' => 'Template', 'options' => ContentAdmin::templates($app), 'translate' => false, 'default' => 'default', 'free' => true,
                'rules' => 'required|regex:/^[a-z0-9_-]+$/', 'tab' => 'cmpublish', 'width' => 'md'],
            'parent_id' => ['type' => 'select', 'label' => 'Parent page', 'options' => self::parentOptions($app, $id), 'translate' => false, 'free' => true, 'empty' => 'None (top level)',
                'rules' => 'nullable|int', 'tab' => 'cmpublish', 'width' => 'md'],
            'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm', 'tab' => 'cmpublish'],
            'is_home' => ['type' => 'toggle', 'label' => 'Home page', 'default' => 0, 'tab' => 'cmpublish',
                'hint' => 'Only one page can be the home page: this one takes the place of the previous one.'],
            'slug' => ['type' => 'slug', 'label' => 'Address', 'i18n' => true, 'store' => false, 'rules' => 'max:90', 'tab' => 'cmseo',
                'hint' => 'Leave it empty to take it from the title. If you change it, the old address keeps redirecting.'],
            'meta_title' => ['label' => 'Meta title', 'i18n' => true, 'rules' => 'max:191', 'tab' => 'cmseo', 'hint' => 'Around 60 characters.'],
            'meta_description' => ['type' => 'textarea', 'label' => 'Meta description', 'i18n' => true, 'rows' => 3, 'rules' => 'max:320', 'tab' => 'cmseo', 'hint' => 'Around 155 characters.'],
            'noindex' => ['type' => 'toggle', 'label' => 'Do not index this page', 'default' => 0, 'tab' => 'cmseo',
                'hint' => 'Search engines are asked not to list it.'],
        ];

        return ['tabs' => ['cmmain' => 'Content', 'cmpublish' => 'Publishing', 'cmseo' => 'SEO'], 'fields' => $fields];
    }

    /**
     * Páginas que pueden ser madre de esta (todas menos ella y sus descendientes), por su título en el idioma principal.
     *
     * @return array<string,string> id => título
     */
    private static function parentOptions(App $app, ?int $id): array
    {
        $db = $app->db();
        $rows = $db->select('SELECT p.id AS id, p.parent_id AS parent_id, x.title AS title FROM {pages} p LEFT JOIN {page_i18n} x ON x.page_id = p.id AND x.lang = ? ORDER BY x.title LIMIT 500', [$app->defaultLang()]);
        $blocked = $id !== null ? self::descendants($app, $id) + [$id => true] : [];
        $out = [];
        foreach ($rows as $row) {
            if (!isset($blocked[(int) $row['id']])) {
                $out[(string) $row['id']] = (string) $row['title'] !== '' ? (string) $row['title'] : '#' . $row['id'];
            }
        }

        return $out;
    }

    /**
     * Identificadores de todas las páginas que cuelgan de una (hijas, nietas…).
     *
     * @return array<int,bool>
     */
    public static function descendants(App $app, int $id): array
    {
        $set = [];
        $queue = [$id];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            foreach ($app->db()->column('SELECT id FROM {pages} WHERE parent_id = ?', [$current]) as $child) {
                $child = (int) $child;
                if (!isset($set[$child]) && $child !== $id) {
                    $set[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $set;
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        $id = $current !== null ? (int) $current['id'] : 0;
        $errors = [];
        $parent = isset($values['parent_id']) && $values['parent_id'] !== null && $values['parent_id'] !== '' ? (int) $values['parent_id'] : 0;
        if ($parent > 0) {
            if ($app->db()->value('SELECT id FROM {pages} WHERE id = ?', [$parent]) === null) {
                $errors['parent_id'] = t('This page does not exist');
            } elseif ($id > 0 && ($parent === $id || isset(self::descendants($app, $id)[$parent]))) {
                $errors['parent_id'] = t('A page cannot be inside itself or inside one of its subpages');
            }
        }
        $values['parent_id'] = $parent > 0 ? $parent : null;

        $checked = ContentAdmin::check($values, $current, $app);
        if (isset($checked['_errors'])) {
            $errors += $checked['_errors'];
        }
        if ($errors !== []) {
            return ['_errors' => $errors];
        }

        return $checked;
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
                $db->update('pages', ['author_id' => $admin], ['id' => $id]);
            }
        }
        $tags = [];
        if (!empty($values['is_home'])) {
            // Solo hay una portada: la anterior deja de serlo (y su caché se vacía).
            foreach ($db->column('SELECT id FROM {pages} WHERE is_home = 1 AND id <> ?', [$id]) as $other) {
                $tags[] = 'page:' . (int) $other;
            }
            $db->run('UPDATE {pages} SET is_home = 0 WHERE is_home = 1 AND id <> ?', [$id]);
        }
        ContentAdmin::finish('page', $id, $tags, $app);
    }

    /**
     * No se borra una página con subpáginas.
     *
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $children = (int) $app->db()->value('SELECT COUNT(*) FROM {pages} WHERE parent_id = ?', [(int) $row['id']]);

        return $children > 0 ? t('This page has subpages. Move or delete them first.') : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $id = (int) $row['id'];
        $db = $app->db();
        $db->delete('revisions', ['entity' => 'page', 'entity_id' => $id]);
        $db->delete('menu_items', ['type' => 'page', 'ref' => $id]);
        $app->invalidate(['cms', 'page:' . $id]);
        $app->hooks()->fire('content.saved', 'page', $id);
    }

    /**
     * @param array<string,mixed>|null $row
     */
    public static function side(?array $row, App $app): string
    {
        return ContentAdmin::side('page', $row, $app);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function bulk(): array
    {
        return [
            'publish' => ['label' => 'Publish', 'confirm' => 'Publish the selected pages?', 'run' => static function (array $ids, App $app): string {
                return ContentAdmin::bulkStatus('page', 'published', $ids, $app);
            }],
            'draft' => ['label' => 'Move to draft', 'run' => static function (array $ids, App $app): string {
                return ContentAdmin::bulkStatus('page', 'draft', $ids, $app);
            }],
        ];
    }
}
