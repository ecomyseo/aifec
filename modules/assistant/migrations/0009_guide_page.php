<?php
/** AIFEC — ámbito opcional de una pregunta frecuente de la tienda. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    if (!$schema->hasColumn('assistant_guides', 'page_path')) {
        $schema->addColumn('assistant_guides', static function (TableDef $t): void {
            $t->string('page_path', 190)->default('');
        });
    }
};
