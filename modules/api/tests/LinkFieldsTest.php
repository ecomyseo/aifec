<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * «Compra con enlaces» documenta cada campo de cada paso (nombre, tipo, si es obligatorio, ejemplo con datos inventados) y cada código
 * de error con lo que hay que hacer: en la propia página, en el documento OpenAPI y en el llms.txt, desde una sola fuente.
 */

declare(strict_types=1);

use Aifec\Modules\Api\LinkFields;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../orders/tests/FrontKit.php';

/** @param array<string,mixed> $ajustes */
function fldApp(array $ajustes = [], array $modulos = []): \Aifec\Kernel\App
{
    return apiApp($ajustes + ['api.links.details_in_address' => true], $modulos);
}

/** @return array{0:int,1:array<string,mixed>,2:\Aifec\Kernel\Response} */
function fldOpen(\Aifec\Kernel\App $app, string $url, bool $json = true): array
{
    $request = \Aifec\Kernel\Request::create('GET', (string) preg_replace('#^https?://[^/]+#', '', $url), [], [], $json ? ['accept' => 'application/json'] : []);
    $request->host = 'tienda.test';
    $request->secure = true;
    $request->ip = '127.0.0.1';
    $r = $app->handle($request);
    $data = json_decode($r->body, true);

    return [$r->status, is_array($data) ? $data : [], $r];
}

test('la página de la guía trae cada campo de cada paso con tipo, obligatoriedad y ejemplo, y los errores con qué hacer', function (): void {
    $app = fldApp([], ['semantic']);
    assistantShop($app);
    [$status, $guide] = fldOpen($app, 'https://tienda.test/buy-with-links');
    same(200, $status);
    $data = $guide['data'];
    foreach (['add', 'customer', 'shipping', 'payment', 'code', 'place'] as $step) {
        check(isset($data['fields'][$step]) && $data['fields'][$step] !== [], 'el paso «' . $step . '» tiene sus campos');
    }
    foreach ($data['fields'] as $step => $fields) {
        foreach ($fields as $name => $field) {
            foreach (['type', 'required', 'example', 'note'] as $key) {
                check(isset($field[$key]) && $field[$key] !== '', $step . '.' . $name . ' lleva «' . $key . '»');
            }
        }
    }
    same(true, $data['fields']['customer']['email']['required']);
    same(true, $data['fields']['customer']['first_name']['required']);
    same('if the order ships', $data['fields']['customer']['line1']['required'], 'la dirección solo si hay envío');
    same(false, $data['fields']['customer']['company']['required']);
    same(true, $data['fields']['place']['buyer_code']['required']);
    $statuses = [];
    foreach ($data['errors'] as $error) {
        check($error['when'] !== '' && $error['do'] !== '', 'el error ' . $error['status'] . ' dice cuándo sale y qué hacer');
        $statuses[] = $error['status'];
    }
    foreach ([400, 401, 403, 404, 409, 422, 429] as $code) {
        check(in_array($code, $statuses, true), 'documenta el estado ' . $code);
    }
    // La página de texto (la que abre un lector de páginas) también lo lleva.
    [, , $page] = fldOpen($app, 'https://tienda.test/buy-with-links', false);
    $text = html_entity_decode($page->body, ENT_QUOTES);
    contains('"first_name"', $text);
    contains('if the order ships', $text);
    contains('"status": 429', $text);
});

test('todo parámetro que acepta el paso «customer» está documentado, y nada documentado se queda sin aceptar', function (): void {
    $accepted = (new ReflectionClassConstant(\Aifec\Modules\Api\BrowseController::class, 'TOOLS'))->getValue()['customer'][1];
    $documented = array_keys(LinkFields::steps()['customer']);
    sort($accepted);
    sort($documented);
    same($accepted, $documented, 'lo que acepta el paso y lo que dice la documentación son lo mismo');
    foreach (['carrier_id' => 'shipping', 'method' => 'payment'] as $param => $step) {
        check(isset(LinkFields::steps()[$step][$param]), $step . '.' . $param . ' documentado');
    }
});

test('los ejemplos son inventados: ni un teléfono, un correo o un NIF que puedan ser de alguien', function (): void {
    foreach (LinkFields::steps() as $step => $fields) {
        foreach ($fields as $name => $field) {
            $example = $field['example'];
            if ($name === 'email') {
                check(preg_match('/@[a-z.]*\.test$/', $example) === 1, $step . '.' . $name . ' usa un dominio .test');
            }
            if ($name === 'phone') {
                check(preg_match('/^\+44 7700 9{1}0{2}[0-9]{3}$/', $example) === 1, 'el teléfono es del rango reservado para la ficción');
            }
            if ($name === 'vat_id') {
                check(in_array($example, ['B12345674', '12345678Z', 'X1234567L'], true), 'el NIF es de ejemplo');
            }
        }
    }
    check(preg_match('/(?<![0-9])[6789][0-9]{8}(?![0-9])/', (string) json_encode(LinkFields::steps())) !== 1, 'ningún móvil español de verdad');
});

test('el documento OpenAPI y el llms.txt llevan los campos del paso «customer», lo obligatorio de cada paso y los errores', function (): void {
    $app = fldApp([], ['semantic']);
    assistantShop($app);
    $doc = apiJson(apiCall($app, 'GET', '/api/openapi.json'));
    $params = [];
    foreach ($doc['paths']['/go/{step}']['get']['parameters'] as $param) {
        $params[$param['name']] = $param;
    }
    foreach (['email', 'first_name', 'last_name', 'phone', 'line1', 'postcode', 'city', 'country', 'vat_id'] as $name) {
        check(isset($params[$name]), 'el parámetro «' . $name . '» sale en el documento');
        contains('example:', (string) $params[$name]['description'], $name . ' con su ejemplo');
    }
    same(false, $params['email']['required'], 'el paso genérico no obliga a un campo de un paso concreto');
    contains('required for this step', (string) $params['email']['description']);
    contains('if the order ships', (string) $params['line1']['description']);
    contains('Buying with links: the fields of each step', (string) $doc['info']['description']);
    contains('Buying with links: the errors', (string) $doc['info']['description']);
    contains('429: Too many requests', (string) $doc['info']['description']);

    $llms = (new TestClient($app))->get('/llms.txt')->body;
    contains('Required fields by step', $llms);
    contains('customer: email, first_name', $llms, 'lo obligatorio del paso de los datos');
    contains('place: confirm_token, buyer_confirmed, buyer_code', $llms);
    contains('Errors: 400, 401, 403, 404, 409, 422, 429', $llms);
});
