<?php
/**
 * AIFEC — pruebas del pintado de bloques con los pintores dinámicos (imagen de la biblioteca, productos, entradas y formulario),
 * de la conversión de bloques de WordPress, de la vista previa de bloques y de la vista previa de páginas y entradas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Cms\Cms;

require_once __DIR__ . '/helpers.php';

function cmsRenderer(App $app): Cms
{
    $cms = $app->service('cms');
    check($cms instanceof Cms, 'el servicio cms es el del módulo');

    return $cms;
}

test('un bloque de imagen con identificador de la biblioteca pinta su etiqueta, su figura y su texto alternativo', function (): void {
    $app = cmsApp();
    $media = aifecMedia($app, 'Mesa de roble');
    $html = cmsRenderer($app)->render([['type' => 'image', 'attrs' => ['media_id' => $media, 'alt' => 'Mi foto & más', 'caption' => 'Pie de foto']]]);
    contains('<figure class="af-b af-b-image">', $html);
    contains('<img', $html);
    contains('alt="Mi foto &amp; más"', $html);
    contains('2026/11/foto-', $html);
    contains('-large.webp', $html, 'una imagen de contenido usa el tamaño grande');
    contains('<figcaption>Pie de foto</figcaption>', $html);
    notContains('<script', $html);

    $stored = cmsRenderer($app)->render([['type' => 'image', 'attrs' => ['media_id' => $media]]]);
    contains('alt="Mesa de roble"', $stored, 'sin texto alternativo en el bloque, el de la biblioteca');
    contains('-card.webp', cmsRenderer($app)->render([['type' => 'image', 'attrs' => ['media_id' => $media, 'width' => 300]]]), 'una imagen pequeña usa el tamaño de tarjeta');
});

test('un bloque de imagen cuya imagen ya no existe cae en su dirección, y sin ninguna no pinta nada', function (): void {
    $app = cmsApp();
    $cms = cmsRenderer($app);
    $html = $cms->render([['type' => 'image', 'attrs' => ['media_id' => 99999, 'src' => 'https://tienda.test/x.png', 'alt' => 'x']]]);
    contains('src="https://tienda.test/x.png"', $html);
    same('', $cms->render([['type' => 'image', 'attrs' => ['media_id' => 99999]]]));
    same('', $cms->render([['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']]]), 'una dirección peligrosa no se pinta');
});

test('un bloque de productos sin catálogo no rompe la página ni deja huecos', function (): void {
    $app = cmsApp(['cms']);
    $cms = cmsRenderer($app);
    same('', $cms->render([['type' => 'products', 'attrs' => ['mode' => 'latest']]]));
    same('', $cms->render([['type' => 'product', 'attrs' => ['id' => 3]]]));
    $html = $cms->render([
        ['type' => 'paragraph', 'attrs' => ['html' => 'Antes']],
        ['type' => 'products', 'attrs' => ['mode' => 'featured', 'limit' => 4]],
        ['type' => 'paragraph', 'attrs' => ['html' => 'Después']],
    ]);
    contains('Antes', $html);
    contains('Después', $html);
    notContains('af-b-products', $html);
});

test('un bloque de productos con catálogo pinta tarjetas: a mano, de una categoría, las últimas, las destacadas y las rebajadas', function (): void {
    $app = cmsApp(['cms', 'catalog']);
    $category = aifecCategory($app, 'Salón');
    $mesa = aifecProduct($app, ['name' => 'Mesa de roble', 'categories' => [$category], 'created_at' => '2026-11-01 10:00:00']);
    $silla = aifecProduct($app, ['name' => 'Silla de pino', 'featured' => 1, 'created_at' => '2026-11-02 10:00:00']);
    $lampara = aifecProduct($app, ['name' => 'Lámpara rebajada', 'price' => 2000, 'compare_price' => 3000, 'created_at' => '2026-11-03 10:00:00']);
    aifecProduct($app, ['name' => 'Borrador oculto', 'status' => 'draft']);
    $cms = cmsRenderer($app);

    $latest = $cms->render([['type' => 'products', 'attrs' => ['mode' => 'latest', 'limit' => 10]]]);
    contains('af-b-products', $latest);
    contains('af-product-card', $latest);
    foreach (['Mesa de roble', 'Silla de pino', 'Lámpara rebajada'] as $name) {
        contains($name, $latest);
    }
    notContains('Borrador oculto', $latest, 'lo que no se ve en la tienda no sale');
    check(strpos($latest, 'Lámpara') < strpos($latest, 'Mesa'), 'las últimas primero');

    $manual = $cms->render([['type' => 'products', 'attrs' => ['mode' => 'manual', 'ids' => [$silla, $mesa]]]]);
    check(strpos($manual, 'Silla') < strpos($manual, 'Mesa'), 'a mano, en el orden pedido');
    notContains('Lámpara', $manual);

    $byCategory = $cms->render([['type' => 'products', 'attrs' => ['mode' => 'category', 'category_id' => $category]]]);
    contains('Mesa de roble', $byCategory);
    notContains('Silla de pino', $byCategory);
    contains('Silla de pino', $cms->render([['type' => 'products', 'attrs' => ['mode' => 'featured']]]));
    notContains('Mesa de roble', $cms->render([['type' => 'products', 'attrs' => ['mode' => 'featured']]]));
    contains('Lámpara rebajada', $cms->render([['type' => 'products', 'attrs' => ['mode' => 'sale']]]));
    notContains('Mesa de roble', $cms->render([['type' => 'products', 'attrs' => ['mode' => 'sale']]]));
    same('', $cms->render([['type' => 'products', 'attrs' => ['mode' => 'category', 'category_id' => 99999]]]), 'una categoría sin productos no pinta nada');

    $one = $cms->render([['type' => 'product', 'attrs' => ['id' => $lampara]]]);
    contains('Lámpara rebajada', $one);
    same('', $cms->render([['type' => 'product', 'attrs' => ['id' => 99999]]]));
});

test('una página con un bloque de producto se actualiza sola cuando cambia ese producto', function (): void {
    $app = cmsApp(['cms', 'catalog']);
    $pid = aifecProduct($app, ['name' => 'Mesa de roble']);
    $page = cmsInsertPage($app, ['title' => 'Ofertas', 'blocks' => [['type' => 'product', 'attrs' => ['id' => $pid]]]]);
    $cms = cmsRenderer($app);
    contains('Mesa de roble', $cms->page($page)['html']);
    $app->db()->update('product_i18n', ['name' => 'Mesa de nogal'], ['product_id' => $pid]);
    contains('Mesa de roble', $cms->page($page)['html'], 'mientras nadie avise, sigue la copia guardada');
    $app->invalidate(['product:' . $pid]);
    contains('Mesa de nogal', $cms->page($page)['html'], 'al cambiar el producto, la página se rehace');
});

test('un bloque de entradas pinta las últimas con su enlace, y una lista vacía no pinta nada', function (): void {
    $app = cmsApp();
    $cms = cmsRenderer($app);
    same('', $cms->render([['type' => 'posts', 'attrs' => ['limit' => 3]]]));
    cmsInsertPost($app, ['title' => 'Una & otra', 'published_at' => '2026-11-01 10:00:00']);
    cmsInsertPost($app, ['title' => 'La más nueva', 'published_at' => '2026-11-05 10:00:00']);
    $html = $cms->render([['type' => 'posts', 'attrs' => ['limit' => 1]]]);
    contains('af-b-posts', $html);
    contains('La más nueva', $html);
    notContains('Una &amp; otra', $html);
    contains('href="/blog/la-mas-nueva"', $html);
    contains('Una &amp; otra', $cms->render([['type' => 'posts', 'attrs' => ['limit' => 5]]]), 'el título sale escapado');
    $cat = cmsTerm($app, 'Ideas');
    same('', $cms->render([['type' => 'posts', 'attrs' => ['limit' => 5, 'category_id' => $cat]]]), 'una categoría sin entradas');
});

test('un bloque de formulario pinta el formulario con sus campos, su trampa, su marca y sus textos traducidos', function (): void {
    $app = cmsApp();
    $html = cmsRenderer($app)->render([['type' => 'form', 'attrs' => ['form_id' => 'contact']]]);
    contains('af-b-form', $html);
    contains('<form class="af-form-block" method="post" id="form-contact" action="/formulario/contact"', $html);
    contains('name="f[name]"', $html);
    contains('type="email"', $html);
    contains('name="f[email]"', $html);
    contains('<textarea', $html);
    contains('name="f[message]"', $html);
    contains('type="checkbox"', $html);
    contains('name="f[consent]"', $html);
    contains('name="website"', $html, 'el campo trampa');
    contains('name="_mark"', $html, 'la marca de tiempo');
    contains('Nombre', $html);
    contains('Mensaje', $html);
    contains('He leído y acepto la política de privacidad', $html);
    contains('Enviar', $html);
    contains(' required', $html);
    same(substr_count($html, '<form'), 1);
    contains('af-b-form', cmsRenderer($app)->render([['type' => 'form', 'attrs' => ['form_id' => 1]]]), 'también por su identificador');
    same('', cmsRenderer($app)->render([['type' => 'form', 'attrs' => ['form_id' => 'no-existe']]]));
});

test('el formulario enseña el gracias cuando se le dice y vuelve a pintar lo escrito con sus errores', function (): void {
    $app = cmsApp();
    $app->hooks()->filter('cms.form.state', static function ($state, $form): array {
        return ['sent' => false, 'errors' => ['email' => 'Escribe un correo válido'], 'old' => ['name' => 'Ana "la" <b>Ruiz</b>', 'email' => 'no-es-un-correo']];
    });
    $html = cmsRenderer($app)->render([['type' => 'form', 'attrs' => ['form_id' => 'contact']]]);
    contains('Escribe un correo válido', $html);
    contains('aria-invalid="true"', $html);
    contains('value="Ana &quot;la&quot; &lt;b&gt;Ruiz&lt;/b&gt;"', $html, 'lo escrito vuelve escapado');
    contains('af-alert-bad', $html);

    $app2 = cmsApp();
    $app2->hooks()->filter('cms.form.state', static function ($state): array {
        return ['sent' => true, 'errors' => [], 'old' => []];
    });
    $thanks = cmsRenderer($app2)->render([['type' => 'form', 'attrs' => ['form_id' => 'contact']]]);
    contains('Gracias. Hemos recibido tu mensaje', $thanks);
    notContains('<form', $thanks);
});

test('las etiquetas de un formulario se escapan', function (): void {
    $app = cmsApp();
    $app->db()->insert('forms', ['code' => 'raro', 'name' => 'Raro', 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql(),
        'fields' => json_encode([['key' => 'a', 'type' => 'text', 'label' => '<img src=x onerror=alert(1)> & más', 'required' => 0]])]);
    $html = cmsRenderer($app)->render([['type' => 'form', 'attrs' => ['form_id' => 'raro']]]);
    notContains('<img src=x', $html);
    contains('&lt;img src=x onerror=alert(1)&gt; &amp; más', $html);
});

test('un bloque de WordPress se convierte por la ruta del panel', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $wp = '<!-- wp:heading --><h2>Un título</h2><!-- /wp:heading -->'
        . '<!-- wp:paragraph --><p>Hola <strong>mundo</strong> <script>alert(1)</script></p><!-- /wp:paragraph -->'
        . '<!-- wp:list --><ul><li>Uno</li><li>Dos</li></ul><!-- /wp:list -->';
    $r = $web->postJson('/admin/cms/blocks/import', ['_token' => aifecToken($web), 'content' => $wp]);
    same(200, $r->status);
    $blocks = $web->json()['blocks'];
    same(['heading', 'paragraph', 'list'], array_map(static function (array $b): string {
        return $b['type'];
    }, $blocks));
    same(2, $blocks[0]['attrs']['level']);
    contains('mundo', $blocks[1]['attrs']['html']);
    notContains('script', (string) json_encode($blocks), 'lo peligroso no pasa');
    contains('no-store', $r->headers['Cache-Control']);
});

test('importar sin contenido, o con demasiado, da un error y no convierte nada', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $token = aifecToken($web);
    same(400, $web->postJson('/admin/cms/blocks/import', ['_token' => $token, 'content' => '   '])->status);
    same(400, $web->postJson('/admin/cms/blocks/import', ['_token' => $token])->status);
    same(400, $web->postJson('/admin/cms/blocks/import', ['_token' => $token, 'content' => str_repeat('a', 2000001)])->status);
});

test('la vista previa de bloques los pinta como la tienda, desde una lista o desde JSON, y sanea lo que llega', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $token = aifecToken($web);
    $blocks = [['type' => 'heading', 'attrs' => ['level' => 2, 'html' => 'Título']], ['type' => 'paragraph', 'attrs' => ['html' => 'Texto <script>x()</script>']]];
    same(200, $web->postJson('/admin/cms/blocks/render', ['_token' => $token, 'blocks' => $blocks])->status);
    $html = $web->json()['html'];
    contains('<h2', $html);
    contains('Título', $html);
    notContains('<script', $html);
    $web->postJson('/admin/cms/blocks/render', ['_token' => $token, 'blocks' => json_encode($blocks)]);
    contains('Título', $web->json()['html'], 'también si llega como texto JSON');
    same(400, $web->postJson('/admin/cms/blocks/render', ['_token' => $token, 'blocks' => 'esto no es JSON'])->status);
});

test('la vista previa pinta lo guardado, publicado o no, con el tema y sin indexar', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'draft', 'title' => ['es' => 'Borrador secreto', 'en' => 'Secret draft'], 'blocks' => ['es' => cmsBlocks('Contenido del borrador'), 'en' => cmsBlocks('Draft content')]]);
    $id = cmsLastId($app, 'pages');
    same(null, cmsRenderer($app)->page($id), 'la tienda no lo enseña');

    $r = $web->get('/admin/cms/preview/page/' . $id);
    same(200, $r->status);
    contains('Borrador secreto', $r->body);
    contains('Contenido del borrador', $r->body);
    contains('No está publicado', $r->body);
    contains('content="noindex, nofollow"', $r->body, 'la cabecera del tema lleva noindex');
    contains('noindex', $r->headers['X-Robots-Tag']);
    contains('no-store', $r->headers['Cache-Control']);
    contains('<main', $r->body, 'va dentro del envoltorio del tema');

    $en = $web->get('/admin/cms/preview/page/' . $id . '?lang=en');
    contains('Secret draft', $en->body);
    contains('Draft content', $en->body);
    same(200, $web->get('/admin/cms/preview/page/' . $id . '?lang=zz')->status, 'un idioma desconocido no rompe');

    cmsSavePage($web, ['status' => 'published', 'title' => ['es' => 'Borrador secreto', 'en' => ''], 'blocks' => ['es' => cmsBlocks('Contenido del borrador'), 'en' => '']], $id);
    contains('ya pueden verlo', $web->get('/admin/cms/preview/page/' . $id)->body);
    same(404, $web->get('/admin/cms/preview/page/99999')->status);
    same(404, $web->get('/admin/cms/preview/otra/1')->status);
});

test('la vista previa de una entrada enseña su fecha y su autor, y el título se escapa', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePost($web, ['status' => 'draft', 'title' => ['es' => 'Café & <i>té</i>', 'en' => '']]);
    $id = cmsLastId($app, 'posts');
    $r = $web->get('/admin/cms/preview/post/' . $id);
    same(200, $r->status);
    contains('Café &amp; &lt;i&gt;té&lt;/i&gt;', $r->body);
    notContains('<i>té</i>', $r->body);
});
