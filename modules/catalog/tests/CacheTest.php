<?php
/**
 * AIFEC — pruebas de la caché del catálogo: la segunda llamada no toca la base de datos, las etiquetas invalidan lo que toca
 * y lo que depende del reloj (ofertas, publicación) no se queda viejo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la segunda llamada a product() no hace ninguna consulta', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Silla', 'categories' => [aifecCategory($app, 'Sillas')], 'tags' => ['oficina']]);
    $catalog = aifecCatalog($app);
    $before = $app->db()->queries;
    $first = $catalog->product($id);
    $afterFirst = $app->db()->queries;
    check($afterFirst > $before, 'la primera lee de la base de datos');
    $second = $catalog->product($id);
    same($afterFirst, $app->db()->queries, 'la segunda sale de la caché');
    same($first, $second);
});

test('la caché se guarda en disco y otra instancia del servicio la aprovecha', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Silla']);
    aifecCatalog($app)->product($id);
    catalogNewRequest($app);
    $other = new \Aifec\Modules\Catalog\Catalog($app);
    $files = glob($app->cache()->dir() . '/data/*/*.php');
    check(is_array($files) && count($files) > 0, 'hay ficheros de caché');
    $before = $app->db()->queries;
    same('Silla', $other->product($id)['name']);
    same($before, $app->db()->queries, 'sin consultas');
});

test('tras invalidar «product:<id>» se vuelve a leer, y solo ese producto', function (): void {
    $app = catalogApp();
    $a = aifecProduct($app, ['name' => 'Antes A']);
    $b = aifecProduct($app, ['name' => 'Antes B']);
    $catalog = aifecCatalog($app);
    $catalog->product($a);
    $catalog->product($b);
    $app->db()->update('product_i18n', ['name' => 'Después A'], ['product_id' => $a]);
    $app->db()->update('product_i18n', ['name' => 'Después B'], ['product_id' => $b]);
    same('Antes A', $catalog->product($a)['name'], 'cacheado');
    $app->invalidate(['product:' . $a]);
    $before = $app->db()->queries;
    same('Antes B', $catalog->product($b)['name'], 'el otro sigue en caché');
    same($before, $app->db()->queries);
    same('Después A', $catalog->product($a)['name'], 'este se lee de nuevo');
    check($app->db()->queries > $before, 'con consultas');
});

test('invalidar «catalog» o «tax» vacía todas las fichas y tarjetas', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Antes']);
    $catalog = aifecCatalog($app);
    $catalog->product($id);
    $catalog->card($id);
    $app->db()->update('product_i18n', ['name' => 'Después'], ['product_id' => $id]);
    $app->invalidate(['catalog']);
    same('Después', $catalog->product($id)['name']);
    same('Después', $catalog->card($id)['name']);
    $app->db()->update('product_i18n', ['name' => 'Otra vez'], ['product_id' => $id]);
    $app->invalidate(['tax']);
    same('Otra vez', $catalog->product($id)['name'], 'un cambio de impuestos también la vacía');
});

test('las tarjetas se guardan aparte y tampoco hacen consultas la segunda vez', function (): void {
    $app = catalogApp();
    $ids = [];
    for ($i = 1; $i <= 6; $i++) {
        $ids[] = aifecProduct($app, ['name' => 'P' . $i]);
    }
    $catalog = aifecCatalog($app);
    $catalog->cards($ids);
    $before = $app->db()->queries;
    $cards = $catalog->cards($ids);
    same($before, $app->db()->queries);
    same(6, count($cards));
    $app->invalidate(['product:' . $ids[2]]);
    $app->db()->update('product_i18n', ['name' => 'Cambiado'], ['product_id' => $ids[2]]);
    same('Cambiado', $catalog->cards($ids)[$ids[2]]['name']);
});

test('el borrado o guardado del panel invalida el producto, o todo el catálogo si cambia algo compartido', function (): void {
    $app = catalogApp();
    $a = aifecProduct($app, ['name' => 'Antes A']);
    $b = aifecProduct($app, ['name' => 'Antes B']);
    $catalog = aifecCatalog($app);
    $catalog->product($a);
    $catalog->product($b);
    $app->db()->update('product_i18n', ['name' => 'Después A'], ['product_id' => $a]);
    $app->db()->update('product_i18n', ['name' => 'Después B'], ['product_id' => $b]);
    $app->hooks()->fire('admin.resource.saved', 'products', $a, [], null);
    same('Después A', $catalog->product($a)['name']);
    same('Antes B', $catalog->product($b)['name'], 'solo el producto guardado');
    $app->hooks()->fire('admin.resource.saved', 'brands', 1, [], null);
    same('Después B', $catalog->product($b)['name'], 'una marca cambia lo que enseñan todos');
    $app->db()->update('product_i18n', ['name' => 'Tercero'], ['product_id' => $b]);
    $app->hooks()->fire('admin.resource.deleted', 'otra_cosa', 5, []);
    same('Después B', $catalog->product($b)['name'], 'un recurso ajeno no toca nada');
});

test('una oferta que acaba no se queda en la caché: caduca a la vez que la oferta', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 2000, 'sale_price' => 1500, 'sale_to' => '2026-11-12 12:30:00']);
    $catalog = aifecCatalog($app);
    same(1500, $catalog->product($id)['price']['amount']);
    $app->clock()->advance(1799); // 12:29:59
    catalogNewRequest($app);
    same(1500, $catalog->product($id)['price']['amount'], 'aún vale');
    $app->clock()->advance(2); // 12:30:01
    catalogNewRequest($app);
    $price = $catalog->product($id)['price'];
    same(2000, $price['amount'], 'ya no');
    same(null, $price['compare']);
});

test('una oferta que empieza más tarde entra sola cuando llega su hora', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 2000, 'sale_price' => 1500, 'sale_from' => '2026-11-12 13:00:00']);
    $catalog = aifecCatalog($app);
    same(2000, $catalog->product($id)['price']['amount']);
    same(2000, $catalog->card($id)['price']['amount']);
    $app->clock()->set((int) strtotime('2026-11-12 13:00:00 UTC'));
    catalogNewRequest($app);
    same(1500, $catalog->product($id)['price']['amount']);
    same(1500, $catalog->card($id)['price']['amount']);
});

test('un producto que se publica mañana aparece al pasar la hora, sin vaciar la caché', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['published_at' => '2026-11-12 18:00:00']);
    $catalog = aifecCatalog($app);
    same(null, $catalog->product($id));
    same(null, $catalog->card($id));
    same(0, $catalog->listing([])['total']);
    $app->clock()->set((int) strtotime('2026-11-12 18:00:00 UTC'));
    catalogNewRequest($app);
    same($id, $catalog->product($id)['id']);
    same($id, $catalog->card($id)['id']);
    same(1, $catalog->listing([])['total']);
});

test('la insignia «nuevo» se retira sola cuando se cumple el plazo', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['published_at' => '2026-11-02 12:00:00']); // 10 días
    $catalog = aifecCatalog($app);
    same(['new'], $catalog->card($id)['badges']);
    $app->clock()->advance(20 * 86400 - 1); // le queda 1 s para los 30 días
    catalogNewRequest($app);
    same(['new'], $catalog->card($id)['badges']);
    $app->clock()->advance(1);
    catalogNewRequest($app);
    same([], $catalog->card($id)['badges']);
});
