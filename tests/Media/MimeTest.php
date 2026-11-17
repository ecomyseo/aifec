<?php
/**
 * AIFEC — pruebas de los tipos MIME y los nombres de fichero seguros.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Media\MediaException;
use Aifec\Media\Mime;

test('safeFilename quita rutas, dobles extensiones peligrosas y signos', function (): void {
    $name = Mime::safeFilename('../../Mi Foto (1).PHP.jpg');
    same('mi-foto-1.jpg', $name);
    notContains('/', $name);
    notContains('\\', $name);
    notContains('.php', $name);
    same('foto.jpg', Mime::safeFilename('C:\\fotos\\Foto.JPG'));
    same('cancion-del-nino.png', Mime::safeFilename('Canción del niño.png'));
    same('informe-final.pdf', Mime::safeFilename('Informe.Final.PDF'));
});

test('safeFilename elimina las extensiones ejecutables en cualquier posición', function (): void {
    same('shell', Mime::safeFilename('shell.php'));
    same('a-b.png', Mime::safeFilename('a.phtml.b.png'));
    same('x.png', Mime::safeFilename('x.php5.png'));
    same('x.png', Mime::safeFilename('x.html.png'));
    same('x', Mime::safeFilename('x.exe'));
    same('file', Mime::safeFilename('.htaccess'));
    same('x.jpg', Mime::safeFilename("x.php\0.jpg"));
});

test('safeFilename nunca devuelve vacío y respeta el máximo de 120 caracteres', function (): void {
    same('file', Mime::safeFilename(''));
    same('file', Mime::safeFilename('...'));
    same('file.png', Mime::safeFilename('😀😀.png'));
    same('file', Mime::safeFilename('???'));
    $long = Mime::safeFilename(str_repeat('abcdefghij', 30) . '.webp');
    check(strlen($long) <= 120, 'longitud ' . strlen($long));
    same('.webp', substr($long, -5));
    $longNoExt = Mime::safeFilename(str_repeat('a', 300));
    same(120, strlen($longNoExt));
    $emojis = Mime::safeFilename('Ofertas & "descuentos" ñandú ✓.png');
    same('ofertas-descuentos-nandu.png', $emojis);
});

test('isAllowedUpload admite imágenes, documentos, vídeo y audio, y rechaza PHP, HTML y ejecutables', function (): void {
    foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'application/pdf', 'application/zip', 'text/plain', 'text/csv', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg'] as $mime) {
        check(Mime::isAllowedUpload($mime), $mime);
    }
    check(Mime::isAllowedUpload('IMAGE/JPEG'), 'mayúsculas');
    check(Mime::isAllowedUpload('text/plain; charset=utf-8'), 'con parámetros');
    check(Mime::isAllowedUpload('application/x-zip-compressed'), 'sinónimo de zip');
    foreach (['application/x-php', 'text/x-php', 'application/x-httpd-php', 'text/html', 'application/xhtml+xml', 'application/x-msdownload', 'application/x-dosexec', 'application/x-sh', 'application/javascript', 'application/octet-stream', ''] as $mime) {
        check(!Mime::isAllowedUpload($mime), 'debería rechazar «' . $mime . '»');
    }
});

test('extension devuelve la extensión que toca o null', function (): void {
    same('jpg', Mime::extension('image/jpeg'));
    same('webp', Mime::extension('image/webp'));
    same('svg', Mime::extension('image/svg+xml'));
    same('mp3', Mime::extension('audio/mpeg'));
    same('png', Mime::extension('Image/PNG; foo=bar'));
    same(null, Mime::extension('application/x-php'));
    same(null, Mime::extension(''));
});

test('fromContent mira el contenido y no la extensión', function (): void {
    $dir = tmpDir();
    $png = imagecreatetruecolor(4, 4);
    imagepng($png, $dir . '/foto.jpg');
    same('image/png', Mime::fromContent($dir . '/foto.jpg'));

    file_put_contents($dir . '/texto.png', "hola mundo\n");
    same('text/plain', Mime::fromContent($dir . '/texto.png'));

    file_put_contents($dir . '/logo.txt', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>');
    same('image/svg+xml', Mime::fromContent($dir . '/logo.txt'));

    file_put_contents($dir . '/imagen.jpg', "<?php echo 'x';\n");
    check(!Mime::isAllowedUpload(Mime::fromContent($dir . '/imagen.jpg')), 'un PHP con extensión .jpg no se admite');

    throws(function () use ($dir): void {
        Mime::fromContent($dir . '/no-existe.png');
    }, MediaException::class);
});
