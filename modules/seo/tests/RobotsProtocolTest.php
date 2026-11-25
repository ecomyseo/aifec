<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Pruebas de /robots.txt para agentes: las rutas de los protocolos (UCP, ACP, MCP, API, compra con enlaces) se pueden abrir aunque
 * otra regla cierre «?q=», con el criterio de RFC 9309 (gana la regla más larga y, a igual longitud, la que permite); y la señal
 * «Content-Signal» y la regla de Google-Extended siguen los ajustes de robots del panel.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * ¿Puede ese robot abrir esa ruta según el robots.txt? Con RFC 9309: se usa el grupo que nombra al robot (sin distinguir
 * mayúsculas) o, si ninguno, el de «*»; gana la regla de coincidencia más larga y, a igualdad, «Allow»; «*» vale por cualquier
 * cadena y «$» ancla el final. Sin regla que coincida, se puede.
 */
function seoRobotMay(string $robots, string $agent, string $path): bool
{
    $groups = [];
    $current = null;
    $lastWasAgent = false;
    foreach (preg_split('/\r?\n/', $robots) ?: [] as $line) {
        $line = trim((string) preg_replace('/\s+#.*$/', '', $line));
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m) !== 1) {
            continue;
        }
        $key = strtolower($m[1]);
        if ($key === 'user-agent') {
            if (!$lastWasAgent || $current === null) {
                $groups[] = ['agents' => [], 'rules' => []];
                $current = count($groups) - 1;
            }
            $groups[$current]['agents'][] = strtolower($m[2]);
            $lastWasAgent = true;
            continue;
        }
        $lastWasAgent = false;
        if (($key === 'allow' || $key === 'disallow') && $current !== null) {
            $groups[$current]['rules'][] = [$key === 'allow', $m[2]];
        }
    }
    $rules = [];
    foreach ($groups as $g) {
        if (in_array(strtolower($agent), $g['agents'], true)) {
            $rules = array_merge($rules, $g['rules']);
        }
    }
    if ($rules === []) {
        foreach ($groups as $g) {
            if (in_array('*', $g['agents'], true)) {
                $rules = array_merge($rules, $g['rules']);
            }
        }
    }
    $bestLen = -1;
    $allowed = true;
    foreach ($rules as $rule) {
        list($isAllow, $pattern) = $rule;
        if ($pattern === '') {
            continue;
        }
        $regex = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')) . '#';
        if (preg_match($regex, $path) !== 1) {
            continue;
        }
        $len = strlen($pattern);
        if ($len > $bestLen || ($len === $bestLen && $isAllow)) {
            $bestLen = $len;
            $allowed = $isAllow;
        }
    }

    return $allowed;
}

test('el intérprete de RFC 9309 de la prueba: gana la regla más larga y, a igualdad, la que permite', function (): void {
    $txt = "User-agent: *\nDisallow: /*?q=\nAllow: /ucp/\nDisallow: /admin\n\nUser-agent: Bot\nDisallow: /\n";
    check(seoRobotMay($txt, 'Otro', '/ucp/v1/x?q=1'), 'Allow igual de largo que Disallow gana');
    check(!seoRobotMay($txt, 'Otro', '/buscar?q=1'), 'Disallow ?q= cierra el buscador');
    check(!seoRobotMay($txt, 'Otro', '/admin/x'));
    check(!seoRobotMay($txt, 'bot', '/ucp/v1'), 'el grupo del robot manda y no se mezcla con *');
});

test('con todos los módulos de protocolo, cada robot de IA y los buscadores pueden abrir las rutas de los protocolos aunque se cierre «?q=»', function (): void {
    $app = seoApp(['seo', 'orders', 'agentic', 'api', 'mcp'], ['modules.active' => ['seo', 'orders', 'mail', 'media', 'jobs', 'agentic', 'api', 'mcp'], 'agentic.ucp_enabled' => true, 'agentic.acp_enabled' => true]);
    $body = (new TestClient($app))->get('/robots.txt')->body;
    $paths = [
        '/ucp/v1/catalog/search?q=silla', '/ucp/v1/checkout-sessions', '/ucp/mcp', '/acp/feed.jsonl?q=x', '/checkout_sessions',
        '/api/v1/search?q=silla', '/api/v1/products?q=x', '/buy-with-links/new/3?text=hola', '/mcp', '/.well-known/ucp', '/.well-known/acp.json',
    ];
    $bots = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended', 'Googlebot', 'UnRobotCualquiera'];
    foreach ($bots as $bot) {
        foreach ($paths as $path) {
            check(seoRobotMay($body, $bot, $path), $bot . ' puede abrir ' . $path);
        }
        // Y lo privado sigue cerrado para todos.
        foreach (['/admin', '/carrito', '/finalizar-compra', '/mi-cuenta', '/buscar?q=x'] as $private) {
            check(!seoRobotMay($body, $bot, $private), $bot . ' no puede abrir ' . $private);
        }
    }
});

test('sin el módulo de un protocolo, o con él apagado, no se anuncia su ruta', function (): void {
    $active = ['seo', 'orders', 'mail', 'media', 'jobs', 'agentic'];
    $ucpOnly = (new TestClient(seoApp(['seo', 'orders', 'agentic'], ['modules.active' => $active, 'agentic.ucp_enabled' => true])))->get('/robots.txt')->body;
    contains("Allow: /ucp/\n", $ucpOnly);
    notContains("Allow: /acp/\n", $ucpOnly, 'ACP apagado');
    notContains('/checkout_sessions', $ucpOnly, 'ACP apagado');
    notContains("Allow: /mcp\n", $ucpOnly, 'sin el módulo MCP');
    notContains("Allow: /buy-with-links\n", $ucpOnly, 'sin el módulo de la API');
    $acpOnly = (new TestClient(seoApp(['seo', 'orders', 'agentic'], ['modules.active' => $active, 'agentic.acp_enabled' => true])))->get('/robots.txt')->body;
    contains("Allow: /acp/\n", $acpOnly);
    contains("Allow: /checkout_sessions\n", $acpOnly);
    notContains('/ucp/', $acpOnly, 'UCP apagado de serie');
    $none = (new TestClient(seoApp(['seo', 'orders', 'agentic'], ['modules.active' => $active])))->get('/robots.txt')->body;
    notContains('/ucp/', $none);
    notContains('/acp/', $none);
    $off = (new TestClient(seoApp(['seo', 'api', 'mcp'], ['mcp.enabled' => false, 'api.links' => false])))->get('/robots.txt')->body;
    notContains("Allow: /mcp\n", $off, 'MCP apagado en los ajustes');
    notContains("Allow: /buy-with-links\n", $off, 'compra con enlaces apagada');
    contains("Allow: /api/v1/\n", $off);
});

test('Content-Signal y Google-Extended siguen los ajustes de robots del panel', function (): void {
    $default = (new TestClient(seoApp(['seo'])))->get('/robots.txt')->body;
    contains("Content-Signal: search=yes, ai-input=yes, ai-train=yes\n", $default, 'por defecto todo permitido, como dicen los ajustes');
    contains("User-agent: Google-Extended\n", $default);
    contains("User-agent: Applebot-Extended\n", $default);
    check(seoRobotMay($default, 'Google-Extended', '/producto/silla'));

    $noTrain = (new TestClient(seoApp(['seo'], ['seo.bots.training' => 'disallow'])))->get('/robots.txt')->body;
    contains("Content-Signal: search=yes, ai-input=yes, ai-train=no\n", $noTrain);
    check(!seoRobotMay($noTrain, 'Google-Extended', '/producto/silla'), 'Google-Extended fuera con el entrenamiento bloqueado');
    check(!seoRobotMay($noTrain, 'GPTBot', '/producto/silla'));
    check(seoRobotMay($noTrain, 'Googlebot', '/producto/silla'), 'Google Search sigue entrando');
    check(seoRobotMay($noTrain, 'ChatGPT-User', '/producto/silla'));

    $noAssistants = (new TestClient(seoApp(['seo'], ['seo.bots.assistant' => 'disallow', 'seo.bots.search' => 'disallow'])))->get('/robots.txt')->body;
    contains("Content-Signal: search=no, ai-input=no, ai-train=yes\n", $noAssistants);
    // El sitio cerrado no lleva la señal: dice «Disallow: /» y nada más.
    notContains('Content-Signal', (new TestClient(seoApp(['seo'], ['seo.noindex_site' => true])))->get('/robots.txt')->body);
});
