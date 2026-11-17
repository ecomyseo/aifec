<?php
/**
 * AIFEC — escritor de clientes: crea con sus direcciones y consentimientos, repite sin cambios, actualiza, choca con lo editado aquí, no
 * duplica al cliente que ya existía (lo enlaza sin tocar su contraseña ni su nombre), deja entrar con la contraseña de siempre en cada
 * esquema y la renueva, no deja un solo resumen de contraseña fuera de la fila del cliente y no avisa a nadie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\CustomersWriter;
use Aifec\Security\Passwords;

require_once __DIR__ . '/WritersKit2.php';

pwTest('el cliente de ejemplo se escribe con sus datos, su dirección, su consentimiento y su correo ya verificado', function (): void {
    [$app, $transport] = pwApp();
    $ctx = writersContext($app);
    $hash = pwBcrypt('clave-larga-1');
    $out = (new CustomersWriter($app))->write(pwCustomer(['password_hash' => $hash, 'password_scheme' => 'bcrypt']), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $row = $app->db()->find('customers', ['id' => $out['id']]);

    same(['ana@cliente.test', 'Ana', 'Ruiz Peña', '999000001', 'es'], [$row['email'], $row['first_name'], $row['last_name'], $row['phone'], $row['locale']]);
    same(['woocommerce', '77'], [$row['source'], $row['legacy_id']], 'de dónde vino y con qué identificador');
    same([0, 1, 1], [(int) $row['guest'], (int) $row['active'], (int) $row['marketing']]);
    same(['2024-05-02 16:20:00', '2024-05-02 16:20:00', null], [$row['created_at'], $row['marketing_at'], $row['last_login_at']], 'con las fechas del origen');
    same('2024-05-02 16:20:00', $row['email_verified_at'], 'una cuenta con registro propio entra verificada, en la fecha de su alta');
    same($hash, $row['password'], 'el resumen se guarda tal cual');
    same(1, (int) $row['group_id'], 'el grupo que no se trajo cae en el de por defecto');
    contains('customer group', implode(' ', $ctx->noteTexts('customers')));

    $addresses = $app->db()->select('SELECT * FROM {addresses} WHERE customer_id = ?', [$out['id']]);
    same(1, count($addresses));
    same(['Ana', 'Ruiz Peña', 'Calle Mayor 1', '2.º B', '28013', 'Madrid', '28', 'ES', 1, 1], [
        $addresses[0]['first_name'], $addresses[0]['last_name'], $addresses[0]['line1'], $addresses[0]['line2'], $addresses[0]['postcode'], $addresses[0]['city'],
        $addresses[0]['region'], $addresses[0]['country'], (int) $addresses[0]['default_shipping'], (int) $addresses[0]['default_billing'],
    ], 'la dirección, con la provincia de España como dos cifras');
    same((int) $addresses[0]['id'], $ctx->localId('addresses', '5'), 'casada por su identificador de origen');

    $consents = $app->db()->select('SELECT * FROM {consents} WHERE email = ?', ['ana@cliente.test']);
    same(1, count($consents));
    same(['marketing', 1, 'registro', '2024-05-02 16:20:00', (int) $out['id']], [$consents[0]['kind'], (int) $consents[0]['granted'], $consents[0]['context'], $consents[0]['created_at'], (int) $consents[0]['customer_id']]);
    pwSilent($app, $transport, 'tras escribir un cliente');
});

pwTest('repetir el cliente no cambia ninguna tabla y cambiarlo en el origen lo actualiza en su sitio', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new CustomersWriter($app);
    $record = pwCustomer();
    $id = (int) $writer->write($record, $ctx)['id'];
    $counts = wrCounts($app);

    $again = $writer->write($record, $ctx);
    same(TypeWriter::UNCHANGED, $again['result']);
    same($id, $again['id']);
    same($counts, wrCounts($app), 'ni una fila nueva');

    $changed = $writer->write(pwCustomer(['first_name' => 'Anita', 'phone' => '999000009']), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same($counts, wrCounts($app), 'se actualiza en su sitio, sin duplicar direcciones ni consentimientos');
    same(['Anita', '999000009'], [wrValue($app, 'SELECT first_name FROM {customers} WHERE id = ?', [$id]), wrValue($app, 'SELECT phone FROM {customers} WHERE id = ?', [$id])]);
});

pwTest('un cliente editado aquí después de traerlo choca y no se toca; overwrite_local lo pisa', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new CustomersWriter($app);
    $id = (int) $writer->write(pwCustomer(), $ctx)['id'];
    $app->clock()->advance(300);
    $app->db()->update('customers', ['phone' => '999500006', 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);

    $out = $writer->write(pwCustomer(['first_name' => 'Anita']), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    same($id, $out['id']);
    same(['Ana', '999500006'], [wrValue($app, 'SELECT first_name FROM {customers} WHERE id = ?', [$id]), wrValue($app, 'SELECT phone FROM {customers} WHERE id = ?', [$id])], 'lo de aquí queda intacto');

    $over = writersContext($app, ['overwrite_local' => true]);
    same(TypeWriter::UPDATED, $writer->write(pwCustomer(['first_name' => 'Anita']), $over)['result']);
    same('Anita', wrValue($app, 'SELECT first_name FROM {customers} WHERE id = ?', [$id]));
});

pwTest('simulacro de clientes: se decide lo mismo y no cambia ninguna tabla', function (): void {
    [$app] = pwApp();
    $dry = writersContext($app)->dry(true);
    $before = wrCounts($app);
    same(TypeWriter::CREATED, (new CustomersWriter($app))->write(pwCustomer(['password_hash' => pwBcrypt('x1234567'), 'password_scheme' => 'bcrypt']), $dry)['result']);
    same($before, wrCounts($app), 'ni clientes, ni direcciones, ni consentimientos, ni parejas');
    same(0, wrCount($app, 'import_map'));

    $real = writersContext($app);
    $writer = new CustomersWriter($app);
    $writer->write(pwCustomer(), $real);
    $counts = wrCounts($app);
    same(TypeWriter::UNCHANGED, $writer->write(pwCustomer(), $dry)['result']);
    same(TypeWriter::UPDATED, $writer->write(pwCustomer(['first_name' => 'Zoe']), $dry)['result']);
    same($counts, wrCounts($app));
    same('Ana', wrValue($app, 'SELECT first_name FROM {customers}'), 'y el nombre sigue igual');
});

pwTest('un cliente que ya existía con ese correo se enlaza: conserva su contraseña y su nombre, rellena lo vacío y gana las direcciones', function (): void {
    [$app] = pwApp();
    $mine = pwBcrypt('mi-clave-de-aqui');
    $now = $app->clock()->sql();
    $existing = $app->db()->insert('customers', [
        'email' => 'ana@cliente.test', 'password' => $mine, 'guest' => 0, 'first_name' => 'Anita', 'last_name' => null, 'phone' => null, 'group_id' => 1, 'locale' => 'es',
        'active' => 1, 'marketing' => 0, 'orders_count' => 0, 'total_spent' => 0, 'source' => 'register', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $ctx = writersContext($app);
    $writer = new CustomersWriter($app);
    $out = $writer->write(pwCustomer(['password_hash' => pwBcrypt('la-de-antes'), 'password_scheme' => 'bcrypt']), $ctx);

    same(TypeWriter::UPDATED, $out['result'], 'casar por lo natural es una actualización');
    same($existing, $out['id']);
    same(1, wrCount($app, 'customers'), 'no se duplica');
    $row = $app->db()->find('customers', ['id' => $existing]);
    same($mine, $row['password'], 'su contraseña, intacta');
    same(['Anita', 'Ruiz Peña', '999000001', 'register', '77', 0], [$row['first_name'], $row['last_name'], $row['phone'], $row['source'], $row['legacy_id'], (int) $row['marketing']], 'el nombre y el origen no se tocan; lo vacío se rellena; el marketing no cambia');
    same(1, wrCount($app, 'addresses', 'customer_id = ?', [$existing]), 'gana la dirección');
    same($existing, $ctx->localId('customers', '77'));

    $writer->write(pwCustomer(['first_name' => 'Zoe', 'password_hash' => pwBcrypt('otra-mas'), 'password_scheme' => 'bcrypt']), $ctx);
    $row = $app->db()->find('customers', ['id' => $existing]);
    same(['Anita', $mine], [$row['first_name'], $row['password']], 'tampoco en las vueltas siguientes: no es suyo');
});

pwTest('entra con su contraseña de siempre en bcrypt y el resumen se renueva al de aquí', function (): void {
    [$app] = pwApp();
    $hash = pwBcrypt('clave-larga-1');
    $id = (int) (new CustomersWriter($app))->write(pwCustomer(['password_hash' => $hash, 'password_scheme' => 'bcrypt']), writersContext($app))['id'];
    $service = $app->service('customers');

    same(null, $service->login('ana@cliente.test', 'otra-clave'));
    same($id, $service->login('ana@cliente.test', 'clave-larga-1'));
    $now = (string) wrValue($app, 'SELECT password FROM {customers} WHERE id = ?', [$id]);
    check($now !== $hash && Passwords::verify('clave-larga-1', $now) && !Passwords::needsRehash($now), 'tras entrar, el resumen es el de aquí');
    $service->logout();
    same($id, $service->login('ana@cliente.test', 'clave-larga-1'), 'y con el nuevo sigue entrando');
});

pwTest('entra con su contraseña de siempre en los esquemas de WordPress: phpass, el moderno y el md5 viejo', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new CustomersWriter($app);
    $service = $app->service('customers');
    $cases = [
        ['phpass', 'p@ss-de-wordpress', pwPhpass('p@ss-de-wordpress')],
        ['moderno', 'otra-de-wordpress', pwWpModern('otra-de-wordpress')],
        ['md5', 'vieja-md5-clave', md5('vieja-md5-clave')],
    ];
    foreach ($cases as $i => $case) {
        $email = 'wp' . $i . '@cliente.test';
        $id = (int) $writer->write(pwCustomer(['id' => 'w' . $i, 'email' => $email, 'addresses' => [], 'consents' => [], 'password_hash' => $case[2], 'password_scheme' => 'wordpress']), $ctx)['id'];
        same($case[2], wrValue($app, 'SELECT password FROM {customers} WHERE id = ?', [$id]), $case[0] . ': se guarda tal cual');
        same(null, $service->login($email, 'mala-' . $case[1]), $case[0] . ': con otra contraseña no entra');
        same($id, $service->login($email, $case[1]), $case[0] . ': entra con la de siempre');
        $renewed = (string) wrValue($app, 'SELECT password FROM {customers} WHERE id = ?', [$id]);
        check(Passwords::verify($case[1], $renewed) && !Passwords::needsRehash($renewed) && $renewed !== $case[2], $case[0] . ': se renueva');
        $service->logout();
    }
});

pwTest('la contraseña antigua de PrestaShop entra con la clave de cookies de la migración, que se guarda cifrada y fuera de la fila del cliente', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app, ['cookie_key' => 'CLAVE-DE-COOKIE-ANTIGUA']);
    $md5 = md5('CLAVE-DE-COOKIE-ANTIGUA' . 'secreto-123');
    $id = (int) (new CustomersWriter($app))->write(pwCustomer(['password_hash' => $md5, 'password_scheme' => 'prestashop-md5']), $ctx)['id'];

    same('CLAVE-DE-COOKIE-ANTIGUA', $app->secret('customers.legacy_cookie_key'), 'la clave está donde Customers la busca');
    check(strpos($app->settings()->str('customers.legacy_cookie_key'), 'enc:') === 0, 'y cifrada');
    same($md5, wrValue($app, 'SELECT password FROM {customers} WHERE id = ?', [$id]));
    same([], pwLeaks($app, 'CLAVE-DE-COOKIE-ANTIGUA'), 'la clave en claro no está en ninguna tabla');
    $service = $app->service('customers');
    same(null, $service->login('ana@cliente.test', 'otra-clave'));
    same($id, $service->login('ana@cliente.test', 'secreto-123'));
    $now = (string) wrValue($app, 'SELECT password FROM {customers} WHERE id = ?', [$id]);
    check(Passwords::verify('secreto-123', $now) && !Passwords::needsRehash($now), 'queda renovada');

    // Otra migración con otra clave no pisa la que ya hay.
    $other = writersContext($app, ['cookie_key' => 'CLAVE-DISTINTA-99']);
    (new CustomersWriter($app))->write(pwCustomer(['id' => '78', 'email' => 'luis@cliente.test', 'addresses' => [], 'consents' => [], 'password_hash' => $md5, 'password_scheme' => 'prestashop-md5']), $other);
    same('CLAVE-DE-COOKIE-ANTIGUA', $app->secret('customers.legacy_cookie_key'), 'la primera se queda');
});

pwTest('sin la clave de cookies no entra, y se avisa; un esquema desconocido o sin resumen deja al cliente sin contraseña y con la salida de restablecerla', function (): void {
    [$app, $transport] = pwApp();
    $ctx = writersContext($app);
    $writer = new CustomersWriter($app);
    $service = $app->service('customers');
    $ps = (int) $writer->write(pwCustomer(['password_hash' => md5('X' . 'secreto-123'), 'password_scheme' => 'prestashop-md5']), $ctx)['id'];
    same(null, $service->login('ana@cliente.test', 'secreto-123'), 'sin la clave de cookies no se puede comprobar');
    contains('cookie key', implode(' ', $ctx->noteTexts('customers')));
    same(md5('X' . 'secreto-123'), wrValue($app, 'SELECT password FROM {customers} WHERE id = ?', [$ps]), 'pero el resumen se guarda por si se da la clave después');

    $unknown = (int) $writer->write(pwCustomer(['id' => '80', 'email' => 'raro@cliente.test', 'addresses' => [], 'consents' => [], 'password_hash' => 'no-se-que-formato-es', 'password_scheme' => 'unknown']), $ctx)['id'];
    $none = (int) $writer->write(pwCustomer(['id' => '81', 'email' => 'nada@cliente.test', 'addresses' => [], 'consents' => []]), $ctx)['id'];
    foreach ([$unknown, $none] as $id) {
        same(null, wrValue($app, 'SELECT password FROM {customers} WHERE id = ?', [$id]), 'sin contraseña');
        same(false, $app->service('customers')->find($id)['has_password']);
    }
    same(null, $service->login('raro@cliente.test', 'no-se-que-formato-es'));
    same(1, $ctx->unmappedCount('password', 'Password format that cannot be checked here'), 'se cuenta el que traía un resumen que no se puede comprobar');

    pwSilent($app, $transport, 'antes de pedir el enlace');
    pwAllowMail($transport);
    $service->requestReset('nada@cliente.test');
    same(1, count($transport->sent), 'puede restablecerla: fuera de la migración el correo sí sale');
});

pwTest('ningún resumen de contraseña sale de la fila del cliente: ni el informe, ni el registro de actividad, ni ninguna otra tabla', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new CustomersWriter($app);
    $hashes = [pwBcrypt('a-clave-1'), pwPhpass('b-clave-2'), pwWpModern('c-clave-3'), md5('X' . 'd-clave-4')];
    foreach ($hashes as $i => $hash) {
        $scheme = $i === 3 ? 'prestashop-md5' : ($i === 0 ? 'bcrypt' : 'wordpress');
        $writer->write(pwCustomer(['id' => 'h' . $i, 'email' => 'h' . $i . '@cliente.test', 'addresses' => [], 'consents' => [], 'password_hash' => $hash, 'password_scheme' => $scheme]), $ctx);
    }
    // Un registro que se descarta lleva su resumen en el registro pero no en el motivo.
    $bad = $writer->write(pwCustomer(['id' => 'malo', 'email' => 'no-es-un-correo', 'password_hash' => $hashes[0], 'password_scheme' => 'bcrypt']), $ctx);
    same(TypeWriter::SKIPPED, $bad['result']);
    notContains($hashes[0], $bad['reason']);
    foreach ($hashes as $hash) {
        same(['customers'], pwLeaks($app, $hash), 'el resumen solo está en la columna password de customers');
    }
    same(4, (int) wrValue($app, "SELECT COUNT(*) FROM {customers} WHERE password IS NOT NULL"));
    foreach ($ctx->notes as $note) {
        foreach ($hashes as $hash) {
            notContains($hash, $note[3]);
        }
    }
});

pwTest('las direcciones sin país o sin calle se dejan fuera con una nota y la región de España queda como dos cifras', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $base = neutralSample('customers')['addresses'][0];
    $record = pwRaw('customers', ['password_hash' => null, 'password_scheme' => 'unknown', 'addresses' => [
        ['id' => 'a1', 'first_name' => 'Ana', 'line1' => 'Calle sin país 3', 'postcode' => '28013', 'city' => 'Madrid', 'country' => ''],
        ['id' => 'a2', 'first_name' => 'Ana', 'line1' => '', 'postcode' => '28013', 'city' => 'Madrid', 'country' => 'ES'],
        ['id' => 'a3', 'first_name' => 'Ana', 'line1' => 'Calle Larga 5', 'postcode' => '35001', 'city' => 'Las Palmas', 'region' => 'GC', 'country' => 'es', 'default_billing' => true],
        $base,
    ]]);
    $out = (new CustomersWriter($app))->write($record, $ctx);
    $rows = $app->db()->select('SELECT * FROM {addresses} WHERE customer_id = ? ORDER BY id', [$out['id']]);
    same(['Calle Larga 5', 'Calle Mayor 1'], array_column($rows, 'line1'), 'solo las dos válidas');
    same(['35', '28'], array_column($rows, 'region'), 'GC → 35 y M → 28');
    same(['ES', 'ES'], array_column($rows, 'country'), 'el país en mayúsculas');
    same([1, 0], [(int) $rows[0]['default_billing'], (int) $rows[1]['default_billing'] === 1 ? 1 : 0], 'solo una por defecto: la primera que lo pide');
    $notes = implode(' | ', $ctx->noteTexts('customers'));
    contains('has no country', $notes);
    contains('has no street', $notes);
});

pwTest('un correo que no es un correo no entra, y dos clientes del origen con el mismo correo no se pisan', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new CustomersWriter($app);
    $bad = $writer->write(pwCustomer(['id' => '1', 'email' => 'esto no vale']), $ctx);
    same(TypeWriter::SKIPPED, $bad['result']);
    contains('not valid', $bad['reason']);
    same(0, wrCount($app, 'customers'));

    same(TypeWriter::CREATED, $writer->write(pwCustomer(['id' => '10']), $ctx)['result']);
    $twin = $writer->write(pwCustomer(['id' => '11', 'addresses' => [], 'consents' => []]), $ctx);
    same(TypeWriter::SKIPPED, $twin['result']);
    contains('already has this email address', $twin['reason']);
    same(1, wrCount($app, 'customers'), 'y el segundo no deja nada a medias');
    same(1, wrCount($app, 'addresses'));
});

pwTest('un invitado no lleva contraseña ni correo verificado aunque el origen traiga un resumen', function (): void {
    [$app] = pwApp();
    $id = (int) (new CustomersWriter($app))->write(pwCustomer(['guest' => true, 'password_hash' => pwBcrypt('x1234567'), 'password_scheme' => 'bcrypt']), writersContext($app))['id'];
    $row = $app->db()->find('customers', ['id' => $id]);
    same([1, null, null], [(int) $row['guest'], $row['password'], $row['email_verified_at']]);
    same(null, $app->service('customers')->login('ana@cliente.test', 'x1234567'), 'un invitado no entra');
});

pwTest('el grupo se toma de la equivalencia elegida y las cifras del destino se cuentan por lo traído', function (): void {
    [$app] = pwApp();
    $group = $app->db()->insert('customer_groups', ['name' => 'Profesionales', 'discount' => 1000, 'tax_exempt' => 0, 'is_default' => 0]);
    $ctx = writersContext($app, [], ['customer_group' => ['3' => (string) $group]]);
    $writer = new CustomersWriter($app);
    $id = (int) $writer->write(pwCustomer(), $ctx)['id'];
    same($group, (int) wrValue($app, 'SELECT group_id FROM {customers} WHERE id = ?', [$id]));
    $writer->write(pwCustomer(['id' => '78', 'email' => 'luis@cliente.test', 'addresses' => [], 'consents' => []]), $ctx);
    // Un cliente de aquí, que no vino de ningún sitio, no cuenta.
    $now = $app->clock()->sql();
    $app->db()->insert('customers', ['email' => 'propio@cliente.test', 'guest' => 0, 'locale' => 'es', 'active' => 1, 'marketing' => 0, 'orders_count' => 0, 'total_spent' => 0, 'created_at' => $now, 'updated_at' => $now]);
    same(['customers_count' => 2, 'addresses_count' => 1], $writer->checksums($ctx));
});
