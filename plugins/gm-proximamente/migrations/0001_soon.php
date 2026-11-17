<?php
/**
 * AIFEC — GM coming soon: las dos tablas del plugin (productos «próximamente» y peticiones de aviso).
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
    // Un producto marcado «próximamente». release_at: si lo trae, una tarea lo pasa a disponible cuando llega (UTC).
    $schema->create('gm_soon_products', static function (TableDef $t): void {
        $t->bigInteger('product_id');
        $t->boolean('soon')->default(1);
        $t->datetime('release_at')->nullable();
        $t->datetime('updated_at');
        $t->primary(['product_id']);
    });

    // «Avísame»: una petición por correo y producto. consented_at es la prueba de que marcó la casilla; notified_at, que ya se
    // le avisó (nunca se vuelve a avisar por la misma petición). Se borran a los 90 días.
    $schema->create('gm_soon_requests', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->string('email', 191);
        $t->string('lang', 8)->default('');
        $t->datetime('consented_at');
        $t->datetime('created_at');
        $t->datetime('notified_at')->nullable();
        $t->unique(['product_id', 'email']);
        $t->index(['created_at']);
    });
};
