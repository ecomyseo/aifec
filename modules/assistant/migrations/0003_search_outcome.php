<?php
/** AIFEC — resultado medido de la búsqueda del asistente. @license Ver LICENSE.md */
declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->addColumn('conversation_messages', static function (TableDef $t): void {
        $t->boolean('empty_search')->nullable();
    });
};
