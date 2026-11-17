<?php
/**
 * AIFEC — índices de los informes de ventas: los pedidos se buscan por la fecha del cobro y las devoluciones por su fecha.
 * Sin ellos, cada informe recorrería la tabla entera.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;

return static function (Schema $schema, Db $db): void {
    $schema->addIndex('orders', ['paid_at']);
    $schema->addIndex('order_refunds', ['created_at']);
};
