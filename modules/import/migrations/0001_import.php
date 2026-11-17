<?php
/**
 * AIFEC — esquema de la migración desde otras tiendas.
 *
 * import_runs  una fila por migración (o por simulacro): de dónde, con qué opciones, por dónde va y sus cuentas.
 *              Las credenciales del origen NO van aquí: se guardan cifradas con App::setSecret('import.conn.<id>').
 *              cursor avanza por lo LEÍDO del origen, nunca por lo creado. status acaba siempre en done, failed o cancelled.
 * import_map   casa cada registro del origen con el de aquí. source_key distingue dos tiendas del mismo tipo.
 *              source_hash = resumen del registro de origen (¿cambió allí?); written_at = cuándo se escribió aquí
 *              (si el registro de aquí se tocó después, alguien lo editó y no se pisa sin avisar).
 * import_log   lo que hay que poder enseñar registro a registro: descartados, choques, errores y notas.
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
    $schema->create('import_runs', static function (TableDef $t): void {
        $t->id();
        $t->string('source', 20);
        $t->string('mode', 8);
        $t->string('source_key', 60);
        $t->string('label', 120)->nullable();
        $t->text('options')->nullable();
        $t->boolean('dry_run')->default(0);
        $t->string('status', 12)->default('draft');
        $t->string('phase', 12)->default('write');
        $t->string('current_type', 30)->nullable();
        $t->text('cursor')->nullable();
        $t->text('counters')->nullable();
        $t->text('equivalences')->nullable();
        $t->text('unmapped')->nullable();
        $t->text('checksums')->nullable();
        $t->string('pause_reason', 191)->nullable();
        $t->integer('source_errors')->default(0);
        $t->bigInteger('user_id')->nullable();
        $t->datetime('started_at')->nullable();
        $t->datetime('heartbeat_at')->nullable();
        $t->datetime('finished_at')->nullable();
        $t->timestamps();
        $t->index(['status']);
    });

    $schema->create('import_map', static function (TableDef $t): void {
        $t->id();
        $t->string('source', 20);
        $t->string('source_key', 60);
        $t->string('type', 30);
        $t->string('source_id', 64);
        $t->bigInteger('local_id');
        $t->string('source_hash', 40);
        $t->bigInteger('run_id');
        $t->datetime('written_at');
        $t->unique(['source', 'source_key', 'type', 'source_id']);
        $t->index(['type', 'local_id']);
    });

    $schema->create('import_log', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('run_id');
        $t->string('type', 30);
        $t->string('source_id', 64)->nullable();
        $t->string('result', 12);
        $t->string('reason', 255)->nullable();
        $t->datetime('created_at');
        $t->index(['run_id', 'type', 'result']);
    });
};
