<?php
/**
 * AIFEC — datos de serie de envíos: las cuatro zonas de España, solo si la tienda no tiene ninguna.
 *
 * Península es «ES» sin códigos postales; las otras tres ganan por ser más concretas (por código postal).
 * Los nombres se guardan en inglés y se traducen al enseñarlos. No se crea ningún transportista: cada tienda pone los suyos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;

return static function (Schema $schema, Db $db): void {
    if ((int) $db->value('SELECT COUNT(*) FROM {shipping_zones}') > 0) {
        return;
    }
    $zones = [
        ['Peninsula', ''],
        ['Balearic Islands', '07'],
        ['Canary Islands', '35,38'],
        ['Ceuta and Melilla', '51,52'],
    ];
    foreach ($zones as $position => $zone) {
        $id = $db->insert('shipping_zones', ['name' => $zone[0], 'active' => 1, 'position' => $position]);
        $db->insert('zone_locations', ['zone_id' => $id, 'country' => 'ES', 'region' => '', 'postcodes' => $zone[1]]);
    }
};
