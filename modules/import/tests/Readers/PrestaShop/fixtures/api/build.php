<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * Regenera los JSON de ejemplo del servicio web a partir de fixtures/db/data.php:
 *
 *   php modules/import/tests/Readers/PrestaShop/fixtures/api/build.php
 */
require __DIR__ . '/../db/schema.php';
require __DIR__ . '/../db/data.php';
require __DIR__ . '/WsExport.php';

$pdo = psMemoryDb();
psSeed($pdo);
$resources = WsExport::export($pdo);
$files = [];
foreach ($resources as $resource => $items) {
    $files[WsExport::FILES[$resource]][$resource] = $items;
}
foreach ($files as $name => $data) {
    file_put_contents(__DIR__ . '/' . $name . '.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo $name . '.json: ' . count($data) . " recursos\n";
}
