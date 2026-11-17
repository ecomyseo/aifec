<?php
/**
 * AIFEC — esquema del módulo de IA: gasto, propuestas, lotes y marcas de lo aprobado.
 *
 * El coste se guarda en millonésimas de céntimo (cost_micro): fichas × céntimos por millón de fichas, todo en enteros.
 * «day» es el día UTC (Y-m-d) y sirve para el tope diario.
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
    // Una fila por llamada al modelo, bien o mal. El error nunca lleva claves.
    $schema->create('ai_usage', static function (TableDef $t): void {
        $t->id();
        $t->string('day', 10);
        $t->string('task', 80);
        $t->string('tier', 16);
        $t->string('provider', 24);
        $t->string('model', 120);
        $t->integer('tokens_in')->default(0);
        $t->integer('tokens_out')->default(0);
        $t->bigInteger('cost_micro')->default(0);
        $t->integer('ms')->default(0);
        $t->boolean('ok')->default(1);
        $t->text('error')->nullable();
        $t->datetime('created_at');
        $t->index(['day']);
        $t->index(['day', 'task']);
    });

    // status: pending, approved, rejected. lang vacío = la propuesta no es de un idioma (el texto alternativo de una imagen).
    $schema->create('ai_proposals', static function (TableDef $t): void {
        $t->id();
        $t->string('entity', 32);
        $t->bigInteger('entity_id');
        $t->string('field', 40);
        $t->string('lang', 8)->default('');
        $t->text('current_text')->nullable();
        $t->text('proposed_text');
        $t->string('status', 12)->default('pending');
        $t->string('model', 120)->default('');
        $t->string('task', 80)->default('');
        $t->bigInteger('created_by')->nullable();
        $t->bigInteger('resolved_by')->nullable();
        $t->datetime('created_at');
        $t->datetime('resolved_at')->nullable();
        $t->index(['entity', 'entity_id', 'field', 'lang', 'status']);
        $t->index(['status', 'created_at']);
    });

    // status: running, paused, done, cancelled. last_id = el último producto consumido (leído, haya salido bien o mal).
    $schema->create('ai_batches', static function (TableDef $t): void {
        $t->id();
        $t->string('task', 40);
        $t->text('filter');
        $t->text('options')->nullable();
        $t->integer('total')->default(0);
        $t->integer('done')->default(0);
        $t->integer('failed')->default(0);
        $t->integer('streak')->default(0);
        $t->bigInteger('last_id')->default(0);
        $t->string('status', 12)->default('running');
        $t->text('reason')->nullable();
        $t->bigInteger('created_by')->nullable();
        $t->datetime('created_at');
        $t->datetime('updated_at')->nullable();
        $t->datetime('finished_at')->nullable();
        $t->index(['status']);
    });

    // Lo que una persona aprobó: quién, cuándo y con qué modelo. Es lo que enseña la marca de la tienda.
    $schema->create('ai_marks', static function (TableDef $t): void {
        $t->id();
        $t->string('entity', 32);
        $t->bigInteger('entity_id');
        $t->string('field', 40);
        $t->string('lang', 8)->default('');
        $t->bigInteger('user_id')->nullable();
        $t->string('model', 120)->default('');
        $t->datetime('created_at');
        $t->index(['entity', 'entity_id']);
    });
};
