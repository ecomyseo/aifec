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
    $schema->create('learning_runs', static function (TableDef $t): void {
        $t->id();
        $t->string('site_id', 80);
        $t->string('context_hash', 64);
        $t->text('context');
        $t->string('lang', 8);
        $t->string('mode', 16);
        $t->string('status', 24);
        $t->bigInteger('initiator_id');
        $t->bigInteger('resumed_by')->nullable();
        $t->text('options');
        $t->text('catalogue');
        $t->text('provider_config');
        $t->bigInteger('budget_micro');
        $t->bigInteger('spent_micro')->default(0);
        $t->bigInteger('reserved_micro')->default(0);
        $t->integer('calls')->default(0);
        $t->bigInteger('tokens_in')->default(0);
        $t->bigInteger('tokens_out')->default(0);
        $t->integer('done')->default(0);
        $t->integer('total');
        $t->integer('version')->default(0);
        $t->integer('applied_version')->nullable();
        $t->integer('generation')->default(0);
        $t->text('validation')->nullable();
        $t->string('error', 120)->nullable();
        $t->string('lease', 64)->nullable();
        $t->datetime('locked_at')->nullable();
        $t->timestamps();
        $t->index(['context_hash', 'id']);
        $t->index(['status']);
    });
    $schema->create('learning_cases', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('run_id');
        $t->string('kind', 16);
        $t->string('query', 200);
        $t->text('profile');
        $t->text('target_ids');
        $t->text('evidence');
        $t->text('before_result');
        $t->text('after_result')->nullable();
        $t->string('verdict', 32)->default('not_validated');
        $t->boolean('verifiable')->default(0);
        $t->index(['run_id', 'id']);
    });
    $schema->create('learning_proposals', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('run_id');
        $t->bigInteger('case_id');
        $t->string('context_hash', 64);
        $t->integer('version');
        $t->string('kind', 16);
        $t->string('source', 200);
        $t->string('target', 200);
        $t->text('product_ids');
        $t->text('evidence');
        $t->text('reason');
        $t->string('status', 16)->default('pending');
        $t->index(['run_id', 'id']);
    });
    $schema->create('learning_calls', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('run_id');
        $t->string('call_key', 64);
        $t->string('state', 16);
        $t->bigInteger('reserved_micro');
        $t->bigInteger('actual_micro')->nullable();
        $t->bigInteger('tokens_in')->nullable();
        $t->bigInteger('tokens_out')->nullable();
        $t->string('request_hash', 64);
        $t->bigInteger('usage_id')->nullable();
        $t->timestamps();
        $t->unique(['call_key']);
        $t->index(['run_id', 'state']);
    });
    $schema->create('learning_states', static function (TableDef $t): void {
        $t->id();
        $t->string('context_hash', 64);
        $t->integer('version')->default(0);
        $t->text('memory');
        $t->datetime('updated_at');
        $t->unique(['context_hash']);
    });
    $schema->create('learning_applications', static function (TableDef $t): void {
        $t->id();
        $t->bigInteger('run_id');
        $t->string('context_hash', 64);
        $t->integer('version_before');
        $t->integer('version_after');
        $t->text('memory_before');
        $t->text('memory_after');
        $t->bigInteger('reviewed_by');
        $t->string('status', 16)->default('active');
        $t->datetime('created_at');
        $t->datetime('reverted_at')->nullable();
        $t->index(['context_hash', 'id']);
    });
};
