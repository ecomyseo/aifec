<?php
/**
 * AIFEC — el buscador por significado: cómo acabó cada búsqueda (exact, relaxed, related, none o shop), para el resumen del panel.
 * Sigue sin guardar quién busca: solo una palabra más por fila del registro de siempre.
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
    // Vacío = una búsqueda anterior a esta versión (no se sabe cómo acabó).
    $schema->addColumn('search_log', static function (TableDef $t): void {
        $t->string('outcome', 8)->default('');
    });
};
