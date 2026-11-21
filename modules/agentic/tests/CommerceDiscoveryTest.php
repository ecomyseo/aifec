<?php
/** Prueba el descubrimiento de la tienda raíz y el transporte UCP MCP sobre el carrito nativo. */
declare(strict_types=1);

require_once __DIR__ . '/UcpKit.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';
require_once dirname(__DIR__, 3) . '/tests/Install/support.php';

test('Una instalación independiente sirve UCP y ACP desde el punto de entrada real de AIFEC', function (): void {
    $installation = [$root, $data, $public] = aifecInstEmpty();
    \Aifec\Install\SiteFactory::create($root, $data, aifecInstOptions([
        'public_dir' => $public,
        'modules' => ['catalog', 'orders', 'media', 'semantic', 'api', 'mcp', 'agentic'],
        'settings' => ['agentic.ucp_enabled' => true, 'agentic.acp_enabled' => true],
    ]));
    $app = new \Aifec\Kernel\App(\Aifec\Kernel\SiteResolver::load($data, 'main'), $root);
    $app->boot();
    $product = aifecProduct($app, ['name' => 'Silla independiente', 'price' => 1700, 'stock' => 4, 'images' => 1]);
    $profile = ucpJson(aifecInstGet($installation, '/.well-known/ucp'));
    same('https://tienda.test/ucp/v1', $profile['ucp']['services']['dev.ucp.shopping'][0]['endpoint']);
    $request = \Aifec\Kernel\Request::create('POST', '/ucp/v1/catalog/search', [], [], ['content-type' => 'application/json'], '{"query":"silla"}');
    $request->host = 'tienda.test';
    $request->secure = true;
    $catalog = ucpJson(\Aifec\Kernel\Front::run($root, $data, $public, '', $request));
    same((string) $product, $catalog['products'][0]['id']);
    $acp = ucpJson(aifecInstGet($installation, '/.well-known/acp.json'));
    same('https://tienda.test/checkout_sessions', $acp['checkout_sessions_url']);
});

test('Los documentos anuncian las puertas de compra de la propia tienda y los transportes operativos', function (): void {
    $modules = ['orders', 'agentic', 'mcp', 'semantic', 'catalog', 'media', 'cms', 'api'];
    $app = aifecOrderApp(['modules' => $modules, 'settings' => [
        'modules.active' => $modules, 'agentic.ucp_enabled' => true, 'agentic.acp_enabled' => true,
    ]]);
    $product = aifecProduct($app, ['name' => 'Silla de roble', 'price' => 12345, 'stock' => 5, 'images' => 1]);
    $profile = ucpJson(ucpCall($app, 'GET', '/.well-known/ucp'));
    $bindings = array_column($profile['ucp']['services']['dev.ucp.shopping'], 'endpoint', 'transport');
    same('https://tienda.test/ucp/v1', $bindings['rest']);
    same('https://tienda.test/ucp/mcp', $bindings['mcp']);
    same(200, ucpCall($app, 'GET', '/ucp/v1')->status);
    $olderUrl = $profile['ucp']['supported_versions']['2026-04-08'];
    same('https://tienda.test/.well-known/ucp/2026-04-08', $olderUrl);
    $older = ucpJson(ucpCall($app, 'GET', (string) parse_url($olderUrl, PHP_URL_PATH)));
    check(!isset($older['ucp']['supported_versions']), 'cada perfil histórico es independiente');

    $gates = ucpJson(ucpCall($app, 'GET', '/.well-known/aifec.json'))['gates'];
    same($bindings['rest'], $gates['ucp']['rest']);
    same('https://tienda.test/checkout_sessions', $gates['acp']['checkout_sessions']);
    $llms = ucpCall($app, 'GET', '/llms.txt')->body;
    contains($gates['ucp']['catalog_search'], $llms);
    contains($gates['acp']['checkout_sessions'], $llms);
    contains($gates['acp']['feed'], $llms);

    $rpc = static function (string $method, array $params = [], array $headers = []) use ($app): array {
        $response = ucpCall($app, 'POST', '/ucp/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params], $headers);
        same(200, $response->status, 'MCP responde a ' . $method);
        return ucpJson($response)['result'];
    };
    $call = static function (string $name, array $arguments, array $headers = []) use ($rpc): array {
        $arguments += ['meta' => ['ucp-agent' => ['profile' => 'https://plataforma.test/profile']]];
        return $rpc('tools/call', ['name' => $name, 'arguments' => $arguments], $headers);
    };
    same('aifec-ucp', $rpc('initialize')['serverInfo']['name']);
    check(in_array('create_checkout', array_column($rpc('tools/list')['tools'], 'name'), true));
    $native = ucpJson(ucpCall($app, 'POST', '/ucp/v1/catalog/search', ['query' => 'silla']));
    same((string) $product, $native['products'][0]['id']);
    same($native, $call('search_catalog', ['catalog' => ['query' => 'silla']])['structuredContent']);
    $item = ['item' => ['id' => (string) $product], 'quantity' => 1];
    $key = ['authorization' => 'Bearer ' . ucpKey($app)];
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {carts}');
    $blocked = $call('create_cart', ['cart' => ['line_items' => [$item]]], ['user-agent' => 'Mozilla/5.0']);
    same(true, $blocked['isError']);
    same('browser_blocked', $blocked['structuredContent']['messages'][0]['code']);
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    $cart = $call('create_cart', ['cart' => ['line_items' => [$item]]], $key)['structuredContent'];
    same(1, $cart['line_items'][0]['quantity']);
    same($cart['id'], ucpJson(ucpCall($app, 'GET', '/ucp/v1/carts/' . $cart['id'], null, $key))['id']);
    $item['quantity'] = 3;
    $updated = $call('update_cart', ['id' => $cart['id'], 'cart' => ['line_items' => [$item]]], $key)['structuredContent'];
    same(3, $updated['line_items'][0]['quantity']);
    $checkout = $call('create_checkout', ['checkout' => ['cart_id' => $cart['id']]], $key)['structuredContent'];
    same($cart['id'], $checkout['id']);
    same(3, $checkout['line_items'][0]['quantity']);
    same($before + 1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    contains('https://tienda.test/ucp/continue/', $checkout['continue_url']);
    $canceled = $call('cancel_checkout', ['id' => $checkout['id'], 'meta' => ['idempotency-key' => 'cancel-demo-checkout']], $key);
    same('canceled', $canceled['structuredContent']['status']);
});

test('La elegibilidad del catálogo OpenAI requiere ACP activo, alta comercial y dos páginas públicas', function (): void {
    $modules = ['agentic', 'orders', 'catalog', 'media', 'cms', 'semantic'];
    $app = aifecOrderApp(['modules' => $modules, 'settings' => ['modules.active' => $modules, 'agentic.acp_enabled' => true]]);
    $terms = cmsInsertPage($app, ['title' => 'Condiciones', 'slug' => 'condiciones']);
    $privacy = cmsInsertPage($app, ['title' => 'Privacidad', 'slug' => 'privacidad']);
    $app->settings()->setMany(['orders.terms_page_id' => $terms, 'agentic.privacy_page_id' => $privacy]);
    aifecProduct($app, ['name' => 'Silla', 'price' => 1200, 'images' => 1, 'stock' => 5]);
    $feed = static function () use ($app): array {
        $response = ucpCall($app, 'GET', '/acp/feed.jsonl');
        $response->drain();
        return json_decode(trim($response->body), true, 512, JSON_THROW_ON_ERROR);
    };
    same(false, $feed()['is_eligible_checkout']);
    $app->settings()->set('semantic.openai.enable_checkout', true);
    $eligible = $feed();
    same(true, $eligible['is_eligible_checkout']);
    contains('/condiciones', $eligible['seller_tos']);
    contains('/privacidad', $eligible['seller_privacy_policy']);
    $app->settings()->set('agentic.privacy_page_id', 0);
    same(false, $feed()['is_eligible_checkout']);
});
