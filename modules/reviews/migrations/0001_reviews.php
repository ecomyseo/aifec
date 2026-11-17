<?php
/**
 * AIFEC — esquema de las valoraciones: las opiniones de los compradores y las peticiones que se mandan por correo.
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
    // Una opinión. status: pending (espera moderación), published, rejected. verified = hay un pedido pagado de ese correo con
    // ese producto (order_id es ese pedido). El correo se guarda en minúsculas: una opinión por producto y correo.
    // reply = respuesta de la tienda, en texto plano. rating es de 1 a 5.
    $schema->create('reviews', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('product_id');
        $t->bigInteger('order_id')->nullable();
        $t->bigInteger('customer_id')->nullable();
        $t->string('name', 80);
        $t->string('email', 191);
        $t->integer('rating');
        $t->string('title', 120)->nullable();
        $t->text('body');
        $t->string('lang', 8)->default('es');
        $t->string('status', 12)->default('pending');
        $t->boolean('verified')->default(0);
        $t->text('reply')->nullable();
        $t->datetime('replied_at')->nullable();
        $t->datetime('published_at')->nullable();
        $t->timestamps();
        $t->unique(['product_id', 'email']);
        $t->index(['product_id', 'status']);
        $t->index(['status', 'created_at']);
    });

    // La petición de opinión de un producto de un pedido completado. Se anota al completarse el pedido (nunca se recorre el
    // histórico) con su fecha de envío prevista. status: pending, sent, used (ya valoró), expired (pasó más de un día de la fecha
    // sin mandarse). token = resumen de la ficha de un solo uso del enlace del correo (la ficha en claro sale de la clave del sitio).
    $schema->create('review_requests', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->bigInteger('product_id');
        $t->string('email', 191);
        $t->string('name', 80)->nullable();
        $t->string('lang', 8)->default('es');
        $t->string('token', 64);
        $t->string('status', 12)->default('pending');
        $t->datetime('send_at');
        $t->datetime('sent_at')->nullable();
        $t->datetime('used_at')->nullable();
        $t->bigInteger('review_id')->nullable();
        $t->datetime('created_at');
        $t->unique(['order_id', 'product_id']);
        $t->unique(['token']);
        $t->index(['status', 'send_at']);
    });
};
