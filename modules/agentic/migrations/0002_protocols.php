<?php
/**
 * AIFEC — tipo de sesión de comercio.
 * @author Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
return static function (Schema $schema, Db $db): void {
    $schema->addColumn('ucp_checkouts', static function (TableDef $t): void {
        $t->string('kind', 12)->default('checkout');
    });
};
