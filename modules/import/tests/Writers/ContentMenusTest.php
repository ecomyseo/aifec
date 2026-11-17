<?php
/**
 * AIFEC — escritor de menús: se enlazan a lo ya traído por su pareja (categoría, página, entrada, categoría del blog, producto), lo que no case queda
 * como enlace con su dirección antigua, la ubicación nueva se crea con su nombre, un menú que el comerciante ya tenía no se pisa, repite sin cambios,
 * choca si se tocó aquí y el simulacro no escribe nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\MenusWriter;
use Aifec\Modules\Import\Writers\PagesWriter;
use Aifec\Modules\Import\Writers\PostCategoriesWriter;

require_once __DIR__ . '/WritersKit2.php';

/** Un menú neutro: el de ejemplo con cambios. */
function pwMenu(array $over = []): array
{
    return wr('menus', $over);
}

/**
 * Un sitio con la categoría «12» de la tienda, la página «7», la categoría del blog «4» y el producto «101» ya traídos.
 *
 * @return array{0:App,1:WritersContext,2:int,3:int}
 */
function pwMenuSite(): array
{
    [$app, , $ctx, $product] = pwOrderSite();
    $page = (int) (new PagesWriter($app))->write(wr('pages', ['old_paths' => []]), $ctx)['id'];
    (new PostCategoriesWriter($app))->write(wr('post_categories', ['old_paths' => []]), $ctx);
    $ctx->notes = [];

    return [$app, $ctx, $page, $product];
}

/**
 * Los elementos del menú como [tipo, referencia, dirección, nivel, etiqueta en español].
 *
 * @return array<int,array<int,mixed>>
 */
function pwItems(App $app, string $code): array
{
    $out = [];
    foreach ($app->service('cms')->menus()->editable($code) as $item) {
        $out[] = [$item['type'], $item['ref'], $item['url'], $item['level'], isset($item['labels']['es']) ? $item['labels']['es'] : null];
    }

    return $out;
}

pwTest('el menú de ejemplo se enlaza a la categoría y a la página que se trajeron, con su submenú', function (): void {
    [$app, $ctx, $page] = pwMenuSite();
    $out = (new MenusWriter($app))->write(pwMenu(), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $category = $ctx->localId('categories', '12');
    same([['category', $category, '', 0, 'Mesas'], ['url', 0, '/blog/', 1, 'Blog'], ['page', $page, '', 0, 'Privacidad']], pwItems($app, 'main'), 'categoría, su submenú y la página');
    same(['main', 'Principal'], [wrValue($app, 'SELECT code FROM {menus} WHERE id = ?', [$out['id']]), wrValue($app, 'SELECT name FROM {menus} WHERE id = ?', [$out['id']])]);

    $menu = $app->service('cms')->menu('main', 'es');
    same(['Mesas', 'Privacidad'], array_column($menu, 'label'));
    same(['Blog'], array_column($menu[0]['children'], 'label'));
    contains('/mesas', (string) $menu[0]['url'], 'y la categoría enlaza a su dirección de aquí');
    same([], $ctx->noteTexts('menus'), 'sin nada que avisar');
});

pwTest('lo que no tiene pareja queda como enlace con su dirección antigua, y sin dirección se deja fuera con una nota', function (): void {
    [$app, $ctx] = pwMenuSite();
    $items = [
        ['label' => ['es' => 'Página vieja'], 'type' => 'page', 'ref' => '999', 'url' => 'https://antigua.test/pagina-vieja'],
        ['label' => ['es' => 'Sin destino'], 'type' => 'post', 'ref' => '998'],
        ['label' => ['es' => 'Inicio'], 'type' => 'home'],
        ['label' => ['es' => 'Entradas'], 'type' => 'post_category', 'ref' => '4'],
        ['label' => ['es' => 'Mesa'], 'type' => 'product', 'ref' => '101'],
        ['label' => ['es' => 'Peligro'], 'type' => 'url', 'url' => 'javascript:alert(1)'],
    ];
    (new MenusWriter($app))->write(pwMenu(['items' => $items]), $ctx);
    $got = pwItems($app, 'main');
    same(['url', 0, 'https://antigua.test/pagina-vieja', 0, 'Página vieja'], $got[0], 'el que no casa, como enlace');
    same(['home', 0, '', 0, 'Inicio'], $got[1]);
    same(['term', $ctx->localId('post_categories', '4'), '', 0, 'Entradas'], $got[2], 'la categoría del blog');
    same(['product', $ctx->localId('products', '101'), '', 0, 'Mesa'], $got[3]);
    same(4, count($got), 'el que no tiene a dónde apuntar y el de dirección peligrosa se quedaron fuera');
    $notes = implode(' | ', $ctx->noteTexts('menus'));
    contains('kept as a link to its old address', $notes);
    contains('left out', $notes);
});

pwTest('una ubicación que el tema no conoce se crea con su nombre, y las marcas se enlazan a su dirección', function (): void {
    [$app, $ctx, , $product] = pwMenuSite();
    (new \Aifec\Modules\Import\Writers\BrandsWriter($app))->write(wr('brands', ['old_paths' => []]), $ctx);
    $record = pwMenu(['id' => 'side', 'location' => 'Barra lateral', 'name' => 'Lateral', 'items' => [['label' => ['es' => 'Nordika'], 'type' => 'brand', 'ref' => '9']]]);
    $out = (new MenusWriter($app))->write($record, $ctx);
    same(['barra-lateral', 'Lateral'], [wrValue($app, 'SELECT code FROM {menus} WHERE id = ?', [$out['id']]), wrValue($app, 'SELECT name FROM {menus} WHERE id = ?', [$out['id']])]);
    same([['url', 0, '/marca/nordika', 0, 'Nordika']], pwItems($app, 'barra-lateral'));
    check($product > 0, 'hecho');
});

pwTest('un menú que el comerciante ya tenía con elementos no se pisa; uno vacío se adopta', function (): void {
    [$app, $ctx, $page] = pwMenuSite();
    $cms = $app->service('cms')->menus();
    $cms->save('main', 'El mío', [['type' => 'url', 'url' => '/mi-pagina', 'labels' => ['es' => 'Mía'], 'level' => 0]]);
    $writer = new MenusWriter($app);
    $out = $writer->write(pwMenu(), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    contains('already exists', $out['reason']);
    same([['url', 0, '/mi-pagina', 0, 'Mía']], pwItems($app, 'main'), 'el de aquí, intacto');
    same(0, wrCount($app, 'import_map', "type = 'menus'"));

    $app->db()->delete('menu_items', ['menu_id' => (int) wrValue($app, "SELECT id FROM {menus} WHERE code = 'main'")]);
    $adopted = $writer->write(pwMenu(), $ctx);
    same(TypeWriter::UPDATED, $adopted['result'], 'vacío: se adopta');
    same(3, count(pwItems($app, 'main')));
    same(1, wrCount($app, 'menus', "code = 'main'"));
    check($page > 0, 'hecho');
});

pwTest('el menú repite sin cambios, se actualiza, el enlazado de la segunda pasada es idempotente y el simulacro no escribe', function (): void {
    [$app, $ctx] = pwMenuSite();
    $writer = new MenusWriter($app);
    $record = pwMenu();
    $id = (int) $writer->write($record, $ctx)['id'];
    $writer->link($record, $ctx);
    $counts = wrCounts($app);
    $items = pwItems($app, 'main');

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    $writer->link($record, $ctx);
    same($counts, wrCounts($app), 'ni una fila nueva');
    same($items, pwItems($app, 'main'));

    $changed = $writer->write(pwMenu(['items' => [['label' => ['es' => 'Inicio'], 'type' => 'home']]]), $ctx);
    same([TypeWriter::UPDATED, $id], [$changed['result'], $changed['id']]);
    same([['home', 0, '', 0, 'Inicio']], pwItems($app, 'main'));

    $dry = writersContext($app)->dry(true);
    $before = wrCounts($app);
    same(TypeWriter::CREATED, $writer->write(pwMenu(['id' => 'f', 'location' => 'footer']), $dry)['result']);
    same(TypeWriter::UPDATED, $writer->write($record, $dry)['result']);
    $writer->link($record, $dry);
    same($before, wrCounts($app), 'el simulacro no escribe, ni siquiera en la segunda pasada');
    same(['menus_count' => 1], $writer->checksums($ctx));
});

pwTest('un menú que se tocó aquí después de traerlo choca; overwrite_local lo pisa; la segunda pasada no lo toca', function (): void {
    [$app, $ctx] = pwMenuSite();
    $writer = new MenusWriter($app);
    $record = pwMenu();
    $writer->write($record, $ctx);
    $app->service('cms')->menus()->save('main', 'Principal', [['type' => 'url', 'url' => '/otra', 'labels' => ['es' => 'Otra'], 'level' => 0]]);

    $out = $writer->write(pwMenu(['items' => [['label' => ['es' => 'Inicio'], 'type' => 'home']]]), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    $writer->link($record, $ctx);
    same([['url', 0, '/otra', 0, 'Otra']], pwItems($app, 'main'), 'lo de aquí queda intacto, también tras la segunda pasada');

    $over = $writer->write(pwMenu(['items' => [['label' => ['es' => 'Inicio'], 'type' => 'home']]]), writersContext($app, ['overwrite_local' => true]));
    same(TypeWriter::UPDATED, $over['result']);
    same([['home', 0, '', 0, 'Inicio']], pwItems($app, 'main'));
});

pwTest('el menú de más de dos niveles se aplana al segundo, con nota', function (): void {
    [$app, $ctx] = pwMenuSite();
    $items = [['label' => ['es' => 'Uno'], 'type' => 'url', 'url' => '/uno', 'children' => [
        ['label' => ['es' => 'Dos'], 'type' => 'url', 'url' => '/dos', 'children' => [['label' => ['es' => 'Tres'], 'type' => 'url', 'url' => '/tres']]],
    ]]];
    (new MenusWriter($app))->write(pwMenu(['items' => $items]), $ctx);
    same([['url', 0, '/uno', 0, 'Uno'], ['url', 0, '/dos', 1, 'Dos'], ['url', 0, '/tres', 1, 'Tres']], pwItems($app, 'main'));
    contains('moved up to the second level', implode(' ', $ctx->noteTexts('menus')));
});
