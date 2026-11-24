<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\Clock;
use Aifec\Kernel\Request;
use Aifec\Kernel\VisitLog;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../orders/tests/FrontKit.php';

/*
 * Compra con enlaces y privacidad (auditoría externa del 24/11/2026): los datos de quien compra no viajan por una dirección. De serie
 * la tienda no los lee de ahí, la guía no los pone de ejemplo ni los pide, y ni el registro de visitas ni la caché de páginas los
 * guardan. Los datos de ejemplo son inventados.
 */

const PRIVACY_NAME = 'Ana Ruiz';
const PRIVACY_PHONE = '999000111';
const PRIVACY_EMAIL = 'ana.ruiz@cliente.test';
const PRIVACY_STREET = 'Calle de Ejemplo 1';
const PRIVACY_TEXT = 'Ana Ruiz, Calle de Ejemplo 1, 28013 Madrid, 999000111, ana.ruiz@cliente.test';

/** Abre una dirección como un lector que solo abre enlaces (la respuesta, ya descodificada si es JSON). */
function privacyOpen(\Aifec\Kernel\App $app, string $path, array $headers = ['accept' => 'application/json']): \Aifec\Kernel\Response
{
    $request = Request::create('GET', $path, [], [], $headers);
    $request->host = 'tienda.test';
    $request->secure = true;
    $request->ip = '127.0.0.1';

    return $app->handle($request);
}

/** Ninguna cadena de datos personales de la prueba, en ninguna forma (tal cual, codificada o sin acentos). */
function privacyLeaks(string $text): array
{
    $found = [];
    foreach ([PRIVACY_NAME, PRIVACY_PHONE, PRIVACY_EMAIL, PRIVACY_STREET, rawurlencode(PRIVACY_EMAIL), rawurlencode(PRIVACY_NAME), urlencode(PRIVACY_NAME), urlencode(PRIVACY_STREET)] as $needle) {
        if (stripos($text, $needle) !== false) {
            $found[] = $needle;
        }
    }

    return $found;
}

test('la guía no lleva ejemplos con datos del comprador ni los pide por la dirección', function (): void {
    $app = apiApp();
    foreach (['/buy-with-links'] as $path) {
        foreach ([['accept' => 'application/json'], []] as $headers) {
            $r = privacyOpen($app, $path, $headers);
            same(200, $r->status, $path);
            foreach (['STREET AND NUMBER', 'POSTCODE CITY', 'PHONE, EMAIL', 'text=NAME', 'put their own words in text'] as $bad) {
                notContains($bad, $r->body, $path . ': no pone «' . $bad . '»');
            }
        }
    }
    $start = privacyOpen($app, '/buy-with-links/start');
    $cart = json_decode($start->body, true)['data']['cart'] ?? '';
    check(is_string($cart) && strlen($cart) === 48, 'se crea un carrito');
    $product = $app->db()->value('SELECT id FROM {products} ORDER BY id');
    if ($product === null) {
        aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 1999]);
        $product = $app->db()->value('SELECT id FROM {products} ORDER BY id');
    }
    foreach (['/buy-with-links/add?product_id=' . $product . '&qty=1', '/buy-with-links/cart', '/buy-with-links/status'] as $path) {
        $r = privacyOpen($app, $path);
        foreach (['STREET AND NUMBER', 'text=NAME', 'POSTCODE CITY'] as $bad) {
            notContains($bad, $r->body, $path);
        }
    }
});

test('si alguien escribe datos personales en la dirección, la tienda no los guarda, no los devuelve y lo dice', function (): void {
    $app = apiApp();
    $product = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 1999]);
    $r = privacyOpen($app, '/buy-with-links/add?product_id=' . $product . '&qty=1&text=' . rawurlencode(PRIVACY_TEXT));
    same(200, $r->status);
    $data = json_decode($r->body, true);
    check(!empty($data['data']['ok']) || !empty($data['data']['added']) || isset($data['data']['cart']), 'el producto sí se añade');
    check(isset($data['notice']) && stripos((string) $data['notice'], 'personal') !== false, 'la respuesta avisa de que no se guardan datos escritos en la dirección');
    same([], privacyLeaks($r->body), 'ningún dato del comprador vuelve en la respuesta ni en las direcciones siguientes');
    $cart = (string) ($data['data']['cart'] ?? '');
    $status = privacyOpen($app, '/buy-with-links/status/' . $cart);
    $missing = json_decode($status->body, true)['data']['missing_fields'] ?? [];
    check($missing !== [], 'quien compra sigue sin estar guardado: falta ' . json_encode($missing));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {customers} WHERE email = ?", [PRIVACY_EMAIL]), 'no se ha creado ningún cliente');

    // Los campos sueltos (correo, teléfono, calle…) tampoco.
    $one = privacyOpen($app, '/buy-with-links/customer/' . $cart . '?email=' . rawurlencode(PRIVACY_EMAIL) . '&phone=' . PRIVACY_PHONE . '&first_name=Ana&last_name=Ruiz&line1=' . rawurlencode(PRIVACY_STREET) . '&postcode=28013&city=Madrid');
    same(200, $one->status);
    same([], privacyLeaks($one->body), 'los campos sueltos no se devuelven');
    $after = json_decode(privacyOpen($app, '/buy-with-links/status/' . $cart)->body, true)['data']['missing_fields'] ?? [];
    check($after !== [], 'y siguen sin guardarse');
});

test('una talla o un color escritos en la dirección sí pasan: no son datos de nadie', function (): void {
    $app = apiApp();
    $product = aifecProduct($app, ['name' => 'Zapatilla', 'slug' => 'zapatilla', 'price' => 4900]);
    $r = privacyOpen($app, '/buy-with-links/add?product_id=' . $product . '&qty=1&text=' . rawurlencode('talla 42, color azul'));
    same(200, $r->status);
    $data = json_decode($r->body, true);
    check(!isset($data['notice']), 'una talla no activa el aviso de datos personales');
});

test('las páginas con datos en la dirección no se guardan en la caché ni llevan referer, y el registro de visitas solo apunta la ruta', function (): void {
    $app = apiApp();
    $product = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 1999]);
    $path = '/buy-with-links/add?product_id=' . $product . '&qty=1&text=' . rawurlencode(PRIVACY_TEXT);
    $r = privacyOpen($app, $path);
    same(0, $r->cacheTtl, 'la respuesta no se puede guardar en la caché de página');
    contains('no-store', (string) ($r->headers['Cache-Control'] ?? ''));
    same('no-referrer', $r->headers['Referrer-Policy'] ?? '', 'sin referer hacia otros sitios');
    $page = privacyOpen($app, $path, []);
    same('no-referrer', $page->headers['Referrer-Policy'] ?? '', 'la página de texto también');
    // El registro de visitas de los robots: la línea lleva la ruta, nunca la consulta.
    $request = Request::create('GET', $path, [], [], ['user-agent' => 'ChatGPT-User/1.0']);
    VisitLog::record($app->site(), $request, 200, false, new Clock($app->clock()->now()));
    $log = (string) file_get_contents($app->site()->path('logs') . '/visits-' . gmdate('Y-m-d', $app->clock()->now()) . '.tsv');
    contains('/buy-with-links/add', $log, 'la visita se apunta');
    same([], privacyLeaks($log), 'sin ningún dato del comprador');
    notContains('text=', $log, 'ni la consulta');
});

test('el formulario de la persona manda sus datos por POST: se guardan y no pasan por ninguna dirección', function (): void {
    $app = apiApp();
    $product = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 1999]);
    $added = json_decode(privacyOpen($app, '/buy-with-links/add?product_id=' . $product . '&qty=1')->body, true);
    $cart = (string) $added['data']['cart'];
    $form = privacyOpen($app, '/buy-with-links/customer/' . $cart, ['accept' => 'text/html']);
    contains('<form method="post"', $form->body);
    notContains('method="get"', $form->body);

    $post = Request::create('POST', '/buy-with-links/customer/' . $cart, [
        'first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => PRIVACY_STREET, 'postcode' => '28013', 'city' => 'Madrid',
        'phone' => PRIVACY_PHONE, 'email' => PRIVACY_EMAIL, 'country' => 'ES',
    ], [], ['accept' => 'application/json']);
    $post->host = 'tienda.test';
    $post->secure = true;
    $post->ip = '127.0.0.1';
    $r = $app->handle($post);
    same(200, $r->status);
    contains('no-store', (string) ($r->headers['Cache-Control'] ?? ''));
    $status = json_decode(privacyOpen($app, '/buy-with-links/status/' . $cart)->body, true);
    $missing = $status['data']['missing_fields'] ?? ['?'];
    same([], array_values(array_diff($missing, ['shipping_method', 'payment_method'])), 'quién compra queda guardado: ' . json_encode($missing));
    // Y ninguna de las direcciones siguientes lleva esos datos.
    foreach (($status['next'] ?? []) as $link) {
        same([], privacyLeaks((string) $link['url']), 'la dirección «' . $link['do'] . '» no lleva datos del comprador');
    }
});

test('con el ajuste encendido (la tienda lo decide) el flujo antiguo sigue guardando los datos, y de serie está apagado', function (): void {
    $fields = [];
    foreach ((array) (require dirname(__DIR__) . '/settings.php')['tabs'] as $tab) {
        $fields += $tab['fields'];
    }
    same(false, $fields['api.links.details_in_address']['default'], 'apagado de serie');
    $app = apiApp(['api.links.details_in_address' => true]);
    $product = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 1999]);
    $r = privacyOpen($app, '/buy-with-links/add?product_id=' . $product . '&qty=1&text=' . rawurlencode(PRIVACY_TEXT));
    $data = json_decode($r->body, true);
    check(!isset($data['notice']), 'encendido, no hay aviso');
});
