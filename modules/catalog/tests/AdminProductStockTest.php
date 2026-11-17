<?php
/**
 * AIFEC — pruebas de la edición rápida de existencias y precio, de las piezas del inicio del panel y del buscador de órdenes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminProductKitTest.php';

test('la edición rápida guarda solo lo que cambia, apunta movimientos y no pierde una venta hecha mientras tanto', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $a = aifecProduct($app, ['name' => 'Silla', 'price' => 1000, 'stock' => 10]);
    $b = aifecProduct($app, ['name' => 'Mesa', 'price' => 2500, 'stock' => 4]);
    $c = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'variants' => [
        ['label' => 'Rojo / S', 'stock' => 5, 'values' => ['color' => 'rojo', 'talla' => 's']],
        ['label' => 'Rojo / M', 'stock' => 2, 'values' => ['color' => 'rojo', 'talla' => 'm']],
    ]]);
    $variants = array_map('intval', $app->db()->column('SELECT id FROM {variants} WHERE product_id = ? ORDER BY position', [$c]));
    $catalog = aifecCatalog($app);
    same(1000, $catalog->product($a)['price']['amount']);

    $screen = $web->get('/admin/catalog/stock');
    same(200, $screen->status);
    foreach (['Silla', 'Mesa', 'Camiseta', 'name="p[' . $a . '][stock]"', 'name="p[' . $a . '][price]"', 'name="v[' . $variants[0] . '][stock]"', 'name="v[' . $variants[1] . '][price]"'] as $text) {
        contains($text, $screen->body);
    }
    notContains('name="p[' . $c . '][stock]"', $screen->body, 'las existencias de un producto con variantes están en cada variante');

    // Mientras la persona tiene la pantalla abierta se vende una unidad y media.
    $catalog->adjustStock($a, 0, -3, 'sale', 'W-1');
    same(7, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$a]));

    $r = prodPost($web, '/admin/catalog/stock', ['next' => '/admin/catalog/stock?q=s', 'p' => [
        $a => ['price' => '12,00', 'price0' => '10,00', 'stock' => '15', 'stock0' => '10'],
        $b => ['price' => '25,00', 'price0' => '25,00', 'stock' => '4', 'stock0' => '4'],
    ], 'v' => [
        $variants[0] => ['price' => '30,00', 'price0' => '', 'stock' => '9', 'stock0' => '5'],
        $variants[1] => ['price' => '', 'price0' => '', 'stock' => '2', 'stock0' => '2'],
    ]]);
    same(302, $r->status);
    contains('/admin/catalog/stock?q=s', $r->headers['Location'], 'vuelve a la misma página');
    contains('4 cambios guardados', $web->follow()->body);

    same('1200', (string) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$a]));
    same(12, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$a]), 'se suma lo que se cambió (+5) a lo que había (7): la venta no se pierde');
    $moves = $app->db()->select("SELECT delta, reason FROM {stock_moves} WHERE product_id = ? AND reason = 'manual'", [$a]);
    same(1, count($moves));
    same('5', (string) $moves[0]['delta']);
    same('2500', (string) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$b]), 'lo que no cambió, no se toca');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {stock_moves} WHERE product_id = ?', [$b]));
    same('3000', (string) $app->db()->value('SELECT price FROM {variants} WHERE id = ?', [$variants[0]]));
    same(null, $app->db()->value('SELECT price FROM {variants} WHERE id = ?', [$variants[1]]));
    same(9, (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$variants[0]]));
    same(1, prodScalar($app, "SELECT COUNT(*) FROM {stock_moves} WHERE variant_id = ? AND reason = 'manual'", [$variants[0]]));
    same(1200, $catalog->product($a)['price']['amount'], 'la caché del producto se renovó');
    same(1, prodScalar($app, "SELECT COUNT(*) FROM {audit} WHERE action = 'quick_edit' AND entity_id = ?", [(string) $a]), 'queda en el registro de actividad');

    // Un importe o unas existencias que no valen no se guardan y se dicen; lo demás sí.
    $web->get('/admin/catalog/stock');
    $r = prodPost($web, '/admin/catalog/stock', ['p' => [
        $a => ['price' => 'doce', 'price0' => '12,00', 'stock' => '12', 'stock0' => '12'],
        $b => ['price' => '26,00', 'price0' => '25,00', 'stock' => '-3', 'stock0' => '4'],
    ]]);
    same(302, $r->status);
    $body = $web->follow()->body;
    contains($app->i18n()->t('Enter an amount, for example 12,50'), $body);
    contains($app->i18n()->t('The stock has to be a whole number, zero or more.'), $body);
    same('1200', (string) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$a]));
    same(4, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$b]));
    same('2600', (string) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$b]), 'el precio válido de la fila sí se guardó');

    // Sin cambios, lo dice.
    $web->get('/admin/catalog/stock');
    prodPost($web, '/admin/catalog/stock', ['p' => [$a => ['price' => '12,00', 'price0' => '12,00', 'stock' => '12', 'stock0' => '12']]]);
    contains('No había cambiado nada', $web->follow()->body);
});

test('la edición rápida filtra por pocas existencias, busca, pagina y vuelve a la página en la que estaba', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    aifecProduct($app, ['name' => 'Sobrante', 'sku' => 'SOB-1', 'stock' => 50]);
    aifecProduct($app, ['name' => 'Escasa', 'sku' => 'ESC-1', 'stock' => 2]);
    aifecProduct($app, ['name' => 'Con aviso alto', 'sku' => 'AVI-1', 'stock' => 8, 'stock_alert' => 10]);
    aifecProduct($app, ['name' => 'Sin control', 'sku' => 'SIN-1', 'stock' => 0, 'manage_stock' => 0]);

    $body = $web->get('/admin/catalog/stock?low=1')->body;
    contains('Escasa', $body);
    contains('Con aviso alto', $body, 'cuenta el aviso propio del producto');
    notContains('Sobrante', $body);
    notContains('Sin control', $body, 'sin controlar las existencias no hay «pocas»');
    $body = $web->get('/admin/catalog/stock?q=SOB')->body;
    contains('Sobrante', $body);
    notContains('Escasa', $body);
    contains('Sin resultados', $web->get('/admin/catalog/stock?q=nadaquecoincida')->body);

    for ($i = 1; $i <= 33; $i++) {
        aifecProduct($app, ['name' => sprintf('Paginado %02d', $i)]);
    }
    $one = $web->get('/admin/catalog/stock?q=Paginado')->body;
    contains('Paginado 01', $one);
    contains('Paginado 30', $one);
    notContains('Paginado 31', $one, 'son 30 por página');
    contains('Página 1 de 2', $one);
    $two = $web->get('/admin/catalog/stock?q=Paginado&p=2')->body;
    contains('Paginado 31', $two);
    contains('Paginado 33', $two);
    notContains('Paginado 01', $two);
    contains('name="next" value="' . e('/admin/catalog/stock?q=Paginado&p=2') . '"', $two, 'el guardado vuelve a esta página');
});

test('la edición rápida pide permiso para verse y para guardar', function (): void {
    $app = prodApp();
    $admin = adminClient($app);
    $id = aifecProduct($app, ['name' => 'Silla', 'price' => 1000, 'stock' => 10]);

    $support = adminClient($app, 'support');
    $token = aifecToken($support);
    same(403, $support->get('/admin/catalog/stock')->status);
    same(403, $support->post('/admin/catalog/stock', ['_token' => $token, 'p' => [$id => ['price' => '1,00', 'price0' => '10,00', 'stock' => '1', 'stock0' => '10']]])->status);

    $viewer = prodViewer($app);
    $token = aifecToken($viewer);
    $r = $viewer->get('/admin/catalog/stock');
    same(200, $r->status);
    contains('Silla', $r->body);
    notContains('name="p[', $r->body, 'sin campos para cambiar');
    same(403, $viewer->post('/admin/catalog/stock', ['_token' => $token, 'p' => [$id => ['price' => '1,00', 'price0' => '10,00', 'stock' => '1', 'stock0' => '10']]])->status);
    same('1000', (string) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$id]), 'no se tocó');
    contains('/admin/catalog/stock', $admin->get('/admin')->body, 'sale en el menú');
});

test('el inicio enseña los productos con pocas existencias y avisa de los publicados sin imagen o sin precio', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $img = static function () use ($app): int {
        return aifecMedia($app);
    };
    aifecProduct($app, ['name' => 'Sana', 'price' => 1000, 'stock' => 50, 'images' => [$img()]]);
    aifecProduct($app, ['name' => 'Escasa', 'price' => 1000, 'stock' => 2, 'images' => [$img()]]);
    $noImage = aifecProduct($app, ['name' => 'Sin foto', 'price' => 1000, 'stock' => 50]);
    $noPrice = aifecProduct($app, ['name' => 'Sin precio', 'price' => 0, 'stock' => 50, 'images' => [$img()]]);
    aifecProduct($app, ['name' => 'Borrador sin foto', 'status' => 'draft', 'stock' => 50]);
    aifecProduct($app, ['name' => 'Con variantes', 'price' => 0, 'stock' => 50, 'images' => [$img()], 'variants' => [
        ['label' => 'Grande', 'price' => 1500, 'stock' => 1, 'values' => ['talla' => 'l']],
        ['label' => 'Pequeña', 'price' => 1200, 'stock' => 30, 'values' => ['talla' => 's']],
    ]]);

    $home = $web->get('/admin')->body;
    contains('Pocas existencias', $home);
    contains('Escasa', $home);
    contains('Con variantes', $home, 'una variante con pocas existencias también sale');
    contains('Grande', $home);
    notContains('Pequeña', $home);
    notContains('>Sana<', $home);
    contains('2 productos publicados no tienen imagen o no tienen precio', $home);
    $link = [];
    preg_match('#href="([^"]*issues[^"]*)"#', $home, $link);
    check(isset($link[1]), 'el aviso trae su enlace');
    $list = $web->get(html_entity_decode($link[1]))->body;
    contains('Sin foto', $list);
    contains('Sin precio', $list);
    notContains('Con variantes', $list, 'el precio de una variante activa cuenta');
    notContains('Borrador sin foto', $list);
    notContains('>Sana<', $list);

    // Con lo corregido, el aviso desaparece.
    $app->db()->update('products', ['price' => 500, 'main_media_id' => aifecMedia($app)], ['id' => $noPrice]);
    $app->db()->update('products', ['main_media_id' => aifecMedia($app)], ['id' => $noImage]);
    $home = $web->get('/admin')->body;
    notContains('no tienen imagen o no tienen precio', $home);
    notContains('no tiene imagen o no tiene precio', $home);

    // Quien no ve el catálogo no ve ninguna de las dos piezas.
    $support = adminClient($app, 'support');
    $home = $support->get('/admin')->body;
    notContains('Pocas existencias', $home);
    notContains('Escasa', $home);
});

test('el buscador de órdenes encuentra productos por nombre o referencia, sin tratar el % como comodín', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $chair = aifecProduct($app, ['name' => 'Silla de roble', 'sku' => 'SIL-9']);
    aifecProduct($app, ['name' => 'Mesa', 'sku' => 'MES-1']);
    aifecProduct($app, ['name' => 'Oferta 50% & más', 'sku' => 'OFE-5']);

    $items = $web->get('/admin/palette.json?q=roble');
    same(200, $items->status);
    $found = json_decode($items->body, true)['items'];
    $labels = array_map(static function (array $item): string {
        return $item['label'];
    }, $found);
    contains('Silla de roble · SIL-9', implode('|', $labels));
    $hit = array_values(array_filter($found, static function (array $item): bool {
        return strpos($item['label'], 'Silla') === 0;
    }));
    same('Productos', $hit[0]['group']);
    same('/admin/r/products/' . $chair, $hit[0]['url']);

    $byRef = json_decode($web->get('/admin/palette.json?q=mes-1')->body, true)['items'];
    contains('Mesa', implode('|', array_map(static function (array $item): string {
        return $item['label'];
    }, $byRef)), 'por referencia');

    $percent = json_decode($web->get('/admin/palette.json?q=%25')->body, true)['items'];
    $names = implode('|', array_map(static function (array $item): string {
        return $item['label'];
    }, $percent));
    contains('Oferta 50% & más', $names, 'el % buscado es un % de verdad');
    notContains('Silla', $names);
    notContains('Mesa', $names);

    $support = adminClient($app, 'support');
    $none = json_decode($support->get('/admin/palette.json?q=roble')->body, true)['items'];
    same([], array_values(array_filter($none, static function (array $item): bool {
        return strpos($item['label'], 'Silla') === 0;
    })), 'sin permiso del catálogo no salen productos');
});
