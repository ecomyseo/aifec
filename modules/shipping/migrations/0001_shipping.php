<?php
/**
 * AIFEC — esquema de envíos: zonas, transportistas y tarifas.
 *
 * Una dirección cae en la zona más concreta que la contenga (código postal > región > país > «resto del mundo»).
 * Tarifa: basis weight (gramos) o price (céntimos del importe de los artículos); vale la fila cuyo tramo
 * [min, max] contiene el valor (max 0 = sin tope). Los importes van «tal como se escriben», igual que los precios.
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
    $schema->create('shipping_zones', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->boolean('active')->default(1);
        $t->integer('position')->default(0);
    });

    // country «*» = cualquier país. region y postcodes como en los impuestos («35,38», «07000-07999»).
    $schema->create('zone_locations', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('zone_id');
        $t->string('country', 2);
        $t->string('region', 16)->default('');
        $t->string('postcodes', 255)->default('');
        $t->index(['zone_id']);
        $t->index(['country']);
    });

    // handler: «rates» (tabla de tarifas), «pickup» (recogida en tienda, sin dirección) o el código de un plugin de transporte.
    // free_from: importe de artículos a partir del cual sale gratis (nulo = nunca). tracking_url con «{code}».
    $schema->create('carriers', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 40);
        $t->string('name', 120);
        $t->string('delay', 191)->nullable();
        $t->string('handler', 40)->default('rates');
        $t->text('settings')->nullable();
        $t->bigInteger('tax_class_id')->nullable();
        $t->integer('free_from')->nullable();
        $t->integer('max_weight')->default(0);
        $t->integer('min_days')->nullable();
        $t->integer('max_days')->nullable();
        $t->string('tracking_url', 255)->nullable();
        $t->bigInteger('media_id')->nullable();
        $t->boolean('active')->default(1);
        $t->integer('position')->default(0);
        $t->timestamps();
        $t->unique(['code']);
    });

    $schema->create('carrier_rates', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('carrier_id');
        $t->bigInteger('zone_id');
        $t->string('basis', 8)->default('weight');
        $t->integer('min')->default(0);
        $t->integer('max')->default(0);
        $t->integer('price')->default(0);
        $t->index(['carrier_id', 'zone_id']);
    });
};
