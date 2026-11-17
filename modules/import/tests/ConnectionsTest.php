<?php
/**
 * AIFEC — conexiones del origen: la base de datos de solo lectura de hecho, la dirección validada y ningún dato de
 * conexión fuera de su sitio cifrado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Connections;
use Aifec\Modules\Import\ReadOnlyPdo;
use Aifec\Modules\Import\Redactor;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

require_once __DIR__ . '/helpers.php';

/** Una copia de la base de datos de mentira (SQLite) con una tabla y tres filas, abierta de solo lectura. */
function importReadOnly(): ReadOnlyPdo
{
    $file = tmpDir('copia') . '/origen.sqlite';
    $raw = new \PDO('sqlite:' . $file);
    $raw->exec('CREATE TABLE ps_product (id_product INTEGER PRIMARY KEY, reference TEXT)');
    $raw->exec("INSERT INTO ps_product (id_product, reference) VALUES (1, 'A;B'), (2, 'C'), (3, 'D')");
    $raw = null;
    $pdo = new ReadOnlyPdo('sqlite:' . $file);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

test('el envoltorio de solo lectura deja pasar SELECT, SHOW y DESCRIBE', function (): void {
    $pdo = importReadOnly();
    same(3, (int) $pdo->query('SELECT COUNT(*) FROM ps_product')->fetchColumn());
    $stmt = $pdo->prepare('SELECT reference FROM ps_product WHERE id_product > ? ORDER BY id_product LIMIT ?');
    $stmt->execute([1, 5]);
    same(['C', 'D'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    same(1, (int) $pdo->query('SELECT 1;')->fetchColumn(), 'un punto y coma al final no es otra sentencia');
    same(';', (string) $pdo->query("SELECT ';' AS x")->fetchColumn(), 'un punto y coma dentro de una cadena tampoco');
    same(1, (int) $pdo->query("SELECT 1 -- ; DROP TABLE ps_product\n")->fetchColumn(), 'ni dentro de un comentario');
    ReadOnlyPdo::check('SHOW TABLES');
    ReadOnlyPdo::check('  describe ps_product');
    ReadOnlyPdo::check('SELECT * FROM `ps_product` WHERE reference = "a;b"');
});

test('rechaza UPDATE, DELETE, INSERT, DROP, dos sentencias en una y lo que se disfraza', function (): void {
    $pdo = importReadOnly();
    $bad = [
        "UPDATE ps_product SET reference = 'X'", 'DELETE FROM ps_product', "INSERT INTO ps_product (id_product) VALUES (9)", 'DROP TABLE ps_product',
        'TRUNCATE ps_product', 'ALTER TABLE ps_product ADD x INT', 'SELECT 1; DROP TABLE ps_product', 'SELECT 1; SELECT 2',
        '  delete from ps_product', "/* hola */ UPDATE ps_product SET reference = 'X'", "-- inocente\nDELETE FROM ps_product",
        '/*!50000 DROP TABLE ps_product */ SELECT 1', "SELECT * FROM ps_product INTO OUTFILE '/tmp/x'", '(SELECT 1)', '', ';', 'REPLACE INTO ps_product VALUES (1, 2)',
        'CREATE TABLE x (a INT)', 'PRAGMA writable_schema = 1',
    ];
    foreach ($bad as $sql) {
        throws(static function () use ($pdo, $sql): void {
            $pdo->query($sql);
        }, SourceError::class, 'query: ' . $sql);
        throws(static function () use ($pdo, $sql): void {
            $pdo->prepare($sql);
        }, SourceError::class, 'prepare: ' . $sql);
        throws(static function () use ($pdo, $sql): void {
            $pdo->exec($sql);
        }, SourceError::class, 'exec: ' . $sql);
    }
    same(3, (int) $pdo->query('SELECT COUNT(*) FROM ps_product')->fetchColumn(), 'no se tocó ni una fila');
    same('A;B', (string) $pdo->query('SELECT reference FROM ps_product WHERE id_product = 1')->fetchColumn());
});

test('los datos de conexión se guardan cifrados y se leen de vuelta; borrarlos los quita', function (): void {
    $app = importApp();
    $conns = importService($app)->connections();
    $conns->save(7, ['url' => 'https://origen.test', 'key' => 'clave-de-mentira-7777'], ['host' => 'db.test', 'name' => 'tiendaps', 'user' => 'lector', 'password' => 'clave-db-8888']);
    $raw = (string) $app->settings()->str('import.conn.7');
    check(strpos($raw, 'enc:') === 0, 'va cifrado');
    notContains('clave-de-mentira-7777', $raw);
    notContains('origen.test', $raw);
    $conn = $conns->load(7);
    same('clave-de-mentira-7777', $conn['config']['key']);
    same('clave-db-8888', $conn['db']['password']);
    same(true, $conns->has(7));
    same(true, $conns->copy(7, 8));
    same('clave-db-8888', $conns->load(8)['db']['password']);
    same(false, $conns->copy(99, 100), 'no copia lo que no existe');
    $conns->delete(7);
    same(false, $conns->has(7));
    same(null, $conns->load(7));
    same(true, $conns->has(8));
});

test('los datos de conexión no están en import_runs, ni en import_log, ni en el registro de actividad, ni en el informe', function (): void {
    $app = importApp(['import', 'media', 'catalog']);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3)];
    ImportFakeWriter::$notes = true;
    $web = adminClient($app);
    $id = importDraft($app, ['types' => ['redirects']], ['user_id' => 1]);
    // El mismo camino que el asistente: guardar la conexión y empezar.
    $web->get('/admin/import/' . $id . '/connection');
    $web->post('/admin/import/' . $id . '/connection', ['_token' => aifecToken($web), 'url' => 'https://origen.test', 'key' => 'clave-de-mentira-7777']);
    same(302, $web->last->status);
    importService($app)->runner()->start($id);
    importWork($app);
    same('done', importRun($app, $id)['status']);

    $dump = '';
    foreach (['import_runs', 'import_log', 'import_map', 'audit'] as $table) {
        $dump .= json_encode($app->db()->select('SELECT * FROM {' . $table . '}'), JSON_UNESCAPED_UNICODE);
    }
    $dump .= $web->get('/admin/import/' . $id . '/report')->body . $web->get('/admin/import/' . $id . '/report.csv')->body;
    $dump .= $web->get('/admin/import')->body . $web->get('/admin/import/' . $id . '/connection')->body;
    notContains('clave-de-mentira-7777', $dump);
    check(strpos($dump, 'import.connection') !== false, 'sí queda apuntado que se guardó la conexión (sin sus datos)');
    same(false, importService($app)->connections()->has($id), 'y al cerrar la migración se borran');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {settings} WHERE k LIKE 'import.conn.%'"));
});

test('un fallo de conexión provocado no enseña el servidor, el usuario ni la contraseña', function (): void {
    $app = importApp();
    $conns = importService($app)->connections();
    $db = ['host' => '127.0.0.1', 'port' => 1, 'name' => 'base-secreta', 'user' => 'usuario-secreto', 'password' => 'clave-secreta-db'];
    try {
        $conns->openPdo($db);
        check(false, 'tenía que fallar');
    } catch (SourceError $e) {
        foreach (['127.0.0.1', 'base-secreta', 'usuario-secreto', 'clave-secreta-db'] as $secret) {
            notContains($secret, $e->getMessage());
        }
        check(in_array($e->getMessage(), ['Could not connect to the database', 'The database refused the user name or the password'], true), 'mensaje fijo: ' . $e->getMessage());
    }
    throws(static function () use ($conns): void {
        $conns->openPdo(['host' => 'a;b=c', 'port' => 3306, 'name' => 'x', 'user' => 'u', 'password' => 'p']);
    }, SourceError::class, 'un servidor con punto y coma no entra en la cadena de conexión');
    throws(static function () use ($conns): void {
        $conns->openPdo(['host' => 'db.test', 'port' => 70000, 'name' => 'x', 'user' => 'u', 'password' => 'p']);
    }, SourceError::class);
    throws(static function () use ($conns): void {
        $conns->openPdo(['host' => 'db.test', 'port' => 3306, 'name' => "x';DROP", 'user' => 'u', 'password' => 'p']);
    }, SourceError::class);
});

test('una fábrica de conexiones propia sustituye a MySQL y el lector la recibe', function (): void {
    $app = importApp();
    importService($app)->registry();
    $conns = importService($app)->connections();
    $file = tmpDir('copia') . '/x.sqlite';
    $conns->setPdoFactory(static function (array $db) use ($file): \PDO {
        return new ReadOnlyPdo('sqlite:' . $file);
    });
    $pdo = $conns->openPdo(['host' => 'db.test']);
    check($pdo instanceof ReadOnlyPdo, 'es el envoltorio de solo lectura');
});

test('la dirección del origen: solo https (http si es local), sin usuario y que no sea este sitio', function (): void {
    $app = importApp();
    $conns = importService($app)->connections();
    same(null, $conns->validateUrl('https://tienda-vieja.example'));
    same(null, $conns->validateUrl('https://tienda-vieja.example:8443/blog'));
    same(null, $conns->validateUrl('http://localhost:8080'));
    same(null, $conns->validateUrl('http://127.0.0.1'));
    same(null, $conns->validateUrl('http://origen.test'));
    same('Only https addresses are allowed (http only for a local address)', $conns->validateUrl('http://tienda-vieja.example'));
    same('Only https addresses are allowed (http only for a local address)', $conns->validateUrl('ftp://tienda-vieja.example'));
    same('Enter the full address of the shop, for example https://shop.example', $conns->validateUrl('tienda-vieja.example'));
    same('Enter the full address of the shop, for example https://shop.example', $conns->validateUrl(''));
    same('The address cannot contain a user name or a password', $conns->validateUrl('https://' . 'admin' . ':' . 'dato' . '@' . 'tienda-vieja.example'));
    same('That address is this same shop', $conns->validateUrl('https://tienda.test'), 'el propio sitio de las pruebas es tienda.test');
    same('That address is this same shop', $conns->validateUrl('https://TIENDA.test/otra-ruta'));
    same(null, $conns->validateUrl('https://tienda.test:8443'), 'otro puerto es otra tienda');
});

test('normalize: campos obligatorios, un secreto vacío conserva el guardado y se validan direcciones, carpetas y opciones', function (): void {
    $app = importApp();
    $conns = importService($app)->connections();
    $dir = tmpDir('files');
    $card = ['fields' => [
        'url' => ['type' => 'url', 'label' => 'Shop address', 'required' => true],
        'key' => ['type' => 'secret', 'label' => 'Key', 'required' => true],
        'files' => ['type' => 'path', 'label' => 'Files', 'required' => false],
        'units' => ['type' => 'select', 'label' => 'Units', 'options' => ['kg' => 'kg', 'g' => 'g'], 'default' => 'kg'],
    ]];
    $bad = $conns->normalize($card, ['url' => 'http://tienda-vieja.example', 'key' => '', 'files' => $dir . '/no-existe', 'units' => 'lb']);
    same(['url', 'key', 'files', 'units'], array_keys($bad['errors']));
    same('This field is required', $bad['errors']['key']);
    $ok = $conns->normalize($card, ['url' => 'https://tienda-vieja.example/', 'key' => '', 'files' => $dir . '\\', 'units' => ''], ['key' => 'guardada-1234']);
    same([], $ok['errors']);
    same('https://tienda-vieja.example', $ok['config']['url'], 'sin barra final');
    same('guardada-1234', $ok['config']['key'], 'el secreto vacío conserva el guardado');
    same(rtrim(str_replace('\\', '/', $dir), '/'), $ok['config']['files']);
    same('kg', $ok['config']['units'], 'el valor por omisión de un select');

    $db = $conns->normalizeDb(['db_host' => 'db.test', 'db_port' => '', 'db_name' => 'tiendaps', 'db_user' => 'lector', 'db_password' => ''], ['password' => 'p-guardada']);
    same([], $db['errors']);
    same(['host' => 'db.test', 'port' => 3306, 'name' => 'tiendaps', 'user' => 'lector', 'password' => 'p-guardada'], $db['db']);
    $badDb = $conns->normalizeDb(['db_host' => 'a b', 'db_port' => 'x', 'db_name' => '', 'db_user' => '']);
    same(['db_host', 'db_port', 'db_name', 'db_user'], array_keys($badDb['errors']));
});

test('Redactor tapa los secretos largos, ordena por tamaño y acorta', function (): void {
    same('a *** b', Redactor::scrub('a clave-larga b', ['clave-larga', 'ab']));
    same('x ***', Redactor::scrub('x clave-larga-2', ['clave-larga', 'clave-larga-2']), 'primero el más largo');
    same('no cambia ab', Redactor::scrub('no cambia ab', ['ab']), 'un valor de menos de 4 caracteres taparía palabras corrientes');
    same(10, mb_strlen(Redactor::scrub(str_repeat('z', 50), [], 10)));
    same('mucho espacio', Redactor::scrub("mucho \n  espacio", []));
    $secrets = Redactor::secretsOf('customers', array_merge(neutralSample('customers'), ['password_hash' => 'hash-secreto-123']));
    same(['hash-secreto-123'], $secrets);
    same(['clave-de-mentira'], Redactor::secretsOf('settings', neutralSample('settings')));
    check(in_array('clave-de-mentira', Redactor::secretsOf('payment_methods', neutralSample('payment_methods')), true), 'los secretos de una forma de pago');
});
