<?php
/**
 * AIFEC — ensancha en los sitios ya creados las columnas de texto que se declararon con más de 191 caracteres.
 *
 * Hasta esta versión el esquema recortaba toda columna de texto corto a 191 sin decirlo. En SQLite no se notaba (no mira
 * longitudes); en MySQL y MariaDB un texto de más de 191 caracteres en una de estas columnas daba «Data too long». Las tablas
 * nuevas ya nacen con su anchura; aquí se corrigen las que existían. Solo se ensancha: ningún dato se toca ni se pierde.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;

return static function (Schema $schema, Db $db): void {
    // Tabla, columna y anchura declarada en la migración que la creó (del núcleo y de los módulos de serie).
    $columns = [
        ['users', 'password', 255],
        ['users', 'totp_secret', 255],
        ['product_i18n', 'meta_description', 320],
        ['category_i18n', 'meta_description', 320],
        ['product_faqs', 'question', 255],
        ['product_files', 'path', 255],
        ['page_i18n', 'meta_description', 320],
        ['post_i18n', 'meta_description', 320],
        ['customers', 'password', 255],
        ['erp_webhooks', 'url', 255],
        ['erp_webhooks', 'paused_reason', 255],
        ['erp_deliveries', 'error', 255],
        ['import_log', 'reason', 255],
        ['order_refunds', 'reason', 255],
        ['zone_locations', 'postcodes', 255],
        ['carriers', 'tracking_url', 255],
        ['tax_rates', 'postcodes', 255],
    ];
    foreach ($columns as $column) {
        // widenString no hace nada si la tabla no existe todavía (un módulo sin activar) o si no es MySQL/MariaDB.
        $schema->widenString($column[0], $column[1], $column[2]);
    }
};
