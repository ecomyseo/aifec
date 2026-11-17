<?php
/**
 * AIFEC — pruebas de las imágenes de la documentación: la ruta /docs/_media/{fichero} sirve solo imágenes de verdad de esa
 * carpeta (nada de `.json`, `.php`, `../`, textos con nombre de imagen ni enlaces simbólicos hacia fuera), con su tipo, su caché
 * y su validación por fecha; y en la página cada captura sale con su tamaño y carga diferida, y la que falta no se pinta (solo
 * deja una nota a quien tiene abierto el panel).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Docs\Library;

require_once __DIR__ . '/helpers.php';

if (!function_exists('docsMediaDir')) {
    /** La carpeta de prueba compartida por las pruebas que solo leen. */
    function docsMediaDir(): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = aifecDocsMediaFolder();
            aifecDocsPng($dir . '/fuera.png', 3, 3);
        }

        return $dir;
    }

    function docsMediaWeb(): TestClient
    {
        return new TestClient(aifecDocsApp(docsMediaDir()));
    }
}

// --- La ruta ---------------------------------------------------------------------------------------------------------------

test('la ruta sirve un PNG de verdad con su tipo y sus cabeceras de caché', function (): void {
    $web = docsMediaWeb();
    $r = $web->get('/docs/_media/pedidos-listado.png');
    same(200, $r->status);
    same('image/png', $r->headers['Content-Type']);
    same(docsMediaDir() . '/_media/pedidos-listado.png', $r->file, 'se envía el fichero de la carpeta');
    same('nosniff', $r->headers['X-Content-Type-Options']);
    contains('public', $r->headers['Cache-Control']);
    contains('max-age=604800', $r->headers['Cache-Control'], 'caché larga');
    check(isset($r->headers['ETag']) && $r->headers['ETag'] !== '', 'lleva ETag');
    contains(' GMT', $r->headers['Last-Modified']);
    same(0, $r->cacheTtl, 'un fichero no entra en la caché de página');
    same('inline', substr($r->headers['Content-Disposition'], 0, 6));
});

test('la ruta valida por fecha y por ETag: la misma versión da un 304 sin cuerpo', function (): void {
    $web = docsMediaWeb();
    $first = $web->get('/docs/_media/pedidos-listado.png');
    $byTag = $web->get('/docs/_media/pedidos-listado.png', ['if-none-match' => $first->headers['ETag']]);
    same(304, $byTag->status);
    same('', $byTag->body);
    same(null, $byTag->file);
    $byDate = $web->get('/docs/_media/pedidos-listado.png', ['if-modified-since' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT']);
    same(304, $byDate->status);
    $stale = $web->get('/docs/_media/pedidos-listado.png', ['if-modified-since' => gmdate('D, d M Y H:i:s', 86400) . ' GMT']);
    same(200, $stale->status, 'una copia más vieja que el fichero se vuelve a enviar');
    $other = $web->get('/docs/_media/pedidos-listado.png', ['if-none-match' => '"otra-cosa"']);
    same(200, $other->status, 'un ETag que no es el de la imagen no vale');
});

foreach ([
    'un .json, aunque lleve bytes de PNG' => '/docs/_media/datos.json',
    'un .php, aunque lleve bytes de PNG' => '/docs/_media/script.php',
    'un texto renombrado a .png' => '/docs/_media/falso.png',
    'un SVG, que puede llevar guiones' => '/docs/_media/logo.svg',
    'un .md' => '/docs/_media/notas.md',
    'un fichero que no existe' => '/docs/_media/no-existe.png',
    '«..» con barra' => '/docs/_media/../indice.json',
    '«..%2f» codificado' => '/docs/_media/..%2fsecreto.png',
    '«%2e%2e%2f» codificado' => '/docs/_media/%2e%2e%2ffuera.png',
    '«..» con barra invertida' => '/docs/_media/..%5cfuera.png',
    'un fichero de la carpeta de arriba' => '/docs/_media/../fuera.png',
    'una subcarpeta' => '/docs/_media/sub/otra.png',
    'el nombre en mayúsculas' => '/docs/_media/PEDIDOS-LISTADO.PNG',
    'un nombre que empieza por punto' => '/docs/_media/.png',
    'la carpeta sin fichero' => '/docs/_media/',
] as $docsMediaName => $docsMediaPath) {
    test('la ruta de imágenes da 404 para ' . $docsMediaName, function () use ($docsMediaPath): void {
        $r = docsMediaWeb()->get($docsMediaPath);
        same(404, $r->status, $docsMediaPath);
        same(null, $r->file, 'no se envía ningún fichero');
    });
}

test('un fichero con la extensión de imagen que no sea de la lista (bmp, tiff, ico) no se sirve aunque sea una imagen', function (): void {
    $dir = tmpDir('aifec-docs-ext');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\nTexto.\n");
    aifecDocsPng($dir . '/_media/ok.png');
    $bmp = base64_decode('Qk06AAAAAAAAADYAAAAoAAAAAQAAAAEAAAABABgAAAAAAAQAAAATCwAAEwsAAAAAAAAAAAAA////AA==', true);
    aifecDocsWrite($dir . '/_media/foto.bmp', (string) $bmp);
    $web = new TestClient(aifecDocsApp($dir));
    same(200, $web->get('/docs/_media/ok.png')->status);
    same(404, $web->get('/docs/_media/foto.bmp')->status);
});

test('Library::media comprueba el nombre, la extensión, el sitio y el contenido', function (): void {
    $library = new Library(docsMediaDir());
    $png = $library->media('pedidos-listado.png');
    check($png !== null, 'una imagen de verdad se publica');
    same('image/png', $png['mime']);
    same(7, $png['width']);
    same(5, $png['height']);
    same(docsMediaDir() . '/_media/pedidos-listado.png', $png['file']);
    foreach (['../fuera.png', '..\\fuera.png', 'sub/otra.png', '/fuera.png', '', '.png', 'PEDIDOS.PNG', 'datos.json', 'script.php', 'falso.png', 'logo.svg', 'no-existe.png', "otra.png\0.json"] as $bad) {
        same(null, $library->media($bad), 'no se publica «' . addcslashes($bad, "\0") . '»');
    }
});

test('un fichero de la carpeta de arriba (fuera de _media) no sale ni por Library::media', function (): void {
    $library = new Library(docsMediaDir());
    check(is_file(docsMediaDir() . '/fuera.png'), 'el PNG de verdad está en docs/, no en docs/_media');
    same(null, $library->media('../fuera.png'));
    same(null, $library->media('fuera.png'), 'tampoco por su nombre solo: no está en _media');
});

test('una imagen enlazada desde fuera de docs/ por un enlace simbólico no se sirve', function (): void {
    $dir = aifecDocsMediaFolder();
    $outside = dirname($dir) . '/externa.png';
    aifecDocsPng($outside, 5, 5);
    $made = @symlink($outside, $dir . '/_media/enlace.png');
    if (!$made) {
        // Sin permiso para crear enlaces simbólicos (Windows sin modo de desarrollador): se comprueba lo que se puede.
        same(null, (new Library($dir))->media('enlace.png'), 'sin enlace, no hay imagen');

        return;
    }
    same(404, (new TestClient(aifecDocsApp($dir)))->get('/docs/_media/enlace.png')->status);
    same(null, (new Library($dir))->media('enlace.png'));
});

test('una carpeta _media que es un enlace simbólico hacia fuera no se sirve', function (): void {
    $dir = tmpDir('aifec-docs-media-link') . '/docs';
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\nTexto.\n");
    $outside = dirname($dir) . '/fuera';
    aifecDocsPng($outside . '/secreta.png');
    if (!@symlink($outside, $dir . '/_media')) {
        same(null, (new Library($dir))->media('secreta.png'), 'sin enlace, no hay imagen');

        return;
    }
    same(404, (new TestClient(aifecDocsApp($dir)))->get('/docs/_media/secreta.png')->status);
    same(null, (new Library($dir))->media('secreta.png'));
});

test('sin carpeta _media la ruta da 404 y la página sigue funcionando', function (): void {
    $dir = tmpDir('aifec-docs-sin-media');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\n![x](_media/a.png)\n\nTexto.\n");
    $web = new TestClient(aifecDocsApp($dir));
    same(404, $web->get('/docs/_media/a.png')->status);
    same(200, $web->get('/docs/uno')->status);
});

test('la ruta de imágenes no come las páginas: una página llamada «media» sigue abriéndose', function (): void {
    $dir = tmpDir('aifec-docs-pagina-media');
    aifecDocsWrite($dir . '/media.md', "# Medios\n\nTexto de medios.\n");
    aifecDocsPng($dir . '/_media/a.png');
    $web = new TestClient(aifecDocsApp($dir));
    same(200, $web->get('/docs/media')->status);
    same(200, $web->get('/docs/_media/a.png')->status);
});

// --- La imagen dentro de la página -----------------------------------------------------------------------------------------

test('la imagen de una página sale con su dirección publicada, su tamaño, carga diferida y el texto alternativo escapado', function (): void {
    $prose = aifecDocsProse(docsMediaWeb()->get('/docs/tienda/pedidos')->body);
    contains('<img src="/docs/_media/pedidos-listado.png" alt="Listado de &quot;pedidos&quot; &amp; más" width="7" height="5" loading="lazy">', $prose);
    notContains('../_media', $prose, 'la ruta relativa del fichero no sale a la web');
});

test('la imagen, en una tienda en subcarpeta, lleva el prefijo del sitio y el contenido se sirve desde ahí', function (): void {
    $web = new TestClient(aifecDocsAppAt(docsMediaDir(), '/demos/x'));
    contains('src="/demos/x/docs/_media/pedidos-listado.png"', aifecDocsProse($web->get('/docs/tienda/pedidos')->body));
    same(200, $web->get('/docs/_media/pedidos-listado.png')->status);
});

test('una imagen que falta no se pinta: el visitante no ve nada roto ni su nombre', function (): void {
    $prose = aifecDocsProse(docsMediaWeb()->get('/docs/tienda/pedidos')->body);
    notContains('falta.png', $prose);
    notContains('captura pendiente', $prose);
    notContains('<p></p>', $prose, 'el párrafo que solo llevaba la imagen desaparece');
    check(substr_count($prose, '<img') === 1, 'solo la imagen que existe');
});

test('con el panel abierto, en su sitio queda una nota discreta con el nombre de lo que falta', function (): void {
    $app = aifecDocsApp(docsMediaDir());
    $web = adminClient($app);
    $prose = aifecDocsProse($web->get('/docs/tienda/pedidos')->body);
    contains('<span class="af-docs-pending">(captura pendiente: falta.png)</span>', $prose);
    check(substr_count($prose, '<img') === 1, 'la imagen que existe sigue pintándose');
});

test('la nota del panel no se queda en la caché de página: el visitante de después no la ve, ni al revés', function (): void {
    $app = aifecDocsApp(docsMediaDir());
    $guest = new TestClient($app);
    notContains('captura pendiente', aifecDocsProse($guest->get('/docs/tienda/pedidos')->body));
    $admin = adminClient($app);
    contains('captura pendiente: falta.png', aifecDocsProse($admin->get('/docs/tienda/pedidos')->body), 'con la copia del visitante guardada, el panel ve la nota');
    notContains('captura pendiente', aifecDocsProse((new TestClient($app))->get('/docs/tienda/pedidos')->body), 'y el visitante sigue sin verla');
});

test('una cookie del panel inventada, sin sesión detrás, no enseña la nota', function (): void {
    $app = aifecDocsApp(docsMediaDir());
    $web = new TestClient($app);
    $web->cookies['af_a_' . $app->site()->cookieSuffix()] = 'inventada';
    notContains('captura pendiente', aifecDocsProse($web->get('/docs/tienda/pedidos')->body));
});

test('una imagen que no es de _media (otra carpeta, otro sitio) no se pinta ni se enlaza', function (): void {
    $dir = tmpDir('aifec-docs-img');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\nTexto.\n\n![a](otra/foto.png)\n\n![b](../../fuera.png)\n\n![c](https://ejemplo.test/x.png)\n");
    aifecDocsPng($dir . '/otra/foto.png');
    $prose = aifecDocsProse((new TestClient(aifecDocsApp($dir)))->get('/docs/uno')->body);
    notContains('otra/foto.png', $prose);
    notContains('fuera.png', $prose);
    contains('<img src="https://ejemplo.test/x.png" alt="c">', $prose, 'una imagen absoluta se deja como está');
});

test('un enlace a una imagen de _media apunta a su dirección publicada, y a una que falta queda como texto', function (): void {
    $dir = tmpDir('aifec-docs-enlace-img');
    aifecDocsWrite($dir . '/01-a/uno.md', "# Uno\n\nVer [la captura](../_media/a.png) y [otra](../_media/falta.png).\n");
    aifecDocsPng($dir . '/_media/a.png');
    $prose = aifecDocsProse((new TestClient(aifecDocsApp($dir)))->get('/docs/a/uno')->body);
    contains('<a href="/docs/_media/a.png">la captura</a>', $prose);
    contains('<a>otra</a>', $prose);
});

test('la descripción de la página salta el párrafo que solo lleva una imagen', function (): void {
    $dir = tmpDir('aifec-docs-desc');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\n![x](_media/a.png)\n\nLa primera frase de verdad.\n");
    aifecDocsPng($dir . '/_media/a.png');
    contains('<meta name="description" content="La primera frase de verdad.">', (new TestClient(aifecDocsApp($dir)))->get('/docs/uno')->body);
});

test('la hoja de estilos limita las imágenes al ancho de la página', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/docs.css');
    contains('.af-docs-prose img{', $css);
    contains('max-width:100%', $css);
    contains('height:auto', $css);
});
