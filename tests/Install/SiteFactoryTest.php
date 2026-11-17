<?php
/**
 * AIFEC — pruebas de la creación y el borrado de sitios y de las comprobaciones del servidor.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Install\Requirements;
use Aifec\Install\SiteFactory;
use Aifec\Kernel\SiteResolver;

require_once __DIR__ . '/support.php';

test('crear un sitio SQLite deja sites.php, config.php con su clave y la base, y la persona administradora entra al panel', function (): void {
    [$root, $data, $public] = aifecInstEmpty();
    $result = SiteFactory::create($root, $data, aifecInstOptions(['demo' => true, 'public_dir' => $public]));
    same(['id' => 'main', 'url' => 'https://tienda.test', 'admin_url' => 'https://tienda.test/admin', 'admin_email' => 'ana@tienda.test'], $result);

    same([['id' => 'main', 'host' => '*', 'path' => '']], SiteResolver::all($data));
    $config = require $data . '/main/config.php';
    same(64, strlen((string) $config['secret']), 'la clave del sitio son 32 bytes en hexadecimal');
    same('sqlite', $config['db']['driver']);
    same('af_', $config['db']['prefix']);
    same(true, $config['demo']);
    check(is_file($data . '/main/db.sqlite'), 'la base SQLite está en la carpeta del sitio');

    $db = aifecInstDb($data, 'main');
    same('Mi tienda & más', json_decode((string) $db->value('SELECT v FROM {settings} WHERE k = ?', ['store.name']), true));
    $user = $db->row('SELECT email, name, role, active FROM {users}');
    $user['active'] = (int) $user['active'];
    same(['email' => 'ana@tienda.test', 'name' => 'Ana Ruiz', 'role' => 'admin', 'active' => 1], $user);
    check(strpos((string) $db->value('SELECT password FROM {users}'), 'clave-larga') === false, 'la contraseña se guarda con hash');

    $site = SiteResolver::load($data, 'main');
    same(true, $site->isDemo());

    $login = aifecInstPost([$root, $data, $public], '/admin/login', ['email' => 'ana@tienda.test', 'password' => 'clave-larga-1234']);
    same(302, $login->status, 'la persona administradora entra');
    $panel = aifecInstGet([$root, $data, $public], '/admin', [], aifecInstCookies($login));
    same(200, $panel->status);
    contains('Mi tienda &amp; más', $panel->body, 'el panel enseña el nombre de la tienda guardado, escapado');
});

test('un segundo sitio en /demos/moda es otro: otra base, otra persona, y el principal sigue igual', function (): void {
    [$root, $data, $public] = aifecInstEmpty();
    $i = [$root, $data, $public];
    SiteFactory::create($root, $data, aifecInstOptions(['public_dir' => $public]));
    $second = SiteFactory::create($root, $data, aifecInstOptions([
        'id' => 'moda', 'path' => '/demos/moda', 'url' => 'https://tienda.test/demos/moda', 'shop_name' => 'Moda', 'demo' => true,
        'admin' => ['name' => 'Bea Sanz', 'email' => 'bea@tienda.test'], 'public_dir' => $public,
    ]));
    same('https://tienda.test/demos/moda/admin', $second['admin_url']);
    same(2, count(SiteResolver::all($data)));

    $moda = aifecInstGet($i, '/demos/moda/admin/login');
    same(200, $moda->status);
    contains('Moda', $moda->body);
    $main = aifecInstGet($i, '/admin/login');
    same(200, $main->status);
    contains('Mi tienda &amp; más', $main->body);
    check(strpos($main->body, 'Moda') === false, 'el principal no enseña el nombre del otro sitio');

    check(is_file($data . '/main/db.sqlite') && is_file($data . '/moda/db.sqlite') && $data . '/main/db.sqlite' !== $data . '/moda/db.sqlite');
    same('ana@tienda.test', aifecInstDb($data, 'main')->value('SELECT email FROM {users}'));
    same('bea@tienda.test', aifecInstDb($data, 'moda')->value('SELECT email FROM {users}'));
    same(1, (int) aifecInstDb($data, 'moda')->value('SELECT COUNT(*) FROM {users}'));

    same(302, aifecInstPost($i, '/demos/moda/admin/login', ['email' => 'bea@tienda.test', 'password' => 'clave-larga-1234'])->status, 'Bea entra en el suyo');
    same(422, aifecInstPost($i, '/demos/moda/admin/login', ['email' => 'ana@tienda.test', 'password' => 'clave-larga-1234'])->status, 'Ana no existe en el de moda');
    same(422, aifecInstPost($i, '/admin/login', ['email' => 'bea@tienda.test', 'password' => 'clave-larga-1234'])->status, 'Bea no existe en el principal');
    same(302, aifecInstPost($i, '/admin/login', ['email' => 'ana@tienda.test', 'password' => 'clave-larga-1234'])->status, 'Ana sigue entrando en el principal');
});

test('lo que no vale se rechaza con su motivo y no se escribe nada: ni carpeta ni entrada en sites.php', function (): void {
    $root = aifecInstRoot();
    $data = tmpDir('aifec-inst-data') . '/nueva';
    $bad = [
        'id repetido o mal formado' => ['id' => 'Moda_1'],
        'ruta sin barra inicial' => ['path' => 'demos/moda'],
        'ruta con barra final' => ['path' => '/demos/moda/'],
        'ruta con mayúsculas' => ['path' => '/Demos'],
        'ruta con espacios' => ['path' => '/a b'],
        'ruta solo barra' => ['path' => '/'],
        'contraseña corta' => ['admin' => ['password' => 'corta-123']],
        'correo mal' => ['admin' => ['email' => 'no-es-un-correo']],
        'sin nombre de administrador' => ['admin' => ['name' => '']],
        'dirección sin protocolo' => ['url' => 'tienda.test'],
        'zona horaria inventada' => ['timezone' => 'Marte/Olimpo'],
        'idioma mal' => ['locale' => 'castellano'],
        'módulo que no existe' => ['modules' => ['no-existe']],
        'base de datos rara' => ['db' => ['driver' => 'oracle']],
    ];
    foreach ($bad as $why => $over) {
        $message = '';
        try {
            SiteFactory::create($root, $data, aifecInstOptions($over));
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
        }
        check($message !== '', 'tendría que rechazar: ' . $why);
        check(!file_exists($data), 'no escribe nada con: ' . $why);
    }
});

test('un sitio repetido, o con el mismo dominio y ruta, se rechaza sin tocar el que ya existe', function (): void {
    [$root, $data, $public] = aifecInstEmpty();
    SiteFactory::create($root, $data, aifecInstOptions(['public_dir' => $public]));
    $before = (string) file_get_contents($data . '/sites.php');
    $dbBefore = md5_file($data . '/main/db.sqlite');

    throws(function () use ($root, $data): void {
        SiteFactory::create($root, $data, aifecInstOptions(['path' => '/otro']));
    }, \InvalidArgumentException::class, 'mismo id');
    throws(function () use ($root, $data): void {
        SiteFactory::create($root, $data, aifecInstOptions(['id' => 'otro']));
    }, \InvalidArgumentException::class, 'mismo dominio y ruta');
    SiteFactory::create($root, $data, aifecInstOptions(['id' => 'moda', 'host' => 'Tienda.TEST', 'path' => '/demos/moda']));
    throws(function () use ($root, $data): void {
        SiteFactory::create($root, $data, aifecInstOptions(['id' => 'moda2', 'host' => 'tienda.test', 'path' => '/demos/moda']));
    }, \InvalidArgumentException::class, 'el dominio se compara sin mayúsculas');

    check(!is_dir($data . '/otro') && !is_dir($data . '/moda2'));
    same(2, count(SiteResolver::all($data)));
    same($dbBefore, md5_file($data . '/main/db.sqlite'), 'la base del sitio existente no se toca');
    check(strpos((string) file_get_contents($data . '/sites.php'), 'moda') !== false && $before !== (string) file_get_contents($data . '/sites.php'));
});

test('si falla a mitad no queda ni la carpeta, ni la entrada en sites.php, ni la carpeta de datos que se creó', function (): void {
    $root = aifecInstRoot();
    $data = tmpDir('aifec-inst-data') . '/recien-creada';
    // Un ajuste que no se puede guardar como JSON (UTF-8 roto) rompe el proceso cuando ya hay carpeta, config y base.
    $failed = false;
    try {
        SiteFactory::create($root, $data, aifecInstOptions(['settings' => ['bad.value' => "\xB1\x31"]]));
    } catch (\RuntimeException $e) {
        $failed = true;
        contains('Could not create the site', $e->getMessage());
    }
    check($failed, 'tenía que fallar a mitad');
    check(!is_dir($data . '/main'), 'sin carpeta del sitio');
    check(!is_file($data . '/sites.php'), 'sin sites.php');
    check(!file_exists($data), 'ni siquiera la carpeta de datos que creó la propia orden');
    same([], SiteResolver::all($data));

    // Con otro sitio ya instalado, el fallo lo deja exactamente como estaba.
    [$root, $data2, $public] = aifecInstEmpty();
    SiteFactory::create($root, $data2, aifecInstOptions(['public_dir' => $public]));
    $sites = (string) file_get_contents($data2 . '/sites.php');
    throws(function () use ($root, $data2): void {
        SiteFactory::create($root, $data2, aifecInstOptions(['id' => 'moda', 'path' => '/moda', 'settings' => ['bad.value' => "\xB1\x31"]]));
    }, \RuntimeException::class);
    check(!is_dir($data2 . '/moda'));
    same($sites, (string) file_get_contents($data2 . '/sites.php'));
    same(1, count(SiteResolver::all($data2)));
});

test('con datos de MySQL imposibles se rechaza antes de escribir nada y sin enseñar la contraseña', function (): void {
    $root = aifecInstRoot();
    $data = tmpDir('aifec-inst-data') . '/sin-escribir';
    $message = '';
    try {
        SiteFactory::create($root, $data, aifecInstOptions(['db' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'name' => 'x', 'user' => 'u', 'password' => 'sup3r-secreta', 'prefix' => 'af_']]));
    } catch (\InvalidArgumentException $e) {
        $message = $e->getMessage();
    }
    check($message !== '', 'tenía que rechazarlo');
    notContains('sup3r-secreta', $message);
    check(!file_exists($data), 'no se ha escrito nada');
    foreach ([['prefix' => 'AF-'], ['name' => 'a;b'], ['host' => 'a b'], ['port' => 70000], ['user' => '']] as $over) {
        throws(function () use ($root, $data, $over): void {
            SiteFactory::create($root, $data, aifecInstOptions(['db' => $over + ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'name' => 'x', 'user' => 'u', 'prefix' => 'af_']]));
        }, \InvalidArgumentException::class);
    }
    check(!file_exists($data));
});

test('borrar un sitio quita su entrada y su carpeta y deja el otro intacto', function (): void {
    [$root, $data, $public] = aifecInstEmpty();
    SiteFactory::create($root, $data, aifecInstOptions(['public_dir' => $public]));
    SiteFactory::create($root, $data, aifecInstOptions(['id' => 'moda', 'path' => '/demos/moda', 'admin' => ['email' => 'bea@tienda.test']]));
    check(is_file($data . '/moda/db.sqlite'));

    SiteFactory::delete($data, 'moda');
    check(!is_dir($data . '/moda'), 'la carpeta se va con la base dentro');
    same([['id' => 'main', 'host' => '*', 'path' => '']], SiteResolver::all($data));
    check(is_file($data . '/main/db.sqlite'), 'el otro sitio sigue');
    same('ana@tienda.test', aifecInstDb($data, 'main')->value('SELECT email FROM {users}'));

    throws(function () use ($data): void {
        SiteFactory::delete($data, 'moda');
    }, \InvalidArgumentException::class, 'ya no existe');
    throws(function () use ($data): void {
        SiteFactory::delete($data, '../main');
    }, \InvalidArgumentException::class, 'un identificador con «..» no vale');
    check(is_dir($data . '/main'));

    SiteFactory::delete($data, 'main', true);
    same([], SiteResolver::all($data));
    check(!is_dir($data . '/main'));
    // Sin ningún sitio, el punto de entrada vuelve a ser el instalador.
    contains('Welcome to AIFEC', aifecInstGet([$root, $data, $public], '/')->body);
});

test('si la carpeta de datos cuelga de la pública, se protege con .htaccess e index.html vacío', function (): void {
    $root = aifecInstRoot();
    $public = tmpDir('aifec-inst-public');
    $inside = $public . '/datos';
    SiteFactory::create($root, $inside, aifecInstOptions(['public_dir' => $public]));
    contains('Require all denied', (string) file_get_contents($inside . '/.htaccess'));
    check(is_file($inside . '/index.html') && filesize($inside . '/index.html') === 0, 'index.html vacío');

    $outside = tmpDir('aifec-inst-data');
    SiteFactory::create($root, $outside, aifecInstOptions(['public_dir' => $public]));
    check(!is_file($outside . '/.htaccess') && !is_file($outside . '/index.html'), 'fuera de la pública no se escribe nada de más');

    // Lo que ya había no se pisa.
    $kept = tmpDir('aifec-inst-public') . '/d';
    mkdir($kept);
    file_put_contents($kept . '/.htaccess', 'MIO');
    SiteFactory::protectDataRoot($kept, dirname($kept));
    same('MIO', (string) file_get_contents($kept . '/.htaccess'));
});

test('las comprobaciones del servidor devuelven todas su forma y una carpeta imposible sale en rojo', function (): void {
    $public = tmpDir('aifec-inst-public');
    $rows = Requirements::check(tmpDir('aifec-inst-data'), $public);
    $ids = array_map(static function (array $r): string {
        return $r['id'];
    }, $rows);
    same(['php', 'pdo', 'mbstring', 'json', 'ctype', 'dom', 'crypto', 'images', 'intl', 'curl', 'fileinfo', 'zip', 'zlib', 'exif', 'ftp', 'data_dir', 'media_dir'], $ids);
    foreach ($rows as $row) {
        same(['id', 'label', 'ok', 'required', 'detail', 'impact'], array_keys($row));
        check(is_bool($row['ok']) && is_bool($row['required']) && is_string($row['label']) && is_string($row['detail']));
        check($row['impact'] !== '' && $row['impact'] === Requirements::IMPACT[$row['id']], 'cada comprobación dice qué deja de funcionar sin ella: ' . $row['id']);
    }
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }
    same(true, $byId['php']['ok']);
    same(PHP_VERSION, $byId['php']['detail']);
    same(true, $byId['data_dir']['ok']);
    same(true, $byId['media_dir']['ok'], 'la carpeta media no existe pero se puede crear');
    foreach (['php', 'pdo', 'mbstring', 'json', 'ctype', 'dom', 'crypto', 'data_dir', 'media_dir'] as $id) {
        same(true, $byId[$id]['required'], $id . ' es obligatorio');
    }
    foreach (['images', 'intl', 'curl', 'fileinfo', 'zip', 'zlib', 'exif', 'ftp'] as $id) {
        same(false, $byId[$id]['required'], $id . ' es recomendado');
    }

    // Una ruta que no se puede crear: cuelga de un fichero.
    $file = tmpDir('aifec-inst-file') . '/soy-un-fichero';
    file_put_contents($file, 'x');
    $bad = Requirements::check($file . '/datos', $file . '/publica');
    foreach ($bad as $row) {
        if ($row['id'] === 'data_dir' || $row['id'] === 'media_dir') {
            same(false, $row['ok'], $row['id'] . ' no se puede crear');
            same('Not writable', $row['detail']);
        }
    }
});
