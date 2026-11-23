<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Comprar con enlaces: la compra entera solo con peticiones GET, para los asistentes que solo saben abrir direcciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../orders/tests/FrontKit.php';

/**
 * Abre una dirección de las que da la tienda (con su dominio), como lo haría un asistente: un GET sin cabeceras ni cuerpo.
 *
 * @return array{0:int,1:array<string,mixed>,2:\Aifec\Kernel\Response}
 */
function linksOpen(\Aifec\Kernel\App $app, string $url, bool $json = true): array
{
    $ruta = (string) preg_replace('#^https?://[^/]+#', '', $url);
    $request = \Aifec\Kernel\Request::create('GET', $ruta, [], [], $json ? ['accept' => 'application/json'] : []);
    $request->host = 'tienda.test';
    $request->secure = true;
    $request->ip = '127.0.0.1';
    $r = $app->handle($request);
    $data = json_decode($r->body, true);

    return [$r->status, is_array($data) ? $data : [], $r];
}

/**
 * La dirección del paso siguiente cuyo texto contiene $trozo, tal como la da la respuesta.
 *
 * @param array<string,mixed> $answer
 */
function linksNext(array $answer, string $trozo): string
{
    foreach (isset($answer['next']) && is_array($answer['next']) ? $answer['next'] : [] as $link) {
        if (strpos((string) $link['url'], $trozo) !== false) {
            return (string) $link['url'];
        }
    }
    check(false, 'la respuesta trae un enlace con «' . $trozo . '»: ' . json_encode(isset($answer['next']) ? $answer['next'] : null));

    return '';
}

/** El código que la tienda ha mandado por correo (su asunto empieza por él). */
function linksCode(\Aifec\Kernel\App $app): string
{
    $subject = (string) $app->db()->value("SELECT subject FROM {mail_messages} WHERE template = 'buyer_code' ORDER BY id DESC");
    preg_match('/^([0-9]{6}) /', $subject, $m);

    return isset($m[1]) ? $m[1] : '';
}

test('la compra entera solo abriendo direcciones: cada respuesta trae la del paso siguiente, y el pedido solo se hace con el código del comprador', function (): void {
    $app = apiApp();
    $app->settings()->set('api.browser_agents_allowed', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36');
    $ids = assistantShop($app);

    [$status, $guide, $response] = linksOpen($app, 'https://tienda.test/api/v1/go');
    same(200, $status, $response->body);
    contains('no-store', (string) $response->headers['Cache-Control'], 'no se guarda en caché');
    contains('noindex', (string) $response->headers['X-Robots-Tag'], 'ni se indexa');
    contains('verification code', (string) json_encode($guide['data']['rules']), 'la guía avisa del código');

    [$status, $start] = linksOpen($app, linksNext($guide, '/go/start'));
    same(200, $status);
    $cart = (string) $start['data']['cart'];
    same(48, strlen($cart), 'la ficha del carrito');

    $add = str_replace('product_id=ID', 'product_id=' . $ids['silla_roja'], linksNext($start, '/go/add'));
    contains('cart=' . $cart, $add, 'la dirección ya lleva el carrito');
    [$status, $added] = linksOpen($app, $add);
    same(200, $status, json_encode($added));
    linksOpen($app, $add);
    [, $view] = linksOpen($app, linksNext($added, '/go/cart'));
    same(1, (int) $view['data']['count'], 'abrir dos veces la misma dirección no añade dos: ' . json_encode($view['data']));

    $customer = str_replace(rawurlencode('NAME, STREET AND NUMBER, POSTCODE CITY, PHONE, EMAIL'), rawurlencode(CHAT_DATA), str_replace(',', '%2C', linksNext($added, '/go/customer')));
    [$status, $saved] = linksOpen($app, $customer);
    same(200, $status, json_encode($saved));
    notContains('ana@correo.test', (string) json_encode($saved), 'los datos personales vuelven tapados');

    [, $shipping] = linksOpen($app, linksNext($saved, '/go/shipping-options'));
    [$status, $shipped] = linksOpen($app, linksNext($shipping, '/go/shipping?'));
    same(200, $status, json_encode($shipped));
    [, $payments] = linksOpen($app, linksNext($shipped, '/go/payment-options'));
    // Lo que se va a pagar, a la vista en los pasos de entrega y de pago: líneas con nombre y cantidad, y el total.
    foreach ([$shipping, $payments] as $stepAnswer) {
        $summary = $stepAnswer['data']['cart_summary'] ?? null;
        check(is_array($summary) && count($summary['lines']) >= 1, 'el paso trae cart_summary con al menos una línea: ' . json_encode($stepAnswer['data'] ?? null));
        check($summary['lines'][0]['name'] !== '' && $summary['lines'][0]['quantity'] >= 1, 'cada línea trae nombre y cantidad');
        check((int) $summary['total'] > 0 && $summary['total_formatted'] !== '' && $summary['lines'][0]['total_formatted'] !== '', 'y el total, en céntimos y con formato');
    }
    [$status, $paid] = linksOpen($app, linksNext($payments, 'method=transfer'));
    same(200, $status, json_encode($paid));

    [$status, $review] = linksOpen($app, linksNext($paid, '/go/review'));
    same(200, $status, json_encode($review));
    same(true, $review['data']['confirmation']['sent'], 'la revisión manda el código al comprador');
    contains('WAIT', $review['say'], 'y la tienda le dice al asistente que espere');
    $code = linksCode($app);
    same(6, strlen($code));
    notContains($code, (string) json_encode($review), 'el código no va en la respuesta');
    $place = linksNext($review, '/go/place');
    contains('buyer_code=CODE', $place, 'la dirección del pedido espera el código de la persona');

    [$status] = linksOpen($app, $place);
    same(400, $status, 'abrir la dirección tal cual (un rastreador, una caché) no hace nada');
    [$status] = linksOpen($app, str_replace('buyer_code=CODE', 'buyer_code=' . ($code === '000000' ? '000001' : '000000'), $place));
    same(403, $status, 'con un código inventado, tampoco');
    [$status] = linksOpen($app, str_replace(['buyer_code=CODE', 'buyer_confirmed=true'], ['buyer_code=' . $code, 'buyer_confirmed=no'], $place));
    same(400, $status, 'sin la declaración de la persona, tampoco');
    same(0, count(apiOrders($app)));

    [$status, $order] = linksOpen($app, str_replace('buyer_code=CODE', 'buyer_code=' . $code, $place));
    same(200, $status, json_encode($order));
    check((string) $order['data']['number'] !== '', 'la respuesta trae el número de pedido');
    $orders = apiOrders($app);
    same(1, count($orders));
    same('buyer_code', json_decode((string) $orders[0]['attribution'], true)['confirmed_by']);
    [$status, $again] = linksOpen($app, str_replace('buyer_code=CODE', 'buyer_code=' . $code, $place));
    same(200, $status, 'abrir otra vez la dirección del pedido devuelve ESE pedido: quien perdió la primera respuesta no se queda sin número');
    same((string) $order['data']['number'], (string) $again['data']['number']);
    same(true, $again['data']['already_placed']);
    contains('/', (string) $again['data']['order_page'], 'con la página del pedido');
    same(200, (new TestClient($app))->get((string) preg_replace('#^https?://[^/]+#', '', (string) $again['data']['order_page']))->status, 'que abre');
    same(1, count(apiOrders($app)), 'y no hace otro');
    [$status, $cart] = linksOpen($app, 'https://tienda.test/api/v1/go/cart?cart=' . $start['data']['cart']);
    same(200, $status, 'mirar el carrito después dice lo mismo');
    same((string) $order['data']['number'], (string) $cart['data']['number']);

    // Horas después, esa dirección es de OTRA conversación (17/11/2026: Claude, con memoria, reabría carritos de la víspera y
    // daba aquel pedido como el de ahora): no se da el pedido, se dice que es viejo y se ofrece comprar lo mismo de nuevo.
    // La PERSONA que abre (enseguida) una dirección de ese carrito ya pedido ve qué pedido es y puede volver a comprar, no
    // la página para asistentes (17/11/2026: Claude le dio de memoria la dirección de los datos de un carrito ya pedido).
    $personNow = apiCall($app, 'GET', '/buy-with-links/customer/' . $start['data']['cart'], null,
        ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36', 'accept' => 'text/html']);
    same(200, $personNow->status);
    contains('Este carrito ya es un pedido', $personNow->body);
    contains((string) $order['data']['number'], $personNow->body, 'con su número');
    contains('/buy-with-links/new/', $personNow->body, 'y volver a comprar lo mismo, en un carrito nuevo');
    $app->clock()->set($app->clock()->now() + 3 * 3600);
    [$status, $old] = linksOpen($app, 'https://tienda.test/api/v1/go/cart?cart=' . $start['data']['cart']);
    same(200, $status, json_encode($old));
    same(true, $old['data']['old_cart'] ?? null, 'es un carrito viejo');
    check(!isset($old['data']['number']), 'y no se da su pedido como el de ahora');
    contains('not what the person is buying now', (string) $old['say']);
    $again = linksNext($old, '/buy-with-links/new/');
    [, $oldTwice] = linksOpen($app, 'https://tienda.test/api/v1/go/cart?cart=' . $start['data']['cart']);
    check($again !== linksNext($oldTwice, '/buy-with-links/new/'), 'cada vez una dirección nueva: la caché del lector no puede devolver otra vez el carrito viejo');
    [, $fresh] = linksOpen($app, $again);
    same(true, !empty($fresh['data']['ok']), 'la dirección de volver a comprar crea un carrito nuevo con ese producto: ' . json_encode($fresh['data']));
    check((string) $fresh['data']['cart'] !== (string) $start['data']['cart'], 'otro carrito');
    // La PERSONA que abre una dirección de ese carrito viejo (su asistente se la dio de memoria) va a un carrito nuevo, no a la
    // página para asistentes: con lo que pedía, si la dirección es de añadir, o con lo de aquel pedido.
    $person = ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36', 'accept' => 'text/html'];
    $oldAdd = apiCall($app, 'GET', '/buy-with-links/add/' . $start['data']['cart'] . '/' . $ids['mesa'] . '/1', null, $person);
    same(302, $oldAdd->status, $oldAdd->body);
    contains('/buy-with-links/new/' . $ids['mesa'], (string) $oldAdd->headers['Location'], 'lo que pedía, en un carrito nuevo');
    $oldStatus = apiCall($app, 'GET', '/buy-with-links/status/' . $start['data']['cart'], null, $person);
    same(302, $oldStatus->status);
    contains('/buy-with-links/new/', (string) $oldStatus->headers['Location'], 'o lo de aquel pedido');
    // Y la caja de ese carrito viejo ya no lleva a aquel pedido (salía «cancelado»): vuelve a comprar lo mismo, en otro carrito.
    $resume = (new TestClient($app))->get('/carrito/retomar/' . $start['data']['cart']);
    same(302, $resume->status);
    contains('/buy-with-links/new/', (string) $resume->headers['Location'], 'a comprar lo mismo de nuevo');
    [$status] = linksOpen($app, str_replace('buyer_code=CODE', 'buyer_code=999999', $place));
    same(200, $status, 'con el pedido ya hecho el código ya no pinta nada: no se puede hacer otro');
    same(1, count(apiOrders($app)));
});

test('los enlaces no se saltan nada: sin carrito, con un carrito inventado, un paso que no existe, un número mal escrito y con el ajuste apagado', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    [$status, $none] = linksOpen($app, 'https://tienda.test/api/v1/go/cart');
    same(401, $status);
    contains('/go/start', $none['error']['message'], 'dice por dónde se empieza');
    [$status] = linksOpen($app, 'https://tienda.test/api/v1/go/cart?cart=' . str_repeat('a', 48));
    same(401, $status, 'un carrito que no existe');
    [, $start] = linksOpen($app, 'https://tienda.test/api/v1/go/start');
    $cart = (string) $start['data']['cart'];
    [$status] = linksOpen($app, 'https://tienda.test/api/v1/go/borrar-todo?cart=' . $cart);
    same(404, $status, 'un paso que no existe');
    [$status] = linksOpen($app, 'https://tienda.test/api/v1/go/add?cart=' . $cart . '&product_id=' . $ids['silla_roja'] . '&qty=dos');
    same(400, $status, 'la cantidad tiene que ser un número');
    [$status] = linksOpen($app, 'https://tienda.test/api/v1/go/add?cart=' . $cart . '&product_id=999999&qty=1');
    same(422, $status, 'un producto que no existe');
    [$status] = linksOpen($app, 'https://tienda.test/api/v1/go/review?cart=' . $cart);
    same(422, $status, 'revisar un carrito vacío');

    $off = apiApp(['api.links' => false]);
    assistantShop($off);
    same(404, linksOpen($off, 'https://tienda.test/api/v1/go')[0], 'apagado, la guía no existe');
    same(404, linksOpen($off, 'https://tienda.test/api/v1/go/start')[0], 'ni se puede empezar');
    same(201, apiCall($off, 'POST', '/api/v1/carts')->status, 'y la API de siempre sigue funcionando');
});

test('con «solo con clave» o apagado, los enlaces llegan hasta la revisión pero no hacen pedidos', function (): void {
    foreach (['key' => 401, 'off' => 403] as $mode => $expected) {
        $app = apiApp(['api.agent_orders' => $mode]);
        $ids = assistantShop($app);
        $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
        $review = apiBuy($app, $token);
        [$status, $viaLink] = linksOpen($app, 'https://tienda.test/api/v1/go/review?cart=' . $token);
        same(200, $status);
        check(!isset($viaLink['data']['confirmation']), $mode . ': no se manda ningún código');
        [$status] = linksOpen($app, 'https://tienda.test/api/v1/go/place?cart=' . $token . '&confirm_token=' . $review['confirm_token'] . '&buyer_confirmed=true&buyer_code=123456');
        same($expected, $status, $mode);
        same(0, count(apiOrders($app)), $mode);
    }
});

test('el documento OpenAPI y el llms.txt cuentan la compra con enlaces', function (): void {
    $app = apiApp();
    assistantShop($app);
    $doc = apiJson(apiCall($app, 'GET', '/api/openapi.json'));
    check(isset($doc['paths']['/go']['get']), 'la guía está en el documento');
    check(isset($doc['paths']['/go/{step}']['get']), 'y los pasos');
    check(!isset($doc['paths']['/go/{step}']['get']['security']), 'no piden cabeceras: la ficha va en la dirección');
    contains('/go/start', (string) $doc['info']['description'], 'la descripción dice cómo se empieza');
});

test('sin pedir JSON, cada paso es una página de texto que un lector de páginas sabe abrir: con lo que hay que hacer, la respuesta y las direcciones siguientes', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    [$status, , $response] = linksOpen($app, 'https://tienda.test/api/v1/go', false);
    same(200, $status);
    same('text/plain; charset=UTF-8', (string) $response->headers['Content-Type'], 'ni application/json ni text/markdown: hay lectores de páginas que no los abren');
    contains('https://tienda.test/api/v1/go/start', $response->body, 'trae la dirección por la que se empieza, entera');
    contains('noindex', (string) $response->headers['X-Robots-Tag']);

    [, , $start] = linksOpen($app, 'https://tienda.test/api/v1/go/start', false);
    check(preg_match('#"cart": "([a-f0-9]{48})"#', $start->body, $m) === 1, 'la página enseña la ficha del carrito');
    $cart = $m[1];
    contains('/go/add?cart=' . $cart, $start->body, 'y la dirección del paso siguiente ya lleva el carrito');
    notContains('format=json', $start->body);

    [$status, , $added] = linksOpen($app, 'https://tienda.test/api/v1/go/add?cart=' . $cart . '&product_id=' . $ids['silla_roja'] . '&qty=1', false);
    same(200, $status);
    contains('/go/customer?cart=' . $cart, $added->body);

    [$status, , $wrong] = linksOpen($app, 'https://tienda.test/api/v1/go/add?cart=' . $cart . '&product_id=999999&qty=1', false);
    same(200, $status, 'un fallo también es una página: un lector de páginas no enseña el cuerpo de un 4xx');
    contains('did not work (status 422)', $wrong->body, 'con su motivo escrito');
    same('text/plain; charset=UTF-8', (string) $wrong->headers['Content-Type']);
    [$status, , $noCart] = linksOpen($app, 'https://tienda.test/api/v1/go/review', false);
    same(200, $status);
    contains('status 401', $noCart->body);

    [$status, $viaParam] = linksOpen($app, 'https://tienda.test/api/v1/go/start?format=json', false);
    same(200, $status);
    check(isset($viaParam['data']['cart']), 'con ?format=json la respuesta es JSON');
    contains('format=json', (string) $viaParam['next'][0]['url'], 'y las direcciones siguientes lo conservan');
    same(422, linksOpen($app, 'https://tienda.test/api/v1/go/review?format=json&cart=' . $viaParam['data']['cart'], false)[0], 'en JSON los fallos conservan su estado HTTP');
});

test('la compra con enlaces existe también fuera de /api/, y por esa entrada las direcciones siguientes siguen fuera de /api/', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    [$status, , $guide] = linksOpen($app, 'https://tienda.test/buy-with-links', false);
    same(200, $status);
    contains('text/html', (string) $guide->headers['Content-Type'], 'por aquí la página es HTML: hay lectores que no abren otra cosa');
    contains('<a href="https://tienda.test/buy-with-links/start">', $guide->body, 'con enlaces de verdad');
    contains('<!doctype html>', $guide->body);
    [$status, , $start] = linksOpen($app, 'https://tienda.test/buy-with-links/start', false);
    same(200, $status);
    check(preg_match('#"cart": "([a-f0-9]{48})"#', html_entity_decode($start->body, ENT_QUOTES), $m) === 1, 'da un carrito');
    contains('<a href="https://tienda.test/buy-with-links/add/' . $m[1] . '/ID/1">', $start->body, 'las direcciones siguientes son rutas limpias, sin «&»');
    notContains('&amp;', $start->body, 'ninguna dirección lleva «&»: hay lectores que la rechazan si no es idéntica al HTML');
    notContains('/api/v1/go/', $start->body, 'ninguna dirección siguiente vuelve a /api/');
    [$status, , $added] = linksOpen($app, 'https://tienda.test/buy-with-links/add/' . $m[1] . '/' . $ids['silla_roja'] . '/1', false);
    same(200, $status);
    contains('/buy-with-links/customer/' . $m[1] . '?text=', $added->body, 'los datos del comprador van en un solo parámetro');
    notContains('&amp;', $added->body);
    $doc = apiJson(apiCall($app, 'GET', '/api/openapi.json'));
    check(!isset($doc['paths']['/buy-with-links']), 'el documento OpenAPI es de /api/v1: la entrada de fuera no sale ahí');
    same(404, linksOpen(apiApp(['api.links' => false]), 'https://tienda.test/buy-with-links/start')[0], 'apagada, tampoco existe por aquí');
});

test('el robots.txt de una tienda cerrada a los buscadores deja pasar a la compra con enlaces, y solo a ella', function (): void {
    $closed = apiApp(['seo.noindex_site' => true], ['seo']);
    assistantShop($closed);
    $robots = \Aifec\Modules\Seo\Robots::build($closed);
    check(strpos($robots, "User-agent: *\nAllow: /buy-with-links\nAllow: /*buy-with-links\nDisallow: /\n") === 0, 'los lectores de páginas de los asistentes obedecen este fichero: ' . $robots);
    $off = apiApp(['seo.noindex_site' => true, 'api.links' => false], ['seo']);
    assistantShop($off);
    check(strpos(\Aifec\Modules\Seo\Robots::build($off), "User-agent: *\nDisallow: /\n") === 0, 'con la compra con enlaces apagada, la regla general no abre nada');
});

test('una tienda cerrada a los buscadores no está cerrada al lector que abre una página para una persona: sin eso Claude no puede leer ni la ficha', function (): void {
    // 16/11/2026: en la tienda de pruebas (cerrada a los buscadores) Claude contestó «la lectura directa está bloqueada por su
    // robots.txt» a una clienta que le pedía un producto. Su lector (Claude-User) obedece el fichero, y decía «Disallow: /».
    $closed = apiApp(['seo.noindex_site' => true], ['seo']);
    assistantShop($closed);
    $robots = \Aifec\Modules\Seo\Robots::build($closed);

    // El grupo propio de los lectores «para una persona», después de la regla general.
    $own = (string) substr($robots, (int) strpos($robots, 'User-agent: ChatGPT-User'));
    foreach (['ChatGPT-User', 'Claude-User', 'Perplexity-User'] as $agent) {
        contains('User-agent: ' . $agent . "\n", $own);
    }
    contains("\nAllow: /\n", $own, 'pueden leer la tienda');
    contains("\nDisallow: /admin\n", $own, 'menos las zonas privadas');
    contains("\nDisallow: /carrito\n", $own);
    // Lo que la guía les manda abrir, con «?q=» y «?text=»: Claude-User decía que el buscador estaba bloqueado (16/11/2026).
    contains("\nAllow: /api/v1/search\n", $own, 'el buscador de la API');
    contains("\nAllow: /buy-with-links\n", $own, 'la compra con enlaces');
    // Los buscadores, sus arañas de IA y los robots de entrenamiento siguen fuera: no tienen grupo propio y les vale «*».
    foreach (['Googlebot', 'GPTBot', 'ClaudeBot', 'OAI-SearchBot', 'PerplexityBot'] as $crawler) {
        notContains('User-agent: ' . $crawler, $robots);
    }
    notContains('Sitemap:', $robots, 'y no se anuncia el mapa del sitio');

    // Bloqueando los asistentes en los ajustes, tampoco entran ellos.
    $shut = apiApp(['seo.noindex_site' => true, 'seo.bots.assistant' => 'disallow'], ['seo']);
    assistantShop($shut);
    same("User-agent: *\nAllow: /buy-with-links\nAllow: /*buy-with-links\nDisallow: /\n", \Aifec\Modules\Seo\Robots::build($shut));
});

test('para el asistente que solo abre direcciones literales: una sola dirección escrita por la persona crea el carrito, añade el producto y guarda sus datos; las siguientes vienen ya hechas', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    [$status, $first] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1&text=' . rawurlencode(CHAT_DATA));
    same(200, $status, json_encode($first));
    $cart = (string) $first['data']['cart'];
    same(48, strlen($cart), 'la respuesta trae el carrito que se ha creado');
    notContains('ana@correo.test', (string) json_encode($first), 'los datos personales vuelven tapados');
    // Si la abre la persona (17/11/2026: Claude le escribió la dirección con sus datos), lo primero es para ella.
    contains('Tus datos están guardados', (string) $first['for_the_person'], json_encode($first));
    contains('/carrito/retomar/' . $cart, (string) $first['for_the_person'], 'con la caja de su carrito por si su asistente no sigue');

    // A partir de aquí no se monta ninguna dirección: cada una se abre tal como viene.
    [, $shipping] = linksOpen($app, linksNext($first, '/buy-with-links/shipping-options/' . $cart));
    same(400, linksOpen($app, 'https://tienda.test/buy-with-links/shipping/' . $cart . '/uno')[0], 'un envío que no es un número no entra');
    [, $shipped] = linksOpen($app, linksNext($shipping, '/buy-with-links/shipping/' . $cart . '/'));
    [, $payments] = linksOpen($app, linksNext($shipped, '/buy-with-links/payment-options/' . $cart));
    [, $paid] = linksOpen($app, linksNext($payments, '/buy-with-links/payment/' . $cart . '/transfer'));
    [$status, $review] = linksOpen($app, linksNext($paid, '/buy-with-links/review'));
    same(200, $status, json_encode($review));
    same(true, $review['data']['confirmation']['sent']);
    $place = linksNext($review, '/buy-with-links/place');
    contains('enter the code with links', (string) json_encode($review['next']), 'y da otra dirección para quien no puede montar la del código');
    check(substr($place, -5) === '/CODE' && strpos($place, '&') === false && strpos($place, '?') === false, 'la dirección del pedido es una ruta limpia que acaba en el código: ' . $place);
    same(400, linksOpen($app, $place)[0], 'abierta tal cual, con CODE, no hace nada');
    same(403, linksOpen($app, substr($place, 0, -4) . (linksCode($app) === '000000' ? '000001' : '000000'))[0], 'ni con un código inventado');
    same(0, count(apiOrders($app)));
    [$status, $order] = linksOpen($app, substr($place, 0, -4) . linksCode($app));
    same(200, $status, json_encode($order));
    same(1, count(apiOrders($app)));

    // La persona que abre la dirección del pedido con su código (su asistente no podía abrir la que completa, 17/11/2026) va a
    // pagar o a la página de su pedido, no a la página para asistentes; y la talla escrita entre sus datos llega a la nota.
    [, $second] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['mesa'] . '&qty=1&text=' . rawurlencode(CHAT_DATA . ', talla 39'));
    $cart2 = (string) $second['data']['cart'];
    contains('talla 39', (string) $app->db()->value('SELECT checkout FROM {carts} WHERE token = ?', [hash('sha256', $cart2)]), 'la talla, en la nota del carrito');
    [, $ship2] = linksOpen($app, linksNext($second, '/buy-with-links/shipping-options/' . $cart2));
    [, $shipped2] = linksOpen($app, linksNext($ship2, '/buy-with-links/shipping/' . $cart2 . '/'));
    [, $pays2] = linksOpen($app, linksNext($shipped2, '/buy-with-links/payment-options/' . $cart2));
    [, $paid2] = linksOpen($app, linksNext($pays2, '/buy-with-links/payment/' . $cart2 . '/transfer'));
    [, $review2] = linksOpen($app, linksNext($paid2, '/buy-with-links/review'));
    // Al lector de una IA de chat, la revisión le da solo el código cifra a cifra: la del «CODE» la completaba, su herramienta
    // la rechazaba y la persona tenía que abrirla (17/11/2026, R40).
    $readerReview = apiCall($app, 'GET', '/buy-with-links/review/' . $cart2, null,
        ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0; +Claude-User@anthropic.com', 'accept' => 'text/html']);
    notContains('/CODE', $readerReview->body, 'sin la dirección que hay que completar');
    contains('/buy-with-links/code/' . $cart2 . '/', $readerReview->body, 'con la de las cifras');
    notContains('/carrito/retomar/', $readerReview->body, 'ni la caja');
    $place2 = (string) preg_replace('#^https?://[^/]+#', '', substr(linksNext($review2, '/buy-with-links/place'), 0, -4) . linksCode($app));
    $app->settings()->set('api.browser_agents_allowed', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36');
    $byPerson = apiCall($app, 'GET', $place2, null, ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36', 'accept' => 'text/html']);
    same(302, $byPerson->status, 'la persona va a su pedido: ' . substr($byPerson->body, 0, 300));
    contains('/pedido/', (string) $byPerson->headers['Location'], 'a la página de su pedido (o a pagar)');
    same(2, count(apiOrders($app)), 'y el pedido está hecho');

    [$status, $noProduct] = linksOpen($app, 'https://tienda.test/buy-with-links/add?qty=1');
    // Sin producto no se añade nada: la respuesta lleva al catálogo por enlaces (17/11/2026: era un 422 sin salida).
    same(false, $noProduct['data']['added'] ?? null, 'sin producto no hay nada que añadir: ' . json_encode($noProduct));
    same(401, linksOpen($app, 'https://tienda.test/buy-with-links/customer?text=x')[0], 'solo «add» crea el carrito: los demás pasos lo piden');
});

test('para el lector que no monta direcciones: el código se teclea con enlaces, un dígito por página, y la dirección del sexto ya es la del pedido', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    [, $first] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1&text=' . rawurlencode(CHAT_DATA));
    $cart = (string) $first['data']['cart'];
    [, $shipping] = linksOpen($app, linksNext($first, '/buy-with-links/shipping-options/' . $cart));
    [, $shipped] = linksOpen($app, linksNext($shipping, '/buy-with-links/shipping/' . $cart . '/'));
    [, $payments] = linksOpen($app, linksNext($shipped, '/buy-with-links/payment-options/' . $cart));
    [, $paid] = linksOpen($app, linksNext($payments, '/buy-with-links/payment/' . $cart . '/transfer'));
    [, $review] = linksOpen($app, linksNext($paid, '/buy-with-links/review'));
    $code = linksCode($app);
    same(6, strlen($code), 'la revisión ha mandado el código');
    $pad = linksNext($review, '/buy-with-links/code/' . $cart . '/');
    check(strpos($pad, '&') === false && strpos($pad, '?') === false, 'el teclado es una ruta limpia: ' . $pad);
    $sent = static function () use ($app): int {
        return (int) $app->db()->value("SELECT COUNT(*) FROM {mail_messages} WHERE template = 'buyer_code'");
    };
    $mails = $sent();

    // El teclado no adivina ni deja pasar direcciones a medias.
    same(400, linksOpen($app, $pad . '/123456')[0], 'seis dígitos ya no son del teclado: la dirección del sexto es la del pedido');
    same(400, linksOpen($app, $pad . '/1a')[0], 'solo dígitos');
    same(400, linksOpen($app, 'https://tienda.test/buy-with-links/code/' . $cart)[0], 'sin la ficha de la revisión no hay teclado');

    $url = $pad;
    for ($i = 0; $i < 6; ++$i) {
        [$status, $page, $raw] = linksOpen($app, $url);
        same(200, $status, json_encode($page));
        same(10, count($page['next']), 'diez direcciones, una por dígito');
        // Una página pequeña: el lector de Gemini cortaba la de cien direcciones (17 KB) en la número 36 y no veía el resto.
        check(strlen((string) $raw->body) < 4000, 'la página del teclado cabe entera en un lector que recorta: ' . strlen((string) $raw->body) . ' bytes');
        same(substr($code, 0, $i), (string) $page['data']['typed'], 'lo tecleado hasta ahora');
        same(6 - $i, (int) $page['data']['digits_left']);
        contains('digit ' . ($i + 1) . ' of 6', (string) $page['say'], 'dice qué dígito toca');
        $link = $page['next'][(int) $code[$i]];
        contains('Digit ' . ($i + 1) . ' is ' . $code[$i], (string) $link['do'], 'cada dirección dice su dígito');
        $url = (string) $link['url'];
        check(substr($url, -($i + 2)) === '/' . substr($code, 0, $i + 1), 'la dirección del dígito lleva lo tecleado: ' . $url);
        check(strpos($url, $i < 5 ? '/buy-with-links/code/' : '/buy-with-links/place/') !== false, 'las cinco primeras siguen en el teclado y la sexta es la del pedido: ' . $url);
        check(strpos($url, '&') === false && strpos($url, '?') === false, 'sin «&» ni «?»: ' . $url);
        same(0, count(apiOrders($app)), 'teclear no hace ningún pedido');
    }
    same($mails, $sent(), 'ni manda más códigos');
    // La página de texto (la que lee un asistente) del teclado también es pequeña.
    [, , $text] = linksOpen($app, $pad, false);
    check(strlen((string) $text->body) < 6000, 'la página de texto del teclado es pequeña: ' . strlen((string) $text->body) . ' bytes');
    for ($digit = 0; $digit <= 9; ++$digit) {
        contains($pad . '/' . $digit, (string) $text->body, 'la dirección del dígito ' . $digit . ' va escrita en la página');
    }

    // Un dígito mal tecleado al final: la tienda no hace el pedido.
    $wrong = substr($url, 0, -1) . ((int) substr($url, -1) === 9 ? '0' : (string) ((int) substr($url, -1) + 1));
    same(403, linksOpen($app, $wrong)[0], 'con un dígito cambiado no hay pedido');
    same(0, count(apiOrders($app)));

    [$status, $order] = linksOpen($app, $url);
    same(200, $status, json_encode($order));
    same(1, count(apiOrders($app)));
    check((string) $order['data']['number'] !== '', 'la respuesta trae el número de pedido');

    // Como página de texto (lo que lee el asistente): las diez direcciones van escritas y la guía cuenta el paso.
    [, , $guide] = linksOpen($app, 'https://tienda.test/buy-with-links', false);
    contains('one digit per page', $guide->body);
    contains('Write that address in your answer to the person', $guide->body, 'y pide al asistente que guarde la dirección del teclado');
    // La revisión también se lo dice, junto a la dirección: ChatGPT la perdía de un turno a otro (16/11/2026).
    $padEntry = null;
    foreach ($review['next'] as $entry) {
        if (strpos((string) $entry['url'], '/buy-with-links/code/') !== false) {
            $padEntry = $entry;
        }
    }
    check($padEntry !== null, 'la revisión da la dirección del teclado');
    contains('in the answer to the person now, when asking for the code', (string) $padEntry['do']);
});

/*
 * Lo que salió de probar con clientes de verdad hablando con ChatGPT, Claude, Gemini y Grok (16/11/2026): la persona solo dice
 * «cómprame esto en tal tienda», y el lector de casi todas esas IA solo abre direcciones que ha LEÍDO escritas. No puede montar
 * la de «añadir» (le falta el número del producto) ni la de los datos del comprador (son texto libre).
 */

test('del llms.txt al carrito sin montar ninguna dirección: la ficha y el listado en texto traen escrita la de «añadir», y el llms.txt lo dice lo primero', function (): void {
    $app = apiApp([], ['semantic']);
    $ids = assistantShop($app);
    $web = new TestClient($app);

    $llms = $web->get('/llms.txt')->body;
    $guide = 'https://tienda.test/buy-with-links';
    contains('(' . $guide . ')', $llms, 'el llms.txt enlaza la guía por la entrada que abren todos los lectores');
    check(strpos($llms, '(' . $guide . ')') < strpos($llms, '/categoria/'), 'y va antes que la lista de categorías: los lectores recortan los textos largos');
    contains('Aquí puedes comprar por la persona', $llms);

    // Como ruta limpia, sin «?»: el lector de Claude perdía a veces los parámetros (17/11/2026).
    $add = $guide . '/new/' . $ids['silla_roja'];
    $md = $web->get('/producto/silla-roja.md');
    same(200, $md->status);
    contains('**Comprar con un asistente:** [' . $add . '](' . $add . ')', $md->body, 'la ficha en texto trae la dirección entera');
    check(strpos($md->body, $add) < strpos($md->body, '## '), 'arriba, antes de la primera sección');
    $soldOut = $web->get('/producto/silla-azul.md');
    same(200, $soldOut->status);
    notContains('buy-with-links', $soldOut->body, 'un producto agotado no trae dirección de compra');

    $list = $web->get('/categoria/sillas.md');
    same(200, $list->status);
    contains('[añadir a un carrito (abierto por la persona, la lleva a la caja con este producto)](' . $add . ')', $list->body, 'y el listado, la de cada producto que se puede comprar');
    notContains('/new/' . $ids['silla_azul'] . ')', $list->body);
    notContains('?product_id=', $list->body, 'ninguna lleva parámetros');

    // La búsqueda de la API trae la misma dirección en cada resultado que se puede comprar (23/11/2026, la pidió una auditoría).
    [$st, $found] = linksOpen($app, 'https://tienda.test/api/v1/search?q=silla');
    same(200, $st);
    $byId = [];
    foreach ($found['data'] as $item) {
        $byId[(int) $item['id']] = $item;
    }
    same($add, $byId[$ids['silla_roja']]['buy_url'] ?? null, 'el resultado trae la dirección que mete el producto en un carrito');
    if (isset($byId[$ids['silla_azul']])) {
        same(null, $byId[$ids['silla_azul']]['buy_url'], 'un producto agotado no la trae');
    }

    // Abrirla tal cual, sin carrito previo: lo crea, mete el producto y dice las dos salidas.
    [$status, $added] = linksOpen($app, $add);
    same(200, $status, json_encode($added));
    $cart = (string) $added['data']['cart'];
    same(48, strlen($cart), 'la respuesta trae la ficha del carrito');
    // Lo primero que lee la IA trae ya escritas las dos direcciones para la persona (16/11/2026: ChatGPT sin herramientas no
    // usaba las de «next» y decía que la tienda no respondía): el formulario de los datos y la caja con este carrito.
    contains('The way that works with any tool', (string) $added['say']);
    contains('https://tienda.test/buy-with-links/customer/' . $cart . ' .', (string) $added['say'], 'el formulario de los datos, escrito');
    contains('https://tienda.test/carrito/retomar/' . $cart . ' .', (string) $added['say'], 'y la caja con este carrito, escrita');
    contains('The carrier needs the phone', (string) $added['say'], 'el teléfono es obligatorio');
    // Las dos direcciones que hacen falta, lo primero y escritas: el lector de Claude resume y perdía las de «next» (17/11/2026).
    check(strpos((string) $added['say'], '/buy-with-links/customer/' . $cart) < 200, 'el formulario, al principio: ' . $added['say']);
    contains('https://tienda.test/buy-with-links/status/' . $cart . ' .', (string) $added['say'], 'y la de seguir, escrita');
    // Y al lector de un asistente no se le pone delante la caja «para la persona»: la daba en vez del formulario.
    $byReader = apiCall($app, 'GET', '/buy-with-links/new/' . $ids['silla_roja'], null,
        ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0; +Claude-User@anthropic.com', 'accept' => 'text/html']);
    same(200, $byReader->status);
    notContains('Tu carrito está listo en la caja', $byReader->body, 'sin la línea para la persona');
    notContains('a phone only if', (string) $added['say']);
    same('https://tienda.test/carrito/retomar/' . $cart, linksNext($added, '/carrito/retomar/'), 'entre ellas, la dirección de la caja para la persona');

    // La página normal de la ficha (la que pega en su chat quien está mirando el producto) lleva la misma dirección, a la vista.
    $html = $web->get('/producto/silla-roja');
    same(200, $html->status);
    contains('data-af-assistant-buy><a href="' . e($add) . '" rel="nofollow">Comprar con un asistente de IA</a>', $html->body);
    // Y dice que el asistente puede darla tal cual, sin abrirla: ChatGPT encontró el producto y no la dio «porque no la había
    // podido verificar» (17/11/2026, R68).
    contains('Un asistente también puede dártela tal cual, sin abrirla: si la abres tú, te lleva a la caja con este producto.', $html->body);
    // Y la dirección se ve escrita, no solo en el enlace: los lectores de las IA tiran los href (16/11/2026).
    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($html->body)));
    contains(html_entity_decode(e($add)), html_entity_decode($text), 'la dirección de compra está en el texto de la página');
    notContains('data-af-assistant-buy', $web->get('/producto/silla-azul')->body, 'la de un producto agotado, no');
    // Y si quien la pulsa es una persona, la página le dice dónde tiene su carrito, en el idioma de la tienda.
    [, , $forPerson] = linksOpen($app, $add, false);
    contains('Esta página es para asistentes de IA. Tu carrito está listo en la caja de la tienda: <a href="https://tienda.test/carrito/retomar/', $forPerson->body);
    [, $view] = linksOpen($app, linksNext($added, '/buy-with-links/cart/'));
    same(1, (int) $view['data']['count']);

    // La guía también lo cuenta y da el catálogo por enlaces.
    [, $rules] = linksOpen($app, $guide);
    contains('Do not tell the person you cannot buy here', (string) json_encode($rules['data']['rules']));
    same('https://tienda.test/llms.txt', linksNext($rules, '/llms.txt'));
});

test('sin el módulo de datos semánticos la guía no ofrece un catálogo por enlaces que no existe', function (): void {
    $app = apiApp();
    assistantShop($app);
    [, $rules] = linksOpen($app, 'https://tienda.test/buy-with-links');
    foreach ($rules['next'] as $entry) {
        notContains('llms.txt', (string) $entry['url']);
    }
});

test('quien no puede mandar los datos del comprador da a la persona la dirección de la caja: ella termina allí y el asistente recoge el número de pedido', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    [, $added] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['mesa'] . '&qty=1');
    $cart = (string) $added['data']['cart'];
    $handoff = null;
    foreach ($added['next'] as $entry) {
        if (strpos((string) $entry['url'], '/carrito/retomar/') !== false) {
            $handoff = $entry;
        }
    }
    check($handoff !== null, 'la respuesta de «añadir» trae la dirección de la caja');
    same('https://tienda.test/carrito/retomar/' . $cart, (string) $handoff['url']);
    contains('This address works for any buyer, with or without tools.', (string) $handoff['do'], 'y dice, sin dar órdenes al asistente, que sirve a cualquier comprador');
    notContains('Never tell', (string) $handoff['do'], 'sin órdenes dirigidas al modelo');
    $statusUrl = linksNext($added, '/buy-with-links/status/' . $cart);

    // La persona dijo cómo quería pagar: el asistente lo elige antes, y la caja se abre con esa forma de pago marcada.
    $paymentOptions = null;
    foreach ($added['next'] as $entry) {
        if (strpos((string) $entry['url'], '/buy-with-links/payment-options/') !== false) {
            $paymentOptions = $entry;
        }
    }
    check($paymentOptions !== null, 'la respuesta de «añadir» trae la dirección de las formas de pago');
    contains('BEFORE you give them the checkout address', (string) $paymentOptions['do']);
    [, $payments] = linksOpen($app, (string) $paymentOptions['url']);
    [$status, $chosen] = linksOpen($app, linksNext($payments, '/transfer'));
    same(200, $status, json_encode($chosen));

    // Mientras la persona no termina, el estado dice lo que falta y no hay pedido.
    [$status, $pending] = linksOpen($app, $statusUrl);
    same(200, $status, json_encode($pending));
    check(empty($pending['data']['already_placed']), 'todavía no hay pedido');
    same(0, count(apiOrders($app)));

    // La persona abre esa dirección en su navegador y termina en la caja de la tienda, con el carrito ya lleno.
    $web = new TestClient($app);
    same(302, $web->get('/carrito/retomar/' . $cart)->status);
    $page = $web->get('/finalizar-compra');
    contains('Mesa de roble', $page->body, 'la caja enseña lo que preparó el asistente');
    check(preg_match('/name="payment_method" value="transfer"[^>]*\schecked/', $page->body) === 1, 'y trae marcada la forma de pago que eligió el asistente');
    $place = $web->post('/finalizar-compra/place', frontForm($web, frontFill(), $page->body));
    same(303, $place->status, substr($place->body, 0, 300));
    [$number] = frontOrderPath($place);

    // El asistente abre la dirección del estado y ya tiene el número: no hace ni inventa otro pedido.
    [$status, $done] = linksOpen($app, $statusUrl);
    same(200, $status, json_encode($done));
    same(true, $done['data']['already_placed']);
    same($number, (string) $done['data']['number']);
    contains('already been ordered', (string) $done['say']);
    same(1, count(apiOrders($app)));

    // Y si en vez del estado vuelve a abrir la dirección del carrito (ChatGPT lo hizo y se encontró un 404: «no puedo
    // verificar tu pedido», 16/11/2026), va a la página de ESE pedido, que es donde está el número.
    $again = (new TestClient($app))->get('/carrito/retomar/' . $cart);
    same(302, $again->status);
    contains('/pedido/' . $number . '/', (string) $again->headers['Location'], 'lleva a la página del pedido');
    contains('no-store', (string) $again->headers['Cache-Control']);
    contains('noindex', (string) $again->headers['X-Robots-Tag']);
    // Y esa página ABRE: el enlace se montaba con el resumen guardado del secreto y daba siempre un 404 (17/11/2026, Claude
    // dio a dos clientes «tu pedido» con un enlace que no abría). Lo mismo el «order_page» de la respuesta del estado.
    $location = (string) preg_replace('#^https?://[^/]+#', '', (string) $again->headers['Location']);
    same(200, (new TestClient($app))->get($location)->status, 'la página del pedido abre: ' . $location);
    $page = (string) preg_replace('#^https?://[^/]+#', '', (string) $done['data']['order_page']);
    check($page !== '', 'el estado trae la página del pedido');
    same(200, (new TestClient($app))->get($page)->status, 'y abre: ' . $page);
    contains('If the person is buying now, this is NOT their new order', (string) $done['say'], 'con su fecha y el aviso para quien vuelve con una dirección vieja');
});

test('la segunda salida: el asistente escribe la dirección con los datos, la abre la PERSONA, y él sigue por el estado hasta el pedido sin montar ninguna', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    [, $added] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1');
    $cart = (string) $added['data']['cart'];
    $template = linksNext($added, '/buy-with-links/customer/' . $cart);
    contains('text=NAME', $template, 'la dirección de los datos viene con su texto de ejemplo, para que el asistente lo cambie');
    contains('and a phone (the carrier needs it', (string) json_encode($added['next']), 'y pide el teléfono: el transporte lo exige (16/11/2026)');
    // Junto a la propia dirección, qué hacer si la herramienta se niega a abrirla: ChatGPT, al no poder, pedía otra
    // herramienta o mandaba a la persona a la caja sin intentar esta salida (3 de 9 clientes, 16/11/2026).
    contains('do not give up and do not ask for another tool: write the completed address in your answer as a link', (string) json_encode($added['next']));
    $statusUrl = linksNext($added, '/buy-with-links/status/' . $cart);

    // La persona pulsa en su navegador el enlace que le ha escrito el asistente (él no puede abrirlo): una página normal, sin
    // cabecera «Accept: application/json».
    $written = substr($template, 0, (int) strpos($template, '?text=')) . '?text=' . rawurlencode(CHAT_DATA);
    [$status, , $page] = linksOpen($app, $written, false);
    same(200, $status);
    contains('text/html', (string) $page->headers['Content-Type']);
    contains('<strong>Si eres la persona que compra:</strong> Tus datos están guardados. Vuelve a tu asistente y dile que continúe.', $page->body, 'le dice, en su idioma, que ya está');
    check(strpos($page->body, 'Si eres la persona que compra') < strpos($page->body, 'Answer of the shop'), 'y es lo primero de la página');
    notContains('ana@correo.test', $page->body, 'sus datos vuelven tapados');

    // Con datos a medias (otro carrito, solo el nombre), la página no dice que estén guardados: dice que falta algo.
    [, $other] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['mesa'] . '&qty=1');
    $otherTemplate = linksNext($other, '/buy-with-links/customer/');
    [, , $half] = linksOpen($app, substr($otherTemplate, 0, (int) strpos($otherTemplate, '?text=')) . '?text=' . rawurlencode('Me llamo Ana Ruiz'), false);
    notContains('Tus datos están guardados', $half->body);
    contains('Falta alguno de tus datos o no es válido', $half->body);

    // El asistente abre el estado (dirección que ya tenía escrita) y de ahí todo son direcciones que le da la tienda.
    [$status, $pending] = linksOpen($app, $statusUrl);
    same(200, $status, json_encode($pending));
    [, $shipping] = linksOpen($app, linksNext($pending, '/buy-with-links/shipping-options/' . $cart));
    [$status, $shipped] = linksOpen($app, linksNext($shipping, '/buy-with-links/shipping/' . $cart . '/'));
    same(200, $status, json_encode($shipped));
    [, $payments] = linksOpen($app, linksNext($shipped, '/buy-with-links/payment-options/' . $cart));
    [$status, $paid] = linksOpen($app, linksNext($payments, '/transfer'));
    same(200, $status, json_encode($paid));
    [$status, $review] = linksOpen($app, linksNext($paid, '/buy-with-links/review/' . $cart));
    same(200, $status, json_encode($review));
    same(true, $review['data']['confirmation']['sent'], 'la revisión manda el código al correo que la persona dejó al abrir su dirección');

    // El código, tecleado con enlaces: seis páginas, y la última ya es el pedido.
    $code = linksCode($app);
    $pad = linksNext($review, '/buy-with-links/code/' . $cart);
    $order = [];
    foreach (str_split($code) as $digit) {
        [$status, $step] = linksOpen($app, $pad);
        same(200, $status, json_encode($step));
        $pad = (string) $step['next'][(int) $digit]['url'];
    }
    [$status, $order] = linksOpen($app, $pad);
    same(200, $status, json_encode($order));
    check((string) $order['data']['number'] !== '', 'el pedido está hecho');
    same(1, count(apiOrders($app)));
});

test('la persona abre el enlace de los datos tal cual: un formulario que los guarda en el carrito que preparó su asistente', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    [, $added] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1');
    $cart = (string) $added['data']['cart'];
    $formUrl = '';
    foreach ($added['next'] as $link) {
        if (strpos((string) $link['do'], 'It is a form where they write') !== false) {
            $formUrl = (string) $link['url'];
        }
    }
    same('https://tienda.test/buy-with-links/customer/' . $cart, $formUrl, 'un enlace ya escrito, sin nada que completar (16/11/2026: sin TinyFish, ChatGPT no guardaba los datos)');

    [$status, , $page] = linksOpen($app, $formUrl, false);
    same(200, $status);
    contains('text/html', (string) $page->headers['Content-Type']);
    contains('<form method="get" action="https://tienda.test/buy-with-links/customer/' . $cart . '">', $page->body, 'el formulario vuelve a la misma dirección');
    foreach (['first_name', 'last_name', 'line1', 'postcode', 'city', 'phone', 'email'] as $field) {
        contains('name="' . $field . '"', $page->body, 'pide ' . $field);
    }
    contains('Tus datos para el pedido', $page->body, 'en el idioma de la tienda');
    // El lector del asistente no recibe el formulario: se le dice que es para la persona (17/11/2026, Claude: «no puedo
    // enviar formularios» y mandaba a la persona a terminar sola en la caja).
    $reader = apiCall($app, 'GET', (string) preg_replace('#^https?://[^/]+#', '', $formUrl), null,
        ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0; +Claude-User@anthropic.com', 'accept' => 'text/html']);
    same(200, $reader->status);
    notContains('<form', $reader->body, 'al lector, sin formulario');
    contains('This address is a form for the PERSON', $reader->body);
    contains('/buy-with-links/customer/' . $cart, $reader->body, 'con la dirección que tiene que darle, escrita');
    // La de «lo que falta», nueva en cada respuesta para el lector: su caché le devolvía la página vieja, sin los datos que la
    // persona acababa de guardar (17/11/2026, R42).
    $claudeUa = ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0; +Claude-User@anthropic.com', 'accept' => 'text/html'];
    $again = apiCall($app, 'GET', (string) preg_replace('#^https?://[^/]+#', '', $formUrl), null, $claudeUa);
    preg_match('#/buy-with-links/status/' . $cart . '/([a-f0-9]+)#', $reader->body, $one);
    preg_match('#/buy-with-links/status/' . $cart . '/([a-f0-9]+)#', $again->body, $two);
    check(isset($one[1], $two[1]) && $one[1] !== $two[1], 'una dirección de estado distinta cada vez');
    $fresh = apiCall($app, 'GET', '/buy-with-links/status/' . $cart . '/' . $one[1], null, ['accept' => 'application/json']);
    same(200, $fresh->status, 'y abre el estado');

    contains('name="note"', $page->body, 'y una nota para lo que la ficha no ofrece como opción (la talla)');
    // El carrito dice lo que falta de quién compra: Claude lo miraba tras guardar la persona sus datos y, como no lo decía,
    // concluía que no estaban (17/11/2026, R42).
    [, $cartBefore] = linksOpen($app, 'https://tienda.test/buy-with-links/cart/' . $cart);
    check(($cartBefore['data']['missing_fields'] ?? []) !== [], 'el carrito, antes de los datos, dice qué falta: ' . json_encode($cartBefore['data']['missing_fields'] ?? null));
    check(!isset($cartBefore['say']), 'y no dice que ya están');
    $sent = $formUrl . '?first_name=Ana&last_name=Ruiz&line1=' . rawurlencode('Calle de Ejemplo 1') . '&postcode=28013&city=Madrid&phone=999000401&email=' . rawurlencode('ana@cliente.test') . '&country=ES&note=' . rawurlencode('Talla 39');
    [$status, , $saved] = linksOpen($app, $sent, false);
    same(200, $status);
    contains('Tus datos están guardados. Vuelve a tu asistente y dile que continúe.', $saved->body, 'y le dice que vuelva al chat');
    contains('Si tu asistente no puede seguir, termina tú en la caja', $saved->body, 'y, si su asistente no sigue, que puede terminar ella');
    contains('/carrito/retomar/', $saved->body, 'con la caja de su carrito a un clic');
    $checkout = (string) $app->db()->value('SELECT checkout FROM {carts} WHERE token = ?', [hash('sha256', $cart)]);
    contains('999000401', $checkout, 'los datos (con el teléfono) quedan en ese carrito');
    contains('Talla 39', $checkout, 'y la nota también');
    [, $statusNote] = linksOpen($app, 'https://tienda.test/buy-with-links/status/' . $cart);
    same('Talla 39', (string) ($statusNote['data']['note'] ?? ''), 'el estado enseña la nota, para que el asistente la confirme');
    // Elegir la forma de pago no es pagar: Claude le dio a la persona la dirección de elegirla y no hubo pedido (R50).
    [, $payOptions] = linksOpen($app, 'https://tienda.test/buy-with-links/payment-options/' . $cart);
    contains('Choosing the payment method only records it in this cart: nothing is charged', (string) ($payOptions['say'] ?? ''), 'la lista de pagos dice que elegir no cobra: ' . json_encode($payOptions['say'] ?? null));
    $choose = array_values(array_filter($payOptions['next'] ?? [], static function ($n) { return strpos((string) ($n['url'] ?? ''), '/buy-with-links/payment/') !== false; }));
    check($choose !== [] && strpos((string) $choose[0]['do'], 'nothing is charged') !== false, 'y cada opción también: ' . json_encode($choose[0] ?? null));
    [, $cartAfter] = linksOpen($app, 'https://tienda.test/buy-with-links/cart/' . $cart);
    same([], $cartAfter['data']['missing_fields'] ?? null, 'y el carrito, con los datos guardados, ya no echa nada en falta');
    contains('The details of who buys are saved in this cart', (string) ($cartAfter['say'] ?? ''), 'y lo dice');
    // Y la guía, al lector de una IA de chat, sin los ejemplos que hay que completar (los abría tal cual, R38).
    $guideReader = apiCall($app, 'GET', '/buy-with-links', null,
        ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0; +Claude-User@anthropic.com', 'accept' => 'text/html']);
    notContains('q=WORDS', $guideReader->body);
    notContains('product_id=ID', $guideReader->body);
    contains('Category ', $guideReader->body, 'con el catálogo por enlaces (sus categorías)');
    // Ni «empezar un carrito» ni «añadir otro» le dan la plantilla «ID» que no puede completar (17/11/2026, R67).
    $readerUa = ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0; +Claude-User@anthropic.com', 'accept' => 'text/html'];
    $startReader = apiCall($app, 'GET', '/buy-with-links/start', null, $readerUa);
    same(200, $startReader->status);
    notContains('product_id=ID', $startReader->body, 'empezar: sin la plantilla');
    notContains('/ID/', $startReader->body);
    contains('Category ', $startReader->body, 'empezar: con el catálogo por enlaces');
    $addReader = apiCall($app, 'GET', '/buy-with-links/new/' . $ids['silla_roja'], null, $readerUa);
    notContains('product_id=ID', $addReader->body, 'añadir otro: sin la plantilla');
    notContains('/ID/', $addReader->body);

    // Mientras faltaban los datos, cada paso (aquí, elegir el pago) empezaba diciendo cómo darlos, con el formulario escrito.
    [, $other] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['mesa'] . '&qty=1');
    $otherCart = (string) $other['data']['cart'];
    [, $paid] = linksOpen($app, linksNext($other, '/buy-with-links/payment-options/' . $otherCart));
    contains('The shop still needs who buys', (string) (isset($paid['say']) ? $paid['say'] : ''), 'sin datos, el paso lo recuerda lo primero (16/11/2026)');
    contains('This address works for any buyer, with or without tools.', (string) (isset($paid['say']) ? $paid['say'] : ''), 'y dice, sin dar órdenes al asistente, que la dirección de la caja sirve a cualquiera');
    notContains('Never tell', (string) (isset($paid['say']) ? $paid['say'] : ''), 'sin órdenes dirigidas al modelo');
    contains('https://tienda.test/buy-with-links/customer/' . $otherCart . ' .', (string) $paid['say'], 'con el formulario escrito');
    [, $paidAfter] = linksOpen($app, 'https://tienda.test/buy-with-links/payment-options/' . $cart);
    notContains('The shop still needs who buys', (string) (isset($paidAfter['say']) ? $paidAfter['say'] : ''), 'con los datos ya guardados, ya no lo dice');

    // El asistente que lo pide en JSON sin datos sigue recibiendo el error de siempre: el formulario es solo para la persona.
    $r = apiCall($app, 'GET', '/buy-with-links/customer/' . $cart, null, ['accept' => 'application/json']);
    same(422, $r->status, 'en JSON, sin datos, es un error');
});

test('el enlace de la caja con el carrito, abierto por el lector de una IA: dice que es para la persona, sin redirigir ni poner el carrito', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    [, $added] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1');
    $resume = (string) parse_url(linksNext($added, '/carrito/retomar/'), PHP_URL_PATH);
    $bot = apiCall($app, 'GET', $resume, null, ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot']);
    same(200, $bot->status, 'contesta, no redirige a la caja que tiene cerrada (16/11/2026: ChatGPT decía que la tienda no era accesible)');
    contains('This address works: it is for the person who is buying', $bot->body);
    same(false, isset($bot->headers['Location']), 'sin redirección');
    $person = apiCall($app, 'GET', $resume, null, ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36', 'sec-fetch-user' => '?1', 'sec-fetch-dest' => 'document']);
    same(302, $person->status, 'la persona sí va a la caja');

    // Y el robots.txt se lo deja abrir a los lectores de los asistentes, aunque el resto del carrito siga cerrado.
    $seo = apiApp(['seo.noindex_site' => true], ['seo']);
    $robots = apiCall($seo, 'GET', '/robots.txt')->body;
    contains("Allow: /\nAllow: /carrito/retomar/\n", $robots, 'el grupo de los lectores abre el enlace de retomar');
    contains("Disallow: /carrito\n", $robots, 'el carrito sigue cerrado');
});

test('el lector que solo abre direcciones escritas: el ejemplo de los datos abierto tal cual le lleva al formulario, y tiene ya escrita la que añade otro producto al MISMO carrito', function (): void {
    // 16/11/2026, Claude-User: abría «customer/…?text=NAME, STREET AND NUMBER…» tal cual (no puede abrir la que completa él),
    // recibía un 422 «no he encontrado ningún dato personal» y no podía meter el colorante en el carrito del kit de velas.
    $app = apiApp();
    $ids = assistantShop($app);
    [$status, $first] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1');
    same(200, $status, json_encode($first));
    $cart = (string) $first['data']['cart'];
    $template = linksNext($first, 'STREET');
    [$status, $answer] = linksOpen($app, $template);
    same(200, $status, 'el ejemplo tal cual no es un error: ' . json_encode($answer));
    contains('The example address was opened as it is', (string) $answer['say']);
    notContains('/carrito/retomar/', (string) json_encode($answer['next']), 'sin la caja delante: el camino es el formulario y seguir el asistente');
    linksNext($answer, '/buy-with-links/customer/' . $cart);
    contains('/buy-with-links/customer/' . $cart . ' ', (string) $answer['say'], 'con el formulario para la persona ya escrito');
    $checkout = (string) $app->db()->value('SELECT checkout FROM {carts} WHERE token = ?', [hash('sha256', $cart)]);
    notContains('STREET', $checkout, 'y no se guarda nada del ejemplo');

    $more = '';
    foreach ($first['next'] as $link) {
        if (strpos((string) $link['do'], 'Add to this same cart: Sillón orejero') === 0) {
            $more = (string) $link['url'];
        }
        check(strpos((string) $link['do'], 'Add to this same cart: Silla azul') !== 0, 'lo agotado no se ofrece');
    }
    check($more !== '' && strpos($more, 'ID') === false, 'otro asiento de la misma categoría, con su dirección completa: ' . json_encode($first['next']));
    [$status, $second] = linksOpen($app, $more);
    same(200, $status, json_encode($second));
    same($cart, (string) $second['data']['cart'], 'en el mismo carrito');
    same(true, !empty($second['data']['ok']), json_encode($second['data']));
    [, $view] = linksOpen($app, 'https://tienda.test/buy-with-links/cart/' . $cart);
    same(2, count(isset($view['data']['lines']) ? $view['data']['lines'] : []), 'las dos cosas en el mismo carrito: ' . json_encode($view['data']));
    foreach ($second['next'] as $link) {
        check(strpos((string) $link['do'], 'Add to this same cart: Sillón orejero') !== 0, 'lo que ya está en el carrito no se ofrece otra vez');
    }

    // La dirección «todo en una» con el ejemplo tal cual: añade el producto y pide los datos aparte, sin error.
    [$status, $one] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1&text=' . rawurlencode('NAME, STREET AND NUMBER, POSTCODE CITY, PHONE, EMAIL'));
    same(200, $status, json_encode($one));
    check(!isset($one['data']['customer']), 'no intenta guardar el ejemplo como datos: ' . json_encode($one['data']));
});

test('«add» sin producto o con el ejemplo «ID», y el buscador con «WORDS»: el catálogo por enlaces, sin error y sin crear carritos', function (): void {
    // 17/11/2026, Claude-User: abría «/buy-with-links/add» a secas y «/api/v1/search?q=WORDS» tal cual (no puede completar
    // direcciones) y recibía un 422 y una búsqueda vacía.
    $app = apiApp();
    $ids = assistantShop($app);
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {carts}');
    [$status, $bare] = linksOpen($app, 'https://tienda.test/buy-with-links/add');
    same(200, $status, json_encode($bare));
    contains('the products are reached by links', (string) $bare['say']);
    $categories = array_values(array_filter($bare['next'], static function ($l): bool {
        return strpos((string) $l['do'], 'Category ') === 0;
    }));
    check($categories !== [], 'con las categorías, cada una con su dirección: ' . json_encode($bare['next']));
    check(strpos((string) $categories[0]['url'], 'https://tienda.test/') === 0, 'completas: ' . $categories[0]['url']);
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'abrirla sin producto no crea un carrito');

    [, $first] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1');
    $cart = (string) $first['data']['cart'];
    [$status, $withId] = linksOpen($app, 'https://tienda.test/buy-with-links/add/' . $cart . '/ID/1');
    same(200, $status, 'el ejemplo «ID» tal cual tampoco es un error: ' . json_encode($withId));
    linksNext($withId, '/buy-with-links/cart/' . $cart);

    [$status, $words] = linksOpen($app, 'https://tienda.test/api/v1/search?q=WORDS');
    same(200, $status);
    same([], $words['data']);
    contains('llms.txt', (string) $words['meta']['note'], 'manda al catálogo por enlaces: ' . json_encode($words));
});

test('el navegador autorizado va a la caja con el producto y el asistente identificado recibe la página de pasos', function (): void {
    $app = apiApp();
    $app->settings()->set('api.browser_agents_allowed', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36');
    $ids = assistantShop($app);
    $add = '/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1';
    $person = apiCall($app, 'GET', $add, null, ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36', 'accept' => 'text/html,application/xhtml+xml']);
    same(302, $person->status, 'la persona no ve la página para asistentes (16/11/2026: en inglés y con JSON)');
    contains('/carrito/retomar/', (string) $person->headers['Location'], 'va a la caja con su carrito');
    $bot = apiCall($app, 'GET', $add, null, ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0', 'accept' => 'text/html']);
    same(200, $bot->status, 'el lector de una IA sigue recibiendo la página de pasos');
    // Y ninguna caché compartida la puede guardar: el lector de Claude daba «una copia guardada» con el carrito de otro (17/11/2026).
    contains('private', (string) $bot->headers['Cache-Control'], 'privada');
    contains('no-cache', (string) $bot->headers['Cache-Control'], 'y se revalida');
    // Al lector de una IA de chat (no completa direcciones) no se le da el ejemplo de los datos: solo el formulario para la
    // persona (17/11/2026: Claude abría el ejemplo tal cual, «saved: false», y era un tropiezo en todas las pruebas).
    notContains('STREET AND NUMBER', $bot->body, 'sin el ejemplo de los datos');
    notContains('Save who buys', $bot->body);
    contains('/buy-with-links/customer/', $bot->body, 'con el formulario para la persona');
    // Las dos formas de seguir: la caja con este carrito LO PRIMERO (vale con cualquier asistente) y el formulario para preparar
    // el pedido. Antes se obligaba al pedido y las IA que no recorren varios pasos no tenían nada que dar (17/11/2026).
    contains('There are two ways to go on, and both are right', $bot->body);
    $caja = strpos($bot->body, '/carrito/retomar/');
    $form = strpos($bot->body, '/buy-with-links/customer/');
    check($caja !== false && $form !== false && $caja < $form, 'la caja, antes que el formulario');
    $section = (string) substr($bot->body, (int) strpos($bot->body, '<h2>'));
    $cajaNext = strpos($section, 'The checkout for the person');
    $formNext = strpos($section, 'If you cannot open addresses you complete yourself');
    check($cajaNext !== false && $formNext !== false && $cajaNext < $formNext, 'y en las siguientes direcciones, la caja la primera');
    // En una demostración se le dice que es de pruebas y que seguir está bien: Gemini y Claude no seguían porque «recordaban»
    // que era una tienda de pruebas (17/11/2026). Una tienda que cobra de verdad no se presenta así.
    contains('This is a demo shop', $bot->body, 'con la pasarela en modo de prueba, se lo dice');
    $live = apiApp();
    $liveIds = assistantShop($live);
    $live->service('payments')->get('gw')->test = false;
    $liveBot = apiCall($live, 'GET', '/buy-with-links/add?product_id=' . $liveIds['silla_roja'] . '&qty=1', null,
        ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0', 'accept' => 'text/html']);
    contains('There are two ways to go on', $liveBot->body);
    notContains('This is a demo shop', $liveBot->body, 'una tienda que cobra de verdad no se presenta como demostración');
    $demo = apiApp();
    $demo->site()->config['demo'] = true; // lo que la instalación escribe en el config.php de una demostración
    $demoIds = assistantShop($demo);
    $gw = $demo->service('payments')->get('gw');
    if ($gw !== null) {
        $gw->test = false; // lo dice por ser demostración, no por la pasarela
    }
    $demoBot = apiCall($demo, 'GET', '/buy-with-links/add?product_id=' . $demoIds['silla_roja'] . '&qty=1', null,
        ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0', 'accept' => 'text/html']);
    contains('This is a demo shop: its payments are in test mode and nothing is ever charged', $demoBot->body, 'una demostración se lo dice al asistente');
});

test('lo que la ficha no ofrece como opción (la talla) viaja en la nota del pedido junto a los datos', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    [, $added] = linksOpen($app, 'https://tienda.test/buy-with-links/add?product_id=' . $ids['silla_roja'] . '&qty=1');
    $cart = (string) $added['data']['cart'];
    contains('goes in the parameter note of the same address', (string) json_encode($added['next']), 'la guía lo dice junto a la dirección de los datos (16/11/2026: la talla 42 no llegó al pedido)');
    $template = linksNext($added, '/buy-with-links/customer/' . $cart);
    $url = substr($template, 0, (int) strpos($template, '?text=')) . '?text=' . rawurlencode(CHAT_DATA) . '&note=' . rawurlencode('Talla 42');
    [$status] = linksOpen($app, $url);
    same(200, $status);
    $checkout = (string) $app->db()->value('SELECT checkout FROM {carts} WHERE token = ?', [hash('sha256', $cart)]);
    contains('Talla 42', $checkout, 'la nota queda en la compra y llega al pedido');
});
