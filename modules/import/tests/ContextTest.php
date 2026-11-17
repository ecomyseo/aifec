<?php
/**
 * AIFEC — lo que un escritor tiene a mano: parejas del origen, opciones, equivalencias, imágenes y direcciones antiguas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Modules\Import\Context;

require_once __DIR__ . '/helpers.php';

/**
 * Un contexto sobre una migración de mentira.
 *
 * @param array<string,mixed> $options
 * @param array<string,mixed> $extra
 */
function importContext(App $app, array $options = [], array $extra = [], string $filesDir = ''): Context
{
    $id = importDraft($app, $options, $extra);
    $run = importRun($app, $id);
    $run['source_key'] = 'origen.test';

    return new Context($app, importService($app)->runs(), $run, ['clave-de-mentira-7777'], $filesDir);
}

/** Una imagen PNG pequeña (GD) dentro de una carpeta; la semilla cambia los colores y por tanto el resumen. */
function importImage(string $dir, string $name, int $seed = 1): string
{
    $img = imagecreatetruecolor(40, 30);
    $bg = (int) imagecolorallocate($img, ($seed * 53) % 256, ($seed * 97) % 256, ($seed * 191) % 256);
    imagefilledrectangle($img, 0, 0, 40, 30, $bg);
    if (!is_dir(dirname($dir . '/' . $name))) {
        mkdir(dirname($dir . '/' . $name), 0777, true);
    }
    imagepng($img, $dir . '/' . $name);
    imagedestroy($img);

    return $dir . '/' . $name;
}

test('mapped, localId y remember: una pareja por registro, que se actualiza al repetir', function (): void {
    $app = importApp();
    $ctx = importContext($app);
    same(null, $ctx->mapped('products', '101'));
    same(null, $ctx->localId('products', '101'));
    $ctx->remember('products', '101', 55, 'abc');
    $mapped = $ctx->mapped('products', '101');
    same(55, $mapped['local_id']);
    same('abc', $mapped['source_hash']);
    same($app->clock()->sql(), $mapped['written_at']);
    same(55, $ctx->localId('products', '101'));
    same(null, $ctx->localId('categories', '101'), 'el tipo forma parte de la clave');
    $app->clock()->advance(60);
    $ctx->remember('products', '101', 55, 'def');
    same('def', $ctx->mapped('products', '101')['source_hash']);
    same($app->clock()->sql(), $ctx->mapped('products', '101')['written_at']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {import_map}'), 'se actualiza, no se duplica');
});

test('dos tiendas del mismo tipo no se mezclan: la clave del origen separa las parejas', function (): void {
    $app = importApp();
    $a = importContext($app);
    $a->remember('products', '7', 70, 'x');
    $run = importRun($app, importDraft($app));
    $run['source_key'] = 'otra-tienda.test';
    $b = new Context($app, importService($app)->runs(), $run, [], '');
    same(null, $b->mapped('products', '7'));
    $b->remember('products', '7', 99, 'y');
    same(70, $a->localId('products', '7'));
    same(99, $b->localId('products', '7'));
});

test('las opciones tienen sus valores de partida y las de la migración mandan', function (): void {
    $app = importApp();
    $ctx = importContext($app);
    same(true, $ctx->option('keep_urls'));
    same(true, $ctx->option('import_images'));
    same(false, $ctx->option('overwrite_local'));
    same('x', $ctx->option('algo_raro', 'x'));
    same(true, $ctx->option('prices_include_tax_here'), 'por defecto, el ajuste de impuestos de este sitio');
    $app->settings()->set('tax.prices_include_tax', false);
    same(false, $ctx->option('prices_include_tax_here'));

    $custom = importContext($app, ['keep_urls' => false, 'overwrite_local' => true, 'shop' => '2']);
    same(false, $custom->option('keep_urls'));
    same(true, $custom->option('overwrite_local'));
    same('2', $custom->option('shop'));
});

test('equivalent devuelve lo que eligió el comerciante, o null', function (): void {
    $app = importApp();
    $ctx = importContext($app, [], ['equivalences' => ['order_state' => ['3' => 'shipped'], 'tax_class' => ['1' => '']]]);
    same('shipped', $ctx->equivalent('order_state', '3'));
    same(null, $ctx->equivalent('order_state', '4'));
    same(null, $ctx->equivalent('tax_class', '1'), 'vacío es sin equivalencia');
    same(null, $ctx->equivalent('customer_group', '1'));
});

test('note apunta en el informe y tapa los datos de conexión y los secretos del registro', function (): void {
    $app = importApp();
    $ctx = importContext($app);
    $id = (int) importRun($app, 1)['id'];
    $ctx->guard(['clave-del-cliente-123']);
    $ctx->note('customers', '9', 'skipped', 'Rejected clave-de-mentira-7777 and clave-del-cliente-123 for ana');
    $ctx->note('products', '', 'note', 'Plain note');
    $rows = importService($app)->runs()->logs($id);
    same(2, count($rows));
    same('Rejected *** and *** for ana', $rows[0]['reason']);
    same('9', $rows[0]['source_id']);
    same(null, $rows[1]['source_id']);
    $ctx->unmapped('feature', 'Gift wrap', 2, 'No equivalent');
    $ctx->unmapped('feature', 'Gift wrap', 3);
    same([['kind' => 'feature', 'name' => 'Gift wrap', 'count' => 5, 'note' => 'No equivalent']], $ctx->drainUnmapped());
    same([], $ctx->drainUnmapped(), 'se vacía al leerlo');
});

test('en simulacro remember no escribe pero note sí', function (): void {
    $app = importApp();
    $ctx = importContext($app, [], ['dry_run' => true]);
    same(true, $ctx->dryRun());
    $ctx->remember('products', '1', 5, 'h');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_map}'));
    $ctx->note('products', '1', 'note', 'visible');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {import_log}'));
    same(null, $ctx->image(['url' => 'https://origen.test/a.png'], 'products', '1'));
    $ctx->oldPaths('page', 1, ['/vieja']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {slugs}'));
});

test('oldPaths con keep_urls: la antigua pasa a ser la dirección y las demás siguen funcionando', function (): void {
    $app = importApp();
    $app->slugs()->set('page', 5, 'es', 'nueva');
    $ctx = importContext($app);
    $ctx->oldPaths('page', 5, ['/vieja-1/', '/es/vieja-2?x=1', '/vieja-3']);
    same('vieja-1', $app->slugs()->path('page', 5, 'es'), 'la primera es la canónica');
    foreach (['vieja-1', 'vieja-2', 'vieja-3', 'nueva'] as $path) {
        $found = $app->slugs()->resolve('es', $path);
        check($found !== null && $found['type'] === 'page' && $found['entity_id'] === 5, $path . ' sigue resolviendo a la página');
    }
    same(false, $app->slugs()->resolve('es', 'vieja-2')['canonical']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'));
});

test('oldPaths sin keep_urls: redirección 301 a la nueva', function (): void {
    $app = importApp();
    $app->slugs()->set('page', 5, 'es', 'nueva');
    $ctx = importContext($app, ['keep_urls' => false]);
    $ctx->oldPaths('page', 5, ['/vieja-1/', '/es/vieja-2']);
    same('nueva', $app->slugs()->path('page', 5, 'es'), 'la dirección nueva no cambia');
    $rows = $app->db()->select('SELECT source, target, code, active FROM {redirects} ORDER BY source');
    same([
        ['source' => 'es/vieja-2', 'target' => '/nueva', 'code' => 301, 'active' => 1],
        ['source' => 'vieja-1', 'target' => '/nueva', 'code' => 301, 'active' => 1],
        ['source' => 'vieja-2', 'target' => '/nueva', 'code' => 301, 'active' => 1],
    ], array_map(static function (array $r): array {
        return ['source' => $r['source'], 'target' => $r['target'], 'code' => (int) $r['code'], 'active' => (int) $r['active']];
    }, $rows), 'con el prefijo del idioma principal se guardan las dos: con él y sin él');
    $ctx->oldPaths('page', 5, ['/vieja-1', '/es/vieja-2']);
    same(3, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'repetir no duplica');
});

test('una dirección que ya usa otra entidad no se pisa y se apunta', function (): void {
    $app = importApp();
    $app->slugs()->set('page', 5, 'es', 'nueva');
    $app->slugs()->set('product', 9, 'es', 'ocupada');
    $keep = importContext($app);
    $keep->oldPaths('page', 5, ['/ocupada']);
    same('nueva', $app->slugs()->path('page', 5, 'es'), 'la página no se quedó con la dirección del producto');
    same('product', $app->slugs()->resolve('es', 'ocupada')['type']);
    $notes = importService($app)->runs()->logs(1);
    same(1, count($notes));
    contains('already used by another page', (string) $notes[0]['reason']);

    $redirect = importContext($app, ['keep_urls' => false]);
    $redirect->oldPaths('page', 5, ['/ocupada']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'tampoco se redirige lo que otro usa');
});

test('image: una ruta dentro de la carpeta de ficheros entra en la biblioteca sin duplicarse', function (): void {
    $app = importApp();
    $dir = tmpDir('origen-files');
    $a = importImage($dir, 'img/p/1/a.png', 1);
    copy($a, $dir . '/img/p/1/copia.png');
    $ctx = importContext($app, [], [], $dir);
    $id = $ctx->image(['path' => $a, 'alt' => ['es' => 'Una foto']], 'products', '101');
    check($id !== null && $id > 0, 'entra');
    $row = $app->service('media')->get((int) $id);
    same('Una foto', $row['alt']);
    same($id, $ctx->image(['path' => $a], 'products', '101'), 'la misma imagen dos veces: el mismo identificador');
    same($id, $ctx->image(['path' => 'img/p/1/copia.png'], 'products', '102'), 'la misma imagen con otro nombre y ruta relativa: el mismo identificador');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {media}'));
});

test('image: una ruta fuera de la carpeta de ficheros se rechaza, también con «..»', function (): void {
    $app = importApp();
    $dir = tmpDir('origen-files');
    $outside = tmpDir('fuera');
    $secret = importImage($outside, 'secreta.png', 3);
    $ctx = importContext($app, [], [], $dir);
    same(null, $ctx->image(['path' => $secret], 'products', '1'));
    same(null, $ctx->image(['path' => $dir . '/../' . basename($outside) . '/secreta.png'], 'products', '1'));
    same(null, $ctx->image(['path' => '../' . basename($outside) . '/secreta.png'], 'products', '1'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {media}'), 'no entró nada');
    $notes = importService($app)->runs()->logs(1, 'products', 'note');
    same(3, count($notes));
    contains('outside the files folder', (string) $notes[0]['reason']);

    $none = importContext($app);
    same(null, $none->image(['path' => $secret], 'products', '1'), 'sin carpeta de ficheros configurada no se lee ninguna ruta');
});

test('image: por dirección, se baja una vez y la misma imagen no se duplica; un fallo se apunta y sigue', function (): void {
    $http = new FakeHttpClient();
    $app = importApp(['import', 'media', 'catalog'], [], ['http' => $http]);
    $dir = tmpDir('bytes');
    $bytes = (string) file_get_contents(importImage($dir, 'a.png', 4));
    $calls = 0;
    $http->when('GET', 'origen.test/img/a.png', static function () use ($bytes, &$calls): HttpResponse {
        ++$calls;

        return new HttpResponse(200, ['Content-Type' => 'image/png'], $bytes);
    });
    $http->when('GET', 'origen.test/img/falta.png', static function (): HttpResponse {
        return new HttpResponse(404, [], 'nope');
    });
    $ctx = importContext($app);
    $id = $ctx->image(['url' => 'https://origen.test/img/a.png'], 'products', '101');
    check($id !== null && $id > 0, 'se baja');
    same($id, $ctx->image(['url' => 'https://origen.test/img/a.png'], 'products', '102'));
    same(1, $calls, 'la segunda vez no vuelve a bajarla');
    same(null, $ctx->image(['url' => 'https://origen.test/img/falta.png'], 'products', '103'));
    $notes = importService($app)->runs()->logs(1, 'products', 'note');
    same(1, count($notes));
    contains('could not be brought', (string) $notes[0]['reason']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {media}'));
});

test('image: con la opción import_images apagada no se trae nada', function (): void {
    $app = importApp();
    $dir = tmpDir('origen-files');
    $a = importImage($dir, 'a.png', 5);
    $ctx = importContext($app, ['import_images' => false], [], $dir);
    same(null, $ctx->image(['path' => $a], 'products', '1'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {media}'));
});
