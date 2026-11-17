<?php
/**
 * AIFEC — esquema del módulo de traducciones: los trabajos de la IA (uno por idioma y fila de la tabla de cobertura) y las propuestas
 * de la IA que este módulo guarda él mismo (los textos de la interfaz y las etiquetas de los menús; las de productos y páginas van a
 * la bandeja del módulo de IA).
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
    // status: running, paused, done, cancelled. cursor = lo último CONSUMIDO (leído, saliera bien o mal); su forma depende de la fila.
    $schema->create('translation_jobs', static function (TableDef $t): void {
        $t->id();
        $t->string('lang', 8);
        $t->string('scope', 24);
        $t->string('status', 12)->default('running');
        $t->integer('total')->default(0);
        $t->integer('done')->default(0);
        $t->integer('failed')->default(0);
        $t->integer('streak')->default(0);
        $t->integer('chunks')->default(0);
        $t->text('cursor')->nullable();
        $t->string('reason', 300)->nullable();
        $t->bigInteger('created_by')->nullable();
        $t->datetime('created_at');
        $t->datetime('updated_at');
        $t->datetime('finished_at')->nullable();
        $t->index(['status']);
        $t->index(['lang', 'scope']);
    });

    // Una sola propuesta por idioma + fila + elemento (item: el texto inglés de la interfaz, o «código:posición» de un menú).
    $schema->create('translation_proposals', static function (TableDef $t): void {
        $t->id();
        $t->string('lang', 8);
        $t->string('scope', 24);
        $t->string('item_hash', 40);
        $t->text('item');
        $t->text('source')->nullable();
        $t->text('text');
        $t->bigInteger('job_id')->nullable();
        $t->datetime('created_at');
        $t->unique(['lang', 'scope', 'item_hash']);
    });
};
