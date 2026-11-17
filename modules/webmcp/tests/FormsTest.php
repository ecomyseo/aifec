<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * WebMCP declarativo: los formularios de la tienda que hacen algo útil llevan «toolname» y «tooldescription», cada campo su
 * «toolparamdescription», los nombres no se repiten en una página y las descripciones salen del catálogo de idioma.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Webmcp\Webmcp;

require_once __DIR__ . '/helpers.php';

/**
 * Una tienda con una ficha con variantes, otra sin ellas y un carrito con dos líneas.
 *
 * @return array{0:\Aifec\Kernel\App,1:TestClient,2:array<string,int>}
 */
function webmcpFormsShop(): array
{
    $app = webmcpApp();
    aifecAttribute($app, 'talla', 'Talla', ['s' => 'S', 'm' => 'M']);
    $ids = assistantShop($app);
    $ids['camiseta'] = aifecProduct($app, [
        'name' => 'Camiseta', 'slug' => 'camiseta', 'price' => 1500, 'stock' => 5,
        'variants' => [
            ['sku' => 'CAM-S', 'stock' => 3, 'values' => ['talla' => 's']],
            ['sku' => 'CAM-M', 'stock' => 2, 'values' => ['talla' => 'm']],
        ],
    ]);
    $web = new TestClient($app);
    $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 1]);
    $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 2]);

    return [$app, $web, $ids];
}

test('el comprobador de formularios marcados caza lo que falta y deja pasar lo completo', function (): void {
    $ok = '<form toolname="buscar_algo" tooldescription="Buscar en el catálogo"><input name="q" toolparamdescription="Qué buscar"><button type="submit">Ir</button></form>';
    same([], webmcpFormProblems($ok));
    contains('le falta la descripción', implode('|', webmcpFormProblems('<form toolname="buscar_algo"><input name="q" toolparamdescription="Qué buscar"></form>')));
    contains('toolparamdescription', implode('|', webmcpFormProblems('<form toolname="buscar_algo" tooldescription="Buscar en el catálogo"><input name="q"></form>')));
    contains('el nombre no es válido', implode('|', webmcpFormProblems('<form toolname="Buscar Algo" tooldescription="Buscar en el catálogo"></form>')));
    contains('se repite 2 veces', implode('|', webmcpFormProblems($ok . $ok)));
    contains('sin nombre', implode('|', webmcpFormProblems('<form tooldescription="Buscar en el catálogo"></form>')));
    contains('fuera de un formulario marcado', implode('|', webmcpFormProblems('<form><input name="q" toolparamdescription="Qué buscar"></form>')));
});

test('la ficha de producto marca el formulario de añadir al carrito con su nombre, su descripción y la de cada campo', function (): void {
    [$app, $web, $ids] = webmcpFormsShop();
    $body = $web->get($app->url()->entity('product', $ids['silla_roja']))->body;

    contains('toolname="add_to_cart"', $body);
    same([], webmcpFormProblems($body), 'formularios de la ficha');
    $form = frontNode($body, '//form[@toolname="add_to_cart"]');
    check($form instanceof DOMElement, 'el formulario de añadir existe');
    same('Añadir este producto al carrito. El precio es siempre el de la tienda.', $form->getAttribute('tooldescription'), 'la descripción sale del catálogo de idioma');
    foreach (['product_id', 'variant_id', 'qty'] as $field) {
        $input = frontNode($body, '//form[@toolname="add_to_cart"]//input[@name="' . $field . '"]');
        check($input instanceof DOMElement && $input->getAttribute('toolparamdescription') !== '', 'el campo ' . $field . ' tiene su descripción');
    }
});

test('una ficha con variantes marca también los radios de cada opción', function (): void {
    [$app, $web, $ids] = webmcpFormsShop();
    $body = $web->get($app->url()->entity('product', $ids['camiseta']))->body;

    $radios = frontNodes($body, '//form[@toolname="add_to_cart"]//input[@type="radio"]');
    check(count($radios) >= 2, 'hay radios de opción');
    foreach ($radios as $radio) {
        /** @var DOMElement $radio */
        check($radio->getAttribute('toolparamdescription') !== '', 'el radio ' . $radio->getAttribute('name') . ' tiene descripción');
    }
    same([], webmcpFormProblems($body));
});

test('la cabecera marca el buscador como search_products en todas las páginas y el campo q lleva su descripción', function (): void {
    [$app, $web, $ids] = webmcpFormsShop();
    foreach (['/', '/carrito', $app->url()->entity('product', $ids['mesa']), '/no-existe-esta-pagina'] as $path) {
        $body = $web->get($path)->body;
        $form = frontNode($body, '//form[@toolname="search_products"]');
        check($form instanceof DOMElement, $path . ': el buscador está marcado');
        $q = frontNode($body, '//form[@toolname="search_products"]//input[@name="q"]');
        check($q instanceof DOMElement && $q->getAttribute('toolparamdescription') !== '', $path . ': el campo q');
        same([], webmcpFormProblems($body), $path);
    }
});

test('el carrito marca una línea por cada producto, el cupón y el cálculo del envío, y ningún nombre se repite', function (): void {
    [$app, $web] = webmcpFormsShop();
    $body = $web->get('/carrito')->body;

    $names = [];
    foreach (frontNodes($body, '//form[@toolname]') as $form) {
        /** @var DOMElement $form */
        $names[] = $form->getAttribute('toolname');
    }
    foreach (['update_cart_line_1', 'update_cart_line_2', 'apply_coupon', 'calculate_shipping', 'search_products'] as $name) {
        check(in_array($name, $names, true), 'está ' . $name . ' (hay ' . implode(', ', $names) . ')');
    }
    same(count($names), count(array_unique($names)), 'ningún nombre repetido');
    same([], webmcpFormProblems($body));
    $line = frontNode($body, '//form[@toolname="update_cart_line_1"]');
    contains('Cambiar la cantidad de la línea 1 del carrito:', $line->getAttribute('tooldescription'));
    $remove = frontNodes($body, '//form[contains(@action, "/quitar")]');
    foreach ($remove as $form) {
        /** @var DOMElement $form */
        check(!$form->hasAttribute('toolname'), 'el botón de quitar no es una herramienta');
    }
});

test('la página de la tienda no repite el nombre de una herramienta, ni con dos buscadores, en ninguna página', function (): void {
    [$app, $web, $ids] = webmcpFormsShop();
    $paths = ['/', '/categoria/sillas', '/buscar?q=silla', '/buscar?q=zzzzzz', '/carrito', '/mi-cuenta/entrar', '/no-existe', $app->url()->entity('product', $ids['camiseta'])];
    foreach ($paths as $path) {
        $body = $web->get($path)->body;
        $problems = webmcpFormProblems($body);
        same([], $problems, $path);
    }
});

test('las descripciones salen del catálogo de idioma: en castellano en la tienda y con el texto de cada nombre escapado', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $app->db()->update('product_i18n', ['name' => 'Mesa "A" & <B>'], ['product_id' => $ids['mesa'], 'lang' => 'es']);
    $app->invalidate(['catalog', 'product:' . $ids['mesa']]);
    $web = new TestClient($app);
    $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 1]);

    $body = $web->get('/carrito')->body;
    contains('toolname="update_cart_line_1"', $body);
    notContains('<B>', $body, 'el nombre del producto no rompe el atributo');
    contains('Mesa &quot;A&quot; &amp; &lt;B&gt;', $body);
    same([], webmcpFormProblems($body));

    /** @var Webmcp $webmcp */
    $webmcp = $app->service('webmcp');
    foreach (Webmcp::FORMS as $form) {
        $info = $webmcp->describe($form, ['n' => 1, 'name' => 'Mesa']);
        check($info !== null && strlen($info['description']) > 20, $form . ' tiene descripción');
        check($info['params'] !== [], $form . ' describe sus campos');
        // Castellano de verdad: ninguna descripción se queda en la cadena inglesa.
        check(preg_match('/\b(the|Search the|Add this)\b/', $info['description']) !== 1 || $form === 'update_cart_line', $form . ' está traducida: ' . $info['description']);
    }
});

test('los formularios marcados no meten nada personal ni cambian la caché: la página es la misma para todos', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $path = $app->url()->entity('product', $ids['silla_roja']);

    $first = (new TestClient($app))->get($path);
    $second = (new TestClient($app))->get($path);
    same(600, $first->cacheTtl, 'la ficha se sigue guardando en la caché de página');
    same([], $first->cookies, 'la página no pone ninguna cookie');
    same($first->body, $second->body, 'el HTML es el mismo para todo el mundo');
});

test('el panel de formularios sabe en qué plantillas está cada marca', function (): void {
    $app = webmcpApp();
    /** @var Webmcp $webmcp */
    $webmcp = $app->service('webmcp');
    $placement = $webmcp->placement();

    same(Webmcp::FORMS, array_keys($placement));
    foreach (['search_products', 'add_to_cart', 'update_cart_line', 'apply_coupon', 'calculate_shipping'] as $form) {
        check($placement[$form] !== [], $form . ' está en alguna plantilla');
    }
    check(in_array('modules/catalog/views/front/product.php', $placement['add_to_cart'], true), 'add_to_cart en la ficha del catálogo');
    check(in_array('themes/base/templates/partials/header.php', $placement['search_products'], true), 'search_products en la cabecera del tema base');
});

test('un formulario que WebMCP no conoce no lleva ninguna marca y el hueco se queda vacío', function (): void {
    $app = webmcpApp();
    /** @var Webmcp $webmcp */
    $webmcp = $app->service('webmcp');

    same('', $webmcp->formAttributes('borrar_tienda'));
    same('', $webmcp->paramAttribute('borrar_tienda', 'q'));
    same('', $webmcp->paramAttribute('search_products', 'campo_que_no_existe'));
    contains('toolname="search_products"', $webmcp->formAttributes('search_products'));
    contains('toolname="update_cart_line_3"', $webmcp->formAttributes('update_cart_line', ['n' => 3, 'name' => 'Mesa']));
    same('', hook('webmcp.form', 'borrar_tienda'), 'el hueco de plantilla tampoco');
});
