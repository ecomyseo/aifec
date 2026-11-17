<?php
/**
 * AIFEC — esquema de impuestos: clases (general, reducido…) y tipos por destino.
 *
 * rate va en centésimas de punto: 2100 = 21 %. region vacía = todo el país; postcodes = lista de prefijos o
 * rangos separados por comas («35,38» Canarias, «51000-51999» Ceuta) y manda sobre region.
 * Se aplica el tipo más concreto que case (código postal > región > país) y, a igualdad, el de mayor prioridad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->create('tax_classes', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 32);
        $t->string('name', 80);
        $t->boolean('is_default')->default(0);
        $t->integer('position')->default(0);
        $t->unique(['code']);
    });

    $schema->create('tax_rates', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('tax_class_id');
        $t->string('country', 2);
        $t->string('region', 16)->default('');
        $t->string('postcodes', 255)->default('');
        $t->integer('rate')->default(0);
        $t->string('name', 60);
        $t->integer('priority')->default(0);
        $t->boolean('active')->default(1);
        $t->index(['tax_class_id', 'country']);
    });

    // De serie: las tres clases del IVA español y sus tipos en península y Baleares; IGIC en Canarias; exento en Ceuta y Melilla.
    $classes = [['standard', 'Standard rate', 1], ['reduced', 'Reduced rate', 0], ['super-reduced', 'Super-reduced rate', 0], ['zero', 'Zero rate', 0]];
    foreach ($classes as $i => $class) {
        $db->insertIgnore('tax_classes', ['code' => $class[0], 'name' => $class[1], 'is_default' => $class[2], 'position' => $i]);
    }
    if ((int) $db->value('SELECT COUNT(*) FROM {tax_rates}') === 0) {
        $ids = [];
        foreach ($db->select('SELECT id, code FROM {tax_classes}') as $row) {
            $ids[(string) $row['code']] = (int) $row['id'];
        }
        $rates = [
            ['standard', 'ES', '', 2100, 'IVA 21 %', 0], ['reduced', 'ES', '', 1000, 'IVA 10 %', 0], ['super-reduced', 'ES', '', 400, 'IVA 4 %', 0], ['zero', 'ES', '', 0, 'IVA 0 %', 0],
            ['standard', 'ES', '35,38', 700, 'IGIC 7 %', 10], ['reduced', 'ES', '35,38', 300, 'IGIC 3 %', 10], ['super-reduced', 'ES', '35,38', 0, 'IGIC 0 %', 10], ['zero', 'ES', '35,38', 0, 'IGIC 0 %', 10],
            ['standard', 'ES', '51,52', 0, 'Sin IVA (IPSI en destino)', 10], ['reduced', 'ES', '51,52', 0, 'Sin IVA (IPSI en destino)', 10],
            ['super-reduced', 'ES', '51,52', 0, 'Sin IVA (IPSI en destino)', 10], ['zero', 'ES', '51,52', 0, 'Sin IVA (IPSI en destino)', 10],
        ];
        foreach ($rates as $rate) {
            $db->insert('tax_rates', ['tax_class_id' => $ids[$rate[0]], 'country' => $rate[1], 'region' => '', 'postcodes' => $rate[2], 'rate' => $rate[3], 'name' => $rate[4], 'priority' => $rate[5], 'active' => 1]);
        }
    }
};
