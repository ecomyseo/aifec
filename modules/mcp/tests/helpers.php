<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Ayudas de las pruebas del MCP. No es una prueba (no acaba en «Test.php»): se carga con require_once. Usa las de la API
 * (apiApp, apiCall, apiKey…) y las de la compra en el chat.
 *
 *   mcpApp(array $ajustes = [], array $modulos = []): App
 *       Tienda de pruebas con el MCP y la API (apiApp).
 *
 *   mcpPost(App $app, $cuerpo, array $cabeceras = [], string $ip = '127.0.0.1'): Response
 *       POST /mcp con ese cuerpo (un array se manda como JSON; un texto, tal cual).
 *
 *   mcpRpc(App $app, string $metodo, array $params = [], array $cabeceras = [], int $id = 1): array
 *       Un mensaje JSON-RPC y su respuesta descodificada (el mensaje entero: jsonrpc, id y result o error).
 *
 *   mcpSession(App $app): string
 *       Abre una sesión con initialize y devuelve su identificador (Mcp-Session-Id).
 *
 *   mcpTool(App $app, string $herramienta, array $argumentos = [], ?string $sesion = null, array $cabeceras = []): array
 *       tools/call: devuelve el result (content, structuredContent, isError). structuredContent, como array.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/../../api/tests/helpers.php';

if (!function_exists('mcpApp')) {
    /**
     * @param array<string,mixed> $ajustes
     * @param string[]            $modulos
     */
    function mcpApp(array $ajustes = [], array $modulos = []): App
    {
        return apiApp($ajustes, array_merge(['mcp'], $modulos));
    }

    /**
     * @param array<mixed>|string  $cuerpo
     * @param array<string,string> $cabeceras
     */
    function mcpPost(App $app, $cuerpo, array $cabeceras = [], string $ip = '127.0.0.1'): Response
    {
        return apiCall($app, 'POST', '/mcp', $cuerpo, $cabeceras, $ip);
    }

    /**
     * @param array<string,mixed>  $params
     * @param array<string,string> $cabeceras
     *
     * @return array<string,mixed>
     */
    function mcpRpc(App $app, string $metodo, array $params = [], array $cabeceras = [], int $id = 1): array
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $metodo];
        if ($params !== []) {
            $message['params'] = $params;
        }
        $r = mcpPost($app, $message, $cabeceras);
        $json = json_decode($r->body, true);
        check(is_array($json), 'la respuesta es JSON: ' . substr($r->body, 0, 200));

        return $json;
    }

    function mcpSession(App $app): string
    {
        $r = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
        same(200, $r->status, 'initialize');
        check(isset($r->headers['Mcp-Session-Id']) && preg_match('/^[a-f0-9]{32}$/D', $r->headers['Mcp-Session-Id']) === 1, 'initialize entrega la sesión');

        return $r->headers['Mcp-Session-Id'];
    }

    /**
     * @param array<string,mixed>  $argumentos
     * @param array<string,string> $cabeceras
     *
     * @return array<string,mixed>
     */
    function mcpTool(App $app, string $herramienta, array $argumentos = [], ?string $sesion = null, array $cabeceras = []): array
    {
        if ($sesion !== null) {
            $cabeceras['mcp-session-id'] = $sesion;
        }
        $json = mcpRpc($app, 'tools/call', ['name' => $herramienta, 'arguments' => $argumentos], $cabeceras);
        check(isset($json['result']), $herramienta . ' devuelve un resultado: ' . (string) json_encode($json));
        $result = $json['result'];
        check(isset($result['content'][0]['text']) && $result['content'][0]['type'] === 'text', 'con un bloque de texto');
        same($result['structuredContent'], json_decode($result['content'][0]['text'], true), 'el texto y el contenido estructurado dicen lo mismo');

        return $result;
    }
}
