<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las direcciones públicas de los ficheros descargables de un producto (public/descargas/<código>/<nombre>): activar, desactivar,
 * sustituir por una versión nueva sin perder la dirección, borrar, nombres maliciosos, códigos impredecibles y que nada se lista.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Catalog\Admin\ProductSaver;
use Aifec\Modules\Catalog\PublicFiles;

require_once __DIR__ . '/AdminProductKitTest.php';

const PF_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

/**
 * Un producto digital con un fichero.
 *
 * @return array{0:\Aifec\Kernel\App,1:TestClient,2:int,3:array<string,mixed>,4:string}
 */
function pfShop(bool $public = true, string $upload = 'manual.pdf', string $content = PF_PDF): array
{
    $app = prodApp();
    $web = adminClient($app);
    $form = prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'new_file_name' => 'Manual', 'new_file_version' => '1.0']);
    if ($public) {
        $form['new_file_public'] = '1';
    }
    $file = prodFile($content, $upload);
    $r = prodSave($web, $form, null, prodUpload('new_file', $file, $upload));
    same(302, $r->status);
    $id = prodScalar($app, 'SELECT MAX(id) FROM {products}');

    return [$app, $web, $id, (array) $app->db()->row('SELECT * FROM {product_files} WHERE product_id = ?', [$id]), $file];
}

/** @return string[] lo que hay dentro de una carpeta pública, sin los index.php */
function pfFiles(string $dir): array
{
    $out = [];
    foreach (glob($dir . '/*') ?: [] as $entry) {
        if (basename($entry) !== 'index.php') {
            $out[] = basename($entry);
        }
    }

    return $out;
}

test('activar la dirección pública copia el fichero a descargas/<código>/ con un código de 48 cifras, byte a byte', function (): void {
    [$app, , , $row, $original] = pfShop();
    $token = (string) $row['public_token'];
    same(1, preg_match('/^[a-f0-9]{48}$/', $token), 'el código son 48 cifras hexadecimales: ' . $token);
    $dir = $app->publicDir() . '/descargas/' . $token;
    same(['manual.pdf'], pfFiles($dir), 'la carpeta tiene su fichero con el nombre original');
    same(sha1_file($original), sha1_file($dir . '/manual.pdf'), 'la copia coincide byte a byte con el original');
    // Sin listados: la carpeta de descargas y cada carpeta redirigen y no enseñan nada.
    foreach ([$app->publicDir() . '/descargas/index.php', $dir . '/index.php'] as $index) {
        check(is_file($index), $index . ' existe');
        contains("header('Location: ../');", (string) file_get_contents($index));
    }
    contains('Options -Indexes', (string) file_get_contents($app->publicDir() . '/descargas/.htaccess'));
    $names = array_map('basename', array_merge(glob($app->publicDir() . '/descargas/*') ?: [], glob($app->publicDir() . '/descargas/.htaccess') ?: []));
    sort($names);
    $expected = ['.htaccess', 'index.php', $token];
    sort($expected);
    same($expected, $names, 'en descargas solo hay el index.php, el .htaccess y las carpetas con código');
});

test('un SVG con guiones se publica limpio, una página HTML se publica como texto y las descargas van con nosniff y como adjunto', function (): void {
    $evil = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="1" height="1"/></svg>';
    [$app, , , $row] = pfShop(true, 'logo.svg', $evil);
    $dir = $app->publicDir() . '/descargas/' . (string) $row['public_token'];
    $names = pfFiles($dir);
    same(['logo.svg'], $names);
    $copy = (string) file_get_contents($dir . '/logo.svg');
    notContains('<script', $copy);
    notContains('onload', $copy);
    contains('<rect', $copy);

    same('pagina.html.txt', PublicFiles::sanitize('pagina.html'));
    same('x.xhtml.txt', PublicFiles::sanitize('x.xhtml'));
    same('a.js.txt', PublicFiles::sanitize('a.js'));
    same('manual.pdf', PublicFiles::sanitize('manual.pdf'));
    $htaccess = (string) file_get_contents($app->publicDir() . '/descargas/.htaccess');
    contains('nosniff', $htaccess);
    contains('Content-Disposition', $htaccess);
    contains('Options -Indexes', $htaccess);
});

test('un SVG que no es un SVG no se publica', function (): void {
    [$app, , , $row] = pfShop(true, 'raro.svg', '<html><body onload="alert(1)">hola</body></html>');
    $token = isset($row['public_token']) ? (string) $row['public_token'] : '';
    check($token === '' || pfFiles($app->publicDir() . '/descargas/' . $token) === [], 'no queda copia pública de un SVG inválido');
});

test('dos activaciones dan códigos distintos e impredecibles', function (): void {
    [, , , $one] = pfShop();
    [, , , $two] = pfShop();
    check((string) $one['public_token'] !== (string) $two['public_token'], 'dos activaciones, dos códigos');
    $tokens = [];
    for ($i = 0; $i < 200; ++$i) {
        $token = PublicFiles::newToken();
        same(48, strlen($token));
        check(PublicFiles::validToken($token), 'siempre son 48 cifras hexadecimales');
        $tokens[$token] = true;
    }
    same(200, count($tokens), 'ni uno repetido en 200 códigos');
    check(!PublicFiles::validToken('../../etc/passwd') && !PublicFiles::validToken(str_repeat('A', 48)) && !PublicFiles::validToken(str_repeat('a', 47)), 'un código que no es de 48 cifras minúsculas no vale');
});

test('guardar sin tocar el desplegable no cambia el código ni la copia', function (): void {
    [$app, $web, $id, $row] = pfShop();
    $token = (string) $row['public_token'];
    $rows = [['id' => (string) $row['id'], 'name' => 'Manual', 'version' => '1.0', 'public' => '1']];
    for ($i = 0; $i < 3; ++$i) {
        same(302, prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'files' => $rows]), $id)->status);
        same($token, (string) $app->db()->value('SELECT public_token FROM {product_files} WHERE id = ?', [$row['id']]), 'el mismo código tras guardar otra vez');
    }
    same(['manual.pdf'], pfFiles($app->publicDir() . '/descargas/' . $token));
    // Un formulario antiguo, sin el campo «public», tampoco lo cambia.
    same(302, prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'files' => [['id' => (string) $row['id'], 'name' => 'Manual', 'version' => '1.0']]]), $id)->status);
    same($token, (string) $app->db()->value('SELECT public_token FROM {product_files} WHERE id = ?', [$row['id']]));
    // La copia se renueva en cada guardado: si alguien la borra o la estropea, vuelve a estar.
    file_put_contents($app->publicDir() . '/descargas/' . $token . '/manual.pdf', 'estropeada');
    prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'files' => $rows]), $id);
    same(PF_PDF, (string) file_get_contents($app->publicDir() . '/descargas/' . $token . '/manual.pdf'), 'la copia se renueva al guardar');
});

test('desactivar la dirección borra la carpeta y el código, y el fichero privado sigue ahí', function (): void {
    [$app, $web, $id, $row] = pfShop();
    $token = (string) $row['public_token'];
    $dir = $app->publicDir() . '/descargas/' . $token;
    check(is_dir($dir), 'antes existe');
    same(302, prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'files' => [['id' => (string) $row['id'], 'name' => 'Manual', 'version' => '1.0', 'public' => '0']]]), $id)->status);
    check(!is_dir($dir), 'la carpeta pública ya no está');
    same(null, $app->db()->value('SELECT public_token FROM {product_files} WHERE id = ?', [$row['id']]), 'ni el código');
    check($app->service('files')->path((string) $row['path']) !== null, 'el fichero privado sigue entregándose con el enlace del pedido');
    // Volver a activarla da un código nuevo: el viejo no resucita.
    prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'files' => [['id' => (string) $row['id'], 'name' => 'Manual', 'version' => '1.0', 'public' => '1']]]), $id);
    $again = (string) $app->db()->value('SELECT public_token FROM {product_files} WHERE id = ?', [$row['id']]);
    check($again !== '' && $again !== $token, 'un código nuevo');
});

test('subir una versión nueva sustituye el fichero privado y la copia pública conserva el código y el nombre', function (): void {
    [$app, $web, $id, $row] = pfShop();
    $token = (string) $row['public_token'];
    $oldPath = (string) $row['path'];
    $oldFull = (string) $app->service('files')->path($oldPath);
    $new = prodFile("%PDF-1.4\nversion dos\n%%EOF\n", 'manual-v2.pdf');
    $rows = [['id' => (string) $row['id'], 'name' => 'Manual', 'version' => '1.0', 'public' => '1']];
    same(302, prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'files' => $rows, 'new_file_version' => '2.0', 'new_file_replaces' => (string) $row['id']]), $id, prodUpload('new_file', $new, 'manual-v2.pdf'))->status);
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_files} WHERE product_id = ?', [$id]), 'sigue habiendo una sola fila');
    $after = (array) $app->db()->row('SELECT * FROM {product_files} WHERE id = ?', [$row['id']]);
    same($token, (string) $after['public_token'], 'el mismo código: la dirección del aviso sigue valiendo');
    check((string) $after['path'] !== $oldPath, 'el fichero privado es el nuevo');
    same('2.0', (string) $after['version']);
    check(!is_file($oldFull), 'el privado de antes se ha borrado');
    same(['manual.pdf'], pfFiles($app->publicDir() . '/descargas/' . $token), 'la carpeta tiene un solo fichero, con el nombre de siempre');
    same(sha1_file($new), sha1_file($app->publicDir() . '/descargas/' . $token . '/manual.pdf'), 'y con el contenido nuevo');
});

test('un fichero nuevo sin la casilla queda privado: sin carpeta en descargas', function (): void {
    [$app, , , $row] = pfShop(false);
    same(null, $app->db()->value('SELECT public_token FROM {product_files} WHERE id = ?', [$row['id']]));
    check(!is_dir($app->publicDir() . '/descargas') || (glob($app->publicDir() . '/descargas/*') ?: []) === [] || pfFiles($app->publicDir() . '/descargas') === [], 'no hay ninguna carpeta con código');
});

test('quitar la fila y borrar el producto borran la carpeta pública', function (): void {
    [$app, $web, $id, $row] = pfShop();
    $dir = $app->publicDir() . '/descargas/' . $row['public_token'];
    check(is_dir($dir));
    same(302, prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital']), $id)->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {product_files} WHERE product_id = ?', [$id]));
    check(!is_dir($dir), 'quitar la fila borra la carpeta');

    [$app2, , $id2, $row2] = pfShop();
    $dir2 = $app2->publicDir() . '/descargas/' . $row2['public_token'];
    check(is_dir($dir2));
    ProductSaver::erase($app2, $id2);
    check(!is_dir($dir2), 'borrar el producto también');
});

test('el nombre se sanea: sin carpetas, sin «..», sin caracteres de control, sin index.php y sin extensiones que se ejecuten', function (): void {
    $cases = [
        '../../etc/passwd' => 'passwd',
        '..\\..\\windows\\system.ini' => 'system.ini',
        'index.php' => 'index.php.txt',
        'INDEX.PHP' => 'INDEX.PHP.txt',
        'x.php' => 'x.php.txt',
        'x.phtml' => 'x.phtml.txt',
        'x.phar' => 'x.phar.txt',
        'shell.php.jpg' => 'shell.php.jpg.txt',
        '.htaccess' => 'htaccess',
        '..' => 'fichero',
        '' => 'fichero',
        "a\x00b\x1fc\n.txt" => 'a-b-c-.txt',
        'Mi informe (v2).pdf' => 'Mi-informe-v2-.pdf',
        'ñandú.zip' => 'and-.zip',
    ];
    foreach ($cases as $in => $out) {
        same($out, PublicFiles::sanitize((string) $in), 'sanear «' . addcslashes((string) $in, "\0..\37") . '»');
    }
    foreach (['../x', 'a/../b', "z\0.php", str_repeat('é', 400), 'index.php', '..'] as $bad) {
        $name = PublicFiles::sanitize($bad);
        same(1, preg_match('/^[A-Za-z0-9._-]{1,110}$/', $name), 'solo caracteres seguros: ' . $name);
        check(strpos($name, '..') === false && $name[0] !== '.' && strtolower($name) !== 'index.php', 'sin «..», sin punto delante y nunca index.php: ' . $name);
    }
});

test('un fichero que ya estaba en la carpeta de otro no se pisa y un nombre malicioso no sale de su carpeta', function (): void {
    [$app, $web, $id, $row] = pfShop(true, 'cursos.zip', 'PK-contenido');
    $base = $app->publicDir() . '/descargas';
    // Un fichero ajeno junto a las carpetas.
    file_put_contents($base . '/ajeno.txt', 'no tocar');
    $token = (string) $row['public_token'];
    prodSave($web, prodForm(['name' => ['es' => 'Módulo', 'en' => ''], 'kind' => 'digital', 'files' => [['id' => (string) $row['id'], 'name' => '../../index', 'version' => '', 'public' => '1']]]), $id);
    same('no tocar', (string) file_get_contents($base . '/ajeno.txt'), 'un fichero ajeno no se toca');
    same(['cursos.zip'], pfFiles($base . '/' . $token), 'el nombre público sale del fichero guardado, no del campo «nombre»');
    // Borrar un código raro no sale de descargas.
    PublicFiles::remove($app, '../' . str_repeat('a', 45));
    PublicFiles::remove($app, str_repeat('b', 48));
    same('no tocar', (string) file_get_contents($base . '/ajeno.txt'));
    check(is_dir($base . '/' . $token), 'y la carpeta buena sigue');
});

test('/descargas/ y /descargas/<código>/ no listan nada: ni el nombre del fichero ni los códigos', function (): void {
    [$app, , , $row] = pfShop();
    $token = (string) $row['public_token'];
    $web = new TestClient($app);
    foreach (['/descargas/', '/descargas', '/descargas/' . $token . '/', '/descargas/' . $token] as $path) {
        $r = $web->get($path);
        check(in_array($r->status, [301, 302, 403, 404], true), $path . ' no responde con un listado: ' . $r->status);
        notContains('manual.pdf', $r->body, $path);
        if (strpos($path, $token) === false) {
            notContains($token, $r->body, $path . ' no enseña ningún código');
        }
    }
    // Y en disco, solo el index.php que redirige y las carpetas con código.
    $names = array_map('basename', glob($app->publicDir() . '/descargas/*') ?: []);
    sort($names);
    $expected = ['index.php', $token];
    sort($expected);
    same($expected, $names);
});

test('el formulario del producto enseña el código, dónde está guardado y la dirección con su botón de copiar, y el id de la fila no es una caja', function (): void {
    [$app, $web, $id, $row] = pfShop();
    $html = $web->get('/admin/r/products/' . $id)->body;
    $url = PublicFiles::url($app, (string) $row['public_token'], 'manual.pdf');
    contains('/descargas/' . $row['public_token'] . '/manual.pdf', $html, 'la dirección completa');
    contains('data-af-copy="' . $url . '"', $html, 'el botón de copiar la dirección');
    contains($app->i18n()->t('Public link'), $html);
    contains($app->i18n()->t('Stored at'), $html);
    contains(str_replace('\\', '/', $app->site()->dataDir) . '/private/' . $row['path'], str_replace('\\', '/', $html), 'la ruta absoluta del fichero');
    same(1, preg_match('/<input type="hidden"[^>]*name="f\[files\]\[0\]\[id\]"[^>]*value="' . $row['id'] . '"/', $html), 'el id va oculto');
    same(0, preg_match('/<input[^>]*type="text"[^>]*name="f\[files\]\[0\]\[id\]"/', $html), 'y no hay ninguna caja de texto con el id');
    same(0, preg_match('/<input[^>]*name="f\[files\]\[0\]\[id\]"[^>]*type="text"/', $html));
    check(strpos($html, 'name="f[new_file_public]"') !== false && strpos($html, 'name="f[new_file_replaces]"') !== false, 'la subida lleva la casilla y el «sustituye a»');
});

test('la columna del código la crea la migración 0004 en una instalación que ya existía, y repetirla no rompe nada', function (): void {
    $app = prodApp();
    check($app->db()->tableExists('product_files'), 'la tabla existe');
    check(in_array('public_token', array_map('strval', array_keys($app->db()->columns('product_files'))), true) || in_array('public_token', $app->db()->columns('product_files'), true), 'la columna está en las instalaciones nuevas');
    $migration = require dirname(__DIR__) . '/migrations/0004_product_files_public_token.php';
    $schema = new \Aifec\Db\Schema($app->db());
    $migration($schema, $app->db());
    $migration($schema, $app->db());
    check($schema->hasColumn('product_files', 'public_token'), 'sigue la columna');
});
