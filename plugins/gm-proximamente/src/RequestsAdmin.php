<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Proximamente;

use Aifec\Kernel\App;

/**
 * El listado de peticiones de aviso del panel y su ficha de solo lectura.
 */
final class RequestsAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        $lang = "'" . (string) preg_replace('/[^a-z]/', '', strtolower($app->defaultLang())) . "'";

        return [
            'from' => '{gm_soon_requests} t LEFT JOIN {product_i18n} x ON x.product_id = t.product_id AND x.lang = ' . $lang,
            'select' => 't.*, x.name AS product_name',
            'key' => 'id',
            'key_sql' => 't.id',
            'columns' => [
                'created_at' => ['label' => 'Date', 'type' => 'datetime', 'sort' => 't.created_at', 'link' => true],
                'email' => ['label' => 'Email', 'sort' => 't.email'],
                'product_name' => ['label' => 'Product', 'sort' => 'x.name', 'hide_sm' => true],
                'notified_at' => ['label' => 'Told', 'type' => 'datetime', 'sort' => 't.notified_at', 'hide_sm' => true],
            ],
            'search' => ['t.email', 'x.name'],
            'filters' => ['told' => ['label' => 'State', 'options' => ['no' => 'Waiting', 'yes' => 'Told'], 'column' => 't.id', 'sql' => [
                'no' => 't.notified_at IS NULL', 'yes' => 't.notified_at IS NOT NULL',
            ]]],
            'sort' => ['t.id', 'desc'],
            'per_page' => 50,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function show(array $row, App $app): string
    {
        $name = $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [(int) $row['product_id'], $app->defaultLang()]);

        return $app->view()->render('gm-proximamente::admin/request', ['row' => $row, 'product_name' => $name !== null ? (string) $name : '#' . (int) $row['product_id']]);
    }
}
