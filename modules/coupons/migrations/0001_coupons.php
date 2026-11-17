<?php
/**
 * AIFEC — esquema de cupones.
 *
 * kind: percent (percent en centésimas de punto: 1000 = 10 %), fixed (amount en céntimos, «tal como se escriben» los
 * precios) o free_shipping. products y categories = listas JSON de identificadores a los que se limita (vacío = todo).
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
    $schema->create('coupons', static function (TableDef $t): void {
        $t->id();
        $t->string('code', 40);
        $t->string('kind', 16)->default('percent');
        $t->integer('percent')->default(0);
        $t->integer('amount')->default(0);
        $t->integer('min_subtotal')->default(0);
        $t->datetime('starts_at')->nullable();
        $t->datetime('ends_at')->nullable();
        $t->integer('max_uses')->default(0);
        $t->integer('max_uses_per_customer')->default(0);
        $t->integer('uses')->default(0);
        $t->boolean('first_order_only')->default(0);
        $t->boolean('exclude_sale')->default(0);
        $t->boolean('combinable')->default(0);
        $t->bigInteger('customer_id')->nullable();
        $t->text('products')->nullable();
        $t->text('categories')->nullable();
        $t->string('note', 191)->nullable();
        $t->boolean('active')->default(1);
        $t->timestamps();
        $t->unique(['code']);
    });

    $schema->create('coupon_uses', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('coupon_id');
        $t->bigInteger('order_id');
        $t->bigInteger('customer_id')->nullable();
        $t->string('email', 191)->nullable();
        $t->integer('amount')->default(0);
        $t->datetime('created_at');
        $t->index(['coupon_id']);
        $t->index(['email']);
        $t->unique(['coupon_id', 'order_id']);
    });
};
