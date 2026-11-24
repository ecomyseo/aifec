<?php
/** Comprueba el bloqueo en las rutas públicas de compra y el acceso del agente elegido. */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('Un navegador sin permiso no crea carritos aunque envíe cabeceras de navegación', function (): void {
    $app = apiApp(['api.cors_origins' => 'https://integracion.test']);
    $products = assistantShop($app);
    $client = new TestClient($app);
    $agent = 'Mozilla/5.0 Navegador de prueba';
    $headers = ['user-agent' => $agent, 'sec-fetch-user' => '?1', 'sec-fetch-dest' => 'document', 'sec-fetch-mode' => 'navigate'];
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {carts}');
    same(403, $client->get('/buy-with-links/start', $headers)->status);
    $fallback = $client->get('/buy-with-links/new/' . $products['silla_roja'], $headers + ['accept' => 'text/html']);
    check($fallback->isRedirect(), 'la navegación humana pasa a la ficha normal');
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'las dos rutas no crean carritos');
    same(403, $client->post('/api/v1/carts', [], $headers)->status, 'la API anónima respeta el bloqueo');
    same(403, $client->post('/api/v1/carts', [], $headers + ['origin' => 'https://tienda.test'])->status, 'el navegador de la tienda también necesita permiso para usar la API');
    same(403, $client->post('/api/v1/carts', [], $headers + ['origin' => 'https://integracion.test'])->status, 'un origen CORS permitido tampoco concede permiso');
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    $app->settings()->set('api.browser_agents_allowed', $agent);
    same(403, $client->post('/api/v1/carts', [], $headers + ['origin' => 'https://otro.test'])->status, 'la lista de navegadores no concede permisos CORS');
    $allowed = $client->get('/buy-with-links/start', $headers + ['accept' => 'application/json']);
    same(200, $allowed->status);
    same($before + 1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    same(403, $client->send('HEAD', '/buy-with-links/start', [], $headers)->status);
    same($before + 1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
});

test('Los robots de vista previa y las precargas no crean carritos por GET en /buy-with-links (new, add, start)', function (): void {
    // Auditoría externa del 24/11/2026: una dirección que crea un carrito la abren solos los chats al pegarla (WhatsApp, Facebook,
    // Slack…) y el navegador al adelantarla.
    $app = apiApp();
    $product = assistantShop($app)['silla_roja'];
    $client = new TestClient($app);
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {carts}');
    $previews = ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'WhatsApp/2.23.20.0 A', 'Slackbot-LinkExpanding 1.0',
        'Twitterbot/1.0', 'TelegramBot (like TwitterBot)', 'Discordbot/2.0', 'LinkedInBot/1.0', 'SkypeUriPreview Preview/0.5', 'Mastodon/4.2'];
    foreach ($previews as $agent) {
        foreach (['/buy-with-links/start', '/buy-with-links/new/' . $product, '/buy-with-links/add?product_id=' . $product . '&qty=1'] as $path) {
            $status = $client->get($path, ['user-agent' => $agent])->status;
            same(403, $status, $agent . ' ' . $path);
        }
    }
    $prefetch = ['user-agent' => 'Mozilla/5.0 Chrome/149.0', 'sec-purpose' => 'prefetch;prerender', 'sec-fetch-user' => '?1', 'sec-fetch-dest' => 'document', 'sec-fetch-mode' => 'navigate'];
    $app->settings()->set('api.browser_agents_allowed', 'Mozilla/5.0 Chrome/149.0');
    same(403, $client->get('/buy-with-links/start', $prefetch)->status, 'una precarga no crea carritos ni con el navegador permitido');
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'ningún carrito nuevo');
});

test('Los rastreadores no crean carritos por las rutas directas ni por MCP', function (): void {
    $app = apiApp([], ['mcp']);
    $product = assistantShop($app)['silla_roja'];
    $client = new TestClient($app);
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {carts}');
    foreach (['SemrushBot/7', 'hrefLang checker', 'ExampleCrawler/1'] as $bot) {
        $headers = ['user-agent' => $bot];
        same(403, $client->post('/carrito/add', ['product_id' => $product, 'qty' => 1], $headers)->status);
        same(403, $client->post('/api/v1/carts', [], $headers)->status);
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'cart_add', 'arguments' => ['product_id' => $product]]];
        same(403, apiCall($app, 'POST', '/mcp', $rpc, $headers)->status, 'el cliente MCP puede leer el catálogo, pero no crear un carrito');
    }
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    $customer = $client->post('/carrito/add', ['product_id' => $product, 'qty' => 1], ['user-agent' => 'Mozilla/5.0 Chrome/149.0']);
    check($customer->isRedirect(), 'el formulario habitual del cliente crea el carrito');
    same($before + 1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
});
