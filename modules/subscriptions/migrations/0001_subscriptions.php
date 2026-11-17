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
    $schema->create('subscription_plans', static function (TableDef $t): void {
        $t->id();
        $t->string('name', 120);
        $t->text('description')->nullable();
        $t->integer('amount');
        $t->bigInteger('tax_class_id')->nullable();
        $t->boolean('active')->default(1);
        $t->timestamps();
    });
    $schema->create('subscriptions', static function (TableDef $t): void {
        $t->id();
        $t->string('public_id', 48);
        $t->bigInteger('plan_id');
        $t->bigInteger('customer_id');
        $t->string('status', 24)->default('pending');
        $t->string('provider', 20);
        $t->string('environment', 12);
        $t->string('provider_id', 80)->nullable();
        $t->string('provider_plan_id', 80)->nullable();
        $t->text('approval_url')->nullable();
        $t->text('snapshot');
        $t->datetime('consented_at');
        $t->datetime('next_payment_at')->nullable();
        $t->datetime('cancelled_at')->nullable();
        $t->integer('version')->default(0);
        $t->integer('failures')->default(0);
        $t->timestamps();
        $t->unique(['public_id']);
        $t->unique(['provider', 'environment', 'provider_id']);
        $t->index(['customer_id', 'id']);
        $t->index(['status', 'next_payment_at']);
    });
    $schema->create('subscription_payments', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('subscription_id');
        $t->string('payment_key', 64);
        $t->string('reference', 120);
        $t->integer('amount');
        $t->string('currency', 3);
        $t->string('status', 16)->default('paid');
        $t->integer('refunded_amount')->default(0);
        $t->bigInteger('order_id');
        $t->datetime('paid_at');
        $t->unique(['payment_key']);
        $t->index(['subscription_id', 'paid_at']);
    });
    $schema->create('subscription_events', static function (TableDef $t): void {
        $t->id();
        $t->string('event_key', 64);
        $t->string('resource_key', 64)->nullable();
        $t->bigInteger('subscription_id');
        $t->string('kind', 80);
        $t->string('digest', 64);
        $t->string('payment_key', 64)->nullable();
        $t->text('body')->nullable();
        $t->boolean('processed')->default(1);
        $t->datetime('created_at');
        $t->unique(['event_key']);
        $t->unique(['resource_key']);
        $t->index(['payment_key', 'processed']);
    });
};
