<?php
/**
 * AIFEC — pruebas de los textos de la interfaz: el catálogo del sitio y el motor de idiomas, la cobertura, y la lista para editar a
 * mano (buscador, filtros, de 50 en 50, guardar, aprobar o descartar una propuesta, permisos).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Translations\Proposals;
use Aifec\Modules\Translations\Scopes\InterfaceScope;
use Aifec\Modules\Translations\SiteCatalog;

require_once __DIR__ . '/TranslationsKit.php';
require_once __DIR__ . '/../../../tests/Perf/helpers.php';

test('guardar en el catálogo del sitio: el motor lo ve en la misma petición y en la siguiente', function (): void {
    $app = trxApp();
    $catalog = new SiteCatalog($app);
    same(1, $catalog->update('fr', ['Add to cart' => 'Ajouter au panier']));
    same('Ajouter au panier', $app->i18n()->t('Add to cart', [], 'fr'));
    check(is_file($app->site()->path('lang') . '/fr.php'), 'el fichero vive en la carpeta de datos del sitio');
    $next = perfNext($app);
    $next->boot();
    same('Ajouter au panier', $next->i18n()->t('Add to cart', [], 'fr'), 'la petición siguiente (con el catálogo fundido) también');
});

test('una cadena vacía quita la traducción del sitio y vuelve la de serie; guardar lo mismo no cambia nada', function (): void {
    $app = trxApp();
    $catalog = new SiteCatalog($app);
    $catalog->update('es', ['Translations' => 'Idiomas de la tienda']);
    same('Idiomas de la tienda', $app->i18n()->t('Translations'), 'el sitio gana al catálogo del módulo');
    same(0, $catalog->update('es', ['Translations' => 'Idiomas de la tienda']));
    same(1, $catalog->update('es', ['Translations' => '']));
    same('Traducciones', $app->i18n()->t('Translations'));
    same([], $catalog->all('es'));
});

test('un idioma que no es de dos letras no se escribe', function (): void {
    $app = trxApp();
    throws(function () use ($app): void {
        (new SiteCatalog($app))->update('../x', ['A' => 'B']);
    }, \InvalidArgumentException::class);
    same([], (new SiteCatalog($app))->all('../x'));
});

test('un idioma sin catálogo en el código se ve traducido en la tienda tras guardar, y se rehace la copia fundida', function (): void {
    $app = trxApp([], ['translations', 'catalog']);
    aifecProduct($app, ['name' => 'Silla', 'i18n' => ['fr' => ['name' => 'Chaise']]]);
    $web = new TestClient($app);
    $before = $web->get('/fr/producto/chaise');
    same(200, $before->status);
    contains('Chaise', $before->body);
    contains('>In stock<', $before->body, 'sin traducir sale en inglés');
    (new SiteCatalog($app))->update('fr', ['In stock' => 'En stock & co']);
    $after = perfNext($app);
    $after->boot();
    same('En stock & co', $after->i18n()->t('In stock', [], 'fr'), 'la petición siguiente la tiene');
    $r = (new TestClient($after))->get('/fr/producto/chaise');
    contains('data-af-availability>En stock &amp; co<', $r->body, 'la ficha guardada en la caché se rehace con el texto nuevo');
    notContains('>In stock<', $r->body);
    $copy = \Aifec\Kernel\Cache::import($app->site()->path('cache') . '/lang-fr.php');
    check(is_array($copy) && $copy['catalog']['In stock'] === 'En stock & co', 'la copia fundida lleva la del sitio');
    same('En stock', $after->i18n()->t('In stock', [], 'es'), 'el castellano sigue con el suyo');
});

test('la cobertura de la interfaz cuenta total, traducidos y propuestos con un catálogo de prueba a medias', function (): void {
    $app = trxApp();
    trxCodeCatalog($app, 'fr', ['Add to cart' => 'Ajouter au panier', 'Orders' => 'Commandes']);
    (new SiteCatalog($app))->update('fr', ['Checkout' => 'Commander']);
    (new Proposals($app))->put('fr', 'shop', 'Hello {name}', 'Hello {name}', 'Bonjour {name}');
    same(['total' => 4, 'translated' => 2, 'proposed' => 1, 'missing' => 1], (new InterfaceScope($app, 'shop'))->coverage('fr'));
    same(['total' => 2, 'translated' => 1, 'proposed' => 0, 'missing' => 1], (new InterfaceScope($app, 'panel'))->coverage('fr'));
});

test('en inglés la interfaz cuenta como traducida entera y la IA no se ofrece', function (): void {
    $app = trxApp([], ['translations'], ['es', 'en']);
    $scope = new InterfaceScope($app, 'shop');
    same(['total' => 4, 'translated' => 4, 'proposed' => 0, 'missing' => 0], $scope->coverage('en'));
    check($scope->blocked('en') !== '', 'bloqueada en inglés');
    same('', $scope->blocked('fr'));
});

test('la pantalla tiene una pestaña por idioma que no es el principal y la tabla con sus filas', function (): void {
    $app = trxApp([], ['translations'], ['es', 'fr', 'de']);
    $web = adminClient($app);
    $r = $web->get('/admin/translations');
    same(200, $r->status);
    contains('data-tr-tab="fr"', $r->body);
    contains('data-tr-tab="de"', $r->body);
    notContains('data-tr-tab="es"', $r->body, 'el principal no tiene pestaña');
    contains('Interfaz de la tienda', $r->body);
    contains('Interfaz del panel', $r->body);
    contains('<details class="af-help', $r->body, 'la ayuda va plegada');
    contains('carpeta de datos del sitio', $r->body);
    $de = $web->get('/admin/translations?lang=de');
    contains('aria-current="page" data-tr-tab="de"', $de->body);
});

test('con un solo idioma la pantalla lo dice y enlaza a los ajustes', function (): void {
    $app = trxApp([], ['translations'], ['es']);
    $r = adminClient($app)->get('/admin/translations');
    same(200, $r->status);
    contains('La tienda solo tiene un idioma', $r->body);
    contains('/admin/settings', $r->body);
});

test('la lista de textos busca por inglés y por traducción, y filtra lo que falta y lo propuesto', function (): void {
    $app = trxApp();
    trxCodeCatalog($app, 'fr', ['Add to cart' => 'Ajouter au panier']);
    (new Proposals($app))->put('fr', 'shop', 'Checkout', 'Checkout', 'Passer commande');
    $web = adminClient($app);
    $all = $web->get('/admin/translations/fr/shop');
    same(200, $all->status);
    contains('4 textos', $all->body);
    $byFrench = $web->get('/admin/translations/fr/shop?q=panier');
    contains('1 texto', $byFrench->body);
    contains('Add to cart', $byFrench->body);
    $byEnglish = $web->get('/admin/translations/fr/shop?q=HELLO');
    contains('Hello {name}', $byEnglish->body);
    notContains('Add to cart', $byEnglish->body);
    $missing = $web->get('/admin/translations/fr/shop?f=missing');
    contains('2 textos', $missing->body, 'Hello y el plural: ni traducidos ni propuestos');
    $proposed = $web->get('/admin/translations/fr/shop?f=proposed');
    contains('1 texto', $proposed->body);
    contains('Passer commande', $proposed->body);
    contains('Propuesta de la IA', $proposed->body);
});

test('la lista va de 50 en 50', function (): void {
    $app = trxApp();
    $many = [];
    for ($i = 1; $i <= 120; $i++) {
        $many[sprintf('Text number %03d', $i)] = ['area' => 'shop', 'origin' => 'core'];
    }
    trxBindStrings($app, $many);
    $web = adminClient($app);
    $first = $web->get('/admin/translations/fr/shop');
    same(50, substr_count($first->body, 'data-tr-string='));
    contains('Text number 001', $first->body);
    contains('Página 1 de 3', $first->body);
    contains('data-tr-next', $first->body);
    $last = $web->get('/admin/translations/fr/shop?page=3');
    same(20, substr_count($last->body, 'data-tr-string='));
    contains('Text number 120', $last->body);
    notContains('data-tr-next', $last->body);
});

test('guardar una página guarda solo lo que cambió, y la tienda lo ve', function (): void {
    $app = trxApp();
    trxCodeCatalog($app, 'fr', ['Orders' => 'Commandes']);
    $web = adminClient($app);
    $web->get('/admin/translations/fr/shop');
    $r = $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'save',
        'k' => ['Add to cart', 'Checkout'], 'o' => ['', ''], 'p' => ['0', '0'], 'v' => ['Ajouter au panier', '']]);
    same(303, $r->status);
    same(['Add to cart' => 'Ajouter au panier'], (new SiteCatalog($app))->all('fr'));
    same('Ajouter au panier', $app->i18n()->t('Add to cart', [], 'fr'));
    contains('1 traducción guardada', $web->follow()->body);
});

test('una traducción con un marcador perdido no se guarda y se dice por qué', function (): void {
    $app = trxApp();
    $web = adminClient($app);
    $web->get('/admin/translations/fr/shop');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'save',
        'k' => ['Hello {name}', '{n} item|{n} items'], 'o' => ['', ''], 'p' => ['0', '0'], 'v' => ['Bonjour', '{n} article']]);
    same([], (new SiteCatalog($app))->all('fr'));
    $page = $web->follow()->body;
    contains('Falta el marcador {name}', $page);
    contains('Necesita 2 formas', $page);
});

test('vaciar un campo vuelve al texto de serie', function (): void {
    $app = trxApp();
    (new SiteCatalog($app))->update('fr', ['Checkout' => 'Commander']);
    $web = adminClient($app);
    $web->get('/admin/translations/fr/shop');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'save', 'k' => ['Checkout'], 'o' => ['Commander'], 'p' => ['0'], 'v' => ['']]);
    same([], (new SiteCatalog($app))->all('fr'));
    same('Checkout', $app->i18n()->t('Checkout', [], 'fr'));
});

test('una propuesta se aprueba fila a fila (con lo escrito) o se descarta', function (): void {
    $app = trxApp();
    $proposals = new Proposals($app);
    $proposals->put('fr', 'shop', 'Checkout', 'Checkout', 'Passer commande');
    $proposals->put('fr', 'shop', 'Add to cart', 'Add to cart', 'Ajouter');
    $web = adminClient($app);
    $page = $web->get('/admin/translations/fr/shop?f=proposed');
    contains('data-tr-approve-one', $page->body);
    same('Checkout', $app->i18n()->t('Checkout', [], 'fr'), 'una propuesta no se ve en la tienda');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'approve:1',
        'k' => ['Add to cart', 'Checkout'], 'o' => ['Ajouter', 'Passer commande'], 'p' => ['1', '1'], 'v' => ['Ajouter', 'Commander']]);
    same(['Checkout' => 'Commander'], (new SiteCatalog($app))->all('fr'), 'solo la fila aprobada, con lo corregido');
    same(['Add to cart'], array_keys($proposals->pending('fr', 'shop')));
    $web->get('/admin/translations/fr/shop');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'reject:0',
        'k' => ['Add to cart'], 'o' => ['Ajouter'], 'p' => ['1'], 'v' => ['Ajouter']]);
    same([], $proposals->pending('fr', 'shop'));
    same(['Checkout' => 'Commander'], (new SiteCatalog($app))->all('fr'), 'descartar no publica nada');
});

test('guardar sin tocar una propuesta no la aprueba', function (): void {
    $app = trxApp();
    (new Proposals($app))->put('fr', 'shop', 'Checkout', 'Checkout', 'Passer commande');
    $web = adminClient($app);
    $web->get('/admin/translations/fr/shop');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'save', 'k' => ['Checkout'], 'o' => ['Passer commande'], 'p' => ['1'], 'v' => ['Passer commande']]);
    same([], (new SiteCatalog($app))->all('fr'));
    same(1, (new Proposals($app))->count('fr', 'shop'));
});

test('una clave que no es del código no se guarda', function (): void {
    $app = trxApp();
    $web = adminClient($app);
    $web->get('/admin/translations/fr/shop');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'save', 'k' => ['Orders', 'Inventada'], 'o' => ['', ''], 'p' => ['0', '0'], 'v' => ['Commandes', 'X']]);
    same([], (new SiteCatalog($app))->all('fr'), '«Orders» es del panel, no de la tienda, y la otra no existe');
});

test('se puede cambiar una frase del idioma principal sin tocar el código', function (): void {
    $app = trxApp();
    $web = adminClient($app);
    $web->get('/admin/translations/es/shop');
    $web->post('/admin/translations/es/shop', ['_token' => aifecToken($web), 'action' => 'save', 'k' => ['Add to cart'], 'o' => ['Añadir al carrito'], 'p' => ['0'], 'v' => ['Lo quiero']]);
    same('Lo quiero', $app->i18n()->t('Add to cart', [], 'es'));
});

test('los textos con «&», comillas y saltos de línea se guardan tal cual y se pintan escapados', function (): void {
    $app = trxApp();
    $web = adminClient($app);
    $web->get('/admin/translations/fr/shop');
    $web->post('/admin/translations/fr/shop', ['_token' => aifecToken($web), 'action' => 'save', 'k' => ['Checkout'], 'o' => [''], 'p' => ['0'], 'v' => ["Payer & «finir»\r\n\"vite\""]]);
    same("Payer & «finir»\n\"vite\"", (new SiteCatalog($app))->all('fr')['Checkout']);
    $page = $web->get('/admin/translations/fr/shop?q=Checkout');
    contains('Payer &amp; «finir»', $page->body);
    contains('&quot;vite&quot;', $page->body);
});

test('sin translations.view no se ve y sin translations.edit no se guarda', function (): void {
    $app = trxApp();
    $none = trxPanel($app, 'nadie', ['dashboard.view']);
    same(403, $none->get('/admin/translations')->status);
    same(403, $none->get('/admin/translations/fr/shop')->status);
    $viewer = trxPanel($app, 'viewer', ['dashboard.view', 'translations.view']);
    $page = $viewer->get('/admin/translations/fr/shop');
    same(200, $page->status);
    contains('readonly', $page->body);
    notContains('Guardar esta página', $page->body);
    $r = $viewer->post('/admin/translations/fr/shop', ['_token' => aifecToken($viewer), 'action' => 'save', 'k' => ['Checkout'], 'o' => [''], 'p' => ['0'], 'v' => ['Commander']]);
    same(403, $r->status);
    same([], (new SiteCatalog($app))->all('fr'));
});

test('sin entrada propia en el menú: se llega desde Ajustes → Traducciones, y un idioma que no es de la tienda da 404', function (): void {
    $app = trxApp();
    $web = adminClient($app);
    $home = $web->get('/admin')->body;
    $nav = substr($home, (int) strpos($home, '<nav class="af-nav">'));
    notContains('/admin/translations', substr($nav, 0, (int) strpos($nav, '</nav>')), 'el menú no suma ninguna entrada');
    contains('/admin/settings/translations', $web->get('/admin/settings')->body);
    $settings = $web->get('/admin/settings/translations');
    same(200, $settings->status);
    contains('data-tr-open', $settings->body);
    contains('/admin/translations"', $settings->body);
    same(404, $web->get('/admin/translations/de/shop')->status);
    same(404, $web->get('/admin/translations/fr/nada')->status);
});
