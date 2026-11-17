<?php
/**
 * AIFEC — la migración de PrestaShop de punta a punta: una tienda de mentira completa (copia de su base de datos) entra por el motor de
 * verdad, con los lectores y los escritores de verdad, y al terminar se comprueba con cifras que lo de aquí es lo de allí: los mismos precios
 * al céntimo, los mismos clientes entrando con su contraseña de siempre, los mismos pedidos con sus totales, las direcciones antiguas
 * funcionando y la comprobación final en verde (y en rojo cuando se estropea algo a propósito).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\Neutral;

require_once __DIR__ . '/fixtures/E2eKit.php';

e2eTest('PrestaShop: la migración entera termina y en cada tipo leídos = creados + actualizados + sin cambios + choques + descartados', function (): void {
    [$app, $run] = e2ePsMigrated();
    $expected = ['settings' => 1, 'tax_classes' => 2, 'tax_rates' => 8, 'shipping_zones' => 3, 'carriers' => 2, 'payment_methods' => 3, 'customer_groups' => 4, 'brands' => 2,
        'categories' => 4, 'attributes' => 4, 'products' => 6, 'customers' => 3, 'coupons' => 3, 'orders' => 12, 'reviews' => 2, 'pages' => 3, 'redirects' => 1, 'team' => 2, 'subscribers' => 3];
    foreach ($expected as $type => $read) {
        same($read, $run['counters'][$type]['read'], $type . ': registros leídos');
        same($read, importSum($run, $type), $type . ': read = created + updated + unchanged + conflict + skipped');
    }
    same(0, $run['counters']['products']['skipped'] + $run['counters']['orders']['skipped'] + $run['counters']['customers']['skipped'], 'ni un producto, pedido o cliente descartado');
    same('done', $run['phase']);
    same(false, e2eService($app)->connections()->has((int) $run['id']), 'al acabar se borran los datos de conexión');
});

e2eTest('PrestaShop: los productos cuestan aquí lo mismo que allí, al céntimo (100,00 €, 60,50 €, 10,91 €, 36,30 €, 145,20 € y su oferta)', function (): void {
    [$app] = e2ePsMigrated();
    // 82,644628 + 21 % = 100,00 (desde 82,64 saldrían 99,99); 50 + 21 % = 60,50; 9,917355 + 10 % = 10,91; 30 + 21 % = 36,30; 120 + 21 % = 145,20.
    same(10000, e2eGross($app, 'MESA-ROBLE'));
    same(6050, e2eGross($app, 'SILLA-NORDICA'));
    same(1091, e2eGross($app, 'GUIA-PDF'));
    same(3630, e2eGross($app, 'MANTEL-5'));
    same(11616, e2eGross($app, 'SILLON-4'), 'el sillón, con su rebaja del 20 % ya aplicada');
    $id = (int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'MESA-ROBLE'");
    $price = aifecCatalog($app)->product($id, 'es')['price'];
    same('100,00 €', str_replace("\xC2\xA0", ' ', money($price['gross'])), 'y se enseña como 100,00 €');
    same(2100, $price['tax_rate']);
    // La rebaja del 20 % con impuestos del sillón: 145,20 € → 116,16 €.
    $sillon = aifecCatalog($app)->product((int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'SILLON-4'"), 'es')['price'];
    same(11616, $sillon['amount'], 'la oferta vigente');
    same(14520, $sillon['compare'], 'y el precio normal al lado');
    same('SILLON-4', (string) $app->db()->value("SELECT sku FROM {products} WHERE id = ?", [(int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'SILLON-4'")]));
});

e2eTest('PrestaShop: las combinaciones existen con su precio final y sus existencias', function (): void {
    [$app] = e2ePsMigrated();
    $silla = (int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'SILLA-NORDICA'");
    $variants = $app->db()->select('SELECT sku, price, stock, active FROM {variants} WHERE product_id = ? ORDER BY sku', [$silla]);
    same(['SILLA-G-NOG', 'SILLA-G-ROB', 'SILLA-P-NOG', 'SILLA-P-ROB'], array_column($variants, 'sku'));
    // El precio de la combinación es el del producto más su impacto: 50 + 12,396694 = 62,396694 + 21 % = 75,50.
    same(['SILLA-G-NOG' => 8760, 'SILLA-G-ROB' => 7260, 'SILLA-P-NOG' => 7550, 'SILLA-P-ROB' => 6050], array_map('intval', array_column($variants, 'price', 'sku')));
    same(['SILLA-G-NOG' => 3, 'SILLA-G-ROB' => 7, 'SILLA-P-NOG' => 5, 'SILLA-P-ROB' => 10], array_map('intval', array_column($variants, 'stock', 'sku')));
    $names = $app->db()->select('SELECT vv.value_id FROM {variant_values} vv JOIN {variants} v ON v.id = vv.variant_id WHERE v.product_id = ?', [$silla]);
    same(8, count($names), 'cada combinación con sus dos atributos');
});

e2eTest('PrestaShop: el cliente entra con su contraseña de siempre, la de bcrypt y la antigua de MD5 con la clave de cookies, y se renueva', function (): void {
    [$app, $run] = e2ePsMigrated();
    $customers = $app->service('customers');
    same(null, $customers->login('ana@cliente.test', 'otra-clave-123'), 'con otra contraseña no entra');
    $ana = $customers->login('ana@cliente.test', 'secreto-123');
    check(is_int($ana) && $ana > 0, 'Ana entra con su contraseña de bcrypt');
    $luis = $customers->login('luis@cliente.test', 'clave-vieja-9');
    check(is_int($luis) && $luis > 0, 'Luis entra con su contraseña antigua de MD5 + clave de cookies');
    $hash = (string) $app->db()->value('SELECT password FROM {customers} WHERE id = ?', [$luis]);
    check(strpos($hash, '$') === 0 && !preg_match('/^[0-9a-f]{32}$/', $hash), 'y su resumen se renovó en la primera entrada');
    same(['prestashop', '2'], [(string) $app->db()->value('SELECT source FROM {customers} WHERE id = ?', [$luis]), (string) $app->db()->value('SELECT legacy_id FROM {customers} WHERE id = ?', [$luis])]);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {customers} WHERE email IN ('marta@cliente.test', 'borrado@cliente.test')"), 'ni la clienta de la otra tienda ni la borrada');
    same(3, (int) $run['counters']['customers']['created']);
});

e2eTest('PrestaShop: sin la clave de cookies los clientes de MD5 no entran, el resumen se guarda por si se da después y el informe lo dice', function (): void {
    [$app] = e2eApp();
    $id = e2ePsDraft($app, e2ePsPdo(), ['import_images' => false], [], ['cookie_key' => '']);
    $run = e2eRun($app, $id);
    same('done', $run['status']);
    same(null, $app->service('customers')->login('luis@cliente.test', 'clave-vieja-9'), 'sin la clave no se puede comprobar');
    check(is_int($app->service('customers')->login('ana@cliente.test', 'secreto-123')), 'la de bcrypt sí entra');
    $notes = implode(' ', array_column(e2eService($app)->runs()->logs($id, 'customers', 'note', 50), 'reason'));
    contains('cookie key', $notes);
    contains('clave de cookies', \Aifec\Modules\Import\Report::translateNote('The old PrestaShop passwords need the cookie key of the old shop (option cookie_key) and it was not given: those customers have to reset their password'));
});

e2eTest('PrestaShop: la clave de cookies no está en claro en ninguna tabla, ni en el registro, ni en el informe, ni en las pantallas', function (): void {
    [$app, $run, $id] = e2ePsMigrated();
    same([], pwLeaks($app, E2E_COOKIE_KEY), 'ni una tabla la lleva en claro');
    same([], pwLeaks($app, 'clave-de-bd-de-mentira'), 'ni la contraseña de la base de datos');
    foreach (e2eService($app)->runs()->logs($id, '', '', 1000) as $line) {
        notContains(E2E_COOKIE_KEY, (string) $line['reason']);
    }
    notContains(E2E_COOKIE_KEY, json_encode($run['options']), 'ni en las opciones de la migración');
    notContains(E2E_COOKIE_KEY, json_encode($run['cursor']), 'ni en el cursor');
    notContains(E2E_COOKIE_KEY, json_encode($run['checksums']), 'ni en la comprobación');
    $web = adminClient($app);
    foreach (['/admin/import', '/admin/import/' . $id . '/report', '/admin/import/' . $id . '/print', '/admin/import/' . $id . '/progress', '/admin/import/' . $id . '/report.csv', '/admin/import/' . $id . '/report.csv?part=verify'] as $path) {
        $r = $web->get($path);
        same(200, $r->status, $path);
        notContains(E2E_COOKIE_KEY, $r->body, $path);
        notContains('clave-de-bd-de-mentira', $r->body, $path);
    }
});

e2eTest('PrestaShop: los 12 pedidos llegan con sus totales, sus impuestos y sus estados, y sin descontar existencias', function (): void {
    [$app, $run, $id, $pdo] = e2ePsMigrated();
    $source = $pdo->query('SELECT id_order, current_state, total_paid_tax_incl, total_paid_tax_excl FROM ps_orders ORDER BY id_order')->fetchAll(PDO::FETCH_ASSOC);
    same(12, count($source));
    $here = $app->db()->select("SELECT legacy_id, status, payment_status, total, total_tax, total_net, source FROM {orders} WHERE source = 'import' ORDER BY CAST(legacy_id AS INTEGER)");
    same(12, count($here));
    $states = [1 => 'shipped', 2 => 'processing', 3 => 'completed', 4 => 'cancelled', 5 => 'processing', 6 => 'processing', 7 => 'shipped', 8 => 'completed', 9 => 'completed', 10 => 'cancelled', 11 => 'refunded', 12 => 'failed'];
    foreach ($source as $i => $row) {
        $order = $here[$i];
        same((string) $row['id_order'], (string) $order['legacy_id']);
        same(Neutral::cents((string) $row['total_paid_tax_incl']), (int) $order['total'], 'pedido ' . $row['id_order'] . ': el total, al céntimo');
        same(Neutral::cents((string) $row['total_paid_tax_incl']) - Neutral::cents((string) $row['total_paid_tax_excl']), (int) $order['total_tax'], 'pedido ' . $row['id_order'] . ': los impuestos');
        same($states[(int) $row['id_order']], (string) $order['status'], 'pedido ' . $row['id_order'] . ': el estado');
    }
    same(102916, (int) array_sum(array_column($here, 'total')), 'la suma de todos los pedidos');
    same(26, (int) $app->db()->value('SELECT COUNT(*) FROM {order_items} WHERE order_id IN (SELECT id FROM {orders} WHERE source = ?)', ['import']), '14 líneas de producto y 12 de envío');
    // Sin descontar existencias: la combinación que llevan 9 pedidos conserva las 5 unidades del origen.
    same(5, (int) $app->db()->value("SELECT stock FROM {variants} WHERE sku = 'SILLA-P-NOG'"), 'existencias intactas');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {order_items} i JOIN {orders} o ON o.id = i.order_id WHERE o.source = 'import' AND i.kind = 'product' AND i.product_id IS NULL"), 'todas las líneas enlazadas a su producto');
});

e2eTest('PrestaShop: las direcciones antiguas funcionan por el sitio de verdad: la conservada responde y las demás redirigen con un 301', function (): void {
    [$app] = e2ePsMigrated();
    $web = new TestClient($app);
    $r = $web->get('/es/sillas/2-silla-nordica.html');
    same(200, $r->status, 'la antigua dirección de la silla responde ella misma');
    contains('Silla nórdica', $r->body);
    $r = $web->get('/en/chairs/2-nordic-chair.html');
    same(301, $r->status, 'la inglesa redirige');
    contains('/es/sillas/2-silla-nordica.html', $r->headers['Location']);
    same(200, $web->follow()->status, 'y al seguirla llega a la silla');
    same(301, $web->get('/en/content/1-delivery-and-shipping')->status, 'también una página, la inglesa');
    same(200, $web->get('/content/1-entrega-y-envios')->status, 'y la de la página en el idioma principal, sin prefijo');
});

e2eTest('PrestaShop: la dirección antigua de una página con el prefijo del idioma principal («/es/content/…») redirige con un 301 a la nueva y la comprobación lo da por bueno', function (): void {
    [$app, $run] = e2ePsMigrated();
    $web = new TestClient($app);
    // Con «es» de idioma principal la tienda no acepta el prefijo /es/: se guarda la dirección sin él Y la que la gente tenía enlazada.
    $r = $web->get('/es/content/1-entrega-y-envios');
    same(301, $r->status, 'la dirección que tenía la gente enlazada ya no da 404: redirige');
    contains('/content/1-entrega-y-envios', (string) $r->headers['Location']);
    notContains('/es/', (string) $r->headers['Location'], 'y no vuelve a llevar el prefijo');
    same(200, $web->follow()->status, 'y al seguirla llega a la página');
    same(200, $web->get('/content/1-entrega-y-envios')->status, 'la misma sin el prefijo responde');
    $pages = array_values(array_filter($run['checksums']['verify']['rows'], static function (array $row): bool {
        return $row['section'] === 'addresses' && strpos((string) $row['record'], 'pages#') === 0;
    }));
    check(count($pages) >= 3, 'las direcciones de las páginas entran en la muestra: ' . count($pages));
    foreach ($pages as $row) {
        same(true, $row['ok'], 'responde: ' . $row['vars']['path']);
    }
    same([], e2eRed($run['checksums']['verify']), 'y no hay nada en rojo');
});

e2eTest('PrestaShop: con las direcciones antiguas sin conservar, todas redirigen con un 301 a la nueva', function (): void {
    [$app] = e2ePsMigrated(['keep_urls' => false]);
    $web = new TestClient($app);
    $new = (string) $app->slugs()->path('product', (int) $app->db()->value("SELECT id FROM {products} WHERE sku = 'SILLA-NORDICA'"), 'es');
    check($new !== '' && strpos($new, 'es/sillas/2-silla') === false, 'la dirección nueva es la de aquí: ' . $new);
    $r = $web->get('/es/sillas/2-silla-nordica.html');
    same(301, $r->status);
    contains($new, $r->headers['Location']);
    same(200, $web->follow()->status);
    $dest = e2eService($app)->runs()->find(1);
    same([], e2eRed($dest['checksums']['verify']), 'y la comprobación final da por buenas las direcciones de productos y categorías');
});

e2eTest('PrestaShop: la comprobación final lo da todo en verde: cifras, pedidos por estado y por año, 20 productos, 10 pedidos, hasta 50 direcciones y todos los precios', function (): void {
    [$app, $run] = e2ePsMigrated();
    $verify = $run['checksums']['verify'];
    same([], e2eRed($verify), 'ni una fila en rojo');
    same([], $run['checksums']['diffs'], 'y las cifras de los lectores cuadran con las de los escritores');
    $by = [];
    foreach ($verify['rows'] as $row) {
        $by[$row['section']][] = $row;
    }
    foreach (['counts', 'states', 'years', 'products', 'prices', 'orders', 'addresses'] as $section) {
        check(isset($by[$section]) && count($by[$section]) > 0, 'hay filas de ' . $section);
    }
    same(6, count(array_filter($by['products'], static function (array $row): bool {
        return strpos($row['label'], 'variant') === false && strpos($row['label'], 'sale') === false;
    })), 'los seis productos de la tienda entran en la muestra de veinte');
    same(20, count($by['orders']), '10 pedidos, con su total y sus impuestos');
    $summary = $by['addresses'][0];
    same('Old addresses that work or redirect', $summary['label'], 'la primera fila de las direcciones es la suma');
    check($summary['source'] >= 10 && $summary['source'] <= \Aifec\Modules\Import\Verify::SAMPLE_PATHS, 'una muestra de hasta 50 direcciones: ' . $summary['source']);
    same($summary['source'], $summary['here'], 'y todas responden o redirigen');
    same($summary['source'] + 1, count($by['addresses']), 'cada dirección de la muestra, además de la suma');
    $prices = $by['prices'][0];
    same('Prices with tax identical', $prices['label']);
    same($prices['source'], $prices['here'], 'todos los precios con impuestos coinciden');
    same((int) $app->db()->value("SELECT COUNT(*) FROM {import_map} WHERE type = '_gross'"), $prices['source'], 'se comprobaron todos los apuntados');
    check($prices['source'] >= 10, 'productos, ofertas y variantes: ' . $prices['source']);
    same(['2025', '2026'], array_values(array_unique(array_map(static function (array $row): string {
        return (string) $row['vars']['year'];
    }, $by['years']))), 'pedidos por año');
    $amounts = array_values(array_filter($by['years'], static function (array $row): bool {
        return $row['kind'] === 'cents';
    }));
    same(102916, $amounts[0]['source'] + $amounts[1]['source'], 'la suma de importes por año, la del origen');
    same(102916, $amounts[0]['here'] + $amounts[1]['here']);
});

e2eTest('PrestaShop: import:verify da todo en verde y, si se estropea a propósito un precio aquí, lo marca en rojo con ese producto', function (): void {
    [$app, $run, $id] = e2ePsMigrated(['types' => e2eWithoutPages()]);
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(0, $code, $out);
    contains('No differences', $out);
    contains('Everything matches', $out);
    $app->db()->run("UPDATE {products} SET price = price + 100 WHERE sku = 'SILLA-NORDICA'");
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(1, $code, 'con una diferencia, sale con 1');
    contains('WRONG products#2', $out);
    contains('Product SILLA-NORDICA (#2): price with tax', $out);
    contains('source 60.50, here 61.50', $out);
    contains('WRONG Prices with tax identical', $out);
    $row = importRun($app, $id)['checksums']['verify'];
    same(false, $row['ok']);
    same(2, $row['wrong'], 'dos filas en rojo: la muestra con ese producto y el recuento de todos los precios');
    $prices = array_values(array_filter($row['rows'], static function (array $r): bool {
        return $r['label'] === 'Prices with tax identical';
    }));
    same(1, $prices[0]['source'] - $prices[0]['here'], 'y en el recuento falta exactamente uno');
    // Se arregla y vuelve el verde.
    $app->db()->run("UPDATE {products} SET price = price - 100 WHERE sku = 'SILLA-NORDICA'");
    [$code] = e2eCli($app, 'import:verify', [(string) $id]);
    same(0, $code);
});

e2eTest('PrestaShop: la comprobación marca también un pedido, una dirección antigua y un cliente que se estropean después', function (): void {
    [$app, $run, $id] = e2ePsMigrated();
    $evidence = $run['cursor']['ev'];
    $order = $evidence['orders'][0];
    $path = $evidence['paths'][0];
    $app->db()->run('UPDATE {orders} SET total = total + 5 WHERE legacy_id = ?', [(string) $order[1]]);
    $app->db()->run('DELETE FROM {slugs} WHERE path = ?', [\Aifec\Kernel\Slugs::clean((string) $path[3])]);
    $app->db()->run("DELETE FROM {import_map} WHERE type = 'customers' AND source_id = '2'");
    [$code, $out] = e2eCli($app, 'import:verify', [(string) $id]);
    same(1, $code);
    contains('WRONG orders#' . $order[1], $out);
    contains('WRONG ' . $path[1] . '#' . $path[2] . ' Old address', $out);
    contains('WRONG Customers: source 3, here 2', $out);
    $labels = array_column(array_filter(importRun($app, $id)['checksums']['verify']['rows'], static function (array $r): bool {
        return !$r['ok'];
    }), 'label');
    check(in_array('Customers', $labels, true), 'el recuento de clientes salta');
    check(in_array('Orders placed in {year}', $labels, true) || in_array('Amount of the orders placed in {year}', $labels, true), 'y el importe del año');
});

e2eTest('PrestaShop: con una tienda vacía los precios se siguen escribiendo CON impuestos y MESA-ROBLE cobra 100,00 € como en el origen; la comprobación lo da por bueno', function (): void {
    [$app, $run, $id] = e2ePsMigrated([], false);
    same(true, $app->settings()->bool('tax.prices_include_tax', true), 'el origen guarda los precios con seis decimales: aquí siguen con impuestos');
    same(10000, (int) $app->db()->value("SELECT price FROM {products} WHERE sku = 'MESA-ROBLE'"), 'se guarda el importe con impuestos, redondeado UNA vez: 82,644628 + 21 %');
    same(10000, e2eGross($app, 'MESA-ROBLE'), 'y cobra 100,00 €');
    same([], e2eRed($run['checksums']['verify']), 'ni una fila en rojo');
    $notes = implode(' ', array_column(e2eService($app)->runs()->logs($id, 'settings', 'note', 20), 'reason'));
    contains('prices keep including taxes', $notes);
});

e2eTest('PrestaShop: en una tienda que ya escribe los precios sin impuestos, un importe que una base en céntimos no puede formar cambia un céntimo, se apunta y la comprobación lo marca', function (): void {
    [$app] = e2eApp(['tax.prices_include_tax' => false]);
    aifecProduct($app, ['name' => 'Producto propio', 'sku' => 'PROPIO-1', 'price' => 1000]);
    $id = e2ePsDraft($app, e2ePsPdo(), ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('done', $run['status'], (string) $run['pause_reason']);
    same(false, $app->settings()->bool('tax.prices_include_tax', true), 'la tienda sigue escribiendo sin impuestos');
    $gross = e2eGross($app, 'MESA-ROBLE');
    same(1, abs($gross - 10000), 'a 21 % la base 82,64 da 99,99 y la 82,65 da 100,01: 100,00 no existe, se queda en el más cercano: ' . $gross);
    $notes = implode(' ', array_column(e2eService($app)->runs()->logs($id, 'products', 'note', 50), 'reason'));
    contains('differs by a cent', $notes);
    $red = e2eRed($run['checksums']['verify']);
    check(count($red) >= 1, 'la comprobación lo marca');
    $mesa = array_values(array_filter($red, static function (array $row): bool {
        return $row['record'] === 'products#1';
    }));
    same([10000, $gross], [$mesa[0]['source'], $mesa[0]['here']], 'con lo que cobraba el origen y lo que cobra aquí');
    foreach ($red as $row) {
        same(true, abs((int) $row['source'] - (int) $row['here']) === 1, 'ninguna diferencia pasa de un céntimo: ' . $row['label']);
    }
});

e2eTest('PrestaShop: repetir una migración terminada no cambia nada: 0 creados y 0 actualizados', function (): void {
    [$app, $first, $id, $pdo] = e2ePsMigrated();
    $before = e2eCounts($app);
    $fingerprint = e2eFingerprint($app);
    $second = e2ePsDraft($app, $pdo, ['import_images' => false]);
    $run = e2eRun($app, $second);
    same('done', $run['status']);
    foreach ($run['counters'] as $type => $c) {
        same(0, $c['created'], $type . ': nada creado');
        same(0, $c['updated'], $type . ': nada actualizado');
        same($c['read'], $c['unchanged'] + $c['skipped'] + $c['conflict'], $type . ': todo sin cambios (o descartado como la vez anterior)');
    }
    same($before, e2eCounts($app), 'ninguna tabla cambia de tamaño');
    same($fingerprint, e2eFingerprint($app), 'ni de contenido');
    same([], e2eRed($run['checksums']['verify']), 'y la comprobación sigue en verde');
});

e2eTest('PrestaShop: cortar a mitad con una excepción en los pedidos y relanzar da el mismo resultado final que sin cortar, sin duplicados', function (): void {
    // La referencia: una migración sin cortes.
    [$clean, $cleanRun] = e2ePsMigrated();
    $reference = e2eFingerprint($clean);
    $referenceCounts = e2eCounts($clean);

    // La cortada: el origen deja de contestar (una excepción de verdad) al leer la segunda tanda de pedidos.
    [$app] = e2eApp();
    aifecProduct($app, ['name' => 'Producto propio', 'sku' => 'PROPIO-1', 'price' => 1000]);
    $pdo = new E2eFlakyPdo('sqlite::memory:');
    e2eCopyPdo(e2ePsPdo(), $pdo);
    $pdo->armOn = 'SELECT o.* FROM ps_orders o';
    $pdo->armAfter = 1;
    $id = e2ePsDraft($app, $pdo, ['import_images' => false]);
    $run = e2eRun($app, $id);
    same('failed', $run['status'], 'la migración cortada queda cerrada como fallida: ' . (string) $run['pause_reason']);
    contains('origen se cayó', (string) $run['pause_reason']);
    $partial = $app->db()->select("SELECT legacy_id FROM {orders} WHERE source = 'import' ORDER BY id");
    check(count($partial) > 0 && count($partial) < 12, 'se quedó a medias: ' . count($partial) . ' pedidos de 12');
    // Se relanza (el origen vuelve): una migración nueva sobre la misma tienda.
    $pdo->armOn = '';
    $second = e2ePsDraft($app, $pdo, ['import_images' => false]);
    $again = e2eRun($app, $second);
    same('done', $again['status']);
    same($referenceCounts, e2eCounts($app), 'las mismas filas en cada tabla que sin cortar');
    same($reference, e2eFingerprint($app), 'y el mismo contenido');
    same(12, (int) $app->db()->value("SELECT COUNT(DISTINCT legacy_id) FROM {orders} WHERE source = 'import'"));
    same(12, (int) $app->db()->value("SELECT COUNT(*) FROM {orders} WHERE source = 'import'"), 'ni un pedido duplicado');
    same([], e2eRed($again['checksums']['verify']), 'y la comprobación final en verde');
});

e2eTest('PrestaShop: matar el proceso entre lo escrito y su apunte y seguir no duplica nada ni se salta nada (el cursor avanza por lo consumido)', function (): void {
    [$clean] = e2ePsMigrated();
    $reference = e2eFingerprint($clean);
    $referenceCounts = e2eCounts($clean);

    [$app] = e2eApp();
    aifecProduct($app, ['name' => 'Producto propio', 'sku' => 'PROPIO-1', 'price' => 1000]);
    $id = e2ePsDraft($app, e2ePsPdo(), ['import_images' => false]);
    $runner = e2eService($app)->runner();
    $app->settings()->set('import.step_records', 7);
    $runs = e2eService($app)->runs();
    check($runner->start($id, false)['ok']);
    $kills = 0;
    for ($i = 0; $i < 400; ++$i) {
        $snapshot = $runs->find($id);
        $outcome = $runner->step($id);
        if (in_array($outcome, ['done', 'failed', 'paused'], true)) {
            break;
        }
        // Cada tercera tanda, el proceso «muere» justo después de escribir y antes de apuntar el cursor: la fila vuelve a como estaba.
        if ($i % 3 === 1) {
            $runs->update($id, ['cursor' => $snapshot['cursor'], 'counters' => $snapshot['counters'], 'unmapped' => $snapshot['unmapped'], 'phase' => $snapshot['phase'],
                'current_type' => $snapshot['current_type']]);
            ++$kills;
        }
    }
    check($kills >= 5, 'se mató varias veces: ' . $kills);
    $run = $runs->find($id);
    same('done', $run['status'], (string) $run['pause_reason']);
    same($referenceCounts, e2eCounts($app), 'las mismas filas en cada tabla que sin matar nada');
    same($reference, e2eFingerprint($app), 'y el mismo contenido');
    same([], e2eRed($run['checksums']['verify']), 'y la comprobación final en verde');
    same([], $run['checksums']['diffs'], 'con las cifras de los lectores cuadrando con las de los escritores');
});

e2eTest('PrestaShop: --dry-run no escribe nada en ninguna tabla y dice lo que haría', function (): void {
    [$app] = e2eApp();
    $pdo = e2ePsPdo();
    e2eService($app)->connections()->setPdoFactory(static function () use ($pdo): PDO {
        return $pdo;
    });
    $before = e2eCounts($app);
    $tables = [];
    foreach ($app->db()->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $tables[(string) $name] = (int) $app->db()->pdo()->query('SELECT COUNT(*) FROM "' . $name . '"')->fetchColumn();
    }
    putenv('AIFEC_IMPORT_DB_PASSWORD=clave-de-bd-de-mentira');
    putenv('AIFEC_IMPORT_COOKIE_KEY=' . E2E_COOKIE_KEY);
    [$code, $out] = e2eCli($app, 'import:run', ['prestashop:db', 'dry-run' => true, 'db-host' => 'bd.test', 'db-name' => 'tienda_vieja', 'db-user' => 'usuario', 'prefix' => 'ps_', 'base-url' => 'https://vieja.test']);
    putenv('AIFEC_IMPORT_DB_PASSWORD');
    putenv('AIFEC_IMPORT_COOKIE_KEY');
    same(0, $code, $out);
    contains('Simulation #1 started', $out);
    contains('products', $out);
    same($before, e2eCounts($app));
    $after = [];
    foreach ($app->db()->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $after[(string) $name] = (int) $app->db()->pdo()->query('SELECT COUNT(*) FROM "' . $name . '"')->fetchColumn();
    }
    $changed = [];
    foreach ($after as $name => $n) {
        if (($tables[$name] ?? 0) !== $n) {
            $changed[] = $name;
        }
    }
    same([], array_values(array_diff($changed, ['af_import_log', 'af_import_runs', 'af_settings', 'sqlite_sequence'])), 'solo cambian las tablas de la propia migración (su fila, sus notas y su conexión guardada): ' . implode(', ', $changed));
    $run = importRun($app, 1);
    same(1, $run['dry_run']);
    same([], $run['checksums'], 'un simulacro no hace comprobación final');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_map}'));
});

e2eTest('PrestaShop: --only=products solo trae productos y dice de qué le falta qué', function (): void {
    [$app] = e2eApp();
    $pdo = e2ePsPdo();
    e2eService($app)->connections()->setPdoFactory(static function () use ($pdo): PDO {
        return $pdo;
    });
    putenv('AIFEC_IMPORT_DB_PASSWORD=clave-de-bd-de-mentira');
    [$code, $out] = e2eCli($app, 'import:run', ['prestashop:db', 'yes' => true, 'only' => 'products', 'db-host' => 'bd.test', 'db-name' => 'tienda_vieja', 'db-user' => 'usuario', 'prefix' => 'ps_', 'base-url' => 'https://vieja.test']);
    putenv('AIFEC_IMPORT_DB_PASSWORD');
    same(0, $code, $out);
    contains('Note: «products» refers to', $out);
    contains('categories', $out);
    contains('brands', $out);
    contains('tax_classes', $out);
    same(['products'], array_keys(importRun($app, 1)['counters']));
    same(6, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}') + (int) $app->db()->value('SELECT COUNT(*) FROM {customers}') + (int) $app->db()->value('SELECT COUNT(*) FROM {categories}'));
    $notes = implode(' ', array_column(e2eService($app)->runs()->logs(1, 'products', 'note', 100), 'reason'));
    contains('The brand of the product was not brought', $notes);
    contains('The tax class of the product was not brought', $notes);
    contains('Some categories of the product were not brought', $notes);
});

e2eTest('PrestaShop: import:run pinta una barra de avance por tipo y el resumen final, y import:verify la comprobación', function (): void {
    [$app] = e2eApp();
    aifecProduct($app, ['name' => 'Producto propio', 'sku' => 'PROPIO-1', 'price' => 1000]);
    $pdo = e2ePsPdo();
    e2eService($app)->connections()->setPdoFactory(static function () use ($pdo): PDO {
        return $pdo;
    });
    $app->settings()->set('import.step_records', 5);
    putenv('AIFEC_IMPORT_DB_PASSWORD=clave-de-bd-de-mentira');
    putenv('AIFEC_IMPORT_COOKIE_KEY=' . E2E_COOKIE_KEY);
    [$code, $out] = e2eCli($app, 'import:run', ['prestashop:db', 'yes' => true, 'no-images' => true, 'only' => implode(',', e2eWithoutPages()), 'db-host' => 'bd.test', 'db-name' => 'tienda_vieja', 'db-user' => 'usuario', 'prefix' => 'ps_', 'base-url' => 'https://vieja.test']);
    putenv('AIFEC_IMPORT_DB_PASSWORD');
    putenv('AIFEC_IMPORT_COOKIE_KEY');
    same(0, $code, $out);
    contains('Migration #1 started', $out);
    preg_match_all('/^\s+(\w+)\s+\[(#*)(-*)\]\s+(\d+)%\s+(\d+)\/(\d+|\?)$/m', $out, $bars, PREG_SET_ORDER);
    check(count($bars) >= 19, 'una barra por tipo como mínimo: ' . count($bars));
    $orders = array_values(array_filter($bars, static function (array $b): bool {
        return $b[1] === 'orders';
    }));
    check(count($orders) >= 2, 'los pedidos, en varias tandas, tienen varias líneas de avance');
    same(['100', '12', '12'], [end($orders)[4], end($orders)[5], end($orders)[6]], 'y acaban al 100 % con 12 de 12');
    same(str_repeat('#', 20), end($orders)[2], 'barra llena');
    contains('Finished as done: read ', $out);
    contains('created ', $out);
    check(preg_match('/conflicts 0, skipped \d+/', $out) === 1, 'el resumen final cuenta los descartados');
    [$code, $out] = e2eCli($app, 'import:verify');
    same(0, $code, $out);
    contains('Everything matches', $out);
    contains('Orders in state «processing»', $out);
});

e2eTest('PrestaShop: ni un correo, ni un trabajo de correo, ni un aviso ni un gancho de bienvenida o de pedido durante la migración ni después, con todas las tareas programadas', function (): void {
    [$app, $transport] = e2eApp();
    $fired = [];
    foreach (['customer.registered', 'order.placed', 'order.paid', 'order.status', 'order.refunded', 'order.cancelled', 'review.published', 'invoice.issued', 'cart.changed'] as $hook) {
        $app->hooks()->on($hook, static function () use (&$fired, $hook): void {
            $fired[$hook] = (isset($fired[$hook]) ? $fired[$hook] : 0) + 1;
        });
    }
    $pdo = e2ePsPdo();
    $id = e2ePsDraft($app, $pdo, ['import_images' => false]);
    e2eRun($app, $id);
    same(['customers' => 3, 'orders' => 12], [
        'customers' => (int) $app->db()->value("SELECT COUNT(*) FROM {customers} WHERE source = 'prestashop'"), 'orders' => (int) $app->db()->value("SELECT COUNT(*) FROM {orders} WHERE source = 'import'"),
    ], 'hay clientes y pedidos traídos que podrían avisar');
    same(0, count($transport->sent), 'ningún mensaje salió');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'), 'ninguna fila en el registro de correo');
    $types = array_column($app->db()->select("SELECT DISTINCT type FROM {jobs} WHERE type NOT LIKE 'import.%'"), 'type');
    sort($types);
    foreach ($types as $type) {
        check(strpos($type, 'search.') === 0 || strpos($type, 'semantic.') === 0, 'solo trabajos de índices, nunca de avisos: ' . $type);
    }
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type LIKE 'mail%' OR type LIKE 'erp%' OR type LIKE 'datalayer%' OR type LIKE 'webhook%'"), 'ni un trabajo de correo, erp, datalayer o webhook');
    same([], $fired, 'ningún gancho de aviso se disparó: ' . json_encode($fired));
    // Y después: todas las tareas programadas, durante días (pedir opinión, cancelar sin pagar, purgar y enviar el correo pendiente).
    $now = $app->clock()->now();
    foreach ([0, 2, 20, 40] as $days) {
        $app->clock()->set($now + $days * 86400);
        pwTasks($app);
    }
    same(0, count($transport->sent), 'tampoco después: ningún mensaje tras las tareas de 40 días');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages}'));
    same([], $fired, 'ni un gancho después: ' . json_encode($fired));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {orders} WHERE source = 'import' AND status = 'cancelled' AND legacy_id NOT IN ('4', '10')"), 'y las tareas no cancelaron ningún pedido antiguo');
});
