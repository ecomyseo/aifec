<?php
/**
 * AIFEC — esquema de las devoluciones y del desistimiento: la devolución, sus líneas y su historial.
 *
 * Importes en céntimos. Los nombres de las líneas se copian de la línea del pedido (el pedido congela lo cobrado).
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
    // kind: return (devolución) o withdrawal (desistimiento). status: requested, approved, received, refunded, rejected, cancelled.
    // open_key = el id del pedido mientras la devolución está abierta y NULL al cerrarla: la clave única impide, en la propia base de
    // datos, dos devoluciones abiertas del mismo pedido (varios NULL no chocan). reason = código del motivo.
    // photos = JSON [{path, name, mime, bytes}] en la carpeta privada del sitio. refunded_amount = lo ya devuelto de dinero.
    // full_order = la devolución cubre todas las unidades del pedido (el envío de ida solo se devuelve así).
    $schema->create('returns', static function (TableDef $t): void {
        $t->id();
        $t->string('number', 32);
        $t->bigInteger('order_id');
        $t->bigInteger('customer_id')->nullable();
        $t->string('email', 191);
        $t->string('lang', 8)->default('es');
        $t->string('currency', 3)->default('EUR');
        $t->string('kind', 12)->default('return');
        $t->string('status', 12)->default('requested');
        $t->string('reason', 40);
        $t->text('comment')->nullable();
        $t->text('photos')->nullable();
        $t->boolean('full_order')->default(0);
        $t->text('instructions')->nullable();
        $t->text('reject_reason')->nullable();
        $t->integer('refunded_amount')->default(0);
        $t->bigInteger('open_key')->nullable();
        $t->string('source', 12)->default('web');
        $t->datetime('requested_at');
        $t->datetime('approved_at')->nullable();
        $t->datetime('received_at')->nullable();
        $t->datetime('refunded_at')->nullable();
        $t->datetime('closed_at')->nullable();
        $t->timestamps();
        $t->unique(['number']);
        $t->unique(['open_key']);
        $t->index(['order_id']);
        $t->index(['status', 'created_at']);
        $t->index(['email']);
        $t->index(['customer_id']);
    });

    // Las unidades que se devuelven de cada línea del pedido. restock: se repone la existencia (se decide al recibirla).
    // refunded: el dinero de esta línea ya se devolvió (una devolución de dinero que se corta a medias se reanuda sin repetir nada).
    $schema->create('return_items', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('return_id');
        $t->bigInteger('order_item_id');
        $t->bigInteger('product_id')->nullable();
        $t->bigInteger('variant_id')->nullable();
        $t->string('sku', 64)->nullable();
        $t->string('name', 191);
        $t->string('variant_label', 191)->nullable();
        $t->integer('qty')->default(1);
        $t->boolean('restock')->default(0);
        $t->boolean('refunded')->default(0);
        $t->index(['return_id']);
        $t->index(['order_item_id']);
    });

    // Todo lo que le pasa a una devolución, en orden. kind: status, note, refund. actor: customer, staff o system.
    // notified: ese cambio avisó al cliente por correo.
    $schema->create('return_events', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('return_id');
        $t->string('kind', 12);
        $t->string('from_value', 40)->nullable();
        $t->string('to_value', 40)->nullable();
        $t->text('note')->nullable();
        $t->string('actor', 12)->default('system');
        $t->bigInteger('user_id')->nullable();
        $t->boolean('notified')->default(0);
        $t->datetime('created_at');
        $t->index(['return_id', 'created_at']);
    });
};
