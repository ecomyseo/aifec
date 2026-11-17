<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El guion webmcp.js: pesa poco, no lleva textos visibles, solo actúa si el navegador ofrece la API, registra las
 * herramientas de la lista (nunca a otro sitio, nunca dos veces con un formulario marcado) y cada una llama a su dirección.
 * Se ejecuta con Node y un navegador de mentira (script_harness.js) cuando Node está disponible.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** La ruta del guion. */
function webmcpScriptPath(): string
{
    return dirname(__DIR__) . '/assets/webmcp.js';
}

/** Su código sin comentarios de línea ni de bloque (lo que ejecuta el navegador). */
function webmcpScriptCode(): string
{
    $code = '';
    foreach (explode("\n", (string) file_get_contents(webmcpScriptPath())) as $line) {
        $trim = ltrim($line);
        if ($trim !== '' && strpos($trim, '//') !== 0 && strpos($trim, '/*') !== 0 && strpos($trim, '*') !== 0) {
            $code .= $line . "\n";
        }
    }

    return $code;
}

/**
 * Ejecuta el guion con el arnés de Node. null si Node no está en esta máquina.
 *
 * @return array<string,mixed>|null
 */
function webmcpRunScript(string $scenario): ?array
{
    static $node = null;
    if ($node === null) {
        $out = [];
        $code = 1;
        @exec('node --version 2>&1', $out, $code);
        $node = $code === 0 ? 'node' : '';
    }
    if ($node === '') {
        return null;
    }
    $output = [];
    $code = 1;
    exec('node ' . escapeshellarg(__DIR__ . '/script_harness.js') . ' ' . escapeshellarg(webmcpScriptPath()) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $code);
    $data = json_decode(implode("\n", $output), true);
    check($code === 0 && is_array($data), 'el arnés de Node responde (' . $scenario . '): ' . implode(' | ', $output));

    return is_array($data) ? $data : null;
}

test('el guion pesa 8 KB o menos y acaba cerrado', function (): void {
    $bytes = strlen((string) file_get_contents(webmcpScriptPath()));
    check($bytes <= 8192, 'pesa ' . $bytes . ' bytes');
    check($bytes > 1000, 'no está vacío');
    check(preg_match('/\}\(\)\);\s*$/', (string) file_get_contents(webmcpScriptPath())) === 1, 'termina con el cierre de su función');
});

test('el guion pasa node --check', function (): void {
    $out = [];
    $code = 1;
    @exec('node --check ' . escapeshellarg(webmcpScriptPath()) . ' 2>&1', $out, $code);
    if ($code === 127 || ($code !== 0 && strpos(implode(' ', $out), 'not recognized') !== false)) {
        check(true, 'Node no está en esta máquina: no se puede comprobar la sintaxis aquí');

        return;
    }
    same(0, $code, 'node --check: ' . implode(' | ', $out));
});

test('el guion no lleva cadenas visibles: ni letras con tilde, ni frases, ni HTML', function (): void {
    $code = webmcpScriptCode();
    check(preg_match('/[^\x00-\x7F]/', $code) === 0, 'sin letras con tilde ni frases en el código del guion');
    preg_match_all("/'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'/", $code, $strings);
    check(count($strings[1]) > 10, 'se leyeron las cadenas del guion');
    foreach ($strings[1] as $string) {
        check(strpos($string, ' ') === false || $string === 'use strict', 'una cadena con espacios parece una frase: «' . $string . '»');
        check(strpos($string, '<') === false, 'nada de HTML: «' . $string . '»');
    }
    notContains('innerHTML', $code);
    notContains('document.write', $code);
    notContains('eval(', $code);
    notContains('alert(', $code);
    notContains('Math.random', $code);
});

test('el guion es un reposo: se carga con defer, espera al navegador en reposo y no pinta nada en la página', function (): void {
    $code = webmcpScriptCode();
    contains('requestIdleCallback', $code, 'trabaja cuando el navegador está en reposo');
    contains("'data-webmcp-tools'", $code, 'la dirección de la lista llega en un atributo');
    notContains('createElement', $code, 'no crea nada en la página');
    notContains('appendChild', $code);
    notContains('.style', $code, 'no toca estilos');
    notContains('addEventListener', $code, 'no se engancha a ningún evento');
    same(2, substr_count($code, 'window.fetch('), 'solo pide la lista y llama a las herramientas');
    contains("credentials: 'same-origin'", $code);
    contains("cache: 'no-store'", $code, 'las llamadas no se cachean');
    contains("'X-Requested-With': 'fetch'", $code);

    $app = webmcpApp();
    assistantShop($app);
    $body = (new TestClient($app))->get('/')->body;
    check(preg_match('#<script src="[^"]*assets/m/webmcp/[^"]*/webmcp\.js" data-webmcp-tools="/webmcp/tools\.json" defer></script>#', $body) === 1, 'la etiqueta del guion lleva defer y la dirección de la lista');
    same(1, substr_count($body, 'webmcp.js'), 'una sola vez');
});

test('sin la API en el navegador el guion no hace nada: ni una petición', function (): void {
    $r = webmcpRunScript('none');
    if ($r === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    same([], $r['registered']);
    same([], $r['fetches'], 'ni siquiera pide la lista');
    same(0, $r['idleCalls']);
});

test('con document.modelContext registra las herramientas de la lista, con su esquema y sus anotaciones, en reposo', function (): void {
    $r = webmcpRunScript('ok');
    if ($r === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    $names = array_column($r['registered'], 'name');
    same(['search_products', 'cart_add'], $names, 'solo las que son de la propia web');
    foreach ($r['registered'] as $tool) {
        same(true, $tool['hasSchema'], $tool['name']);
    }
    same(true, $r['registered'][0]['annotations']['readOnlyHint'], 'search_products solo lee');
    same([], (array) $r['registered'][1]['annotations'], 'cart_add no es de solo lectura: sin anotación');
    same('/webmcp/tools.json', $r['fetches'][0]['url']);
    same(1, $r['idleCalls'], 'esperó al reposo');
});

test('con navigator.modelContext (la forma anterior de la API) también funciona', function (): void {
    $r = webmcpRunScript('legacy');
    if ($r === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    same(['search_products', 'cart_add'], array_column($r['registered'], 'name'));
});

test('nunca registra una herramienta que llame a otro sitio, ni con una dirección que empiece por //', function (): void {
    $r = webmcpRunScript('ok');
    if ($r === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    $names = array_column($r['registered'], 'name');
    check(!in_array('evil_tool', $names, true), 'la de https://evil.test se descarta');
    check(!in_array('sneaky_tool', $names, true), 'la de //evil.test se descarta');
    foreach ($r['fetches'] as $fetch) {
        check(strpos($fetch['url'], 'evil.test') === false, 'ninguna petición sale a otro sitio');
    }
});

test('si un formulario ya ofrece la herramienta, el guion no la registra otra vez con el mismo nombre', function (): void {
    $r = webmcpRunScript('declared');
    if ($r === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    same(['cart_add'], array_column($r['registered'], 'name'), 'search_products ya está en el formulario del buscador');
});

test('un error al registrar una herramienta no tumba el resto', function (): void {
    $r = webmcpRunScript('throwing');
    if ($r === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    same(['cart_add'], array_column($r['registered'], 'name'));
});

test('ejecutar una herramienta es un POST JSON a su dirección, con las cookies de la propia web y sin caché, y devuelve el JSON como texto', function (): void {
    $r = webmcpRunScript('execute');
    if ($r === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    same('/webmcp/call/cart_add', $r['lastFetch']['url']);
    same('POST', $r['lastFetch']['method']);
    same('same-origin', $r['lastFetch']['credentials']);
    same('no-store', $r['lastFetch']['cache']);
    same('{"product_id":5,"qty":2}', $r['lastFetch']['body']);
    same('{"ok":true,"echo":{"product_id":5,"qty":2}}', $r['executed'], 'el agente recibe el JSON como texto');
    same('{"ok":true,"echo":{}}', $r['executedNoArgs'], 'sin argumentos manda un objeto vacío');
    $post = null;
    foreach ($r['fetches'] as $fetch) {
        if ($fetch['method'] === 'POST') {
            $post = $fetch;
        }
    }
    check($post === null, 'registrar no llama a ninguna herramienta (solo ejecutar lo hace)');
});

test('si el servidor no contesta o no hay atributo con la lista, el guion se calla sin romper la página', function (): void {
    $failing = webmcpRunScript('failing');
    $noattr = webmcpRunScript('noattr');
    if ($failing === null || $noattr === null) {
        check(true, 'sin Node no se ejecuta');

        return;
    }
    same([], $failing['registered'], 'sin lista no registra nada');
    same([], $noattr['registered']);
    same([], $noattr['fetches'], 'sin dirección de la lista no pide nada');
});
