<?php
/**
 * AIFEC — pruebas de GM legal texts per product: los bloques salen donde toca (por producto y por categoría), se escapan, la caja
 * pregunta por la renuncia al desistimiento sin impedir comprar y guarda la respuesta, el correo legal sale una sola vez, el
 * panel pide permiso y el plugin se desinstala limpio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Gm\LegalProducto\Blocks;

require_once dirname(__DIR__, 3) . '/modules/orders/tests/FrontKit.php';

/** @return string[] */
function gmLegActive(): array
{
    return ['plugins', 'orders', 'mail', 'media', 'jobs', 'gm-legal-producto'];
}

/**
 * @param array<string,mixed> $settings
 */
function gmLegShop(array $settings = []): App
{
    $app = frontShop(['settings' => $settings + ['modules.active' => gmLegActive()], 'modules' => ['plugins', 'gm-legal-producto']]);
    $app->migrate(true);

    return $app;
}

/**
 * Crea un bloque legal y lo asigna.
 *
 * @param array<string,mixed> $data
 * @param int[]               $categories
 * @param int[]               $products
 */
function gmLegBlock(App $app, string $title, string $body, array $data = [], array $categories = [], array $products = []): int
{
    static $n = 0;
    ++$n;
    $now = $app->clock()->sql();
    $id = $app->db()->insert('gm_legal_blocks', $data + ['code' => 'bloque-' . $n, 'title' => $title, 'kind' => 'guarantee', 'body' => $body, 'active' => 1, 'in_mail' => 1, 'position' => 0, 'created_at' => $now, 'updated_at' => $now]);
    (new Blocks($app))->assign($id, $categories, $products);

    return $id;
}

/**
 * Hace el pedido desde la caja como lo haría un navegador (con la pasarela de pruebas que cobra al instante). Con lo digital solo
 * se piden los datos de facturación; con lo físico, la dirección de entrega.
 *
 * @param array<string,string|null> $changes campos que se añaden o cambian en el formulario
 *
 * @return array{0:string,1:string} número y ficha del pedido
 */
function gmLegPlace(App $app, TestClient $web, bool $digital, array $changes = []): array
{
    static $n = 0;
    ++$n;
    aifecOrderPayment($app)->action = PaymentAction::paid('PAY-LEG-' . $n);
    $page = $web->get('/finalizar-compra');
    check($page->status === 200, 'la caja se abre');
    $base = $digital ? ['email' => 'ana@cliente.test', 'billing[first_name]' => 'Ana', 'billing[last_name]' => 'Ruiz', 'accept_terms' => '1'] : frontFill();

    return frontOrderPath($web->post('/finalizar-compra/place', frontForm($web, $changes + $base, $page->body)));
}

/** El producto tal como lo reciben los huecos. @return array<string,mixed> */
function gmLegProduct(App $app, int $id): array
{
    $product = aifecCatalog($app)->product($id);
    check(is_array($product), 'el producto existe');

    return $product;
}

test('gm-legal-producto se activa, se carga y crea sus tablas', function (): void {
    $app = gmLegShop();
    check($app->modules()->isLoaded('gm-legal-producto'), 'el plugin está cargado');
    check(!isset($app->modules()->failed()['gm-legal-producto']), 'no falló al cargar');
    foreach (['gm_legal_blocks', 'gm_legal_assignments', 'gm_legal_orders'] as $table) {
        check($app->db()->tableExists($table), 'existe ' . $table);
    }
});

test('el bloque asignado por categoría sale en la ficha de un producto de esa categoría y de sus subcategorías, y no en otro', function (): void {
    $app = gmLegShop();
    $root = aifecCategory($app, 'Programas');
    $child = aifecCategory($app, 'Utilidades', $root);
    $other = aifecCategory($app, 'Mesas');
    gmLegBlock($app, 'Garantía legal', 'Dos años de garantía.', [], [$root]);

    $inRoot = aifecProduct($app, ['name' => 'A', 'categories' => [$root]]);
    $inChild = aifecProduct($app, ['name' => 'B', 'categories' => [$child]]);
    $elsewhere = aifecProduct($app, ['name' => 'C', 'categories' => [$other]]);
    $nowhere = aifecProduct($app, ['name' => 'D']);

    foreach ([$inRoot, $inChild] as $id) {
        $html = $app->hooks()->render('product.summary.after', gmLegProduct($app, $id));
        contains('Garantía legal', $html);
        contains('Dos años de garantía.', $html);
    }
    same('', $app->hooks()->render('product.summary.after', gmLegProduct($app, $elsewhere)));
    same('', $app->hooks()->render('product.summary.after', gmLegProduct($app, $nowhere)));
});

test('la asignación directa a un producto, el orden por posición y los bloques apagados', function (): void {
    $app = gmLegShop();
    $id = aifecProduct($app, ['name' => 'Licencia']);
    $other = aifecProduct($app, ['name' => 'Otra']);
    gmLegBlock($app, 'Segundo', 'Texto dos', ['position' => 20], [], [$id]);
    gmLegBlock($app, 'Primero', 'Texto uno', ['position' => 10], [], [$id]);
    gmLegBlock($app, 'Apagado', 'No sale', ['active' => 0], [], [$id]);
    $html = $app->hooks()->render('product.summary.after', gmLegProduct($app, $id));
    check(strpos($html, 'Primero') < strpos($html, 'Segundo'), 'por posición');
    notContains('Apagado', $html);
    notContains('No sale', $html);
    same('', $app->hooks()->render('product.summary.after', gmLegProduct($app, $other)));
});

test('el ajuste mueve los bloques al final de la ficha y nunca salen en los dos sitios', function (): void {
    $app = gmLegShop(['gm_legal_producto.placement' => 'bottom']);
    $id = aifecProduct($app, ['name' => 'Licencia']);
    gmLegBlock($app, 'Soporte', 'Soporte por correo', [], [], [$id]);
    same('', $app->hooks()->render('product.summary.after', gmLegProduct($app, $id)));
    contains('Soporte por correo', $app->hooks()->render('product.bottom', gmLegProduct($app, $id)));
});

test('el texto del comerciante se escapa y cada línea es un párrafo', function (): void {
    $app = gmLegShop();
    $id = aifecProduct($app, ['name' => 'Licencia']);
    gmLegBlock($app, 'Pérez & <Hijos>', "Línea con <b>negrita</b> & comillas \"x\"\n\nSegunda línea", [], [], [$id]);
    $html = $app->hooks()->render('product.summary.after', gmLegProduct($app, $id));
    contains('Pérez &amp; &lt;Hijos&gt;', $html);
    contains('&lt;b&gt;negrita&lt;/b&gt; &amp; comillas &quot;x&quot;', $html);
    notContains('<b>', $html);
    same(2, substr_count($html, '<p class="af-small">'));
});

test('la ficha de verdad (página servida) lleva el bloque y sigue siendo cacheable', function (): void {
    $app = gmLegShop();
    $id = aifecProduct($app, ['name' => 'Licencia', 'stock' => 20]);
    gmLegBlock($app, 'Compatibilidad', 'Funciona con la versión 8', [], [], [$id]);
    $web = new TestClient($app);
    $r = $web->get(parse_url(gmLegProduct($app, $id)['url'], PHP_URL_PATH));
    same(200, $r->status);
    contains('Funciona con la versión 8', $r->body);
    check($r->cacheTtl > 0, 'es cacheable');
});

test('la caja enseña los textos y la casilla de la renuncia solo con contenido digital', function (): void {
    $app = gmLegShop();
    $web = new TestClient($app);
    $digital = aifecProduct($app, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000]);
    gmLegBlock($app, 'Desistimiento', 'Contenido digital sin devolución', ['kind' => 'withdrawal'], [], [$digital]);
    frontAdd($web, $digital, 1);
    $page = $web->get('/finalizar-compra');
    same(200, $page->status);
    contains('name="gm_legal_waiver"', $page->body);
    contains('Contenido digital sin devolución', $page->body);
    contains('Esta casilla es opcional.', $page->body);

    $app2 = gmLegShop();
    $web2 = new TestClient($app2);
    $physical = frontProduct($app2);
    gmLegBlock($app2, 'Garantía', 'Dos años', [], [], [$physical]);
    frontAdd($web2, $physical, 1);
    $page2 = $web2->get('/finalizar-compra');
    contains('Dos años', $page2->body);
    notContains('gm_legal_waiver', $page2->body);
});

test('marcar la casilla guarda la renuncia con su versión y desmarcarla no impide comprar y guarda que no la dio', function (): void {
    $app = gmLegShop(['gm_legal_producto.version' => '1.1']);
    $digital = aifecProduct($app, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000]);

    $web = new TestClient($app);
    frontAdd($web, $digital, 1);
    [$number] = gmLegPlace($app, $web, true, ['gm_legal_waiver' => '1']);
    $row = $app->db()->row('SELECT * FROM {gm_legal_orders} WHERE order_id = (SELECT id FROM {orders} WHERE number = ?)', [$number]);
    check($row !== null, 'queda el consentimiento en el pedido');
    same(1, (int) $row['waiver']);
    same('1.1', (string) $row['version']);
    same('download', (string) $row['kind']);
    same('Pido que la descarga empiece ya y entiendo que, una vez empiece, pierdo mi derecho de desistimiento.', (string) $row['text'], 'con el texto exacto que vio');
    check(strtotime((string) $row['created_at'] . ' UTC') > 0, 'y la fecha y la hora');
    same(0, aifecOrderCount($app, 'consents', "kind = 'withdrawal_waiver'"), 'sin copia en la tabla de consentimientos: allí iría la IP');

    $web2 = new TestClient($app);
    frontAdd($web2, $digital, 1);
    [$number2] = gmLegPlace($app, $web2, true, ['email' => 'luis@cliente.test']);
    $row2 = $app->db()->row('SELECT * FROM {gm_legal_orders} WHERE order_id = (SELECT id FROM {orders} WHERE number = ?)', [$number2]);
    check($row2 !== null, 'también queda lo que contestó');
    same(0, (int) $row2['waiver']);
    check($app->service('orders')->findByNumber($number2) !== null, 'el pedido se hizo igualmente');
});

test('un pedido sin contenido digital no pregunta ni guarda renuncia', function (): void {
    $app = gmLegShop();
    $web = new TestClient($app);
    $product = frontProduct($app);
    frontAdd($web, $product, 1);
    gmLegPlace($app, $web, false);
    same(0, aifecOrderCount($app, 'consents', "kind = 'withdrawal_waiver'"));
    same(0, aifecOrderCount($app, 'gm_legal_orders'));
});

test('al pagarse, los textos legales van dentro del correo del pedido, una sola vez y solo con los bloques marcados para el correo', function (): void {
    $app = gmLegShop();
    $id = aifecProduct($app, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000]);
    gmLegBlock($app, 'Soporte & garantía', 'Soporte del programa', [], [], [$id]);
    gmLegBlock($app, 'Solo en la ficha', 'No va al correo', ['in_mail' => 0], [], [$id]);
    $web = new TestClient($app);
    $orderId = aifecOrderPaid($app, $web, [[$id, 0, 1]]);
    same(0, count(aifecOrderMails($app, 'legal_notice')), 'el correo aparte desaparece: el del pedido ya lleva los textos');
    $mails = aifecOrderMails($app, 'order_confirmed');
    same(1, count($mails));
    same('ana@cliente.test', (string) $mails[0]['to_email']);
    contains('Soporte &amp; garantía', (string) $mails[0]['html']);
    contains('Soporte del programa', (string) $mails[0]['html']);
    contains('Soporte & garantía', (string) $mails[0]['text_body'], 'y en la versión de texto');
    notContains('No va al correo', (string) $mails[0]['html']);
    check((string) $app->db()->value('SELECT mailed_at FROM {gm_legal_orders} WHERE order_id = ?', [$orderId]) !== '', 'se apunta cuándo se mandó');

    // Otro aviso de pago del mismo pedido no manda otro.
    $order = $app->service('orders')->find($orderId);
    $app->hooks()->fire('order.paid', $order);
    same(0, count(aifecOrderMails($app, 'legal_notice')));
    same(1, count(aifecOrderMails($app, 'order_confirmed')));

    // Un pedido sin bloques no lleva textos legales.
    $other = aifecProduct($app, ['name' => 'Mesa']);
    aifecOrderPaid($app, new TestClient($app), [[$other, 0, 1]]);
    $all = aifecOrderMails($app, 'order_confirmed');
    notContains('Soporte del programa', (string) $all[count($all) - 1]['html']);
});

test('el correo de recambio sale solo cuando el correo del pedido no llevó los textos', function (): void {
    $app = gmLegShop();
    $id = aifecProduct($app, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000]);
    gmLegBlock($app, 'Garantía', 'Dos años de garantía', [], [], [$id]);
    $orderId = aifecOrderPaid($app, new TestClient($app), [[$id, 0, 1]]);
    // Se simula un pedido cuyo correo no los llevó: se borra la marca y llega otro aviso de pago.
    $app->db()->update('gm_legal_orders', ['mailed_at' => null], ['order_id' => $orderId]);
    $app->hooks()->fire('order.paid', $app->service('orders')->find($orderId));
    $own = aifecOrderMails($app, 'legal_notice');
    same(1, count($own), 'sale el de recambio');
    contains('Dos años de garantía', (string) $own[0]['html']);
});

test('el panel: los bloques piden permiso, el formulario guarda y asigna, y la ficha del pedido enseña la respuesta', function (): void {
    $app = gmLegShop();
    $category = aifecCategory($app, 'Programas');
    $web = adminClient($app);
    same(200, $web->get('/admin/r/gm_legal_blocks')->status);
    $new = $web->get('/admin/r/gm_legal_blocks/new');
    same(200, $new->status);
    contains('name="f[title]"', $new->body);

    $product = aifecProduct($app, ['name' => 'Licencia']);
    $save = $web->post('/admin/r/gm_legal_blocks/save', ['_token' => aifecToken($web), 'f' => [
        'title' => 'Garantía & más', 'code' => 'garantia', 'kind' => 'guarantee', 'body' => 'Dos años', 'active' => '1', 'in_mail' => '1', 'position' => '1',
        'categories' => [(string) $category], 'products' => $product . ', 999',
    ]]);
    same(302, $save->status, $save->body);
    $id = (int) $app->db()->value("SELECT id FROM {gm_legal_blocks} WHERE code = 'garantia'");
    check($id > 0, 'el bloque se guardó');
    same([$category], (new Blocks($app))->assigned($id, 'category'));
    same([$product, 999], (new Blocks($app))->assigned($id, 'product'));
    contains('Garantía &amp; más', $web->get('/admin/r/gm_legal_blocks')->body);
    $edit = $web->get('/admin/r/gm_legal_blocks/' . $id);
    same(200, $edit->status);
    contains('value="' . $category . '" checked', $edit->body);

    same(403, adminClient($app, 'support')->get('/admin/r/gm_legal_blocks')->status);

    $web->post('/admin/r/gm_legal_blocks/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(0, aifecOrderCount($app, 'gm_legal_assignments'), 'borrar el bloque quita sus asignaciones');
});

test('la ficha del pedido del panel enseña la respuesta a la renuncia', function (): void {
    $app = gmLegShop(['gm_legal_producto.version' => '2.0']);
    $digital = aifecProduct($app, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000]);
    $web = new TestClient($app);
    frontAdd($web, $digital, 1);
    [$number] = gmLegPlace($app, $web, true, ['gm_legal_waiver' => '1']);
    $order = $app->service('orders')->findByNumber($number);
    $html = $app->hooks()->render('admin.order.side', $order);
    contains('Renuncia al desistimiento', $html);
    contains('renunció al derecho de desistimiento', $html);
    contains('2.0', $html);
    same('', $app->hooks()->render('admin.order.side', ['id' => 99999]));
});

test('gm-legal-producto se desactiva y los textos dejan de salir', function (): void {
    $app = gmLegShop();
    $id = aifecProduct($app, ['name' => 'Licencia']);
    gmLegBlock($app, 'Garantía', 'Dos años', [], [], [$id]);
    contains('Dos años', $app->hooks()->render('product.summary.after', gmLegProduct($app, $id)));
    $r = $app->service('plugins')->disable('gm-legal-producto');
    same(true, $r['ok'], (string) $r['message']);
    $off = testApp(['modules' => gmLegActive(), 'settings' => ['modules.active' => $app->settings()->arr('modules.active')]]);
    $off->boot();
    check(!$off->modules()->isLoaded('gm-legal-producto'), 'no se carga');
});

test('gm-legal-producto pasa el contrato AIFEC, también el ciclo de instalar y desinstalar', function (): void {
    $app = gmLegShop();
    $plugins = $app->service('plugins');
    $plugins->useTempBase(tmpDir());
    $report = $plugins->validate('gm-legal-producto');
    $errors = [];
    foreach ($report['checks'] as $check) {
        if (!$check['ok'] && $check['level'] === 'error') {
            $errors[] = $check['rule'] . ': ' . $check['message'] . ' ' . $check['file'] . ':' . $check['line'];
        }
    }
    same([], $errors);
    same(true, $report['ok']);
    same(true, $report['dynamic']);
});

// ------------------------------------------------------------------ P092: la descarga no sale hasta la renuncia

/**
 * Tienda con el plugin y la capacidad «orders» concedida, y un pedido digital pagado desde la caja.
 *
 * @param array<string,string|null> $changes campos del formulario de la caja (la casilla de la renuncia: gm_legal_waiver)
 * @param array<string,mixed>       $settings
 *
 * @return array{0:App,1:int,2:string,3:TestClient} tienda, id del pedido, ficha del enlace de descarga y navegador
 */
function gmLegDigitalOrder(array $changes = [], array $settings = [], bool $granted = true): array
{
    $app = gmLegShop($settings + ['plugins.granted' => $granted ? ['gm-legal-producto:orders'] : []]);
    $id = aifecProduct($app, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000, 'manage_stock' => 0]);
    aifecOrderFile($app, $id, 'programa.txt');
    $web = new TestClient($app);
    frontAdd($web, $id, 1);
    [$number] = gmLegPlace($app, $web, true, $changes);
    $order = $app->service('orders')->findByNumber($number);
    check($order !== null, 'el pedido existe');
    $mails = aifecOrderMails($app, 'order_confirmed');
    $html = (string) $mails[count($mails) - 1]['html'];
    $token = preg_match('#/descarga/([a-f0-9]{48})#', $html, $m) === 1 ? $m[1] : '';

    return [$app, (int) $order['id'], $token, new TestClient($app)];
}

test('sin aceptar la renuncia la descarga no sale: la dirección directa contesta 403 con el motivo y no cuenta nada', function (): void {
    [$app, $orderId, $token, $web] = gmLegDigitalOrder();
    check($token === '', 'el correo no lleva el enlace directo de una descarga retenida');
    $links = $app->db()->value('SELECT COUNT(*) FROM {order_downloads}');
    same(1, (int) $links, 'la descarga está concedida: solo retenida');
    // Con la ficha en claro que se renueva a mano, la puerta también cierra la dirección directa.
    $downloads = $app->service('orders')->downloads();
    $row = $downloads->forOrder($orderId)[0];
    same([false, true], [$row['available'], $row['blocked']]);
    $url = $downloads->renew((int) $row['id'], $orderId);
    $r = $web->get((string) preg_replace('#^https://tienda\.test#', '', (string) $url));
    same(403, $r->status);
    contains('La descarga queda retenida mientras dure tu derecho de desistimiento. Se libera el ', $r->body);
    same(0, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
});

test('aceptando la renuncia en la caja la descarga sale como siempre, y el correo lleva el enlace directo', function (): void {
    [$app, $orderId, $token, $web] = gmLegDigitalOrder(['gm_legal_waiver' => '1']);
    check($token !== '', 'el correo lleva el enlace de descarga');
    $r = $web->get('/descarga/' . $token);
    same(200, $r->status);
    same('Contenido de programa.txt', (string) file_get_contents((string) $r->file));
    same(1, (int) $app->db()->value('SELECT downloads FROM {order_downloads}'));
});

test('la página del pedido enseña el motivo de la descarga retenida en lugar del enlace', function (): void {
    [$app, $orderId, $token, $web] = gmLegDigitalOrder();
    $page = $web->get(gmLegOrderPath($app, $orderId));
    same(200, $page->status);
    contains('La descarga queda retenida mientras dure tu derecho de desistimiento. Se libera el ', $page->body);
    notContains('>Descargar<', $page->body);
    notContains('Esta descarga ya no está disponible.', $page->body);
});

test('el correo de un pedido con la descarga retenida lleva a la página del pedido, no a un enlace directo', function (): void {
    [$app, $orderId] = gmLegDigitalOrder();
    $html = (string) aifecOrderMails($app, 'order_confirmed')[0]['html'];
    notContains('/descarga/', $html);
    contains(gmLegOrderPath($app, $orderId), $html);
});

test('sin la capacidad «orders» concedida, o con la renuncia apagada, o en un pedido del que no se preguntó nada, la descarga no se frena', function (): void {
    [$app, , $token, $web] = gmLegDigitalOrder([], [], false);
    check($token !== '', 'sin capacidad el enlace va en el correo');
    same(200, $web->get('/descarga/' . $token)->status);

    [$off, , $token2, $web2] = gmLegDigitalOrder([], ['gm_legal_producto.waiver_enabled' => false]);
    same(200, $web2->get('/descarga/' . $token2)->status, 'con la renuncia apagada nadie pregunta ni frena');

    [$old, $orderId, $token3, $web3] = gmLegDigitalOrder(['gm_legal_waiver' => '1']);
    $old->db()->delete('gm_legal_orders', ['order_id' => $orderId]);
    same(200, $web3->get('/descarga/' . $token3)->status, 'un pedido sin respuesta guardada (anterior al plugin) se entrega');
});

test('la puerta del plugin no pisa lo que otro plugin ya dijo que no', function (): void {
    $app = gmLegShop(['plugins.granted' => ['gm-legal-producto:orders']]);
    $no = ['allowed' => false, 'reason' => 'Otro dijo que no.'];
    same($no, \Gm\LegalProducto\DownloadGate::filter($no, ['id' => 1], [], [], $app));
    same(['allowed' => true, 'reason' => ''], \Gm\LegalProducto\DownloadGate::filter(true, ['id' => 99999], [], [], $app));
    same(['allowed' => true, 'reason' => ''], \Gm\LegalProducto\DownloadGate::filter(true, 'no es un pedido', [], [], $app));
});

test('el validador del contrato conoce la capacidad «orders» y el gancho sellado de las descargas', function (): void {
    $app = gmLegShop();
    $plugins = $app->service('plugins');
    same('orders', $plugins->sealedHooks()['order.download.allowed']);
    $root = rtrim(str_replace('\\', '/', \Aifec\Autoload::root()), '/');
    $manifest = json_decode((string) file_get_contents($root . '/plugins/gm-legal-producto/plugin.json'), true);
    same(['orders'], $manifest['contract']['capabilities']);
    $run = static function (array $manifest) use ($app, $plugins, $root): array {
        return (new \Aifec\Modules\Plugins\StaticChecks($app->i18n(), $root, 'gm-legal-producto', $manifest, $plugins->protectedTables('gm-legal-producto'), $plugins->sealedHooks()))->run()['findings'];
    };
    $bad = static function (array $findings, string $rule): array {
        return array_values(array_filter($findings, static function (array $f) use ($rule): bool {
            return $f['rule'] === $rule && $f['level'] !== 'info';
        }));
    };
    same([], $bad($run($manifest), 'C1'), 'declarando la capacidad, engancharse a la puerta no falla');
    same([], $bad($run($manifest), 'M1'), 'y la capacidad «orders» no es desconocida');

    $without = $manifest;
    $without['contract']['capabilities'] = [];
    $c1 = $bad($run($without), 'C1');
    check($c1 !== [], 'sin declarar la capacidad, engancharse a la puerta falla');
    contains('order.download.allowed', $c1[0]['message']);

    $odd = $manifest;
    $odd['contract']['capabilities'] = ['orders', 'cosa-rara'];
    $m1 = $bad($run($odd), 'M1');
    check($m1 !== [], 'una capacidad que no existe sigue avisando');
    contains('cosa-rara', $m1[0]['message']);
});

/** La dirección (sin dominio) de la página del pedido. */
function gmLegOrderPath(App $app, int $orderId): string
{
    $orders = $app->service('orders');
    $order = $orders->find($orderId);
    check($order !== null, 'el pedido existe');

    return '/pedido/' . $order['number'] . '/' . $orders->viewTokenOf((string) $order['number']);
}

// ------------------------------------------------------------------ auditoría del 24/11/2026: el desistimiento en la caja

test('el texto de la casilla depende de lo que lleva el carrito: descarga, servicio o las dos cosas; con lo físico no sale', function (): void {
    $app = gmLegShop();
    $web = new TestClient($app);
    $service = aifecProduct($app, ['name' => 'Instalación', 'kind' => 'service', 'price' => 9000]);
    frontAdd($web, $service, 1);
    $page = $web->get('/finalizar-compra')->body;
    contains('name="gm_legal_waiver"', $page);
    contains('Pido que el servicio empiece ya. Si desisto después de que haya empezado, pagaré la parte proporcional de lo prestado.', $page);
    notContains('Pido que la descarga empiece ya', $page);

    $app2 = gmLegShop();
    $web2 = new TestClient($app2);
    $download = aifecProduct($app2, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000]);
    $service2 = aifecProduct($app2, ['name' => 'Instalación', 'kind' => 'service', 'price' => 9000]);
    frontAdd($web2, $download, 1);
    frontAdd($web2, $service2, 1);
    $page2 = $web2->get('/finalizar-compra')->body;
    contains('Pido que la descarga y el servicio empiecen ya.', $page2);
    contains('pagaré la parte proporcional de lo prestado', $page2);
    same(1, substr_count($page2, 'name="gm_legal_waiver"'), 'una sola casilla');

    $app3 = gmLegShop();
    $web3 = new TestClient($app3);
    frontAdd($web3, frontProduct($app3), 1);
    notContains('gm_legal_waiver', $web3->get('/finalizar-compra')->body, 'un carrito sin descarga ni servicio no pregunta');
});

test('la casilla está ENCIMA del botón de pagar, no debajo', function (): void {
    $app = gmLegShop();
    $web = new TestClient($app);
    frontAdd($web, aifecProduct($app, ['name' => 'Programa', 'kind' => 'digital', 'price' => 5000]), 1);
    $page = $web->get('/finalizar-compra')->body;
    $box = strpos($page, 'name="gm_legal_waiver"');
    $button = strpos($page, 'data-af-checkout-submit');
    check($box !== false && $button !== false, 'están las dos cosas');
    check($box < $button, 'la casilla va antes del botón de pagar');
    $form = strpos($page, '<form');
    $close = strpos($page, '</form>', (int) $box);
    check($form !== false && $close !== false && $form < $box && $box < $close, 'y dentro del formulario');
});

test('el pedido guarda el texto exacto, el tipo y la fecha, sin la IP, y el correo lo confirma con fecha', function (): void {
    $app = gmLegShop();
    $service = aifecProduct($app, ['name' => 'Instalación', 'kind' => 'service', 'price' => 9000, 'manage_stock' => 0]);
    $web = new TestClient($app);
    frontAdd($web, $service, 1);
    [$number] = gmLegPlace($app, $web, true, ['gm_legal_waiver' => '1']);
    $row = $app->db()->row('SELECT * FROM {gm_legal_orders} WHERE order_id = (SELECT id FROM {orders} WHERE number = ?)', [$number]);
    same('service', (string) $row['kind']);
    contains('pagaré la parte proporcional', (string) $row['text']);
    foreach (array_keys($row) as $column) {
        notContains('ip', strtolower((string) $column), 'no hay columna de IP');
    }
    $mails = aifecOrderMails($app, 'order_confirmed');
    $html = (string) $mails[count($mails) - 1]['html'];
    contains('marcaste la casilla:', $html);
    contains(substr((string) $row['created_at'], 0, 10), $html, 'con la fecha');
    contains('Pido que el servicio empiece ya.', $html, 'y el texto que marcó');
    contains('pagarás la parte proporcional', $html, 'y lo que implica');

    $web2 = new TestClient($app);
    frontAdd($web2, $service, 1);
    gmLegPlace($app, $web2, true, ['email' => 'luis@cliente.test']);
    $mails2 = aifecOrderMails($app, 'order_confirmed');
    $html2 = (string) $mails2[count($mails2) - 1]['html'];
    contains('no marcaste la casilla:', $html2, 'sin marcar, también lo confirma');
    contains('conservas', $html2);
});

test('sin marcar, la descarga queda retenida los 14 días del desistimiento y pasados se entrega sola', function (): void {
    [$app, $orderId, , $web] = gmLegDigitalOrder();
    $downloads = $app->service('orders')->downloads();
    $row = $downloads->forOrder($orderId)[0];
    same(true, $row['blocked'], 'el primer día está retenida');
    $app->clock()->advance(13 * 86400);
    $url = $downloads->renew((int) $row['id'], $orderId);
    same(403, $web->get((string) preg_replace('#^https://tienda\.test#', '', (string) $url))->status, 'al día 13 sigue retenida');
    $app->clock()->advance(2 * 86400);
    $url2 = $downloads->renew((int) $row['id'], $orderId);
    same(200, $web->get((string) preg_replace('#^https://tienda\.test#', '', (string) $url2))->status, 'pasados los 14 días se entrega');
});
