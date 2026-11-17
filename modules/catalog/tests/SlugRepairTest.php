<?php
/**
 * AIFEC — un producto sin dirección amigable no enlaza a la portada y la migración 0003 se la devuelve (13/11/2026: en el buscador,
 * «Bailarinas brown planas con tachas y lazada central» llevaba a «/»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Aifec\Db\Schema;

test('sin dirección la tarjeta no enlaza a la portada, y la reparación le da la de su nombre sin tocar las demás', function (): void {
    $app = testApp(['modules' => ['catalog']]);
    $lost = aifecProduct($app, ['name' => 'Bailarinas brown planas', 'price' => 2495]);
    $kept = aifecProduct($app, ['name' => 'Botas camperas', 'price' => 5995]);
    $keptPath = $app->slugs()->path('product', $kept, 'es');
    $app->slugs()->remove('product', $lost);
    $app->invalidate(['catalog']);

    $card = $app->service('catalog')->card($lost);
    check(is_array($card), 'la tarjeta existe');
    same('', $card['url'], 'sin dirección, sin enlace (nunca «/»)');
    same('/producto/botas-camperas', $app->service('catalog')->card($kept)['url'], 'la otra sigue con la suya');

    $migration = require dirname(__DIR__) . '/migrations/0003_product_slugs_repair.php';
    $migration(new Schema($app->db()), $app->db());
    same('producto/bailarinas-brown-planas', $app->db()->value("SELECT path FROM {slugs} WHERE type = 'product' AND entity_id = ? AND canonical = 1", [$lost]));
    same($keptPath, $app->db()->value("SELECT path FROM {slugs} WHERE type = 'product' AND entity_id = ? AND canonical = 1", [$kept]), 'la de la otra no cambia');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {slugs} WHERE type = 'product' AND entity_id = ?", [$kept]), 'ni se le añade otra');
    $migration(new Schema($app->db()), $app->db());
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {slugs} WHERE type = 'product' AND entity_id = ?", [$lost]), 'pasarla dos veces no cambia nada');
});
