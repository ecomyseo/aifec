<?php
/**
 * AIFEC — atención al cliente ve las devoluciones y las preguntas que el asistente no supo contestar, y la encargada tramita
 * devoluciones (14/11/2026, ronda de trabajadores: «Missing permission: returns.view», en inglés). La migración 0004 se lo da a
 * las tiendas que ya existían sin quitar nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Db\Schema;

test('atención al cliente abre devoluciones y preguntas sin contestar; lo que no puede ver lo dice en castellano', function (): void {
    $app = testApp(['modules' => '*', 'settings' => ['modules.active' => ['returns', 'orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs', 'assistant', 'search']]]);
    $support = adminClient($app, 'support');
    same(200, $support->get('/admin/returns')->status, 'devoluciones');
    same(200, $support->get('/admin/assistant/unanswered')->status, 'preguntas sin contestar');
    $denied = $support->get('/admin/r/coupons/new');
    same(403, $denied->status);
    contains('Falta el permiso', (string) $denied->body);
});

test('la migración 0004 añade los permisos que faltan a los papeles de serie y respeta lo cambiado', function (): void {
    $app = testApp();
    $db = $app->db();
    $db->update('roles', ['caps' => json_encode(['dashboard.view', 'orders.view', 'custom.cap'])], ['code' => 'support']);
    $db->update('roles', ['caps' => json_encode(['orders.*'])], ['code' => 'manager']);
    $migration = require dirname(__DIR__, 2) . '/core/migrations/0004_staff_caps.php';
    $migration(new Schema($db), $db);
    $support = json_decode((string) $db->value("SELECT caps FROM {roles} WHERE code = 'support'"), true);
    same(['dashboard.view', 'orders.view', 'custom.cap', 'returns.view', 'assistant.view'], $support);
    same(['orders.*', 'returns.*'], json_decode((string) $db->value("SELECT caps FROM {roles} WHERE code = 'manager'"), true));
    $migration(new Schema($db), $db);
    same($support, json_decode((string) $db->value("SELECT caps FROM {roles} WHERE code = 'support'"), true), 'dos veces no repite nada');
});
