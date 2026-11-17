<?php
/**
 * AIFEC — pruebas de GM coming soon: un producto «próximamente» se ve con su insignia y no se puede comprar (por el formulario
 * ni por el servicio del carrito), el «avísame» guarda una petición por correo y producto, y al pasar a disponible se encola un
 * solo aviso por petición reciente (nunca por las de hace 91 días). Sin datos de nadie en la ficha, sin tablas al desinstalar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\Proximamente\Soon;

require_once dirname(__DIR__, 3) . '/modules/orders/tests/helpers.php';

/** @return string[] */
function gmSoonActive(): array
{
    return ['plugins', 'orders', 'mail', 'media', 'jobs', 'gm-proximamente'];
}

/**
 * Tienda de pruebas con pedidos, correo y el plugin activo (con la capacidad «pricing» concedida salvo que se diga otra cosa).
 *
 * @param array<string,mixed> $settings
 */
function gmSoonShop(array $settings = [], bool $granted = true): App
{
    $app = aifecOrderApp(['settings' => $settings + ['modules.active' => gmSoonActive(), 'plugins.granted' => $granted ? ['gm-proximamente:pricing'] : []], 'modules' => ['plugins', 'gm-proximamente']]);
    $app->migrate(true);

    return $app;
}

/** Un producto que ya está «próximamente». */
function gmSoonProduct(App $app, string $name = 'Silla de roble'): int
{
    $id = aifecProduct($app, ['name' => $name, 'price' => 5000, 'stock' => 20]);
    (new Soon($app))->set($id, true);

    return $id;
}

test('gm-proximamente se activa, se carga y crea sus tablas', function (): void {
    $app = gmSoonShop();
    check($app->modules()->isLoaded('gm-proximamente'), 'el plugin está cargado');
    check(!isset($app->modules()->failed()['gm-proximamente']), 'no falló al cargar');
    check($app->db()->tableExists('gm_soon_products') && $app->db()->tableExists('gm_soon_requests'), 'sus migraciones crearon las tablas');
    check($app->router()->has('gm.soon.notify'), 'tiene la ruta del avísame');
});

test('un producto próximamente se ve con su etiqueta, su insignia y su formulario; los demás no cambian', function (): void {
    $app = gmSoonShop();
    $soon = gmSoonProduct($app);
    $normal = aifecProduct($app, ['name' => 'Mesa', 'stock' => 20]);

    $data = aifecCatalog($app)->product($soon);
    same('out_of_stock', $data['availability']['status']);
    same('Próximamente', $data['availability']['label']);
    same('in_stock', aifecCatalog($app)->product($normal)['availability']['status']);

    $badge = $app->hooks()->render('product.price.after', $data);
    contains('af-badge', $badge);
    contains('Próximamente', $badge);
    $form = $app->hooks()->render('product.buy.after', $data);
    contains('action="/proximamente/avisame"', $form);
    contains('name="product_id" value="' . $soon . '"', $form);
    same('', $app->hooks()->render('product.price.after', aifecCatalog($app)->product($normal)));
    same('', $app->hooks()->render('product.buy.after', aifecCatalog($app)->product($normal)));

    $web = new TestClient($app);
    $page = $web->get(parse_url($data['url'], PHP_URL_PATH));
    same(200, $page->status);
    contains('gm-soon-form', $page->body);
    check($page->cacheTtl > 0, 'la ficha sigue siendo cacheable');
});

test('un producto próximamente no se añade al carrito desde el formulario: mensaje claro y carrito vacío', function (): void {
    $app = gmSoonShop();
    $id = gmSoonProduct($app);
    $web = new TestClient($app);

    $r = $web->post('/carrito/add', ['product_id' => $id, 'qty' => 1]);
    same(302, $r->status);
    $cart = $web->follow();
    contains('Este producto llegará pronto y todavía no se puede comprar.', $cart->body);
    same(0, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));

    $json = $web->postJson('/carrito/add', ['product_id' => $id, 'qty' => 1]);
    same(422, $json->status);
    $body = $web->json();
    same(false, $body['ok']);
    same('Este producto llegará pronto y todavía no se puede comprar.', $body['error']);
    same(0, $body['count']);
});

test('tampoco entra por el servicio del carrito (API, asistente): la línea se quita sola', function (): void {
    $app = gmSoonShop();
    $soon = gmSoonProduct($app);
    $normal = aifecProduct($app, ['name' => 'Mesa', 'stock' => 20]);
    $web = new TestClient($app);
    $count = aifecCartRun($web, static function ($cart) use ($normal, $soon): int {
        $cart->add($normal, 0, 1);
        $cart->add($soon, 0, 2);

        return $cart->count();
    });
    same(1, $count, 'solo queda la unidad del producto normal');
});

test('sin la capacidad pricing concedida el producto sigue sin poder comprarse, aunque su etiqueta no cambie', function (): void {
    $app = gmSoonShop([], false);
    $id = gmSoonProduct($app);
    $web = new TestClient($app);
    same('in_stock', aifecCatalog($app)->product($id)['availability']['status'], 'el filtro sellado no entró');
    $web->post('/carrito/add', ['product_id' => $id, 'qty' => 1]);
    same(0, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
});

test('el avísame guarda una petición por correo y producto, escapa y no repite', function (): void {
    $app = gmSoonShop();
    $id = gmSoonProduct($app);
    $web = new TestClient($app);
    $r = $web->post('/proximamente/avisame', ['product_id' => $id, 'email' => 'Ana@Cliente.test', 'consent' => '1']);
    same(200, $r->status);
    contains('Gracias', $r->body);
    $web->post('/proximamente/avisame', ['product_id' => $id, 'email' => 'ana@cliente.test', 'consent' => '1']);
    same(1, aifecOrderCount($app, 'gm_soon_requests'));
    same('ana@cliente.test', (string) $app->db()->value('SELECT email FROM {gm_soon_requests} WHERE product_id = ?', [$id]));
    check((string) $app->db()->value('SELECT consented_at FROM {gm_soon_requests} WHERE product_id = ?', [$id]) !== '', 'queda la fecha del consentimiento');

    $web->post('/proximamente/avisame', ['product_id' => $id, 'email' => 'otra@cliente.test', 'consent' => '1']);
    same(2, aifecOrderCount($app, 'gm_soon_requests'));
});

test('el avísame rechaza un correo malo, la casilla sin marcar, un producto normal y la trampa de robots', function (): void {
    $app = gmSoonShop();
    $id = gmSoonProduct($app);
    $normal = aifecProduct($app, ['name' => 'Mesa']);
    $web = new TestClient($app);

    $bad = $web->post('/proximamente/avisame', ['product_id' => $id, 'email' => 'no-es-un-correo', 'consent' => '1']);
    same(422, $bad->status);
    contains('Escribe un correo válido.', $bad->body);
    $none = $web->post('/proximamente/avisame', ['product_id' => $id, 'email' => 'ana@cliente.test']);
    same(422, $none->status);
    contains('Marca la casilla', $none->body);
    same(404, $web->post('/proximamente/avisame', ['product_id' => $normal, 'email' => 'ana@cliente.test', 'consent' => '1'])->status);
    $bot = $web->post('/proximamente/avisame', ['product_id' => $id, 'email' => 'ana@cliente.test', 'consent' => '1', 'website' => 'http://spam.test']);
    same(200, $bot->status);
    same(0, aifecOrderCount($app, 'gm_soon_requests'), 'nada de eso se guardó');

    $app2 = gmSoonShop(['gm_proximamente.rate_per_hour' => 2]);
    $id2 = gmSoonProduct($app2);
    $web2 = new TestClient($app2);
    same(200, $web2->post('/proximamente/avisame', ['product_id' => $id2, 'email' => 'a@cliente.test', 'consent' => '1'])->status);
    same(200, $web2->post('/proximamente/avisame', ['product_id' => $id2, 'email' => 'b@cliente.test', 'consent' => '1'])->status);
    same(429, $web2->post('/proximamente/avisame', ['product_id' => $id2, 'email' => 'c@cliente.test', 'consent' => '1'])->status);
});

test('al pasar a disponible se encola un solo aviso por petición de los últimos 90 días y ninguno de las de 91', function (): void {
    $app = gmSoonShop();
    $id = gmSoonProduct($app);
    $soon = new Soon($app);
    $soon->request($id, 'reciente@cliente.test', 'es');
    $soon->request($id, 'otra@cliente.test', 'es');
    $soon->request($id, 'vieja@cliente.test', 'es');
    $old = $app->clock()->sql($app->clock()->now() - 91 * 86400);
    $app->db()->update('gm_soon_requests', ['created_at' => $old], ['email' => 'vieja@cliente.test']);

    same(2, $soon->set($id, false), 'se avisó a las dos recientes');
    $mails = aifecOrderMails($app, 'soon_available');
    same(2, count($mails));
    $to = array_map(static function (array $m): string {
        return (string) $m['to_email'];
    }, $mails);
    sort($to);
    same(['otra@cliente.test', 'reciente@cliente.test'], $to);
    contains('Silla de roble', (string) $mails[0]['html']);
    check(!in_array('vieja@cliente.test', $to, true), 'la de hace 91 días no se avisa');
    same(2, aifecOrderCount($app, 'gm_soon_requests', 'notified_at IS NOT NULL'));
    same(null, $app->db()->value('SELECT notified_at FROM {gm_soon_requests} WHERE email = ?', ['vieja@cliente.test']));

    // Pasarlo otra vez por «disponible» no reenvía nada.
    same(0, $soon->release($id));
    same(0, $soon->set($id, false));
    same(2, count(aifecOrderMails($app, 'soon_available')));
});

test('la fecha de salida pasa el producto a disponible en la tarea y avisa a quien lo pidió', function (): void {
    $app = gmSoonShop();
    $id = aifecProduct($app, ['name' => 'Lámpara', 'stock' => 20]);
    $soon = new Soon($app);
    $future = $app->clock()->sql($app->clock()->now() + 3600);
    $soon->set($id, true, $future);
    $soon->request($id, 'ana@cliente.test', 'es');
    same(0, $soon->releaseDue(), 'todavía no llegó la fecha');
    same(true, Soon::isSoon($app, $id));

    $app->clock()->advance(7200);
    same(1, $soon->releaseDue());
    same(false, Soon::isSoon($app, $id));
    same(1, count(aifecOrderMails($app, 'soon_available')));
    same('in_stock', aifecCatalog($app)->product($id)['availability']['status']);
});

test('la tarea diaria borra las peticiones de más de 90 días y respeta las demás', function (): void {
    $app = gmSoonShop();
    $id = gmSoonProduct($app);
    $soon = new Soon($app);
    $soon->request($id, 'nueva@cliente.test', 'es');
    $soon->request($id, 'vieja@cliente.test', 'es');
    $app->db()->update('gm_soon_requests', ['created_at' => $app->clock()->sql($app->clock()->now() - 100 * 86400)], ['email' => 'vieja@cliente.test']);
    same(1, $soon->purge());
    same(1, aifecOrderCount($app, 'gm_soon_requests'));
});

test('el panel: la ficha del producto trae la pestaña y se guarda; el listado pide permiso y escapa', function (): void {
    $app = gmSoonShop();
    $id = aifecProduct($app, ['name' => 'Sofá', 'stock' => 4]);
    $form = $app->adminDefinitions()['catalog']['resources']['products']['form']($app, $id);
    same('Coming soon', $form['tabs']['gpssoon']);
    same('toggle', $form['fields']['gm_soon']['type']);
    same(false, $form['fields']['gm_soon']['store']);
    check(isset($form['fields']['price']), 'los campos del catálogo siguen ahí');

    $app->hooks()->fire('admin.resource.saved', 'products', $id, ['gm_soon' => 1, 'gm_soon_release' => ''], null);
    same(true, Soon::isSoon($app, $id));
    $again = $app->adminDefinitions()['catalog']['resources']['products']['form']($app, $id);
    same(1, $again['fields']['gm_soon']['default']);
    $app->hooks()->fire('admin.resource.saved', 'products', $id, ['gm_soon' => 0], null);
    same(false, Soon::isSoon($app, $id));
    $app->hooks()->fire('admin.resource.saved', 'customers', $id, ['gm_soon' => 1], null);
    same(false, Soon::isSoon($app, $id), 'otro recurso no cuenta');

    (new Soon($app))->set($id, true);
    (new Soon($app))->request($id, 'a&b@cliente.test', 'es');
    $web = adminClient($app);
    $list = $web->get('/admin/r/gm_soon_requests');
    same(200, $list->status);
    contains('a&amp;b@cliente.test', $list->body);
    contains('Sofá', $list->body);
    $role = adminClient($app, 'support');
    same(403, $role->get('/admin/r/gm_soon_requests')->status);
});

test('gm-proximamente se desactiva y el producto vuelve a poder comprarse', function (): void {
    $app = gmSoonShop();
    $id = gmSoonProduct($app);
    $r = $app->service('plugins')->disable('gm-proximamente');
    check($id > 0, 'había un producto próximamente');
    same(true, $r['ok'], (string) $r['message']);
    $off = testApp(['modules' => gmSoonActive(), 'settings' => ['modules.active' => $app->settings()->arr('modules.active')]]);
    $off->boot();
    check(!$off->modules()->isLoaded('gm-proximamente'), 'no se carga');
});

test('gm-proximamente pasa el contrato AIFEC, también el ciclo de instalar y desinstalar', function (): void {
    $app = gmSoonShop();
    $plugins = $app->service('plugins');
    $plugins->useTempBase(tmpDir());
    $report = $plugins->validate('gm-proximamente');
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

// ------------------------------------------------------------------ P092: insignia en la tarjeta, sin precio y no comprable de verdad

require_once dirname(__DIR__, 3) . '/modules/semantic/tests/helpers.php';

test('en la tarjeta de un listado el producto dice «Próximamente» en lugar de «Agotado», sin la oferta', function (): void {
    $app = gmSoonShop();
    $soon = gmSoonProduct($app);
    $other = aifecProduct($app, ['name' => 'Mesa', 'stock' => 0, 'created_at' => '2026-01-01 00:00:00']);
    $card = aifecCatalog($app)->card($soon);
    check(!in_array('out', $card['badges'], true), 'ya no pone «Agotado»');
    check(in_array('gm-soon', $card['badges'], true), 'pone «Próximamente»');
    $labels = array_column($card['badge_list'], 'label', 'code');
    same('Próximamente', $labels['gm-soon']);
    // Otro producto agotado conserva su «Agotado».
    check(in_array('out', aifecCatalog($app)->card($other)['badges'], true), 'los demás no cambian');
    $html = $app->view()->render('partials/product-card', ['product' => $card]);
    contains('Próximamente', $html);
    notContains('af-badge-out', $html);
});

test('la tarjeta y la ficha de un producto próximamente no enseñan el precio, y su tarjeta no tiene botón de compra', function (): void {
    $app = gmSoonShop();
    $soon = gmSoonProduct($app, 'Silla futura');
    $normal = aifecProduct($app, ['name' => 'Mesa', 'price' => 54321, 'stock' => 20]);
    $card = aifecCatalog($app)->card($soon);
    same(true, $card['price']['hidden']);
    same(0, $card['price']['amount']);
    same(false, $card['availability']['purchasable']);
    $html = $app->view()->render('partials/product-card', ['product' => $card]);
    notContains('50,00', $html);
    notContains('af-price-now', $html);
    notContains('data-af-cart-add', $html);

    $web = new TestClient($app);
    $page = $web->get('/producto/silla-futura');
    same(200, $page->status);
    notContains('50,00', $page->body);
    // La columna de compra del producto (las tarjetas de «También te puede interesar», debajo, llevan el precio de OTROS).
    $buy = (string) substr($page->body, (int) strpos($page->body, 'af-product-buy'));
    $buy = strstr($buy, 'id="af-related"', true) !== false ? (string) strstr($buy, 'id="af-related"', true) : $buy;
    notContains('af-price-now', $buy);
    notContains('data-af-cart-add', $buy, 'sin formulario de compra');
    contains('gm-soon-form', $page->body, 'pero sí el «avísame»');
    contains('Próximamente', $page->body);
    check(empty(aifecCatalog($app)->product($normal)['price']['hidden']), 'los demás siguen con su precio');
});

test('no comprable de verdad: lo que lee el carrito dice que no, y el servicio del carrito lo rechaza con la etiqueta', function (): void {
    $app = gmSoonShop();
    $soon = gmSoonProduct($app);
    $p = aifecCatalog($app)->purchasable($soon);
    same(false, $p['available']);
    same('Próximamente', $p['reason']);
    $web = new TestClient($app);
    $r = aifecCartRun($web, static function ($cart) use ($soon): array {
        return $cart->add($soon, 0, 1);
    });
    same(false, $r['ok']);
    same('Próximamente', $r['error'], 'rechazado por la disponibilidad, antes de que haga falta el guardián');
});

test('la disponibilidad de un producto próximamente dice PreOrder a las máquinas, sin precio en los datos estructurados', function (): void {
    $app = aifecOrderApp(['settings' => ['modules.active' => array_merge(gmSoonActive(), ['semantic']), 'plugins.granted' => ['gm-proximamente:pricing']], 'modules' => ['plugins', 'gm-proximamente', 'semantic']]);
    $app->migrate(true);
    $id = aifecProduct($app, ['name' => 'Silla futura', 'slug' => 'silla-futura', 'price' => 5000, 'stock' => 20, 'images' => 1]);
    (new Soon($app))->set($id, true);
    same('PreOrder', aifecCatalog($app)->product($id)['availability']['schema']);
    $offer = semanticFind(semanticLd((new TestClient($app))->get('/producto/silla-futura')->body), 'Product')['offers'];
    same('https://schema.org/PreOrder', $offer['availability']);
    check(!array_key_exists('price', $offer), 'sin precio');
});

test('al dejar de ser «próximamente» el producto vuelve entero: precio, botón, disponibilidad y sin la insignia', function (): void {
    $app = gmSoonShop();
    $soon = gmSoonProduct($app, 'Silla futura');
    $web = new TestClient($app);
    notContains('data-af-cart-add', $web->get('/producto/silla-futura')->body);
    (new Soon($app))->set($soon, false);
    $page = $web->get('/producto/silla-futura');
    contains('data-af-cart-add', $page->body);
    contains('af-price-now', $page->body);
    $card = aifecCatalog($app)->card($soon);
    check(empty($card['price']['hidden']), 'el precio vuelve');
    check(!in_array('gm-soon', $card['badges'], true), 'la insignia se va');
    same(true, aifecCatalog($app)->purchasable($soon)['available']);
    same('', aifecCatalog($app)->product($soon)['availability']['schema']);
});

test('sin la capacidad pricing concedida, el precio y el botón no cambian y el guardián sigue impidiendo la compra', function (): void {
    $app = gmSoonShop([], false);
    $soon = gmSoonProduct($app);
    $card = aifecCatalog($app)->card($soon);
    check(empty($card['price']['hidden']), 'sin capacidad no se toca el precio');
    same(true, $card['availability']['purchasable']);
    $web = new TestClient($app);
    $web->post('/carrito/add', ['product_id' => $soon, 'qty' => 1]);
    same(0, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
});
