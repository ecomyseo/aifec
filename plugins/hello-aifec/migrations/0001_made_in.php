<?php
/**
 * AIFEC — Hello AIFEC: tabla del país de fabricación de cada producto.
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
    $schema->create('hello_aifec_made_in', static function (TableDef $t): void {
        $t->bigInteger('product_id');
        $t->string('country', 80);
        $t->datetime('updated_at');
        $t->primary(['product_id']);
    });
};
