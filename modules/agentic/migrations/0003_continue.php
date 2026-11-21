<?php
/**
 * AIFEC — impedir continuar una compra cancelada o caducada.
 * @author Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;
return static function (Schema $schema, Db $db): void {
    $schema->addColumn('ucp_checkouts', static function (TableDef $t): void {
        $t->string('continue_hash', 64)->nullable();
    });
    do {
        $rows = $db->select('SELECT public_id FROM {ucp_checkouts} WHERE continue_hash IS NULL LIMIT 500');
        foreach ($rows as $row) {
            $token = substr(hash('sha256', 'aifec-ucp-cart|' . $row['public_id']), 0, 48);
            $db->update('ucp_checkouts', ['continue_hash' => hash('sha256', $token)], ['public_id' => $row['public_id']]);
        }
    } while (count($rows) === 500);
    $schema->addIndex('ucp_checkouts', ['continue_hash']);
};
