<?php
/**
 * AIFEC — movimientos de existencias: cada cambio de stock queda apuntado con su motivo.
 *
 * delta negativo = sale mercancía (venta), positivo = entra (reposición, cancelación). stock_after es lo que queda
 * justo después. variant_id vale 0 cuando el movimiento es de un producto sin variantes. reference es libre (el
 * número de pedido, el albarán…).
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
    $schema->create('stock_moves', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->bigInteger('variant_id')->default(0);
        $t->integer('delta');
        $t->integer('stock_after');
        $t->string('reason', 191);
        $t->string('reference', 191)->nullable();
        $t->datetime('created_at');
        $t->index(['product_id', 'created_at']);
        $t->index(['reference']);
    });
};
