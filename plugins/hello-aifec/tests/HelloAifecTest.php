<?php
/**
 * AIFEC — pruebas de Hello AIFEC: se activa, hace lo suyo (dato tipado, hueco, ajuste, campo del panel), se desactiva y
 * pasa el contrato.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once dirname(__DIR__, 3) . '/modules/catalog/tests/helpers.php';

/**
 * Sitio de pruebas con el catálogo, el gestor de plugins y Hello AIFEC activo.
 *
 * @param array<string,mixed> $settings
 */
function helloSite(array $settings = []): App
{
    $app = testApp(['modules' => ['plugins', 'catalog', 'hello-aifec'], 'settings' => $settings + ['modules.active' => ['plugins', 'catalog', 'hello-aifec']]]);
    $app->migrate(true);
    $app->boot();

    return $app;
}

/** @return array<string,mixed> */
function helloExtra(App $app, int $product): array
{
    $data = aifecCatalog($app)->product($product);
    check(is_array($data), 'el producto existe');

    return $data['extra'];
}

test('hello-aifec se activa y se carga', function (): void {
    $app = helloSite();
    check($app->modules()->isLoaded('hello-aifec'), 'el plugin está cargado');
    check(!isset($app->modules()->failed()['hello-aifec']), 'no falló al cargar');
    check($app->db()->tableExists('hello_aifec_made_in'), 'su migración creó la tabla');
});

test('el dato «Hecho en» sale en el producto con su etiqueta, su tipo y si es público', function (): void {
    $app = helloSite();
    $id = aifecProduct($app, ['name' => 'Silla']);
    (new Plugins\HelloAifec\MadeIn($app))->set($id, 'España');
    $extra = helloExtra($app, $id);
    same('España', $extra['made_in']['value']);
    same('Fabricado en', $extra['made_in']['label']);
    same('text', $extra['made_in']['type']);
    same(true, $extra['made_in']['public']);
});

test('un producto sin dato y sin ajuste no lleva «made_in»', function (): void {
    $app = helloSite();
    $id = aifecProduct($app, ['name' => 'Mesa']);
    check(!isset(helloExtra($app, $id)['made_in']), 'no hay dato');
});

test('el ajuste da el país por defecto a los productos que no tienen el suyo', function (): void {
    $app = helloSite(['hello_aifec.default_country' => 'Portugal']);
    $own = aifecProduct($app, ['name' => 'Cama']);
    $other = aifecProduct($app, ['name' => 'Lámpara']);
    (new Plugins\HelloAifec\MadeIn($app))->set($own, 'Italia');
    same('Italia', helloExtra($app, $own)['made_in']['value']);
    same('Portugal', helloExtra($app, $other)['made_in']['value']);
});

test('el hueco de la ficha pinta el país y escapa lo que viene de los datos', function (): void {
    $app = helloSite();
    $id = aifecProduct($app, ['name' => 'Estantería']);
    (new Plugins\HelloAifec\MadeIn($app))->set($id, 'Pérez & <Hijos>');
    $html = $app->hooks()->render('product.summary.after', ['id' => $id]);
    contains('Fabricado en: Pérez &amp; &lt;Hijos&gt;', $html);
    notContains('<Hijos>', $html);
    same('', $app->hooks()->render('product.summary.after', ['id' => 0]));
});

test('el campo del panel sale de verdad en la ficha del catálogo real: `form` es una función, se envuelve y su resultado lleva el campo', function (): void {
    $app = helloSite();
    $id = aifecProduct($app, ['name' => 'Sofá']);

    // El recurso real: «form» es una función (App, ?int) y no un array.
    $definitions = $app->adminDefinitions();
    check(is_callable($definitions['catalog']['resources']['products']['form']), 'el formulario de productos del catálogo es una función');
    $form = $definitions['catalog']['resources']['products']['form']($app, $id);
    $field = $form['fields']['hello_aifec_made_in'];
    same('text', $field['type']);
    same(false, $field['store']);
    same('', $field['default'], 'sin país guardado, el campo sale vacío (no con el del ajuste)');
    check(isset($form['fields']['price']), 'los campos del catálogo siguen ahí');
    same(['x' => 1], $app->hooks()->apply('admin.definitions', ['x' => 1], $app), 'sin el recurso de productos no toca nada');

    // Y en la pantalla que abre el administrador, con su nombre de campo.
    $web = adminClient($app);
    $page = $web->get('/admin/r/products/' . $id);
    same(200, $page->status);
    contains('name="f[hello_aifec_made_in]"', $page->body);
});

test('el campo del panel se guarda en la tabla del plugin, vuelve a salir puesto al abrir la ficha y se pinta en la tienda', function (): void {
    $app = helloSite();
    $id = aifecProduct($app, ['name' => 'Sofá']);
    $app->request()->post = ['f' => ['hello_aifec_made_in' => ' Francia ']];
    $app->hooks()->fire('admin.resource.saved', 'products', $id, [], null);
    same('Francia', (string) $app->db()->value('SELECT country FROM {hello_aifec_made_in} WHERE product_id = ?', [$id]));

    $form = $app->adminDefinitions()['catalog']['resources']['products']['form']($app, $id);
    same('Francia', $form['fields']['hello_aifec_made_in']['default'], 'al abrir la ficha el campo sale con lo guardado');
    $web = adminClient($app);
    contains('value="Francia"', $web->get('/admin/r/products/' . $id)->body);

    $app->invalidate(['catalog']);
    same('Francia', helloExtra($app, $id)['made_in']['value'], 'y se pinta en la tienda');
    contains('Fabricado en: Francia', $app->hooks()->render('product.summary.after', ['id' => $id]));

    $app->request()->post = ['f' => ['hello_aifec_made_in' => '']];
    $app->hooks()->fire('admin.resource.saved', 'products', $id, [], null);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {hello_aifec_made_in} WHERE product_id = ?', [$id]));
});

test('hello-aifec se desactiva y el dato deja de salir', function (): void {
    $app = helloSite();
    $id = aifecProduct($app, ['name' => 'Banco']);
    (new Plugins\HelloAifec\MadeIn($app))->set($id, 'Grecia');
    $r = $app->service('plugins')->disable('hello-aifec');
    same(true, $r['ok'], (string) $r['message']);
    check(!in_array('hello-aifec', $app->settings()->arr('modules.active'), true), 'ya no está en modules.active');
    $off = testApp(['modules' => ['plugins', 'catalog', 'hello-aifec'], 'settings' => ['modules.active' => $app->settings()->arr('modules.active')]]);
    $off->boot();
    check(!$off->modules()->isLoaded('hello-aifec'), 'no se carga');
});

test('hello-aifec pasa el contrato AIFEC, también el ciclo entero de instalar y desinstalar', function (): void {
    $app = helloSite();
    $plugins = $app->service('plugins');
    $plugins->useTempBase(tmpDir());
    $report = $plugins->validate('hello-aifec');
    $errors = [];
    foreach ($report['checks'] as $check) {
        if (!$check['ok'] && $check['level'] === 'error') {
            $errors[] = $check['rule'] . ': ' . $check['message'] . ' ' . $check['file'] . ':' . $check['line'];
        }
    }
    same([], $errors);
    same(true, $report['ok']);
    same(true, $report['dynamic']);
    same(100, $report['score']);
});
