<?php
/** AIFEC — contenido del paquete y referencia unitaria para comparar precios. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
return static function (Schema $schema, Db $db): void {
    $schema->addColumn('products', static function (TableDef $t): void {
        $t->integer('pack_units')->default(0);
        $t->bigInteger('pack_single_id')->default(0);
    });
};
