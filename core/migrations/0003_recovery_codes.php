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
 * Códigos de recuperación de la verificación en dos pasos: diez por persona, de un solo uso. Solo se guarda el resumen
 * (HMAC con la clave del sitio), nunca el código. used_at lo pone quien lo gasta; la fila sigue ahí para contar los que quedan.
 */
return static function (Schema $schema, Db $db): void {
    $schema->create('recovery_codes', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('user_id');
        $t->string('hash', 64);
        $t->datetime('used_at')->nullable();
        $t->datetime('created_at');
        $t->unique(['hash']);
        $t->index(['user_id']);
    });
};
