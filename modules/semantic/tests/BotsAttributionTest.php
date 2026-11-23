<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Semantic\BotsReport;

require_once __DIR__ . '/../../orders/tests/FrontKit.php';

/*
 * De dónde viene el pedido: una visita llegada desde un asistente deja su marca en la sesión y el pedido la copia.
 */

/** Una tienda con pedidos, el módulo semántico y un producto, y un navegador de mentira. @return array{0:App,1:TestClient,2:int} */
function botsOrderShop(): array
{
    $app = frontShop(['modules' => ['semantic']]);
    $product = frontProduct($app);

    return [$app, new TestClient($app), $product];
}

/** @return array<int,array<string,mixed>> los datos de las sesiones de clientes que hay en la base */
function botsCustomerSessions(App $app): array
{
    $out = [];
    foreach ($app->db()->select("SELECT data FROM {sessions} WHERE guard = 'customer'") as $row) {
        $data = json_decode((string) $row['data'], true);
        $out[] = is_array($data) ? $data : [];
    }

    return $out;
}

test('una visita que llega de ChatGPT deja la atribución en la sesión y el pedido hecho después la lleva', function (): void {
    [$app, $web, $product] = botsOrderShop();

    same(200, $web->get('/?utm_source=chatgpt.com')->status);
    $sessions = botsCustomerSessions($app);
    same(1, count($sessions), 'solo en este caso se crea sesión');
    same(['source' => 'ChatGPT', 'landing' => '/', 'at' => '2026-11-12 12:00:00'], $sessions[0]['attribution']);

    frontAdd($web, $product, 1);
    $r = $web->post('/finalizar-compra/place', frontForm($web, frontFill()));
    same(303, $r->status);
    $order = $app->service('orders')->find(1);
    same(['source' => 'ChatGPT', 'landing' => '/', 'at' => '2026-11-12 12:00:00'], $order['attribution']);
    same('web', $order['source']);
});

test('también se reconoce por la página de origen, y la última visita de un asistente sustituye a la anterior', function (): void {
    [$app, $web] = botsOrderShop();

    $web->get('/?utm_source=chatgpt.com');
    $web->get('/', ['referer' => 'https://www.perplexity.ai/search?q=silla']);
    $sessions = botsCustomerSessions($app);
    same(1, count($sessions), 'la misma sesión');
    same('Perplexity', $sessions[0]['attribution']['source']);
    same('/', $sessions[0]['attribution']['landing']);
});

test('quien llega desde un asistente a una página que ya está en la caché no recibe la copia: la aplicación arranca y apunta de dónde viene', function (): void {
    [$app, $web] = botsOrderShop();
    same(200, $web->get('/')->status);
    // Lo que haría el servidor ANTES de arrancar la aplicación: servir la copia guardada, si la petición puede salir de ella.
    $copy = static function (string $uri, array $headers = []) use ($app): ?\Aifec\Kernel\Response {
        $request = \Aifec\Kernel\Request::create('GET', $uri, [], [], $headers + ['user-agent' => 'Mozilla/5.0']);
        $request->host = 'tienda.test';
        $request->secure = true;

        return \Aifec\Kernel\PageCache::serve($app->site(), $request, $app->clock());
    };
    check($copy('/') !== null, 'la portada está en la caché');
    check($copy('/?utm_source=boletin&utm_campaign=otono') !== null, 'un parámetro de seguimiento cualquiera sale de la misma copia');
    check($copy('/', ['referer' => 'https://www.google.com/search?q=silla']) !== null, 'y quien viene de un buscador');

    same(null, $copy('/?utm_source=chatgpt.com'), 'desde un asistente (utm_source), no hay copia: arranca la aplicación');
    same(null, $copy('/', ['referer' => 'https://www.perplexity.ai/search?q=silla']), 'desde un asistente (página de origen), tampoco');
    // Un robot tiene su propia copia (por clase de robot: según los ajustes recibe el texto o el HTML), que se guarda con su primera visita.
    $web->get('/', ['user-agent' => 'GPTBot/1.1']);
    check($copy('/?utm_source=chatgpt.com', ['user-agent' => 'GPTBot/1.1']) !== null, 'un robot sí recibe la copia: no compra');

    // Y al arrancar, la marca queda puesta aunque la página estuviera guardada.
    same([], botsCustomerSessions($app));
    same(200, $web->get('/?utm_source=chatgpt.com')->status);
    same('ChatGPT', botsCustomerSessions($app)[0]['attribution']['source']);
    // La visita del asistente no ha pisado la copia de los demás con una página que lleva sesión.
    check($copy('/') !== null, 'la copia de la portada sigue ahí');
    notContains('af_c_', implode(' ', array_keys((array) $copy('/')->headers)) . json_encode($copy('/')->headers));
});

test('una visita normal no crea sesión ni cookie', function (): void {
    [$app, $web] = botsOrderShop();

    $web->get('/');
    $web->get('/?utm_source=boletin');
    $web->get('/', ['referer' => 'https://www.google.com/search?q=silla']);
    same([], botsCustomerSessions($app));
    same([], array_filter(array_keys($web->cookies), static function (string $name): bool {
        return strpos($name, 'af_c_') === 0;
    }), 'ninguna cookie de cliente');
});

test('los robots, las versiones en texto y el panel no dejan marca aunque lleven el origen de un asistente', function (): void {
    [$app, $web] = botsOrderShop();

    $web->get('/?utm_source=chatgpt.com', ['user-agent' => 'GPTBot/1.1']);
    $web->get('/index.md?utm_source=chatgpt.com');
    $web->get('/llms.txt?utm_source=chatgpt.com');
    $web->get('/feeds/google.xml?utm_source=chatgpt.com');
    $web->get('/admin/login?utm_source=chatgpt.com');
    same([], botsCustomerSessions($app));
});

test('la atribución sale en el panel y en la pantalla de robots con los pedidos y el importe de cada asistente', function (): void {
    [$app, $web, $product] = botsOrderShop();
    $web->get('/?utm_source=chatgpt.com');
    frontAdd($web, $product, 1);
    same(303, $web->post('/finalizar-compra/place', frontForm($web, frontFill()))->status);
    $total = (int) $app->service('orders')->find(1)['total'];
    check($total > 0, 'el pedido tiene importe');

    // El pedido se hizo con la pasarela de pruebas y sin cobrar: se da por vendido de verdad.
    $now = $app->clock()->sql();
    same(0, (int) $app->service('orders')->find(1)['paid_total'], 'todavía sin cobrar');
    $app->db()->run("UPDATE {orders} SET test = 0, payment_status = 'paid' WHERE id = 1");
    // Uno de prueba, uno cancelado, uno que espera el pago y uno de otro origen no cuentan como ventas de ChatGPT.
    $app->db()->run(
        "INSERT INTO {orders} (number, email, total, status, payment_status, placed_at, created_at, updated_at, test, attribution, secret) VALUES ('P-1', 'x@example.com', 666, 'pending_payment', 'pending', ?, ?, ?, 0, ?, 's4')",
        [$now, $now, $now, json_encode(['source' => 'ChatGPT'])]
    );
    $app->db()->run(
        "INSERT INTO {orders} (number, email, total, status, payment_status, placed_at, created_at, updated_at, test, attribution, secret) VALUES ('T-1', 'x@example.com', 999, 'completed', 'paid', ?, ?, ?, 1, ?, 's1')",
        [$now, $now, $now, json_encode(['source' => 'ChatGPT'])]
    );
    $app->db()->run(
        "INSERT INTO {orders} (number, email, total, status, payment_status, placed_at, cancelled_at, created_at, updated_at, test, attribution, secret) VALUES ('C-1', 'x@example.com', 888, 'cancelled', 'cancelled', ?, ?, ?, ?, 0, ?, 's2')",
        [$now, $now, $now, $now, json_encode(['source' => 'ChatGPT'])]
    );
    $app->db()->run(
        "INSERT INTO {orders} (number, email, total, status, payment_status, placed_at, created_at, updated_at, test, attribution, secret) VALUES ('B-1', 'x@example.com', 777, 'completed', 'paid', ?, ?, ?, 0, ?, 's3')",
        [$now, $now, $now, json_encode(['utm_source' => 'boletin'])]
    );
    botsVisitFor($app, 'Perplexity');
    botsVisitFor($app, 'ChatGPT');
    botsVisitFor($app, 'ChatGPT');

    \Aifec\Modules\Semantic\Bots::ingest($app);
    $report = BotsReport::build($app);
    $sources = [];
    foreach ($report['sources'] as $source) {
        $sources[$source['name']] = $source;
    }
    same(2, $sources['ChatGPT']['visits']);
    same(1, $sources['ChatGPT']['orders'], 'solo el pedido de verdad');
    same($total, $sources['ChatGPT']['amount']);
    same([1, 0, 0], [$sources['Perplexity']['visits'], $sources['Perplexity']['orders'], $sources['Perplexity']['amount']]);
    check(!isset($sources['boletin']), 'otros orígenes no son asistentes');

    $page = adminClient($app)->get('/admin/semantic/bots')->body;
    contains('ChatGPT', $page);
    contains($app->i18n()->money($total, 'EUR'), $page);
});

/** Apunta una visita llegada desde un asistente, tal como lo hace el núcleo. */
function botsVisitFor(App $app, string $assistant): void
{
    $domains = ['ChatGPT' => 'chatgpt.com', 'Perplexity' => 'perplexity.ai'];
    $request = \Aifec\Kernel\Request::create('GET', '/', [], [], ['user-agent' => 'Mozilla/5.0', 'referer' => 'https://' . $domains[$assistant] . '/']);
    \Aifec\Kernel\VisitLog::record($app->site(), $request, 200, false, new \Aifec\Kernel\Clock($app->clock()->now()));
}
