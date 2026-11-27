<?php
/** AIFEC — términos para aprendizaje observado con consentimiento estadístico. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
return static function (Schema $schema, Db $db): void {
    $schema->addColumn('conversation_messages', static function (TableDef $t): void { $t->string('learning_term', 28)->nullable(); });
    $schema->addColumn('conversation_messages', static function (TableDef $t): void { $t->string('learning_lang', 8)->nullable(); });
};
