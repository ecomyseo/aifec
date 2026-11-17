<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Tablas del asistente: una conversación por visitante (identificada por una cookie propia, de la que solo se guarda
 * el resumen SHA-256) y sus mensajes. Una migración se ejecuta antes de que el cargador de clases conozca el módulo y
 * no puede usar sus clases.
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
    // messages = mensajes del cliente (no cuenta los del asistente); unanswered = cuántos no supo contestar.
    // mode: ai o rules (el último que contestó). cart_added / checkout / order_id: hasta dónde llegó la conversación.
    // state: JSON corto con lo que el asistente espera del cliente («expect» = postcode, order) y las últimas fichas enseñadas.
    $schema->create('conversations', static function (TableDef $t): void {
        $t->id();
        $t->string('token_hash', 64);
        $t->string('lang', 8)->default('es');
        $t->string('mode', 8)->default('rules');
        $t->datetime('started_at');
        $t->datetime('last_at');
        $t->integer('messages')->default(0);
        $t->integer('unanswered')->default(0);
        $t->boolean('cart_added')->default(0);
        $t->boolean('checkout')->default(0);
        $t->bigInteger('order_id')->nullable();
        $t->text('state')->nullable();
        $t->unique(['token_hash']);
        $t->index(['last_at']);
    });

    // role: user, assistant. tools: JSON con las herramientas usadas (nombre, si salió bien y la entrada sin correos).
    // unanswered y phrase solo en mensajes del cliente: phrase es el texto normalizado y sin datos personales, para agrupar.
    $schema->create('conversation_messages', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('conversation_id');
        $t->string('role', 12);
        $t->text('content');
        $t->text('tools')->nullable();
        $t->boolean('unanswered')->default(0);
        $t->string('phrase', 160)->nullable();
        $t->datetime('created_at');
        $t->index(['conversation_id']);
        $t->index(['unanswered', 'created_at']);
    });
};
