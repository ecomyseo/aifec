<?php
/**
 * AIFEC — pruebas de la pantalla con y sin IA: sin el módulo de IA no hay botones ni rutas de IA; sin «ai.use» no se lanza; sin
 * «translations.edit» no se aprueba; con todo, «Traducir lo que falta» con su estimación y la lista de trabajos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Translations\Fill;
use Aifec\Modules\Translations\Glossary;
use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\SiteCatalog;

require_once __DIR__ . '/TranslationsKit.php';

test('sin el módulo de IA todo lo manual funciona, y ni hay botones de IA ni glosario ni sus rutas', function (): void {
    $app = trxApp();
    $web = adminClient($app);
    $page = $web->get('/admin/translations?lang=fr');
    same(200, $page->status);
    notContains('data-tr-fill', $page->body);
    notContains('name="glossary"', $page->body);
    contains('Ver y editar', $page->body);
    contains('data-tr-export', $page->body);
    $token = aifecToken($web);
    same(404, $web->post('/admin/translations/fr/shop/fill', ['_token' => $token])->status);
    same(404, $web->post('/admin/translations/glossary', ['_token' => $token, 'lang' => 'fr', 'glossary' => 'a = b'])->status);
    same(404, $web->post('/admin/translations/jobs/1/pause', ['_token' => $token])->status);
    $web->get('/admin/translations/fr/shop');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'save', 'k' => ['Checkout'], 'o' => [''], 'p' => ['0'], 'v' => ['Commander']]);
    same(['Checkout' => 'Commander'], (new SiteCatalog($app))->all('fr'));
});

test('con todo: «Traducir lo que falta» sale con su estimación, lanza el trabajo y la lista de trabajos lo enseña', function (): void {
    [$app, $ai] = trxAiApp();
    $web = adminClient($app);
    $page = $web->get('/admin/translations?lang=fr');
    $row = substr($page->body, (int) strpos($page->body, 'data-tr-row="shop"'), 2500);
    contains('data-tr-fill', $row);
    contains('fichas', $row, 'la estimación del gasto antes de lanzar');
    contains('name="glossary"', $page->body);
    $web->post('/admin/translations/fr/shop/fill', ['_token' => aifecToken($web)]);
    contains('La IA está traduciendo en segundo plano', $web->follow()->body);
    $id = (int) $app->db()->value('SELECT id FROM {translation_jobs}');
    check($id > 0, 'hay un trabajo');
    trxWork($app);
    same(1, count($ai->requests));
    $after = $web->get('/admin/translations?lang=fr');
    contains('data-tr-job="' . $id . '"', $after->body);
    contains('Traducciones de la IA', $after->body);
    $shop = substr($after->body, (int) strpos($after->body, 'data-tr-row="shop"'), 2500);
    contains('<td class="af-num" data-tr-proposed>4</td>', $shop);
});

test('el glosario se guarda desde la pestaña del idioma', function (): void {
    [$app] = trxAiApp();
    $web = adminClient($app);
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/glossary', ['_token' => aifecToken($web), 'lang' => 'fr', 'glossary' => "cart = panier\nOslo"]);
    same("cart = panier\nOslo", Glossary::get($app, 'fr'));
    contains('cart = panier', $web->follow()->body);
    $web->post('/admin/translations/glossary', ['_token' => aifecToken($web), 'lang' => 'xx', 'glossary' => 'a']);
    same(400, $web->last->status);
});

test('con la IA instalada pero apagada no salen los botones de traducir', function (): void {
    [$app] = trxAiApp(['ai.enabled' => false]);
    $page = adminClient($app)->get('/admin/translations?lang=fr');
    notContains('data-tr-fill', $page->body);
    contains('name="glossary"', $page->body, 'el glosario sí, para cuando se encienda');
});

test('sin «ai.use» no salen los botones de IA ni se lanza, pero se aprueba lo de la interfaz', function (): void {
    [$app, $ai] = trxAiApp();
    (new Proposals($app))->put('fr', 'shop', 'Checkout', 'Checkout', 'Commander');
    $web = trxPanel($app, 'translator', ['dashboard.view', 'translations.view', 'translations.edit']);
    $page = $web->get('/admin/translations?lang=fr');
    notContains('data-tr-fill', $page->body);
    contains('data-tr-approve', $page->body);
    same(403, $web->post('/admin/translations/fr/shop/fill', ['_token' => aifecToken($web)])->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {translation_jobs}'));
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/fr/shop/approve', ['_token' => aifecToken($web)]);
    same(['Checkout' => 'Commander'], (new SiteCatalog($app))->all('fr'));
    $web->get('/admin/translations?lang=fr');
    same(403, $web->post('/admin/translations/fr/products/approve', ['_token' => aifecToken($web)])->status, 'aprobar productos es usar la bandeja de la IA');
    same(0, count($ai->requests));
});

test('sin «translations.edit» no se aprueba, ni se lanza, ni se importa, ni se cambia el glosario', function (): void {
    [$app] = trxAiApp();
    (new Proposals($app))->put('fr', 'shop', 'Checkout', 'Checkout', 'Commander');
    $web = trxPanel($app, 'reader', ['dashboard.view', 'translations.view', 'ai.use']);
    $page = $web->get('/admin/translations?lang=fr');
    same(200, $page->status);
    notContains('data-tr-approve', $page->body);
    notContains('data-tr-fill', $page->body);
    $token = aifecToken($web);
    same(403, $web->post('/admin/translations/fr/shop/approve', ['_token' => $token])->status);
    same(403, $web->post('/admin/translations/fr/shop/fill', ['_token' => $token])->status);
    same(403, $web->post('/admin/translations/fr/import', ['_token' => $token, 'csv' => "Checkout,Commander\n"])->status);
    same(403, $web->post('/admin/translations/glossary', ['_token' => $token, 'lang' => 'fr', 'glossary' => 'a'])->status);
    same([], (new SiteCatalog($app))->all('fr'));
    same(200, $web->get('/admin/translations/fr/export.csv')->status, 'descargar el CSV es mirar');
});

test('lanzar una fila que no se puede rellenar avisa con el motivo', function (): void {
    [$app] = trxAiApp();
    $web = adminClient($app);
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/es/categories/fill', ['_token' => aifecToken($web)]);
    contains('Esta fila no se puede traducir con IA', $web->follow()->body);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {translation_jobs}'));
});

test('el estado de un trabajo pausado se ve con su motivo traducido', function (): void {
    [$app] = trxAiApp();
    $id = (new Fill($app))->start('fr', 'shop');
    (new Fill($app))->pause($id, Fill::OFF);
    $page = adminClient($app)->get('/admin/translations?lang=fr');
    contains('La IA está apagada', $page->body);
    contains('En pausa', $page->body);
});
