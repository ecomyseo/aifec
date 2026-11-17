<?php
/**
 * AIFEC — los papeles de serie que atienden a clientes ven lo que necesitan (14/11/2026, ronda de trabajadores del panel: la persona
 * de atención al cliente no podía ver las devoluciones ni las preguntas que el asistente no supo contestar, y la encargada no
 * podía tramitar devoluciones). Solo se añade lo que falta; lo que la tienda haya cambiado a mano se queda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Db;
use Aifec\Db\Schema;

return static function (Schema $schema, Db $db): void {
    $add = [
        'support' => ['returns.view', 'assistant.view'],
        'manager' => ['returns.*'],
    ];
    foreach ($add as $code => $caps) {
        $row = $db->row('SELECT id, caps FROM {roles} WHERE code = ?', [$code]);
        if ($row === null) {
            continue;
        }
        $current = json_decode((string) $row['caps'], true);
        $current = is_array($current) ? $current : [];
        $new = $current;
        foreach ($caps as $cap) {
            if (!in_array($cap, $new, true)) {
                $new[] = $cap;
            }
        }
        if ($new !== $current) {
            $db->update('roles', ['caps' => json_encode(array_values($new))], ['id' => (int) $row['id']]);
        }
    }
};
