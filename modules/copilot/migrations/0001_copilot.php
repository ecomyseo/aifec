<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Tabla del copiloto del panel: una fila por cada orden que escribe una persona del equipo, con lo que se entendió, lo que
 * se vio antes de aplicar y lo necesario para deshacerlo. Una migración se ejecuta antes de que el cargador de clases
 * conozca el módulo y no puede usar sus clases.
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
    // status: proposed (lista para aplicar), incomplete (falta un dato o hay varias lecturas), applied, undone, discarded, failed.
    // action vacía = no se entendió. args, preview y undo son JSON; fingerprint = huella de lo que se enseñó (acción, datos y cambios).
    // source: rules o ai (quién entendió la orden). result = lo que contestó el sistema al aplicar o deshacer.
    $schema->create('copilot_proposals', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('user_id')->nullable();
        $t->string('user_name', 120)->nullable();
        $t->text('text');
        $t->string('action', 80)->default('');
        $t->text('args')->nullable();
        $t->text('preview')->nullable();
        $t->string('status', 12)->default('incomplete');
        $t->string('fingerprint', 64)->default('');
        $t->text('undo')->nullable();
        $t->text('result')->nullable();
        $t->string('source', 8)->default('rules');
        $t->datetime('created_at');
        $t->datetime('updated_at');
        $t->datetime('applied_at')->nullable();
        $t->datetime('undone_at')->nullable();
        $t->index(['user_id', 'created_at']);
        $t->index(['status', 'created_at']);
        $t->index(['updated_at']);
    });
};
