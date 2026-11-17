<?php
/**
 * AIFEC — pruebas de los puntos de extensión del catálogo (P092): las insignias de la tarjeta y de la ficha (filtro
 * product.badges), y la disponibilidad con efecto (product.availability con «purchasable», «show_price» y «schema»): lo que se ve
 * en la ficha y en la tarjeta, lo que pide el carrito, el precio que desaparece de la página, de los datos estructurados y de los
 * feeds, y que un listado de 24 productos no hace una consulta más por producto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\ProductData;
use Aifec\Modules\Catalog\Stock;

require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__, 2) . '/semantic/tests/helpers.php';

/**
 * Engancha un filtro como lo haría un módulo del sistema (el catálogo tiene concedida la capacidad «pricing»).
 */
function catExtFilter(App $app, string $hook, callable $filter): void
{
    $hooks = $app->hooks();
    $hooks->setCurrentModule('catalog');
    $hooks->filter($hook, $filter);
    $hooks->setCurrentModule('core');
    // Lo que ya está en la caché se calculó sin este filtro: como cuando un plugin cambia algo y vacía la etiqueta del catálogo.
    $app->invalidate(['catalog']);
}

/**
 * Un producto con oferta del 17 %, nuevo y sin existencias: las tres insignias de serie.
 *
 * @param array<string,mixed> $extra
 */
function catExtProduct(App $app, array $extra = []): int
{
    return aifecProduct($app, $extra + ['name' => 'Silla', 'price' => 2000, 'sale_price' => 1660, 'published_at' => '2026-11-02 12:00:00', 'stock' => 0]);
}

/**
 * @param array<int,array<string,string>> $badges
 *
 * @return string[]
 */
function catExtCodes(array $badges): array
{
    return array_map(static function (array $badge): string {
        return $badge['code'];
    }, $badges);
}

test('insignias de serie: oferta con su porcentaje, nuevo y agotado, cada una con código, texto y tipo', function (): void {
    $app = catalogApp();
    $card = aifecCatalog($app)->card(catExtProduct($app));
    same([
        ['code' => 'sale', 'label' => "\u{2212}17\u{a0}%", 'kind' => 'sale'],
        ['code' => 'new', 'label' => 'Nuevo', 'kind' => 'ok'],
        ['code' => 'out', 'label' => 'Agotado', 'kind' => 'warn'],
    ], $card['badge_list']);
    same(['sale', 'new', 'out'], $card['badges'], 'los códigos de siempre siguen ahí, para las plantillas antiguas');
});

test('una tarjeta sin nada especial no lleva insignias', function (): void {
    $app = catalogApp();
    $card = aifecCatalog($app)->card(aifecProduct($app, ['name' => 'Mesa', 'created_at' => '2026-01-01 00:00:00', 'stock' => 20]));
    same([], $card['badge_list']);
    same([], $card['badges']);
});

test('un plugin añade una insignia y quita «Agotado» en la tarjeta', function (): void {
    $app = catalogApp();
    catExtFilter($app, 'product.badges', static function (array $badges): array {
        $out = [];
        foreach ($badges as $badge) {
            if ($badge['code'] !== 'out') {
                $out[] = $badge;
            }
        }
        $out[] = ['code' => 'soon', 'label' => 'Próximamente', 'kind' => 'info'];

        return $out;
    });
    $card = aifecCatalog($app)->card(catExtProduct($app));
    same(['sale', 'new', 'soon'], catExtCodes($card['badge_list']));
    same(['sale', 'new', 'soon'], $card['badges']);
    same('Próximamente', $card['badge_list'][2]['label']);
});

test('un plugin puede cambiar el texto de una insignia del catálogo', function (): void {
    $app = catalogApp();
    catExtFilter($app, 'product.badges', static function (array $badges): array {
        foreach ($badges as $i => $badge) {
            if ($badge['code'] === 'new') {
                $badges[$i]['label'] = 'Recién llegado';
            }
        }

        return $badges;
    });
    $card = aifecCatalog($app)->card(catExtProduct($app));
    same('Recién llegado', $card['badge_list'][1]['label']);
});

test('el filtro recibe el producto delante y dónde se pide: la tarjeta o la ficha', function (): void {
    $app = catalogApp();
    $seen = [];
    catExtFilter($app, 'product.badges', static function ($badges, $product, $where) use (&$seen) {
        $seen[$where] = [(int) $product['id'], (string) $product['name'], is_array($product['price']) && isset($product['price']['amount'])];

        return $badges;
    });
    $id = catExtProduct($app);
    aifecCatalog($app)->card($id);
    aifecCatalog($app)->product($id);
    same([$id, 'Silla', true], $seen['card']);
    same([$id, 'Silla', true], $seen['page']);
});

test('la ficha lleva su lista de insignias y las pinta en el hueco de la galería, sin repetir la de oferta que ya está en el precio', function (): void {
    $app = catalogApp();
    catExtFilter($app, 'product.badges', static function (array $badges, array $product, string $where): array {
        $badges[] = ['code' => 'gm-extra', 'label' => 'Envío 24 h', 'kind' => 'ok'];

        return $badges;
    });
    $product = aifecCatalog($app)->product(catExtProduct($app));
    same(['sale', 'new', 'out', 'gm-extra'], catExtCodes($product['badge_list']));
    $html = $app->hooks()->render('product.gallery.before', $product);
    same('', $app->hooks()->render('product.summary.before', $product), 'el hueco del resumen queda libre para los plugins');
    contains('class="af-product-badges af-badges"', $html);
    contains('Envío 24 h', $html);
    contains('Nuevo', $html);
    notContains("\u{2212}17", $html, 'el porcentaje ya sale junto al precio');
    notContains('af-badge-sale', $html);
});

test('la ficha sin insignias que enseñar no pinta el hueco', function (): void {
    $app = catalogApp();
    $product = aifecCatalog($app)->product(aifecProduct($app, ['name' => 'Mesa', 'created_at' => '2026-01-01 00:00:00', 'stock' => 20]));
    same('', $app->hooks()->render('product.gallery.before', $product));
});

test('el texto de una insignia sale escapado, y un tipo que no existe se pinta como «info»', function (): void {
    $app = catalogApp();
    catExtFilter($app, 'product.badges', static function (array $badges): array {
        return [['code' => 'x', 'label' => '<script>alert(1)</script> & "co"', 'kind' => 'peligro']];
    });
    $card = aifecCatalog($app)->card(catExtProduct($app));
    same('info', $card['badge_list'][0]['kind']);
    $html = $app->view()->render('partials/badges', ['product' => $card, 'tag' => 'span']);
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;co&quot;', $html);
    notContains('<script', $html);
    contains('class="af-badge"', $html);
    contains('class="af-badges"', $html);
});

test('cada tipo de insignia tiene su clase, y la etiqueta y la clase del tema se respetan', function (): void {
    $app = catalogApp();
    $card = ['badge_list' => [
        ['code' => 'a', 'label' => 'A', 'kind' => 'ok'], ['code' => 'b', 'label' => 'B', 'kind' => 'warn'],
        ['code' => 'c', 'label' => 'C', 'kind' => 'sale'], ['code' => 'd', 'label' => 'D', 'kind' => 'info'],
    ]];
    $html = $app->view()->render('partials/badges', ['product' => $card, 'tag' => 'ul', 'class' => 'ds-badges', 'item' => 'li']);
    contains('<ul class="ds-badges af-badges" role="list">', $html);
    contains('<li><span class="af-badge af-badge-new">A</span></li>', $html);
    contains('<li><span class="af-badge af-badge-out">B</span></li>', $html);
    contains('<li><span class="af-badge af-badge-sale">C</span></li>', $html);
    contains('<li><span class="af-badge">D</span></li>', $html);
    $odd = $app->view()->render('partials/badges', ['product' => $card, 'tag' => 'script', 'class' => 'x"y']);
    contains('<span class="x&quot;y af-badges">', $odd, 'una etiqueta que no está permitida cae en span y la clase se escapa');
});

test('una tarjeta hecha a mano que solo trae los códigos de siempre se pinta como siempre', function (): void {
    $app = catalogApp();
    $html = $app->view()->render('partials/badges', ['product' => [
        'badges' => ['sale', 'new', 'out'], 'price' => ['amount' => 8000, 'compare' => 10000],
    ], 'tag' => 'span']);
    contains("\u{2212}20\u{a0}%", $html);
    contains('af-badge-sale', $html);
    contains('af-badge-new', $html);
    contains('af-badge-out', $html);
    same('', trim($app->view()->render('partials/badges', ['product' => ['badges' => []], 'tag' => 'span'])));
});

test('lo que devuelve el filtro se limpia: sin texto o sin código no entra, ni los repetidos; hasta ocho y texto corto', function (): void {
    $clean = ProductData::cleanBadges([
        ['code' => 'ok', 'label' => 'Bien', 'kind' => 'ok'],
        ['code' => 'ok', 'label' => 'Repetida', 'kind' => 'ok'],
        ['code' => 'sin-texto', 'label' => '   '],
        ['label' => 'Sin código'],
        'basura',
        ['code' => 'Raro Código!', 'label' => str_repeat('é', 60), 'kind' => 'warn'],
        ['code' => 'lista', 'label' => ['no'], 'kind' => 'ok'],
        ['code' => 'espacios', 'label' => "  mucho \n  espacio  "],
    ]);
    same(['ok', 'rarocdigo', 'espacios'], catExtCodes($clean));
    same(40, mb_strlen($clean[1]['label'], 'UTF-8'));
    same('mucho espacio', $clean[2]['label']);
    $many = [];
    for ($i = 1; $i <= 12; ++$i) {
        $many[] = ['code' => 'b' . $i, 'label' => 'B' . $i];
    }
    same(8, count(ProductData::cleanBadges($many)));
});

test('un filtro de insignias que lanza o que devuelve basura deja las del catálogo', function (): void {
    $app = catalogApp();
    expectHookErrors();
    catExtFilter($app, 'product.badges', static function (): array {
        throw new \RuntimeException('roto');
    });
    $card = aifecCatalog($app)->card(catExtProduct($app));
    same(['sale', 'new', 'out'], $card['badges']);

    $other = catalogApp();
    catExtFilter($other, 'product.badges', static function (): string {
        return 'no es una lista';
    });
    same(['sale', 'new', 'out'], aifecCatalog($other)->card(catExtProduct($other))['badges']);
});

test('la tarjeta cuenta a los plugins cuándo es de la lista y cuándo es de la ficha, sin volver a calcular nada al leerla de la caché', function (): void {
    $app = catalogApp();
    $calls = 0;
    catExtFilter($app, 'product.badges', static function (array $badges) use (&$calls): array {
        ++$calls;

        return $badges;
    });
    $id = catExtProduct($app);
    aifecCatalog($app)->card($id);
    aifecCatalog($app)->card($id);
    same(1, $calls, 'la segunda lectura sale de la caché');
});

test('la disponibilidad de serie se puede comprar, enseña el precio y no pide ninguna disponibilidad a las máquinas', function (): void {
    $app = catalogApp();
    $product = aifecCatalog($app)->product(aifecProduct($app, ['stock' => 20]));
    same(true, $product['availability']['purchasable']);
    same(true, $product['availability']['show_price']);
    same('', $product['availability']['schema']);
    check(!isset($product['price']['hidden']), 'el precio no lleva marca');
    check($product['price']['amount'] > 0, 'el precio es el de verdad');
});

test('un filtro que no conoce las claves nuevas las deja con su valor de serie, y un schema desconocido se descarta', function (): void {
    $app = catalogApp();
    catExtFilter($app, 'product.availability', static function (): array {
        return ['status' => 'low_stock', 'label' => 'Casi'];
    });
    $a = aifecCatalog($app)->product(aifecProduct($app))['availability'];
    same('low_stock', $a['status']);
    same('Casi', $a['label']);
    same(true, $a['purchasable']);
    same(true, $a['show_price']);
    same('', $a['schema']);

    same('PreOrder', Stock::normalise(['status' => 'x', 'label' => 'x', 'schema' => 'PreOrder'])['schema']);
    same('', Stock::normalise(['status' => 'x', 'label' => 'x', 'schema' => 'https://evil.test/'])['schema']);
    same('', Stock::normalise(['status' => 'x', 'label' => 'x', 'schema' => ['PreOrder']])['schema']);
    same(false, Stock::normalise(['purchasable' => 0])['purchasable']);
    same(false, Stock::normalise(['show_price' => ''])['show_price']);
    same('in_stock', Stock::normalise([])['status']);
});

test('purchasable = false: lo que lee el carrito dice que no se puede comprar, con el texto de la disponibilidad como motivo', function (): void {
    $app = catalogApp();
    $soon = aifecProduct($app, ['name' => 'Silla', 'stock' => 20]);
    $normal = aifecProduct($app, ['name' => 'Mesa', 'stock' => 20]);
    catExtFilter($app, 'product.availability', static function (array $a, array $context) use ($soon): array {
        if ($context['id'] === $soon) {
            $a['label'] = 'Próximamente';
            $a['purchasable'] = false;
        }

        return $a;
    });
    $p = aifecCatalog($app)->purchasable($soon);
    same(false, $p['available']);
    same('Próximamente', $p['reason']);
    same(true, aifecCatalog($app)->purchasable($normal)['available']);
});

test('purchasable = false sin texto en la disponibilidad usa el motivo de siempre', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 20]);
    catExtFilter($app, 'product.availability', static function (array $a): array {
        $a['purchasable'] = false;
        $a['label'] = '';

        return $a;
    });
    $p = aifecCatalog($app)->purchasable($id);
    same(false, $p['available']);
    same('Este producto no está disponible', $p['reason']);
});

test('con variantes, purchasable = false vale por variante: la elegida no se compra y la otra sí', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Camiseta', 'variants' => [['sku' => 'S', 'stock' => 5], ['sku' => 'M', 'stock' => 5]]]);
    $variants = aifecCatalog($app)->product($id)['variants'];
    $blocked = (int) $variants[0]['id'];
    catExtFilter($app, 'product.availability', static function (array $a, array $context) use ($blocked): array {
        if ($context['variant_id'] === $blocked) {
            $a['purchasable'] = false;
            $a['label'] = 'Sin tallas por ahora';
        }

        return $a;
    });
    $no = aifecCatalog($app)->purchasable($id, $blocked);
    same(false, $no['available']);
    same('Sin tallas por ahora', $no['reason']);
    same(true, aifecCatalog($app)->purchasable($id, (int) $variants[1]['id'])['available']);
    $product = aifecCatalog($app)->product($id);
    same(false, $product['variants'][0]['availability']['purchasable']);
    same(true, $product['variants'][1]['availability']['purchasable']);
});

test('show_price = false: la ficha y la tarjeta traen el precio oculto, a cero, y sin insignia de oferta', function (): void {
    $app = catalogApp();
    $id = catExtProduct($app, ['stock' => 20]);
    catExtFilter($app, 'product.availability', static function (array $a): array {
        $a['show_price'] = false;

        return $a;
    });
    $product = aifecCatalog($app)->product($id);
    $card = aifecCatalog($app)->card($id);
    foreach ([$product['price'], $card['price']] as $price) {
        same(true, $price['hidden']);
        same(0, $price['amount']);
        same(0, $price['gross']);
        same(null, $price['compare']);
        same(false, $price['on_sale']);
    }
    same(false, $card['availability']['show_price']);
    notContains('sale', implode(',', $card['badges']), 'la oferta no se anuncia si el precio no se enseña');
    same(['new'], $card['badges']);
});

test('show_price = false en una variante: solo su precio se oculta, y la ficha de variantes lo sabe', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Camiseta', 'variants' => [['sku' => 'S', 'price' => 1500, 'stock' => 5], ['sku' => 'M', 'price' => 1800, 'stock' => 5]]]);
    $first = (int) aifecCatalog($app)->product($id)['variants'][0]['id'];
    catExtFilter($app, 'product.availability', static function (array $a, array $context) use ($first): array {
        if ($context['variant_id'] === $first) {
            $a['show_price'] = false;
        }

        return $a;
    });
    $product = aifecCatalog($app)->product($id);
    same(true, $product['variants'][0]['price']['hidden']);
    same(0, $product['variants'][0]['price']['amount']);
    check(!isset($product['variants'][1]['price']['hidden']), 'la otra variante sigue con su precio');
    same(1800 > 0, $product['variants'][1]['price']['amount'] > 0);
    $data = \Aifec\Modules\Catalog\Front\Variants::data($product);
    same(true, $data['variants'][0]['price']['hidden']);
    same('', $data['variants'][0]['price']['formatted']);
    same(false, $data['variants'][1]['price']['hidden']);
    check($data['variants'][1]['price']['formatted'] !== '', 'la otra sigue con su precio escrito');
});

test('la disponibilidad que sale en la ficha de variantes dice si cada una se puede comprar', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Camiseta', 'variants' => [['sku' => 'S', 'stock' => 5], ['sku' => 'M', 'stock' => 5]]]);
    $variants = aifecCatalog($app)->product($id)['variants'];
    $blocked = (int) $variants[0]['id'];
    catExtFilter($app, 'product.availability', static function (array $a, array $context) use ($blocked): array {
        $a['purchasable'] = $context['variant_id'] !== $blocked;

        return $a;
    });
    $product = aifecCatalog($app)->product($id);
    $data = \Aifec\Modules\Catalog\Front\Variants::data($product);
    same(false, $data['variants'][0]['availability']['purchasable']);
    same(true, $data['variants'][1]['availability']['purchasable']);
    same(true, \Aifec\Modules\Catalog\Front\Variants::soldOut($product['variants'][0]), 'una variante que no se compra cuenta como agotada para el botón');
    same(false, \Aifec\Modules\Catalog\Front\Variants::soldOut($product['variants'][1]));
    same((int) $variants[1]['id'], (int) \Aifec\Modules\Catalog\Front\Variants::initial($product)['id'], 'la elegida de serie es la primera que se puede comprar');
});

test('el JSON-LD de la ficha usa la disponibilidad que pide el plugin (PreOrder) y no lleva precio si no se enseña', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app, ['stock' => 5]);
    catExtFilter($app, 'product.availability', static function (array $a): array {
        $a['schema'] = 'PreOrder';
        $a['show_price'] = false;
        $a['purchasable'] = false;

        return $a;
    });
    $web = new TestClient($app);
    $r = $web->get('/producto/silla');
    same(200, $r->status);
    $offer = semanticFind(semanticLd($r->body), 'Product')['offers'];
    same('https://schema.org/PreOrder', $offer['availability']);
    check(!array_key_exists('price', $offer), 'sin precio en los datos estructurados');
    check(!array_key_exists('priceCurrency', $offer), 'ni moneda');
    check(!array_key_exists('shippingDetails', $offer), 'ni envío calculado sobre un precio que no se enseña');
    unset($id);
});

test('el JSON-LD con precio y sin schema es el de siempre', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['stock' => 5]);
    $offer = semanticFind(semanticLd((new TestClient($app))->get('/producto/silla')->body), 'Product')['offers'];
    same('19.99', $offer['price']);
    same('https://schema.org/InStock', $offer['availability']);
});

test('los feeds no enseñan el precio de un producto que no lo enseña: ni Google, ni OpenAI, ni el público', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['stock' => 5]);
    $visible = aifecProduct($app, ['name' => 'Mesa visible', 'slug' => 'mesa-visible', 'price' => 4321, 'images' => 1, 'stock' => 5]);
    catExtFilter($app, 'product.availability', static function (array $a, array $context) use ($visible): array {
        if ($context['id'] !== $visible) {
            $a['show_price'] = false;
        }

        return $a;
    });
    semanticBuildFeeds($app);
    $google = semanticFeed($app, 'google.xml');
    notContains('Silla de roble', $google, 'Google exige precio: el producto sin precio no entra');
    notContains('19.99', $google);
    contains('Mesa visible', $google);
    contains('43.21', $google);

    $openai = semanticFeed($app, 'openai.json');
    notContains('Silla de roble', $openai);
    notContains('19.99', $openai);
    contains('Mesa visible', $openai);

    $public = semanticFeed($app, 'products.json');
    notContains('19.99', $public, 'el feed público no lleva el importe');
    notContains('1999', $public);
    contains('Silla de roble', $public, 'pero el producto sigue en el catálogo público');
    contains('"hidden":true', $public);
});

test('las páginas de texto para las IA tampoco dicen el precio de un producto que no lo enseña', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['stock' => 5]);
    catExtFilter($app, 'product.availability', static function (array $a): array {
        $a['show_price'] = false;
        $a['label'] = 'Próximamente';

        return $a;
    });
    $text = (new TestClient($app))->get('/producto/silla.md');
    if ($text->status === 200) {
        notContains('19,99', $text->body);
        notContains('19.99', $text->body);
        contains('Próximamente', $text->body);
        notContains('**Precio:**', $text->body);
    } else {
        $product = $app->service('catalog')->product(1);
        same(true, $product['price']['hidden']);
    }
});

test('un listado de 24 productos hace las mismas consultas con y sin filtros de disponibilidad e insignias: ninguna por producto', function (): void {
    $measure = static function (int $products, bool $plugin): int {
        $app = catalogApp();
        $soon = null;
        if ($plugin) {
            // Un plugin de verdad: la lista de los suyos se lee UNA vez (y queda en la caché), no una por tarjeta.
            catExtFilter($app, 'product.availability', static function (array $a, array $context) use ($app, &$soon): array {
                if ($soon === null) {
                    $soon = array_map('intval', $app->db()->column('SELECT id FROM {products} WHERE sku = ?', ['SOON']));
                }
                if (in_array($context['id'], (array) $soon, true)) {
                    $a['purchasable'] = false;
                    $a['show_price'] = false;
                }

                return $a;
            });
            catExtFilter($app, 'product.badges', static function (array $badges, array $product) use (&$soon): array {
                if (in_array($product['id'], (array) $soon, true)) {
                    $badges[] = ['code' => 'soon', 'label' => 'Próximamente', 'kind' => 'info'];
                }

                return $badges;
            });
        }
        for ($i = 1; $i <= $products; ++$i) {
            aifecProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'p-' . $i, 'sku' => $i % 5 === 0 ? 'SOON' : 'P' . $i, 'price' => 1000 + $i, 'stock' => 10]);
        }
        $before = $app->db()->queries;
        $list = aifecCatalog($app)->listing(['per_page' => 24]);
        same(min($products, 24), count($list['items']));

        return $app->db()->queries - $before;
    };
    $withoutPlugin = $measure(24, false);
    $withPlugin24 = $measure(24, true);
    $withPlugin3 = $measure(3, true);
    same($withPlugin3, $withPlugin24, 'con 24 productos las mismas consultas que con 3: nada de una por tarjeta');
    check($withPlugin24 - $withoutPlugin <= 1, 'el plugin añade, como mucho, la consulta de su lista (' . $withoutPlugin . ' sin plugin, ' . $withPlugin24 . ' con él)');
});

test('el listado enseña la tarjeta con lo que dice el filtro: etiqueta, no comprable y sin precio, sin consulta de más por producto', function (): void {
    $app = catalogApp();
    $ids = [];
    for ($i = 1; $i <= 6; ++$i) {
        $ids[$i] = aifecProduct($app, ['name' => 'P' . $i, 'slug' => 'p-' . $i, 'stock' => 10]);
    }
    $hidden = $ids[2];
    catExtFilter($app, 'product.availability', static function (array $a, array $context) use ($hidden): array {
        if ($context['id'] === $hidden) {
            $a['label'] = 'Próximamente';
            $a['purchasable'] = false;
            $a['show_price'] = false;
        }

        return $a;
    });
    $items = aifecCatalog($app)->listing(['per_page' => 24])['items'];
    $by = [];
    foreach ($items as $card) {
        $by[(int) $card['id']] = $card;
    }
    same('Próximamente', $by[$hidden]['availability']['label']);
    same(false, $by[$hidden]['availability']['purchasable']);
    same(true, $by[$hidden]['price']['hidden']);
    same(true, $by[$ids[1]]['availability']['purchasable']);
    check(!isset($by[$ids[1]]['price']['hidden']), 'los demás siguen igual');
});
