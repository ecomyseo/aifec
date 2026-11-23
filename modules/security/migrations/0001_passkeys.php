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
 * Passkeys de las personas del panel. Solo se guarda la clave PÚBLICA (la privada no sale nunca del dispositivo), el identificador
 * de la credencial y el contador de usos que ayuda a detectar una clave copiada.
 */
return static function (Schema $schema, Db $db): void {
    $schema->create('passkeys', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('user_id');
        $t->string('credential_id', 190);
        $t->text('public_key');
        $t->bigInteger('sign_count')->default(0);
        $t->string('name', 80);
        $t->datetime('created_at');
        $t->datetime('last_used_at')->nullable();
        $t->unique(['credential_id']);
        $t->index(['user_id']);
    });
};
