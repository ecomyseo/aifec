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

return static function (Schema $schema, Db $db): void {
    // Las sesiones de compra de UCP. public_id es el identificador que ve la plataforma; el carrito sale de él. snapshot =
    // la sesión tal como quedó al hacer el pedido o al cancelarla (JSON), para poder devolverla después.
    $schema->create('ucp_checkouts', static function (TableDef $t): void {
        $t->id();
        $t->string('public_id', 40);
        $t->string('status', 20)->default('open');
        $t->string('platform', 120)->nullable();
        $t->bigInteger('key_id')->nullable();
        $t->bigInteger('order_id')->nullable();
        $t->string('order_number', 40)->nullable();
        $t->text('order_url')->nullable();
        $t->text('buyer')->nullable();
        $t->text('snapshot')->nullable();
        $t->timestamps();
        $t->unique(['public_id']);
        $t->index(['order_id']);
    });

    // Idempotency-Key: la respuesta de cada petición repetible, un día. key_hash = SHA-256 de método, ruta y clave.
    $schema->create('ucp_requests', static function (TableDef $t): void {
        $t->id();
        $t->string('key_hash', 64);
        $t->string('request_hash', 64);
        $t->integer('status')->default(0);
        $t->text('body')->nullable();
        $t->datetime('created_at');
        $t->unique(['key_hash']);
        $t->index(['created_at']);
    });
};
