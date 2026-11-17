<?php
/**
 * AIFEC — pruebas del listado: paginación, cada filtro, atributos, facetas con sus cuentas, cada orden, subcategorías,
 * visibilidad, agotados, búsqueda, los filtros de los plugins y el número fijo de consultas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Una tienda pequeña con todo lo que hay que filtrar. Devuelve los identificadores por clave.
 *
 *   p1 Silla Ergo       5000   marca Nórdica  Oficina   roble + rojo        stock 10  destacado  pos 3  ventas 10  publicado 01/11
 *   p2 Silla Pro        8000→6000 (oferta en marcha)  Nórdica  Sillas  pino  stock 0  pos 1  ventas 50  05/11
 *   p3 Mesa Roble      12000 (comparación 15000)  marca Zeta  Mesas  roble  stock 5  pos 2  ventas 5  20/10
 *   p4 Taburete         2000   sin marca  sin categoría  stock 0 pero se vende igual  pos 4  10/11
 *   p5 Camiseta         1500   marca Zeta  variantes: rojo 1500 (3 uds) y azul 2500 (0 uds)  pos 5  08/11
 *   c  Solo catálogo    1000   visibilidad «catalog»  pos 6  (sin fecha de publicación: sale como el más nuevo)
 *   h  Oculto / s  Solo búsqueda / d  Borrador: para la visibilidad
 *
 * @return array<string,int>
 */
function catalogShop(App $app): array
{
    $ids = [];
    $ids['sillas'] = aifecCategory($app, 'Sillas');
    $ids['oficina'] = aifecCategory($app, 'Oficina', $ids['sillas']);
    $ids['mesas'] = aifecCategory($app, 'Mesas');
    $ids['inactiva'] = aifecCategory($app, 'Inactiva', null, ['active' => 0]);
    $ids['nordica'] = aifecBrand($app, 'Nórdica');
    $ids['zeta'] = aifecBrand($app, 'Zeta');
    aifecAttribute($app, 'material', 'Material', ['roble' => 'Roble', 'pino' => 'Pino']);
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul'], ['type' => 'color', 'colors' => ['rojo' => '#ff0000']]);

    $ids['p1'] = aifecProduct($app, [
        'name' => 'Silla Ergo', 'price' => 5000, 'brand_id' => $ids['nordica'], 'categories' => [$ids['oficina']], 'stock' => 10,
        'featured' => 1, 'position' => 3, 'sales_count' => 10, 'published_at' => '2026-11-01 00:00:00', 'sku' => 'SILLA-1',
        'attributes' => ['material' => 'roble', 'color' => 'rojo'], 'tags' => ['oficina'],
    ]);
    $ids['p2'] = aifecProduct($app, [
        'name' => 'Silla Pro', 'price' => 8000, 'sale_price' => 6000, 'brand_id' => $ids['nordica'], 'categories' => [$ids['sillas']],
        'stock' => 0, 'position' => 1, 'sales_count' => 50, 'published_at' => '2026-11-05 00:00:00', 'attributes' => ['material' => 'pino'],
    ]);
    $ids['p3'] = aifecProduct($app, [
        'name' => 'Mesa Roble', 'price' => 12000, 'compare_price' => 15000, 'brand_id' => $ids['zeta'], 'categories' => [$ids['mesas']],
        'stock' => 5, 'position' => 2, 'sales_count' => 5, 'published_at' => '2026-10-20 00:00:00', 'attributes' => ['material' => 'roble'],
        'tags' => ['oficina', 'comedor'],
    ]);
    $ids['p4'] = aifecProduct($app, [
        'name' => 'Taburete', 'price' => 2000, 'stock' => 0, 'backorders' => 'yes', 'position' => 4, 'published_at' => '2026-11-10 00:00:00',
    ]);
    $ids['p5'] = aifecProduct($app, [
        'name' => 'Camiseta', 'price' => 1500, 'brand_id' => $ids['zeta'], 'position' => 5, 'published_at' => '2026-11-08 00:00:00',
        'variants' => [
            ['sku' => 'CAM-R', 'stock' => 3, 'values' => ['color' => 'rojo']],
            ['sku' => 'CAM-A', 'price' => 2500, 'stock' => 0, 'values' => ['color' => 'azul']],
        ],
    ]);
    $ids['c'] = aifecProduct($app, ['name' => 'Solo catálogo', 'price' => 1000, 'visibility' => 'catalog', 'position' => 6]);
    $ids['h'] = aifecProduct($app, ['name' => 'Oculto silla', 'visibility' => 'hidden']);
    $ids['s'] = aifecProduct($app, ['name' => 'Solo búsqueda silla', 'visibility' => 'search']);
    $ids['d'] = aifecProduct($app, ['name' => 'Borrador silla', 'status' => 'draft']);

    return $ids;
}

test('sin filtros: lo que se ve en el catálogo, por posición y con la forma del contrato', function (): void {
    $app = catalogApp();
    $ids = catalogShop($app);
    $result = aifecCatalog($app)->listing([]);
    same(['Silla Pro', 'Mesa Roble', 'Silla Ergo', 'Taburete', 'Camiseta', 'Solo catálogo'], catalogNames($result));
    same(6, $result['total']);
    same(1, $result['page']);
    same(1, $result['pages']);
    same(24, $result['per_page']);
    same('relevance', $result['sort']);
    same(['items', 'total', 'page', 'pages', 'per_page', 'sort', 'sorts', 'facets', 'price_range'], array_keys($result));
    same(aifecCatalog($app)->card($ids['p1']), $result['items'][2], 'cada elemento es la tarjeta de esa ficha');
});

test('paginación: páginas, última página, límites de per_page y valores raros', function (): void {
    $app = catalogApp();
    for ($i = 1; $i <= 7; $i++) {
        aifecProduct($app, ['name' => 'N' . $i]);
    }
    $catalog = aifecCatalog($app);
    $page1 = $catalog->listing(['per_page' => 3]);
    same(['N1', 'N2', 'N3'], catalogNames($page1));
    same(7, $page1['total']);
    same(3, $page1['pages']);
    same(['N4', 'N5', 'N6'], catalogNames($catalog->listing(['per_page' => 3, 'page' => 2])));
    same(['N7'], catalogNames($catalog->listing(['per_page' => 3, 'page' => 3])));
    $beyond = $catalog->listing(['per_page' => 3, 'page' => 99]);
    same(3, $beyond['page'], 'una página que no existe lleva a la última');
    same(['N7'], catalogNames($beyond));
    same(96, $catalog->listing(['per_page' => 200])['per_page']);
    same(24, $catalog->listing(['per_page' => 0])['per_page'], 'cero: el de por defecto');
    same(24, $catalog->listing(['per_page' => -5])['per_page']);
    same(1, $catalog->listing(['page' => 0])['page']);
    same(1, $catalog->listing(['page' => 'abc'])['page']);
    same(2, count($catalog->listing(['per_page' => 2])['items']));
});

test('catalog.per_page cambia el tamaño de página por defecto', function (): void {
    $app = catalogApp(['catalog.per_page' => 2]);
    for ($i = 1; $i <= 5; $i++) {
        aifecProduct($app, ['name' => 'N' . $i]);
    }
    $result = aifecCatalog($app)->listing([]);
    same(2, $result['per_page']);
    same(3, $result['pages']);
});

test('sin productos: página vacía, una página, rango [0, 0] y sin facetas', function (): void {
    $app = catalogApp();
    $result = aifecCatalog($app)->listing([]);
    same([], $result['items']);
    same(0, $result['total']);
    same(1, $result['pages']);
    same([0, 0], $result['price_range']);
    same([], $result['facets']);
});

test('categoría: incluye las subcategorías; una subcategoría, solo lo suyo; inactiva o inexistente, nada', function (): void {
    $app = catalogApp();
    $ids = catalogShop($app);
    $catalog = aifecCatalog($app);
    same(['Silla Pro', 'Silla Ergo'], catalogNames($catalog->listing(['category_id' => $ids['sillas']])));
    same(['Silla Ergo'], catalogNames($catalog->listing(['category_id' => $ids['oficina']])));
    same(['Mesa Roble'], catalogNames($catalog->listing(['category_id' => $ids['mesas']])));
    same(0, $catalog->listing(['category_id' => $ids['inactiva']])['total']);
    same(0, $catalog->listing(['category_id' => 99999])['total']);
});

test('categoría con subcategorías de varios niveles y una categoría desactivada a mitad de la rama', function (): void {
    $app = catalogApp();
    $root = aifecCategory($app, 'Raíz');
    $mid = aifecCategory($app, 'Medio', $root);
    $leaf = aifecCategory($app, 'Hoja', $mid);
    $off = aifecCategory($app, 'Apagada', $root, ['active' => 0]);
    aifecProduct($app, ['name' => 'En hoja', 'categories' => [$leaf]]);
    aifecProduct($app, ['name' => 'En raíz', 'categories' => [$root]]);
    aifecProduct($app, ['name' => 'En apagada', 'categories' => [$off]]);
    $catalog = aifecCatalog($app);
    same(['En hoja', 'En raíz'], catalogNames($catalog->listing(['category_id' => $root])), 'dos niveles hacia abajo; la apagada no cuenta');
    same(['En hoja'], catalogNames($catalog->listing(['category_id' => $mid])));
    same(['En hoja'], catalogNames($catalog->listing(['category_id' => $leaf])));
});

test('relevancia dentro de una categoría: la posición del producto en ella', function (): void {
    $app = catalogApp();
    $cat = aifecCategory($app, 'Cat');
    $a = aifecProduct($app, ['name' => 'A', 'categories' => [$cat]]);
    $b = aifecProduct($app, ['name' => 'B', 'categories' => [$cat]]);
    $c = aifecProduct($app, ['name' => 'C', 'categories' => [$cat]]);
    $app->db()->update('product_categories', ['position' => 3], ['product_id' => $a]);
    $app->db()->update('product_categories', ['position' => 1], ['product_id' => $b]);
    $app->db()->update('product_categories', ['position' => 2], ['product_id' => $c]);
    same(['B', 'C', 'A'], catalogNames(aifecCatalog($app)->listing(['category_id' => $cat])));
});

test('marca, etiqueta e identificadores', function (): void {
    $app = catalogApp();
    $ids = catalogShop($app);
    $catalog = aifecCatalog($app);
    same(['Silla Pro', 'Silla Ergo'], catalogNames($catalog->listing(['brand_id' => $ids['nordica']])));
    same(['Mesa Roble', 'Camiseta'], catalogNames($catalog->listing(['brand_id' => $ids['zeta']])));
    same(['Mesa Roble', 'Silla Ergo'], catalogNames($catalog->listing(['tag' => 'oficina'])));
    same(['Mesa Roble'], catalogNames($catalog->listing(['tag' => 'comedor'])));
    same([], catalogNames($catalog->listing(['tag' => 'no-existe'])));
    same(['Silla Ergo', 'Taburete'], catalogNames($catalog->listing(['ids' => [$ids['p4'], $ids['p1']]])), 'los que se piden, en el orden del listado');
    same([], catalogNames($catalog->listing(['ids' => []])), 'una lista vacía no es «todos»');
    same(['Silla Ergo'], catalogNames($catalog->listing(['ids' => [$ids['p1'], $ids['h'], $ids['d'], 424242]])), 'ni ocultos, ni borradores, ni inexistentes');
});

test('visibilidad: oculto nunca; «catalog» no sale al buscar; «search» solo al buscar', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $catalog = aifecCatalog($app);
    $browse = catalogNames($catalog->listing([]));
    check(!in_array('Oculto silla', $browse, true), 'oculto no sale en el listado');
    check(!in_array('Solo búsqueda silla', $browse, true), '«search» no sale en el listado');
    check(!in_array('Borrador silla', $browse, true), 'un borrador no sale');
    check(in_array('Solo catálogo', $browse, true), '«catalog» sí sale en el listado');
    $search = catalogNames($catalog->listing(['q' => 'silla']));
    same(['Solo búsqueda silla', 'Silla Pro', 'Silla Ergo'], $search, 'se busca «silla»: entra el de «search» (posición 0) y no el oculto ni el borrador');
    same([], catalogNames($catalog->listing(['q' => 'solo catálogo'])), '«catalog» no sale al buscar');
});

test('búsqueda sin buscador propio: nombre, referencia y código de barras, todas las palabras', function (): void {
    $app = catalogApp();
    aifecProduct($app, ['name' => 'Silla ergonómica', 'sku' => 'ABC-123', 'gtin' => '8412345678905']);
    aifecProduct($app, ['name' => 'Mesa de roble', 'sku' => 'XYZ-9']);
    aifecProduct($app, ['name' => 'Mesa de cocina', 'sku' => 'MC-1']);
    $catalog = aifecCatalog($app);
    same(['Silla ergonómica'], catalogNames($catalog->listing(['q' => 'silla'])));
    same(['Silla ergonómica'], catalogNames($catalog->listing(['q' => 'ABC-123'])), 'por referencia');
    same(['Silla ergonómica'], catalogNames($catalog->listing(['q' => '8412345678905'])), 'por código de barras');
    same(['Mesa de roble', 'Mesa de cocina'], catalogNames($catalog->listing(['q' => 'mesa'])));
    same(['Mesa de roble'], catalogNames($catalog->listing(['q' => 'mesa roble'])), 'todas las palabras, en cualquier sitio');
    same([], catalogNames($catalog->listing(['q' => 'sofá'])));
    same(['Mesa de roble'], catalogNames($catalog->listing(['q' => '  MESA   ROBLE  '])), 'sin importar mayúsculas ni espacios de más');
});

test('búsqueda: el % y el _ son letras, no comodines; comillas, tildes y eñes valen', function (): void {
    $app = catalogApp();
    aifecProduct($app, ['name' => '100% algodón']);
    aifecProduct($app, ['name' => '1000 algodón']);
    aifecProduct($app, ['name' => 'talla_m']);
    aifecProduct($app, ['name' => 'tallaXm']);
    aifecProduct($app, ['name' => 'Cañón "pro"']);
    aifecProduct($app, ['name' => "Papá's"]);
    $catalog = aifecCatalog($app);
    same(['100% algodón'], catalogNames($catalog->listing(['q' => '100%'])));
    same(['talla_m'], catalogNames($catalog->listing(['q' => 'talla_m'])));
    same(['Cañón "pro"'], catalogNames($catalog->listing(['q' => 'Cañón "pro"'])));
    same(["Papá's"], catalogNames($catalog->listing(['q' => "Papá's"])));
    same(['Cañón "pro"'], catalogNames($catalog->listing(['q' => '"pro'])));
});

test('búsqueda: en el idioma pedido, y con el principal como respaldo', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    aifecProduct($app, ['name' => 'Silla', 'i18n' => ['en' => ['name' => 'Chair']]]);
    aifecProduct($app, ['name' => 'Mesa']);
    $catalog = aifecCatalog($app);
    same(['Chair'], catalogNames($catalog->listing(['q' => 'chair', 'lang' => 'en'])));
    same(['Mesa'], catalogNames($catalog->listing(['q' => 'mesa', 'lang' => 'en'])), 'sin traducción, se busca el nombre principal');
    same([], catalogNames($catalog->listing(['q' => 'chair', 'lang' => 'es'])));
});

test('búsqueda con un servicio «search»: manda su orden y solo salen los que se ven', function (): void {
    $app = catalogApp();
    $ids = catalogShop($app);
    $order = [$ids['p3'], $ids['h'], $ids['p1'], $ids['d'], $ids['s'], $ids['p2']];
    $received = [];
    $app->bind('search', static function () use ($order, &$received): object {
        return new class($order, $received) {
            /** @var int[] */
            private $ids;
            /** @var array<string,mixed> */
            private $log;

            /**
             * @param int[]               $ids
             * @param array<string,mixed> $log
             */
            public function __construct(array $ids, array &$log)
            {
                $this->ids = $ids;
                $this->log = &$log;
            }

            /**
             * @param array<string,mixed> $options
             *
             * @return array<string,mixed>
             */
            public function query(string $q, array $options = []): array
            {
                $this->log = ['q' => $q, 'options' => $options];

                return ['ids' => $this->ids, 'total' => count($this->ids), 'corrected' => null, 'confident' => true];
            }
        };
    });
    $result = aifecCatalog($app)->listing(['q' => 'sillas']);
    same(['Mesa Roble', 'Silla Ergo', 'Solo búsqueda silla', 'Silla Pro'], catalogNames($result), 'el orden del buscador, sin el oculto ni el borrador');
    same(4, $result['total']);
    same('sillas', $received['q']);
    same(200, $received['options']['limit']);
    same('es', $received['options']['lang']);
    // Con otro orden pedido, manda ese orden y el buscador solo decide qué entra.
    $byName = catalogNames(aifecCatalog($app)->listing(['q' => 'sillas', 'sort' => 'name']));
    same(['Mesa Roble', 'Silla Ergo', 'Silla Pro', 'Solo búsqueda silla'], $byName);
    // Paginando el resultado del buscador.
    same(['Solo búsqueda silla', 'Silla Pro'], catalogNames(aifecCatalog($app)->listing(['q' => 'sillas', 'per_page' => 2, 'page' => 2])));
});

test('un servicio «search» que no encuentra nada deja el listado vacío; si falla, se busca por texto', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $app->bind('search', static function (): object {
        return new class() {
            /**
             * @return array<string,mixed>
             */
            public function query(string $q, array $options = []): array
            {
                return ['ids' => [], 'total' => 0, 'corrected' => null, 'confident' => false];
            }
        };
    });
    same(0, aifecCatalog($app)->listing(['q' => 'silla'])['total']);
    $broken = catalogApp();
    catalogShop($broken);
    $broken->bind('search', static function (): object {
        return new class() {
            /**
             * @return array<string,mixed>
             */
            public function query(string $q, array $options = []): array
            {
                throw new \RuntimeException('índice roto');
            }
        };
    });
    same(['Solo búsqueda silla', 'Silla Pro', 'Silla Ergo'], catalogNames(aifecCatalog($broken)->listing(['q' => 'silla'])));
});

test('agotados: catalog.show_out_of_stock en falso los oculta (con variantes, si ninguna tiene); bajo pedido se queda', function (): void {
    $app = catalogApp(['catalog.show_out_of_stock' => false]);
    catalogShop($app);
    aifecProduct($app, ['name' => 'Variantes agotadas', 'position' => 7, 'variants' => [['stock' => 0], ['stock' => 0]]]);
    aifecProduct($app, ['name' => 'Sin control', 'position' => 8, 'manage_stock' => 0, 'stock' => 0]);
    $names = catalogNames(aifecCatalog($app)->listing([]));
    same(['Mesa Roble', 'Silla Ergo', 'Taburete', 'Camiseta', 'Solo catálogo', 'Sin control'], $names);
    $shown = catalogApp(['catalog.show_out_of_stock' => true]);
    catalogShop($shown);
    check(in_array('Silla Pro', catalogNames(aifecCatalog($shown)->listing([])), true), 'con el ajuste encendido, sí sale');
});

test('in_stock: solo lo que se puede comprar, también con el ajuste de agotados encendido', function (): void {
    $app = catalogApp();
    catalogShop($app);
    same(['Mesa Roble', 'Silla Ergo', 'Taburete', 'Camiseta', 'Solo catálogo'], catalogNames(aifecCatalog($app)->listing(['in_stock' => true])));
});

test('destacados y ofertas (precio de oferta, comparación y variantes con comparación propia)', function (): void {
    $app = catalogApp();
    catalogShop($app);
    aifecProduct($app, ['name' => 'Variante rebajada', 'position' => 9, 'price' => 3000, 'variants' => [
        ['price' => 900, 'compare_price' => 1200, 'stock' => 1], ['price' => 3000, 'stock' => 1],
    ]]);
    aifecProduct($app, ['name' => 'Oferta caducada', 'position' => 10, 'price' => 3000, 'sale_price' => 1000, 'sale_to' => '2026-11-12 11:00:00']);
    $catalog = aifecCatalog($app);
    same(['Silla Ergo'], catalogNames($catalog->listing(['featured' => true])));
    same(['Silla Pro', 'Mesa Roble', 'Variante rebajada'], catalogNames($catalog->listing(['on_sale' => true])));
});

test('atributos: varios valores del mismo atributo = cualquiera; atributos distintos = todos; incluye las variantes', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $catalog = aifecCatalog($app);
    same(['Mesa Roble', 'Silla Ergo'], catalogNames($catalog->listing(['filters' => ['material' => ['roble']]])));
    same(['Silla Pro', 'Mesa Roble', 'Silla Ergo'], catalogNames($catalog->listing(['filters' => ['material' => ['roble', 'pino']]])), 'cualquiera de los dos');
    same(['Silla Ergo'], catalogNames($catalog->listing(['filters' => ['material' => ['roble'], 'color' => ['rojo']]])), 'los dos atributos a la vez');
    same(['Silla Ergo', 'Camiseta'], catalogNames($catalog->listing(['filters' => ['color' => ['rojo']]])), 'rojo en el producto y en una variante');
    same(['Camiseta'], catalogNames($catalog->listing(['filters' => ['color' => ['azul']]])), 'azul solo en una variante');
    same([], catalogNames($catalog->listing(['filters' => ['material' => ['pino'], 'color' => ['rojo']]])));
    same([], catalogNames($catalog->listing(['filters' => ['material' => ['no-existe']]])));
    same([], catalogNames($catalog->listing(['filters' => ['no-existe' => ['x']]])));
    same(6, $catalog->listing(['filters' => ['material' => [], 'color' => '']])['total'], 'un filtro vacío no filtra');
    same(['Silla Ergo'], catalogNames($catalog->listing(['filters' => ['color' => 'rojo', 'material' => 'roble']])), 'un valor suelto también vale');
});

test('un valor que solo tiene una variante desactivada no cuenta', function (): void {
    $app = catalogApp();
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul']);
    aifecProduct($app, ['name' => 'Gorra', 'variants' => [
        ['stock' => 1, 'values' => ['color' => 'rojo']], ['stock' => 1, 'values' => ['color' => 'azul'], 'active' => false],
    ]]);
    $catalog = aifecCatalog($app);
    same(['Gorra'], catalogNames($catalog->listing(['filters' => ['color' => ['rojo']]])));
    same([], catalogNames($catalog->listing(['filters' => ['color' => ['azul']]])));
});

test('precio: sobre el precio vigente tal como se escribe (oferta en marcha y variantes)', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $catalog = aifecCatalog($app);
    same(['Silla Pro', 'Mesa Roble', 'Silla Ergo'], catalogNames($catalog->listing(['price_min' => 5000])), 'Silla Pro cuenta por 6000, no por 8000');
    same(['Taburete', 'Camiseta', 'Solo catálogo'], catalogNames($catalog->listing(['price_max' => 2000])), 'Camiseta: una variante vale 1500');
    same(['Taburete', 'Camiseta'], catalogNames($catalog->listing(['price_min' => 2000, 'price_max' => 2600])), 'Camiseta entra por su variante de 2500');
    same(['Silla Pro'], catalogNames($catalog->listing(['price_min' => 5500, 'price_max' => 6000])), 'los extremos entran');
    same([], catalogNames($catalog->listing(['price_min' => 7000, 'price_max' => 8000])), 'el precio normal de la oferta no cuenta');
    same(6, $catalog->listing(['price_min' => 'x', 'price_max' => ''])['total'], 'valores que no son números no filtran');
    $app->db()->update('products', ['sale_to' => '2026-11-12 11:00:00'], ['id' => (int) $app->db()->value('SELECT id FROM {products} WHERE sale_price = 6000')]);
    same(['Silla Pro'], catalogNames($catalog->listing(['price_min' => 7000, 'price_max' => 8000])), 'con la oferta caducada vuelve a valer 8000');
});

test('orden: relevancia, novedades, precio, nombre y más vendidos; uno desconocido es la relevancia', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $catalog = aifecCatalog($app);
    same(['Silla Pro', 'Mesa Roble', 'Silla Ergo', 'Taburete', 'Camiseta', 'Solo catálogo'], catalogNames($catalog->listing(['sort' => 'relevance'])));
    same(['Solo catálogo', 'Taburete', 'Camiseta', 'Silla Pro', 'Silla Ergo', 'Mesa Roble'], catalogNames($catalog->listing(['sort' => 'newest'])), 'sin fecha de publicación, la de alta (hoy)');
    same(['Solo catálogo', 'Camiseta', 'Taburete', 'Silla Ergo', 'Silla Pro', 'Mesa Roble'], catalogNames($catalog->listing(['sort' => 'price_asc'])), 'el precio vigente: Silla Pro vale 6000');
    same(['Mesa Roble', 'Silla Pro', 'Silla Ergo', 'Taburete', 'Camiseta', 'Solo catálogo'], catalogNames($catalog->listing(['sort' => 'price_desc'])));
    same(['Camiseta', 'Mesa Roble', 'Silla Ergo', 'Silla Pro', 'Solo catálogo', 'Taburete'], catalogNames($catalog->listing(['sort' => 'name'])));
    same(['Silla Pro', 'Silla Ergo', 'Mesa Roble', 'Taburete', 'Camiseta', 'Solo catálogo'], catalogNames($catalog->listing(['sort' => 'best'])));
    $unknown = $catalog->listing(['sort' => 'inventado']);
    same('relevance', $unknown['sort']);
    same('Silla Pro', catalogNames($unknown)[0]);
    same('price_asc', $catalog->listing(['sort' => 'price_asc'])['sort']);
});

test('los órdenes que se ofrecen, con su texto en castellano', function (): void {
    $app = catalogApp();
    same([
        'relevance' => 'Relevancia', 'newest' => 'Novedades', 'price_asc' => 'Precio: de menor a mayor',
        'price_desc' => 'Precio: de mayor a menor', 'name' => 'Nombre', 'best' => 'Más vendidos',
    ], aifecCatalog($app)->listing([])['sorts']);
});

test('los ganchos listing.query y listing.sorts: un plugin cambia la consulta y añade órdenes', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $app->hooks()->filter('listing.query', static function (array $query): array {
        $query['in_stock'] = true;

        return $query;
    });
    $app->hooks()->filter('listing.sorts', static function (array $sorts): array {
        $sorts['custom'] = 'Mi orden';

        return $sorts;
    });
    $result = aifecCatalog($app)->listing([]);
    check(!in_array('Silla Pro', catalogNames($result), true), 'el plugin ha forzado «con existencias»');
    same('Mi orden', $result['sorts']['custom']);
    same('custom', aifecCatalog($app)->listing(['sort' => 'custom'])['sort'], 'un orden añadido por un plugin es válido');
});

test('facetas: marcas y atributos con su número de productos, y los valores sin productos no salen', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $facets = aifecCatalog($app)->listing([])['facets'];
    same(['brand', 'material', 'color'], array_map(static function (array $f): string {
        return $f['code'];
    }, $facets));
    same(['code' => 'brand', 'name' => 'Marca', 'type' => 'brand', 'values' => [
        ['code' => (string) $app->db()->value('SELECT id FROM {brands} WHERE name = ?', ['Nórdica']), 'label' => 'Nórdica', 'count' => 2, 'selected' => false],
        ['code' => (string) $app->db()->value('SELECT id FROM {brands} WHERE name = ?', ['Zeta']), 'label' => 'Zeta', 'count' => 2, 'selected' => false],
    ]], $facets[0]);
    same(['code' => 'material', 'name' => 'Material', 'type' => 'select', 'values' => [
        ['code' => 'roble', 'label' => 'Roble', 'count' => 2, 'selected' => false],
        ['code' => 'pino', 'label' => 'Pino', 'count' => 1, 'selected' => false],
    ]], $facets[1]);
    same('color', $facets[2]['code']);
    same('color', $facets[2]['type']);
    same([
        ['code' => 'rojo', 'label' => 'Rojo', 'count' => 2, 'selected' => false, 'color' => '#ff0000'],
        ['code' => 'azul', 'label' => 'Azul', 'count' => 1, 'selected' => false, 'color' => null],
    ], $facets[2]['values'], 'el rojo cuenta el producto y la variante');
});

test('facetas con filtros: cada una cuenta con todos los demás filtros menos el suyo, y marca los elegidos', function (): void {
    $app = catalogApp();
    $ids = catalogShop($app);
    $result = aifecCatalog($app)->listing(['filters' => ['material' => ['roble']]]);
    same(['Mesa Roble', 'Silla Ergo'], catalogNames($result));
    $facets = [];
    foreach ($result['facets'] as $facet) {
        $facets[$facet['code']] = $facet;
    }
    // Marcas con el material elegido: Nórdica solo Silla Ergo, Zeta solo Mesa Roble.
    same([1, 1], array_map(static function (array $v): int {
        return $v['count'];
    }, $facets['brand']['values']));
    // Material (sin su propio filtro): roble 2, pino 1, con roble marcado.
    same([['code' => 'roble', 'label' => 'Roble', 'count' => 2, 'selected' => true], ['code' => 'pino', 'label' => 'Pino', 'count' => 1, 'selected' => false]], $facets['material']['values']);
    // Color con el material elegido: solo Silla Ergo (rojo).
    same([['code' => 'rojo', 'label' => 'Rojo', 'count' => 1, 'selected' => false, 'color' => '#ff0000']], $facets['color']['values']);

    $both = aifecCatalog($app)->listing(['brand_id' => $ids['nordica'], 'filters' => ['color' => ['rojo']]]);
    same(['Silla Ergo'], catalogNames($both));
    $facets = [];
    foreach ($both['facets'] as $facet) {
        $facets[$facet['code']] = $facet;
    }
    // Marcas (sin el filtro de marca, con rojo): Nórdica 1 (Silla Ergo), Zeta 1 (Camiseta); Nórdica marcada.
    same([['count' => 1, 'selected' => true], ['count' => 1, 'selected' => false]], array_map(static function (array $v): array {
        return ['count' => $v['count'], 'selected' => $v['selected']];
    }, $facets['brand']['values']));
    // Color (sin el suyo, con marca Nórdica): Silla Ergo rojo 1; azul no sale; rojo marcado.
    same([['code' => 'rojo', 'label' => 'Rojo', 'count' => 1, 'selected' => true, 'color' => '#ff0000']], $facets['color']['values']);
    // Material (marca Nórdica y rojo): roble 1.
    same([['code' => 'roble', 'label' => 'Roble', 'count' => 1, 'selected' => false]], $facets['material']['values']);
});

test('una faceta elegida sin productos sigue saliendo marcada y con cuenta cero', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $result = aifecCatalog($app)->listing(['filters' => ['material' => ['pino'], 'color' => ['rojo']]]);
    same(0, $result['total']);
    $color = null;
    foreach ($result['facets'] as $facet) {
        if ($facet['code'] === 'color') {
            $color = $facet;
        }
    }
    check($color !== null, 'la faceta de color sale');
    same(['code' => 'rojo', 'label' => 'Rojo', 'count' => 0, 'selected' => true, 'color' => '#ff0000'], $color['values'][0]);
});

test('un atributo no filtrable no sale como faceta, y uno de texto tampoco', function (): void {
    $app = catalogApp();
    aifecAttribute($app, 'origen', 'Origen', ['es' => 'España'], ['filterable' => 0]);
    aifecAttribute($app, 'nota', 'Nota', [], ['type' => 'text']);
    aifecProduct($app, ['attributes' => ['origen' => 'es', 'nota' => 'hola']]);
    same([], aifecCatalog($app)->listing([])['facets']);
});

test('price_range: el mínimo y el máximo vigentes del conjunto, sin contar el propio filtro de precio', function (): void {
    $app = catalogApp();
    catalogShop($app);
    $catalog = aifecCatalog($app);
    // Mínimo: Solo catálogo 1000. Máximo: Mesa Roble 12000 (Camiseta llega a 2500; Silla Pro vale 6000).
    same([1000, 12000], $catalog->listing([])['price_range']);
    $nordica = (int) $app->db()->value('SELECT id FROM {brands} WHERE name = ?', ['Nórdica']);
    $zeta = (int) $app->db()->value('SELECT id FROM {brands} WHERE name = ?', ['Zeta']);
    same([5000, 6000], $catalog->listing(['brand_id' => $nordica])['price_range'], 'Silla Ergo 5000 y Silla Pro 6000');
    same([1500, 12000], $catalog->listing(['brand_id' => $zeta])['price_range'], 'Camiseta desde 1500 y Mesa Roble 12000');
    same([1500, 12000], $catalog->listing(['brand_id' => $zeta, 'price_max' => 100])['price_range'], 'aunque el filtro de precio no deje ninguno');
    same(0, $catalog->listing(['brand_id' => $zeta, 'price_max' => 100])['total']);
    same([1500, 2500], $catalog->listing(['filters' => ['color' => ['azul']]])['price_range'], 'Camiseta: de 1500 a 2500');
});

test('los elementos del listado cuestan un número fijo de consultas, con 5 o con 40 productos', function (): void {
    $queries = [];
    foreach ([5, 40] as $n) {
        $app = catalogApp([], ['catalog', 'media']);
        $brand = aifecBrand($app, 'Marca');
        $cat = aifecCategory($app, 'Cat');
        aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo']);
        for ($i = 1; $i <= $n; $i++) {
            aifecProduct($app, [
                'name' => 'Producto ' . $i, 'price' => 1000 + $i, 'brand_id' => $brand, 'categories' => [$cat], 'images' => 2,
                'attributes' => ['color' => 'rojo'], 'rating_sum' => 4, 'rating_count' => 1,
                'variants' => $i % 2 === 0 ? [['stock' => 2, 'values' => ['color' => 'rojo']]] : [],
            ]);
        }
        $catalog = aifecCatalog($app);
        $before = $app->db()->queries;
        $result = $catalog->listing(['per_page' => 96]);
        $queries[$n] = $app->db()->queries - $before;
        same($n, count($result['items']));
        $second = $app->db()->queries;
        $catalog->listing(['per_page' => 96]);
        check($app->db()->queries - $second < $queries[$n], 'la segunda vez, con las tarjetas en caché, cuesta menos');
    }
    same($queries[5], $queries[40], 'las mismas consultas con 5 que con 40 productos');
    check($queries[5] <= 30, 'y no son muchas: ' . $queries[5]);
});

test('cards() de muchos productos también cuesta un número fijo de consultas', function (): void {
    $queries = [];
    foreach ([3, 30] as $n) {
        $app = catalogApp([], ['catalog', 'media']);
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = aifecProduct($app, ['name' => 'P' . $i, 'images' => 1, 'variants' => [['stock' => 1]]]);
        }
        $before = $app->db()->queries;
        same($n, count(aifecCatalog($app)->cards($ids)));
        $queries[$n] = $app->db()->queries - $before;
    }
    same($queries[3], $queries[30]);
});

test('el listado en inglés: tarjetas con la traducción y el respaldo al principal', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    aifecProduct($app, ['name' => 'Silla', 'i18n' => ['en' => ['name' => 'Chair']]]);
    aifecProduct($app, ['name' => 'Mesa', 'position' => 1]);
    $result = aifecCatalog($app)->listing(['lang' => 'en']);
    same(['Chair', 'Mesa'], catalogNames($result));
    same('/en/producto/chair', $result['items'][0]['url']);
    same('Relevance', $result['sorts']['relevance']);
});
