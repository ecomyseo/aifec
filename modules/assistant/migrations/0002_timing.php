<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Lo que tarda cada respuesta del asistente: milisegundos de la IA, milisegundos de las herramientas y rondas de herramientas.
 * El panel enseña la mediana y el percentil 90 de los últimos siete días. Una migración se ejecuta antes de que el cargador de
 * clases conozca el módulo y no puede usar sus clases.
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
    // Solo en los mensajes del asistente. ai_ms vacío = se contestó por reglas, sin IA; el resto de filas (y las anteriores a
    // esta versión) quedan vacías y no cuentan en las estadísticas.
    $schema->addColumn('conversation_messages', static function (TableDef $t): void {
        $t->integer('ai_ms')->nullable();
        $t->integer('tools_ms')->nullable();
        $t->integer('rounds')->nullable();
    });
};
