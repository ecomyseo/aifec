<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El /llms.txt cumple el formato que comprueba la auditoría «Navegación agéntica» de Lighthouse (llmstxt.org): título,
 * resumen en cita y secciones con listas de enlaces. Aquí está el validador y la prueba de la tienda real contra él.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el validador del formato acepta un llms.txt correcto y caza cada cosa que le falta', function (): void {
    $ok = "# Tienda\n\n> Muebles de roble.\n\nTexto libre opcional.\n\n## Productos\n\n- [Silla](https://tienda.test/producto/silla.md): 59,90 €\n- [Mesa](https://tienda.test/producto/mesa.md)\n";
    same([], webmcpLlmsProblems($ok));

    contains('título', implode('|', webmcpLlmsProblems("Tienda\n\n> Resumen.\n\n## A\n\n- [x](https://tienda.test/x)\n")));
    contains('resumen en cita', implode('|', webmcpLlmsProblems("# Tienda\n\nResumen sin cita.\n\n## A\n\n- [x](https://tienda.test/x)\n")));
    contains('no hay ninguna sección', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n")));
    contains('no tiene ningún enlace', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n\n## Vacía\n\n## Otra\n\n- [x](https://tienda.test/x)\n")));
    contains('solo van enlaces', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n\n## A\n\nUn párrafo suelto.\n")));
    contains('segundo nivel', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n\n### Tercer nivel\n\n## A\n\n- [x](https://tienda.test/x)\n")));
    contains('más de un título', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n\n# Otro\n\n## A\n\n- [x](https://tienda.test/x)\n")));
    contains('solo van enlaces', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n\n## A\n\n- [x](/relativo)\n")));
    contains('sale dos veces', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n\n## A\n\n- [x](https://tienda.test/x)\n- [y](https://tienda.test/x)\n")));
    contains('salto de línea', implode('|', webmcpLlmsProblems("# Tienda\n\n> Resumen.\n\n## A\n\n- [x](https://tienda.test/x)")));
});

test('el /llms.txt de una tienda con catálogo, blog y páginas cumple el formato', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms', 'webmcp'], ['store.name' => 'Muebles Norte & Hijos', 'semantic.summary' => "Muebles de roble\nhechos a mano."]);
    $muebles = aifecCategory($app, 'Muebles');
    $sillas = aifecCategory($app, 'Sillas', $muebles);
    semanticProduct($app, ['categories' => [$sillas]]);
    aifecProduct($app, ['name' => 'Mesa baja', 'categories' => [$muebles]]);
    cmsInsertPost($app, ['title' => 'Cómo cuidar la madera']);
    $web = new TestClient($app);

    $r = $web->get('/llms.txt');
    same(200, $r->status);
    same([], webmcpLlmsProblems($r->body), 'el llms.txt de la tienda');
    contains('# Muebles Norte & Hijos', $r->body);
    contains("\n> Muebles de roble hechos a mano.\n", $r->body);
});

test('el llms.txt de una tienda vacía y sin módulos extra también es válido, y /llms-full.txt empieza igual', function (): void {
    $app = semanticApp(['semantic'], ['store.name' => 'Tienda Mínima']);
    $web = new TestClient($app);

    $short = $web->get('/llms.txt');
    same([], webmcpLlmsProblems($short->body), 'sin catálogo ni blog');
    $full = $web->get('/llms-full.txt');
    same(200, $full->status);
    check(strpos($full->body, '# Tienda Mínima' . "\n") === 0, 'el completo empieza por el título');
});

test('con un nombre de tienda o un resumen con corchetes, paréntesis y almohadillas el llms.txt sigue siendo válido', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['store.name' => 'Casa [Norte] (2026) #1', 'semantic.summary' => "Muebles [a medida] (roble)\n# no es un título"]);
    semanticProduct($app, ['name' => 'Silla [roble] (nórdica) # uno']);
    $r = (new TestClient($app))->get('/llms.txt');

    same(200, $r->status);
    same([], webmcpLlmsProblems($r->body), $r->body);
});

test('con WebMCP encendido el llms.txt cuenta dónde está la lista de herramientas, y apagado no la nombra', function (): void {
    $on = semanticApp(['semantic', 'webmcp', 'assistant', 'catalog']);
    $body = (new TestClient($on))->get('/llms.txt')->body;
    contains('- [Herramientas WebMCP para agentes en el navegador](https://tienda.test/webmcp/tools.json)', $body);
    same([], webmcpLlmsProblems($body));

    $off = semanticApp(['semantic', 'webmcp', 'assistant', 'catalog'], ['webmcp.enabled' => false]);
    notContains('webmcp', (new TestClient($off))->get('/llms.txt')->body);
});
