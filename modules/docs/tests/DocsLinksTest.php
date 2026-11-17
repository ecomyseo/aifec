<?php
/**
 * AIFEC — pruebas de los enlaces entre páginas de la documentación: los seis estilos de enlace relativo acaban en la dirección
 * publicada (también en una tienda en subcarpeta y con ancla), un enlace roto se queda como texto y se apunta una vez, nada se
 * sale de la carpeta, la versión en texto lleva los enlaces a «.md», y las subcarpetas de una sección (`_generado`) se publican
 * como páginas de esa sección con su rótulo, su orden de indice.json y sus tablas anchas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Docs\Library;
use Aifec\Modules\Docs\Search;

require_once __DIR__ . '/helpers.php';

if (!function_exists('docsLinksShared')) {
    /** La carpeta de prueba compartida por las pruebas que solo leen (se crea una vez por pasada). */
    function docsLinksShared(): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = aifecDocsMediaFolder();
        }

        return $dir;
    }

    /** El contenido de una página de la carpeta compartida, tal como lo ve un visitante. */
    function docsLinksProse(string $path): string
    {
        return aifecDocsProse((new TestClient(aifecDocsApp(docsLinksShared())))->get('/docs/' . $path)->body);
    }
}

// --- Los seis estilos de enlace relativo, con ancla y en una tienda en subcarpeta -----------------------------------------

foreach ([
    'el nombre del fichero en la misma carpeta' => ['[a](07-envios.md)', '<a href="/docs/tienda/envios">a</a>'],
    'con «./» delante' => ['[b](./07-envios.md)', '<a href="/docs/tienda/envios">b</a>'],
    'subiendo y bajando por la misma sección' => ['[c](../01-tienda/07-envios.md)', '<a href="/docs/tienda/envios">c</a>'],
    'a otra sección' => ['[d](../00-empezar/01-inicio.md)', '<a href="/docs/empezar/inicio">d</a>'],
    'con ancla' => ['[e](07-envios.md#plazos)', '<a href="/docs/tienda/envios#plazos">e</a>'],
    'a una subcarpeta de la referencia generada' => ['[f](../07-referencia/_generado/rutas.md)', '<a href="/docs/referencia/generado/rutas">f</a>'],
] as $docsLinksName => $docsLinksCase) {
    test('enlace relativo: ' . $docsLinksName, function () use ($docsLinksCase): void {
        contains($docsLinksCase[1], docsLinksProse('tienda/pedidos'));
    });
}

test('enlace relativo desde una subcarpeta: «_generado/rutas.md» escrito en la página de la sección', function (): void {
    contains('<a href="/docs/referencia/generado/rutas">rutas</a>', docsLinksProse('referencia/rutas'));
});

test('enlace relativo desde una página de la subcarpeta hacia la sección que la contiene', function (): void {
    $dir = aifecDocsMediaFolder();
    aifecDocsWrite($dir . '/07-referencia/_generado/indice.md', "# Índice generado\n\nVolver a [las rutas](../01-rutas.md) y a [los envíos](../../01-tienda/07-envios.md).\n");
    $prose = aifecDocsProse((new TestClient(aifecDocsApp($dir)))->get('/docs/referencia/generado/indice')->body);
    contains('<a href="/docs/referencia/rutas">las rutas</a>', $prose);
    contains('<a href="/docs/tienda/envios">los envíos</a>', $prose);
});

test('enlace relativo en una tienda en subcarpeta: la dirección lleva el prefijo del sitio y conserva el ancla', function (): void {
    $web = new TestClient(aifecDocsAppAt(docsLinksShared(), '/demos/x'));
    $prose = aifecDocsProse($web->get('/docs/tienda/pedidos')->body);
    contains('<a href="/demos/x/docs/tienda/envios">a</a>', $prose);
    contains('<a href="/demos/x/docs/tienda/envios#plazos">e</a>', $prose);
    contains('<a href="/demos/x/docs/referencia/generado/rutas">f</a>', $prose);
    notContains('href="07-', $prose, 'ningún enlace relativo queda apuntando a un fichero');
    notContains('href="../', $prose);
    notContains('href="./', $prose);
});

test('el ancla de un enlace relativo se codifica y no se puede usar para colar comillas', function (): void {
    $dir = tmpDir('aifec-docs-anchor');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\nVer [dos](dos.md#a%22b%3Cc) y [tres](dos.md#a\"b).\n");
    aifecDocsWrite($dir . '/dos.md', "# Dos\n\nTexto.\n");
    $prose = aifecDocsProse((new TestClient(aifecDocsApp($dir)))->get('/docs/uno')->body);
    contains('href="/docs/dos#a%22b%3Cc"', $prose);
    notContains('"b', $prose, 'una comilla en el ancla no cierra el atributo');
});

// --- Lo que no es una página: texto, nunca un 404 ni una salida de la carpeta ---------------------------------------------

test('un enlace a una página que no existe se queda como texto, sin href', function (): void {
    $prose = docsLinksProse('tienda/pedidos');
    contains('<a>roto</a>', $prose);
    notContains('no-existe.md"', $prose);
});

test('un enlace que sube por encima de la carpeta de documentación se queda como texto', function (): void {
    $prose = docsLinksProse('tienda/pedidos');
    contains('<a>fuera</a>', $prose);
    notContains('passwd', $prose);
    notContains('etc/', $prose);
});

test('un enlace «javascript:» no produce ningún href', function (): void {
    $prose = docsLinksProse('tienda/pedidos');
    contains('<a>js</a>', $prose);
    notContains('javascript:', $prose);
});

test('un enlace absoluto, una ancla y una ruta del propio sitio no se tocan', function (): void {
    $prose = docsLinksProse('tienda/pedidos');
    contains('<a href="https://ejemplo.test/x.md">absoluto</a>', $prose);
    contains('<a href="#devolver">ancla</a>', $prose);
    contains('<a href="/docs/otra">otra</a>', $prose);
});

test('un enlace escrito dentro de un bloque de código o de código en línea no se convierte en enlace', function (): void {
    $prose = docsLinksProse('tienda/pedidos');
    notContains('<a>en código', $prose);
    notContains('<a>inline', $prose);
    contains('[en código](no-existe.md)', $prose, 'el texto del código se enseña tal cual');
});

test('un enlace roto se apunta en el registro una vez por página, con los enlaces que no llevan a nada', function (): void {
    $web = new TestClient(aifecDocsApp(docsLinksShared()));
    $web->get('/docs/tienda/pedidos');
    $records = array_values(array_filter($web->app->log()->records, static function (array $r): bool {
        return strpos((string) $r['message'], 'docs: links that point to no page') === 0;
    }));
    same(1, count($records), 'un solo apunte para la página');
    same('tienda/pedidos', $records[0]['context']['page']);
    same(['no-existe.md', '../../../etc/passwd.md'], $records[0]['context']['links']);
    same('warning', $records[0]['level']);
});

test('pintar la misma página dos veces en el mismo proceso no repite el apunte', function (): void {
    $app = aifecDocsApp(docsLinksShared());
    /** @var Aifec\Modules\Docs\Docs $docs */
    $docs = $app->service('docs');
    $entry = $docs->library()->find('tienda/pedidos');
    check($entry !== null, 'la página existe');
    $docs->render($docs->library()->read($entry), $entry);
    $docs->render($docs->library()->read($entry), $entry);
    $count = 0;
    foreach ($app->log()->records as $record) {
        if (strpos((string) $record['message'], 'docs: links') === 0) {
            ++$count;
        }
    }
    same(1, $count);
});

test('una página sin enlaces rotos no deja ningún apunte', function (): void {
    $web = new TestClient(aifecDocsApp(docsLinksShared()));
    $web->get('/docs/tienda/envios');
    same([], array_values(array_filter($web->app->log()->records, static function (array $r): bool {
        return strpos((string) $r['message'], 'docs:') === 0;
    })));
});

test('render dice qué enlaces están rotos y qué imágenes faltan', function (): void {
    $app = aifecDocsApp(docsLinksShared());
    /** @var Aifec\Modules\Docs\Docs $docs */
    $docs = $app->service('docs');
    $entry = $docs->library()->find('tienda/pedidos');
    $result = $docs->render($docs->library()->read($entry), $entry);
    same(['no-existe.md', '../../../etc/passwd.md'], $result['broken']);
    same(['../_media/falta.png'], $result['missing']);
});

test('render sin la página de origen no toca ningún enlace (el comportamiento de antes)', function (): void {
    $app = aifecDocsApp(docsLinksShared());
    /** @var Aifec\Modules\Docs\Docs $docs */
    $docs = $app->service('docs');
    $html = $docs->html("[a](07-envios.md) ![x](../_media/falta.png)\n");
    contains('<a href="07-envios.md">a</a>', $html);
    contains('<img src="../_media/falta.png"', $html);
});

test('Library resuelve a la página, y solo a páginas que conoce', function (): void {
    $library = (new Aifec\Modules\Docs\Library(docsLinksShared()));
    $from = $library->find('tienda/pedidos');
    check($from !== null, 'la página existe');
    same('tienda/envios', $library->pageFor($from, '07-envios.md')['path']);
    same('tienda/envios', $library->pageFor($from, '07-ENVIOS.md#x')['path'], 'no distingue mayúsculas');
    same('referencia/generado/rutas', $library->pageFor($from, '../07-referencia/_generado/rutas.md')['path']);
    same(null, $library->pageFor($from, '../_media/notas.md'), '_media no publica páginas');
    same(null, $library->pageFor($from, '../../x.md'));
    same(null, $library->pageFor($from, '/etc/passwd.md'));
    same(null, $library->pageFor($from, "07-envios.md\0.png"));
    same(null, $library->pageFor($from, '..\\..\\secreto.md'));
    same(null, $library->pageFor($from, '07-envios.txt'));
    same(null, $library->pageFor($from, ''));
});

// --- La versión en texto ---------------------------------------------------------------------------------------------------

test('la versión en texto lleva los enlaces a otras páginas con su dirección .md', function (): void {
    $r = (new TestClient(aifecDocsApp(docsLinksShared())))->get('/docs/tienda/pedidos.md');
    same(200, $r->status);
    contains('[a](/docs/tienda/envios.md)', $r->body);
    contains('[b](/docs/tienda/envios.md)', $r->body);
    contains('[d](/docs/empezar/inicio.md)', $r->body);
    contains('[e](/docs/tienda/envios.md#plazos)', $r->body);
    contains('[f](/docs/referencia/generado/rutas.md)', $r->body);
});

test('la versión en texto deja como estaba lo que no es una página, y el código', function (): void {
    $r = (new TestClient(aifecDocsApp(docsLinksShared())))->get('/docs/tienda/pedidos.md');
    contains('[roto](no-existe.md)', $r->body);
    contains('[js](javascript:alert(1))', $r->body);
    contains('[absoluto](https://ejemplo.test/x.md)', $r->body);
    contains("```\n[en código](no-existe.md)\n```", $r->body);
    contains('`[inline](no-existe.md)`', $r->body);
    contains('![Falta](../_media/falta.png)', $r->body);
});

test('la versión en texto, en una tienda en subcarpeta, lleva el prefijo del sitio', function (): void {
    $r = (new TestClient(aifecDocsAppAt(docsLinksShared(), '/demos/x')))->get('/docs/tienda/pedidos.md');
    contains('[a](/demos/x/docs/tienda/envios.md)', $r->body);
});

// --- Subcarpetas de una sección -------------------------------------------------------------------------------------------

test('una subcarpeta publica sus .md como páginas de su sección, detrás de las propias', function (): void {
    $library = new Library(docsLinksShared());
    $paths = [];
    foreach ($library->pages() as $page) {
        $paths[] = $page['path'];
    }
    same(['empezar/inicio', 'tienda/pedidos', 'tienda/envios', 'referencia/rutas', 'referencia/generado/indice', 'referencia/generado/rutas'], array_slice($paths, 0, 6));
    same(6, count($paths), 'ni _media ni un segundo nivel publican nada');
});

test('la página de una subcarpeta se abre con su dirección de un nivel', function (): void {
    $web = new TestClient(aifecDocsApp(docsLinksShared()));
    $r = $web->get('/docs/referencia/generado/rutas');
    same(200, $r->status);
    contains('<h1 class="af-title">Rutas generadas</h1>', $r->body);
    contains('<a href="/docs/referencia/generado/rutas" aria-current="page">Rutas generadas</a>', $r->body);
    contains('rel="prev" href="/docs/referencia/generado/indice"', $r->body);
    contains('/docs/referencia/generado/rutas.md', $r->body, 'y su versión en texto');
    same(200, $web->get('/docs/referencia/generado/rutas.md')->status);
});

test('en el índice lateral las páginas generadas salen agrupadas bajo su rótulo', function (): void {
    $body = (new TestClient(aifecDocsApp(docsLinksShared())))->get('/docs/referencia/rutas')->body;
    contains('<p class="af-docs-subtitle">Referencia para desarrolladores</p>', $body);
    check(strpos($body, 'af-docs-subtitle') > strpos($body, '/docs/referencia/rutas"'), 'el rótulo va detrás de las páginas propias');
    notContains('data-docs-folded', $body, 'dos páginas no se pliegan');
});

test('un grupo de más de cinco páginas sale plegado, y abierto si la página actual está dentro', function (): void {
    $dir = aifecDocsMediaFolder();
    for ($i = 1; $i <= 6; ++$i) {
        aifecDocsWrite($dir . '/07-referencia/_generado/extra' . $i . '.md', '# Extra ' . $i . "\n\nTexto.\n");
    }
    $web = new TestClient(aifecDocsApp($dir));
    $closed = $web->get('/docs/tienda/envios')->body;
    contains('<details class="af-docs-sub" data-docs-folded>', $closed);
    contains('<summary>Referencia para desarrolladores <span class="af-small af-muted">(8)</span></summary>', $closed);
    $open = $web->get('/docs/referencia/generado/extra3')->body;
    contains('<details class="af-docs-sub" data-docs-folded open>', $open);
});

test('las páginas de una subcarpeta entran en el buscador', function (): void {
    $web = new TestClient(aifecDocsApp(docsLinksShared()));
    $r = $web->get('/docs/buscar?q=mandarina');
    contains('href="/docs/referencia/generado/rutas"', $r->body);
    contains('<mark>mandarina</mark>', $r->body);
    $hits = (new Search($web->app->service('docs')))->run('mandarina');
    same('referencia/generado/rutas', $hits[0]['page']['path']);
});

test('_media nunca publica páginas, ni como sección ni como subcarpeta, y dos niveles no entran', function (): void {
    $web = new TestClient(aifecDocsApp(docsLinksShared()));
    foreach (['/docs/media/notas', '/docs/_media/notas', '/docs/_media/notas.md', '/docs/referencia/generado/niveles/hondo', '/docs/referencia/niveles/hondo', '/docs/referencia/generado/hondo'] as $path) {
        same(404, $web->get($path)->status, $path);
    }
    $index = $web->get('/docs')->body;
    notContains('notas', $index);
    notContains('hondo', $index);
});

test('indice.json puede ordenar y retitular una página de una subcarpeta con slug «generado/rutas»', function (): void {
    $dir = aifecDocsMediaFolder();
    aifecDocsWrite($dir . '/indice.json', (string) json_encode(['sections' => [
        ['slug' => 'referencia', 'title' => 'Referencia', 'pages' => [
            ['slug' => 'generado/rutas', 'title' => 'Todas las rutas'],
            ['slug' => 'rutas'],
        ]],
    ]]));
    $library = new Library($dir);
    $tree = $library->tree();
    same('referencia', $tree[0]['slug'], 'lo que el índice nombra va primero');
    same(['generado/rutas', 'rutas', 'generado/indice'], array_map(static function (array $p): string {
        return $p['slug'];
    }, $tree[0]['pages']), 'lo que no nombra va detrás');
    same('Todas las rutas', $tree[0]['pages'][0]['title']);
    $web = new TestClient(aifecDocsApp($dir));
    contains('<h1 class="af-title">Todas las rutas</h1>', $web->get('/docs/referencia/generado/rutas')->body);
});

test('sin indice.json todo sigue como antes: los nombres de las carpetas y el orden de los ficheros', function (): void {
    $library = new Library(docsLinksShared());
    $titles = [];
    foreach ($library->tree() as $section) {
        $titles[] = $section['title'];
    }
    same(['Empezar', 'Tienda', 'Referencia'], $titles);
});

// --- Tablas anchas ---------------------------------------------------------------------------------------------------------

test('una tabla va dentro de un contenedor con desplazamiento horizontal, y su primera fila es fija', function (): void {
    $prose = docsLinksProse('referencia/generado/rutas');
    contains('<div class="af-docs-table"><table>', $prose);
    contains('</table></div>', $prose);
    same(substr_count($prose, '<table>'), substr_count($prose, '<div class="af-docs-table">'));
    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/docs.css');
    contains('.af-docs-table{', $css);
    contains('overflow-x:auto', $css);
    contains('position:sticky', $css);
});

test('el código «<table>» escrito como texto no se convierte en un contenedor', function (): void {
    $dir = tmpDir('aifec-docs-tabla');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\nEscribe `<table>` y </table> a mano.\n");
    $prose = aifecDocsProse((new TestClient(aifecDocsApp($dir)))->get('/docs/uno')->body);
    notContains('af-docs-table', $prose);
});

// --- La portada ------------------------------------------------------------------------------------------------------------

test('la portada enseña el buscador arriba, una línea con cuántas páginas hay y las secciones con sus páginas', function (): void {
    $body = (new TestClient(aifecDocsApp(docsLinksShared())))->get('/docs')->body;
    contains('data-docs-count>6 páginas</p>', $body);
    check(strpos($body, 'af-docs-search') < strpos($body, 'af-docs-index'), 'el buscador va antes que el listado');
    contains('Tienda', $body);
    contains('href="/docs/tienda/pedidos"', $body);
    contains('href="/docs/referencia/generado/rutas"', $body);
});

test('la portada, con una sola página, dice «1 página»', function (): void {
    $dir = tmpDir('aifec-docs-una');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\nTexto.\n");
    contains('data-docs-count>1 página</p>', (new TestClient(aifecDocsApp($dir)))->get('/docs')->body);
});

test('la portada con la carpeta vacía lo dice y no cuenta páginas', function (): void {
    $body = (new TestClient(aifecDocsApp(tmpDir('aifec-docs-vacia'))))->get('/docs')->body;
    contains('data-docs-empty', $body);
    notContains('data-docs-count', $body);
});
