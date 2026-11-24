<?php
/**
 * AIFEC — AI-First E-Commerce.
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
    // La prueba de lo que se le preguntó: el tipo de pedido (download, service, both) y el texto exacto de la casilla que vio.
    // Los pedidos anteriores quedan con el tipo «download» y sin texto (entonces solo había descargas).
    $schema->addColumn('gm_legal_orders', static function (TableDef $t): void {
        $t->string('kind', 10)->default('download');
        $t->text('text')->nullable();
    });
};
