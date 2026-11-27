<?php
/** AIFEC — equivalencias que se aplican únicamente en el sector elegido. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
return static function (Schema $schema, Db $db): void {
    $schema->addColumn('synonyms', static function (TableDef $t): void { $t->string('sector', 24)->default(''); });
};
