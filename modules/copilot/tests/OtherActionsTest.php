<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las órdenes sobre cupones, envíos y ajustes, en castellano y en inglés, con vista previa exacta, aplicar, comprobar en la base de
 * datos y deshacer.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Tienda con un transportista «GLS» de una sola tarifa en la península (5,90 €).
 *
 * @return array{0:\Aifec\Kernel\App,1:TestClient,2:int,3:int}
 */
function copilotShippingSetup(): array
{
    $app = copilotApp();
    $gls = shippingAddCarrier($app, 'gls', ['name' => 'GLS']);
    $rate = shippingAddRate($app, $gls, shippingZoneId($app, 'Peninsula'), 'weight', 0, 1000, 590);

    return [$app, copilotAdmin($app), $gls, $rate];
}

test('crear un cupón en castellano: código, porcentaje y último día; aplicar lo inserta, deshacer lo desactiva y no borra', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Crea el cupón VERANO10 con un 10 % hasta el 31/12/2026');
    same('coupons.create', $row['action']);
    same(['code' => 'VERANO10', 'percent' => 1000, 'ends' => '2026-12-31'], $row['args']);
    same('proposed', $row['status']);
    same(['Código', 'Descuento', 'Último día', 'Usos máximos'], array_column($row['preview']['changes'], 'label'));
    same(['VERANO10', '10 %', '2026-12-31', 'Sin límite'], array_column($row['preview']['changes'], 'to'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {coupons}'), 'entender no crea nada');

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    $coupon = $app->db()->row("SELECT * FROM {coupons} WHERE code = 'VERANO10'");
    same(['percent', 1000, 1, 0], [$coupon['kind'], (int) $coupon['percent'], (int) $coupon['active'], (int) $coupon['max_uses']]);
    same('2026-12-31', substr((string) $coupon['ends_at'], 0, 10));

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');
    same(0, (int) $app->db()->value("SELECT active FROM {coupons} WHERE code = 'VERANO10'"), 'deshacer lo desactiva');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {coupons}'), 'pero no lo borra');
});

test('crear un cupón en inglés: importe fijo y número de usos', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Create the coupon SUMMER5 for 5 € off, 100 uses');
    same(['code' => 'SUMMER5', 'amount' => 500, 'uses' => 100], $row['args']);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    $coupon = $app->db()->row("SELECT kind, amount, percent, max_uses FROM {coupons} WHERE code = 'SUMMER5'");
    same(['fixed', 500, 0, 100], [$coupon['kind'], (int) $coupon['amount'], (int) $coupon['percent'], (int) $coupon['max_uses']]);
});

test('un cupón con un código repetido, mal escrito o caducado ya es un error; sin código queda incompleto', function (): void {
    $app = copilotApp();
    couponsMake($app, ['code' => 'VERANO10']);
    $web = copilotAdmin($app);

    $dup = copilotAsk($web, 'Crea el cupón VERANO10 con un 10 %');
    contains('Ya hay un cupón con el código VERANO10', $dup['preview']['errors'][0]);
    $old = copilotAsk($web, 'Crea el cupón OTONO20 con un 20 % hasta el 01/01/2026');
    contains('ya ha pasado', $old['preview']['errors'][0]);
    $none = copilotAsk($web, 'Crea un cupón del 10 %');
    same('incomplete', $none['status']);
    same(['code'], $none['preview']['missing']);
    copilotPost($web, '/admin/copilot/' . $none['id'] . '/complete', ['p' => ['code' => 'a b!']]);
    contains('3 a 40', copilotRow($app, (int) $none['id'])['preview']['errors'][0]);

    foreach ([$dup, $old, $none] as $row) {
        copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    }
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {coupons}'), 'no se ha creado ninguno más');
});

test('un cupón que ya se ha usado no se deshace', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'Create the coupon BIENVENIDA with 15 % off');
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    $app->db()->update('coupons', ['uses' => 1], ['code' => 'BIENVENIDA']);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');
    same('applied', copilotRow($app, (int) $row['id'])['status'], 'sigue aplicada');
    same(1, (int) $app->db()->value("SELECT active FROM {coupons} WHERE code = 'BIENVENIDA'"));
});

test('precio de una tarifa de un transportista, en castellano y en inglés; aplicar y deshacer', function (): void {
    list($app, $web, $gls, $rate) = copilotShippingSetup();

    $es = copilotAsk($web, 'Pon la tarifa de GLS a 6,90');
    same('shipping.rate', $es['action']);
    same(['carrier' => 'GLS', 'price' => 690], $es['args']);
    same(1, $es['preview']['count']);
    same(money(590), $es['preview']['changes'][0]['from']);
    same(money(690), $es['preview']['changes'][0]['to']);
    $en = copilotAsk($web, 'Set the GLS rate to 7.50');
    same(750, $en['args']['price']);

    copilotPost($web, '/admin/copilot/' . $es['id'] . '/apply');
    same(690, (int) $app->db()->value('SELECT price FROM {carrier_rates} WHERE id = ?', [$rate]));
    copilotPost($web, '/admin/copilot/' . $es['id'] . '/undo');
    same(590, (int) $app->db()->value('SELECT price FROM {carrier_rates} WHERE id = ?', [$rate]));
});

test('un transportista con varias tarifas ofrece cuál cambiar; la zona la acota; una tarifa a cero es un error', function (): void {
    list($app, $web, $gls, $rate) = copilotShippingSetup();
    $canarias = shippingAddRate($app, $gls, shippingZoneId($app, 'Canary Islands'), 'weight', 0, 1000, 1190);

    $many = copilotAsk($web, 'Pon la tarifa de GLS a 8');
    same('incomplete', $many['status']);
    same(2, count($many['preview']['choices']));
    same('rate', $many['preview']['choices'][0]['param']);
    $zone = copilotAsk($web, 'Set the GLS rate for Canary Islands to 9.90');
    same('proposed', $zone['status']);
    same(money(1190), $zone['preview']['changes'][0]['from']);
    copilotPost($web, '/admin/copilot/' . $zone['id'] . '/apply');
    same([590, 990], [(int) $app->db()->value('SELECT price FROM {carrier_rates} WHERE id = ?', [$rate]), (int) $app->db()->value('SELECT price FROM {carrier_rates} WHERE id = ?', [$canarias])]);

    copilotPost($web, '/admin/copilot/' . $many['id'] . '/complete', ['p' => ['rate' => '#' . $rate]]);
    same('proposed', copilotRow($app, (int) $many['id'])['status']);

    $zero = copilotAsk($web, 'Set the GLS rate for Canary Islands to 0');
    contains('por encima de cero', $zero['preview']['errors'][0]);
});

test('envío gratis a partir de un importe, en los dos idiomas; quitarlo; deshacer', function (): void {
    list($app, $web, $gls, $rate) = copilotShippingSetup();

    $es = copilotAsk($web, 'Envío gratis a partir de 50 € con GLS');
    same('shipping.free_from', $es['action']);
    same(['carrier' => 'GLS', 'amount' => 5000], $es['args']);
    same([['label' => 'Envío gratis a partir de · GLS', 'from' => 'Nunca', 'to' => money(5000)]], $es['preview']['changes']);
    $en = copilotAsk($web, 'Free shipping for orders over 60 euros with GLS');
    same(6000, $en['args']['amount']);

    copilotPost($web, '/admin/copilot/' . $es['id'] . '/apply');
    same(5000, (int) $app->db()->value('SELECT free_from FROM {carriers} WHERE id = ?', [$gls]));
    $off = copilotAsk($web, 'Quita el envío gratis de GLS');
    same(0, $off['args']['amount']);
    copilotPost($web, '/admin/copilot/' . $off['id'] . '/apply');
    same(null, $app->db()->value('SELECT free_from FROM {carriers} WHERE id = ?', [$gls]));
    copilotPost($web, '/admin/copilot/' . $off['id'] . '/undo');
    same(5000, (int) $app->db()->value('SELECT free_from FROM {carriers} WHERE id = ?', [$gls]));
});

test('ajustes de la lista cerrada: el nombre de la tienda en los dos idiomas, aplicar, dar el aviso de «ajustes guardados» y deshacer', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);
    $saved = [];
    $app->hooks()->on('settings.saved', static function (string $module, array $keys) use (&$saved): void {
        $saved[] = [$module, $keys];
    });

    $es = copilotAsk($web, 'Cambia el nombre de la tienda a Muebles Ejemplo');
    same('settings.set', $es['action']);
    same(['setting' => 'store.name', 'value' => 'Muebles Ejemplo'], $es['args']);
    same([['label' => 'Nombre de la tienda', 'from' => 'AIFEC', 'to' => 'Muebles Ejemplo']], $es['preview']['changes']);
    $en = copilotAsk($web, 'Set the shop name to Acme & Sons');
    same('Acme & Sons', $en['args']['value']);

    copilotPost($web, '/admin/copilot/' . $en['id'] . '/apply');
    same('Acme & Sons', $app->settings()->str('store.name'));
    same([['core', ['store.name']]], $saved);
    contains('Acme &amp; Sons', $web->get('/admin/copilot/' . $en['id'])->body);
    copilotPost($web, '/admin/copilot/' . $en['id'] . '/undo');
    same('AIFEC', $app->settings()->str('store.name'));
    same(2, count($saved), 'deshacer también avisa');
});

test('ajustes de encender o apagar y de números: precios sin impuestos, productos por página, con su rango', function (): void {
    $app = copilotApp();
    $app->settings()->set('tax.display_with_tax', true);
    $web = copilotAdmin($app);

    $off = copilotAsk($web, 'Muestra los precios sin impuestos');
    same(['setting' => 'tax.display_with_tax', 'value' => '0'], $off['args']);
    same([['label' => 'Enseñar los precios con impuestos en la tienda', 'from' => 'Sí', 'to' => 'No']], $off['preview']['changes']);
    copilotPost($web, '/admin/copilot/' . $off['id'] . '/apply');
    same(false, $app->settings()->bool('tax.display_with_tax', true));
    $on = copilotAsk($web, 'Show the prices with tax');
    same('1', $on['args']['value']);
    copilotPost($web, '/admin/copilot/' . $on['id'] . '/apply');
    same(true, $app->settings()->bool('tax.display_with_tax'));

    $page = copilotAsk($web, 'Pon 12 productos por página');
    same(['setting' => 'catalog.per_page', 'value' => '12'], $page['args']);
    copilotPost($web, '/admin/copilot/' . $page['id'] . '/apply');
    same(12, $app->settings()->int('catalog.per_page'));
    $high = copilotAsk($web, 'Set products per page to 500');
    contains('entre 1 y 200', $high['preview']['errors'][0]);
    copilotPost($web, '/admin/copilot/' . $high['id'] . '/apply');
    same(12, $app->settings()->int('catalog.per_page'));
});

test('lo que no está en la lista de ajustes seguros no se cambia: se encuentra y se enseña la pantalla', function (): void {
    $app = copilotApp();
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Cambia el IVA al 10');
    same('failed', $row['status']);
    same('', $row['action']);
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    contains('No lo he entendido', $page);
    contains('/admin/settings/tax', $page);
});

test('cada ajuste exige el permiso de su propia pantalla: con catalog.edit se cambian los productos por página, pero no el nombre de la tienda', function (): void {
    $app = copilotApp();
    adminOrdersRole($app, 'catalogador', ['copilot.use', 'catalog.edit']);
    $web = copilotAdmin($app, 'catalogador');

    $ok = copilotAsk($web, 'Pon 20 productos por página');
    same('proposed', $ok['status']);
    copilotPost($web, '/admin/copilot/' . $ok['id'] . '/apply');
    same(20, $app->settings()->int('catalog.per_page'));

    $no = copilotAsk($web, 'Cambia el nombre de la tienda a Otra');
    same('failed', $no['status']);
    contains('No tienes permiso', $no['result']);
    same('AIFEC', $app->settings()->str('store.name'));
});
