<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las órdenes sobre el catálogo: el precio de un producto, los precios de un conjunto, las existencias, activar o desactivar y
 * crear un producto en borrador. Cada una en castellano y en inglés, con vista previa exacta, aplicar, comprobar en la base de datos
 * y deshacer.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Un cliente del panel con la tienda de ejemplo puesta.
 *
 * @return array{0:\Aifec\Kernel\App,1:TestClient,2:array<string,int>}
 */
function copilotCatalogSetup(array $settings = []): array
{
    $app = copilotApp($settings === [] ? [] : ['settings' => $settings]);
    $ids = copilotShop($app);

    return [$app, copilotAdmin($app), $ids];
}

test('precio de un producto, fijado: castellano por título y inglés por referencia; enseña antes y después y no toca nada', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $es = copilotAsk($web, 'Pon el precio de la silla roja a 64,90');
    same('catalog.price', $es['action']);
    same(['product' => 'silla roja', 'price' => 6490], $es['args']);
    same([['label' => 'Silla roja', 'from' => money(5990), 'to' => money(6490)]], $es['preview']['changes']);
    same('proposed', $es['status']);
    $en = copilotAsk($web, 'Set the price of SILLA-A to 74.90');
    same(['product' => 'silla-a', 'price' => 7490], $en['args'], 'la referencia se busca sin distinguir mayúsculas');
    same([['label' => 'Silla azul', 'from' => money(6990), 'to' => money(7490)]], $en['preview']['changes']);
    same(5990, copilotProduct($app, $ids['roja'], 'price'), 'entender no cambia nada');
});

test('precio de un producto, sumando o restando: un importe o un porcentaje, en los dos idiomas', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $plus = copilotAsk($web, 'Sube 5 euros el precio de la silla roja');
    same(500, $plus['args']['delta']);
    same(money(6490), $plus['preview']['changes'][0]['to']);
    $minus = copilotAsk($web, 'Lower the price of SILLA-R by 3 euros');
    same(-300, $minus['args']['delta']);
    same(money(5690), $minus['preview']['changes'][0]['to']);
    $down = copilotAsk($web, 'Baja un 10 % el precio de la silla roja');
    same(-1000, $down['args']['percent']);
    same(money(5391), $down['preview']['changes'][0]['to'], '59,90 menos el 10 % = 53,91');
    $up = copilotAsk($web, 'Raise the price of SILLA-A by 5 %');
    same(500, $up['args']['percent']);
    same(money(7340), $up['preview']['changes'][0]['to'], '69,90 más el 5 % = 73,395 → 73,40 (medio céntimo hacia arriba)');
});

test('aplicar un precio lo escribe en la base de datos, apunta la auditoría y deshacer lo devuelve', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();
    $row = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(6490, copilotProduct($app, $ids['roja'], 'price'));
    same('applied', copilotRow($app, (int) $row['id'])['status']);
    same(5990, (int) copilotRow($app, (int) $row['id'])['undo']['items'][0]['before']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'copilot.apply'"));
    contains(money(6490), $web->get('/admin/copilot/' . $row['id'])->body);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');
    same(5990, copilotProduct($app, $ids['roja'], 'price'));
    same('undone', copilotRow($app, (int) $row['id'])['status']);
});

test('un precio que quedaría en cero o en negativo es un error y no se aplica', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    foreach (['Pon el precio de SILLA-R a 0', 'Baja 100 euros el precio de SILLA-R', 'Reduce the price of SILLA-R by 100 %'] as $text) {
        $row = copilotAsk($web, $text);
        check($row['preview']['errors'] !== [], $text . ' → error');
        contains('tiene que seguir por encima de cero', $row['preview']['errors'][0]);
        same(false, copilotService($app)->present($row)['can_apply']);
        copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
        same(5990, copilotProduct($app, $ids['roja'], 'price'), $text . ' → el precio sigue igual');
    }
});

test('un producto con variantes con precio propio: el porcentaje las mueve a todas y deshacer las devuelve; un precio fijo avisa de que las conserva', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $row = copilotAsk($web, 'Sube un 10 % el precio de Sofá Nube');
    same(3, $row['preview']['count'], 'el producto y sus dos variantes');
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(54890, copilotProduct($app, $ids['sofa'], 'price'));
    same([54890, 65890], array_map('intval', $app->db()->column('SELECT price FROM {variants} WHERE product_id = ? ORDER BY position, id', [$ids['sofa']])));
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');
    same([49900, 59900], array_map('intval', $app->db()->column('SELECT price FROM {variants} WHERE product_id = ? ORDER BY position, id', [$ids['sofa']])));

    $fixed = copilotAsk($web, 'Pon el precio de Sofá Nube a 450');
    contains('conservan', $fixed['preview']['warnings'][0]);
});

test('un producto con precio de oferta avisa de que la oferta no cambia', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();
    $app->db()->update('products', ['sale_price' => 4990], ['id' => $ids['roja']]);

    $row = copilotAsk($web, 'Pon el precio de SILLA-R a 64,90');
    contains('precio de oferta', $row['preview']['warnings'][0]);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(4990, copilotProduct($app, $ids['roja'], 'sale_price'), 'la oferta queda como estaba');
});

test('un nombre que encaja con varios productos no se adivina: ofrece los dos y elegir uno deja la propuesta lista', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $row = copilotAsk($web, 'Pon el precio de la silla a 50');
    same('incomplete', $row['status']);
    same(2, count($row['preview']['choices']));
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    contains('Silla roja (SILLA-R)', $page);
    contains('Silla azul (SILLA-A)', $page);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/complete', ['p' => ['product' => '#' . $ids['azul']]]);
    $row = copilotRow($app, (int) $row['id']);
    same('proposed', $row['status']);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(5000, copilotProduct($app, $ids['azul'], 'price'));
    same(5990, copilotProduct($app, $ids['roja'], 'price'), 'la otra no se toca');
});

test('si falta un dato la propuesta queda incompleta, la pantalla lo pide con un campo y rellenarlo la deja lista', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $row = copilotAsk($web, 'Cambia el precio de la silla roja');
    same('incomplete', $row['status']);
    same(['price', 'delta', 'percent'], $row['preview']['missing']);
    $page = $web->get('/admin/copilot/' . $row['id'])->body;
    contains('name="p[price]"', $page);
    contains('Faltan datos', $page);

    $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/complete', ['p' => ['product' => 'silla roja', 'price' => '70,00']]);
    same(303, $r->status);
    $row = copilotRow($app, (int) $row['id']);
    same('proposed', $row['status']);
    same(7000, $row['args']['price']);
});

test('precios de un conjunto por categoría y por marca, en castellano y en inglés: vista previa por producto, aplicar y deshacer', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $cat = copilotAsk($web, 'Sube un 5 % los precios de la categoría Sillas');
    same('catalog.bulk_price', $cat['action']);
    same(['category' => 'Sillas', 'percent' => 500], $cat['args']);
    same(2, $cat['preview']['count']);
    same([['label' => 'Silla roja', 'from' => money(5990), 'to' => money(6290)], ['label' => 'Silla azul', 'from' => money(6990), 'to' => money(7340)]], $cat['preview']['changes']);
    $brand = copilotAsk($web, 'Lower the prices of the brand Acme by 10 %');
    same('catalog.bulk_price', $brand['action']);
    same(['brand' => 'Acme', 'percent' => -1000], $brand['args']);
    same(1, $brand['preview']['count']);

    copilotPost($web, '/admin/copilot/' . $cat['id'] . '/apply');
    same([6290, 7340, 12000], [copilotProduct($app, $ids['roja'], 'price'), copilotProduct($app, $ids['azul'], 'price'), copilotProduct($app, $ids['mesa'], 'price')]);
    copilotPost($web, '/admin/copilot/' . $cat['id'] . '/undo');
    same([5990, 6990, 12000], [copilotProduct($app, $ids['roja'], 'price'), copilotProduct($app, $ids['azul'], 'price'), copilotProduct($app, $ids['mesa'], 'price')]);
});

test('precios de un conjunto por búsqueda: «todos los precios de mesa» toca solo lo que lleva esa palabra', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $row = copilotAsk($web, 'Sube un 10 % todos los precios de mesa');
    same('catalog.bulk_price', $row['action']);
    same('mesa', $row['args']['query']);
    same(1, $row['preview']['count']);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(13200, copilotProduct($app, $ids['mesa'], 'price'));
    same(5990, copilotProduct($app, $ids['roja'], 'price'));
});

test('existencias: fijar y sumar o restar, en castellano y en inglés, con aplicar, el movimiento de stock y deshacer', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $set = copilotAsk($web, 'Pon el stock de la silla roja a 20');
    same('catalog.stock', $set['action']);
    same(20, $set['args']['quantity']);
    same([['label' => 'Silla roja (SILLA-R)', 'from' => '10', 'to' => '20']], $set['preview']['changes']);
    $add = copilotAsk($web, 'Add 5 units to SILLA-A');
    same(5, $add['args']['delta']);
    same('9', $add['preview']['changes'][0]['to']);
    $sub = copilotAsk($web, 'Quita 2 unidades de la silla azul');
    same(-2, $sub['args']['delta']);

    copilotPost($web, '/admin/copilot/' . $set['id'] . '/apply');
    copilotPost($web, '/admin/copilot/' . $add['id'] . '/apply');
    same(20, (int) copilotProduct($app, $ids['roja'], 'stock'));
    same(9, (int) copilotProduct($app, $ids['azul'], 'stock'));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {stock_moves} WHERE product_id = ? AND reason = 'copilot'", [$ids['roja']]), 'el movimiento queda apuntado');
    copilotPost($web, '/admin/copilot/' . $set['id'] . '/undo');
    same(10, (int) copilotProduct($app, $ids['roja'], 'stock'));
});

test('existencias de un producto con variantes: pide la variante, o la reconoce por su referencia', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $row = copilotAsk($web, 'Pon el stock de Sofá Nube a 5');
    same('incomplete', $row['status']);
    same(2, count($row['preview']['choices']));
    contains('NUBE-L', $row['preview']['choices'][1]['label']);
    $variantL = (int) $app->db()->value("SELECT id FROM {variants} WHERE sku = 'NUBE-L'");
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/complete', ['p' => ['product' => '#v' . $variantL]]);
    $row = copilotRow($app, (int) $row['id']);
    same('proposed', $row['status']);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(5, (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$variantL]));

    $direct = copilotAsk($web, 'Add 2 units to NUBE-S');
    same('proposed', $direct['status']);
    copilotPost($web, '/admin/copilot/' . $direct['id'] . '/apply');
    same(5, (int) $app->db()->value("SELECT stock FROM {variants} WHERE sku = 'NUBE-S'"));
});

test('existencias que quedarían en negativo, o de un producto sin control de existencias, son un error', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $neg = copilotAsk($web, 'Quita 20 unidades de SILLA-A');
    contains('no pueden ser negativas', $neg['preview']['errors'][0]);
    $app->db()->update('products', ['manage_stock' => 0], ['id' => $ids['mesa']]);
    $free = copilotAsk($web, 'Pon el stock de MESA-1 a 8');
    contains('no se controlan', $free['preview']['errors'][0]);
    foreach ([$neg, $free] as $row) {
        copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    }
    same(4, (int) copilotProduct($app, $ids['azul'], 'stock'));
    same(3, (int) copilotProduct($app, $ids['mesa'], 'stock'));
});

test('activar y desactivar: un producto por su nombre y un conjunto por categoría; aplicar, comprobar y deshacer; nada se borra', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();
    $products = (int) $app->db()->value('SELECT COUNT(*) FROM {products}');

    $one = copilotAsk($web, 'Desactiva la silla roja');
    same('catalog.visibility', $one['action']);
    same(false, $one['args']['active']);
    same([['label' => 'Silla roja', 'from' => 'Publicado', 'to' => 'Borrador']], $one['preview']['changes']);
    $set = copilotAsk($web, 'Deactivate the products of the category Sillas');
    same(2, $set['preview']['count']);

    copilotPost($web, '/admin/copilot/' . $set['id'] . '/apply');
    same(['draft', 'draft'], [copilotProduct($app, $ids['roja'], 'status'), copilotProduct($app, $ids['azul'], 'status')]);
    same('published', copilotProduct($app, $ids['mesa'], 'status'));
    same($products, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'), 'no se borra ningún producto');

    $on = copilotAsk($web, 'Activa la silla roja');
    same(true, $on['args']['active']);
    copilotPost($web, '/admin/copilot/' . $on['id'] . '/apply');
    same('published', copilotProduct($app, $ids['roja'], 'status'));
    copilotPost($web, '/admin/copilot/' . $on['id'] . '/undo');
    same('draft', copilotProduct($app, $ids['roja'], 'status'));
    copilotPost($web, '/admin/copilot/' . $set['id'] . '/undo');
    same(['published', 'published'], [copilotProduct($app, $ids['roja'], 'status'), copilotProduct($app, $ids['azul'], 'status')]);

    $none = copilotAsk($web, 'Activa la mesa de roble');
    contains('ya están todos publicados', $none['preview']['errors'][0]);
});

test('crear un producto en borrador, en castellano y en inglés: queda sin publicar, con su título, su precio y su dirección; deshacer lo archiva', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $es = copilotAsk($web, 'Crea un producto borrador llamado Silla Verde a 45,50');
    same('catalog.create', $es['action']);
    same(['name' => 'Silla Verde', 'price' => 4550], $es['args']);
    same(3, count($es['preview']['changes']));
    $en = copilotAsk($web, 'Create a draft product named Green Lamp at 25.00');
    same(['name' => 'Green Lamp', 'price' => 2500], $en['args']);

    copilotPost($web, '/admin/copilot/' . $es['id'] . '/apply');
    $id = (int) $app->db()->value("SELECT product_id FROM {product_i18n} WHERE name = 'Silla Verde'");
    check($id > 0, 'el producto existe con su título');
    same(['draft', 4550], [copilotProduct($app, $id, 'status'), (int) copilotProduct($app, $id, 'price')]);
    check($app->slugs()->path('product', $id, $app->defaultLang()) === 'producto/silla-verde', 'tiene su dirección amigable');
    copilotPost($web, '/admin/copilot/' . $es['id'] . '/undo');
    same('archived', copilotProduct($app, $id, 'status'), 'deshacer archiva, no borra');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {products} WHERE id = ?', [$id]));
});

test('crear un producto a cero es un error; sin precio queda incompleta hasta que se escribe', function (): void {
    list($app, $web, $ids) = copilotCatalogSetup();

    $zero = copilotAsk($web, 'Create a draft product called Free Thing at 0');
    contains('mayor que cero', $zero['preview']['errors'][0]);
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {products}');
    copilotPost($web, '/admin/copilot/' . $zero['id'] . '/apply');
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'));

    $missing = copilotAsk($web, 'Crea un producto llamado Banco');
    same('incomplete', $missing['status']);
    same(['price'], $missing['preview']['missing']);
    copilotPost($web, '/admin/copilot/' . $missing['id'] . '/complete', ['p' => ['price' => '99']]);
    same('proposed', copilotRow($app, (int) $missing['id'])['status']);
});
