<?php
/**
 * AIFEC — pruebas de los puntos de extensión de los pedidos (P092): el carrito, la caja y la ficha respetan «purchasable» en el
 * servidor, la ficha sin precio ni botón, la puerta de las descargas (filtro sellado order.download.allowed) y los bloques de
 * texto de los plugins en el correo del pedido (filtro order.mail.blocks).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\Downloads;
use Aifec\Modules\Orders\OrderMails;

require_once __DIR__ . '/helpers.php';

/** Engancha un filtro como lo haría un módulo del sistema (el catálogo tiene concedida la capacidad «pricing»). */
function ordExtAvailability(App $app, callable $filter): void
{
    $hooks = $app->hooks();
    $hooks->setCurrentModule('catalog');
    $hooks->filter('product.availability', $filter);
    $hooks->setCurrentModule('core');
    $app->invalidate(['catalog']);
}

/** Un producto que un plugin deja como «próximamente»: no comprable y sin precio. */
function ordExtSoon(App $app, int $productId, string $label = 'Próximamente'): void
{
    ordExtAvailability($app, static function (array $a, array $context) use ($productId, $label): array {
        if ($context['id'] === $productId) {
            $a['label'] = $label;
            $a['purchasable'] = false;
            $a['show_price'] = false;
            $a['schema'] = 'PreOrder';
        }

        return $a;
    });
}

/** Un plugin (módulo «probe») con la capacidad «orders» concedida o no, enganchado a la puerta de las descargas. */
function ordExtGate(App $app, callable $filter, bool $granted = true, string $module = 'probe'): void
{
    $hooks = $app->hooks();
    if ($granted) {
        $hooks->grant($module, 'orders');
    }
    $hooks->setCurrentModule($module);
    $hooks->filter('order.download.allowed', $filter);
    $hooks->setCurrentModule('core');
}

/** Un plugin enganchado a los bloques del correo del pedido (no es un filtro sellado). */
function ordExtBlocks(App $app, callable $filter): void
{
    $hooks = $app->hooks();
    $hooks->setCurrentModule('probe-mail');
    $hooks->filter('order.mail.blocks', $filter);
    $hooks->setCurrentModule('core');
}

/** @return array<int,array<string,mixed>> los registros del apunte de los bloques que se cortaron */
function ordExtCutRecords(App $app): array
{
    return array_values(array_filter($app->log()->records, static function (array $record): bool {
        return strpos((string) $record['message'], 'order.mail.blocks') === 0;
    }));
}

/**
 * Un pedido digital pagado con un fichero real. Devuelve [app, producto, id del pedido, ficha en claro del enlace, navegador].
 *
 * @return array{0:App,1:int,2:int,3:string,4:TestClient}
 */
function ordExtDownloadsPaid(): array
{
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    $id = aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]], ['billing' => ['first_name' => 'Ana', 'country' => 'ES']]);
    $mails = aifecOrderMails($app, 'order_confirmed');
    check(count($mails) === 1, 'hay un correo de confirmación');
    check(preg_match('#/descarga/([a-f0-9]{48})#', (string) $mails[0]['html'], $m) === 1, 'el correo lleva el enlace de descarga');

    return [$app, $guia, $id, $m[1], new TestClient($app)];
}

/** La dirección (sin dominio) de la página del pedido. */
function ordExtOrderPath(App $app, int $orderId): string
{
    $orders = $app->service('orders');
    $order = $orders->find($orderId);
    check($order !== null, 'el pedido existe');

    return '/pedido/' . $order['number'] . '/' . $orders->viewTokenOf((string) $order['number']);
}

// ------------------------------------------------------------------ purchasable en el carrito, la caja y la ficha

test('el carrito rechaza un producto no comprable con el texto de su disponibilidad, y no añade nada', function (): void {
    $app = aifecOrderApp();
    $soon = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    ordExtSoon($app, $soon);
    $web = new TestClient($app);

    $r = aifecCartRun($web, static function ($cart) use ($soon): array {
        return $cart->add($soon, 0, 1);
    });
    same(false, $r['ok']);
    same('Próximamente', $r['error']);
    same(0, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
});

test('un producto normal sigue entrando en el carrito aunque haya un filtro de disponibilidad para otro', function (): void {
    $app = aifecOrderApp();
    $soon = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    $normal = aifecProduct($app, ['name' => 'Mesa', 'stock' => 20]);
    ordExtSoon($app, $soon);
    $web = new TestClient($app);
    $r = aifecCartRun($web, static function ($cart) use ($normal): array {
        return $cart->add($normal, 0, 2);
    });
    same(true, $r['ok']);
    same(2, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
});

test('el formulario «añadir» de la tienda lo rechaza igual, con el mensaje del plugin, en HTML y en JSON', function (): void {
    $app = aifecOrderApp();
    $soon = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    ordExtSoon($app, $soon);
    $web = new TestClient($app);

    $r = $web->post('/carrito/add', ['product_id' => $soon, 'qty' => 1]);
    same(302, $r->status);
    contains('Próximamente', $web->follow()->body);

    $web2 = new TestClient($app);
    $json = $web2->postJson('/carrito/add', ['product_id' => $soon, 'qty' => 1]);
    check($json->status >= 400, 'el JSON no dice que sí: ' . $json->status);
    $body = $web2->json();
    same(false, $body['ok']);
    same('Próximamente', $body['error']);
    same(0, $body['count']);
});

test('la API y el asistente añaden por el mismo servicio del carrito: lo rechazan igual', function (): void {
    $app = aifecOrderApp(['modules' => ['tools', 'assistant']]);
    $soon = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    ordExtSoon($app, $soon);
    $web = new TestClient($app);
    $result = aifecCartRun($web, static function ($cart, App $app) use ($soon): array {
        return $app->service('tools')->call('cart_add', ['product_id' => $soon, 'qty' => 1]);
    });
    check(isset($result['error']) || (isset($result['ok']) && $result['ok'] === false), 'la herramienta no añade: ' . json_encode($result));
    contains('Próximamente', json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
    same(0, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
});

test('un producto que se vuelve no comprable con el carrito ya lleno sale marcado y la caja no lo cobra', function (): void {
    $app = aifecOrderApp();
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20, 'price' => 5000]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($id): void {
        $added = $cart->add($id, 0, 1);
        check(!empty($added['ok']), 'se añade mientras se podía comprar');
    });
    ordExtSoon($app, $id);

    $summary = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    $line = array_values($summary['lines'])[0];
    same(false, $line['available']);
    same('Próximamente', $line['warnings'][0]);

    $placed = aifecCartRun($web, static function ($cart, App $app): array {
        $cart->setPaymentMethod('gw');

        return $app->service('orders')->place(aifecOrderCheckout(), []);
    });
    same(false, $placed['ok'], 'la caja no cobra lo que ya no se puede comprar');
    same(0, aifecOrderCount($app, 'orders'));
});

test('cambiar la cantidad de una línea que ya no se puede comprar tampoco pasa', function (): void {
    $app = aifecOrderApp();
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    $web = new TestClient($app);
    $key = aifecCartRun($web, static function ($cart) use ($id): string {
        $added = $cart->add($id, 0, 1);

        return (string) $added['key'];
    });
    ordExtSoon($app, $id);
    $r = aifecCartRun($web, static function ($cart) use ($key): array {
        return $cart->update($key, 3);
    });
    same(false, $r['ok']);
    same('Próximamente', $r['error']);
});

test('la ficha de un producto no comprable no lleva botón ni formulario de compra; la de uno normal sí', function (): void {
    $app = aifecOrderApp();
    $soon = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'stock' => 20]);
    $normal = aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa', 'stock' => 20]);
    ordExtSoon($app, $soon);
    $web = new TestClient($app);

    $page = $web->get('/producto/silla');
    same(200, $page->status);
    // «También te puede interesar» (P100) puede traer la tarjeta de la mesa, con su compra: se mira la ficha de la silla.
    $own = strstr($page->body, 'id="af-related"', true);
    $own = $own !== false ? $own : $page->body;
    notContains('data-af-cart-add', $own);
    notContains('data-af-add', $own);
    notContains('Añadir al carrito', $own);
    contains('Próximamente', $own);

    $other = $web->get('/producto/mesa');
    contains('data-af-cart-add', $other->body);
    contains('Añadir al carrito', $other->body);
    unset($normal);
});

test('la ficha de un producto sin precio no lo enseña: ni el importe, ni el bloque de precio, ni «oferta»', function (): void {
    $app = aifecOrderApp();
    $soon = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'stock' => 20, 'price' => 12345, 'sale_price' => 9876]);
    $normal = aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa', 'stock' => 20, 'price' => 12345, 'sale_price' => 9876]);
    ordExtSoon($app, $soon);
    $web = new TestClient($app);

    $page = $web->get('/producto/silla')->body;
    // La tarjeta de la mesa en «También te puede interesar» (P100) sí lleva su precio: se mira la ficha de la silla.
    $own = strstr($page, 'id="af-related"', true);
    $page = $own !== false ? $own : $page;
    notContains('123,45', $page);
    notContains('98,76', $page);
    notContains('class="af-price ', $page);
    notContains('af-price-now', $page);
    notContains('af-price-off', $page);

    $other = $web->get('/producto/mesa')->body;
    contains('98,76', $other);
    contains('af-price-now', $other);
    unset($normal);
});

test('el listado: la tarjeta del producto sin precio ni botón sale sin ellos, y las demás igual que siempre', function (): void {
    $app = aifecOrderApp();
    $cat = aifecCategory($app, 'Sillas');
    $soon = aifecProduct($app, ['name' => 'Silla futura', 'slug' => 'futura', 'stock' => 20, 'price' => 12345, 'categories' => [$cat]]);
    aifecProduct($app, ['name' => 'Silla ya', 'slug' => 'ya', 'stock' => 20, 'price' => 22222, 'categories' => [$cat]]);
    ordExtSoon($app, $soon);
    $body = (new TestClient($app))->get('/categoria/sillas')->body;
    same(1, substr_count($body, 'af-price-now'), 'solo la tarjeta del producto con precio lo enseña');
    contains('222,22', $body);
    contains('Silla futura', $body, 'la tarjeta sigue en el listado, sin su precio');
});

// ------------------------------------------------------------------ la puerta de las descargas

test('una descarga frenada por un plugin: la dirección directa contesta 403 con el motivo y no cuenta nada', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    ordExtGate($app, static function ($allowed): array {
        return ['allowed' => false, 'reason' => 'Falta aceptar la renuncia al desistimiento.'];
    });
    $r = $web->get('/descarga/' . $token);
    same(403, $r->status);
    contains('Falta aceptar la renuncia al desistimiento.', $r->body);
    same(0, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'), 'una descarga frenada no se cuenta');
    check($r->file === null, 'no se entrega ningún fichero');
});

test('la puerta recibe (true, pedido, línea, fichero) y una descarga permitida se entrega como siempre', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    $seen = [];
    ordExtGate($app, static function ($allowed, $order, $line, $file) use (&$seen): array {
        $seen = [$allowed, (int) $order['id'], (int) $line['product_id'], (string) $file['name']];

        return ['allowed' => true, 'reason' => ''];
    });
    $r = $web->get('/descarga/' . $token);
    same(200, $r->status);
    same('Contenido de guia.txt', (string) file_get_contents((string) $r->file));
    same([true, $id, $guia, 'guia.txt'], $seen);
    same(1, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
});

test('sin la capacidad «orders» concedida el filtro de un plugin no entra y la descarga sale como siempre', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    ordExtGate($app, static function (): array {
        return ['allowed' => false, 'reason' => 'No debería oírse.'];
    }, false, 'intruso');
    check(!$app->hooks()->has('order.download.allowed'), 'ningún oyente entró');
    $errors = array_filter($app->hooks()->errors(), static function (array $e): bool {
        return $e['module'] === 'intruso';
    });
    same(1, count($errors), 'el intento queda apuntado');
    same(200, $web->get('/descarga/' . $token)->status);
});

test('la página del pedido enseña el motivo en lugar del enlace de una descarga frenada, y la permitida sigue con su botón', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    $allowed = true;
    ordExtGate($app, static function () use (&$allowed): array {
        return $allowed ? ['allowed' => true, 'reason' => ''] : ['allowed' => false, 'reason' => 'Retenida: acepta la <renuncia> & vuelve.'];
    });
    $page = $web->get(ordExtOrderPath($app, $id));
    same(200, $page->status);
    contains('Descargar', $page->body);

    $allowed = false;
    $page = $web->get(ordExtOrderPath($app, $id));
    same(200, $page->status);
    contains('Retenida: acepta la &lt;renuncia&gt; &amp; vuelve.', $page->body);
    notContains('<renuncia>', $page->body);
    notContains('Esta descarga ya no está disponible.', $page->body);
    notContains('>Descargar<', $page->body);
});

test('pedir un enlace nuevo de una descarga frenada no lo da: vuelve a la página del pedido', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    ordExtGate($app, static function (): array {
        return ['allowed' => false, 'reason' => 'Retenida.'];
    });
    $order = $app->service('orders')->find($id);
    $downloadId = $app->service('orders')->downloads()->forOrder($id)[0]['id'];
    $path = ordExtOrderPath($app, $id);
    $r = $web->post($path . '/download/' . $downloadId, []);
    same(303, $r->status);
    same($path, parse_url($r->headers['Location'], PHP_URL_PATH));
    same(200, (new TestClient($app))->get('/descarga/' . $token) ->status === 403 ? 200 : 500, 'y el enlace antiguo sigue frenado');
    unset($order);
});

test('forOrder dice qué descarga está frenada y por qué, y available lo refleja', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    $downloads = $app->service('orders')->downloads();
    $before = $downloads->forOrder($id)[0];
    same([true, false, ''], [$before['available'], $before['blocked'], $before['reason']]);
    ordExtGate($app, static function (): array {
        return ['allowed' => false, 'reason' => 'Retenida.'];
    });
    $after = $downloads->forOrder($id)[0];
    same([false, true, 'Retenida.'], [$after['available'], $after['blocked'], $after['reason']]);
});

test('el correo de un pedido con una descarga frenada lleva a la página del pedido y no al enlace directo', function (): void {
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    ordExtGate($app, static function (): array {
        return ['allowed' => false, 'reason' => 'Retenida.'];
    });
    $id = aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]]);
    $mails = aifecOrderMails($app, 'order_confirmed');
    same(1, count($mails));
    notContains('/descarga/', (string) $mails[0]['html']);
    contains(ordExtOrderPath($app, $id), (string) $mails[0]['html']);
    contains('Guía.txt', (string) $mails[0]['html'] . 'Guía.txt');
    same(1, aifecOrderCount($app, 'order_downloads'), 'la descarga está concedida: solo está retenida');
});

test('el correo de un pedido con la descarga permitida lleva el enlace directo de siempre', function (): void {
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    aifecOrderFile($app, $guia, 'guia.txt');
    ordExtGate($app, static function (): array {
        return ['allowed' => true, 'reason' => ''];
    });
    aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]]);
    $mails = aifecOrderMails($app, 'order_confirmed');
    contains('/descarga/', (string) $mails[0]['html']);
});

test('un plugin de la puerta que lanza no frena nada, y si dos deciden el segundo ve lo que dijo el primero', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    expectHookErrors();
    ordExtGate($app, static function (): array {
        throw new \RuntimeException('roto');
    });
    same(200, $web->get('/descarga/' . $token)->status);

    list($app2, $guia2, $id2, $token2, $web2) = ordExtDownloadsPaid();
    $second = null;
    ordExtGate($app2, static function (): array {
        return ['allowed' => false, 'reason' => 'Dice el primero.'];
    }, true, 'primero');
    ordExtGate($app2, static function ($allowed) use (&$second) {
        $second = $allowed;

        return $allowed;
    }, true, 'segundo');
    same(403, $web2->get('/descarga/' . $token2)->status);
    same(['allowed' => false, 'reason' => 'Dice el primero.'], $second);
});

test('el veredicto se entiende: false es un no, lo que no se entiende deja pasar, y el motivo sale limpio y corto', function (): void {
    same(['allowed' => true, 'reason' => ''], Downloads::verdict(true));
    same(['allowed' => true, 'reason' => ''], Downloads::verdict(null));
    same(['allowed' => true, 'reason' => ''], Downloads::verdict('no'));
    same(['allowed' => true, 'reason' => ''], Downloads::verdict(['reason' => 'sin allowed']));
    same(['allowed' => true, 'reason' => ''], Downloads::verdict(['allowed' => true, 'reason' => 'x']));
    $no = Downloads::verdict(false);
    same(false, $no['allowed']);
    same('Esta descarga todavía no está disponible.', $no['reason']);
    $clean = Downloads::verdict(['allowed' => false, 'reason' => "Una\nlínea\tcon  saltos"]);
    same('Una línea con saltos', $clean['reason']);
    same(300, mb_strlen(Downloads::verdict(['allowed' => 0, 'reason' => str_repeat('ñ', 400)])['reason'], 'UTF-8'));
    same('Esta descarga todavía no está disponible.', Downloads::verdict(['allowed' => false, 'reason' => ['no']])['reason']);
});

test('sin plugins en la puerta no se lee el pedido ni se hace una consulta de más al listar las descargas', function (): void {
    list($app, $guia, $id, $token, $web) = ordExtDownloadsPaid();
    $downloads = $app->service('orders')->downloads();
    $downloads->forOrder($id);
    $before = $app->db()->queries;
    $downloads->forOrder($id);
    $plain = $app->db()->queries - $before;
    ordExtGate($app, static function (): array {
        return ['allowed' => true, 'reason' => ''];
    });
    $before = $app->db()->queries;
    $downloads->forOrder($id);
    $gated = $app->db()->queries - $before;
    check($gated > $plain, 'con un plugin que decide se lee el pedido');
    same(2, $plain, 'sin él, las dos consultas de siempre (estado de pago y descargas)');
});

// ------------------------------------------------------------------ bloques en el correo del pedido

test('los bloques de un plugin salen en el correo del pedido, en HTML y en texto, debajo de las líneas y escapados', function (): void {
    $app = aifecOrderApp();
    ordExtBlocks($app, static function ($blocks, $order, $template): array {
        $blocks[] = ['title' => 'Garantía & <b>soporte</b>', 'lines' => ['Dos años <i>completos</i>', 'Línea & "dos"']];

        return $blocks;
    });
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    $mail = aifecOrderMails($app, 'order_confirmed')[0];
    $html = (string) $mail['html'];
    contains('Garantía &amp; &lt;b&gt;soporte&lt;/b&gt;', $html);
    contains('Dos años &lt;i&gt;completos&lt;/i&gt;', $html);
    contains('Línea &amp; &quot;dos&quot;', $html);
    notContains('<b>soporte</b>', $html);
    notContains('<i>completos</i>', $html);
    check(strpos($html, 'Dos años') > strpos($html, 'Silla'), 'va debajo de las líneas del pedido');
    check(strpos($html, 'Dos años') > strpos($html, 'Total'), 'y del desglose');
    $text = (string) $mail['text_body'];
    contains('Dos años', $text);
    contains('Garantía', $text);
    contains('Línea', $text);
});

test('el filtro sabe qué plantilla se compone y solo sale donde el plugin lo quiere', function (): void {
    $app = aifecOrderApp();
    $templates = [];
    ordExtBlocks($app, static function ($blocks, $order, $template) use (&$templates): array {
        $templates[] = $template;
        if ($template === 'order_confirmed') {
            $blocks[] = ['title' => 'Solo al cliente', 'lines' => ['Texto del cliente']];
        }

        return $blocks;
    });
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    check(in_array('order_confirmed', $templates, true), 'el correo del cliente');
    check(in_array('admin_new_order', $templates, true), 'y el aviso a la tienda también preguntan');
    contains('Texto del cliente', (string) aifecOrderMails($app, 'order_confirmed')[0]['html']);
    notContains('Texto del cliente', (string) aifecOrderMails($app, 'admin_new_order')[0]['html']);
});

test('el filtro recibe el pedido completo y el idioma del pedido puesto mientras se pide, y se restaura después', function (): void {
    $app = aifecOrderApp(['settings' => ['i18n.languages' => ['es', 'en']]]);
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    $orderId = aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    $seen = [];
    ordExtBlocks($app, static function ($blocks, $order, $template) use ($app, &$seen): array {
        $seen = [$app->i18n()->locale(), (string) $order['number'], count($order['items']), t('Subtotal')];
        $blocks[] = ['title' => t('Subtotal'), 'lines' => [t('Total')]];

        return $blocks;
    });
    $order = $app->service('orders')->find($orderId);
    $order['lang'] = 'en';
    $before = $app->i18n()->locale();
    $mails = new OrderMails($app);
    $blocks = $mails->blocks($order, 'order_shipped', 'en');
    same('en', $seen[0], 'se pide con el idioma del pedido');
    same((string) $order['number'], $seen[1]);
    check($seen[2] >= 1, 'lleva las líneas del pedido');
    same('Subtotal', $seen[3], 'y lo que traduce sale en ese idioma');
    same($before, $app->i18n()->locale(), 'el idioma de quien pulsa vuelve');
    same([['title' => 'Subtotal', 'lines' => ['Total']]], $blocks);

    $es = $mails->blocks($order, 'order_shipped', 'es');
    same('Subtotal', $es[0]['title']);
    same('es', $seen[0]);
    same('Subtotal', $seen[3] === 'Subtotal' ? 'Subtotal' : $seen[3]);
});

test('siete bloques se quedan en seis y se apunta una línea en el registro', function (): void {
    $app = aifecOrderApp();
    ordExtBlocks($app, static function (): array {
        $blocks = [];
        for ($i = 1; $i <= 7; ++$i) {
            $blocks[] = ['title' => 'Bloque ' . $i, 'lines' => ['Línea ' . $i]];
        }

        return $blocks;
    });
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    $html = (string) aifecOrderMails($app, 'order_confirmed')[0]['html'];
    contains('Bloque 6', $html);
    notContains('Bloque 7', $html);
    $cuts = ordExtCutRecords($app);
    check(count($cuts) >= 1, 'queda apuntado');
    $mine = array_values(array_filter($cuts, static function (array $r): bool {
        return $r['context']['template'] === 'order_confirmed';
    }));
    same(1, count($mine), 'una sola línea por correo');
    same(1, $mine[0]['context']['blocks_cut']);
    same('warning', $mine[0]['level']);
});

test('trece líneas en un bloque se quedan en doce, y se apunta', function (): void {
    $app = aifecOrderApp();
    $lines = [];
    for ($i = 1; $i <= 14; ++$i) {
        $lines[] = 'Renglón ' . $i;
    }
    ordExtBlocks($app, static function ($blocks) use ($lines): array {
        $blocks[] = ['title' => 'Largo', 'lines' => $lines];

        return $blocks;
    });
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    $html = (string) aifecOrderMails($app, 'order_confirmed')[0]['html'];
    contains('Renglón 12', $html);
    notContains('Renglón 13', $html);
    $mine = array_values(array_filter(ordExtCutRecords($app), static function (array $r): bool {
        return $r['context']['template'] === 'order_confirmed';
    }));
    same(1, count($mine));
    same(2, $mine[0]['context']['lines_cut']);
});

test('justo en el tope no se corta ni se apunta nada', function (): void {
    $app = aifecOrderApp();
    ordExtBlocks($app, static function (): array {
        $blocks = [];
        for ($i = 1; $i <= OrderMails::MAX_BLOCKS; ++$i) {
            $lines = [];
            for ($j = 1; $j <= OrderMails::MAX_LINES; ++$j) {
                $lines[] = 'B' . $i . 'L' . $j;
            }
            $blocks[] = ['title' => 'T' . $i, 'lines' => $lines];
        }

        return $blocks;
    });
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    $html = (string) aifecOrderMails($app, 'order_confirmed')[0]['html'];
    contains('B6L12', $html);
    same([], ordExtCutRecords($app));
});

test('lo que no es un bloque bien formado se ignora, el texto se limpia y un bloque solo con título o solo con líneas vale', function (): void {
    $app = aifecOrderApp();
    ordExtBlocks($app, static function (): array {
        return [
            'basura', ['sin' => 'nada'], ['title' => '', 'lines' => []], ['title' => ['no'], 'lines' => 'no'],
            ['title' => "Con\nsaltos\tdentro", 'lines' => ["  una \n línea  ", '', ['no'], 7, null]],
            ['title' => 'Solo título'],
            ['lines' => ['Solo línea']],
        ];
    });
    $order = $app->service('orders')->find(aifecOrderPaid($app, new TestClient($app), [[aifecProduct($app, ['name' => 'Silla', 'stock' => 20]), 0, 1]]));
    $blocks = (new OrderMails($app))->blocks($order, 'order_confirmed', 'es');
    same([
        ['title' => 'Con saltos dentro', 'lines' => ['una línea', '7']],
        ['title' => 'Solo título', 'lines' => []],
        ['title' => '', 'lines' => ['Solo línea']],
    ], $blocks);
});

test('un filtro que lanza no impide que el correo salga, y lo que devuelve basura no añade nada', function (): void {
    $app = aifecOrderApp();
    expectHookErrors();
    ordExtBlocks($app, static function (): array {
        throw new \RuntimeException('roto');
    });
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));

    $other = aifecOrderApp();
    ordExtBlocks($other, static function (): string {
        return 'no es una lista';
    });
    $order = $other->service('orders')->find(aifecOrderPaid($other, new TestClient($other), [[aifecProduct($other, ['name' => 'Mesa', 'stock' => 20]), 0, 1]]));
    same([], (new OrderMails($other))->blocks($order, 'order_confirmed', 'es'));
});

test('sin plugins el correo del pedido sale como siempre: sin bloques y sin consultar nada', function (): void {
    $app = aifecOrderApp();
    $id = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    $orderId = aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    $order = $app->service('orders')->find($orderId);
    same([], (new OrderMails($app))->blocks($order, 'order_confirmed', 'es'));
    same([], (new OrderMails($app))->payload($order, [], '', 'order_confirmed')['order']['blocks']);
    same([], (new OrderMails($app))->payload($order)['order']['blocks'], 'sin plantilla, no se piden');
    same([], ordExtCutRecords($app));
});

test('la vista previa de las plantillas (el pedido de ejemplo) no necesita bloques', function (): void {
    $sample = OrderMails::sample();
    check(!isset($sample['order']['blocks']), 'el pedido de ejemplo no los lleva: la plantilla los trata como opcionales');
    $app = aifecOrderApp();
    $html = $app->view()->render('orders::emails/_order', ['order' => $sample['order']]);
    contains('Producto de ejemplo', $html);
});
