<?php
/**
 * AIFEC — escritores del equipo y de los suscritos: el equipo entra inactivo y sin contraseña, con el papel que le toca y sin ser
 * administrador salvo que se pida, y no puede entrar; el suscrito solo entra con su fecha de consentimiento, que es la prueba, y no
 * vuelve a suscribir a quien se dio de baja. Los dos repiten sin cambios, actualizan, chocan con lo editado aquí y no escriben nada
 * en un simulacro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\SubscribersWriter;
use Aifec\Modules\Import\Writers\TeamWriter;
use Aifec\Security\Passwords;

require_once __DIR__ . '/WritersKit2.php';

pwTest('el equipo entra inactivo y sin contraseña, con el papel de gestión que le toca', function (): void {
    [$app, $transport] = pwApp();
    $ctx = writersContext($app);
    $out = (new TeamWriter($app))->write(wr('team'), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $row = $app->db()->find('users', ['id' => $out['id']]);
    same(['marta@tienda.test', 'Marta Gil', 'manager', 'es'], [$row['email'], $row['name'], $row['role'], $row['locale']]);
    same([0, ''], [(int) $row['active'], $row['password']], 'inactiva y sin contraseña');
    same('2026-10-30 07:00:00', $row['last_login_at']);
    same(false, Passwords::verify('', (string) $row['password']), 'una contraseña vacía no se puede verificar nunca');
    contains('inactive and without a password', implode(' ', $ctx->noteTexts('team')), 'y va a «revisa a mano»');
    pwSilent($app, $transport, 'tras traer al equipo');
});

pwTest('nadie entra como administrador salvo con la opción, y cada significado tiene su papel', function (): void {
    [$app] = pwApp();
    $writer = new TeamWriter($app);
    $ctx = writersContext($app);
    $roles = [];
    foreach (['admin', 'manager', 'editor', 'support', 'other'] as $i => $meaning) {
        $roles[$meaning] = wrValue($app, 'SELECT role FROM {users} WHERE id = ?', [$writer->write(wr('team', ['id' => 't' . $i, 'email' => 'p' . $i . '@tienda.test', 'role_meaning' => $meaning]), $ctx)['id']]);
    }
    same(['admin' => 'manager', 'manager' => 'manager', 'editor' => 'editor', 'support' => 'support', 'other' => 'support'], $roles, 'el administrador del origen entra como gestor');
    contains('team_admins', implode(' ', $ctx->noteTexts('team')));
    same(0, wrCount($app, 'users', "role = 'admin' AND email LIKE 'p%'"), 'ninguno es administrador');

    $with = writersContext($app, ['team_admins' => true]);
    $id = (int) $writer->write(wr('team', ['id' => 't9', 'email' => 'jefe@tienda.test', 'role_meaning' => 'admin']), $with)['id'];
    same('admin', wrValue($app, 'SELECT role FROM {users} WHERE id = ?', [$id]), 'con team_admins sí');
});

pwTest('no pueden entrar hasta que se les active y se les ponga contraseña', function (): void {
    [$app] = pwApp();
    $id = (int) (new TeamWriter($app))->write(wr('team'), writersContext($app))['id'];
    $web = new TestClient($app);
    same(422, aifecLogin($web, 'marta@tienda.test', 'clave-larga-1234')->status, 'inactiva: no entra');
    $app->db()->update('users', ['active' => 1], ['id' => $id]);
    same(422, aifecLogin($web, 'marta@tienda.test', 'clave-larga-1234')->status, 'activa pero sin contraseña: tampoco');
    same(422, aifecLogin($web, 'marta@tienda.test', '')->status, 'ni con la contraseña vacía');
    $app->db()->update('users', ['password' => Passwords::hash('clave-larga-1234')], ['id' => $id]);
    same(302, aifecLogin($web, 'marta@tienda.test', 'clave-larga-1234')->status, 'activa y con contraseña, sí: entra y la mandan al panel');
});

pwTest('una persona cuyo correo ya existe aquí no se toca', function (): void {
    [$app] = pwApp();
    $now = $app->clock()->sql();
    $mine = $app->db()->insert('users', ['email' => 'marta@tienda.test', 'name' => 'Marta de aquí', 'password' => Passwords::hash('clave-larga-1234'), 'role' => 'admin', 'locale' => 'es', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $ctx = writersContext($app);
    $out = (new TeamWriter($app))->write(wr('team'), $ctx);
    same(TypeWriter::UNCHANGED, $out['result']);
    same($mine, $out['id']);
    same(['Marta de aquí', 'admin', 1], [wrValue($app, 'SELECT name FROM {users} WHERE id = ?', [$mine]), wrValue($app, 'SELECT role FROM {users} WHERE id = ?', [$mine]), (int) wrValue($app, 'SELECT active FROM {users} WHERE id = ?', [$mine])]);
    same(1, wrCount($app, 'users'));
    contains('already exists here', implode(' ', $ctx->noteTexts('team')));
    same(0, wrCount($app, 'import_map', "type = 'team'"), 'ni se casa con ella');
});

pwTest('el equipo repite sin cambios, se actualiza, choca con lo editado aquí y el simulacro no escribe nada', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new TeamWriter($app);
    $id = (int) $writer->write(wr('team'), $ctx)['id'];
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write(wr('team'), $ctx)['result']);
    same(TypeWriter::UPDATED, $writer->write(wr('team', ['name' => 'Marta G. Gil', 'role_meaning' => 'editor']), $ctx)['result']);
    same(['Marta G. Gil', 'editor', 0], [wrValue($app, 'SELECT name FROM {users} WHERE id = ?', [$id]), wrValue($app, 'SELECT role FROM {users} WHERE id = ?', [$id]), (int) wrValue($app, 'SELECT active FROM {users} WHERE id = ?', [$id])], 'sigue inactiva');
    same($counts, wrCounts($app));

    $dry = writersContext($app)->dry(true);
    same(TypeWriter::CREATED, $writer->write(wr('team', ['id' => '9', 'email' => 'nuevo@tienda.test']), $dry)['result']);
    same($counts, wrCounts($app), 'el simulacro no escribe');

    $app->clock()->advance(300);
    $app->db()->update('users', ['active' => 1, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);
    same(TypeWriter::CONFLICT, $writer->write(wr('team', ['name' => 'Otra vez']), $ctx)['result'], 'activada aquí: choca');
    same('Marta G. Gil', wrValue($app, 'SELECT name FROM {users} WHERE id = ?', [$id]));
    same(TypeWriter::UPDATED, $writer->write(wr('team', ['name' => 'Otra vez']), writersContext($app, ['overwrite_local' => true]))['result']);
});

pwTest('un suscrito sin fecha de consentimiento no entra: no hay prueba', function (): void {
    [$app] = pwApp();
    $writer = new SubscribersWriter($app);
    $out = $writer->write(wr('subscribers', ['granted_at' => null]), writersContext($app));
    same(TypeWriter::SKIPPED, $out['result']);
    contains('no proof of consent', $out['reason']);
    same(0, wrCount($app, 'consents'));
    same(TypeWriter::CREATED, $writer->write(wr('subscribers', ['id' => '41', 'email' => 'otra@cliente.test']), writersContext($app))['result'], 'solo se salta el que no tiene fecha');
    same(1, wrCount($app, 'consents'));
});

pwTest('un suscrito con fecha es un consentimiento de marketing con esa fecha y su origen', function (): void {
    [$app, $transport] = pwApp();
    $out = (new SubscribersWriter($app))->write(wr('subscribers'), writersContext($app));
    same(TypeWriter::CREATED, $out['result']);
    $row = $app->db()->find('consents', ['id' => $out['id']]);
    same(['luis@cliente.test', 'marketing', 1, 'pie de página', '2025-06-01 12:00:00', null], [$row['email'], $row['kind'], (int) $row['granted'], $row['context'], $row['created_at'], $row['customer_id']]);
    pwSilent($app, $transport, 'tras traer un suscrito');
});

pwTest('si el correo es de un cliente, el cliente queda con el marketing y su fecha', function (): void {
    [$app] = pwApp();
    $now = $app->clock()->sql();
    $customer = $app->db()->insert('customers', ['email' => 'luis@cliente.test', 'guest' => 0, 'locale' => 'es', 'active' => 1, 'marketing' => 0, 'orders_count' => 0, 'total_spent' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $out = (new SubscribersWriter($app))->write(wr('subscribers'), writersContext($app));
    $row = $app->db()->row('SELECT marketing, marketing_at FROM {customers} WHERE id = ?', [$customer]);
    same([1, '2025-06-01 12:00:00'], [(int) $row['marketing'], $row['marketing_at']]);
    same($customer, (int) wrValue($app, 'SELECT customer_id FROM {consents} WHERE id = ?', [$out['id']]), 'y el consentimiento queda unido al cliente');
});

pwTest('al suscrito que se dio de baja después no se le vuelve a suscribir', function (): void {
    [$app] = pwApp();
    $now = $app->clock()->sql();
    $customer = $app->db()->insert('customers', ['email' => 'luis@cliente.test', 'guest' => 0, 'locale' => 'es', 'active' => 1, 'marketing' => 0, 'orders_count' => 0, 'total_spent' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('consents', ['customer_id' => $customer, 'email' => 'luis@cliente.test', 'kind' => 'marketing', 'granted' => 0, 'context' => 'baja', 'created_at' => '2026-01-01 00:00:00']);
    (new SubscribersWriter($app))->write(wr('subscribers'), writersContext($app));
    same(0, (int) wrValue($app, 'SELECT marketing FROM {customers} WHERE id = ?', [$customer]), 'sigue de baja');
    same(2, wrCount($app, 'consents'), 'pero el consentimiento antiguo queda como prueba');
});

pwTest('los suscritos repiten sin cambios, se actualizan, chocan si ese correo dio otro consentimiento después y el simulacro no escribe', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new SubscribersWriter($app);
    $id = (int) $writer->write(wr('subscribers'), $ctx)['id'];
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write(wr('subscribers'), $ctx)['result']);
    same(TypeWriter::UPDATED, $writer->write(wr('subscribers', ['source' => 'formulario del blog']), $ctx)['result']);
    same('formulario del blog', wrValue($app, 'SELECT context FROM {consents} WHERE id = ?', [$id]));
    same($counts, wrCounts($app), 'sin filas nuevas');

    $dry = writersContext($app)->dry(true);
    same(TypeWriter::CREATED, $writer->write(wr('subscribers', ['id' => '50', 'email' => 'nueva@cliente.test']), $dry)['result']);
    same($counts, wrCounts($app));

    $app->clock()->advance(300);
    $app->db()->insert('consents', ['customer_id' => null, 'email' => 'luis@cliente.test', 'kind' => 'marketing', 'granted' => 0, 'context' => 'baja', 'created_at' => $app->clock()->sql()]);
    $app->clock()->advance(300);
    same(TypeWriter::CONFLICT, $writer->write(wr('subscribers', ['source' => 'otra']), $ctx)['result'], 'se dio de baja aquí: choca');
    same('formulario del blog', wrValue($app, 'SELECT context FROM {consents} WHERE id = ?', [$id]));
    same(TypeWriter::UPDATED, $writer->write(wr('subscribers', ['source' => 'otra']), writersContext($app, ['overwrite_local' => true]))['result']);
});
