<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\LegalProducto;

use Aifec\Kernel\App;

/**
 * El recurso «gm_legal_blocks» del panel: el formulario de un bloque legal (con sus categorías y productos) y su guardado.
 */
final class BlockAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function form(App $app, ?int $id): array
    {
        $blocks = new Blocks($app);
        $categories = [];
        foreach ($app->db()->select('SELECT c.id, i.name FROM {categories} c LEFT JOIN {category_i18n} i ON i.category_id = c.id AND i.lang = ? ORDER BY c.path, c.id', [$app->defaultLang()]) as $row) {
            $categories[(string) $row['id']] = $row['name'] !== null && (string) $row['name'] !== '' ? (string) $row['name'] : '#' . $row['id'];
        }
        $kinds = Blocks::KINDS;

        return [
            'tabs' => ['gplmain' => 'Text', 'gplwhere' => 'Where it applies'],
            'fields' => [
                'title' => ['label' => 'Title', 'rules' => 'required|max:160', 'tab' => 'gplmain'],
                'code' => ['label' => 'Code', 'hint' => 'Letters, numbers and hyphens. It identifies the block.', 'rules' => 'required|slug|max:60|unique:gm_legal_blocks,code', 'width' => 'md', 'tab' => 'gplmain'],
                'kind' => ['type' => 'select', 'label' => 'Type', 'options' => $kinds, 'default' => 'other', 'tab' => 'gplmain'],
                'body' => ['type' => 'textarea', 'label' => 'Text', 'hint' => 'Plain text. Each line is a paragraph.', 'rules' => 'required|max:5000', 'tab' => 'gplmain'],
                'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1, 'tab' => 'gplmain'],
                'in_mail' => ['type' => 'toggle', 'label' => 'Also send it in the order email', 'default' => 1, 'tab' => 'gplmain'],
                'position' => ['type' => 'number', 'label' => 'Position', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm', 'tab' => 'gplmain'],
                'categories' => ['type' => 'checks', 'label' => 'Categories', 'hint' => 'It applies to every product of these categories and their subcategories.', 'options' => $categories, 'store' => false, 'default' => $id !== null ? array_map('strval', $blocks->assigned($id, 'category')) : [], 'tab' => 'gplwhere'],
                'products' => ['type' => 'text', 'label' => 'Products', 'hint' => 'Product numbers separated by commas.', 'store' => false, 'default' => $id !== null ? implode(', ', $blocks->assigned($id, 'product')) : '', 'rules' => 'max:2000', 'tab' => 'gplwhere'],
            ],
        ];
    }

    /**
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $categories = isset($values['categories']) && is_array($values['categories']) ? array_map('intval', $values['categories']) : [];
        $products = [];
        foreach (preg_split('/[\s,;]+/', isset($values['products']) && is_string($values['products']) ? $values['products'] : '') ?: [] as $part) {
            if ((int) $part > 0) {
                $products[] = (int) $part;
            }
        }
        (new Blocks($app))->assign($id, $categories, $products);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        (new Blocks($app))->forget((int) $row['id']);
    }
}
