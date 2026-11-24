<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';

/*
 * Verificación del 24/11/2026 sobre gmartos.es: la ficha de un software descargable decía «ReturnByMail» y «la devolución la paga el
 * cliente, por correo». Una descarga o un servicio no vuelven por correo: sin método ni gastos de devolución, y con el enlace a la
 * página «Envíos y devoluciones».
 */

/** @return array<string,mixed> */
function returnsShop(): array
{
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms'], ['semantic.returns.days' => 14]);
    $page = cmsInsertPage($app, ['title' => 'Envíos y devoluciones', 'slug' => 'envios-y-devoluciones']);
    $app->settings()->set('cms.legal', ['shipping-returns' => $page]);

    return [
        'app' => $app,
        'soft' => aifecProduct($app, ['name' => 'Programa', 'slug' => 'programa', 'kind' => 'digital', 'price' => 5000, 'images' => 1]),
        'silla' => aifecProduct($app, ['name' => 'Silla de roble', 'slug' => 'silla', 'kind' => 'physical', 'price' => 5000, 'images' => 1, 'stock' => 5]),
    ];
}

/** El nodo Product (o su oferta) de la ficha. @return array<string,mixed> */
function returnsPolicyOf(\Aifec\Kernel\App $app, string $slug): array
{
    $product = semanticFind(semanticLd((new TestClient($app))->get('/producto/' . $slug)->body), 'Product');
    check($product !== null, 'la ficha lleva datos de producto');
    $offer = is_array($product) && isset($product['offers']) ? $product['offers'] : [];

    return isset($offer['hasMerchantReturnPolicy']) && is_array($offer['hasMerchantReturnPolicy']) ? $offer['hasMerchantReturnPolicy'] : [];
}

test('un software descargable no emite ReturnByMail ni gastos de devolución y enlaza la página de envíos y devoluciones', function (): void {
    $shop = returnsShop();
    $policy = returnsPolicyOf($shop['app'], 'programa');
    same('MerchantReturnPolicy', $policy['@type'] ?? '');
    same(14, $policy['merchantReturnDays'] ?? 0, 'el plazo sigue');
    check(!isset($policy['returnMethod']), 'sin método de devolución');
    check(!isset($policy['returnFees']), 'sin gastos de devolución');
    same('https://tienda.test/envios-y-devoluciones', $policy['merchantReturnLink'] ?? '', 'y el enlace a la página legal');
});

test('un producto físico conserva su método y sus gastos, y también lleva el enlace', function (): void {
    $shop = returnsShop();
    $policy = returnsPolicyOf($shop['app'], 'silla');
    same('https://schema.org/ReturnByMail', $policy['returnMethod'] ?? '');
    same('https://schema.org/ReturnShippingFees', $policy['returnFees'] ?? '');
    same('https://tienda.test/envios-y-devoluciones', $policy['merchantReturnLink'] ?? '');
});

test('la versión .md de la ficha de un software no dice que el cliente devuelve por correo', function (): void {
    $shop = returnsShop();
    $md = (new TestClient($shop['app']))->get('/producto/programa.md')->body;
    contains('Devoluciones', $md);
    notContains('por correo', $md, 'ni «por correo»');
    notContains('paga la devolución', $md);
    contains('https://tienda.test/envios-y-devoluciones', $md, 'con la página de envíos y devoluciones');
    $silla = (new TestClient($shop['app']))->get('/producto/silla.md')->body;
    contains('por correo', $silla, 'el producto físico sigue diciéndolo');
});

test('openai.json: la política de devoluciones de una descarga no lleva método ni quién paga', function (): void {
    $shop = returnsShop();
    $client = new TestClient($shop['app']);
    $r = $client->get('/feeds/openai.json');
    $doc = json_decode(is_string($r->file) ? (string) file_get_contents($r->file) : $r->body, true);
    $byName = [];
    foreach (is_array($doc) ? $doc['products'] : [] as $item) {
        $byName[$item['title']] = $item['return_policy'] ?? [];
    }
    check(!isset($byName['Programa']['method']) && !isset($byName['Programa']['fees_paid_by']), 'la descarga no lleva método');
    same(14, $byName['Programa']['days'] ?? 0);
    same('mail', $byName['Silla de roble']['method'] ?? '', 'la silla sí');
});

test('el ajuste ofrece «Descarga o servicio»', function (): void {
    $fields = [];
    foreach ((array) (require dirname(__DIR__) . '/settings.php')['tabs'] as $tab) {
        $fields += $tab['fields'];
    }
    check(isset($fields['semantic.returns.method']['options']['none']), 'hay una opción «none»');
});
