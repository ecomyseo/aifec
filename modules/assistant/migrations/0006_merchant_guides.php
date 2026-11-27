<?php
/** AIFEC — contenido editorial de la tienda por idioma. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->create('assistant_guides', static function (TableDef $t): void {
        $t->id();
        $t->string('kind', 24);
        $t->string('lang', 8);
        $t->string('title', 160);
        $t->text('phrases');
        $t->text('product_ids');
        $t->text('body');
        $t->boolean('active')->default(0);
        $t->index(['active', 'lang']);
    });
};
