<?php
/**
 * AIFEC — pruebas del selector de categorías de la ficha de producto: la búsqueda, el árbol por ramas, el HTML de la ficha
 * (etiquetas, respaldo sin JavaScript, sin campos repetidos), el guardado, crear una categoría sin salir y el peso de los ficheros.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Form;
use Aifec\Kernel\App;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Admin\CategoryPicker;

require_once __DIR__ . '/AdminProductKitTest.php';

/**
 * Un pequeño árbol: Hogar > Cocina > Sartenes, Hogar > Salón; Café (raíz); Muebles > Sillas.
 *
 * @return array<string,int>
 */
function cpShop(App $app): array
{
    $home = aifecCategory($app, 'Hogar');
    $kitchen = aifecCategory($app, 'Cocina', $home);

    return [
        'home' => $home, 'kitchen' => $kitchen, 'pans' => aifecCategory($app, 'Sartenes', $kitchen), 'lounge' => aifecCategory($app, 'Salón', $home),
        'cafe' => aifecCategory($app, 'Café'), 'furniture' => $furniture = aifecCategory($app, 'Muebles'), 'chairs' => aifecCategory($app, 'Sillas', $furniture),
    ];
}

/**
 * @return array<string,mixed>
 */
function cpJson(Response $response): array
{
    $data = json_decode((string) $response->body, true);

    return is_array($data) ? $data : [];
}

/** El HTML de una ficha sin lo que va dentro de <noscript> (lo que ve quien tiene JavaScript). */
function cpWithScript(string $html): string
{
    return (string) preg_replace('#<noscript>.*?</noscript>#s', '', $html);
}

function cpNoscript(string $html): string
{
    return preg_match('#<noscript>(.*?)</noscript>#s', $html, $m) === 1 ? $m[1] : '';
}

/**
 * Las etiquetas de la ficha: id => ['path' => …, 'main' => bool].
 *
 * @return array<int,array{path:string,main:bool}>
 */
function cpChips(string $html): array
{
    preg_match_all('#<li class="af-cp-chip" data-cp-id="(\d+)">(.*?)</li>#s', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $chip) {
        preg_match('#data-cp-name>(.*?)</span>#s', $chip[2], $name);
        $out[(int) $chip[1]] = ['path' => html_entity_decode($name[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'main' => strpos($chip[2], 'data-cp-main checked') !== false];
    }

    return $out;
}

/** Un rol que solo puede entrar al panel, sin catálogo. */
function cpNoCatalog(App $app): TestClient
{
    $app->db()->insert('roles', ['code' => 'nocat', 'name' => 'No catalog', 'caps' => json_encode(['dashboard.view']), 'system' => 0]);

    return adminClient($app, 'nocat');
}

/**
 * Crea muchas categorías de golpe (grupos > secciones > tipos) para medir con miles.
 *
 * @return int cuántas
 */
function cpMany(App $app, int $groups, int $sections, int $types): int
{
    $db = $app->db();
    $lang = $app->defaultLang();
    $now = $app->clock()->sql();
    $n = 0;
    $db->transaction(static function () use ($db, $groups, $sections, $types, $lang, $now, &$n): void {
        for ($g = 1; $g <= $groups; $g++) {
            $a = $db->insert('categories', ['parent_id' => null, 'path' => '', 'depth' => 0, 'position' => $g, 'active' => 1, 'in_menu' => 1, 'product_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $db->insert('category_i18n', ['category_id' => $a, 'lang' => $lang, 'name' => 'Grupo ' . $g]);
            $n++;
            for ($s = 1; $s <= $sections; $s++) {
                $b = $db->insert('categories', ['parent_id' => $a, 'path' => '', 'depth' => 1, 'position' => $s, 'active' => 1, 'in_menu' => 1, 'product_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $db->insert('category_i18n', ['category_id' => $b, 'lang' => $lang, 'name' => 'Sección ' . $g . '-' . $s]);
                $n++;
                for ($t = 1; $t <= $types; $t++) {
                    $c = $db->insert('categories', ['parent_id' => $b, 'path' => '', 'depth' => 2, 'position' => $t, 'active' => 1, 'in_menu' => 1, 'product_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
                    $db->insert('category_i18n', ['category_id' => $c, 'lang' => $lang, 'name' => 'Tipo ' . $g . '-' . $s . '-' . $t]);
                    $n++;
                }
            }
        }
    });

    return $n;
}

/**
 * Crea una categoría por la dirección del selector, con la ficha de una página del panel recién abierta.
 *
 * @param array<string,string> $post
 */
function cpCreate(TestClient $web, array $post): Response
{
    $web->get('/admin/r/products/new');

    return prodPost($web, '/admin/catalog/categories/quick', $post);
}

/** Si la variable AIFEC_MEASURE_FILE está puesta, apunta ahí una medida (para el informe); si no, no hace nada. */
function cpMeasure(string $line): void
{
    $file = getenv('AIFEC_MEASURE_FILE');
    if (is_string($file) && $file !== '') {
        file_put_contents($file, $line . "\n", FILE_APPEND);
    }
}

// ---------------------------------------------------------------------- la ruta de búsqueda

test('la búsqueda: sin sesión redirige al acceso y sin permiso de catálogo da 403', function (): void {
    $app = prodApp();
    cpShop($app);
    $guest = new TestClient($app);
    $redirect = $guest->get('/admin/catalog/categories/pick?q=cafe');
    same(302, $redirect->status, 'un navegador sin sesión va al acceso');
    contains('/admin/login', (string) $redirect->headers['Location']);
    same(401, $guest->get('/admin/catalog/categories/pick?q=cafe', ['x-requested-with' => 'fetch'])->status, 'y el guion recibe un 401 en JSON, como en las demás búsquedas del panel');
    same(403, cpNoCatalog($app)->get('/admin/catalog/categories/pick?q=cafe')->status);
    same(200, prodViewer($app)->get('/admin/catalog/categories/pick?q=cafe')->status, 'quien solo ve el catálogo sí puede buscar');
});

test('la búsqueda: «cafe» encuentra «Café» sin tildes ni mayúsculas y la respuesta es JSON con cabeceras anti-caché', function (): void {
    $app = prodApp();
    $ids = cpShop($app);
    $web = adminClient($app);
    foreach (['cafe', 'CAFE', 'Café', 'cAfÉ'] as $q) {
        $r = $web->get('/admin/catalog/categories/pick?q=' . rawurlencode($q));
        same(200, $r->status);
        $items = cpJson($r)['items'];
        same(1, count($items), 'una coincidencia para «' . $q . '»');
        same($ids['cafe'], $items[0]['id']);
        same('Café', $items[0]['name']);
    }
    $r = $web->get('/admin/catalog/categories/pick?q=cafe');
    contains('no-store', (string) $r->headers['Cache-Control']);
    same('no-cache', $r->headers['Pragma']);
    same('0', $r->headers['Expires']);
    contains('json', (string) $r->headers['Content-Type']);
});

test('la búsqueda: lo escrito sin tilde encuentra lo que la lleva y al revés', function (): void {
    $app = prodApp();
    $ids = cpShop($app);
    $web = adminClient($app);
    same($ids['lounge'], cpJson($web->get('/admin/catalog/categories/pick?q=salon'))['items'][0]['id'], '«salon» encuentra «Salón»');
    same($ids['lounge'], cpJson($web->get('/admin/catalog/categories/pick?q=' . rawurlencode('SALÓN')))['items'][0]['id']);
    same($ids['lounge'], cpJson($web->get('/admin/catalog/categories/pick?q=' . rawurlencode('alón')))['items'][0]['id'], 'también por un trozo del nombre');
});

test('la búsqueda: cada resultado trae su ruta completa', function (): void {
    $app = prodApp();
    $ids = cpShop($app);
    $web = adminClient($app);
    $items = cpJson($web->get('/admin/catalog/categories/pick?q=sarten'))['items'];
    same(1, count($items));
    same('Hogar › Cocina › Sartenes', $items[0]['path']);
    same($ids['pans'], $items[0]['id']);
    $root = cpJson($web->get('/admin/catalog/categories/pick?q=muebles'))['items'];
    same('Muebles', $root[0]['path'], 'una raíz es su propio nombre');
});

test('la búsqueda: como mucho diez coincidencias y las que empiezan por lo escrito van primero', function (): void {
    $app = prodApp();
    aifecCategory($app, 'Cubertería de taza');
    for ($i = 1; $i <= 15; $i++) {
        aifecCategory($app, 'Taza ' . $i);
    }
    $web = adminClient($app);
    $items = cpJson($web->get('/admin/catalog/categories/pick?q=taza'))['items'];
    same(10, count($items));
    same('Taza 1', $items[0]['name'], 'las que empiezan por «taza» primero');
    notContains('Cubertería de taza', implode('|', array_column($items, 'name')), 'la que lo lleva por dentro queda detrás de las diez primeras');
    $some = cpJson($web->get('/admin/catalog/categories/pick?q=cuberteria'))['items'];
    same('Cubertería de taza', $some[0]['name']);
});

test('la búsqueda: con menos de dos letras o sin coincidencias, lista vacía', function (): void {
    $app = prodApp();
    cpShop($app);
    $web = adminClient($app);
    same([], cpJson($web->get('/admin/catalog/categories/pick?q=c'))['items']);
    same([], cpJson($web->get('/admin/catalog/categories/pick?q='))['items']);
    same([], cpJson($web->get('/admin/catalog/categories/pick'))['items']);
    same([], cpJson($web->get('/admin/catalog/categories/pick?q=zzzz'))['items']);
});

test('la búsqueda: el nombre llega tal cual en el JSON (lo escapa quien lo pinta) y con «&» no se rompe', function (): void {
    $app = prodApp();
    $id = aifecCategory($app, '<script>alert(1)</script> & Co');
    $web = adminClient($app);
    $items = cpJson($web->get('/admin/catalog/categories/pick?q=' . rawurlencode('& co')))['items'];
    same(1, count($items));
    same($id, $items[0]['id']);
    same('<script>alert(1)</script> & Co', $items[0]['name']);
});

test('«ids» devuelve la ruta de esas categorías y se ignora lo que no es un número', function (): void {
    $app = prodApp();
    $ids = cpShop($app);
    $web = adminClient($app);
    $items = cpJson($web->get('/admin/catalog/categories/pick?ids=' . $ids['pans'] . ',' . $ids['cafe'] . ',abc,99999'))['items'];
    $byId = [];
    foreach ($items as $item) {
        $byId[$item['id']] = $item['path'];
    }
    same([$ids['pans'] => 'Hogar › Cocina › Sartenes', $ids['cafe'] => 'Café'], $byId);
});

// ---------------------------------------------------------------------- el árbol por ramas

test('el árbol: la raíz trae sus ramas y si tienen hijas; cada rama se pide al desplegarla', function (): void {
    $app = prodApp();
    $ids = cpShop($app);
    $web = adminClient($app);
    $roots = cpJson($web->get('/admin/catalog/categories/pick?parent=0'))['nodes'];
    same(['Hogar', 'Café', 'Muebles'], array_column($roots, 'n'), 'las raíces, en su orden');
    $flags = [];
    foreach ($roots as $node) {
        $flags[$node['n']] = $node['c'];
        same(0, $node['p']);
    }
    same(['Hogar' => 1, 'Café' => 0, 'Muebles' => 1], $flags);
    $home = cpJson($web->get('/admin/catalog/categories/pick?parent=' . $ids['home']))['nodes'];
    same(['Cocina', 'Salón'], array_column($home, 'n'));
    same([1, 0], array_column($home, 'c'));
    same([$ids['home'], $ids['home']], array_column($home, 'p'));
    same(['Sartenes'], array_column(cpJson($web->get('/admin/catalog/categories/pick?parent=' . $ids['kitchen']))['nodes'], 'n'));
    same([], cpJson($web->get('/admin/catalog/categories/pick?parent=' . $ids['pans']))['nodes'], 'una hoja no tiene ramas');
});

test('el árbol: el filtro deja las coincidencias y sus madres, y quita el resto', function (): void {
    $app = prodApp();
    $ids = cpShop($app);
    $web = adminClient($app);
    $nodes = cpJson($web->get('/admin/catalog/categories/pick?tree=sarten'))['nodes'];
    same(['Hogar', 'Cocina', 'Sartenes'], array_column($nodes, 'n'), 'la coincidencia y su cadena de madres, en orden de árbol');
    same([0, $ids['home'], $ids['kitchen']], array_column($nodes, 'p'));
    $two = cpJson($web->get('/admin/catalog/categories/pick?tree=' . rawurlencode('SILLA')))['nodes'];
    same(['Muebles', 'Sillas'], array_column($two, 'n'));
    same([], cpJson($web->get('/admin/catalog/categories/pick?tree=zzz'))['nodes']);
});

test('el árbol: sin filtro llega entero y «all» da todas las rutas', function (): void {
    $app = prodApp();
    cpShop($app);
    $web = adminClient($app);
    $all = cpJson($web->get('/admin/catalog/categories/pick?tree='))['nodes'];
    same(['Hogar', 'Cocina', 'Sartenes', 'Salón', 'Café', 'Muebles', 'Sillas'], array_column($all, 'n'));
    $paths = array_column(cpJson($web->get('/admin/catalog/categories/pick?all=1'))['items'], 'path');
    same(['Hogar', 'Hogar › Cocina', 'Hogar › Cocina › Sartenes', 'Hogar › Salón', 'Café', 'Muebles', 'Muebles › Sillas'], $paths);
});

test('el árbol: con una madre que no existe la categoría cuelga de la raíz y no se pierde', function (): void {
    $app = prodApp();
    $orphan = aifecCategory($app, 'Huérfana');
    $app->db()->update('categories', ['parent_id' => 99999], ['id' => $orphan]);
    $roots = cpJson(adminClient($app)->get('/admin/catalog/categories/pick?parent=0'))['nodes'];
    check(in_array('Huérfana', array_column($roots, 'n'), true), 'sale en la raíz');
});

// ---------------------------------------------------------------------- el HTML de la ficha

test('la ficha enseña las categorías como etiquetas y marca la principal', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans'], (string) $c['cafe'], (string) $c['chairs']], 'main_category' => (string) $c['cafe']]);
    $body = $web->get('/admin/r/products/' . $id)->body;
    $chips = cpChips($body);
    same([$c['pans'], $c['cafe'], $c['chairs']], array_keys($chips), 'una etiqueta por categoría, en su orden');
    same('Hogar › Cocina › Sartenes', $chips[$c['pans']]['path']);
    same('Muebles › Sillas', $chips[$c['chairs']]['path']);
    same([false, true, false], array_values(array_map(static function (array $chip): bool { return $chip['main']; }, $chips)), 'solo la principal va marcada');
    contains('aria-label="Quitar Hogar › Cocina › Sartenes"', $body, 'el botón de quitar tiene nombre accesible');
    contains('aria-label="Buscar una categoría"', $body);
    contains('role="combobox"', $body);
    contains('aria-autocomplete="list"', $body);
    contains('role="listbox"', $body);
});

test('si la principal no consta, la primera etiqueta lo es', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans'], (string) $c['cafe']], 'main_category' => '']);
    $chips = cpChips($web->get('/admin/r/products/' . $id)->body);
    same(true, $chips[$c['pans']]['main']);
    same(false, $chips[$c['cafe']]['main']);
});

test('un producto nuevo no lleva etiquetas y dice que no tiene categoría', function (): void {
    $app = prodApp();
    cpShop($app);
    $body = adminClient($app)->get('/admin/r/products/new')->body;
    same([], cpChips($body));
    check(preg_match('#<p class="af-cp-none af-muted" data-cp-note>Este producto todavía no tiene categoría\.</p>#', $body) === 1, 'el aviso está y no va escondido');
});

test('la ficha no lleva ningún campo categories[] fuera del respaldo, y el respaldo lleva cada categoría una vez', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans'], (string) $c['cafe']], 'main_category' => (string) $c['pans']]);
    $body = $web->get('/admin/r/products/' . $id)->body;
    same(0, substr_count(cpWithScript($body), 'name="f[categories][]"'), 'con JavaScript los campos los crea el guion, no el servidor');
    $fallback = cpNoscript($body);
    same(7, substr_count($fallback, 'name="f[categories][]"'), 'sin JavaScript, una casilla por categoría (siete) y ninguna repetida');
    preg_match_all('#name="f\[categories\]\[\]" value="(\d+)"#', $fallback, $m);
    same($m[1], array_values(array_unique($m[1])));
    same(2, substr_count($fallback, ' checked'), 'las del producto, marcadas');
    same(1, substr_count($body, 'name="f[main_category]"'), 'el desplegable de la principal está una vez');
    same(0, substr_count($body, 'name="f[main_category][]"'));
});

test('con 300 categorías la ficha no pinta 300 casillas a la vista: las del respaldo son solo las del producto', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $first = aifecCategory($app, 'Primera');
    cpMany($app, 10, 5, 5);   // 10 + 50 + 250 = 310 más la primera
    $id = prodMake($web, 'Con muchas', ['categories' => [(string) $first], 'main_category' => (string) $first]);
    $body = $web->get('/admin/r/products/' . $id)->body;
    same(0, substr_count(cpWithScript($body), 'type="checkbox" name="f[categories][]"'));
    $fallback = cpNoscript($body);
    check(substr_count($fallback, 'name="f[categories][]"') === 1, 'con tantas, el respaldo lleva solo la del producto');
    contains('activa JavaScript', $fallback, 'y dice cómo añadir más');
    check(strlen($body) < 90000, 'la página no crece con el número de categorías (' . strlen($body) . ' bytes)');
    $options = substr_count(cpWithScript($body), '<option value="');
    check($options < 80, 'tampoco hay 300 opciones a la vista (' . $options . ')');
});

test('el desplegable de la principal solo lleva las categorías del producto', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans'], (string) $c['cafe']], 'main_category' => (string) $c['cafe']]);
    $body = $web->get('/admin/r/products/' . $id)->body;
    preg_match('#<select[^>]*name="f\[main_category\]"[^>]*>(.*?)</select>#s', $body, $m);
    preg_match_all('#<option value="(\d*)"( selected)?#', $m[1], $o, PREG_SET_ORDER);
    same(['', (string) $c['pans'], (string) $c['cafe']], array_column($o, 1), 'la opción vacía y las dos del producto');
    $selected = [];
    foreach ($o as $row) {
        if (isset($row[2])) {
            $selected[] = $row[1];
        }
    }
    same([(string) $c['cafe']], $selected, 'con la principal elegida');
});

test('la ficha carga el guion y la hoja una vez, sin guion escrito dentro ni gestores en la etiqueta', function (): void {
    $app = prodApp();
    cpShop($app);
    $body = adminClient($app)->get('/admin/r/products/new')->body;
    same(1, substr_count($body, 'category-picker.js'));
    same(1, substr_count($body, 'category-picker.css'));
    $block = (string) substr($body, (int) strpos($body, '<div class="af-cp"'), (int) strpos($body, '</noscript>', (int) strpos($body, '<div class="af-cp"')) - (int) strpos($body, '<div class="af-cp"'));
    check(strlen($block) > 500, 'el bloque del selector se encuentra');
    notContains('<script', $block, 'ningún <script> escrito dentro del selector');
    check(preg_match('/\son(click|change|input|keydown|submit)=/i', $block) === 0, 'ningún onclick en el selector');
    contains('data-url="/admin/catalog/categories/pick"', $body);
    contains('data-new="/admin/catalog/categories/quick"', $body, 'quien puede editar ve crear');
    contains('data-t-remove="Quitar {name}"', $body, 'los textos llegan traducidos');
    contains('data-t-create="Crear «{name}» en"', $body);
});

test('quien solo ve el catálogo no recibe la dirección de crear categorías', function (): void {
    $app = prodApp();
    $c = cpShop($app);
    $id = prodMake(adminClient($app), 'Sartén', ['categories' => [(string) $c['cafe']]]);
    $viewer = prodViewer($app);
    $r = $viewer->get('/admin/r/products/' . $id);
    if ($r->status === 200) {
        notContains('data-new=', $r->body);
    } else {
        same(403, $r->status);
    }
});

test('los nombres con «<script>» y con «&» salen escapados en las etiquetas, el respaldo y el desplegable', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $evil = aifecCategory($app, '<script>alert(1)</script> & Co');
    $id = prodMake($web, 'Producto', ['categories' => [(string) $evil], 'main_category' => (string) $evil]);
    $body = $web->get('/admin/r/products/' . $id)->body;
    notContains('<script>alert(1)', $body, 'ni una etiqueta de guion con ese nombre');
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $body);
    same('<script>alert(1)</script> & Co', cpChips($body)[$evil]['path'], 'y descodificado vuelve a ser el nombre');
    contains('aria-label="Quitar &lt;script&gt;alert(1)&lt;/script&gt; &amp; Co"', $body);
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', cpNoscript($body));
    notContains('<script>alert(1)', cpNoscript($body));
});

test('un envío con error vuelve a pintar las etiquetas y la principal que se mandaron', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $r = prodSave($web, prodForm(['name' => ['es' => '', 'en' => ''], 'categories' => [(string) $c['cafe'], (string) $c['chairs']], 'main_category' => (string) $c['chairs']]));
    same(422, $r->status);
    $chips = cpChips($r->body);
    same([$c['cafe'], $c['chairs']], array_keys($chips));
    same(true, $chips[$c['chairs']]['main']);
    same(false, $chips[$c['cafe']]['main']);
});

test('los campos que recibe el servidor se siguen llamando categories y main_category', function (): void {
    $app = prodApp();
    $fields = \Aifec\Modules\Catalog\Admin\ProductForm::definition($app, null)['fields'];
    check(isset($fields['categories']) && $fields['categories']['type'] === 'checks', 'categories es una lista');
    check(isset($fields['main_category']) && $fields['main_category']['type'] === 'select', 'main_category es un valor');
    same('pdcats', $fields['categories']['tab']);
    same(false, $fields['categories']['store']);
});

// ---------------------------------------------------------------------- guardar

test('guardar: añadir, quitar y cambiar la principal', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans']], 'main_category' => (string) $c['pans']]);
    $saved = static function () use ($app, $id): array {
        $out = [];
        foreach ($app->db()->select('SELECT category_id, is_main FROM {product_categories} WHERE product_id = ? ORDER BY position', [$id]) as $row) {
            $out[(int) $row['category_id']] = (int) $row['is_main'];
        }

        return $out;
    };
    same([$c['pans'] => 1], $saved());
    prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => [(string) $c['pans'], (string) $c['cafe']], 'main_category' => (string) $c['pans']]), $id);
    same([$c['pans'] => 1, $c['cafe'] => 0], $saved(), 'añadir una');
    prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => [(string) $c['pans'], (string) $c['cafe']], 'main_category' => (string) $c['cafe']]), $id);
    same([$c['pans'] => 0, $c['cafe'] => 1], $saved(), 'cambiar la principal');
    prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => [(string) $c['pans']], 'main_category' => (string) $c['pans']]), $id);
    same([$c['pans'] => 1], $saved(), 'quitar una');
});

test('guardar: al quitar la principal pasa a serlo la primera que queda', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans'], (string) $c['cafe'], (string) $c['chairs']], 'main_category' => (string) $c['pans']]);
    // Se quita la principal y el envío sigue diciendo que lo es (la etiqueta ya no está): gana la primera que queda.
    prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => [(string) $c['cafe'], (string) $c['chairs']], 'main_category' => (string) $c['pans']]), $id);
    same((string) $c['cafe'], (string) $app->db()->value('SELECT category_id FROM {product_categories} WHERE product_id = ? AND is_main = 1', [$id]));
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_categories} WHERE product_id = ? AND is_main = 1', [$id]), 'y solo una');
    // Sin ningún valor de principal, lo mismo.
    prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => [(string) $c['chairs'], (string) $c['cafe']], 'main_category' => '']), $id);
    same((string) $c['chairs'], (string) $app->db()->value('SELECT category_id FROM {product_categories} WHERE product_id = ? AND is_main = 1', [$id]));
});

test('guardar: dejar el producto sin ninguna categoría', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans']], 'main_category' => (string) $c['pans']]);
    $r = prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => [], 'main_category' => '']), $id);
    same(302, $r->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {product_categories} WHERE product_id = ?', [$id]));
    $body = $web->get('/admin/r/products/' . $id)->body;
    same([], cpChips($body));
    contains('Este producto todavía no tiene categoría.', $body, 'y la ficha lo dice');
});

test('guardar: un identificador que no existe se rechaza y no se guarda nada', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans']], 'main_category' => (string) $c['pans']]);
    $r = prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => [(string) $c['cafe'], '99999'], 'main_category' => (string) $c['cafe']]), $id);
    same(422, $r->status);
    contains('Elige una de las opciones', $r->body);
    same([$c['pans']], array_map('intval', $app->db()->column('SELECT category_id FROM {product_categories} WHERE product_id = ?', [$id])), 'sigue con lo que tenía');
    $new = prodSave($web, prodForm(['categories' => ['abc']]));
    same(422, $new->status);
});

test('guardar: la misma categoría repetida en el envío se guarda una vez', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans'], (string) $c['pans'], (string) $c['cafe']], 'main_category' => (string) $c['pans']]);
    same(2, prodScalar($app, 'SELECT COUNT(*) FROM {product_categories} WHERE product_id = ?', [$id]));
});

// ---------------------------------------------------------------------- crear una categoría sin salir

test('crear: con permiso la crea con su dirección amigable y la devuelve con su ruta', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $r = cpCreate($web, ['name' => 'Ollas', 'parent' => (string) $c['kitchen']]);
    same(200, $r->status);
    $data = cpJson($r);
    same(true, $data['ok']);
    same('Hogar › Cocina › Ollas', $data['path']);
    $row = $app->db()->row('SELECT * FROM {categories} WHERE id = ?', [$data['id']]);
    same((string) $c['kitchen'], (string) $row['parent_id']);
    same(2, (int) $row['depth']);
    same($c['home'] . '/' . $c['kitchen'] . '/' . $data['id'], $row['path']);
    same('Ollas', $app->db()->value('SELECT name FROM {category_i18n} WHERE category_id = ? AND lang = ?', [$data['id'], 'es']));
    same('categoria/hogar/cocina/ollas', $app->slugs()->path('category', (int) $data['id'], 'es'), 'la dirección lleva la de sus madres, como en el formulario de categorías');
    check(prodScalar($app, 'SELECT COUNT(*) FROM {audit} WHERE entity = ? AND entity_id = ?', ['categories', (string) $data['id']]) >= 1, 'queda en el registro de actividad');
    contains('Ollas', cpJson($web->get('/admin/catalog/categories/pick?q=ollas'))['items'][0]['path'], 'y el buscador ya la encuentra');
});

test('crear: en la raíz (madre 0) y con una posición detrás de sus hermanas', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    cpShop($app);
    $web->get('/admin/r/products/new');
    $a = cpJson(cpCreate($web, ['name' => 'Jardín', 'parent' => '0']));
    $b = cpJson(cpCreate($web, ['name' => 'Garaje', 'parent' => '']));
    same(true, $a['ok']);
    same(true, $b['ok']);
    same('Jardín', $a['path']);
    $pa = prodScalar($app, 'SELECT position FROM {categories} WHERE id = ?', [$a['id']]);
    $pb = prodScalar($app, 'SELECT position FROM {categories} WHERE id = ?', [$b['id']]);
    check($pb > $pa, 'la segunda va detrás');
    check(prodScalar($app, 'SELECT COUNT(*) FROM {categories} WHERE parent_id IS NULL AND id IN (?, ?)', [$a['id'], $b['id']]) === 2, 'las dos son de la raíz');
});

test('crear: sin catalog.edit da 403 y no crea nada', function (): void {
    $app = prodApp();
    cpShop($app);
    $before = prodScalar($app, 'SELECT COUNT(*) FROM {categories}');
    $viewer = prodViewer($app);
    $viewer->get('/admin');
    $r = prodPost($viewer, '/admin/catalog/categories/quick', ['name' => 'Intrusa', 'parent' => '0']);
    same(403, $r->status);
    same($before, prodScalar($app, 'SELECT COUNT(*) FROM {categories}'));
    $guest = new TestClient($app);
    check(in_array($guest->post('/admin/catalog/categories/quick', ['name' => 'X'])->status, [302, 401, 403, 419], true), 'sin sesión tampoco');
});

test('crear: sin la ficha de seguridad el servidor no la acepta', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    cpShop($app);
    $before = prodScalar($app, 'SELECT COUNT(*) FROM {categories}');
    $r = $web->post('/admin/catalog/categories/quick', ['name' => 'Sin ficha', 'parent' => '0']);
    check($r->status >= 400 || $r->isRedirect(), 'rechazada (' . $r->status . ')');
    same($before, prodScalar($app, 'SELECT COUNT(*) FROM {categories}'));
});

test('crear: un nombre vacío da un error claro', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    cpShop($app);
    $web->get('/admin/r/products/new');
    foreach (['', '   '] as $name) {
        $r = cpCreate($web, ['name' => $name, 'parent' => '0']);
        same(422, $r->status);
        same(false, cpJson($r)['ok']);
        same('Escribe un nombre para la categoría.', cpJson($r)['message']);
    }
});

test('crear: repetido bajo la misma madre da error (aunque cambien mayúsculas o tildes) y bajo otra madre vale', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $web->get('/admin/r/products/new');
    $before = prodScalar($app, 'SELECT COUNT(*) FROM {categories}');
    foreach (['Sartenes', 'sartenes', 'SARTENES'] as $name) {
        $r = cpCreate($web, ['name' => $name, 'parent' => (string) $c['kitchen']]);
        same(422, $r->status, $name);
        same('Ya hay una categoría con ese nombre en ese sitio.', cpJson($r)['message']);
    }
    $top = cpCreate($web, ['name' => 'CAFE', 'parent' => '0']);
    same(422, $top->status, 'también en la raíz, sin tildes');
    same($before, prodScalar($app, 'SELECT COUNT(*) FROM {categories}'));
    $other = cpCreate($web, ['name' => 'Sartenes', 'parent' => (string) $c['lounge']]);
    same(200, $other->status, 'el mismo nombre bajo otra madre sí');
});

test('crear: una madre que no existe da error y no crea nada', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    cpShop($app);
    $web->get('/admin/r/products/new');
    $before = prodScalar($app, 'SELECT COUNT(*) FROM {categories}');
    $r = cpCreate($web, ['name' => 'Huérfana', 'parent' => '99999']);
    same(422, $r->status);
    same('Elige una de las opciones', cpJson($r)['message']);
    same($before, prodScalar($app, 'SELECT COUNT(*) FROM {categories}'));
});

test('crear: un nombre con «<script>» y con «&» se guarda tal cual y sale escapado en la ficha', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    cpShop($app);
    $web->get('/admin/r/products/new');
    $data = cpJson(cpCreate($web, ['name' => '<b>Tazas</b> & Platos', 'parent' => '0']));
    same(true, $data['ok']);
    same('<b>Tazas</b> & Platos', $app->db()->value('SELECT name FROM {category_i18n} WHERE category_id = ?', [$data['id']]));
    $id = prodMake($web, 'Con la nueva', ['categories' => [(string) $data['id']], 'main_category' => (string) $data['id']]);
    $body = $web->get('/admin/r/products/' . $id)->body;
    notContains('<b>Tazas</b>', $body);
    contains('&lt;b&gt;Tazas&lt;/b&gt; &amp; Platos', $body);
});

test('crear: la categoría nueva ya se puede guardar en el producto', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $web->get('/admin/r/products/new');
    $data = cpJson(cpCreate($web, ['name' => 'Cuchillos', 'parent' => (string) $c['kitchen']]));
    $id = prodMake($web, 'Cuchillo', ['categories' => [(string) $data['id']], 'main_category' => (string) $data['id']]);
    same([(int) $data['id']], array_map('intval', $app->db()->column('SELECT category_id FROM {product_categories} WHERE product_id = ?', [$id])));
});

// ---------------------------------------------------------------------- con miles de categorías

test('con 2 000 categorías: búsqueda, ramas, árbol, página y creación dentro de tiempo y de peso', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $n = cpMany($app, 20, 10, 9);   // 20 + 200 + 1 800 = 2 020
    check($n >= 2000, 'hay miles (' . $n . ')');
    $first = (int) $app->db()->value('SELECT id FROM {categories} ORDER BY id');
    $deep = (int) $app->db()->value("SELECT category_id FROM {category_i18n} WHERE name = 'Tipo 20-10-9'");
    $id = prodMake($web, 'Con miles', ['categories' => [(string) $deep, (string) $first], 'main_category' => (string) $deep]);

    $t = microtime(true);
    $page = $web->get('/admin/r/products/' . $id);
    $pageMs = (microtime(true) - $t) * 1000;
    same(200, $page->status);
    check(strlen($page->body) < 120000, 'la ficha pesa poco con miles de categorías (' . strlen($page->body) . ' bytes)');
    same(2, count(cpChips($page->body)));
    same('Grupo 20 › Sección 20-10 › Tipo 20-10-9', cpChips($page->body)[$deep]['path']);

    $t = microtime(true);
    $search = $web->get('/admin/catalog/categories/pick?q=' . rawurlencode('tipo 20-10'));
    $searchMs = (microtime(true) - $t) * 1000;
    same(9, count(cpJson($search)['items']), 'hay 9 «Tipo 20-10-n» y salen todas');
    check(strlen($search->body) < 3000, 'la búsqueda pesa poco (' . strlen($search->body) . ' bytes)');

    $t = microtime(true);
    $level = $web->get('/admin/catalog/categories/pick?parent=0');
    $levelMs = (microtime(true) - $t) * 1000;
    same(20, count(cpJson($level)['nodes']), 'la raíz trae sus 20 ramas y no las 2 000');

    $t = microtime(true);
    $filter = $web->get('/admin/catalog/categories/pick?tree=' . rawurlencode('seccion 5-5'));
    $filterMs = (microtime(true) - $t) * 1000;
    same(['Grupo 5', 'Sección 5-5'], array_column(cpJson($filter)['nodes'], 'n'), 'solo la que coincide y su madre, no los 2 000');

    $t = microtime(true);
    $all = $web->get('/admin/catalog/categories/pick?tree=');
    $allMs = (microtime(true) - $t) * 1000;
    same(count(cpJson($all)['nodes']), $n);

    $t = microtime(true);
    $parents = $web->get('/admin/catalog/categories/pick?all=1');
    $parentsMs = (microtime(true) - $t) * 1000;

    $web->get('/admin/r/products/new');
    $t = microtime(true);
    $created = cpCreate($web, ['name' => 'Una más', 'parent' => (string) $first]);
    $createMs = (microtime(true) - $t) * 1000;
    same(200, $created->status);

    foreach ([[$pageMs, 6000, 'la ficha'], [$searchMs, 2000, 'la búsqueda'], [$levelMs, 2000, 'las ramas'], [$filterMs, 2000, 'el filtro'], [$allMs, 4000, 'el árbol entero'], [$parentsMs, 4000, 'las madres'], [$createMs, 4000, 'crear']] as $m) {
        check($m[0] < $m[1], $m[2] . ' tarda ' . round($m[0]) . ' ms (tope ' . $m[1] . ')');
    }
    check(strlen($all->body) < 400000, 'el árbol entero a petición pesa ' . strlen($all->body) . ' bytes');
    cpMeasure(sprintf('categorias=%d ficha=%dms/%dB busqueda=%dms/%dB ramas=%dms/%dB filtro=%dms/%dB arbol_entero=%dms/%dB madres=%dms/%dB crear=%dms', $n, $pageMs, strlen($page->body), $searchMs, strlen($search->body), $levelMs, strlen($level->body), $filterMs, strlen($filter->body), $allMs, strlen($all->body), $parentsMs, strlen($parents->body), $createMs));
});

test('con más ramas de las que caben, un nivel y el árbol entero se cortan en su tope', function (): void {
    $app = prodApp();
    cpMany($app, CategoryPicker::MAX_LEVEL + 20, 0, 0);
    same(CategoryPicker::MAX_LEVEL, count(CategoryPicker::level($app, 0)));
    $filtered = CategoryPicker::tree($app, 'grupo');
    check(count($filtered) <= CategoryPicker::MAX_FILTER, 'un filtro flojo no devuelve el catálogo entero (' . count($filtered) . ')');
});

// ---------------------------------------------------------------------- piezas sueltas

test('CategoryPicker::fold quita tildes y mayúsculas y deja la eñe como n', function (): void {
    same('cafe con leche', CategoryPicker::fold('CAFÉ con Leche'));
    same('ano', CategoryPicker::fold('Año'));
    same('', CategoryPicker::fold(''));
    same('a&b <c>', CategoryPicker::fold('Á&B <C>'));
});

test('Form acepta un control propio con «render» y conserva la etiqueta, la ayuda y el error', function (): void {
    $app = prodApp();
    $form = new Form($app, ['fields' => ['x' => ['type' => 'text', 'label' => 'Name', 'hint' => 'A hint', 'render' => static function (string $name, string $id, $value, App $app, array $all): string {
        return '<b data-x="' . e($name . '|' . $id . '|' . (is_scalar($value) ? $value : '') . '|' . count($all)) . '">propio</b>';
    }]]]);
    $html = $form->renderFields(['x' => 'hola'], ['x' => 'Mal']);
    contains('<b data-x="f[x]|f-x|hola|1">propio</b>', $html);
    contains('Nombre', $html);
    contains('A hint', $html);
    contains('Mal', $html);
    notContains('<input', $html, 'el control del tipo no se pinta');
});

test('los ficheros del selector caben en su tope y no llevan nada que la política de contenido o el tema oscuro rechacen', function (): void {
    $dir = dirname(__DIR__) . '/assets/';
    $js = (string) file_get_contents($dir . 'category-picker.js');
    $css = (string) file_get_contents($dir . 'category-picker.css');
    check(strlen($js) <= 8000, 'el guion pesa ' . strlen($js) . ' bytes (tope 8 000)');
    check(strlen($css) <= 2500, 'la hoja pesa ' . strlen($css) . ' bytes (tope 2 500)');
    notContains('innerHTML', $js);
    notContains('insertAdjacentHTML', $js);
    notContains('eval(', $js);
    notContains('document.write', $js);
    check(preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $css) === 0, 'la hoja no lleva ningún color fijo: solo variables');
    contains('var(--', $css);
    check(substr_count($js, '(') === substr_count($js, ')') && substr_count($js, '{') === substr_count($js, '}'), 'paréntesis y llaves cuadran');
    cpMeasure('guion=' . strlen($js) . 'B hoja=' . strlen($css) . 'B');
});

test('el guion se conecta por delegación sobre el documento y pide lo que el servidor ofrece', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/category-picker.js');
    contains("A.on('input'", $js);
    contains("A.on('keydown'", $js);
    contains('aria-activedescendant', $js);
    contains("'Escape'", $js);
    contains("'ArrowDown'", $js);
    contains('setTimeout(', $js);
    contains(', 200)', $js, 'espera de 200 ms');
    contains('x.length < 2', $js, 'desde dos letras');
    contains('showModal()', $js);
    foreach (['q=', 'ids=', 'parent=', 'tree=', 'all=1'] as $param) {
        contains($param, $js);
    }
    notContains("getElementById('f-categories", $js);
});

test('rellenar con IA sigue pudiendo elegir una categoría: pide la etiqueta al selector', function (): void {
    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/quickfill.js');
    contains('aifec:category-add', $js);
    contains('name="f[categories][]"', $js, 'y conserva la casilla de siempre para el respaldo');
    $picker = (string) file_get_contents(dirname(__DIR__) . '/assets/category-picker.js');
    contains('aifec:category-add', $picker);
});

test('la lista de la ficha cuadra con el catálogo en castellano: todos los textos del selector están traducidos', function (): void {
    $app = prodApp();
    cpShop($app);
    $body = adminClient($app)->get('/admin/r/products/new')->body;
    foreach (['Buscar una categoría', 'Ver todos', 'Desplegar todo', 'Plegar todo', 'Filtrar por nombre', 'Aplicar', 'Principal', 'Este producto todavía no tiene categoría.', 'No se han podido cargar las categorías.', 'Ninguna categoría coincide.', 'Crear'] as $text) {
        contains($text, $body);
    }
    notContains('Search a category', $body);
    notContains('Expand all', $body);
});

test('sin JavaScript: el respaldo manda cada categoría una sola vez y el producto se guarda con la elección', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $c = cpShop($app);
    $id = prodMake($web, 'Sartén', ['categories' => [(string) $c['pans'], (string) $c['cafe']], 'main_category' => (string) $c['pans']]);
    $body = $web->get('/admin/r/products/' . $id)->body;
    // El navegador sin guion manda las casillas marcadas del respaldo y nada más de categorías.
    preg_match_all('#name="f\[categories\]\[\]" value="(\d+)" checked#', cpNoscript($body), $m);
    same([(string) $c['pans'], (string) $c['cafe']], $m[1], 'las dos marcadas, cada una una vez');
    $sent = array_values(array_diff($m[1], [(string) $c['cafe']]));
    $sent[] = (string) $c['chairs'];
    $r = prodSave($web, prodForm(['name' => ['es' => 'Sartén', 'en' => ''], 'categories' => $sent, 'main_category' => (string) $c['pans']]), $id);
    same(302, $r->status);
    same([$c['pans'], $c['chairs']], array_map('intval', $app->db()->column('SELECT category_id FROM {product_categories} WHERE product_id = ? ORDER BY position', [$id])));
});
