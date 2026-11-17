<?php
/**
 * AIFEC — pruebas del pedido a mano (P103): permisos, un solo pedido por envío, lo que cambia entre el total enseñado y la
 * confirmación, el resumen en JSON con sus cabeceras, el listado por origen y la pantalla (política de contenido, peso,
 * accesibilidad y funcionamiento sin JavaScript).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Csp;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\Admin\ManualOrderController;
use Aifec\Modules\Orders\Admin\ManualOrderDraft;

require_once __DIR__ . '/AdminManualOrderKit.php';

if (!function_exists('moGuardOrders')) {
    function moGuardOrders(App $app): int
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {orders}');
    }

    function moGuardAsset(string $file): string
    {
        return (string) file_get_contents(\Aifec\Autoload::root() . '/modules/orders/assets/' . $file);
    }

    function moGuardDom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        $before = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($before);

        return $dom;
    }

    /**
     * Ejecuta $fn(App) dentro de UNA petición con las cookies de $web (sesión del panel incluida).
     *
     * @return mixed
     */
    function moGuardRun(TestClient $web, callable $fn)
    {
        static $n = 0;
        ++$n;
        $out = null;
        $web->app->router()->get('/_mo-guard-' . $n, static function (Request $request, App $app) use ($fn, &$out): Response {
            $out = $fn($app);

            return Response::text('ok');
        });
        $web->get('/_mo-guard-' . $n);

        return $out;
    }
}

test('sin «orders.create»: 403 en todas las rutas nuevas, y ni el botón «Pedido nuevo» ni «Repetir» salen', function (): void {
    $s = moShop();
    $app = $s['app'];
    $id = adminOrdersPlace($app, 'transfer', [[$s['mug'], 0, 1]]);
    $web = moClient($app, 'support');
    same(200, $web->get('/admin/orders')->status, 'el listado sí lo ve');
    notContains('/admin/orders/new', $web->get('/admin/orders')->body, 'sin botón «Pedido nuevo»');
    notContains('/repeat', $web->get('/admin/orders/' . $id)->body, 'sin «Repetir» en la ficha');
    same(403, $web->get('/admin/orders/new')->status);
    same(403, $web->get('/admin/orders/new/find/customers?q=Ana')->status);
    same(403, $web->get('/admin/orders/new/find/products?q=Taza')->status);
    foreach (array_keys(ManualOrderController::STEPS) as $step) {
        same(403, moStep($web, $step, ['customer_id' => (int) $s['ana']])->status, 'paso ' . $step);
    }
    same(403, $web->post('/admin/orders/new/place', ['confirm' => str_repeat('a', 64)])->status);
    same(403, $web->post('/admin/orders/' . $id . '/repeat')->status);
    $quote = $web->post('/admin/orders/new/quote');
    same(403, $quote->status);
    contains('application/json', (string) $quote->headers['Content-Type']);
    same(1, moGuardOrders($app), 'nada nuevo');
});

test('con permiso, el botón «Pedido nuevo» sale en el listado (también para el gestor, que tiene «orders.*») y «Repetir» en la ficha', function (): void {
    $s = moShop();
    $app = $s['app'];
    $id = adminOrdersPlace($app, 'transfer', [[$s['mug'], 0, 1]]);
    foreach (['admin', 'manager'] as $role) {
        $web = moClient($app, $role);
        $list = $web->get('/admin/orders')->body;
        check(preg_match('#<a class="af-btn af-btn-primary" href="/admin/orders/new">.*?Pedido nuevo</a>#', $list) === 1, $role . ': el botón');
        contains('action="/admin/orders/' . $id . '/repeat"', $web->get('/admin/orders/' . $id)->body, $role . ': «Repetir»');
        same(200, $web->get('/admin/orders/new')->status);
    }
});

test('el resumen (quote) es JSON con las tres cabeceras anti-caché, también al fallar, y nunca HTML', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 2], [$s['book'], 0, 1]]);
    $checkHeaders = static function (Response $r, string $what): void {
        same('no-store, no-cache, must-revalidate, max-age=0', (string) $r->headers['Cache-Control'], $what . ': Cache-Control');
        same('no-cache', (string) $r->headers['Pragma'], $what . ': Pragma');
        same('0', (string) $r->headers['Expires'], $what . ': Expires');
        contains('application/json', (string) $r->headers['Content-Type'], $what . ': JSON');
        check(is_array(json_decode($r->body, true)), $what . ': se lee como JSON');
        check(stripos($r->body, '<html') === false && stripos($r->body, '<!doctype') === false, $what . ': nada de una página');
    };
    $ok = $web->post('/admin/orders/new/quote');
    same(200, $ok->status);
    $checkHeaders($ok, 'listo');
    $q = json_decode($ok->body, true);
    same(true, $q['ok']);
    same(true, $q['ready']);
    $last = $q['rows'][count($q['rows']) - 1];
    same(['total', $q['total_formatted']], [$last['kind'], $last['value']], 'la última fila es el total, ya escrito por el servidor');
    same(money((int) $q['total']), (string) $q['total_formatted']);
    same(2 * 1200 + 1500, (int) $q['items']);
    $checkHeaders(moClient($app, 'support')->post('/admin/orders/new/quote'), 'sin permiso');
    $anon = new TestClient($app);
    $r = $anon->post('/admin/orders/new/quote');
    same(401, $r->status, 'sin sesión');
    $checkHeaders($r, 'sin sesión');
    $noToken = clone $web;
    unset($noToken->headers['x-csrf-token']);
    $r = $noToken->post('/admin/orders/new/quote');
    same(419, $r->status, 'sin la ficha del formulario');
    $checkHeaders($r, 'sin ficha');
});

test('un doble envío del formulario crea un solo pedido; el segundo lleva al mismo pedido', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    $form = panelKitForm($web->get('/admin/orders/new')->body, '/admin/orders/new/place');
    $first = $web->post('/admin/orders/new/place', $form);
    same(303, $first->status);
    $second = $web->post('/admin/orders/new/place', $form);
    same(303, $second->status);
    same(1, moGuardOrders($app), 'un solo pedido');
    same((string) $first->headers['Location'], (string) $second->headers['Location'], 'el segundo lleva al mismo pedido');
    contains('Este pedido ya estaba creado: aquí lo tienes.', $web->follow()->body);
    same(4, aifecOrderStock($app, $s['mug']), 'las existencias, una sola vez');
    $json = $web->post('/admin/orders/new/place', $form, ['accept' => 'application/json', 'x-requested-with' => 'fetch']);
    same(true, json_decode($json->body, true)['ok']);
    same(1, moGuardOrders($app), 'con el guion, tampoco');
});

test('dos envíos a la vez del mismo borrador: solo uno se queda con el carrito', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    $claims = moGuardRun($web, static function (App $app): array {
        $a = new ManualOrderDraft($app);
        $b = new ManualOrderDraft($app);
        same($a->state()['cart'], $b->state()['cart'], 'los dos leen el mismo borrador');
        try {
            return [$a->claim(), $b->claim()];
        } finally {
            $a->release();
            $b->release();
        }
    });
    check(is_int($claims[0]) && $claims[0] > 0, 'el primero gana');
    same(null, $claims[1], 'el segundo no');
});

test('un segundo envío que leyó la sesión antes de que el primero terminara no crea otro pedido', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    $form = panelKitForm($web->get('/admin/orders/new')->body, '/admin/orders/new/place');
    $sessions = $app->db()->select("SELECT id, data FROM {sessions} WHERE guard = 'admin'");
    same(303, $web->post('/admin/orders/new/place', $form)->status);
    foreach ($sessions as $row) {
        $app->db()->update('sessions', ['data' => $row['data']], ['id' => $row['id']]);
    }
    $again = $web->post('/admin/orders/new/place', $form);
    same(303, $again->status);
    same(1, moGuardOrders($app), 'un solo pedido');
    contains('/admin/orders/new', (string) $again->headers['Location'], 'vuelve a la pantalla');
});

test('si bajan las existencias entre el total y la confirmación, no se crea y la línea sale marcada', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1], [$s['book'], 0, 3]]);
    $form = panelKitForm($web->get('/admin/orders/new')->body, '/admin/orders/new/place');
    $app->db()->update('products', ['stock' => 1], ['id' => (int) $s['book']]);
    $r = $web->post('/admin/orders/new/place', $form);
    same(303, $r->status);
    contains('/admin/orders/new#mo-confirm', (string) $r->headers['Location']);
    same(0, moGuardOrders($app), 'no se crea');
    same(1, aifecOrderStock($app, $s['book']), 'ni se toca nada');
    $page = $web->follow();
    contains('No se ha creado nada: el pedido ha cambiado desde el total que viste.', $page->body);
    check(preg_match('/<tr id="mo-line-p' . (int) $s['book'] . '-v0-[a-f0-9]{6}" class="mo-changed">/', $page->body) === 1, 'la línea, marcada');
    contains('Libro de cocina: Solo queda 1 unidad', $page->body);
    $json = json_decode($web->post('/admin/orders/new/place', $form, ['accept' => 'application/json', 'x-requested-with' => 'fetch'])->body, true);
    same(false, $json['ok']);
    contains('class="mo-changed"', (string) $json['html'], 'con el guion, también marcada');
    same(0, moGuardOrders($app));
});

test('si cambia un precio entre el total y la confirmación, no se crea y dice qué cambió', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    $form = panelKitForm($web->get('/admin/orders/new')->body, '/admin/orders/new/place');
    $app->db()->update('products', ['price' => 1500], ['id' => (int) $s['mug']]);
    $app->invalidate(['catalog']);
    same(303, $web->post('/admin/orders/new/place', $form)->status);
    same(0, moGuardOrders($app));
    $page = $web->follow()->body;
    contains('Taza: el precio ha pasado de ' . money(1200) . ' a ' . money(1500) . '.', $page);
    contains('El total ha pasado de', $page);
    $fresh = panelKitForm($web->get('/admin/orders/new')->body, '/admin/orders/new/place');
    same(303, $web->post('/admin/orders/new/place', $fresh)->status, 'con el total nuevo a la vista, sí');
    same(1, moGuardOrders($app));
    same(1500 + 495, (int) moLastOrder($app)['total']);
});

test('si el cupón caduca entre el total y la confirmación, no se crea', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $q = moReady($web, $s, [[$s['mug'], 0, 1]], 'paid', ['coupon' => 'OTONO']);
    check((int) $q['discount'] > 0, 'con descuento');
    $form = panelKitForm($web->get('/admin/orders/new')->body, '/admin/orders/new/place');
    $app->db()->update('coupons', ['active' => 0], ['id' => (int) $s['coupon']]);
    same(303, $web->post('/admin/orders/new/place', $form)->status);
    same(0, moGuardOrders($app));
    contains('OTONO', $web->follow()->body);
});

test('confirmar sin estar listo no crea nada y dice qué falta', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $r = $web->post('/admin/orders/new/place', ['confirm' => '']);
    same(303, $r->status);
    same(0, moGuardOrders($app));
    contains('Elige el cliente.', $web->follow()->body);
    moStep($web, 'customer', ['customer_id' => (int) $s['ana']]);
    moStep($web, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    $q = moQuote($web);
    same(false, $q['ready']);
    same('', $q['token'], 'sin ficha de confirmación');
    same(['payment'], array_keys($q['missing']), 'la dirección de serie y el envío más barato ya están elegidos; falta el pago');
    contains('href="#mo-payment"', $web->get('/admin/orders/new')->body, 'cada cosa que falta lleva a su paso');
});

test('el listado distingue los pedidos hechos a mano («Panel») y los filtra por origen', function (): void {
    $s = moShop();
    $app = $s['app'];
    $shop = adminOrdersPlace($app, 'transfer', [[$s['book'], 0, 1]]);
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    moPlace($web);
    $manual = (int) moLastOrder($app)['id'];
    $all = $web->get('/admin/orders')->body;
    check(adminOrdersSees($all, adminOrdersNumber($app, $shop)) && adminOrdersSees($all, adminOrdersNumber($app, $manual)), 'los dos en el listado');
    check(preg_match('#>' . preg_quote(adminOrdersNumber($app, $manual), '#') . '</span> <span class="af-badge af-badge-info">Panel</span>#', $all) === 1, 'el hecho a mano lleva «Panel»');
    $filtered = $web->get('/admin/orders?f[source]=admin')->body;
    check(adminOrdersSees($filtered, adminOrdersNumber($app, $manual)), 'filtrado: el de panel');
    check(!adminOrdersSees($filtered, adminOrdersNumber($app, $shop)), 'filtrado: sin el de la tienda');
    contains('Canal', $all, 'el filtro sale con su nombre');
});

test('cada paso funciona sin JavaScript (POST y vuelta a su apartado) y con él (JSON con la columna de los pasos)', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    $r = moStep($web, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    same(303, $r->status);
    same('/admin/orders/new#mo-products', (string) $r->headers['Location']);
    contains('Añadido: Taza', $web->follow()->body);
    $json = moStep($web, 'line', ['product_id' => $s['book'], 'variant_id' => 0, 'qty' => 1], true);
    same(200, $json->status);
    contains('application/json', (string) $json->headers['Content-Type']);
    $data = json_decode($json->body, true);
    same(true, $data['ok']);
    same('Añadido: Libro de cocina', $data['message']);
    same('mo-products', $data['focus']);
    contains('id="mo-products"', (string) $data['html']);
    notContains('af-shell', (string) $data['html'], 'solo la columna, sin el marco');
    notContains('<script', (string) $data['html']);
    same(404, moStep($web, 'nada', [])->status, 'un paso que no existe');
    $reset = moStep($web, 'reset');
    same(303, $reset->status);
    same([], moQuote($web)['lines'], '«Empezar de nuevo» vacía el borrador');
});

test('la pantalla cumple la política de contenido: un solo guion en línea (el del tema, con su huella) y ningún manejador', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    $r = $web->get('/admin/orders/new?cq=Ana&pq=taza');
    $policy = (string) $r->headers['Content-Security-Policy'];
    check(preg_match("/(?:^|;)\s*script-src ([^;]*)/", $policy, $scriptSrc) === 1, 'lleva script-src');
    contains("'self'", $scriptSrc[1]);
    notContains("'unsafe-inline'", $scriptSrc[1], 'los guiones no se relajan');
    preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $r->body, $all, PREG_SET_ORDER);
    $inline = [];
    foreach ($all as $m) {
        if (stripos($m[1], 'src=') === false && trim($m[2]) !== '') {
            $inline[] = $m[2];
        }
    }
    same(1, count($inline), 'solo el guion del tema');
    contains(Csp::hash($inline[0]), $policy, 'con su huella');
    foreach ([' onclick=', ' onchange=', ' onsubmit=', ' oninput=', ' style="'] as $bad) {
        notContains($bad, $r->body, 'nada de ' . trim($bad));
    }
    contains('admin-manual-order.js" defer></script>', $r->body);
});

test('la pantalla está dentro del presupuesto del panel con diez líneas, y el guion y la hoja pesan poco', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moStep($web, 'customer', ['customer_id' => (int) $s['ana']]);
    for ($i = 1; $i <= 10; $i++) {
        $id = aifecProduct($app, ['name' => 'Producto ' . $i . ' & más', 'price' => 100 * $i, 'stock' => 20]);
        moStep($web, 'line', ['product_id' => $id, 'variant_id' => 0, 'qty' => 1]);
    }
    same(10, count(moQuote($web)['lines']));
    $r = $web->get('/admin/orders/new');
    check(strlen($r->body) <= 300 * 1024, 'como mucho 300 KB (' . strlen($r->body) . ')');
    preg_match_all('/<link\b[^>]*rel="stylesheet"|<script\b[^>]*\bsrc=/i', $r->body, $resources);
    check(count($resources[0]) <= 10, 'como mucho 10 recursos (' . count($resources[0]) . ')');
    $nodes = moGuardDom($r->body)->getElementsByTagName('*')->length;
    check($nodes <= 1500, 'como mucho 1.500 nodos (' . $nodes . ')');
    check(strlen(moGuardAsset('admin-manual-order.js')) <= 20 * 1024, 'el guion, como mucho 20 KB');
    check(strlen(moGuardAsset('admin-manual-order.css')) <= 8 * 1024, 'la hoja, como mucho 8 KB');
});

test('el guion no suma nada: copia los textos del servidor, va por delegación, sin innerHTML y con los textos del HTML', function (): void {
    $js = moGuardAsset('admin-manual-order.js');
    $code = '';
    foreach (explode("\n", $js) as $line) {
        $trim = ltrim($line);
        if ($trim !== '' && strpos($trim, '//') !== 0 && strpos($trim, '/*') !== 0 && strpos($trim, '*') !== 0) {
            $code .= $line . "\n";
        }
    }
    check(preg_match('/[^\x00-\x7F]/', $code) === 0, 'sin letras con tilde ni frases en el código');
    foreach (['parseFloat', 'parseInt', 'Number(', 'toFixed', 'Math.', '.reduce(', 'innerHTML', 'eval(', 'document.write'] as $bad) {
        notContains($bad, $code, 'nada de ' . $bad);
    }
    contains('dd.textContent = String(row.value);', $code, 'cada importe es el texto que manda el servidor');
    contains("document.addEventListener('submit'", $code);
    contains("document.addEventListener('keydown'", $code);
    contains("root.getAttribute('data-mo-t-' + name)", $code, 'los textos vienen del HTML');
    contains("form.getAttribute('aria-busy') === 'true'", $code, 'un doble clic no manda dos veces');
    contains('safeUrl', $code);
});

test('accesible: buscadores combobox con su lista y su aviso, botones de icono con nombre, tablas con cabecera', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    moStep($web, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    moStep($web, 'coupon', ['code' => 'OTONO']);
    $html = $web->get('/admin/orders/new')->body;
    $dom = moGuardDom($html);
    $xpath = new DOMXPath($dom);
    $combos = $xpath->query('//input[@role="combobox"]');
    same(2, $combos->length, 'dos buscadores');
    foreach ($combos as $input) {
        $list = $dom->getElementById($input->getAttribute('aria-controls'));
        check($list !== null && $list->getAttribute('role') === 'listbox', 'su lista es un listbox');
        same('false', $input->getAttribute('aria-expanded'));
        same('list', $input->getAttribute('aria-autocomplete'));
        check($xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length === 1, 'con su etiqueta');
    }
    same(1, $xpath->query('//*[@data-mo-live][@aria-live="polite"]')->length, 'los resultados y el total se anuncian');
    foreach ($xpath->query('//button') as $button) {
        if (trim($button->textContent) === '') {
            check($button->getAttribute('aria-label') !== '', 'un botón de solo icono lleva aria-label');
        }
    }
    foreach ($xpath->query('//table[contains(@class, "af-table")]') as $table) {
        check($xpath->query('.//thead//th', $table)->length >= 4, 'la tabla lleva su cabecera (en móvil cada fila es una tarjeta)');
    }
    same(7, $xpath->query('//h2[@tabindex="-1"]')->length, 'cada paso tiene su título, que recibe el foco al repintar');
    notContains('outline: none', moGuardAsset('admin-manual-order.css'));
});

test('sin JavaScript, cada formulario de paso es un POST con su ficha a una dirección del pedido a mano', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    moReady($web, $s, [[$s['mug'], 0, 1]], 'paid', ['coupon' => 'OTONO']);
    $html = $web->get('/admin/orders/new')->body;
    $xpath = new DOMXPath(moGuardDom($html));
    $forms = $xpath->query('//form[@data-mo-form]');
    check($forms->length >= 10, 'los pasos son formularios (' . $forms->length . ')');
    foreach ($forms as $form) {
        same('post', $form->getAttribute('method'));
        check(strpos($form->getAttribute('action'), '/admin/orders/new/') === 0, 'a ' . $form->getAttribute('action'));
        same(1, $xpath->query('.//input[@name="_token"]', $form)->length, 'con su ficha');
    }
    foreach ($xpath->query('//form[@data-mo-find]') as $form) {
        same('get', $form->getAttribute('method'), 'los buscadores son GET');
    }
});
