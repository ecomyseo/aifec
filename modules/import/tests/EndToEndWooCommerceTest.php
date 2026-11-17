<?php
/**
 * AIFEC — la migración de WooCommerce de punta a punta: una tienda de mentira completa (copia de su base de datos con HPOS, y por su API REST)
 * entra por el motor de verdad, con los lectores y los escritores de verdad. Se comprueba con cifras que lo de aquí es lo de allí: precios al
 * céntimo con los impuestos del origen, las tres clases de contraseña de WordPress, pedidos con su reembolso, páginas, entradas y menús, las
 * direcciones antiguas y la comprobación final; y que no sale ni un correo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\Neutral;

require_once __DIR__ . '/fixtures/E2eKit.php';

/**
 * Migra la tienda WooCommerce completa por su copia de la base de datos.
 *
 * @param array<string,mixed> $options
 *
 * @return array{0:App,1:array<string,mixed>,2:int,3:PDO}
 */
function e2eWooMigrated(array $options = [], bool $hpos = true, bool $parentManagesStock = true): array
{
    [$app] = e2eApp();
    $pdo = e2eWooPdo($hpos, $parentManagesStock);
    $id = e2eWooDraft($app, $pdo, $options + ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], 'la migración termina: ' . (string) $run['pause_reason']);

    return [$app, $run, $id, $pdo];
}

e2eTest('WooCommerce: la migración entera termina y en cada tipo leídos = creados + actualizados + sin cambios + choques + descartados', function (): void {
    [$app, $run] = e2eWooMigrated();
    $expected = ['settings' => 1, 'tax_classes' => 2, 'tax_rates' => 4, 'shipping_zones' => 2, 'carriers' => 4, 'payment_methods' => 2, 'brands' => 1, 'categories' => 4, 'attributes' => 2,
        'products' => 6, 'customers' => 3, 'coupons' => 2, 'orders' => 4, 'reviews' => 2, 'media' => 6, 'pages' => 3, 'post_categories' => 2, 'posts' => 2, 'menus' => 1, 'redirects' => 5, 'team' => 2];
    foreach ($expected as $type => $read) {
        same($read, $run['counters'][$type]['read'], $type . ': registros leídos');
        same($read, importSum($run, $type), $type . ': read = created + updated + unchanged + conflict + skipped');
    }
    same(0, $run['counters']['products']['skipped'] + $run['counters']['orders']['skipped'] + $run['counters']['customers']['skipped'], 'ni un producto, pedido o cliente descartado');
    same(6, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'), 'seis productos: el de la papelera no viene');
});

e2eTest('WooCommerce: los productos cuestan aquí lo mismo que allí con sus impuestos (129,00 €, su oferta de 107,69 €, 60,50 €, 72,60 € y la variación en oferta)', function (): void {
    [$app] = e2eWooMigrated();
    $catalog = aifecCatalog($app);
    $mesa = $catalog->product((int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'MESA-ROBLE'"), 'es')['price'];
    // 106,61 sin IVA + 21 % = 129,00 (128,9981); la oferta 89,00 + 21 % = 107,69.
    same([10769, 12900], [$mesa['amount'], $mesa['compare']], 'la mesa con su oferta vigente y el precio normal al lado');
    same('107,69 €', str_replace("\xC2\xA0", ' ', money($mesa['amount'])));
    same(2100, $mesa['tax_rate']);
    same(1209, (int) $catalog->product((int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'MANUAL-PDF'"), 'es')['price']['gross'], '9,99 + 21 % = 12,09');
    same(9559, (int) $catalog->product((int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'ESTANTE-OF'"), 'es')['price']['amount'], 'la estantería en oferta: 79,00 + 21 % = 95,59');
    $silla = $catalog->product((int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'SILLA-NOGAL'"), 'es');
    $prices = [];
    foreach ($silla['variants'] as $variant) {
        $prices[$variant['sku']] = [$variant['price']['amount'], $variant['price']['compare']];
    }
    ksort($prices);
    same(['SILLA-NOGAL-G' => [7623, 8470], 'SILLA-NOGAL-M' => [7260, null], 'SILLA-NOGAL-P' => [6050, null]], $prices, '50, 60 y 70 (63 en oferta) + 21 %');
    same(['SILLA-NOGAL-P' => 9, 'SILLA-NOGAL-M' => 5, 'SILLA-NOGAL-G' => 2], array_map('intval', array_column($app->db()->select("SELECT sku, stock FROM {variants} WHERE sku LIKE 'SILLA-NOGAL%' ORDER BY sku DESC"), 'stock', 'sku')), 'y sus existencias');
});

e2eTest('WooCommerce: los tres tipos de contraseña de WordPress entran: phpass («$P$»), bcrypt y el formato nuevo («$wp$»), y se renuevan', function (): void {
    [$app] = e2eWooMigrated();
    $customers = $app->service('customers');
    foreach ([['ana@cliente.test', 'clave-ana-1', 'phpass'], ['luis@cliente.test', 'clave-luis-2', 'bcrypt'], ['marta@cliente.test', 'clave-marta-3', 'formato nuevo de WordPress']] as $case) {
        same(null, $customers->login($case[0], 'una-clave-que-no-es'), $case[2] . ': con otra contraseña no entra');
        $id = $customers->login($case[0], $case[1]);
        check(is_int($id) && $id > 0, $case[2] . ': entra con la de siempre');
        $hash = (string) $app->db()->value('SELECT password FROM {customers} WHERE id = ?', [$id]);
        check(strpos($hash, '$P$') !== 0 && strpos($hash, '$wp$') !== 0, $case[2] . ': y su resumen se renovó a uno de aquí');
        $customers->logout();
    }
    same(['woocommerce', '77'], [(string) $app->db()->value("SELECT source FROM {customers} WHERE email = 'ana@cliente.test'"), (string) $app->db()->value("SELECT legacy_id FROM {customers} WHERE email = 'ana@cliente.test'")]);
});

e2eTest('WooCommerce: los pedidos llegan con sus totales, sus impuestos, su estado y el reembolso parcial, sin descontar existencias', function (): void {
    [$app, $run, $id, $pdo] = e2eWooMigrated();
    $source = $pdo->query("SELECT id, status, total_amount, tax_amount FROM wp_wc_orders WHERE type = 'shop_order' AND status NOT LIKE '%draft%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    same(4, count($source));
    $here = $app->db()->select("SELECT legacy_id, status, payment_status, total, total_tax, refunded_total, paid_total FROM {orders} WHERE source = 'import' ORDER BY CAST(legacy_id AS INTEGER)");
    same(4, count($here));
    $states = ['wc-processing' => 'processing', 'wc-completed' => 'completed', 'wc-cancelled' => 'cancelled', 'wc-on-hold' => 'on_hold'];
    foreach ($source as $i => $row) {
        same((string) $row['id'], (string) $here[$i]['legacy_id']);
        same(Neutral::cents((string) $row['total_amount']), (int) $here[$i]['total'], 'pedido ' . $row['id'] . ': total al céntimo');
        same(Neutral::cents((string) $row['tax_amount']), (int) $here[$i]['total_tax'], 'pedido ' . $row['id'] . ': impuestos');
        same($states[$row['status']], (string) $here[$i]['status'], 'pedido ' . $row['id'] . ': estado');
    }
    same(9559, (int) $here[1]['refunded_total'], 'el reembolso del 9002: 95,59 €');
    same('partially_refunded', (string) $here[1]['payment_status']);
    same(19832, (int) $here[1]['paid_total'], 'cobrado');
    same(14, (int) $app->db()->value('SELECT stock FROM {products} WHERE sku = ?', ['MESA-ROBLE']), 'existencias intactas: el pedido de la mesa no las descuenta');
    same(3, (int) $app->db()->value('SELECT stock FROM {products} WHERE sku = ?', ['ESTANTE-OF']), 'ni los dos pedidos de la estantería');
    same(13614 + 19832 + 18150 + 7974, (int) array_sum(array_column($here, 'total')), 'la suma de los pedidos');
});

e2eTest('WooCommerce: las páginas, las entradas y el menú llegan y se ven en la tienda', function (): void {
    [$app, $run] = e2eWooMigrated();
    $web = new TestClient($app);
    $pages = $app->db()->select('SELECT id FROM {pages} ORDER BY id');
    same(3, count($pages));
    $quienes = (int) $app->db()->value("SELECT entity_id FROM {slugs} WHERE type = 'page' AND path = 'quienes-somos'");
    check($quienes > 0, 'la página «Quiénes somos» tiene su dirección');
    $r = $web->get('/quienes-somos');
    same(200, $r->status);
    contains('Un taller de Sevilla.', $r->body);
    notContains('wp:paragraph', $r->body, 'los bloques de Gutenberg ya no llevan sus comentarios');
    $privacy = (string) $app->db()->value("SELECT path FROM {slugs} WHERE type = 'page' AND canonical = 1 AND path LIKE '%privacidad%'");
    check($privacy !== '', 'la política de privacidad');
    same(200, $web->get('/' . $privacy)->status);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {posts}'));
    $post = (string) $app->db()->value("SELECT path FROM {slugs} WHERE type = 'post' AND canonical = 1 AND path LIKE '%cuidar%'");
    check($post !== '', 'la entrada sobre cuidar la mesa');
    $r = $web->get('/' . $post);
    same(200, $r->status);
    contains('Un paño húmedo y nada de sol directo.', $r->body);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {menus}'));
    check((int) $app->db()->value('SELECT COUNT(*) FROM {menu_items}') >= 3, 'los elementos del menú');
});

e2eTest('WooCommerce: las direcciones antiguas funcionan: cada una de las elegidas por la comprobación responde o redirige con un 301', function (): void {
    [$app, $run] = e2eWooMigrated();
    $count = count($run['cursor']['ev']['paths']);
    check($count >= 10 && $count <= \Aifec\Modules\Import\Verify::SAMPLE_PATHS, 'hasta 50 direcciones elegidas: ' . $count);
    same([], e2eOldAddressesFailing($app, $run), 'todas responden');
    $paths = array_column($run['cursor']['ev']['paths'], 3);
    check(count(array_filter($paths, static function (string $p): bool {
        return strpos($p, '/producto/') === 0;
    })) > 0, 'las de la base de productos de WooCommerce («/producto/…») entre ellas');
    same([], e2eRedNoPages($run), 'y la comprobación las da por buenas');
});

/** @return array<int,array<string,mixed>> */
function e2eRedNoPages(array $run): array
{
    return array_values(array_filter($run['checksums']['verify']['rows'], static function (array $row): bool {
        return !$row['ok'];
    }));
}

e2eTest('WooCommerce: la comprobación final lo da todo en verde y import:verify sale con 0; con un total estropeado, en rojo con el pedido', function (): void {
    [$app, $run, $id] = e2eWooMigrated();
    same([], e2eRedNoPages($run), 'ni una fila en rojo');
    same([], $run['checksums']['diffs'], 'las cifras de los lectores cuadran con las de los escritores');
    same(true, $run['checksums']['verify']['ok']);
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(0, $code, $out);
    $order = $run['cursor']['ev']['orders'][0];
    $app->db()->run('UPDATE {orders} SET total = total - 1 WHERE legacy_id = ?', [(string) $order[1]]);
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(1, $code);
    contains('WRONG orders#' . $order[1], $out);
});

e2eTest('WooCommerce: una variable con el control de existencias solo en sus variaciones lo conserva por variante y su cifra es la suma de las de sus variantes (33 en total)', function (): void {
    [$app, $run] = e2eWooMigrated([], true, false);
    $silla = $app->db()->row("SELECT id, manage_stock, stock, has_variants FROM {products} WHERE sku = 'SILLA-NOGAL'");
    same(1, (int) $silla['manage_stock'], 'el producto controla existencias: las variantes no son infinitas');
    same(16, (int) $app->db()->value('SELECT SUM(stock) FROM {variants} WHERE product_id = ?', [(int) $silla['id']]), 'y sus variantes guardan las 16 unidades del origen');
    same(16, (int) $silla['stock'], 'la suma del padre es la de sus variantes');
    same([9, 5, 2], array_map('intval', array_column($app->db()->select('SELECT stock FROM {variants} WHERE product_id = ? ORDER BY position', [(int) $silla['id']]), 'stock')), 'cada variante con la suya');
    same([], $run['checksums']['diffs'], 'la comprobación de cifras cuadra: 33 contra 33');
    [$code, $out] = e2eCli($app, 'import:verify');
    same(0, $code, $out);
});

e2eTest('WooCommerce: repetir una migración terminada no cambia nada: 0 creados y 0 actualizados', function (): void {
    [$app, $first, $id, $pdo] = e2eWooMigrated();
    $before = e2eCounts($app);
    $fingerprint = e2eFingerprint($app);
    $run = e2eRun($app, e2eWooDraft($app, $pdo, ['import_images' => false]));
    same('done', $run['status']);
    foreach ($run['counters'] as $type => $c) {
        same(0, $c['created'], $type . ': nada creado');
        same(0, $c['updated'], $type . ': nada actualizado');
    }
    same($before, e2eCounts($app));
    same($fingerprint, e2eFingerprint($app));
});

e2eTest('WooCommerce: ni un correo, ni un trabajo de aviso, ni un gancho de pedido o de cliente, durante la migración ni después de las tareas programadas', function (): void {
    [$app, $transport] = e2eApp();
    $fired = [];
    foreach (['customer.registered', 'order.placed', 'order.paid', 'order.status', 'order.refunded', 'order.cancelled', 'review.published', 'invoice.issued'] as $hook) {
        $app->hooks()->on($hook, static function () use (&$fired, $hook): void {
            $fired[$hook] = (isset($fired[$hook]) ? $fired[$hook] : 0) + 1;
        });
    }
    e2eRun($app, e2eWooDraft($app, e2eWooPdo(true, true), ['import_images' => false]));
    same(4, (int) $app->db()->value("SELECT COUNT(*) FROM {customers} WHERE source IN ('woocommerce', 'import')"), 'tres clientes y el invitado de un pedido');
    same(4, (int) $app->db()->value("SELECT COUNT(*) FROM {orders} WHERE source = 'import'"));
    $now = $app->clock()->now();
    foreach ([0, 3, 30] as $days) {
        $app->clock()->set($now + $days * 86400);
        pwTasks($app);
    }
    same(0, count($transport->sent), 'ningún mensaje');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type LIKE 'mail%' OR type LIKE 'erp%' OR type LIKE 'datalayer%' OR type LIKE 'webhook%'"));
    same([], $fired, 'ningún gancho de aviso: ' . json_encode($fired));
});

e2eTest('WooCommerce por su API REST: la migración entera lee solo con GET del origen, trae lo mismo y no manda nada a nadie', function (): void {
    $http = WooTestKit::http();
    [$app, $transport] = e2eApp([], ['http' => $http]);
    $id = e2eService($app)->createDraft('woocommerce', 'api', [
        'url' => 'https://tienda.test', 'key' => 'ck_clave-de-mentira', 'secret' => 'cs_secreto-de-mentira', 'wp_user' => 'gestor', 'wp_password' => 'abcd efgh ijkl', 'requests_per_second' => 0,
    ], [], ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], (string) $run['pause_reason']);
    check(count($http->requests) > 20, 'muchas peticiones al origen: ' . count($http->requests));
    foreach ($http->requests as $request) {
        same('GET', $request->method, 'solo lee: ' . $request->url);
        same('tienda.test', parse_url($request->url, PHP_URL_HOST), 'y solo habla con el origen');
    }
    same(6, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));
    same(4, (int) $app->db()->value("SELECT COUNT(*) FROM {orders} WHERE source = 'import'"));
    same(0, count($transport->sent), 'ni un correo');
    notContains('cs_secreto-de-mentira', json_encode($run), 'la clave no queda en la fila de la migración');
    same([], pwLeaks($app, 'cs_secreto-de-mentira'), 'ni en ninguna tabla');
    same([], pwLeaks($app, 'abcd efgh ijkl'), 'ni la contraseña de aplicación de WordPress');
    $withoutPasswords = (int) $app->db()->value('SELECT COUNT(*) FROM {customers} WHERE password IS NULL');
    same((int) $app->db()->value('SELECT COUNT(*) FROM {customers}'), $withoutPasswords, 'la API no da contraseñas: los clientes entran restableciendo la suya');
});

e2eTest('PrestaShop por su servicio web: la migración entera lee solo con GET del origen y trae lo mismo, sin mandar nada a nadie', function (): void {
    $http = PsTestKit::http();
    [$app, $transport] = e2eApp([], ['http' => $http]);
    $id = e2eService($app)->createDraft('prestashop', 'api', ['url' => 'https://tienda.test', 'key' => 'clave-ws-de-mentira', 'requests_per_second' => 0, 'cookie_key' => E2E_COOKIE_KEY], [], ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], (string) $run['pause_reason']);
    check(count($http->requests) > 20, 'muchas peticiones al origen: ' . count($http->requests));
    foreach ($http->requests as $request) {
        same('GET', $request->method, 'solo lee: ' . $request->url);
        same('tienda.test', parse_url($request->url, PHP_URL_HOST));
    }
    same(6, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));
    check((int) $app->db()->value("SELECT COUNT(*) FROM {orders} WHERE source = 'import'") >= 1, 'hay pedidos traídos');
    same(0, count($transport->sent), 'ni un correo');
    same([], pwLeaks($app, E2E_COOKIE_KEY), 'la clave de cookies no está en claro en ninguna tabla');
    same([], pwLeaks($app, 'clave-ws-de-mentira'), 'ni la clave del servicio web');
    same([], $run['checksums']['diffs'], 'y las cifras cuadran');
});

e2eTest('WooCommerce: las direcciones de los clientes llegan con su nombre en el idioma de la tienda, no «Billing» y «Shipping»', function (): void {
    [$app] = e2eWooMigrated();
    $labels = array_map('strval', array_column($app->db()->select('SELECT DISTINCT label FROM {addresses} WHERE label IS NOT NULL ORDER BY label'), 'label'));

    check($labels !== [], 'hay direcciones traídas');
    same([], array_values(array_diff($labels, ['Dirección de facturación', 'Dirección de envío'])), 'solo los dos nombres en castellano (una misma dirección para las dos cosas se guarda una vez)');
    check(!in_array('Billing', $labels, true) && !in_array('Shipping', $labels, true), 'ninguna con el nombre en inglés del lector: ' . implode(', ', $labels));
});
