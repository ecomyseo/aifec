<?php
/**
 * AIFEC — pruebas de una ficha de producto más simple: el alta pide nombre y precio, lo que casi nadie toca va en «Más opciones»
 * plegado (abierto si tiene valor o error), no se pierde ningún campo y los que se ven al crear son pocos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Catalog\Admin\MoreOptions;
use Aifec\Modules\Catalog\Admin\ProductForm;

require_once __DIR__ . '/AdminProductKitTest.php';

/**
 * Los campos de la ficha ANTES de hacerla más simple (con las dos lenguas de la tienda de pruebas: es y en). Ninguno tiene que
 * faltar ahora: se pliegan, no se quitan.
 *
 * @var string[]
 */
const SIMPLE_FIELDS_BEFORE = [
    'name', 'summary', 'description', 'status', 'visibility', 'featured', 'brand_id', 'family_id', 'kind',
    'price', 'compare_price', 'sale_price', 'sale_from', 'sale_to', 'cost', 'tax_class_id',
    'stock_note', 'sku', 'gtin', 'mpn', 'manage_stock', 'stock', 'stock_alert', 'backorders', 'min_qty', 'max_qty', 'weight', 'length', 'width', 'height',
    'delivery_min_days', 'delivery_max_days',
    'images', 'categories', 'main_category', 'tags', 'attrs_family', 'attrs_note',
    'slug', 'meta_title', 'meta_description', 'facts_es', 'facts_en', 'faqs_es', 'faqs_en',
    'rel_related', 'rel_upsell', 'rel_cross',
];

/** Contenido de la pestaña activa de una ficha pintada, con sus bloques «Más opciones» quitados. */
function simpleActivePanel(string $html, bool $withMore = false): string
{
    check(preg_match('/data-af-active="([a-z]+)"/', $html, $m) === 1, 'la ficha trae pestaña activa');

    return simplePanel($html, $m[1], $withMore);
}

function simplePanel(string $html, string $tab, bool $withMore = false): string
{
    $start = strpos($html, '<div class="af-tabpanel" role="tabpanel" data-af-panel="' . $tab . '"');
    check($start !== false, 'existe la pestaña ' . $tab);
    $depth = 0;
    $pos = (int) $start;
    $end = null;
    while (preg_match('/<(\/?)div\b[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
        $depth += $m[1][0] === '/' ? -1 : 1;
        $pos = (int) $m[0][1] + strlen($m[0][0]);
        if ($depth === 0) {
            $end = $pos;
            break;
        }
    }
    check($end !== null, 'la pestaña ' . $tab . ' cierra');
    $panel = substr($html, (int) $start, (int) $end - (int) $start);

    return $withMore ? $panel : (string) preg_replace('#<details\b.*?</details>#s', '', $panel);
}

/**
 * Nombres de campo que salen en un trozo de HTML (una vez cada uno).
 *
 * @return string[]
 */
function simpleFieldNames(string $html): array
{
    preg_match_all('/data-af-field="([a-z_0-9]+)"/', $html, $m);

    return $m[1];
}

/** El «Más opciones» de una pestaña: su etiqueta de apertura completa, o null si no hay. */
function simpleMore(string $html, string $tab): ?string
{
    return preg_match('/<details class="af-help af-more" data-af-more="' . $tab . '"[^>]*>/', $html, $m) === 1 ? $m[0] : null;
}

test('el alta se hace con solo el nombre y el precio, y lo demás nace con valores razonables', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $web->get('/admin/r/products/new');
    $r = prodPost($web, '/admin/r/products/save', ['f' => ['name' => ['es' => 'Silla & Banco'], 'price' => '12,50']]);
    same(302, $r->status, 'solo nombre y precio basta');
    $id = prodScalar($app, 'SELECT MAX(id) FROM {products}');
    $row = $app->db()->row('SELECT * FROM {products} WHERE id = ?', [$id]);
    same(1250, (int) $row['price']);
    same('draft', $row['status'], 'nace como borrador');
    same('everywhere', $row['visibility']);
    same('physical', $row['kind']);
    same('no', $row['backorders']);
    same(0, (int) $row['manage_stock'], 'existencias sin controlar');
    same(0, (int) $row['stock']);
    same(0, (int) $row['featured']);
    same(1, (int) $row['min_qty']);
    same(0, (int) $row['max_qty']);
    same(null, $row['tax_class_id'], 'el impuesto por defecto (sin clase propia)');
    same(null, $row['sku']);
    same('Silla & Banco', (string) $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, 'es']));
    same('producto/silla-banco', (string) $app->slugs()->path('product', $id, 'es'), 'la dirección web sale del nombre');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {stock_moves} WHERE product_id = ?', [$id]), 'y sin movimientos de existencias');
});

test('sin nombre no se guarda aunque haya precio; sin precio nace a cero', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $web->get('/admin/r/products/new');
    $r = prodPost($web, '/admin/r/products/save', ['f' => ['name' => ['es' => ''], 'price' => '12,50']]);
    same(422, $r->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products}'));
    $r = prodPost($web, '/admin/r/products/save', ['f' => ['name' => ['es' => 'Gratis']]]);
    same(302, $r->status);
    same(0, prodScalar($app, 'SELECT price FROM {products} ORDER BY id DESC'));
});

test('editar con solo nombre y precio no cambia el estado, la visibilidad, lo destacado ni el control de existencias', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $id = prodMake($web, 'Completa', ['status' => 'published', 'visibility' => 'catalog', 'featured' => '1', 'manage_stock' => '1', 'stock' => '7', 'kind' => 'digital', 'backorders' => 'yes']);
    prodSave($web, ['name' => ['es' => 'Completa', 'en' => ''], 'price' => '20,00', 'stock' => '7'], $id);
    $row = $app->db()->row('SELECT * FROM {products} WHERE id = ?', [$id]);
    same(2000, (int) $row['price']);
    same('published', $row['status']);
    same('catalog', $row['visibility']);
    same(1, (int) $row['featured']);
    same(1, (int) $row['manage_stock']);
    same('digital', $row['kind']);
    same('yes', $row['backorders']);
    same(7, (int) $row['stock']);
});

test('ningún campo desaparece: la lista de antes y la de ahora son la misma y cada campo sale una sola vez', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $now = array_keys(ProductForm::definition($app, null)['fields']);
    $before = SIMPLE_FIELDS_BEFORE;
    sort($now);
    sort($before);
    same($before, $now, 'los mismos campos que antes de plegar');

    $body = $web->get('/admin/r/products/new')->body;
    $shown = simpleFieldNames($body);
    same($shown, array_values(array_unique($shown)), 'ningún campo repetido (con dos iguales, PHP se quedaría con el último)');
    $definition = ProductForm::definition($app, null)['fields'];
    foreach ($definition as $name => $field) {
        if (in_array(isset($field['type']) ? $field['type'] : 'text', ['html', 'heading', 'hidden'], true)) {
            continue;
        }
        check(in_array($name, $shown, true), 'el campo ' . $name . ' sigue en la ficha');
    }
    same(substr_count($body, '<div'), substr_count($body, '</div>'), 'el HTML sigue equilibrado tras recortar los bloques');
    same(1, substr_count($body, 'more.js'), 'el guion que recuerda «Más opciones» va una sola vez');
});

test('al crear se ven pocos campos y el resto está dentro de «Más opciones», plegado', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $body = $web->get('/admin/r/products/new')->body;

    $visible = simpleFieldNames(simpleActivePanel($body));
    check(count($visible) <= 12, 'a la vista al crear: ' . count($visible) . ' (' . implode(', ', $visible) . ')');
    foreach (['name', 'price'] as $needed) {
        check(in_array($needed, $visible, true), 'se ve ' . $needed);
    }
    same('pdmain', preg_match('/data-af-active="([a-z]+)"/', $body, $m) === 1 ? $m[1] : '', 'se abre en la pestaña principal');

    // Todas las pestañas: los visibles de cada una y los plegados.
    $seen = 0;
    $folded = 0;
    foreach (['pdmain', 'pdprice', 'pdstock', 'pdmedia', 'pdcats', 'pdattrs', 'pdseo', 'pdrelated'] as $tab) {
        $outside = simpleFieldNames(simplePanel($body, $tab));
        $all = simpleFieldNames(simplePanel($body, $tab, true));
        $seen += count($outside);
        $folded += count($all) - count($outside);
        $more = simpleMore($body, $tab);
        if ($more !== null) {
            notContains(' open', $more, 'plegado al crear: ' . $tab);
            check(count($all) > count($outside), $tab . ' tiene campos dentro');
        }
    }
    check($folded >= 20, 'lo que casi nadie toca está plegado (' . $folded . ' campos)');
    check($seen + $folded >= 40, 'y en total siguen los campos de siempre (' . ($seen + $folded) . ')');
    check($seen <= 20, 'incluso sumando todas las pestañas se ven pocos (' . $seen . ')');

    foreach (['pdmain' => ['visibility', 'kind', 'brand_id'], 'pdprice' => ['sale_price', 'cost'], 'pdstock' => ['gtin', 'mpn', 'min_qty', 'length', 'width', 'height'], 'pdseo' => ['facts_es', 'faqs_es']] as $tab => $names) {
        $outside = simpleFieldNames(simplePanel($body, $tab));
        $inside = simpleFieldNames(simplePanel($body, $tab, true));
        foreach ($names as $name) {
            check(!in_array($name, $outside, true) && in_array($name, $inside, true), $name . ' va en «Más opciones» de ' . $tab);
        }
    }
    foreach (['sku', 'weight', 'manage_stock'] as $name) {
        check(in_array($name, simpleFieldNames(simplePanel($body, 'pdstock')), true), $name . ' sigue a la vista');
    }
    check(in_array('price', simpleFieldNames(simplePanel($body, 'pdmain')), true), 'el precio está en la pestaña principal');
    notContains('data-af-field="price"', simplePanel($body, 'pdprice', true));
});

test('un producto nuevo no controla las existencias y el campo de existencias solo sale con el interruptor', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $body = $web->get('/admin/r/products/new')->body;
    check(preg_match('/<input[^>]*type="checkbox"[^>]*name="f\[manage_stock\]"[^>]*>/', $body, $m) === 1, 'el interruptor está');
    notContains('checked', $m[0], 'apagado en un producto nuevo');
    contains('data-af-field="stock" data-af-show-if="{&quot;manage_stock&quot;:&quot;1&quot;}"', $body, 'las existencias dependen del interruptor');

    $id = prodMake($web, 'Con control', ['manage_stock' => '1', 'stock' => '4']);
    $edit = $web->get('/admin/r/products/' . $id)->body;
    check(preg_match('/<input[^>]*type="checkbox"[^>]*name="f\[manage_stock\]"[^>]*>/', $edit, $m2) === 1);
    contains('checked', $m2[0], 'un producto que las controla sigue marcado');
});

test('«Más opciones» se abre solo en la pestaña donde algún campo tiene valor', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $plain = prodMake($web, 'Sin extras');
    $body = $web->get('/admin/r/products/' . $plain)->body;
    foreach (['pdmain', 'pdprice', 'pdstock', 'pdcats', 'pdseo'] as $tab) {
        $more = simpleMore($body, $tab);
        check($more !== null, $tab . ' tiene «Más opciones»');
        notContains(' open', (string) $more, $tab . ' plegado: ningún campo tiene valor');
    }

    $rich = prodMake($web, 'Con extras', [
        'gtin' => '8412345678905', 'sale_price' => '8,00', 'visibility' => 'catalog',
        'facts_es' => [['label' => 'Material', 'value' => 'Roble']],
    ]);
    $body = $web->get('/admin/r/products/' . $rich)->body;
    contains(' open', (string) simpleMore($body, 'pdstock'), 'el código de barras abre «Más opciones» de existencias');
    contains(' open', (string) simpleMore($body, 'pdprice'), 'la oferta abre el de precio');
    contains(' open', (string) simpleMore($body, 'pdmain'), 'la visibilidad abre el principal');
    contains(' open', (string) simpleMore($body, 'pdseo'), 'los datos clave abren el de SEO');
    notContains(' open', (string) simpleMore($body, 'pdcats'), 'sin categoría principal sigue plegado');
    same('8412345678905', inputValue($body, 'f[gtin]'), 'y el valor está donde estaba');
});

test('con un error de validación, «Más opciones» sale abierto con el error dentro y la pestaña activa', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $r = prodSave($web, prodForm(['price' => '10,00', 'sale_price' => '10,00']));
    same(422, $r->status);
    $more = simpleMore($r->body, 'pdprice');
    contains(' open', (string) $more, 'abierto por el error');
    $panel = simplePanel($r->body, 'pdprice', true);
    contains($app->i18n()->t('The sale price has to be lower than the price.'), $panel);
    contains('af-field-invalid', $panel);
    contains('data-af-active="pdprice"', $r->body);
    notContains(' open', (string) simpleMore($r->body, 'pdstock'), 'los demás siguen plegados');

    // Un reenvío con un valor en un campo plegado, aunque no haya error en él, lo deja abierto.
    $r = prodSave($web, prodForm(['name' => ['es' => '', 'en' => ''], 'gtin' => '123']));
    same(422, $r->status);
    contains(' open', (string) simpleMore($r->body, 'pdstock'), 'lo que se escribió no se esconde');
    same('123', inputValue($r->body, 'f[gtin]'));
});

test('MoreOptions: qué cuenta como «tener valor»', function (): void {
    $text = ['default' => null];
    $number = ['default' => 0];
    $select = ['default' => 'everywhere'];
    check(!MoreOptions::hasValue($text, null));
    check(!MoreOptions::hasValue($text, ''));
    check(!MoreOptions::hasValue($text, '   '));
    check(MoreOptions::hasValue($text, 'x'));
    check(!MoreOptions::hasValue($number, 0));
    check(!MoreOptions::hasValue($number, '0'));
    check(MoreOptions::hasValue($number, '5'));
    check(!MoreOptions::hasValue($select, 'everywhere'));
    check(MoreOptions::hasValue($select, 'hidden'));
    check(!MoreOptions::hasValue($text, []));
    check(!MoreOptions::hasValue($text, ['es' => '', 'en' => '']));
    check(MoreOptions::hasValue($text, ['es' => '', 'en' => 'Hi']));
    check(!MoreOptions::hasValue($text, [['label' => '', 'value' => '']]));
    check(MoreOptions::hasValue($text, [['label' => 'A', 'value' => '']]));
    check(MoreOptions::hasValue(['default' => 1], '2'), 'la cantidad mínima 2 no es la de serie');
    check(!MoreOptions::hasValue(['default' => 1], '1'));

    $groups = MoreOptions::groups(['es', 'en']);
    same(['facts_es', 'facts_en', 'faqs_es', 'faqs_en'], $groups['pdseo'], 'un campo de datos clave y de preguntas por idioma');
    $open = MoreOptions::open(['t' => ['a', 'b'], 'u' => ['c']], ['a' => ['default' => 0], 'b' => ['default' => 0], 'c' => ['default' => null]], ['a' => '0', 'b' => '3', 'c' => '']);
    same(['t' => true, 'u' => false], $open);
});

test('MoreOptions: recorta bloques con divs dentro, no mete texto raro y no se repite al pasarlo dos veces', function (): void {
    prodApp();
    $field = static function (string $name, string $inner = ''): string {
        return '<div class="af-field" data-af-field="' . $name . '"><label>' . $name . '</label><div class="af-control">' . $inner . '</div></div>';
    };
    $html = '<html><body><div class="af-tabpanel" role="tabpanel" data-af-panel="t1">'
        . $field('a', '<input value="a &amp; b <div>no es un div real</div>">') . $field('b') . $field('c', '<template><div>x</div></template>') . $field('d')
        . '</div><div class="af-tabpanel" role="tabpanel" data-af-panel="t2" hidden>' . $field('e') . '</div></body></html>';
    $groups = ['t1' => ['b', 'c', 'zzz'], 't2' => ['nada'], 't3' => ['e']];

    $once = MoreOptions::wrap($html, $groups, []);
    same(substr_count($once, '<div'), substr_count($once, '</div>'), 'sigue equilibrado');
    contains('data-af-more="t1"', $once);
    notContains('data-af-more="t2"', $once, 'sin campos que plegar no se pinta');
    notContains('data-af-more="t3"', $once, 'una pestaña que no existe se salta');
    foreach (['a', 'd'] as $name) {
        check(strpos($once, 'data-af-field="' . $name . '"') < strpos($once, '<details'), $name . ' se queda fuera');
    }
    foreach (['b', 'c'] as $name) {
        check(strpos($once, 'data-af-field="' . $name . '"') > strpos($once, '<details'), $name . ' va dentro');
    }
    same(1, substr_count($once, 'data-af-field="b"'), 'cada campo una sola vez');
    notContains(' open', substr($once, strpos($once, '<details'), 80));
    contains(' open', MoreOptions::wrap($html, $groups, ['t1' => true]));
    same($once, MoreOptions::wrap($once, $groups, []), 'pasarlo otra vez no cambia nada');

    $invalid = str_replace('<div class="af-field" data-af-field="b">', '<div class="af-field af-field-invalid" data-af-field="b">', $html);
    contains('data-af-more="t1" open', MoreOptions::wrap($invalid, $groups, []), 'un error abre solo');
    same($html, MoreOptions::wrap($html, [], []), 'sin grupos no toca nada');
    same('<p>sin pestañas</p>', MoreOptions::wrap('<p>sin pestañas</p>', $groups, []));
});

test('el guion que recuerda si se abrió existe, va por delegación y no lleva textos sin traducir', function (): void {
    $file = dirname(__DIR__) . '/assets/more.js';
    check(is_file($file), 'assets/more.js existe');
    $js = (string) file_get_contents($file);
    contains("addEventListener('toggle'", $js, 'escucha el evento sobre el documento');
    contains(', true);', $js, 'en la fase de captura, porque «toggle» no sube por el árbol');
    contains('localStorage', $js);
    contains('data-af-more', $js);
    notContains('innerHTML', $js, 'no escribe HTML');
});

test('quien solo puede ver el catálogo no puede dar de alta ni ve «Más opciones» de un alta', function (): void {
    $app = prodApp();
    $viewer = prodViewer($app);
    same(403, $viewer->get('/admin/r/products/new')->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products}'));
    $viewer->get('/admin/r/products');
    same(403, prodPost($viewer, '/admin/r/products/save', ['f' => ['name' => ['es' => 'Colado'], 'price' => '1,00']])->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products}'));
});
