<?php
/**
 * AIFEC — el buscador que entiende: cuántas veces se entendió cada tipo de filtro (precio, color, categoría…) por día e idioma.
 * No guarda la frase ni nada de quien busca: solo el tipo de filtro y las veces.
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
    $schema->create('search_filters', static function (TableDef $t): void {
        $t->id();
        $t->string('day', 10);
        $t->string('lang', 8);
        $t->string('kind', 20);
        $t->integer('hits')->default(0);
        $t->unique(['day', 'lang', 'kind']);
    });
};
