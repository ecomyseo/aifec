<?php
/**
 * AIFEC — GM legal texts per product: las tablas de los bloques legales, sus asignaciones y el registro por pedido.
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
    // Un bloque legal reutilizable. kind: guarantee, withdrawal, compatibility, support, other. body: texto sin formato, una línea
    // = un párrafo (se escapa al pintarlo). in_mail: también va en el correo del pedido.
    $schema->create('gm_legal_blocks', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 60);
        $t->string('title', 160);
        $t->string('kind', 20)->default('other');
        $t->text('body');
        $t->boolean('active')->default(1);
        $t->boolean('in_mail')->default(1);
        $t->integer('position')->default(0);
        $t->datetime('created_at');
        $t->datetime('updated_at');
        $t->unique(['code']);
    });

    // A qué se aplica cada bloque: target_type «product» o «category» (con las subcategorías de esta).
    $schema->create('gm_legal_assignments', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('block_id');
        $t->string('target_type', 10);
        $t->bigInteger('target_id');
        $t->unique(['block_id', 'target_type', 'target_id']);
        $t->index(['target_type', 'target_id']);
    });

    // Un pedido con contenido digital: si marcó la renuncia al desistimiento (waiver 1 o 0) y con qué versión de los textos;
    // mailed_at: cuándo se mandó el correo con los textos (una sola vez por pedido).
    $schema->create('gm_legal_orders', static function (TableDef $t): void {
        $t->bigInteger('order_id');
        $t->boolean('waiver')->nullable();
        $t->string('version', 40)->default('');
        $t->datetime('created_at');
        $t->datetime('mailed_at')->nullable();
        $t->primary(['order_id']);
    });
};
