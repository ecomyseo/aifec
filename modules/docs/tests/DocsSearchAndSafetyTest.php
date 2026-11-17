<?php
/**
 * AIFEC — pruebas del buscador de la documentación, de lo que no se puede pedir (`../`, un `.php`, algo fuera de docs/), de la
 * carpeta vacía y del orden y los títulos que da indice.json.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Request;
use Aifec\Modules\Docs\Library;
use Aifec\Modules\Docs\Search;

require_once __DIR__ . '/helpers.php';

test('el buscador encuentra por palabra, sin mirar mayúsculas, y enseña el fragmento con la palabra marcada', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    $r = $web->get('/docs/buscar?q=ZANAHORIA');
    same(200, $r->status);
    contains('href="/docs/guia/configurar"', $r->body);
    contains('<mark>zanahoria</mark>', $r->body);
    contains('class="af-docs-snippet"', $r->body);
    contains('1 resultado', $r->body);
    notContains('href="/docs/guia/empezar"', substr($r->body, (int) strpos($r->body, 'af-docs-results')), 'solo sale lo que la contiene');
});

test('el buscador entiende eñes y tildes, y exige todas las palabras', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    contains('<mark>Ñandú</mark>', $web->get('/docs/buscar?q=' . rawurlencode('ñandú'))->body);
    contains('href="/docs/guia/configurar"', $web->get('/docs/buscar?q=zanahoria+ajustes')->body);
    contains('data-docs-no-results', $web->get('/docs/buscar?q=zanahoria+cebolla')->body, 'si falta una palabra, no sale');
});

test('el título pesa más que el texto: la página que se llama como lo buscado sale la primera', function (): void {
    $app = aifecDocsApp(aifecDocsFolder());
    $hits = (new Search($app->service('docs')))->run('rutas');
    same('api/rutas', $hits[0]['page']['path']);
    same([], (new Search($app->service('docs')))->run('   '), 'una consulta vacía no busca');
    same(['ñandú', 'cía'], Search::words('Ñandú, & CÍA!'), 'palabras en minúsculas y sin signos');
});

test('un texto con «&», «<» y comillas se escapa en el fragmento y en el cuadro de búsqueda', function (): void {
    $app = aifecDocsApp(aifecDocsFolder());
    $web = new TestClient($app);
    $body = $web->get('/docs/buscar?q=' . rawurlencode('<b>"&'))->body;
    notContains('<b>"&', $body);
    contains('value="&lt;b&gt;&quot;&amp;"', $body);
    same('<mark>a</mark> &amp; b &lt;i&gt;', Search::highlight('a & b <i>', ['a']));
});

test('el fragmento del buscador es texto: las etiquetas de una tabla (<br>) no salen a la vista', function (): void {
    $dir = aifecDocsFolder();
    aifecDocsWrite($dir . '/tabla.md', "# Tabla\n\n| Qué | Detalle |\n|---|---|\n| licencia | AFL-3.0<br>Ver el fichero<br/>LICENSE y <b>más</b> |\n");
    $web = new TestClient(aifecDocsApp($dir));
    $body = $web->get('/docs/buscar?q=licencia')->body;
    $snippet = substr($body, (int) strpos($body, 'af-docs-snippet'));
    contains('<mark>licencia</mark>', $snippet);
    notContains('&lt;br', $snippet, 'ninguna etiqueta escapada a la vista');
    notContains('&lt;b&gt;', $snippet);
    contains('AFL-3.0 Ver el fichero LICENSE y más', $snippet);
});

test('sin consulta el buscador lo pide, y sin resultados lo dice', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    $none = $web->get('/docs/buscar');
    same(200, $none->status);
    contains('Escribe una o más palabras', $none->body);
    contains('No se ha encontrado nada.', $web->get('/docs/buscar?q=inexistentexyz')->body);
});

test('«../», un .php, un .txt y un fichero de fuera de docs/ dan 404', function (): void {
    $dir = aifecDocsFolder();
    $web = new TestClient(aifecDocsApp($dir, ['docs', 'semantic']));
    foreach ([
        '/docs/../secreto.md', '/docs/guia/../../secreto.md', '/docs/..%2Fsecreto.md', '/docs/guia/..%2F..%2Fsecreto',
        '/docs/script.php', '/docs/script', '/docs/guia/notas.txt', '/docs/guia/notas', '/docs/guia/empezar.php.md',
        '/docs/guia/empezar.md.php', '/docs/%2e%2e/secreto.md', '/docs/guia/', '/docs/nada/nada', '/docs/.md',
    ] as $path) {
        $r = $web->get($path);
        check($r->status === 404 || $r->status === 301 || $r->status === 302, $path . ' no se sirve (dio ' . $r->status . ')');
        notContains('esto no se puede leer', $r->body, $path);
        notContains('<?php', $r->body, $path);
    }
    same(404, $web->get('/docs/../secreto.md')->status);
    same(404, $web->get('/docs/script.php')->status);
});

test('con la carpeta vacía o sin crear, el índice lo dice y nada se rompe', function (): void {
    foreach ([tmpDir('aifec-docs-vacia'), tmpDir('aifec-docs-no') . '/no-existe'] as $dir) {
        $web = new TestClient(aifecDocsApp($dir));
        $index = $web->get('/docs');
        same(200, $index->status);
        contains('data-docs-empty', $index->body);
        contains('Todavía no hay documentación.', $index->body);
        same(200, $web->get('/docs/buscar?q=algo')->status, 'el buscador no se rompe');
        same(404, $web->get('/docs/guia/empezar')->status);
    }
    check((new Library(tmpDir('aifec-docs-vacia2')))->isEmpty(), 'la biblioteca vacía lo sabe');
});

test('indice.json pone el orden y los títulos; lo que no nombra va detrás', function (): void {
    $dir = aifecDocsFolder();
    file_put_contents($dir . '/indice.json', json_encode(['sections' => [
        ['slug' => 'api', 'title' => 'Referencia de la API', 'pages' => [['slug' => 'rutas', 'title' => 'Las rutas']]],
        ['slug' => 'guia', 'title' => 'Guía de uso', 'pages' => [['slug' => 'configurar', 'title' => 'Configurar el sistema'], ['slug' => 'no-existe', 'title' => 'Fantasma']]],
    ]], JSON_UNESCAPED_UNICODE));
    $tree = (new Library($dir))->tree();
    same(['api', 'guia'], array_slice(array_column($tree, 'slug'), 0, 2), 'lo que nombra el índice va primero y en su orden');
    same('Referencia de la API', $tree[0]['title']);
    same('Las rutas', $tree[0]['pages'][0]['title']);
    same(['guia/configurar', 'guia/empezar'], array_column($tree[1]['pages'], 'path'), 'la nombrada antes; la que falta en el índice, detrás');
    same('Configurar el sistema', $tree[1]['pages'][0]['title']);
    check(!in_array('guia/no-existe', array_column((new Library($dir))->pages(), 'path'), true), 'una entrada del índice sin fichero no crea una página');
    $web = new TestClient(aifecDocsApp($dir));
    contains('Guía de uso', $web->get('/docs')->body);
});

test('sin indice.json el título sale del primer encabezado y, si no lo hay, del nombre del fichero', function (): void {
    $dir = tmpDir('aifec-docs-titulos');
    aifecDocsWrite($dir . '/03-sin-titulo.md', "solo texto, sin encabezado\n");
    aifecDocsWrite($dir . '/01-con-titulo.md', "# *Con* `título` propio #\n\nTexto.\n");
    aifecDocsWrite($dir . '/2_guia_rapida.md', "# Guía rápida\n");
    $pages = (new Library($dir))->pages();
    same(['con-titulo', 'guia_rapida', 'sin-titulo'], array_column($pages, 'slug'), 'el prefijo numérico ordena y no forma parte de la dirección');
    same('Con título propio', $pages[0]['title']);
    same('Sin titulo', $pages[2]['title']);
});

test('una página enorme no se lee y un .md con marca BOM y saltos CRLF se lee limpio', function (): void {
    $dir = tmpDir('aifec-docs-bytes');
    aifecDocsWrite($dir . '/bom.md', "\xEF\xBB\xBF# Con BOM\r\n\r\nTexto\r\n");
    aifecDocsWrite($dir . '/grande.md', "# Grande\n\n" . str_repeat('x', 1048577));
    $library = new Library($dir);
    $bom = $library->find('bom');
    check($bom !== null, 'la página con BOM existe');
    same("# Con BOM\n\nTexto\n", $library->read($bom));
    $big = $library->find('grande');
    check($big !== null, 'la grande se lista');
    same('', $library->read($big), 'pero no se lee');
    $web = new TestClient(aifecDocsApp($dir));
    same(404, $web->get('/docs/grande')->status, 'una página que no se puede leer da 404');
    same(200, $web->get('/docs/bom')->status);
});
