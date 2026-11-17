<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;

require_once __DIR__ . '/helpers.php';

/*
 * Datos estructurados de la ficha de producto: Product y ProductGroup con su oferta, envío, devoluciones, valoraciones y preguntas.
 */

test('la ficha lleva un bloque ld+json válido con nombre, referencia, código de barras, marca e imágenes completas', function (): void {
    $app = semanticApp();
    $brand = aifecBrand($app, 'Maderas Norte');
    semanticProduct($app, ['sku' => 'SILLA-1', 'gtin' => '8412345678905', 'mpn' => 'MN-100', 'brand_id' => $brand, 'description' => '<p>Una <b>silla</b> de roble macizo.</p>']);
    $web = new TestClient($app);

    $r = $web->get('/producto/silla');
    same(200, $r->status);
    $product = semanticFind(semanticLd($r->body), 'Product');
    check($product !== null, 'hay un objeto Product');
    same('https://schema.org', $product['@context']);
    same('Silla de roble', $product['name']);
    same('SILLA-1', $product['sku']);
    same('8412345678905', $product['gtin']);
    same('MN-100', $product['mpn']);
    same('Maderas Norte', $product['brand']['name']);
    same('Una silla de roble macizo.', $product['description'], 'la descripción va sin HTML');
    same('https://tienda.test/producto/silla', $product['url']);
    same(2, count($product['image']));
    foreach ($product['image'] as $image) {
        check(strpos($image, 'https://tienda.test/') === 0, 'la imagen lleva la dirección completa: ' . $image);
    }
});

test('el precio de la oferta es el que pinta la ficha: con impuestos, con punto y dos decimales, y la misma moneda', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app, ['price' => 1999]);
    $web = new TestClient($app);

    $expected = $app->service('catalog')->product($id)['price'];
    $offer = semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers'];
    same('19.99', $offer['price']);
    same(Money::decimal($expected['gross']), $offer['price']);
    same($expected['currency'], $offer['priceCurrency']);
    same('https://schema.org/NewCondition', $offer['itemCondition']);
    same('AIFEC', $offer['seller']['name']);
});

test('con los precios escritos sin impuestos, la oferta sigue llevando el precio con impuestos', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['tax.prices_include_tax' => false]);
    $id = semanticProduct($app, ['price' => 1000]);
    $web = new TestClient($app);

    $price = $app->service('catalog')->product($id)['price'];
    same(1210, $price['gross'], 'el producto vale 12,10 € con el 21 % de IVA');
    same('12.10', semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers']['price']);
});

test('la disponibilidad sale InStock con existencias y OutOfStock agotado', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['stock' => 5]);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete', 'stock' => 0]);
    $web = new TestClient($app);

    same('https://schema.org/InStock', semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers']['availability']);
    same('https://schema.org/OutOfStock', semanticFind(semanticLd($web->get('/producto/taburete')->body), 'Product')['offers']['availability']);
});

test('una oferta con fin lleva priceValidUntil y sin fin no lo lleva', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['price' => 2000, 'sale_price' => 1500, 'sale_from' => '2026-11-01 00:00:00', 'sale_to' => '2026-12-24 22:59:59']);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete', 'price' => 2000]);
    $web = new TestClient($app);

    $offer = semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers'];
    same('15.00', $offer['price']);
    same('2026-12-24', $offer['priceValidUntil']);
    check(!isset(semanticFind(semanticLd($web->get('/producto/taburete')->body), 'Product')['offers']['priceValidUntil']), 'sin oferta no hay fecha de fin');
});

test('con variantes es un ProductGroup con una oferta por variante activa y sin oferta propia', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['sku' => 'SILLA', 'variants' => [
        ['sku' => 'SILLA-R', 'price' => 1999, 'stock' => 3, 'values' => ['color' => 'rojo']],
        ['sku' => 'SILLA-A', 'price' => 2199, 'stock' => 0, 'values' => ['color' => 'azul']],
        ['sku' => 'SILLA-V', 'price' => 1899, 'stock' => 4, 'active' => 0, 'values' => ['color' => 'verde']],
    ]]);
    $web = new TestClient($app);

    $group = semanticFind(semanticLd($web->get('/producto/silla')->body), 'ProductGroup');
    check($group !== null, 'hay un ProductGroup');
    check(!isset($group['offers']), 'el grupo no lleva oferta propia');
    same('SILLA', $group['productGroupID']);
    same(['https://schema.org/color'], $group['variesBy']);
    same(2, count($group['hasVariant']), 'tantas variantes como activas');
    $bySku = [];
    foreach ($group['hasVariant'] as $variant) {
        $bySku[$variant['sku']] = $variant;
    }
    $skus = array_keys($bySku);
    sort($skus);
    same(['SILLA-A', 'SILLA-R'], $skus, 'la variante inactiva no sale');
    same('19.99', $bySku['SILLA-R']['offers']['price']);
    same('Rojo', $bySku['SILLA-R']['color']);
    same('https://schema.org/InStock', $bySku['SILLA-R']['offers']['availability']);
    same('https://schema.org/OutOfStock', $bySku['SILLA-A']['offers']['availability']);
    same('SILLA', $bySku['SILLA-R']['inProductGroupWithID']);
});

test('sin valoraciones no hay aggregateRating y con valoraciones sí', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete', 'rating_sum' => 46, 'rating_count' => 10]);
    $web = new TestClient($app);

    check(!isset(semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['aggregateRating']), 'sin valoraciones, sin propiedad');
    $rating = semanticFind(semanticLd($web->get('/producto/taburete')->body), 'Product')['aggregateRating'];
    same('AggregateRating', $rating['@type']);
    same(4.6, $rating['ratingValue']);
    same(10, $rating['reviewCount']);
});

test('las preguntas frecuentes salen como un FAQPage aparte y sin preguntas no sale', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['faqs' => [['question' => '¿Se monta fácil?', 'answer' => '<p>Sí, en <b>10 minutos</b>.</p>']]]);
    semanticProduct($app, ['name' => 'Taburete', 'slug' => 'taburete']);
    $web = new TestClient($app);

    $faq = semanticFind(semanticLd($web->get('/producto/silla')->body), 'FAQPage');
    check($faq !== null, 'hay un FAQPage');
    same('¿Se monta fácil?', $faq['mainEntity'][0]['name']);
    same('Sí, en 10 minutos.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
    same(null, semanticFind(semanticLd($web->get('/producto/taburete')->body), 'FAQPage'));
});

test('un nombre con </script> no rompe el bloque ni deja una etiqueta suelta en la página', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['name' => 'Silla </script><b>x</b> & "comillas"', 'slug' => 'silla']);
    $web = new TestClient($app);

    $r = $web->get('/producto/silla');
    $objects = semanticLd($r->body);
    same('Silla </script><b>x</b> & "comillas"', semanticFind($objects, 'Product')['name']);
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $r->body, $blocks);
    foreach ($blocks[1] as $block) {
        notContains('</script', $block);
        notContains('<b>', $block);
    }
});

test('las devoluciones de los ajustes salen en la oferta y, sin días, no sale nada', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['semantic.returns.days' => 30, 'semantic.returns.payer' => 'merchant', 'semantic.returns.method' => 'store']);
    semanticProduct($app);
    $web = new TestClient($app);

    $policy = semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers']['hasMerchantReturnPolicy'];
    same('MerchantReturnPolicy', $policy['@type']);
    same(30, $policy['merchantReturnDays']);
    same('ES', $policy['applicableCountry']);
    same('https://schema.org/FreeReturn', $policy['returnFees']);
    same('https://schema.org/ReturnInStore', $policy['returnMethod']);
    same('https://schema.org/MerchantReturnFiniteReturnWindow', $policy['returnPolicyCategory']);

    $app->settings()->set('semantic.returns.days', 0);
    $app->invalidate(['catalog']);
    check(!isset(semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers']['hasMerchantReturnPolicy']), 'sin días no hay política');
});

test('con envíos, la oferta lleva el coste y el plazo del transportista más barato al país de la tienda', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'shipping']);
    $pen = shippingZoneId($app, 'Peninsula');
    $barato = shippingAddCarrier($app, 'barato', ['min_days' => 3, 'max_days' => 5]);
    $rapido = shippingAddCarrier($app, 'rapido', ['min_days' => 1, 'max_days' => 1]);
    shippingAddRate($app, $barato, $pen, 'weight', 0, 0, 495);
    shippingAddRate($app, $rapido, $pen, 'weight', 0, 0, 995);
    semanticProduct($app, ['weight' => 800]);
    $web = new TestClient($app);

    $offer = semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers'];
    $details = $offer['shippingDetails'];
    same('OfferShippingDetails', $details['@type']);
    same('4.95', $details['shippingRate']['value']);
    same('EUR', $details['shippingRate']['currency']);
    same('ES', $details['shippingDestination']['addressCountry']);
    same(3, $details['deliveryTime']['transitTime']['minValue']);
    same(5, $details['deliveryTime']['transitTime']['maxValue']);
    same('DAY', $details['deliveryTime']['transitTime']['unitCode']);
});

test('un producto digital no lleva datos de envío', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'shipping']);
    $pen = shippingZoneId($app, 'Peninsula');
    shippingAddRate($app, shippingAddCarrier($app, 'unico'), $pen, 'weight', 0, 0, 495);
    semanticProduct($app, ['kind' => 'digital']);
    $web = new TestClient($app);

    check(!isset(semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product')['offers']['shippingDetails']), 'un producto digital no se envía');
});

test('los datos clave y los atributos salen como propiedades y la categoría como su ruta', function (): void {
    $app = semanticApp();
    $muebles = aifecCategory($app, 'Muebles');
    $sillas = aifecCategory($app, 'Sillas', $muebles);
    semanticProduct($app, ['categories' => [$sillas], 'facts' => [['label' => 'Material', 'value' => 'Roble'], ['label' => 'peso', 'value' => '130']], 'attributes' => ['peso' => 130]]);
    $web = new TestClient($app);

    $product = semanticFind(semanticLd($web->get('/producto/silla')->body), 'Product');
    same('Muebles > Sillas', $product['category']);
    $props = [];
    foreach ($product['additionalProperty'] as $property) {
        $props[$property['name']] = $property['value'];
    }
    same('Roble', $props['Material']);
    same('130', isset($props['Peso']) ? $props['Peso'] : $props['peso']);
    same(2, count($product['additionalProperty']), 'el dato repetido en características y atributos va una sola vez');
});

test('el servicio jsonld() de un producto devuelve la lista de objetos y la pasa por el filtro «jsonld»', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app, ['faqs' => [['question' => '¿Q?', 'answer' => 'R']]]);
    $seen = [];
    $app->hooks()->filter('jsonld', static function ($objects, $type, $data) use (&$seen): array {
        $seen[] = $type;
        $objects[] = ['@context' => 'https://schema.org', '@type' => 'Thing', 'name' => 'extra'];

        return $objects;
    });

    $objects = $app->service('semantic')->jsonld('product', $app->service('catalog')->product($id));
    same(['Product', 'FAQPage', 'Thing'], array_column($objects, '@type'));
    same(['product'], $seen);
});
