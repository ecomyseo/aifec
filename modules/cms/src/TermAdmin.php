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
 * Categorías y etiquetas del blog en el panel (dos recursos sobre la misma tabla `terms`, separados por el campo
 * `taxonomy`; ver Aifec\Admin\ResourceController y Aifec\Admin\Form). Una categoría puede tener madre; una etiqueta no.
 */
final class TermAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app, string $taxonomy): array
    {
        $columns = [
            'name' => ['label' => 'Name', 'link' => true, 'sort' => true],
            'posts' => ['label' => 'Posts', 'type' => 'int', 'render' => static function (array $row, App $app): string {
                return e((string) (int) $app->db()->value('SELECT COUNT(*) FROM {post_terms} WHERE term_id = ?', [(int) $row['id']]));
            }],
            'position' => ['label' => 'Order', 'type' => 'int', 'sort' => true, 'hide_sm' => true],
        ];
        if ($taxonomy === 'tag') {
            unset($columns['position']);
        }

        return ['columns' => $columns, 'search' => ['name'], 'sort' => [$taxonomy === 'tag' ? 'name' : 'position', 'asc'], 'per_page' => 50];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id, string $taxonomy): array
    {
        $fields = [
            'name' => ['label' => 'Name', 'i18n' => true, 'rules' => 'required|max:191'],
            'slug' => ['type' => 'slug', 'label' => 'Address', 'i18n' => true, 'store' => false, 'rules' => 'max:90',
                'hint' => 'Leave it empty to take it from the name.'],
        ];
        if ($taxonomy === 'category') {
            $fields['parent_id'] = ['type' => 'select', 'label' => 'Parent category', 'options' => self::parentOptions($app, $id), 'translate' => false, 'free' => true,
                'empty' => 'None (top level)', 'rules' => 'nullable|int', 'width' => 'md'];
            $fields['position'] = ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm'];
        }
        $fields['description'] = ['type' => 'textarea', 'label' => 'Description', 'i18n' => true, 'rows' => 3, 'rules' => 'max:1000'];

        return ['fields' => $fields];
    }

    /**
     * @return array<string,string> id => nombre
     */
    private static function parentOptions(App $app, ?int $id): array
    {
        $blocked = $id !== null ? self::descendants($app, $id) + [$id => true] : [];
        $out = [];
        foreach (PostAdmin::termOptions($app, 'category') as $termId => $name) {
            if (!isset($blocked[(int) $termId])) {
                $out[$termId] = $name;
            }
        }

        return $out;
    }

    /**
     * @return array<int,bool>
     */
    private static function descendants(App $app, int $id): array
    {
        $set = [];
        $queue = [$id];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            foreach ($app->db()->column('SELECT id FROM {terms} WHERE parent_id = ?', [$current]) as $child) {
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
        if (!array_key_exists('parent_id', $values)) {
            return $values;
        }
        $parent = $values['parent_id'] !== null && $values['parent_id'] !== '' ? (int) $values['parent_id'] : 0;
        if ($parent > 0) {
            $id = $current !== null ? (int) $current['id'] : 0;
            if ($app->db()->value("SELECT id FROM {terms} WHERE id = ? AND taxonomy = 'category'", [$parent]) === null) {
                return ['_errors' => ['parent_id' => t('This category does not exist')]];
            }
            if ($id > 0 && ($parent === $id || isset(self::descendants($app, $id)[$parent]))) {
                return ['_errors' => ['parent_id' => t('A category cannot be inside itself or inside one of its subcategories')]];
            }
        }
        $values['parent_id'] = $parent > 0 ? $parent : null;

        return $values;
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $app->invalidate(['cms']);
        $app->hooks()->fire('content.saved', 'term', $id);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $children = (int) $app->db()->value('SELECT COUNT(*) FROM {terms} WHERE parent_id = ?', [(int) $row['id']]);

        return $children > 0 ? t('This category has subcategories. Move or delete them first.') : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $id = (int) $row['id'];
        $app->db()->delete('post_terms', ['term_id' => $id]);
        $app->db()->delete('menu_items', ['type' => 'term', 'ref' => $id]);
        $app->invalidate(['cms']);
        $app->hooks()->fire('content.saved', 'term', $id);
    }
}
