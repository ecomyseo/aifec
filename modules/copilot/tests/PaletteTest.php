<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El buscador de órdenes del panel (Ctrl + K) ampliado por el copiloto: ajustes por intención, datos (pedidos, productos, clientes,
 * cupones), ayuda y «Hacer: …». Todo con el permiso de quien busca. El catálogo y los pedidos ya ponen algunos resultados suyos
 * (productos por nombre o referencia, pedidos por número o correo): el copiloto no los repite.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Copilot\Palette;

require_once __DIR__ . '/helpers.php';

/**
 * Lo que devuelve el buscador del panel para un texto.
 *
 * @return array<int,array{label:string,group:string,url:string}>
 */
function copilotSearch(TestClient $web, string $q): array
{
    $r = $web->get('/admin/palette.json?q=' . rawurlencode($q));
    same(200, $r->status);
    $items = $web->json()['items'];
    check(is_array($items), 'el buscador devuelve una lista');

    return $items;
}

/**
 * Los resultados de un grupo.
 *
 * @param array<int,array{label:string,group:string,url:string}> $items
 *
 * @return array<int,array{label:string,group:string,url:string}>
 */
function copilotGroup(array $items, string $group): array
{
    return array_values(array_filter($items, static function (array $item) use ($group): bool {
        return $item['group'] === $group;
    }));
}

/**
 * ¿Sale alguna dirección que contenga este trozo?
 *
 * @param array<int,array{label:string,group:string,url:string}> $items
 */
function copilotHasUrl(array $items, string $piece): bool
{
    foreach ($items as $item) {
        if (strpos($item['url'], $piece) !== false) {
            return true;
        }
    }

    return false;
}

test('«IVA» encuentra los ajustes de impuestos, con su pantalla y el campo señalado; también preguntado con palabras', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    foreach (['IVA', 'impuestos', '¿Dónde cambio el IVA?', 'where do I change the VAT'] as $q) {
        $settings = copilotGroup(copilotSearch($web, $q), 'Ajustes');
        check($settings !== [], $q . ' → hay ajustes');
        $fields = array_filter(array_column($settings, 'url'), static function (string $url): bool {
            return strpos($url, '/admin/settings/tax#field=f-tax-') !== false && strpos($url, '&tab=') !== false;
        });
        check($fields !== [], $q . ' → un campo de los ajustes de impuestos, con su pestaña: ' . implode(' ', array_column($settings, 'url')));
    }
    $labels = array_column(copilotGroup(copilotSearch($web, 'IVA'), 'Ajustes'), 'label');
    check(count(array_filter($labels, static function (string $label): bool {
        return strpos($label, '›') !== false;
    })) > 0, 'la etiqueta de un campo dice pantalla › pestaña › campo: ' . implode(' | ', $labels));
});

test('«envío gratis» encuentra el importe de envío gratis, y «portes», «logo» y «correo» sus ajustes', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $free = copilotGroup(copilotSearch($web, 'envío gratis'), 'Ajustes');
    same('Importe de envío gratis', $free[0]['label']);
    contains('/admin/r/carriers', $free[0]['url']);
    check(count(copilotGroup(copilotSearch($web, 'portes'), 'Ajustes')) > 0, 'portes');
    check(copilotHasUrl(copilotSearch($web, 'logo'), '#field=f-store-logo'), 'logo');
    check(copilotHasUrl(copilotSearch($web, 'correo'), '#field=f-store-email'), 'correo');
    check(copilotHasUrl(copilotSearch($web, 'SMTP'), '/admin/settings/mail'), 'SMTP');
    same([], copilotGroup(copilotSearch($web, 'zzzxyz'), 'Ajustes'), 'lo que no encaja con nada no da nada');
});

test('un número de pedido encuentra el pedido; también su correo y el nombre de quien lo hizo', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);

    foreach ([$order['n'], '#' . $order['n'], $order['number'], 'ana@cliente.test', 'Ruiz', 'ana ruiz'] as $q) {
        check(copilotHasUrl(copilotSearch($web, $q), '/admin/orders/' . $order['id']), $q . ' → encuentra el pedido');
    }
    $byName = array_values(array_filter(copilotSearch($web, 'ana ruiz'), static function (array $item) use ($order): bool {
        return $item['url'] === '/admin/orders/' . $order['id'] && $item['group'] === 'Pedidos';
    }));
    contains('Pedido ' . $order['number'], $byName[0]['label'], 'por el nombre lo pone el copiloto');
    same(false, copilotHasUrl(copilotSearch($web, '9999'), '/admin/orders/'));
});

test('una referencia, un nombre o un código de barras encuentran el producto', function (): void {
    $app = copilotApp();
    $ids = copilotShop($app);
    $app->db()->update('products', ['gtin' => '8412345678905'], ['id' => $ids['mesa']]);
    $web = copilotAdmin($app);

    check(copilotHasUrl(copilotSearch($web, 'SILLA-R'), '/admin/r/products/' . $ids['roja']), 'referencia');
    check(copilotHasUrl(copilotSearch($web, 'mesa roble'), '/admin/r/products/' . $ids['mesa']), 'nombre');
    check(copilotHasUrl(copilotSearch($web, '8412345678905'), '/admin/r/products/' . $ids['mesa']), 'código de barras');
    check(copilotHasUrl(copilotSearch($web, 'sofa nube'), '/admin/r/products/' . $ids['sofa']), 'sin tildes también');
    check(copilotHasUrl(copilotSearch($web, 'NUBE-L'), '/admin/r/products/' . $ids['sofa']), 'la referencia de una variante');
});

test('clientes por correo, nombre o teléfono, y cupones por código', function (): void {
    $app = copilotApp();
    $customer = aifecCartCustomer($app, 'lucia@cliente.test', ['first_name' => 'Lucía', 'last_name' => 'Marín', 'phone' => '999000002']);
    couponsMake($app, ['code' => 'VERANO10']);
    $web = copilotAdmin($app);

    foreach (['lucia@cliente', 'Marín', 'Lucía Marín', '999000002'] as $q) {
        $found = copilotGroup(copilotSearch($web, $q), 'Clientes');
        check($found !== [], $q . ' → encuentra al cliente');
        same('/admin/r/customers/' . $customer, $found[0]['url'], $q);
    }
    $coupons = copilotGroup(copilotSearch($web, 'verano'), 'Cupones');
    same('Cupón VERANO10', $coupons[0]['label']);
    contains('/admin/r/coupons/', $coupons[0]['url']);
});

test('solo sale lo que el permiso deja ver: sin permiso de clientes no hay clientes, sin el de pedidos no hay pedidos', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $customer = aifecCartCustomer($app, 'lucia@cliente.test', ['first_name' => 'Lucía', 'last_name' => 'Marín']);
    $ids = copilotShop($app);
    adminOrdersRole($app, 'ventas', ['orders.view', 'catalog.view']);
    adminOrdersRole($app, 'fichas', ['customers.view', 'coupons.view']);
    $ventas = copilotAdmin($app, 'ventas');
    $fichas = copilotAdmin($app, 'fichas');

    same(false, copilotHasUrl(copilotSearch($ventas, 'lucia'), '/admin/r/customers/' . $customer), 'ventas no ve clientes');
    same(false, copilotHasUrl(copilotSearch($ventas, 'Marín'), '/admin/r/customers/'));
    check(copilotHasUrl(copilotSearch($ventas, 'ana ruiz'), '/admin/orders/' . $order['id']), 'ventas sí ve pedidos');
    check(copilotHasUrl(copilotSearch($ventas, 'SILLA-R'), '/admin/r/products/' . $ids['roja']), 'y productos');
    check(copilotHasUrl(copilotSearch($fichas, 'lucia'), '/admin/r/customers/' . $customer), 'fichas sí ve clientes');
    same(false, copilotHasUrl(copilotSearch($fichas, 'ana ruiz'), '/admin/orders/'), 'fichas no ve pedidos');
    same(false, copilotHasUrl(copilotSearch($fichas, 'SILLA-R'), '/admin/r/products/'), 'ni productos');
    same([], copilotGroup(copilotSearch($ventas, 'iva'), 'Ajustes'), 'sin permiso de ajustes tampoco salen los de impuestos');
});

test('como mucho cinco resultados por grupo, y un «%» o un «_» escritos valen como letras, no como comodines', function (): void {
    $app = copilotApp();
    for ($i = 1; $i <= 8; ++$i) {
        aifecProduct($app, ['name' => 'Pieza ' . $i]);
        aifecCartCustomer($app, 'pieza' . $i . '@cliente.test', ['first_name' => 'Pieza', 'last_name' => (string) $i]);
    }
    aifecProduct($app, ['name' => 'Oferta 50% extra']);
    aifecProduct($app, ['name' => 'Oferta 500 piezas']);
    $web = copilotAdmin($app);

    $mine = aifecCartRun($web, static function ($cart, \Aifec\Kernel\App $app): array {
        return [Palette::dataItems($app, 'pieza'), Palette::dataItems($app, '50%')];
    });
    same(5, count(copilotGroup($mine[0], 'Productos')), 'productos: cinco');
    same(5, count(copilotGroup($mine[0], 'Clientes')), 'clientes: cinco');
    $percent = copilotGroup($mine[1], 'Productos');
    same(1, count($percent), 'el «%» no es un comodín');
    contains('Oferta 50% extra', $percent[0]['label']);
});

test('«Hacer: …» sale el primero entre lo del copiloto cuando el texto es una orden, y abre el copiloto con la propuesta ya preparada', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);
    $text = 'marca el pedido ' . $order['n'] . ' como enviado con el seguimiento 4455';

    $items = copilotSearch($web, $text);
    $mine = copilotGroup($items, 'Copiloto');
    same(1, count($mine));
    contains('Hacer: ' . $text, $mine[0]['label']);
    same('/admin/copilot?' . http_build_query(['q' => $text]), $mine[0]['url']);
    $firstDo = array_values(array_filter($items, static function (array $item): bool {
        return strpos($item['label'], 'Hacer:') === 0;
    }));
    same($mine[0], $firstDo[0]);

    $r = $web->get($mine[0]['url']);
    same(303, $r->status);
    check(preg_match('#/admin/copilot/(\d+)$#', (string) $r->headers['Location'], $m) === 1, 'salta a la propuesta');
    $row = copilotRow($app, (int) $m[1]);
    same(['orders.ship', 'proposed'], [$row['action'], $row['status']]);
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]), 'abrirlo no aplica nada');
});

test('«Hacer: …» no sale si no es una orden, si no tiene el permiso de usar el copiloto o si no puede hacer lo que pide', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    adminOrdersRole($app, 'soporte', ['copilot.use', 'orders.view']);
    adminOrdersRole($app, 'ventas', ['orders.view', 'orders.edit']);
    $text = 'marca el pedido ' . $order['n'] . ' como enviado';
    $admin = copilotAdmin($app);
    $ventas = copilotAdmin($app, 'ventas');
    $soporte = copilotAdmin($app, 'soporte');

    $hasDo = static function (TestClient $web, string $q): bool {
        foreach (copilotSearch($web, $q) as $item) {
            if (strpos($item['label'], 'Hacer:') === 0) {
                return true;
            }
        }

        return false;
    };
    same(true, $hasDo($admin, $text));
    same(false, $hasDo($admin, 'iva'), 'una búsqueda no es una orden');
    same(false, $hasDo($ventas, $text), 'sin copilot.use no se ofrece');
    same(false, $hasDo($soporte, $text), 'sin el permiso de la acción tampoco');
});

test('la ayuda del manual sale si el módulo «docs» está activo y tiene documentación', function (): void {
    $dir = tmpDir('copilot-docs');
    file_put_contents($dir . '/envios.md', "# Guía de envíos\n\nAquí se explica cómo configurar los portes y el envío gratis.\n");
    $active = ['copilot', 'docs', 'orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs'];
    $app = copilotApp(['modules' => ['docs'], 'settings' => ['docs.dir' => $dir, 'modules.active' => $active]]);
    $web = copilotAdmin($app);

    $help = copilotGroup(copilotSearch($web, 'portes'), 'Ayuda');
    check($help !== [], 'hay una página de ayuda');
    contains('Guía de envíos', $help[0]['label']);
    contains('/docs/envios', $help[0]['url']);

    $plain = copilotAdmin(copilotApp());
    same([], copilotGroup(copilotSearch($plain, 'portes'), 'Ayuda'), 'sin el módulo docs no hay ayuda');
});
