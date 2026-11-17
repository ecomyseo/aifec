<?php
/**
 * AIFEC — esquema de ventas: carritos, pedidos y sus líneas, historial, cobros, devoluciones, descargas y contadores.
 *
 * Todos los importes en céntimos. El pedido guarda lo que se cobró, congelado: no se recalcula con los precios de hoy.
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
    // Contadores correlativos (números de pedido y de factura). Se avanza dentro de una transacción.
    $schema->create('sequences', static function (TableDef $t): void {
        $t->string('name', 60);
        $t->bigInteger('value')->default(0);
        $t->primary(['name']);
    });

    // El carrito vive en una fila: token = resumen de la ficha de la cookie; items = JSON
    // [{"key":"p12-v0-ab12","product_id":12,"variant_id":0,"qty":2,"data":{…}}]. destination = JSON {country, region, postcode}.
    $schema->create('carts', static function (TableDef $t): void {
        $t->id();
        $t->string('token', 64);
        $t->bigInteger('customer_id')->nullable();
        $t->string('lang', 8)->default('es');
        $t->text('items')->nullable();
        $t->text('coupons')->nullable();
        $t->text('destination')->nullable();
        $t->bigInteger('carrier_id')->nullable();
        $t->string('payment_method', 40)->nullable();
        $t->string('email', 191)->nullable();
        $t->text('checkout')->nullable();
        $t->string('source', 20)->default('web');
        $t->bigInteger('order_id')->nullable();
        $t->timestamps();
        $t->unique(['token']);
        $t->index(['customer_id']);
        $t->index(['updated_at']);
    });

    // status = código del estado (order_states). payment_status: pending, authorized, paid, partially_refunded, refunded, failed, cancelled.
    // fulfillment_status: unfulfilled, partial, fulfilled, returned, not_needed (solo digitales o servicios).
    // source: web, assistant, api, mcp, admin, import. secret = resumen de la ficha para ver el pedido sin cuenta.
    $schema->create('orders', static function (TableDef $t): void {
        $t->id();
        $t->string('number', 32);
        $t->bigInteger('customer_id')->nullable();
        $t->string('email', 191);
        $t->string('lang', 8)->default('es');
        $t->string('currency', 3)->default('EUR');
        $t->string('status', 32)->default('pending_payment');
        $t->string('payment_status', 20)->default('pending');
        $t->string('fulfillment_status', 20)->default('unfulfilled');
        $t->string('payment_method', 40)->nullable();
        $t->string('payment_title', 120)->nullable();
        $t->bigInteger('carrier_id')->nullable();
        $t->string('carrier_title', 120)->nullable();
        $t->string('tracking_code', 80)->nullable();
        $t->boolean('prices_include_tax')->default(1);
        $t->integer('items_total')->default(0);
        $t->integer('discount_total')->default(0);
        $t->integer('shipping_total')->default(0);
        $t->integer('fee_total')->default(0);
        $t->integer('total_net')->default(0);
        $t->integer('total_tax')->default(0);
        $t->integer('total')->default(0);
        $t->integer('paid_total')->default(0);
        $t->integer('refunded_total')->default(0);
        $t->text('taxes')->nullable();
        $t->text('adjustments')->nullable();
        $t->text('billing')->nullable();
        $t->text('shipping')->nullable();
        $t->text('coupons')->nullable();
        $t->text('customer_note')->nullable();
        $t->string('source', 20)->default('web');
        $t->text('attribution')->nullable();
        $t->string('secret', 64)->nullable();
        $t->bigInteger('cart_id')->nullable();
        $t->boolean('test')->default(0);
        $t->string('ip', 45)->nullable();
        $t->string('legacy_id', 60)->nullable();
        $t->datetime('placed_at');
        $t->datetime('paid_at')->nullable();
        $t->datetime('shipped_at')->nullable();
        $t->datetime('completed_at')->nullable();
        $t->datetime('cancelled_at')->nullable();
        $t->timestamps();
        $t->unique(['number']);
        $t->index(['customer_id', 'placed_at']);
        $t->index(['status', 'placed_at']);
        $t->index(['payment_status', 'placed_at']);
        $t->index(['email']);
        $t->index(['legacy_id']);
    });

    // kind: product, shipping, fee, discount (línea de ajuste visible). unit_price y line_total «tal como se cobró»
    // (con o sin impuestos según orders.prices_include_tax); line_net + line_tax = importe con impuestos de la línea.
    $schema->create('order_items', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->string('kind', 12)->default('product');
        $t->bigInteger('product_id')->nullable();
        $t->bigInteger('variant_id')->nullable();
        $t->string('sku', 64)->nullable();
        $t->string('name', 191);
        $t->string('variant_label', 191)->nullable();
        $t->integer('qty')->default(1);
        $t->integer('unit_price')->default(0);
        $t->integer('discount')->default(0);
        $t->integer('line_total')->default(0);
        $t->integer('line_net')->default(0);
        $t->integer('line_tax')->default(0);
        $t->integer('tax_rate')->default(0);
        $t->string('tax_name', 60)->nullable();
        $t->integer('weight')->default(0);
        $t->boolean('is_digital')->default(0);
        $t->integer('refunded_qty')->default(0);
        $t->integer('refunded_total')->default(0);
        $t->text('data')->nullable();
        $t->integer('position')->default(0);
        $t->index(['order_id', 'position']);
        $t->index(['product_id']);
    });

    // Todo lo que le pasa a un pedido, en orden. kind: status, payment, shipment, refund, note, mail, system.
    // is_public = el cliente lo ve en su cuenta.
    $schema->create('order_history', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->string('kind', 12);
        $t->string('from_value', 40)->nullable();
        $t->string('to_value', 40)->nullable();
        $t->text('note')->nullable();
        $t->boolean('is_public')->default(0);
        $t->bigInteger('user_id')->nullable();
        $t->string('actor', 60)->nullable();
        $t->text('data')->nullable();
        $t->datetime('created_at');
        $t->index(['order_id', 'created_at']);
    });

    // Movimientos de dinero. kind: charge, authorization, refund. status: pending, ok, failed.
    // (method, reference, kind) es único: el mismo aviso de la pasarela no se apunta dos veces.
    $schema->create('order_payments', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->string('method', 40);
        $t->string('kind', 16)->default('charge');
        $t->string('reference', 120);
        $t->integer('amount')->default(0);
        $t->string('currency', 3)->default('EUR');
        $t->string('status', 12)->default('pending');
        $t->boolean('test')->default(0);
        $t->text('raw')->nullable();
        $t->datetime('created_at');
        $t->unique(['method', 'reference', 'kind']);
        $t->index(['order_id']);
    });

    $schema->create('order_refunds', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->integer('amount')->default(0);
        $t->integer('tax')->default(0);
        $t->text('items')->nullable();
        $t->string('reason', 255)->nullable();
        $t->boolean('restock')->default(0);
        $t->bigInteger('payment_id')->nullable();
        $t->bigInteger('user_id')->nullable();
        $t->datetime('created_at');
        $t->index(['order_id']);
    });

    // Derecho a descargar un fichero comprado. token = resumen de la ficha del enlace.
    $schema->create('order_downloads', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('order_id');
        $t->bigInteger('order_item_id');
        $t->bigInteger('file_id');
        $t->string('token', 64);
        $t->integer('downloads')->default(0);
        $t->integer('max_downloads')->default(0);
        $t->datetime('expires_at')->nullable();
        $t->datetime('last_at')->nullable();
        $t->datetime('created_at');
        $t->unique(['token']);
        $t->index(['order_id']);
    });

    // Estados de pedido. kind: open (en curso), done (terminado bien), cancelled. mail = plantilla que se manda al entrar.
    $schema->create('order_states', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 32);
        $t->string('name', 80);
        $t->string('kind', 12)->default('open');
        $t->string('color', 12)->default('info');
        $t->string('mail', 60)->nullable();
        $t->boolean('system')->default(0);
        $t->integer('position')->default(0);
        $t->unique(['code']);
    });

    $states = [
        ['pending_payment', 'Pending payment', 'open', 'warn', null],
        ['on_hold', 'On hold', 'open', 'warn', 'order_on_hold'],
        ['processing', 'Processing', 'open', 'info', 'order_confirmed'],
        ['shipped', 'Shipped', 'open', 'info', 'order_shipped'],
        ['completed', 'Completed', 'done', 'ok', 'order_completed'],
        ['cancelled', 'Cancelled', 'cancelled', 'bad', 'order_cancelled'],
        ['refunded', 'Refunded', 'cancelled', 'bad', 'order_refunded'],
        ['failed', 'Payment failed', 'cancelled', 'bad', null],
    ];
    foreach ($states as $i => $state) {
        $db->insertIgnore('order_states', ['code' => $state[0], 'name' => $state[1], 'kind' => $state[2], 'color' => $state[3], 'mail' => $state[4], 'system' => 1, 'position' => $i]);
    }
};
