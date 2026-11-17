<?php
/**
 * AIFEC — esquema de la capa de datos: la marca de cada pedido hecho con el módulo encendido y los eventos del servidor.
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
    // Una fila por pedido HECHO con el módulo encendido (nunca se rellena con pedidos antiguos). Se crea al hacer el pedido, con
    // la petición del visitante delante: ahí se lee su decisión de cookies y sus identificadores de medición. consent = letras
    // concedidas en ese momento (s estadísticas, m marketing), ga_client_id / fbp / fbc = valores de sus cookies de medición,
    // ai_source = asistente de IA del que llegó, assistant = el pedido lo hizo el asistente de la tienda.
    // browser_at = la compra ya se empujó en el navegador (la marca de «una sola vez»).
    $schema->create('datalayer_orders', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->string('event_id', 64);
        $t->string('consent', 8)->default('');
        $t->string('ga_client_id', 64)->nullable();
        $t->string('fbp', 80)->nullable();
        $t->string('fbc', 120)->nullable();
        $t->string('ai_source', 40)->nullable();
        $t->boolean('assistant')->default(0);
        $t->datetime('browser_at')->nullable();
        $t->datetime('created_at');
        $t->unique(['order_id']);
    });

    // Lo que el servidor manda (o iba a mandar) a GA4 y a Meta. kind: purchase o refund. destination: ga4 o meta. status: queued,
    // sent, failed o skipped. event_id es el mismo que el del navegador. (order_id, kind, destination, ref) es único: no se
    // encola dos veces; ref distingue varios reembolsos del mismo pedido.
    $schema->create('datalayer_events', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->string('kind', 12);
        $t->string('destination', 8);
        $t->string('ref', 40)->default('');
        $t->string('event_id', 80);
        $t->string('status', 12)->default('queued');
        $t->integer('http_status')->nullable();
        $t->string('response', 500)->nullable();
        $t->datetime('created_at');
        $t->datetime('sent_at')->nullable();
        $t->unique(['order_id', 'kind', 'destination', 'ref']);
        $t->index(['created_at']);
    });
};
