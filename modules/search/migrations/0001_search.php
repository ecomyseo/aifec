<?php
/**
 * AIFEC — esquema del buscador: documentos indexados, vocabulario, listas de apariciones, registro de búsquedas y sinónimos.
 *
 * El índice guarda, por documento (un producto, una página o una entrada, en un idioma), la longitud de cada campo y,
 * por término, en cuántos documentos sale y cuántas veces en cada campo. El registro de búsquedas no guarda quién busca:
 * solo el día, el idioma, la consulta normalizada y cuántas veces se hizo.
 *
 * Los nombres de campo (name, ref, brand…) se repiten aquí a propósito: una migración se ejecuta antes de que
 * el cargador de clases conozca el módulo y no puede usar sus clases.
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
    $fields = ['name', 'ref', 'brand', 'cats', 'tags', 'attrs', 'summary', 'desc', 'extra'];

    // Un documento por (tipo, entidad, idioma). label y name_norm sirven para las sugerencias; sales e in_stock, para desempatar.
    // extra_terms: formas de buscarlo que propuso la IA (NULL = nunca se pidieron). vector: el vector de significado en base64.
    $schema->create('search_docs', static function (TableDef $t) use ($fields): void {
        $t->id();
        $t->string('type', 12);
        $t->bigInteger('entity_id');
        $t->string('lang', 8);
        $t->string('label', 191);
        $t->string('name_norm', 191);
        $t->integer('sales')->default(0);
        $t->boolean('in_stock')->default(1);
        foreach ($fields as $field) {
            $t->integer('len_' . $field)->default(0);
        }
        $t->text('extra_terms')->nullable();
        $t->string('src_hash', 32)->default('');
        $t->text('vector')->nullable();
        $t->datetime('indexed_at');
        $t->unique(['type', 'entity_id', 'lang']);
        $t->index(['lang', 'type']);
    });

    // El vocabulario de cada idioma: la raíz, la forma más corta con que se escribió (para enseñarla) y en cuántos documentos sale.
    $schema->create('search_terms', static function (TableDef $t): void {
        $t->id();
        $t->string('lang', 8);
        $t->string('term', 100);
        $t->string('surface', 100)->default('');
        $t->integer('len')->default(0);
        $t->integer('df')->default(0);
        $t->unique(['lang', 'term']);
        $t->index(['lang', 'len']);
    });

    // Una fila por (término, documento) con las veces que sale en cada campo.
    $schema->create('search_postings', static function (TableDef $t) use ($fields): void {
        $t->bigInteger('term_id');
        $t->bigInteger('doc_id');
        foreach ($fields as $field) {
            $t->integer('f_' . $field)->default(0);
        }
        $t->primary(['term_id', 'doc_id']);
        $t->index(['doc_id']);
    });

    // Una fila por día, idioma y consulta normalizada. found = resultados de la última vez; missed = 1 si no se encontró nada que encajara.
    $schema->create('search_log', static function (TableDef $t): void {
        $t->id();
        $t->string('day', 10);
        $t->string('lang', 8);
        $t->string('phrase', 120);
        $t->integer('hits')->default(0);
        $t->integer('found')->default(0);
        $t->boolean('missed')->default(0);
        $t->unique(['day', 'lang', 'phrase']);
        $t->index(['day', 'missed']);
    });

    // mode: equal (todos significan lo mismo; los términos van separados por comas) u oneway (quien busca «source» también encuentra «terms»).
    // lang vacío = vale para todos los idiomas.
    $schema->create('synonyms', static function (TableDef $t): void {
        $t->id();
        $t->string('lang', 8)->default('');
        $t->string('mode', 8)->default('equal');
        $t->string('source', 120)->nullable();
        $t->text('terms');
        $t->timestamps();
        $t->index(['lang']);
    });
};
