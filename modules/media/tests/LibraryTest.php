<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpResponse;
use Aifec\Media\ImageProcessor;
use Aifec\Media\MediaException;

require_once __DIR__ . '/helpers.php';

test('store guarda la imagen con sus medidas, peso, resumen y color, en AAAA/MM, y deja el .htaccess', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $path = mediaImage(800, 600, 'jpeg', 3, 'Mi Foto Ñandú');

    $row = $lib->store($path, 'Mi Foto Ñandú.JPG', ['alt' => 'Un ñandú', 'folder' => 'Animales']);

    same(800, $row['width']);
    same(600, $row['height']);
    same('image/jpeg', $row['mime']);
    same('image', $row['kind']);
    same((int) filesize($path), $row['bytes']);
    same(hash_file('sha256', $path), $row['hash']);
    check(preg_match('/^#[0-9a-f]{6}$/', (string) $row['dominant']) === 1, 'color medio #rrggbb');
    same('Un ñandú', $row['alt']);
    same('Animales', $row['folder']);
    same('mi-foto-nandu.jpg', $row['filename'], 'el nombre se limpia y la extensión es la real');
    check(preg_match('#^2026/11/mi-foto-nandu-' . substr((string) $row['hash'], 0, 8) . '\.jpg$#', $row['path']) === 1, 'ruta AAAA/MM/nombre-8 del resumen: ' . $row['path']);

    $root = mediaRoot($app);
    check(is_file($root . '/' . $row['path']), 'el original está en disco');
    check(is_file($root . '/.htaccess'), 'hay .htaccess en la raíz de medios');
    contains('<FilesMatch "\.(php\d?|phtml|phar|pl|py|cgi|sh)$">', (string) file_get_contents($root . '/.htaccess'));
    contains('Require all denied', (string) file_get_contents($root . '/.htaccess'));
    contains('</FilesMatch>', (string) file_get_contents($root . '/.htaccess'));
    contains('Options -ExecCGI -Indexes', (string) file_get_contents($root . '/.htaccess'));
    check(is_file($root . '/index.html'), 'hay index.html');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {media}'));
});

test('las variantes caben en su caja sin deformar: 2000 × 1000 da card 480 × 240', function (): void {
    $app = mediaApp();
    $row = mediaLib($app)->store(mediaImage(2000, 1000, 'jpeg', 4), 'ancha.jpg');

    same(['thumb', 'card', 'large'], array_keys($row['variants']));
    same(['width' => 160, 'height' => 80], ['width' => $row['variants']['thumb']['width'], 'height' => $row['variants']['thumb']['height']]);
    same(['width' => 480, 'height' => 240], ['width' => $row['variants']['card']['width'], 'height' => $row['variants']['card']['height']]);
    same(['width' => 1200, 'height' => 600], ['width' => $row['variants']['large']['width'], 'height' => $row['variants']['large']['height']]);

    $expected = ImageProcessor::supports('webp') ? 'webp' : 'jpg';
    foreach ($row['variants'] as $variant) {
        check(is_file(mediaRoot($app) . '/' . $variant['path']), 'existe ' . $variant['path']);
        same($expected, pathinfo($variant['path'], PATHINFO_EXTENSION));
        $info = ImageProcessor::probe(mediaRoot($app) . '/' . $variant['path']);
        same($variant['width'], $info->width, 'las medidas guardadas son las del fichero');
    }
});

test('una imagen pequeña no se amplía en ninguna variante', function (): void {
    $app = mediaApp();
    $row = mediaLib($app)->store(mediaImage(100, 100, 'png', 5), 'pequena.png');

    check($row['variants'] !== [], 'también tiene variantes');
    foreach ($row['variants'] as $name => $variant) {
        check($variant['width'] <= 100 && $variant['height'] <= 100, $name . ' no pasa de 100 × 100');
    }
    same(100, $row['variants']['large']['width']);
    $img = mediaLib($app)->image($row['id']);
    check(strpos($img['large'], 'pequena-') !== false, 'la dirección de large existe');
});

test('el mismo fichero dos veces es una sola fila y no escribe nada más', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $path = mediaImage(300, 200, 'jpeg', 6);

    $first = $lib->store($path, 'a.jpg');
    $files = mediaScan(mediaRoot($app));
    $second = $lib->store($path, 'otro-nombre.jpg', ['alt' => 'Texto nuevo']);

    same($first['id'], $second['id']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {media}'));
    same($files, mediaScan(mediaRoot($app)), 'no se escribió ningún fichero más');
    same('Texto nuevo', $second['alt'], 'el texto alternativo se aprovecha si la fila no tenía');
    $third = $lib->store($path, 'c.jpg', ['alt' => 'Otro']);
    same('Texto nuevo', $third['alt'], 'y no se pisa el que ya había');
});

test('un PHP con nombre .jpg se rechaza y no queda nada en disco ni en la base', function (): void {
    $app = mediaApp();
    $evil = mediaFile("<?php echo 'hola';", 'evil.jpg');

    throws(function () use ($app, $evil): void {
        mediaLib($app)->store($evil, 'evil.jpg');
    }, MediaException::class);
    same([], mediaScan(mediaRoot($app)));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {media}'));

    $html = mediaFile('<html><script>alert(1)</script></html>', 'pagina.png');
    throws(function () use ($app, $html): void {
        mediaLib($app)->store($html, 'pagina.png');
    }, MediaException::class);
    $empty = mediaFile('', 'vacio.jpg');
    throws(function () use ($app, $empty): void {
        mediaLib($app)->store($empty, 'vacio.jpg');
    }, MediaException::class);
    throws(function () use ($app): void {
        mediaLib($app)->store('/no/existe.jpg', 'x.jpg');
    }, MediaException::class);
    same([], mediaScan(mediaRoot($app)));
});

test('un fichero que pasa de media.max_mb o de 50 megapíxeles se rechaza con su mensaje', function (): void {
    $app = mediaApp(['media'], ['media.max_mb' => 1]);
    $big = mediaImage(1200, 900, 'jpeg', 7);
    file_put_contents($big, str_repeat("\0", 1100000), FILE_APPEND);
    try {
        mediaLib($app)->store($big, 'grande.jpg');
        check(false, 'tenía que lanzar');
    } catch (MediaException $e) {
        same('The file is too large', $e->getMessage());
    }
    same([], mediaScan(mediaRoot($app)));

    // PNG de 8000 × 8000 (64 Mpx): solo la cabecera, sin decodificar nada.
    $ihdr = pack('NNCCCCC', 8000, 8000, 8, 2, 0, 0, 0);
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $png = mediaFile("\x89PNG\r\n\x1A\n" . $chunk('IHDR', $ihdr) . $chunk('IEND', ''), 'enorme.png');
    $app2 = mediaApp();
    try {
        mediaLib($app2)->store($png, 'enorme.png');
        check(false, 'tenía que lanzar');
    } catch (MediaException $e) {
        same('The image is too large in pixels', $e->getMessage());
    }
    same([], mediaScan(mediaRoot($app2)));
});

test('un SVG con <script> se guarda sin el script y sin variantes', function (): void {
    $app = mediaApp();
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" onload="alert(1)"><script>alert(1)</script><rect width="120" height="60" fill="#c00"/></svg>';
    $row = mediaLib($app)->store(mediaFile($svg, 'logo.svg'), 'logo.svg');

    same('image/svg+xml', $row['mime']);
    $stored = (string) file_get_contents(mediaRoot($app) . '/' . $row['path']);
    notContains('<script', $stored);
    notContains('onload', $stored);
    contains('<rect', $stored);
    same([], $row['variants']);
    same(120, $row['width']);
    same(null, $row['dominant']);
    $img = mediaLib($app)->image($row['id']);
    same($img['url'], $img['thumb']);
    same($img['url'], $img['large']);
    same('', $img['srcset']);

    $onlyScript = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
    throws(function () use ($app, $onlyScript): void {
        mediaLib($app)->store(mediaFile($onlyScript, 'vacio.svg'), 'vacio.svg');
    }, MediaException::class, 'un SVG que se queda vacío se rechaza');
    throws(function () use ($app): void {
        mediaLib($app)->store(mediaFile('<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>', 'roto.svg'), 'roto.svg');
    }, MediaException::class, 'un SVG mal formado se rechaza');
});

test('image() trae todas las claves y image_tag() pinta width, height, loading y srcset', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $row = $lib->store(mediaImage(2000, 1000, 'jpeg', 8), 'ancha.jpg', ['alt' => 'Paisaje & mar']);

    $img = $lib->image($row['id']);
    foreach (['id', 'url', 'alt', 'width', 'height', 'thumb', 'card', 'large', 'srcset', 'dominant'] as $key) {
        check(array_key_exists($key, $img), 'falta la clave ' . $key);
    }
    same($row['id'], $img['id']);
    same('Paisaje & mar', $img['alt']);
    same(2000, $img['width']);
    contains($row['path'], $img['url']);
    check($img['card'] !== $img['url'], 'card es una variante');
    contains('480w', $img['srcset']);
    contains('1200w', $img['srcset']);
    check(strpos($img['srcset'], '160w') < strpos($img['srcset'], '480w'), 'srcset de menor a mayor');

    $html = image_tag($img, 'card');
    contains('<img', $html);
    contains('width="2000"', $html);
    contains('height="1000"', $html);
    contains('loading="lazy"', $html);
    contains('srcset="', $html);
    contains('alt="Paisaje &amp; mar"', $html);
    contains($img['card'], $html);

    same('Otro texto', $lib->image($row['id'], 'Otro texto')['alt'], 'el texto pedido manda sobre el guardado');
    same('Paisaje & mar', $lib->image($row['id'], '')['alt'], 'vacío = el de la biblioteca');
    same(null, $lib->image(9999));
    same('', $lib->url(9999));
    same($img['url'], $lib->url($row['id']));
    same($img['thumb'], $lib->url($row['id'], 'thumb'));
});

test('images() trae varias con UNA consulta y omite las que no existen', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $a = $lib->store(mediaImage(300, 200, 'jpeg', 10), 'a.jpg')['id'];
    $b = $lib->store(mediaImage(300, 200, 'jpeg', 11), 'b.jpg')['id'];
    $c = $lib->store(mediaImage(300, 200, 'jpeg', 12), 'c.jpg')['id'];

    $before = $app->db()->queries;
    $out = $lib->images([$c, $a, 9999, $b, $a]);
    same(1, $app->db()->queries - $before, 'una sola consulta');
    same([$c, $a, $b], array_keys($out), 'en el orden pedido, sin repetir ni inexistentes');
    same($c, $out[$c]['id']);
    same([], $lib->images([]));
    same([], $lib->images([0, -3]));
});

test('delete() borra el original y todas las variantes; en uso no borra salvo que se fuerce', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $row = $lib->store(mediaImage(1600, 800, 'jpeg', 13), 'borrar.jpg');
    $root = mediaRoot($app);
    $files = [$row['path']];
    foreach ($row['variants'] as $variant) {
        $files[] = $variant['path'];
    }
    foreach ($files as $file) {
        check(is_file($root . '/' . $file), 'existe ' . $file);
    }

    $app->hooks()->filter('media.usage', static function ($uses, int $id) use ($row) {
        if ($id === $row['id']) {
            $uses[] = 'Producto: Silla';
        }

        return $uses;
    });
    same(['Producto: Silla'], $lib->usage($row['id']));
    same(false, $lib->delete($row['id']), 'en uso: no se borra');
    check($lib->get($row['id']) !== null, 'la fila sigue');
    foreach ($files as $file) {
        check(is_file($root . '/' . $file), 'sigue ' . $file);
    }

    same(true, $lib->delete($row['id'], true));
    same(null, $lib->get($row['id']));
    foreach ($files as $file) {
        check(!is_file($root . '/' . $file), 'se borró ' . $file);
    }
    same(false, $lib->delete($row['id']), 'una que no existe devuelve false');

    $free = $lib->store(mediaImage(300, 300, 'jpeg', 14), 'libre.jpg');
    same(true, $lib->delete($free['id']), 'sin uso se borra sin forzar');
});

test('importUrl() baja con el cliente HTTP: bien, y falla con lo que no es imagen, con esquemas raros o direcciones internas', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $http = $app->http();
    $bytes = (string) file_get_contents(mediaImage(640, 480, 'jpeg', 15));
    $http->queue(new HttpResponse(200, ['content-type' => 'image/jpeg'], $bytes));

    $row = $lib->importUrl('https://cdn.ejemplo.test/fotos/silla%20roja.jpg?v=2', ['alt' => 'Silla roja']);
    same(640, $row['width']);
    same('silla-roja.jpg', $row['filename']);
    same('Silla roja', $row['alt']);
    same('https://cdn.ejemplo.test/fotos/silla%20roja.jpg?v=2', $http->last()->url);

    $http->queue(new HttpResponse(200, ['content-type' => 'text/html'], '<html><body>No soy una imagen</body></html>'));
    throws(function () use ($lib): void {
        $lib->importUrl('https://cdn.ejemplo.test/falsa.jpg');
    }, MediaException::class);
    $http->queue(new HttpResponse(404, [], 'no hay'));
    throws(function () use ($lib): void {
        $lib->importUrl('https://cdn.ejemplo.test/404.jpg');
    }, MediaException::class);

    $sent = count($http->requests);
    foreach (['ftp://ejemplo.test/a.jpg', 'file:///etc/passwd', 'javascript:alert(1)', 'http://localhost/a.jpg', 'http://127.0.0.1/a.jpg', 'http://192.168.1.5/a.jpg', 'http://[::1]/a.jpg', ''] as $bad) {
        throws(function () use ($lib, $bad): void {
            $lib->importUrl($bad);
        }, MediaException::class, $bad);
    }
    same($sent, count($http->requests), 'ninguna de esas llegó a salir');
    same([], array_values(array_filter(mediaScan($app->site()->path('tmp')), static function (string $f): bool {
        return strpos($f, 'media-') === 0;
    })), 'no quedan temporales');
});

test('update() cambia texto alternativo, título y carpeta; la búsqueda, el filtro por carpeta y el conteo los usan', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $a = $lib->store(mediaImage(300, 200, 'jpeg', 16), 'sofa-azul.jpg')['id'];
    $b = $lib->store(mediaImage(300, 200, 'jpeg', 17), 'mesa.jpg', ['folder' => 'Mesas'])['id'];

    $lib->update($a, ['alt' => '  Sofá "azul" & cómodo ', 'title' => 'Sofá', 'folder' => '/Salón/']);
    $row = $lib->get($a);
    same('Sofá "azul" & cómodo', $row['alt']);
    same('Sofá', $row['title']);
    same('Salón', $row['folder']);

    $lib->update($a, ['title' => '']);
    same('', $lib->get($a)['title'], 'vacío lo deja sin título');
    same('Sofá "azul" & cómodo', $lib->get($a)['alt'], 'lo que no viene no se toca');

    same(2, $lib->count());
    same(1, $lib->count('sofa'));
    same(1, $lib->count('cómodo'));
    same(0, $lib->count('%'), 'el % de la búsqueda no es un comodín');
    same(0, $lib->count('_'), 'ni el _');
    same(1, $lib->count('', 'Mesas'));
    same([$b], array_map(static function (array $r): int {
        return $r['id'];
    }, $lib->search('', 'Mesas')));
    same(['Mesas' => 1, 'Salón' => 1], $lib->folders());
    same([$b, $a], array_map(static function (array $r): int {
        return $r['id'];
    }, $lib->search()), 'las nuevas primero');
    same([$a], array_map(static function (array $r): int {
        return $r['id'];
    }, $lib->search('', '', 1, 1)), 'límite y desplazamiento');
});

test('regenerate() rehace las variantes que faltan y quita las que ya no valen', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $row = $lib->store(mediaImage(2000, 1000, 'jpeg', 18), 'rehacer.jpg');
    $root = mediaRoot($app);
    unlink($root . '/' . $row['variants']['card']['path']);
    unlink($root . '/' . $row['variants']['large']['path']);
    $stray = dirname($row['path']) . '/' . pathinfo($row['path'], PATHINFO_FILENAME) . '-viejo.webp';
    $row['variants']['viejo'] = ['path' => $stray, 'width' => 10, 'height' => 5];
    file_put_contents($root . '/' . $stray, 'x');
    $app->db()->update('media', ['variants' => json_encode($row['variants'])], ['id' => $row['id']]);

    $lib->regenerate($row['id']);

    $after = $lib->get($row['id']);
    same(['thumb', 'card', 'large'], array_keys($after['variants']));
    foreach ($after['variants'] as $variant) {
        check(is_file($root . '/' . $variant['path']), 'existe ' . $variant['path']);
    }
    check(!is_file($root . '/' . $stray), 'la variante que ya no existe se quitó');
    check(is_file($root . '/' . $row['path']), 'el original no se toca');

    throws(function () use ($lib): void {
        $lib->regenerate(9999);
    }, MediaException::class);
    unlink($root . '/' . $row['path']);
    throws(function () use ($lib, $row): void {
        $lib->regenerate($row['id']);
    }, MediaException::class, 'sin original no hay qué rehacer');
});

test('el trabajo media.regenerate rehace las variantes desde la cola y falla a la primera si la imagen no existe', function (): void {
    $app = mediaApp(['jobs', 'media']);
    $lib = mediaLib($app);
    $row = $lib->store(mediaImage(1500, 900, 'jpeg', 19), 'cola.jpg');
    unlink(mediaRoot($app) . '/' . $row['variants']['card']['path']);

    /** @var \Aifec\Modules\Jobs\Queue $queue */
    $queue = $app->service('jobs');
    $ok = $queue->push('media.regenerate', ['id' => $row['id']]);
    $bad = $queue->push('media.regenerate', ['id' => 9999]);
    $work = $queue->work(5);

    same(1, $work['done']);
    same(1, $work['failed']);
    same('done', (string) $app->db()->value('SELECT status FROM {jobs} WHERE id = ?', [$ok]));
    same('failed', (string) $app->db()->value('SELECT status FROM {jobs} WHERE id = ?', [$bad]));
    same(1, (int) $app->db()->value('SELECT attempts FROM {jobs} WHERE id = ?', [$bad]), 'sin reintentos: repetirlo no lo arregla');
    check(is_file(mediaRoot($app) . '/' . $lib->get($row['id'])['variants']['card']['path']), 'la variante vuelve a existir');
});

test('todos los mensajes de error del módulo tienen traducción al castellano', function (): void {
    $lang = require dirname(__DIR__) . '/lang/es.php';
    $found = 0;
    foreach (glob(dirname(__DIR__) . '/src/*.php') ?: [] as $file) {
        if (preg_match_all("/new MediaException\\('([^']+)'\\)/", (string) file_get_contents($file), $m) > 0) {
            foreach ($m[1] as $message) {
                ++$found;
                check(isset($lang[$message]) && $lang[$message] !== '', 'sin traducción: «' . $message . '» (' . basename($file) . ')');
            }
        }
    }
    check($found >= 15, 'se encontraron los mensajes (' . $found . ')');
});
