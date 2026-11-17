<?php
/**
 * AIFEC — esquema de las facturas y de las facturas rectificativas.
 *
 * Todos los importes en céntimos. Una factura guarda congelado lo que se facturó (emisor, receptor, líneas, impuestos por tipo
 * y totales): no se recalcula nunca con los datos de hoy.
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
    // type: invoice (factura) o credit (rectificativa de una devolución). unique_key = «invoice:<pedido>» o «credit:<devolución>»:
    // la base de datos misma impide dos facturas del mismo pedido o dos rectificativas de la misma devolución.
    // series + number = el contador sequences «invoice:<serie>»; full_number es lo que se imprime (única).
    // refund_id = la devolución (order_refunds) que rectifica; original_id = la factura que rectifica.
    // net, tax y total son los de data (las rectificativas, en negativo); data = JSON congelado:
    //   lang, issuer, receiver, order, lines, discounts, taxes ([name, rate, base, amount]), totals, legal_text, payment_title, original, reason, returned.
    // pdf_path = ruta del PDF dentro de la carpeta privada del sitio (se genera la primera vez que se pide). test = pedido de pruebas (serie TEST).
    $schema->create('invoices', static function (TableDef $t): void {
        $t->id();
        $t->string('unique_key', 60);
        $t->bigInteger('order_id');
        $t->string('type', 10)->default('invoice');
        $t->string('series', 20);
        $t->integer('number');
        $t->string('full_number', 40);
        $t->datetime('issued_at');
        $t->bigInteger('refund_id')->nullable();
        $t->bigInteger('original_id')->nullable();
        $t->string('currency', 3)->default('EUR');
        $t->integer('net')->default(0);
        $t->integer('tax')->default(0);
        $t->integer('total')->default(0);
        $t->boolean('test')->default(0);
        $t->text('data');
        $t->string('pdf_path', 191)->nullable();
        $t->timestamps();
        $t->unique(['unique_key']);
        $t->unique(['full_number']);
        $t->index(['order_id']);
        $t->index(['issued_at']);
        $t->index(['series', 'number']);
    });
};
