<?php
/**
 * AIFEC — escritores de la biblioteca de medios y de las redirecciones: crean, repiten sin cambios, actualizan, no duplican, no pisan una
 * dirección que ya responde con contenido, descartan las cadenas que darían vueltas y no escriben nada en un simulacro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\MediaWriter;
use Aifec\Modules\Import\Writers\RedirectsWriter;

require_once __DIR__ . '/WritersKit.php';
require_once __DIR__ . '/../../../catalog/tests/helpers.php';

/** Un medio con fichero (el «path» es relativo a la carpeta de ficheros del origen). */
function wrMedia(array $over = []): array
{
    return wr('media', $over + ['id' => '310', 'url' => '', 'path' => 'u/taller.png', 'alt' => ['es' => 'El taller'], 'title' => 'taller', 'mime' => 'image/png', 'old_paths' => ['/wp-content/uploads/2026/03/taller.jpg']]);
}

test('un medio se trae a la biblioteca con su texto alternativo y su dirección antigua, se repite sin cambios y se actualiza', function (): void {
    $dir = tmpDir();
    wrPng($dir, 'u/taller.png', 6);
    $app = writersApp();
    $ctx = writersContext($app, [], [], $dir);
    $writer = new MediaWriter($app);

    $first = $writer->write(wrMedia(), $ctx);
    same(TypeWriter::CREATED, $first['result']);
    same(1, wrCount($app, 'media'));
    same('El taller', wrValue($app, 'SELECT alt FROM {media} WHERE id = ?', [$first['id']]));
    same('wp-content/uploads/2026/03/taller.jpg', $app->slugs()->path('media', (int) $first['id'], 'es'), 'la dirección antigua sigue valiendo');

    same(TypeWriter::UNCHANGED, $writer->write(wrMedia(), $ctx)['result']);
    same(1, wrCount($app, 'media'), 'ni un medio nuevo');
    $changed = $writer->write(wrMedia(['alt' => ['es' => 'El taller de Sevilla']]), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same($first['id'], $changed['id']);
    same('El taller de Sevilla', wrValue($app, 'SELECT alt FROM {media} WHERE id = ?', [$first['id']]));
    same(1, wrCount($app, 'media'), 'sin duplicar la imagen');
});

test('dos medios del origen con el mismo fichero comparten la imagen de la biblioteca', function (): void {
    $dir = tmpDir();
    wrPng($dir, 'u/taller.png', 6);
    $app = writersApp();
    $ctx = writersContext($app, [], [], $dir);
    $writer = new MediaWriter($app);
    $a = $writer->write(wrMedia(), $ctx);
    $b = $writer->write(wrMedia(['id' => '311', 'old_paths' => []]), $ctx);

    same(TypeWriter::CREATED, $b['result']);
    same($a['id'], $b['id'], 'la misma imagen');
    same(1, wrCount($app, 'media'));
    same(2, wrCount($app, 'import_map', "type = 'media'"));
});

test('un medio cuyo fichero no se puede traer, o con las imágenes apagadas, se descarta con su motivo', function (): void {
    $dir = tmpDir();
    $app = writersApp();
    $writer = new MediaWriter($app);
    $missing = $writer->write(wrMedia(['path' => 'u/no-esta.png']), writersContext($app, [], [], $dir));
    same(TypeWriter::SKIPPED, $missing['result']);
    contains('could not be brought', $missing['reason']);
    same(0, wrCount($app, 'media'));
    same(0, wrCount($app, 'import_map', "type = 'media'"), 'y no queda pareja');

    wrPng($dir, 'u/taller.png', 6);
    $off = $writer->write(wrMedia(), writersContext($app, ['import_images' => false], [], $dir));
    same(TypeWriter::SKIPPED, $off['result']);
    contains('switched off', $off['reason']);
});

test('simulacro de medios: se decide y no cambia ninguna tabla', function (): void {
    $dir = tmpDir();
    wrPng($dir, 'u/taller.png', 6);
    $app = writersApp();
    $dry = writersContext($app, [], [], $dir)->dry(true);
    $counts = wrCounts($app);
    $out = (new MediaWriter($app))->write(wrMedia(), $dry);

    same(TypeWriter::CREATED, $out['result']);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
});

test('una redirección se crea (sin barras en el origen), se repite sin cambios y se actualiza al cambiar', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new RedirectsWriter($app);
    $record = wr('redirects', ['id' => '5', 'source' => '/mesas-antiguas/', 'target' => '/mesas/', 'code' => 301, 'active' => true]);

    $first = $writer->write($record, $ctx);
    same(TypeWriter::CREATED, $first['result']);
    $row = $app->db()->find('redirects', ['id' => $first['id']]);
    same(['mesas-antiguas', '/mesas/', 301, 1], [$row['source'], $row['target'], (int) $row['code'], (int) $row['active']]);

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same(1, wrCount($app, 'redirects'), 'ni una redirección nueva');
    $moved = $writer->write(wr('redirects', ['id' => '5', 'source' => '/mesas-antiguas/', 'target' => '/sillas/', 'code' => 302, 'active' => true]), $ctx);
    same(TypeWriter::UPDATED, $moved['result']);
    same(['/sillas/', 302], [(string) wrValue($app, 'SELECT target FROM {redirects} WHERE id = ?', [$first['id']]), (int) wrValue($app, 'SELECT code FROM {redirects} WHERE id = ?', [$first['id']])]);
    same(1, wrCount($app, 'redirects'));
});

test('no pisa una dirección que ya responde con contenido', function (): void {
    $app = writersApp();
    $product = aifecProduct($app, ['name' => 'Mesa']);
    $path = (string) $app->slugs()->path('product', $product, 'es');
    check($path !== '', 'el producto tiene su dirección');
    $out = (new RedirectsWriter($app))->write(wr('redirects', ['id' => '6', 'source' => '/' . $path, 'target' => '/otra/', 'code' => 301]), writersContext($app));

    same(TypeWriter::SKIPPED, $out['result']);
    contains('already shows content', $out['reason']);
    same(0, wrCount($app, 'redirects'));
});

test('una redirección que apunta a sí misma o cuya cadena daría vueltas se descarta con su motivo', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new RedirectsWriter($app);
    $self = $writer->write(wr('redirects', ['id' => '1', 'source' => '/a/', 'target' => '/a', 'code' => 301]), $ctx);
    same(TypeWriter::SKIPPED, $self['result']);
    contains('same address', $self['reason']);

    same(TypeWriter::CREATED, $writer->write(wr('redirects', ['id' => '2', 'source' => '/b/', 'target' => '/c/', 'code' => 301]), $ctx)['result']);
    same(TypeWriter::CREATED, $writer->write(wr('redirects', ['id' => '3', 'source' => '/c/', 'target' => '/d/', 'code' => 301]), $ctx)['result']);
    $loop = $writer->write(wr('redirects', ['id' => '4', 'source' => '/d/', 'target' => '/b/', 'code' => 301]), $ctx);
    same(TypeWriter::SKIPPED, $loop['result']);
    contains('round in circles', $loop['reason']);
    same(2, wrCount($app, 'redirects'), 'las dos buenas siguen y la que cerraba el círculo no se escribió');
});

test('una redirección que ya estaba puesta aquí se casa si es igual, choca si va a otro sitio y overwrite_local la pisa', function (): void {
    $app = writersApp();
    $now = $app->clock()->sql();
    $app->db()->insert('redirects', ['source' => 'vieja', 'target' => '/nueva', 'code' => 301, 'hits' => 4, 'active' => 1, 'created_at' => $now]);
    $app->db()->insert('redirects', ['source' => 'otra', 'target' => '/mia', 'code' => 301, 'hits' => 0, 'active' => 1, 'created_at' => $now]);
    $ctx = writersContext($app);
    $writer = new RedirectsWriter($app);

    $same = $writer->write(wr('redirects', ['id' => '1', 'source' => '/vieja', 'target' => '/nueva', 'code' => 301]), $ctx);
    same(TypeWriter::UNCHANGED, $same['result']);
    same(1, wrCount($app, 'import_map', "type = 'redirects'"), 'queda casada');
    same(2, wrCount($app, 'redirects'));

    $other = $writer->write(wr('redirects', ['id' => '2', 'source' => '/otra', 'target' => '/del-origen', 'code' => 301]), $ctx);
    same(TypeWriter::CONFLICT, $other['result']);
    same('/mia', wrValue($app, "SELECT target FROM {redirects} WHERE source = 'otra'"), 'la de aquí queda intacta');

    $over = $writer->write(wr('redirects', ['id' => '2', 'source' => '/otra', 'target' => '/del-origen', 'code' => 301]), writersContext($app, ['overwrite_local' => true]));
    same(TypeWriter::UPDATED, $over['result']);
    same('/del-origen', wrValue($app, "SELECT target FROM {redirects} WHERE source = 'otra'"));
    same(2, wrCount($app, 'redirects'));
});

test('el destino puede ser una dirección completa y un 410 no necesita destino que comprobar', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new RedirectsWriter($app);
    $absolute = $writer->write(wr('redirects', ['id' => '1', 'source' => '/fuera', 'target' => 'https://otra.test/pagina', 'code' => 301]), $ctx);
    same('https://otra.test/pagina', wrValue($app, 'SELECT target FROM {redirects} WHERE id = ?', [$absolute['id']]));

    $gone = $writer->write(wr('redirects', ['id' => '2', 'source' => '/borrada', 'target' => '/borrada', 'code' => 410, 'active' => false]), $ctx);
    same(TypeWriter::CREATED, $gone['result']);
    same([410, 0], [(int) wrValue($app, 'SELECT code FROM {redirects} WHERE id = ?', [$gone['id']]), (int) wrValue($app, 'SELECT active FROM {redirects} WHERE id = ?', [$gone['id']])]);
});

test('simulacro de redirecciones: se decide lo mismo y no cambia ninguna tabla', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $writer = new RedirectsWriter($app);

    same(TypeWriter::CREATED, $writer->write(wr('redirects'), $dry)['result']);
    same(TypeWriter::SKIPPED, $writer->write(wr('redirects', ['id' => '9', 'source' => '/x', 'target' => '/x']), $dry)['result'], 'también descarta lo mismo');
    same($counts, wrCounts($app), 'todas las tablas, iguales');
});
