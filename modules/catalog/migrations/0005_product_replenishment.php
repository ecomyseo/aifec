<?php
/** AIFEC — intervalo de reposición opcional del producto. @license Ver LICENSE.md */
declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    if (!$schema->hasColumn('products', 'replenishment_days')) {
        $schema->addColumn('products', static function (TableDef $t): void {
            $t->integer('replenishment_days')->default(0);
        });
    }
};
