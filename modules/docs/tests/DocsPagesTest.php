<?php
/**
 * AIFEC — pruebas de las páginas de la documentación: el índice, cada página con su árbol y su índice lateral, anterior y
 * siguiente, la versión en texto, la cabecera de la página y que el HTML peligroso de un fichero sale saneado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Request;

require_once __DIR__ . '/helpers.php';

test('el índice enseña las secciones y las páginas en su orden, sin el prefijo numérico en la dirección', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    $r = $web->get('/docs');
    same(200, $r->status);
    contains('<h1 class="af-title">Documentación</h1>', $r->body);
    contains('href="/docs/guia/empezar"', $r->body);
    contains('href="/docs/guia/configurar"', $r->body);
    contains('href="/docs/api/rutas"', $r->body);
    contains('href="/docs/acerca"', $r->body);
    notContains('01-empezar', $r->body);
    notContains('script.php', $r->body, 'un .php no es una página');
    notContains('notas', $r->body, 'un .txt no es una página');
    // «10-extra» va detrás de «02-api»: el orden es de personas, no de cadenas.
    check(strpos($r->body, '/docs/api/rutas') < strpos($r->body, '/docs/extra/otra'), 'la sección 2 va antes que la 10');
    check(strpos($r->body, '/docs/guia/empezar') < strpos($r->body, '/docs/guia/configurar'), 'dentro de la sección, por su número');
});

test('una página enseña su título, el árbol con ella marcada, el contenido en af-prose y el índice con sus h2', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    $r = $web->get('/docs/guia/empezar');
    same(200, $r->status);
    contains('<h1 class="af-title">Empezar</h1>', $r->body);
    contains('class="af-prose af-docs-prose"', $r->body);
    contains('<strong>negrita</strong>', $r->body);
    contains('<a href="/docs/guia/empezar" aria-current="page">Empezar</a>', $r->body);
    contains('class="af-docs-tree"', $r->body);
    contains('class="af-docs-toc"', $r->body);
    contains('<h2 id="instalar">Instalar</h2>', $r->body);
    contains('<a href="#instalar">Instalar</a>', $r->body);
    contains('<a href="#usar">Usar</a>', $r->body);
    contains('class="language-php"', $r->body, 'el bloque de código lleva su lenguaje');
    $prose = substr($r->body, (int) strpos($r->body, 'af-docs-prose'));
    notContains('<script', substr($prose, 0, (int) strpos($prose, '</article>')), 'sin resaltado por JavaScript dentro del contenido');
});

test('anterior y siguiente siguen el orden de lectura, también de una sección a otra', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    // Primero las páginas de la raíz (acerca, peligro) y después las secciones por su número.
    $first = $web->get('/docs/acerca')->body;
    notContains('rel="prev"', $first, 'la primera no tiene anterior');
    contains('rel="next" href="/docs/peligro"', $first);
    $one = $web->get('/docs/guia/empezar')->body;
    contains('rel="prev" href="/docs/peligro"', $one, 'de la raíz a la primera sección');
    contains('rel="next" href="/docs/guia/configurar"', $one);
    $mid = $web->get('/docs/guia/configurar')->body;
    contains('rel="prev" href="/docs/guia/empezar"', $mid);
    contains('rel="next" href="/docs/api/rutas"', $mid, 'de una sección a la siguiente');
    $last = $web->get('/docs/extra/otra')->body;
    contains('rel="prev" href="/docs/api/rutas"', $last);
    notContains('rel="next"', $last, 'la última no tiene siguiente');
});

test('la versión en texto es el .md tal cual, como text/markdown, también con el módulo de datos semánticos encendido', function (): void {
    $dir = aifecDocsFolder();
    foreach ([['docs'], ['docs', 'semantic']] as $modules) {
        $web = new TestClient(aifecDocsApp($dir, $modules));
        $r = $web->get('/docs/guia/empezar.md');
        same(200, $r->status, implode(',', $modules));
        same('text/plain; charset=UTF-8', (string) $r->headers['Content-Type'], 'texto plano: hay lectores de asistentes que no abren text/markdown');
        same(str_replace("\r\n", "\n", (string) file_get_contents($dir . '/01-guia/01-empezar.md')), $r->body);
        contains('noindex', (string) $r->headers['X-Robots-Tag']);
    }
    $web = new TestClient(aifecDocsApp($dir, ['docs', 'semantic']));
    same(404, $web->get('/docs/guia/no-existe.md')->status);
    contains('href="/docs/guia/empezar.md"', $web->get('/docs/guia/empezar')->body, 'la página enlaza a su texto');
});

test('la cabecera de la página lleva título, descripción del primer párrafo, canónica, enlace al texto y migas', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    $body = $web->get('/docs/guia/empezar')->body;
    contains('<title>Empezar', $body);
    contains('<meta name="description" content="Primer párrafo con negrita y un enlace.">', $body);
    contains('<link rel="canonical" href="https://tienda.test/docs/guia/empezar">', $body);
    contains('<link rel="alternate" type="text/markdown" href="https://tienda.test/docs/guia/empezar.md">', $body);
    contains('class="af-breadcrumbs"', $body);
    contains('href="/docs"', $body);
    check(substr_count($body, '<h1') === 1, 'un solo h1');
});

test('el HTML peligroso dentro de un .md sale saneado', function (): void {
    $web = new TestClient(aifecDocsApp(aifecDocsFolder()));
    $body = $web->get('/docs/peligro')->body;
    $prose = substr($body, (int) strpos($body, 'af-docs-prose'));
    $prose = substr($prose, 0, (int) strpos($prose, '</article>'));
    notContains('<script>', $prose);
    notContains('<img', $prose);
    notContains('javascript:', $prose);
    notContains('onerror=alert(2)>', $prose);
    contains('&lt;script&gt;', $prose, 'el HTML se enseña como texto, no se ejecuta');
});

test('las respuestas se guardan en la caché de página con la etiqueta docs, y el buscador no', function (): void {
    $app = aifecDocsApp(aifecDocsFolder());
    foreach (['/docs', '/docs/guia/empezar', '/docs/guia/empezar.md'] as $path) {
        $r = $app->handle(Request::create('GET', $path));
        same(200, $r->status, $path);
        check($r->cacheTtl > 0, $path . ' se puede guardar');
        check(in_array('docs', $r->cacheTags, true), $path . ' lleva la etiqueta docs');
    }
    $search = $app->handle(Request::create('GET', '/docs/buscar?q=zanahoria'));
    same(0, $search->cacheTtl, 'cada consulta del buscador es distinta: no se guarda');
});

test('docs:refresh invalida la etiqueta y dice cuántas páginas hay', function (): void {
    $app = aifecDocsApp(aifecDocsFolder());
    $commands = require $app->root() . '/modules/docs/cli.php';
    $before = $app->cache()->tagVersion('docs');
    $io = new Aifec\Cli\Console(false);
    same(0, $commands['docs:refresh']['run']($app, [], $io));
    check($app->cache()->tagVersion('docs') > $before, 'la etiqueta docs cambia de versión');
    contains('6 pages', $io->output);
});

test('el título del fichero no se repite en el contenido y un segundo «# …» baja a h2: un solo h1 por página', function (): void {
    $dir = tmpDir('aifec-docs-h1');
    aifecDocsWrite($dir . '/uno.md', "# Uno\n\nIntroducción.\n\n# Otro título\n\nTexto.\n");
    $body = (new TestClient(aifecDocsApp($dir)))->get('/docs/uno')->body;
    same(1, substr_count($body, '<h1'), 'un solo h1');
    same(1, substr_count($body, '>Uno</h1>'), 'el título sale una vez, en la plantilla');
    contains('<h2 id="otro-titulo">Otro título</h2>', $body);
    contains('<a href="#otro-titulo">Otro título</a>', $body, 'y entra en el índice de la página');
});
