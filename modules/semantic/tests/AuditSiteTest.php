<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Cms\Legal;
use Aifec\Modules\Semantic\Audit;
use Aifec\Modules\Semantic\AuditService;
use Aifec\Modules\Semantic\Semantic;

require_once __DIR__ . '/helpers.php';

/*
 * La auditoría del sitio: la nota es 100 menos el peso de lo que falla, y cada comprobación dice si se cumple y dónde se arregla.
 */

/**
 * @return array<string,array<string,mixed>> comprobaciones del sitio por código
 */
function auditSiteChecks(App $app): array
{
    $out = [];
    foreach ($app->service('semantic')->audit()['checks'] as $check) {
        $out[$check['id']] = $check;
    }

    return $out;
}

test('el servicio semantic es el de siempre con la auditoría añadida', function (): void {
    $app = semanticApp();
    $service = $app->service('semantic');

    check($service instanceof Semantic, 'sigue siendo un Semantic');
    check($service instanceof AuditService, 'con audit() y auditProduct()');
    same(100, array_sum(Audit::SITE), 'los pesos del sitio suman 100');
});

test('sin devoluciones definidas falla esa comprobación y al definirlas pasa', function (): void {
    $app = semanticApp();
    $checks = auditSiteChecks($app);
    same(false, $checks['returns']['ok']);
    same('La política de devoluciones está definida', $checks['returns']['label']);
    same('/admin/settings/semantic#tab=smreturns', $checks['returns']['fix_url']);
    same(10, $checks['returns']['weight']);

    $app->settings()->set('semantic.returns.days', 14);
    same(true, auditSiteChecks($app)['returns']['ok']);
});

test('la nota es 100 menos el peso de lo que falla y arreglar algo la sube', function (): void {
    $app = semanticApp();
    $audit = $app->service('semantic')->audit();
    $perdido = 0;
    foreach ($audit['checks'] as $check) {
        if (!$check['ok']) {
            $perdido += $check['weight'];
        }
    }
    same(100 - $perdido, $audit['score']);
    check(!isset(auditSiteChecks($app)['sitemap']), 'sin el módulo SEO el mapa del sitio no se pide');

    $app->settings()->set('semantic.returns.days', 30);
    $app->settings()->set('store.email', 'tienda@ejemplo.test');
    same($audit['score'] + 10 + 4, $app->service('semantic')->audit()['score'], 'devoluciones (10) y correo (4)');
});

test('los datos de la empresa piden razón social, teléfono, dirección y logotipo', function (): void {
    $app = semanticApp();
    same(false, auditSiteChecks($app)['organization']['ok']);
    same('/admin/settings/core', auditSiteChecks($app)['organization']['fix_url']);

    $app->settings()->setMany(['store.legal_name' => 'Maderas Norte S.L.', 'store.phone' => '999000001', 'store.address' => 'Calle Mayor 1']);
    same(false, auditSiteChecks($app)['organization']['ok'], 'sin logotipo todavía falta');

    $app->settings()->set('semantic.logo', aifecMedia($app, 'Logotipo'));
    same(true, auditSiteChecks($app)['organization']['ok']);
});

test('el correo de la tienda tiene que ser una dirección válida', function (): void {
    $app = semanticApp();
    $app->settings()->set('store.email', 'esto no es un correo');
    same(false, auditSiteChecks($app)['email']['ok']);
    $app->settings()->set('store.email', 'tienda@ejemplo.test');
    same(true, auditSiteChecks($app)['email']['ok']);
});

test('los feeds tienen que estar hechos hace menos de 24 horas, y apagados todos no hay nada que medir', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    same(false, auditSiteChecks($app)['feeds']['ok'], 'todavía no se han hecho');

    semanticBuildFeeds($app);
    same(true, auditSiteChecks($app)['feeds']['ok']);

    $viejo = $app->clock()->now() - 25 * 3600;
    touch($app->site()->path('feeds') . '/products.json', $viejo);
    same(false, auditSiteChecks($app)['feeds']['ok'], 'uno de 25 horas ya es viejo');

    foreach (['google', 'products', 'openai'] as $feed) {
        $app->settings()->set('semantic.feeds.' . $feed, false);
    }
    same(false, auditSiteChecks($app)['feeds']['ok'], 'sin ningún feed encendido tampoco se cumple');
});

test('con el módulo SEO aparece el mapa del sitio, y bloquear a los asistentes o cerrar el sitio falla su comprobación', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'seo']);
    $checks = auditSiteChecks($app);
    same(true, $checks['sitemap']['ok']);
    same(true, $checks['robots']['ok']);
    same(true, $checks['indexable']['ok']);

    $app->settings()->set('seo.bots.assistant', 'disallow');
    same(false, auditSiteChecks($app)['robots']['ok'], 'robots.txt cierra la puerta a los asistentes');
    same('/admin/settings/seo', auditSiteChecks($app)['robots']['fix_url']);

    $app->settings()->set('seo.noindex_site', true);
    same(false, auditSiteChecks($app)['indexable']['ok']);
});

test('hace falta un transportista con tarifa y plazo de entrega', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'shipping']);
    same(false, auditSiteChecks($app)['shipping']['ok'], 'sin transportistas');

    $zona = shippingZoneId($app, 'Peninsula');
    shippingAddRate($app, shippingAddCarrier($app, 'sin-plazo'), $zona, 'weight', 0, 0, 495);
    same(false, auditSiteChecks($app)['shipping']['ok'], 'con tarifa pero sin plazo');

    shippingAddRate($app, shippingAddCarrier($app, 'con-plazo', ['min_days' => 2, 'max_days' => 4]), $zona, 'weight', 0, 0, 695);
    $check = auditSiteChecks($app)['shipping'];
    same(true, $check['ok']);
    same('/admin/r/carriers', $check['fix_url']);
});

test('las páginas legales tienen que estar todas publicadas', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    same(false, auditSiteChecks($app)['legal']['ok'], 'no hay ninguna');

    (new Legal($app))->create();
    same(false, auditSiteChecks($app)['legal']['ok'], 'creadas pero en borrador');

    $app->db()->run("UPDATE {pages} SET status = 'published'");
    same(true, auditSiteChecks($app)['legal']['ok']);
    same('/admin/cms/legal', auditSiteChecks($app)['legal']['fix_url']);
});

test('el buscador propio, la API y el MCP cuentan solo si sus módulos están activos', function (): void {
    $sin = semanticApp();
    same(false, auditSiteChecks($sin)['search']['ok']);
    same(false, auditSiteChecks($sin)['gates']['ok']);

    $con = semanticApp(['semantic', 'catalog', 'media', 'search']);
    same(true, auditSiteChecks($con)['search']['ok']);
    same(false, auditSiteChecks($con)['gates']['ok'], 'la API y el MCP van juntas: sin ellas no hay puerta');
});
