<?php
/**
 * AIFEC — repara los productos que se quedaron sin dirección amigable en un idioma en el que tienen nombre (13/11/2026: en el
 * buscador, «Bailarinas brown planas con tachas y lazada central» enlazaba a la portada porque no tenía dirección). Se le da la
 * que sale de su nombre, con el prefijo vigente; si ya la usa otro, Slugs le añade «-2», «-3»…
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Kernel\Slugs;
use Aifec\Util\Str;

return static function (Schema $schema, Db $db): void {
    $prefix = 'producto';
    $raw = $db->value('SELECT v FROM {settings} WHERE k = ?', ['urls.product']);
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $value = trim(is_string($decoded) ? $decoded : $raw, "/\" \t");
        if ($value !== '') {
            $prefix = $value;
        }
    }
    $rows = $db->select(
        'SELECT i.product_id, i.lang, i.name FROM {product_i18n} i'
        . ' WHERE NOT EXISTS (SELECT 1 FROM {slugs} s WHERE s.type = ? AND s.entity_id = i.product_id AND s.lang = i.lang AND s.canonical = 1)',
        ['product']
    );
    if ($rows === []) {
        return;
    }
    $slugs = new Slugs($db);
    foreach ($rows as $row) {
        $id = (int) $row['product_id'];
        $segment = Str::slug((string) $row['name'], 90);
        $slugs->set('product', $id, (string) $row['lang'], $prefix . '/' . ($segment !== '' ? $segment : 'product-' . $id));
    }
};
