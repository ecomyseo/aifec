<?php
/**
 * AIFEC — pruebas de GM AI notice: el aviso sale donde debe (ficha, páginas, pie), respeta los ajustes y las marcas del módulo
 * de IA, escapa lo que escribe el comerciante, se apaga limpio y pasa el contrato.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once dirname(__DIR__, 3) . '/modules/catalog/tests/helpers.php';

/**
 * Sitio de pruebas con el catálogo, el gestor de plugins y el plugin activo.
 *
 * @param array<string,mixed> $settings
 * @param string[]            $extra    otros módulos que hay que activar
 */
function gmAiaSite(array $settings = [], array $extra = []): App
{
    $active = array_merge(['plugins', 'catalog', 'tax', 'gm-aviso-ia'], $extra);
    $app = testApp(['modules' => $active, 'settings' => $settings + ['modules.active' => $active]]);
    $app->migrate(true);
    $app->boot();

    return $app;
}

/** La ficha de un producto tal como la reciben los huecos. @return array<string,mixed> */
function gmAiaProduct(App $app, array $data = []): array
{
    $id = aifecProduct($app, $data + ['name' => 'Silla']);
    $product = aifecCatalog($app)->product($id);
    check(is_array($product), 'el producto existe');

    return $product;
}

test('gm-aviso-ia se activa y se carga sin fallos', function (): void {
    $app = gmAiaSite();
    check($app->modules()->isLoaded('gm-aviso-ia'), 'el plugin está cargado');
    check(!isset($app->modules()->failed()['gm-aviso-ia']), 'no falló al cargar');
    check($app->router()->has('gm.aviso_ia'), 'su página explicativa tiene ruta');
});

test('el aviso sale bajo el resumen de la ficha y no al final con el ajuste de serie', function (): void {
    $app = gmAiaSite();
    $product = gmAiaProduct($app);
    $summary = $app->hooks()->render('product.summary.after', $product);
    contains('gm-aviso-ia--product-summary', $summary);
    contains('Textos redactados con ayuda de inteligencia artificial', $summary);
    contains('href="/textos-con-ia"', $summary);
    same('', $app->hooks()->render('product.bottom', $product));
});

test('con «en los dos sitios» sale bajo el resumen y al final, y apagado no sale en ninguno', function (): void {
    $app = gmAiaSite(['gm_aviso_ia.placement' => 'both']);
    $product = gmAiaProduct($app);
    contains('gm-aviso-ia--product-summary', $app->hooks()->render('product.summary.after', $product));
    contains('gm-aviso-ia--product-bottom', $app->hooks()->render('product.bottom', $product));

    $off = gmAiaSite(['gm_aviso_ia.placement' => 'both', 'gm_aviso_ia.enabled' => false]);
    $other = gmAiaProduct($off);
    same('', $off->hooks()->render('product.summary.after', $other));
    same('', $off->hooks()->render('product.bottom', $other));
});

test('los textos del comerciante se escapan y los vacíos usan la redacción de serie', function (): void {
    $app = gmAiaSite(['gm_aviso_ia.title' => 'Hecho por <IA> & Pérez', 'gm_aviso_ia.text' => 'Revisado "a mano" & con cariño']);
    $html = $app->hooks()->render('product.summary.after', gmAiaProduct($app));
    contains('Hecho por &lt;IA&gt; &amp; Pérez', $html);
    contains('Revisado &quot;a mano&quot; &amp; con cariño', $html);
    notContains('<IA>', $html);
});

test('el filtro de categorías deja el aviso solo en los productos de esas categorías', function (): void {
    $app = gmAiaSite();
    $in = aifecCategory($app, 'Sillas');
    $out = aifecCategory($app, 'Mesas');
    $app->settings()->set('gm_aviso_ia.categories', (string) $in . ', 9999');
    $withCategory = gmAiaProduct($app, ['name' => 'Silla roja', 'categories' => [$in]]);
    $other = gmAiaProduct($app, ['name' => 'Mesa baja', 'categories' => [$out]]);
    contains('gm-aviso-ia', $app->hooks()->render('product.summary.after', $withCategory));
    same('', $app->hooks()->render('product.summary.after', $other));
});

test('con «solo lo marcado» y el módulo de IA, el aviso sale solo donde hay una marca aprobada', function (): void {
    $app = gmAiaSite(['gm_aviso_ia.scope' => 'marked', 'gm_aviso_ia.placement' => 'both'], ['ai', 'jobs']);
    check($app->modules()->isLoaded('ai'), 'el módulo de IA está cargado');
    $marked = gmAiaProduct($app, ['name' => 'Con marca']);
    $plain = gmAiaProduct($app, ['name' => 'Sin marca']);
    $app->db()->insert('ai_marks', ['entity' => 'product', 'entity_id' => $marked['id'], 'field' => 'description', 'lang' => 'es', 'user_id' => null, 'model' => 'fake', 'created_at' => $app->clock()->sql()]);
    contains('gm-aviso-ia--product-summary', $app->hooks()->render('product.summary.after', $marked));
    same('', $app->hooks()->render('product.summary.after', $plain));

    // Si el módulo de IA ya pone su propia marca al final, el plugin no la repite en ese sitio.
    $app->settings()->set('ai.show_notice', true);
    notContains('gm-aviso-ia', $app->hooks()->render('product.bottom', $marked));
    contains('gm-aviso-ia--product-summary', $app->hooks()->render('product.summary.after', $marked));
});

test('el aviso de las páginas y el del pie salen en el hueco del pie, y solo en páginas y entradas', function (): void {
    $app = gmAiaSite(['gm_aviso_ia.footer' => true, 'gm_aviso_ia.footer_text' => 'Textos con IA & revisión']);
    $page = ['_prepared' => true, 'type' => 'page', 'entity' => ['type' => 'page', 'id' => 7]];
    $app->hooks()->apply('page.meta', $page, $app);
    $html = $app->hooks()->render('footer.before');
    contains('gm-aviso-ia--page', $html);
    contains('Textos con IA &amp; revisión', $html);

    $product = ['_prepared' => true, 'type' => 'product', 'entity' => ['type' => 'product', 'id' => 7]];
    $app->request()->setAttr('gm_aviso_ia.entity', null);
    $app->hooks()->apply('page.meta', $product, $app);
    $html = $app->hooks()->render('footer.before');
    notContains('gm-aviso-ia--page', $html);
    contains('gm-aviso-ia-footer', $html);
});

test('la página explicativa responde 200, se guarda en la caché y enseña el texto', function (): void {
    $app = gmAiaSite();
    $web = new TestClient($app);
    $r = $web->get('/textos-con-ia');
    same(200, $r->status);
    contains('Cómo se redactan los textos de esta tienda', $r->body);
    check($r->cacheTtl > 0, 'es cacheable');
    contains('Qué redacta la IA', $r->body);
});

test('gm-aviso-ia se desactiva y deja de pintar', function (): void {
    $app = gmAiaSite();
    $product = gmAiaProduct($app);
    contains('gm-aviso-ia', $app->hooks()->render('product.summary.after', $product));
    $r = $app->service('plugins')->disable('gm-aviso-ia');
    same(true, $r['ok'], (string) $r['message']);
    $off = testApp(['modules' => ['plugins', 'catalog', 'tax', 'gm-aviso-ia'], 'settings' => ['modules.active' => $app->settings()->arr('modules.active')]]);
    $off->boot();
    check(!$off->modules()->isLoaded('gm-aviso-ia'), 'no se carga');
});

test('gm-aviso-ia pasa el contrato AIFEC, también el ciclo de instalar y desinstalar', function (): void {
    $app = gmAiaSite();
    $plugins = $app->service('plugins');
    $plugins->useTempBase(tmpDir());
    $report = $plugins->validate('gm-aviso-ia');
    $errors = [];
    foreach ($report['checks'] as $check) {
        if (!$check['ok'] && $check['level'] === 'error') {
            $errors[] = $check['rule'] . ': ' . $check['message'] . ' ' . $check['file'] . ':' . $check['line'];
        }
    }
    same([], $errors);
    same(true, $report['ok']);
    same(true, $report['dynamic']);
});
