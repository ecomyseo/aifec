<?php
/**
 * AIFEC — pruebas del contenido: cobertura de productos, categorías, atributos, páginas, menús y correos; la IA de mentira traduce
 * un producto y una página, quedan como propuestas, al aprobarlas se guardan donde las guarda su módulo, reciben su dirección en el
 * idioma nuevo y se ven en /fr/…
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Translations\Fill;
use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\Scopes;

require_once __DIR__ . '/TranslationsKit.php';

/** Un producto con casi todos sus textos en castellano y otro que ya tiene su nombre en francés. @return array{0:int,1:int} */
function trxProducts(App $app): array
{
    $chair = aifecProduct($app, [
        'name' => 'Silla roble', 'summary' => '<p>Cómoda</p>', 'description' => '<p>Madera &amp; barniz</p>',
        'facts' => [['label' => 'Material', 'value' => 'Roble']], 'meta_title' => 'Silla de roble',
    ]);
    $table = aifecProduct($app, ['name' => 'Mesa', 'i18n' => ['fr' => ['name' => 'Table']]]);

    return [$chair, $table];
}

/** @return array<string,int> */
function trxCoverage(App $app, string $code, string $lang = 'fr'): array
{
    $scope = Scopes::get($app, $code);
    check($scope !== null, 'existe la fila ' . $code);

    return $scope->coverage($lang);
}

test('la cobertura de productos cuenta cada campo con original, con un catálogo a medias', function (): void {
    [$app] = trxAiApp();
    trxProducts($app);
    same(['total' => 7, 'translated' => 1, 'proposed' => 0, 'missing' => 6], trxCoverage($app, 'products'), 'el dato clave cuenta dos textos');
    same(['total' => 0, 'translated' => 0, 'proposed' => 0, 'missing' => 0], trxCoverage($app, 'products', 'es'), 'el principal no se cuenta');
});

test('un producto traducido por la IA queda como propuesta en la bandeja y no se escribe en el catálogo', function (): void {
    [$app, $ai] = trxAiApp();
    [$chair] = trxProducts($app);
    $id = (new Fill($app))->start('fr', 'products');
    trxWork($app);
    same(1, count($ai->requests));
    same(6, count(trxItemsOf($ai->requests[0])), 'nombre, resumen, descripción, dos trozos del dato y título');
    contains('"format":"html"', $ai->requests[0]->messages[0]->content);
    contains('Source language: Spanish', $ai->requests[0]->messages[0]->content);
    $fields = $app->db()->column("SELECT field FROM {ai_proposals} WHERE entity = 'product' AND entity_id = ? AND lang = 'fr' AND status = 'pending' ORDER BY field", [$chair]);
    same(['description', 'facts', 'meta_title', 'name', 'summary'], $fields);
    same(null, $app->db()->row("SELECT id FROM {product_i18n} WHERE product_id = ? AND lang = 'fr'", [$chair]));
    same(['total' => 7, 'translated' => 1, 'proposed' => 6, 'missing' => 0], trxCoverage($app, 'products'));
    $job = trxJob($app, $id);
    same(6, (int) $job['total']);
    same(6, (int) $job['done']);
});

test('«Aprobar todo» guarda el producto en el catálogo, le da su dirección en francés y se ve en /fr/', function (): void {
    [$app] = trxAiApp();
    [$chair] = trxProducts($app);
    (new Fill($app))->start('fr', 'products');
    trxWork($app);
    $web = adminClient($app);
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/fr/products/approve', ['_token' => aifecToken($web)]);
    $row = $app->db()->row("SELECT * FROM {product_i18n} WHERE product_id = ? AND lang = 'fr'", [$chair]);
    same('FR Silla roble', $row['name']);
    contains('Cómoda', (string) $row['summary']);
    same('FR Silla de roble', $row['meta_title']);
    same([['label' => 'FR Material', 'value' => 'FR Roble']], json_decode((string) $row['facts'], true));
    same('producto/fr-silla-roble', $app->slugs()->path('product', $chair, 'fr'));
    $front = (new TestClient($app))->get('/fr/producto/fr-silla-roble');
    same(200, $front->status);
    contains('FR Silla roble', $front->body);
    same(['total' => 7, 'translated' => 7, 'proposed' => 0, 'missing' => 0], trxCoverage($app, 'products'));
});

test('las preguntas frecuentes viajan pregunta a pregunta y vuelven como lista', function (): void {
    [$app] = trxAiApp(['translations.publish_unreviewed' => true]);
    $id = aifecProduct($app, ['name' => 'Silla']);
    $app->db()->insert('product_faqs', ['product_id' => $id, 'lang' => 'es', 'question' => '¿Se monta?', 'answer' => 'Sí, en 5 minutos.', 'position' => 0]);
    $app->db()->insert('product_faqs', ['product_id' => $id, 'lang' => 'es', 'question' => '¿Lleva barniz?', 'answer' => 'No.', 'position' => 1]);
    (new Fill($app))->start('fr', 'products');
    trxWork($app);
    $faqs = $app->db()->select("SELECT question, answer FROM {product_faqs} WHERE product_id = ? AND lang = 'fr' ORDER BY position", [$id]);
    same([['question' => 'FR ¿Se monta?', 'answer' => 'FR Sí, en 5 minutos.'], ['question' => 'FR ¿Lleva barniz?', 'answer' => 'FR No.']], $faqs);
    same('FR Silla', $app->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'fr'", [$id]), 'el nombre se aplica el primero');
});

test('una página traducida por la IA se guarda en el módulo de contenidos al aprobarla y se ve en /fr/', function (): void {
    [$app] = trxAiApp();
    $page = cmsInsertPage($app, ['title' => 'Quiénes somos']);
    same(['total' => 2, 'translated' => 0, 'proposed' => 0, 'missing' => 1], trxCoverage($app, 'pages'), 'el cuerpo se cuenta, pero no le falta a la IA');
    (new Fill($app))->start('fr', 'pages');
    trxWork($app);
    same(['total' => 2, 'translated' => 0, 'proposed' => 1, 'missing' => 0], trxCoverage($app, 'pages'));
    same(1, (new Scopes\PagesScope($app))->approveAll('fr'));
    same('FR Quiénes somos', $app->db()->value("SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = 'fr'", [$page]));
    same('fr-quienes-somos', $app->slugs()->path('page', $page, 'fr'));
    $front = (new TestClient($app))->get('/fr/fr-quienes-somos');
    same(200, $front->status);
    contains('FR Quiénes somos', $front->body);
});

test('una propuesta de página aprobada desde la bandeja de la IA la aplica el módulo de contenidos', function (): void {
    [$app] = trxAiApp();
    $page = cmsInsertPage($app, ['title' => 'Envíos']);
    $ai = $app->service('ai');
    $id = $ai->propose('page', $page, 'title', 'fr', 'Livraisons & retours');
    same('approved', $ai->proposals()->approve($id));
    same('Livraisons & retours', $app->db()->value("SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = 'fr'", [$page]));
    same('livraisons-retours', $app->slugs()->path('page', $page, 'fr'));
});

test('la dirección del idioma nuevo no se toca si ya existía', function (): void {
    [$app] = trxAiApp();
    [, $table] = trxProducts($app);
    $ai = $app->service('ai');
    $id = $ai->propose('product', $table, 'name', 'fr', 'Grande table');
    same('approved', $ai->proposals()->approve($id));
    same('producto/table', $app->slugs()->path('product', $table, 'fr'));
    same('Grande table', $app->db()->value("SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = 'fr'", [$table]));
});

test('los menús: cobertura, propuesta de la IA y al aprobar se guarda por el servicio de contenidos', function (): void {
    [$app] = trxAiApp();
    $menus = $app->service('cms')->menus();
    $menus->save('main', 'Principal', [
        ['type' => 'home', 'labels' => ['es' => 'Inicio']],
        ['type' => 'url', 'url' => '/ofertas', 'labels' => ['es' => 'Ofertas & más']],
        ['type' => 'url', 'url' => '/blog', 'labels' => ['es' => '']],
    ]);
    same(['total' => 2, 'translated' => 0, 'proposed' => 0, 'missing' => 2], trxCoverage($app, 'menus'));
    (new Fill($app))->start('fr', 'menus');
    trxWork($app);
    same(['total' => 2, 'translated' => 0, 'proposed' => 2, 'missing' => 0], trxCoverage($app, 'menus'));
    same(['es' => 'Inicio'], $menus->editable('main')[0]['labels'], 'propuesto no es publicado');
    $web = adminClient($app);
    $web->get('/admin/translations?lang=fr');
    $web->post('/admin/translations/fr/menus/approve', ['_token' => aifecToken($web)]);
    $items = $menus->editable('main');
    same('FR Inicio', $items[0]['labels']['fr']);
    same('FR Ofertas & más', $items[1]['labels']['fr']);
    same(['total' => 2, 'translated' => 2, 'proposed' => 0, 'missing' => 0], trxCoverage($app, 'menus'));
});

test('una propuesta de menú cuyo original ha cambiado se descarta al aprobar', function (): void {
    [$app] = trxAiApp();
    $menus = $app->service('cms')->menus();
    $menus->save('main', 'Principal', [['type' => 'home', 'labels' => ['es' => 'Inicio']]]);
    (new Fill($app))->start('fr', 'menus');
    trxWork($app);
    $menus->save('main', 'Principal', [['type' => 'home', 'labels' => ['es' => 'Portada']]]);
    same(0, (new Scopes\MenusScope($app))->approveAll('fr'));
    same(0, (new Proposals($app))->count('fr', 'menus'));
    same(['es' => 'Portada'], $menus->editable('main')[0]['labels']);
});

test('categorías y atributos se cuentan y la IA los traduce: nombre, textos y dirección de la categoría, atributos y valores', function (): void {
    // 14/11/2026: antes solo se contaban («el catálogo no tiene un servicio para guardarla») y la tienda en otro idioma salía con
    // el menú, las categorías y los filtros en castellano.
    [$app] = trxAiApp();
    $chairs = aifecCategory($app, 'Sillas', null, ['description' => 'Para sentarse']);
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul'], ['i18n' => ['fr' => 'Couleur']]);
    same(['total' => 2, 'translated' => 0, 'proposed' => 0, 'missing' => 2], trxCoverage($app, 'categories'));
    same(['total' => 3, 'translated' => 1, 'proposed' => 0, 'missing' => 2], trxCoverage($app, 'attributes'));
    $web = adminClient($app);
    $page = $web->get('/admin/translations?lang=fr');
    $row = substr($page->body, (int) strpos($page->body, 'data-tr-row="categories"'), 1500);
    notContains('El catálogo aún no tiene un servicio', $row);
    (new Fill($app))->start('fr', 'categories');
    (new Fill($app))->start('fr', 'attributes');
    trxWork($app);
    same(['total' => 2, 'translated' => 0, 'proposed' => 2, 'missing' => 0], trxCoverage($app, 'categories'), 'sin publicar, quedan propuestas');
    same(2, \Aifec\Modules\Translations\Scopes::get($app, 'categories')->approveAll('fr'));
    same(2, \Aifec\Modules\Translations\Scopes::get($app, 'attributes')->approveAll('fr'));
    same('FR Sillas', (string) $app->db()->value("SELECT name FROM {category_i18n} WHERE category_id = ? AND lang = 'fr'", [$chairs]));
    contains('Para sentarse', (string) $app->db()->value("SELECT description FROM {category_i18n} WHERE category_id = ? AND lang = 'fr'", [$chairs]));
    same('categoria/fr-sillas', (string) $app->slugs()->path('category', $chairs, 'fr'), 'la categoría estrena su dirección en ese idioma');
    same('Couleur', (string) $app->db()->value("SELECT name FROM {attribute_i18n} WHERE lang = 'fr'"), 'lo ya traducido no se toca');
    same(['FR Azul', 'FR Rojo'], $app->db()->column("SELECT label FROM {attribute_value_i18n} WHERE lang = 'fr' ORDER BY label"));
    same(['total' => 2, 'translated' => 2, 'proposed' => 0, 'missing' => 0], trxCoverage($app, 'categories'));
    // Una propuesta cuyo original cambió después no se aplica.
    $app->db()->update('category_i18n', ['name' => 'Sillas de comedor'], ['category_id' => $chairs, 'lang' => 'es']);
    $app->db()->update('category_i18n', ['name' => ''], ['category_id' => $chairs, 'lang' => 'fr']);
    (new \Aifec\Modules\Translations\Proposals($app))->put('fr', 'categories', sprintf('c:%08d:1', $chairs), 'Sillas', 'Chaises');
    same(0, \Aifec\Modules\Translations\Scopes::get($app, 'categories')->approveAll('fr'));
    $list = $web->get('/admin/translations/fr/attributes');
    same(200, $list->status);
    contains('Color', $list->body);
    contains('data-tr-state="ok"', $list->body);
});

test('los correos se cuentan por el servicio del módulo de correo y se editan en sus plantillas', function (): void {
    [$app] = trxAiApp();
    $before = trxCoverage($app, 'mail');
    check($before['total'] > 0, 'hay textos de correo');
    $mailer = $app->service('mailer');
    $key = (string) array_keys($mailer->templates())[0];
    $mailer->saveTexts($key, 'fr', ['subject' => 'Sujet de test', 'greeting' => '', 'farewell' => '']);
    same($before['translated'] + 1, trxCoverage($app, 'mail')['translated']);
    $page = adminClient($app)->get('/admin/translations?lang=fr');
    contains('/admin/mail/templates', $page->body);
});

test('«Ver y editar» de productos enseña cada campo con su estado y enlaza a la ficha', function (): void {
    [$app] = trxAiApp();
    [$chair] = trxProducts($app);
    $web = adminClient($app);
    $before = $web->get('/admin/translations/fr/products');
    same(200, $before->status);
    contains('Silla roble', $before->body);
    contains('/admin/r/products/' . $chair, $before->body);
    contains('data-tr-state="missing"', $before->body);
    (new Fill($app))->start('fr', 'products');
    trxWork($app);
    $after = $web->get('/admin/translations/fr/products');
    contains('data-tr-state="proposed"', $after->body);
    contains('/admin/ai/proposals', $after->body);
});
