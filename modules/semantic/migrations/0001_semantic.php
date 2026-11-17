<?php
/**
 * AIFEC — esquema de la auditoría AIFEC y del panel de robots e IA.
 *
 * audit_scores guarda la última nota de cada entidad (hoy, los productos) con la lista de comprobaciones que fallan.
 * bot_visits suma por día las visitas de robots conocidos y de personas que llegan desde un asistente; nunca guarda
 * direcciones IP ni agentes de usuario completos. bot_offsets recuerda hasta dónde se ha leído cada registro diario.
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
    // entity: product. fails = JSON con los códigos de las comprobaciones que no se cumplen, de más a menos peso.
    $schema->create('audit_scores', static function (TableDef $t): void {
        $t->string('entity', 16);
        $t->bigInteger('entity_id');
        $t->integer('score')->default(0);
        $t->text('fails')->nullable();
        $t->datetime('checked_at');
        $t->primary(['entity', 'entity_id']);
        $t->index(['entity', 'score']);
    });

    // day: «2026-11-12» (UTC). kind: bot (un robot) o ref (una visita llegada desde un asistente). family: search, assistant,
    // training, seo u other. page_class: product, category, text, llms, feed, api u other.
    $schema->create('bot_visits', static function (TableDef $t): void {
        $t->id();
        $t->string('day', 10);
        $t->string('kind', 3);
        $t->string('name', 60);
        $t->string('family', 16);
        $t->string('page_class', 12);
        $t->integer('visits')->default(0);
        $t->datetime('last_at');
        $t->unique(['day', 'kind', 'name', 'page_class']);
        $t->index(['day']);
    });

    // file: nombre del registro («visits-2026-11-12.tsv»). pos: bytes ya leídos.
    $schema->create('bot_offsets', static function (TableDef $t): void {
        $t->string('file', 60);
        $t->bigInteger('pos')->default(0);
        $t->datetime('updated_at');
        $t->primary(['file']);
    });
};
