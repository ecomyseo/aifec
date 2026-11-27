<?php
/** AIFEC — medición de las conversaciones y de sus pedidos asociados. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->addColumn('conversations', static function (TableDef $t): void {
        $t->bigInteger('ai_cost_micro')->nullable();
        $t->integer('ai_unpriced')->default(0);
        $t->boolean('measured_checkout')->default(0);
        $t->integer('vote')->nullable();
        $t->string('vote_reason', 24)->nullable();
    });
    $schema->create('assistant_orders', static function (TableDef $t): void {
        $t->bigInteger('order_id');
        $t->bigInteger('conversation_id');
        $t->primary(['order_id']);
        $t->index(['conversation_id']);
    });
    $db->run('INSERT INTO {assistant_orders} (order_id, conversation_id) SELECT c.order_id, MIN(c.id) FROM {conversations} c WHERE c.order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {assistant_orders} a WHERE a.order_id = c.order_id) GROUP BY c.order_id');
};
