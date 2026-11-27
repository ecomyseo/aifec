<?php
/** AIFEC — solicitudes de atención personal con permiso del cliente. */
declare(strict_types=1);
use Aifec\Db\Db;
use Aifec\Db\Schema;
use Aifec\Db\TableDef;

return static function (Schema $schema, Db $db): void {
    $schema->addColumn('conversations', static function (TableDef $t): void {
        $t->string('handoff_status', 12)->default('');
        $t->bigInteger('handoff_request')->default(0);
        $t->text('handoff_summary')->nullable();
        $t->text('handoff_reply')->nullable();
    });
    // El personal de soporte que ya puede ver las conversaciones puede responder a estas solicitudes.
    $row = $db->row("SELECT id, caps FROM {roles} WHERE code = 'support'");
    $caps = $row ? json_decode((string) $row['caps'], true) : [];
    if (is_array($caps) && in_array('assistant.view', $caps, true) && !in_array('assistant.reply', $caps, true)) {
        $caps[] = 'assistant.reply';
        $db->update('roles', ['caps' => json_encode($caps)], ['id' => (int) $row['id']]);
    }
};
