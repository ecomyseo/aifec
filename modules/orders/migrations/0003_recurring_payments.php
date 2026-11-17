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
    $schema->create('order_recurring_payments', static function (TableDef $t): void {
        $t->string('external_key', 64);
        $t->string('fingerprint', 64);
        $t->bigInteger('order_id')->nullable();
        $t->datetime('created_at');
        $t->primary(['external_key']);
        $t->unique(['order_id']);
    });
};
