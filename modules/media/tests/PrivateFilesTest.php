<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\HttpError;
use Aifec\Media\MediaException;
use Aifec\Modules\Media\PrivateFiles;

require_once __DIR__ . '/helpers.php';

/** Servicio «files» de una aplicación con el módulo de medios. */
function mediaPrivate(\Aifec\Kernel\App $app): PrivateFiles
{
    /** @var PrivateFiles $files */
    $files = $app->service('files');

    return $files;
}

test('PrivateFiles guarda fuera de la carpeta pública y devuelve la ruta relativa, el nombre, el peso y el tipo', function (): void {
    $app = mediaApp();
    $files = mediaPrivate($app);
    $tmp = mediaFile("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n", 'Factura Ñ 2026.pdf');

    $stored = $files->store($tmp, 'Factura Ñ 2026.pdf', 'pedidos');

    check(preg_match('#^pedidos/[0-9a-f]{16}-factura-n-2026\.pdf$#', $stored['path']) === 1, 'ruta relativa a la carpeta privada: ' . $stored['path']);
    same('factura-n-2026.pdf', $stored['name']);
    same((int) filesize($tmp), $stored['bytes']);
    same('application/pdf', $stored['mime']);

    $full = $files->path($stored['path']);
    check($full !== null && is_file($full), 'el fichero existe');
    $private = str_replace('\\', '/', (string) realpath($app->site()->path('private')));
    check(strpos((string) $full, $private . '/') === 0, 'está dentro de la carpeta privada');
    check(strpos((string) $full, str_replace('\\', '/', $app->publicDir())) !== 0, 'no está en la raíz web');
    check(is_file($app->site()->path('private') . '/.htaccess'), 'la carpeta privada lleva su .htaccess');
    same(file_get_contents($tmp), file_get_contents((string) $full));

    $default = $files->store(mediaFile('hola', 'nota.txt'), 'nota.txt');
    check(strpos($default['path'], 'files/') === 0, 'la zona por defecto es «files»');
});

test('PrivateFiles.path() no resuelve nada que se salga de la carpeta privada', function (): void {
    $app = mediaApp();
    $files = mediaPrivate($app);
    $stored = $files->store(mediaFile('contenido', 'ok.txt'), 'ok.txt');
    file_put_contents($app->site()->path('secreto.txt', false), 'fuera');
    $app->site()->path('private/otra', true);

    check($files->path($stored['path']) !== null);
    foreach (['../x', '../secreto.txt', 'files/../../secreto.txt', '/etc/passwd', '..', '', 'files/../files/' . basename($stored['path']), "files/\0x", 'files\\..\\..\\secreto.txt', 'no/existe.txt', 'otra', 'files'] as $bad) {
        same(null, $files->path($bad), 'tenía que ser null: ' . json_encode($bad));
    }

    // Un enlace simbólico dentro de la carpeta privada que apunta fuera no vale (si el sistema deja crearlo).
    $outside = tmpDir('media-outside');
    file_put_contents($outside . '/fuera.txt', 'no');
    if (@symlink($outside, $app->site()->path('private') . '/enlace')) {
        same(null, $files->path('enlace/fuera.txt'), 'un enlace que sale de la carpeta no se sigue');
    }
});

test('PrivateFiles.response() es una descarga con el nombre pedido; sin fichero, 404', function (): void {
    $app = mediaApp();
    $files = mediaPrivate($app);
    $stored = $files->store(mediaFile("a;b\n1;2\n", 'datos.csv'), 'datos.csv', 'informes');

    $response = $files->response($stored['path'], 'Informe "mayo".csv');
    same(200, $response->status);
    check($response->file === $files->path($stored['path']), 'sirve ese fichero');
    contains('attachment', $response->headers['Content-Disposition']);
    contains('Informe mayo.csv', $response->headers['Content-Disposition']);
    same('nosniff', $response->headers['X-Content-Type-Options']);
    contains('no-store', $response->headers['Cache-Control']);

    throws(function () use ($files): void {
        $files->response('informes/no-existe.csv', 'x.csv');
    }, HttpError::class);
    throws(function () use ($files): void {
        $files->response('../private/x', 'x.csv');
    }, HttpError::class);
});

test('PrivateFiles rechaza lo ejecutable por el nombre y por el contenido, y no deja nada escrito', function (): void {
    $app = mediaApp();
    $files = mediaPrivate($app);
    $php = mediaFile('<?php echo 1;', 'shell.php');
    $valid = mediaFile("%PDF-1.4\n%%EOF\n", 'x.pdf');

    foreach (['shell.php', 'shell.PHP', 'a.php.pdf', 'a.pdf.php', 'x.phtml', '.htaccess', 'run.sh', 'setup.exe', 'pagina.html', 'x.js'] as $name) {
        throws(function () use ($files, $valid, $name): void {
            $files->store($valid, $name);
        }, MediaException::class, 'por el nombre: ' . $name);
    }
    foreach (['factura.pdf', 'foto.jpg', 'datos.txt'] as $name) {
        throws(function () use ($files, $php, $name): void {
            $files->store($php, $name);
        }, MediaException::class, 'por el contenido: ' . $name);
    }
    throws(function () use ($files): void {
        $files->store('/no/existe', 'a.pdf');
    }, MediaException::class);
    throws(function () use ($files): void {
        $files->store(mediaFile('', 'vacio.pdf'), 'vacio.pdf');
    }, MediaException::class);

    $stored = $files->store($valid, '../../etc/passwd.pdf', '../../fuera');
    check(strpos($stored['path'], '..') === false, 'ni el nombre ni la zona pueden subir de carpeta: ' . $stored['path']);
    check($files->path($stored['path']) !== null);
    $all = mediaScan($app->site()->path('private'));
    same(1, count(array_filter($all, static function (string $f): bool {
        return substr($f, -4) === '.pdf';
    })), 'solo se guardó el bueno');
});

test('PrivateFiles.delete() borra el fichero y no falla con uno que no existe', function (): void {
    $app = mediaApp();
    $files = mediaPrivate($app);
    $stored = $files->store(mediaFile('adiós', 'borrar.txt'), 'borrar.txt');
    $full = (string) $files->path($stored['path']);
    check(is_file($full));

    $files->delete($stored['path']);
    check(!is_file($full), 'borrado');
    same(null, $files->path($stored['path']));
    $files->delete($stored['path']);
    $files->delete('../x');
    check(true);
});
