<?php
/** AIFEC — solo se crea una fila con la petición expresa del titular de la cuenta. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
return static function (Schema $schema, Db $db): void {
    $schema->create('assistant_preferences', static function (TableDef $t): void {
        $t->bigInteger('customer_id');
        $t->primary(['customer_id']);
        $t->string('currency', 3);
        $t->integer('max_price');
    });
};
