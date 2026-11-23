<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El servicio Webmcp por dentro: el esquema listo para JSON, qué herramientas solo leen, las descripciones de los
 * formularios y el módulo declarado como debe (manifiesto, catálogo de idioma, cabeceras de ficheros).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Webmcp\Webmcp;

require_once __DIR__ . '/helpers.php';

test('Webmcp::schema deja las propiedades vacías como objeto y respeta las anidadas', function (): void {
    $empty = Webmcp::schema(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);
    same('{"type":"object","properties":{},"additionalProperties":false}', json_encode($empty));

    $nested = Webmcp::schema(['type' => 'object', 'properties' => [
        'lista' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]],
        'uno' => ['type' => 'object', 'properties' => []],
    ]]);
    same('{"type":"object","properties":{"lista":{"type":"array","items":{"type":"object","properties":{}}},"uno":{"type":"object","properties":{}}}}', json_encode($nested));
    same('{"type":"string"}', json_encode(Webmcp::schema(['type' => 'string'])));
});

test('Webmcp::readOnly: lo que escribe o lo que guarda en la caja no es de solo lectura', function (): void {
    $app = webmcpApp();
    /** @var Webmcp $webmcp */
    $webmcp = $app->service('webmcp');

    same(true, $webmcp->readOnly('search_products', false));
    same(true, $webmcp->readOnly('checkout_status', false));
    same(false, $webmcp->readOnly('checkout_set_customer', false), 'guarda datos');
    same(false, $webmcp->readOnly('checkout_review', false), 'deja la caja esperando');
    same(false, $webmcp->readOnly('cart_add', true));
    same(false, $webmcp->readOnly('search_products', true), 'si la tabla dice que escribe, escribe');
});

test('las excluidas son solo checkout_place y los formularios que sabe marcar son los diez del catálogo de idioma', function (): void {
    same(['checkout_place'], Webmcp::EXCLUDED);
    same(['search_products', 'add_to_cart', 'update_cart_line', 'apply_coupon', 'calculate_shipping', 'sign_in', 'subscribe_newsletter', 'send_contact_message', 'submit_review', 'ask_shop_assistant'], Webmcp::FORMS);
    $app = webmcpApp();
    /** @var Webmcp $webmcp */
    $webmcp = $app->service('webmcp');
    foreach (Webmcp::FORMS as $form) {
        $info = $webmcp->describe($form, ['n' => 2, 'name' => 'Mesa']);
        check($info !== null, $form . ' se describe');
        foreach ($info['params'] as $field => $text) {
            check(strlen($text) >= 4, $form . '.' . $field . ' tiene descripción');
        }
    }
    same(null, $webmcp->describe('no_existe'));
});

test('pedir solo el módulo webmcp trae el del asistente, que es de donde salen las herramientas', function (): void {
    $app = testApp(['modules' => ['webmcp']]);
    $app->boot();

    check($app->has('tools'), 'el servicio de herramientas está porque el módulo lo exige');
    check($app->has('webmcp'), 'y el de WebMCP');
    $web = new TestClient($app);
    $r = $web->get('/webmcp/tools.json');
    same(200, $r->status);
    check(count(json_decode($r->body, true)['tools']) >= 9, 'publica las herramientas');
});

test('el módulo está declarado como los demás: manifiesto, ajuste por defecto encendido, catálogo en castellano y sin tablas', function (): void {
    $dir = dirname(__DIR__);
    $manifest = json_decode((string) file_get_contents($dir . '/module.json'), true);
    same('WebMCP', $manifest['name']);
    same(['assistant'], $manifest['requires']['modules']);
    same([], $manifest['contract']['tables'], 'no crea tablas');
    same([], $manifest['contract']['hosts'], 'no llama a ningún servidor de fuera');
    same([], $manifest['contract']['personal_data'], 'no guarda datos personales');

    $settings = require $dir . '/settings.php';
    same(true, $settings['tabs']['wmgeneral']['fields']['webmcp.enabled']['default'], 'encendido de fábrica');

    $es = require $dir . '/lang/es.php';
    check(count($es) > 50, 'el catálogo tiene sus cadenas');
    foreach ($es as $source => $target) {
        check($target !== '' && ($target !== $source || in_array($source, ['WebMCP', 'General'], true)), 'la cadena «' . mb_substr($source, 0, 40, 'UTF-8') . '» está traducida');
    }
});

test('todos los ficheros PHP del módulo llevan la cabecera exacta y ninguna etiqueta de cierre', function (): void {
    $dir = dirname(__DIR__);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $count = 0;
    foreach ($it as $file) {
        $path = str_replace('\\', '/', (string) $file);
        if (substr($path, -4) !== '.php') {
            continue;
        }
        $count++;
        $code = (string) file_get_contents($path);
        $name = substr($path, strlen($dir) + 1);
        check(strpos($code, "<?php\n/**\n * AIFEC — AI-First E-Commerce.\n") === 0, $name . ': cabecera');
        check(strpos($code, '@author    Ecom Experts <ecomyseo@gmail.com>') !== false, $name . ': autor');
        check(strpos($code, "\r") === false, $name . ': saltos de línea LF');
        check(substr(rtrim($code, "\n"), -2) !== '?>', $name . ': sin etiqueta de cierre');
    }
    check($count >= 10, 'se revisaron los ficheros (' . $count . ')');
});
