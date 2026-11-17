<?php
/**
 * AIFEC — el traductor de WooCommerce al formato neutro: cifras hechas a mano de productos, pedidos, impuestos y envíos, y todo
 * lo raro (agrupados, atributos propios, estados desconocidos, impuestos incluidos, devoluciones parciales).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\WooCommerce\Batch;
use Aifec\Modules\Import\Readers\WooCommerce\Mapper;
use Aifec\Modules\Import\Readers\WooCommerce\Permalinks;
use Aifec\Modules\Import\SourceError;

require_once __DIR__ . '/fixtures/WooTestKit.php';

/** El traductor con la tienda de ejemplo: castellano, hora de Madrid, kg y cm, precios sin impuestos. */
function wooMapper(array $more = []): Mapper
{
    return new Mapper($more + ['locale' => 'es_ES', 'timezone' => 'Europe/Madrid', 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'prices_include_tax' => false, 'site_url' => 'https://tienda.test']);
}

/** @return array<string,mixed> */
function wooRoute(string $path, int $index = -1)
{
    $data = WooTestKit::routes()[$path];

    return $index >= 0 ? $data[$index] : $data;
}

test('las cifras del producto de ejemplo: precio, oferta, peso y medidas a mano', function (): void {
    $p = wooMapper()->product(wooRoute('/wp-json/wc/v3/products', 0));
    same([], Neutral::check('products', $p));
    same(10661, $p['price']);
    same(8900, $p['sale_price']);
    same(false, $p['price_includes_tax']);
    same(6500, $p['weight']);
    same([400, 400, 480], [$p['length'], $p['width'], $p['height']]);
    same(14, $p['stock']);
    same('2026-10-31T23:00:00Z', $p['sale_from']);
    same(null, $p['sale_to']);
    same(['es' => 'Mesa auxiliar de roble'], $p['name']);
    same(['/producto/mesa-auxiliar-roble/'], $p['old_paths']);
    same('9', $p['brand_id']);
    same(['12'], $p['category_ids']);
    same(['es' => ['roble', 'auxiliar']], $p['tags']);
    same('MESA-ROBLE', $p['sku']);
    same('8400000000017', $p['gtin']);
});

test('producto variable: variantes con sus valores, precio final, oferta y la imagen de cada una', function (): void {
    $terms = [1 => wooRoute('/wp-json/wc/v3/products/attributes/1/terms'), 2 => wooRoute('/wp-json/wc/v3/products/attributes/2/terms')];
    $p = wooMapper()->product(wooRoute('/wp-json/wc/v3/products', 1), wooRoute('/wp-json/wc/v3/products/102/variations'), $terms);
    same([], Neutral::check('products', $p));
    same(5000, $p['price']);
    same(3, count($p['variants']));
    same([['attribute_id' => '1', 'value_id' => '21']], $p['variants'][0]['values']);
    same(5000, $p['variants'][0]['price']);
    same(null, $p['variants'][0]['compare_price']);
    same(5200, $p['variants'][0]['weight']);
    same(1, $p['variants'][0]['image_index']);
    same(null, $p['variants'][1]['image_index']);
    same(6300, $p['variants'][2]['price']);
    same(7000, $p['variants'][2]['compare_price']);
    same(9000, $p['variants'][2]['weight']);
    same([['attribute_id' => '2', 'value_ids' => ['25']]], $p['attributes']);
    same(2, count($p['images']));
});

test('descargable: tipo digital y sus ficheros; virtual sin descarga: servicio', function (): void {
    $digital = wooMapper()->product(wooRoute('/wp-json/wc/v3/products', 2));
    same('digital', $digital['kind']);
    same('Manual PDF', $digital['files'][0]['name']);
    contains('manual.pdf', $digital['files'][0]['url']);
    same(999, $digital['price']);
    $source = wooRoute('/wp-json/wc/v3/products', 2);
    $source['downloadable'] = false;
    $source['downloads'] = [];
    same('service', wooMapper()->product($source)['kind']);
    same('physical', wooMapper()->product(wooRoute('/wp-json/wc/v3/products', 0))['kind']);
});

test('un producto agrupado sale como simple con su nota', function (): void {
    $source = wooRoute('/wp-json/wc/v3/products', 0);
    $source['type'] = 'grouped';
    $source['grouped_products'] = [101, 102];
    $p = wooMapper()->product($source);
    same([], Neutral::check('products', $p));
    contains('grouped product brought as a simple product', $p['extra']['import_note']);
    same(['101', '102'], $p['extra']['grouped_products']);
});

test('lo que no se puede traer lanza InvalidArgumentException: papelera, sin nombre', function (): void {
    $source = wooRoute('/wp-json/wc/v3/products', 0);
    $trashed = $source;
    $trashed['status'] = 'trash';
    throws(function () use ($trashed): void {
        wooMapper()->product($trashed);
    }, InvalidArgumentException::class);
    $nameless = $source;
    $nameless['name'] = '  ';
    throws(function () use ($nameless): void {
        wooMapper()->product($nameless);
    }, InvalidArgumentException::class);
});

test('sin existencias: stock a cero y el estado en extra; borrador: no publicado', function (): void {
    $banco = wooMapper()->product(wooRoute('/wp-json/wc/v3/products', 4));
    same(0, $banco['stock']);
    same(true, $banco['manage_stock']);
    same('outofstock', $banco['extra']['stock_status']);
    $lampara = wooMapper()->product(wooRoute('/wp-json/wc/v3/products', 5));
    same('draft', $lampara['status']);
    same(null, $lampara['published_at']);
    same(4500, $lampara['price']);
});

test('un atributo propio del producto no tiene registro: l-nombre y extra.local_attributes', function (): void {
    $source = wooRoute('/wp-json/wc/v3/products', 0);
    $source['attributes'] = [['id' => 0, 'name' => 'Talla', 'position' => 0, 'visible' => true, 'variation' => false, 'options' => ['M', 'L']]];
    $p = wooMapper()->product($source);
    same([], Neutral::check('products', $p));
    same([['attribute_id' => 'l-talla', 'value_ids' => ['m', 'l']]], $p['attributes']);
    same('Talla', $p['extra']['local_attributes'][0]['name']);
});

test('texto con & comillas tildes eñes y emojis pasa entero', function (): void {
    $source = wooRoute('/wp-json/wc/v3/products', 0);
    $source['name'] = 'Mesa &quot;Ñandú&quot; &amp; Cía 🪑';
    $source['description'] = '<p>Tilde: á, eñe: ñ & comillas "dobles"</p>';
    $p = wooMapper()->product($source);
    same(['es' => 'Mesa "Ñandú" & Cía 🪑'], $p['name']);
    same(['es' => '<p>Tilde: á, eñe: ñ & comillas "dobles"</p>'], $p['description']);
    same([], Neutral::check('products', $p));
});

test('pedido sin impuestos incluidos: total, impuesto y líneas hechos a mano', function (): void {
    $o = wooMapper()->order(wooRoute('/wp-json/wc/v3/orders', 0), [], wooRoute('/wp-json/wc/v3/orders/9001/notes'));
    same([], Neutral::check('orders', $o));
    same(10661, $o['items_total']);
    same(0, $o['discount_total']);
    same(590, $o['shipping_total']);
    same(11251, $o['total_net']);
    same(2363, $o['total_tax']);
    same(13614, $o['total']);
    same(13614, $o['paid_total']);
    same([['name' => 'IVA 21 %', 'rate' => 2100, 'base' => 11251, 'amount' => 2363]], $o['taxes']);
    same(10661, $o['items'][0]['unit_price']);
    same(10661, $o['items'][0]['line_net']);
    same(2239, $o['items'][0]['line_tax']);
    same(2100, $o['items'][0]['tax_rate']);
    same('shipping', $o['items'][1]['kind']);
    same(124, $o['items'][1]['line_tax']);
    same('paid', $o['state_meaning']);
    same('11', $o['carrier_id']);
    same('Mensajería 24 h', $o['carrier_title']);
    same(2, count($o['history']));
    same(true, $o['history'][1]['public']);
    same('2026-10-01T08:15:00Z', $o['placed_at']);
    same('2026-10-01T08:16:00Z', $o['payments'][0]['at']);
    same('77', $o['customer_id']);
    same(['203.0.113.5', 'wc_order_abc123'], [$o['extra']['customer_ip_address'], $o['extra']['order_key']]);
});

test('pedido con los precios incluyendo impuestos: unidad, líneas y envío con impuestos; el neto sigue siendo neto', function (): void {
    $source = wooRoute('/wp-json/wc/v3/orders', 0);
    $source['prices_include_tax'] = true;
    $o = wooMapper()->order($source);
    same(true, $o['prices_include_tax']);
    same(12900, $o['items'][0]['unit_price']);
    same(12900, $o['items'][0]['line_total']);
    same(10661, $o['items'][0]['line_net']);
    same(12900, $o['items_total']);
    same(714, $o['shipping_total']);
    same(11251, $o['total_net']);
    same(13614, $o['total']);
    same(12900 + 714, $o['total']);
    same(0, $o['discount_total']);
});

test('devolución parcial: unidades, importe con impuestos, impuesto y total devuelto', function (): void {
    $o = wooMapper()->order(wooRoute('/wp-json/wc/v3/orders', 1), wooRoute('/wp-json/wc/v3/orders/9002/refunds'));
    same([], Neutral::check('orders', $o));
    same(9559, $o['refunded_total']);
    same(9559, $o['refunds'][0]['amount']);
    same(1659, $o['refunds'][0]['tax']);
    same([['index' => 0, 'qty' => 1, 'amount' => 7900]], $o['refunds'][0]['lines']);
    same('Una unidad llegó golpeada', $o['refunds'][0]['reason']);
    same(1, $o['items'][0]['refunded_qty']);
    same(7900, $o['items'][0]['refunded_total']);
    same(2, $o['items'][0]['qty']);
    same(15800, $o['items_total']);
    same(19832, $o['total']);
    same('delivered', $o['state_meaning']);
    same('2026-10-06T08:00:00Z', $o['completed_at']);
});

test('estados de pedido: los de WooCommerce, los propios por su nombre y los desconocidos con aviso', function (): void {
    $m = wooMapper();
    same(['processing', 'paid', false], $m->orderState('wc-processing'));
    same(['on-hold', 'on_hold', false], $m->orderState('on-hold'));
    same(['completed', 'delivered', false], $m->orderState('completed'));
    same(['cancelled', 'cancelled', false], $m->orderState('wc-cancelled'));
    same(['refunded', 'refunded', false], $m->orderState('refunded'));
    same(['failed', 'failed', false], $m->orderState('failed'));
    same(['pending', 'pending', false], $m->orderState('pending'));
    same(['shipped', 'shipped', false], $m->orderState('wc-shipped'));
    same(['preparando-pedido', 'processing', true], $m->orderState('wc-preparando-pedido'));
    $source = wooRoute('/wp-json/wc/v3/orders', 0);
    $source['status'] = 'wc-preparando-pedido';
    $o = $m->order($source);
    same('processing', $o['state_meaning']);
    contains('unknown order status', $o['extra']['state_warning']);
    $cancelled = wooRoute('/wp-json/wc/v3/orders', 2);
    same('cancelled', $m->order($cancelled)['state_meaning']);
    same(0, $m->order($cancelled)['paid_total']);
});

test('un pedido en la papelera, sin correo o sin fecha lanza', function (): void {
    $source = wooRoute('/wp-json/wc/v3/orders', 0);
    foreach (['status' => 'trash', 'billing' => ['email' => '']] as $field => $value) {
        $broken = $source;
        $broken[$field] = $value;
        throws(function () use ($broken): void {
            wooMapper()->order($broken);
        }, InvalidArgumentException::class, $field);
    }
});

test('el tipo del 7 % con sus códigos postales, los rangos y la clase', function (): void {
    $m = wooMapper();
    $igic = $m->taxRate(wooRoute('/wp-json/wc/v3/taxes', 1));
    same([], Neutral::check('tax_rates', $igic));
    same(700, $igic['rate']);
    same('35,38', $igic['postcodes']);
    same('TF', $igic['region']);
    same('standard', $igic['class_id']);
    same(true, $igic['applies_to_shipping']);
    same('reduced-rate', $m->taxRate(wooRoute('/wp-json/wc/v3/taxes', 3))['class_id']);
    same(400, $m->taxRate(wooRoute('/wp-json/wc/v3/taxes', 2))['rate']);
    same('51000-51999,28', $m->postcodes(['51000...51999', '28*']));
    same('35,38', $m->postcodes('35*;38*'));
    throws(function () use ($m): void {
        $m->taxRate(['id' => 9, 'country' => '', 'rate' => '5']);
    }, InvalidArgumentException::class);
    same(['id' => 'standard', 'code' => 'standard', 'name' => 'Standard', 'is_default' => true], $m->taxClass('', 'Standard'));
    same(false, $m->taxClass('reduced-rate', 'Tipo reducido')['is_default']);
});

test('zonas y transportistas: tarifa plana por zona, envío gratis con su mínimo y recogida', function (): void {
    $m = wooMapper();
    $zone = $m->zone(['id' => 2, 'name' => 'Canarias', 'order' => 1], wooRoute('/wp-json/wc/v3/shipping/zones/2/locations'));
    same([], Neutral::check('shipping_zones', $zone));
    same([['country' => 'ES', 'region' => 'TF', 'postcodes' => '35,38'], ['country' => 'ES', 'region' => 'GC', 'postcodes' => '35,38']], $zone['locations']);
    $peninsula = $m->zone(['id' => 1, 'name' => 'Península', 'order' => 0], wooRoute('/wp-json/wc/v3/shipping/zones/1/locations'));
    same([['country' => 'ES', 'region' => '', 'postcodes' => ''], ['country' => 'PT', 'region' => '', 'postcodes' => '']], $peninsula['locations']);

    $methods = wooRoute('/wp-json/wc/v3/shipping/zones/1/methods');
    $flat = $m->carrier(['id' => 1], $methods[0]);
    same([], Neutral::check('carriers', $flat));
    same('rates', $flat['kind']);
    same([['zone_id' => '1', 'basis' => 'flat', 'min' => 0, 'max' => null, 'price' => 590]], $flat['rates']);
    same(['class_cost_7' => '3.00'], $flat['extra']['class_costs']);
    same('11', $flat['id']);
    same(['1'], $flat['zone_ids']);
    $free = $m->carrier(['id' => 1], $methods[1]);
    same('free', $free['kind']);
    same(6000, $free['free_from']);
    same([], $free['rates']);
    $pickup = $m->carrier(['id' => 1], $methods[2]);
    same('pickup', $pickup['kind']);
    same(null, $pickup['tax_class_id']);
    $canarias = $m->carrier(['id' => 2], wooRoute('/wp-json/wc/v3/shipping/zones/2/methods', 0));
    same(1200, $canarias['rates'][0]['price']);
    same('2', $canarias['rates'][0]['zone_id']);
    throws(function () use ($m): void {
        $m->carrier(['id' => 1], ['instance_id' => 99, 'method_id' => 'table_rate']);
    }, InvalidArgumentException::class);
    $formula = $m->carrier(['id' => 1], ['instance_id' => 5, 'method_id' => 'flat_rate', 'settings' => ['cost' => '10 + [qty]'], 'enabled' => true]);
    same(0, $formula['rates'][0]['price']);
    same('10 + [qty]', $formula['extra']['cost_formula']);
});

test('cupones: porcentaje, envío gratis y lo que aquí no existe va a unsupported', function (): void {
    $m = wooMapper();
    $otono = $m->coupon(wooRoute('/wp-json/wc/v3/coupons', 0));
    same([], Neutral::check('coupons', $otono));
    same('percent', $otono['kind']);
    same(1000, $otono['percent']);
    same(0, $otono['amount']);
    same(5000, $otono['min_subtotal']);
    same('2026-12-21T00:00:00Z', $otono['ends_at']);
    same(200, $otono['max_uses']);
    same(1, $otono['max_uses_per_customer']);
    same(37, $otono['uses']);
    same(true, $otono['exclude_sale']);
    same(false, $otono['combinable']);
    same(['12'], $otono['category_ids']);
    same([], $otono['unsupported']);
    $envio = $m->coupon(wooRoute('/wp-json/wc/v3/coupons', 1));
    same([], Neutral::check('coupons', $envio));
    same('free_shipping', $envio['kind']);
    same(null, $envio['min_subtotal']);
    same(null, $envio['max_uses']);
    same(['excluded products: 105', 'restricted to emails: ana@cliente.test'], $envio['unsupported']);
});

test('contraseñas: el esquema sale del prefijo del resumen, la API no da ninguna y redact la tapa', function (): void {
    same('wordpress', Mapper::passwordScheme('$P$B5xK0e8nOcRyGm1WjQh4T0Z9R2vQ1a0'));
    same('wordpress', Mapper::passwordScheme('$H$9abcdefghijklmnopqrstuvwxyz0123'));
    same('wordpress', Mapper::passwordScheme('$wp$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S'));
    same('bcrypt', Mapper::passwordScheme('$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S'));
    same('argon2', Mapper::passwordScheme('$argon2id$v=19$m=65536,t=4,p=1$abc$def'));
    same('unknown', Mapper::passwordScheme('5f4dcc3b5aa765d61d8327deb882cf99'));
    $customer = wooRoute('/wp-json/wc/v3/customers', 0);
    $api = wooMapper()->customer($customer, null);
    same(null, $api['password_hash']);
    check(!array_key_exists('password_scheme', $api));
    $db = wooMapper()->customer($customer, '$P$B5xK0e8nOcRyGm1WjQh4T0Z9R2vQ1a0');
    same('$P$B5xK0e8nOcRyGm1WjQh4T0Z9R2vQ1a0', $db['password_hash']);
    same('wordpress', $db['password_scheme']);
    same([], Neutral::check('customers', $db));
    same('***', Neutral::redact('customers', $db)['password_hash']);
    notContains('B5xK0e8n', json_encode(Neutral::redact('customers', $db)));
});

test('clientes: dos direcciones con su predeterminada, el NIF de un plugin y el correo obligatorio', function (): void {
    $source = wooRoute('/wp-json/wc/v3/customers', 0);
    $source['meta_data'] = [['key' => 'billing_nif', 'value' => '12345678Z']];
    $c = wooMapper()->customer($source);
    same([], Neutral::check('customers', $c));
    same(2, count($c['addresses']));
    same(['billing', true, false], [$c['addresses'][0]['id'], $c['addresses'][0]['default_billing'], $c['addresses'][0]['default_shipping']]);
    same(['shipping', false, true], [$c['addresses'][1]['id'], $c['addresses'][1]['default_billing'], $c['addresses'][1]['default_shipping']]);
    same('12345678Z', $c['vat_id']);
    same('M', $c['addresses'][0]['region']);
    same('ES', $c['addresses'][0]['country']);
    same('999000001', $c['phone']);
    $source['email'] = '';
    throws(function () use ($source): void {
        wooMapper()->customer($source);
    }, InvalidArgumentException::class);
});

test('fechas: verano e invierno de Madrid a UTC, la de GMT manda y la vacía es null', function (): void {
    $m = wooMapper();
    same('2026-07-01T10:00:00Z', $m->date('', '2026-07-01 12:00:00'));
    same('2026-01-10T11:00:00Z', $m->date('', '2026-01-10 12:00:00'));
    same('2026-07-01T08:00:00Z', $m->date('2026-07-01T08:00:00', '2026-07-01 12:00:00'));
    same(null, $m->date('', ''));
    same(null, $m->date('0000-00-00 00:00:00', '0000-00-00 00:00:00'));
    same(null, $m->date(null, null));
    same('+01:00', Mapper::timezone('', 1));
    same('-03:30', Mapper::timezone('', -3.5));
    same('Europe/Madrid', Mapper::timezone('Europe/Madrid', 9));
    same('es', Mapper::lang('es_ES'));
    same('pt-BR', Mapper::lang('pt_BR'));
    same('en', Mapper::lang(''));
});

test('rutas antiguas: producto, categoría, entrada con fecha y página, y sin estructura no hay ruta', function (): void {
    $p = new Permalinks('/%postname%/', ['product_base' => '/producto', 'category_base' => 'categoria']);
    same('/producto/mesa/', $p->product('mesa'));
    same('/categoria/muebles/mesas/', $p->productCategory('muebles/mesas'));
    same('/category/cuidados/', $p->postCategory('cuidados'));
    same('/padre/hija/', $p->page('padre/hija'));
    same('/hola/', $p->post('hola', 5, '2026-03-04 08:30:00'));
    $withCategory = new Permalinks('/%postname%/', ['product_base' => '/tienda/%product_cat%']);
    same('/tienda/muebles/mesas/mesa/', $withCategory->product('mesa', 'muebles/mesas'));
    $dated = new Permalinks('/%year%/%monthnum%/%day%/%postname%/');
    same('/2026/03/04/hola/', $dated->post('hola', 5, '2026-03-04 08:30:00'));
    $plain = new Permalinks('/%category%/%postname%');
    same('/noticias/x', $plain->post('x', 1, '2026-01-01 00:00:00', 'noticias'));
    same('/padre/hija', $plain->page('padre/hija'));
    $none = new Permalinks('');
    same(false, $none->enabled());
    same(null, $none->product('mesa'));
    same(null, $none->post('x', 1, '2026-01-01 00:00:00'));
    same(null, $none->page('x'));
});

test('menú: árbol por orden, inicio, enlace propio en relativo y lo enlazado por su tipo', function (): void {
    $menu = wooMapper()->menu(wooRoute('/wp-json/wp/v2/menus', 0), wooRoute('/wp-json/wp/v2/menu-items'));
    same([], Neutral::check('menus', $menu));
    same('primary', $menu['location']);
    same('50', $menu['id']);
    same(3, count($menu['items']));
    same(['es' => 'Tienda'], $menu['items'][0]['label']);
    same(['url', '/tienda/'], [$menu['items'][0]['type'], $menu['items'][0]['url']]);
    same(['category', '12'], [$menu['items'][0]['children'][0]['type'], $menu['items'][0]['children'][0]['ref']]);
    same(['page', '7'], [$menu['items'][1]['type'], $menu['items'][1]['ref']]);
    same('home', $menu['items'][2]['type']);
    throws(function (): void {
        wooMapper()->menu(['id' => '', 'name' => 'x', 'locations' => []], []);
    }, InvalidArgumentException::class);
});

test('ajustes: moneda, unidades, impuestos, dirección, correo y páginas legales', function (): void {
    $options = [];
    foreach (['general', 'products', 'tax', 'account', 'email', 'advanced'] as $group) {
        foreach (wooRoute('/wp-json/wc/v3/settings/' . $group) as $setting) {
            $options[$setting['id']] = $setting['value'];
        }
    }
    $s = wooMapper()->settings($options, ['title' => 'Muebles Ñandú & Cía', 'tagline' => 'Mesas, sillas y estanterías', 'timezone_string' => 'Europe/Madrid', 'gmt_offset' => 1, 'admin_email' => 'admin@tienda.test', 'next_order_number' => 9005]);
    same([], Neutral::check('settings', $s));
    same('main', $s['id']);
    same('EUR', $s['currency']);
    same(['kg', 'cm'], [$s['weight_unit'], $s['dimension_unit']]);
    same(false, $s['prices_include_tax']);
    same(true, $s['display_prices_with_tax']);
    same('shipping', $s['tax_based_on']);
    same(['line1' => 'Polígono Sur, nave 4', 'line2' => '', 'postcode' => '41001', 'city' => 'Sevilla', 'country' => 'ES', 'region' => 'SE'], $s['address']);
    same(['from_email' => 'hola@tienda.test', 'from_name' => 'Muebles Ñandú'], $s['mail']);
    same(['terms' => '8'], $s['legal_pages']);
    same(['es' => 'Muebles Ñandú & Cía'], $s['home_title']);
    same('Europe/Madrid', $s['timezone']);
    same(9005, $s['order_number_next']);
    same(true, $s['extra']['calc_taxes']);
});

test('plugins sin traer: se nombran los de ejemplo y no WooCommerce ni Yoast', function (): void {
    $plugins = [];
    foreach (wooRoute('/wp-json/wc/v3/system_status')['active_plugins'] as $plugin) {
        $plugins[] = ['slug' => substr($plugin['plugin'], 0, (int) strpos($plugin['plugin'], '/')), 'name' => $plugin['name']];
    }
    $unmapped = Mapper::unmappedPlugins($plugins);
    $names = array_map(static function (array $u): string {
        return $u['name'];
    }, $unmapped);
    same(['WooCommerce Subscriptions', 'MailPoet', 'Gravity Forms'], $names);
    contains('simple products', $unmapped[0]['note']);
    contains('subscribers', $unmapped[1]['note']);
    same('plugin', $unmapped[2]['kind']);
    same(1, $unmapped[2]['count']);
});

test('tandas: el cursor de posición, el final con null y un elemento roto que no corta la tanda', function (): void {
    same([[1, 2], '2'], Batch::slice([1, 2, 3, 4, 5], '', 2));
    same([[3, 4], '4'], Batch::slice([1, 2, 3, 4, 5], '2', 2));
    same([[5], null], Batch::slice([1, 2, 3, 4, 5], '4', 2));
    same([[], null], Batch::slice([], '', 5));
    throws(function (): void {
        Batch::slice([1], 'abc', 2);
    }, SourceError::class);
    list($records, $skipped) = Batch::map([['id' => 1], ['id' => 2], 'basura', ['id' => 4]], static function (array $item): array {
        if ($item['id'] === 2) {
            throw new InvalidArgumentException('no vale');
        }
        if ($item['id'] === 4) {
            throw new TypeError('roto');
        }

        return $item;
    });
    same([['id' => 1]], $records);
    same([['id' => '2', 'reason' => 'no vale'], ['id' => '?', 'reason' => 'not an object'], ['id' => '4', 'reason' => 'unexpected TypeError: roto']], $skipped);
    throws(function (): void {
        Batch::map([['id' => 1]], static function (array $item): array {
            throw new SourceError('el origen falla');
        });
    }, SourceError::class);
});
