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

/*
 * El código de la dirección pública de un fichero descargable (public/descargas/<código>/…). Las instalaciones nuevas ya traen la
 * columna desde la migración 0001; las que ya existían la reciben aquí. NULL = el fichero es privado.
 */
return static function (Schema $schema, Db $db): void {
    if (!$schema->hasColumn('product_files', 'public_token')) {
        $schema->addColumn('product_files', static function (TableDef $t): void {
            $t->string('public_token', 64)->nullable();
        });
    }
};
