<?php
/**
 * AIFEC — órdenes de consola del módulo MCP.
 *
 *   php aifec mcp:call <herramienta> '<json>' [sesión]   llama a una herramienta como lo haría un cliente MCP y enseña el resultado
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Mcp\Agent;
use Aifec\Modules\Mcp\Server;
use Aifec\Modules\Mcp\Sessions;

return [
    'mcp:call' => ['help' => 'Calls an MCP tool the way a client would: mcp:call <tool> \'<json arguments>\' [session id]', 'run' => static function (App $app, array $args, Console $io): int {
        $name = isset($args[0]) ? trim((string) $args[0]) : '';
        $json = isset($args[1]) && trim((string) $args[1]) !== '' ? (string) $args[1] : '{}';
        $arguments = json_decode($json, true);
        if ($name === '' || !is_array($arguments)) {
            $io->error('Usage: mcp:call <tool> \'<json arguments>\' [session id]  (for example: mcp:call search_products \'{"query":"chair"}\')');

            return 2;
        }
        $session = isset($args[2]) && Sessions::valid(strtolower(trim((string) $args[2]))) ? strtolower(trim((string) $args[2])) : Sessions::newId();
        $agent = new Agent($app, $session, null);
        $server = new Server($app, $agent);
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]];
        $agent->prepare($server->needsCart([$message]));
        $answer = $server->handle($message);
        if ($answer === null || isset($answer['error'])) {
            $io->error('error ' . (isset($answer['error']['code']) ? (string) $answer['error']['code'] : '') . ': ' . (isset($answer['error']['message']) ? (string) $answer['error']['message'] : 'no answer'));

            return 1;
        }
        $io->line('session: ' . $session);
        $text = isset($answer['result']['content'][0]['text']) ? (string) $answer['result']['content'][0]['text'] : '{}';
        $pretty = json_decode($text, true);
        $io->line(is_array($pretty) ? (string) json_encode($pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $text);

        return !empty($answer['result']['isError']) ? 1 : 0;
    }],
];
