<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Form;
use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

if (!function_exists('mediaFake')) {
    /** Da de alta filas de media sin ficheros (para probar paginación y búsquedas sin crear cien imágenes). */
    function mediaFake(App $app, int $n, string $prefix = 'img'): void
    {
        for ($i = 1; $i <= $n; ++$i) {
            $app->db()->insert('media', [
                'path' => '2026/11/' . $prefix . $i . '.jpg', 'filename' => $prefix . $i . '.jpg', 'mime' => 'image/jpeg', 'kind' => 'image',
                'bytes' => 1000 + $i, 'width' => 100, 'height' => 100, 'hash' => hash('sha256', $prefix . $i), 'created_at' => $app->clock()->sql(),
            ]);
        }
    }

    /** Enlace de un filtro «media.usage» que dice que una imagen está en uso. */
    function mediaMarkInUse(App $app, int $id): void
    {
        $app->hooks()->filter('media.usage', static function ($uses, int $mediaId) use ($id) {
            if ($mediaId === $id) {
                $uses[] = 'Producto: Silla';
            }

            return $uses;
        });
    }
}

test('la biblioteca se abre con permiso y da 403 sin él (rejilla, selector, ficha y subida)', function (): void {
    $app = mediaApp();
    $id = mediaLib($app)->store(mediaImage(400, 300, 'jpeg', 20), 'a.jpg')['id'];

    $admin = adminClient($app);
    same(200, $admin->get('/admin/media')->status);
    same(200, $admin->get('/admin/media/' . $id)->status);
    same(200, $admin->get('/admin/media/picker')->status);
    contains('/admin/media', $admin->get('/admin')->body, 'sale en el menú');

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/media')->status);
    same(403, $support->get('/admin/media/' . $id)->status);
    same(403, $support->get('/admin/media/picker')->status);
    $support->get('/admin');
    same(403, mediaPost($support, '/admin/media/upload', [], [mediaImage(50, 50, 'jpeg', 21)])->status);
    $editor = adminClient($app, 'editor');
    same(200, $editor->get('/admin/media')->status, 'el papel editor trae media.*');
});

test('quien solo puede mirar no ve la zona de subida ni los formularios y no puede cambiar nada', function (): void {
    $app = mediaApp();
    mediaViewerRole($app);
    $lib = mediaLib($app);
    $id = $lib->store(mediaImage(400, 300, 'jpeg', 22), 'a.jpg', ['alt' => 'Original'])['id'];

    $web = adminClient($app, 'mviewer');
    $grid = $web->get('/admin/media');
    same(200, $grid->status);
    notContains('data-af-upload-input', $grid->body);
    $show = $web->get('/admin/media/' . $id);
    same(200, $show->status);
    notContains('name="alt"', $show->body);
    notContains('/delete', $show->body);
    notContains('data-af-upload-input', $web->get('/admin/media/picker')->body);

    $web->get('/admin/media');
    same(403, mediaPost($web, '/admin/media/upload', [], [mediaImage(50, 50, 'jpeg', 23)])->status);
    $web->get('/admin/media/' . $id);
    same(403, $web->post('/admin/media/' . $id, ['_token' => aifecToken($web), 'alt' => 'Cambiado'])->status);
    $web->get('/admin/media/' . $id);
    same(403, $web->post('/admin/media/' . $id . '/delete', ['_token' => aifecToken($web)])->status);
    same('Original', $lib->get($id)['alt']);
    same(1, $lib->count());
});

test('la subida por POST acepta varios ficheros y contesta JSON con los que entraron y los que no', function (): void {
    $app = mediaApp();
    $web = adminClient($app);
    $web->get('/admin/media');
    $one = mediaImage(800, 600, 'jpeg', 24);
    $two = mediaImage(300, 300, 'png', 25);
    $php = mediaFile('<?php echo 1;', 'malo.jpg');
    $big = mediaImage(100, 100, 'jpeg', 26);

    $r = mediaPost($web, '/admin/media/upload', [], [$one, $two, $php, $big], true, ['uno.jpg', 'dos.png', 'malo.jpg', 'grande.jpg'], [0, 0, 0, 1]);

    same(200, $r->status);
    contains('application/json', $r->headers['Content-Type']);
    $json = $web->json();
    same(false, $json['ok'], 'hubo errores');
    same(2, count($json['items']));
    same(['id', 'thumb', 'filename', 'width', 'height'], array_keys($json['items'][0]));
    same('uno.jpg', $json['items'][0]['filename']);
    same(800, $json['items'][0]['width']);
    contains('/media/test/2026/11/uno-', $json['items'][0]['thumb']);
    same('dos.png', $json['items'][1]['filename']);
    same(2, count($json['errors']));
    same(['name', 'message'], array_keys($json['errors'][0]));
    same('malo.jpg', $json['errors'][0]['name']);
    same('El fichero no es una imagen admitida', $json['errors'][0]['message']);
    same('grande.jpg', $json['errors'][1]['name']);
    same('El fichero es demasiado grande', $json['errors'][1]['message']);

    $rows = $app->db()->select('SELECT id, created_by FROM {media} ORDER BY id');
    same(2, count($rows));
    same(1, (int) $rows[0]['created_by'], 'queda quién la subió');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'media.upload' AND entity_id = ?", [(string) $json['items'][0]['id']]));

    $web->get('/admin/media');
    mediaPost($web, '/admin/media/upload', [], [mediaImage(200, 200, 'jpeg', 27)]);
    same(true, $web->json()['ok'], 'sin errores, ok es true');
    $web->get('/admin/media');
    mediaPost($web, '/admin/media/upload', []);
    $none = $web->json();
    same(false, $none['ok']);
    same('No se ha recibido ningún fichero.', $none['errors'][0]['message']);
});

test('sin JavaScript el mismo formulario sube y vuelve a la rejilla con un aviso', function (): void {
    $app = mediaApp();
    $web = adminClient($app);
    $page = $web->get('/admin/media');
    contains('enctype="multipart/form-data"', $page->body);
    contains('name="files[]"', $page->body);
    contains(' multiple', $page->body);
    contains('data-af-dropzone', $page->body);

    $r = mediaPost($web, '/admin/media/upload', [], [mediaImage(500, 400, 'jpeg', 28), mediaFile('texto', 'nota.jpg')], false, ['uno.jpg', 'nota.jpg']);
    same(302, $r->status);
    contains('/admin/media', $r->headers['Location']);
    $after = $web->follow();
    contains('1 imagen subida', $after->body);
    contains('nota.jpg: El fichero no es una imagen admitida', $after->body);
    contains('uno.jpg', $after->body, 'la rejilla ya lo enseña');
    same(1, mediaLib($app)->count());
});

test('el selector devuelve un fragmento sin marco, sin caché, que busca y pagina', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $lib->store(mediaImage(300, 200, 'jpeg', 29), 'sofa-azul.jpg');
    $lib->store(mediaImage(300, 200, 'jpeg', 30), 'mesa.jpg');
    $web = adminClient($app);

    $r = $web->get('/admin/media/picker?multiple=1');
    same(200, $r->status);
    notContains('<html', $r->body);
    notContains('<body', $r->body);
    notContains('<head', $r->body);
    contains('data-af-picker', $r->body);
    contains('data-multiple="1"', $r->body);
    contains('data-t-title="Añadir imágenes"', $r->body);
    contains('data-af-upload-input', $r->body, 'con permiso, lleva la zona de subida');
    same(2, substr_count($r->body, 'data-af-pick data-id='));
    contains('no-store', $r->headers['Cache-Control']);
    same('no-cache', $r->headers['Pragma']);
    same('0', $r->headers['Expires']);
    contains('data-multiple="0"', $web->get('/admin/media/picker?multiple=0')->body);

    $found = $web->get('/admin/media/picker?q=sofa');
    same(1, substr_count($found->body, 'data-af-pick data-id='));
    contains('sofa-azul.jpg', $found->body);
    notContains('mesa.jpg', $found->body);

    mediaFake($app, 24, 'extra');
    $p1 = $web->get('/admin/media/picker?page=1');
    same(24, substr_count($p1->body, 'data-af-pick data-id='));
    contains('data-af-picker-page="2"', $p1->body);
    $p2 = $web->get('/admin/media/picker?page=2');
    same(2, substr_count($p2->body, 'data-af-pick data-id='));
    same(2, substr_count($web->get('/admin/media/picker?page=99')->body, 'data-af-pick data-id='), 'una página de más cae en la última');
});

test('la rejilla busca, filtra por carpeta y pagina de 48 en 48', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $lib->store(mediaImage(300, 200, 'jpeg', 31), 'sofa-azul.jpg', ['folder' => 'Salón']);
    $lib->store(mediaImage(300, 200, 'jpeg', 32), 'mesa.jpg', ['alt' => 'Mesa de roble']);
    $web = adminClient($app);

    $all = $web->get('/admin/media');
    same(2, substr_count($all->body, 'class="af-media-card"'));
    contains('Salón (1)', $all->body, 'el filtro de carpetas');
    $byName = $web->get('/admin/media?q=sofa');
    same(1, substr_count($byName->body, 'class="af-media-card"'));
    notContains('mesa.jpg', $byName->body);
    $byAlt = $web->get('/admin/media?q=roble');
    contains('mesa.jpg', $byAlt->body);
    notContains('sofa-azul.jpg', $byAlt->body);
    $byFolder = $web->get('/admin/media?folder=' . rawurlencode('Salón'));
    same(1, substr_count($byFolder->body, 'class="af-media-card"'));
    contains('sofa-azul.jpg', $byFolder->body);
    contains('Todavía no hay imágenes', $web->get('/admin/media?q=zzzz')->body);

    mediaFake($app, 48, 'p');
    $p1 = $web->get('/admin/media');
    same(48, substr_count($p1->body, 'class="af-media-card"'));
    contains('Página 1 de 2', $p1->body);
    $p2 = $web->get('/admin/media?p=2');
    same(2, substr_count($p2->body, 'class="af-media-card"'));
    contains('Página 2 de 2', $p2->body);
    same(2, substr_count($web->get('/admin/media?p=99')->body, 'class="af-media-card"'));
    same(48, substr_count($web->get('/admin/media?p=-4')->body, 'class="af-media-card"'), 'una página negativa cae en la primera');
});

test('la ficha enseña la vista previa, las medidas, las variantes, la dirección y dónde se usa; 404 si no existe', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $row = $lib->store(mediaImage(1600, 800, 'jpeg', 33), 'ficha.jpg');
    mediaMarkInUse($app, $row['id']);
    $web = adminClient($app);

    $r = $web->get('/admin/media/' . $row['id']);
    same(200, $r->status);
    contains('ficha.jpg', $r->body);
    contains('1600 × 800 px', $r->body);
    contains('image/jpeg', $r->body);
    contains('data-af-copy="' . e($lib->url($row['id'])) . '"', $r->body, 'dirección con botón de copiar');
    contains('480 × 240 px', $r->body, 'variantes con su tamaño');
    contains('Producto: Silla', $r->body);
    contains('name="alt"', $r->body);
    contains('/admin/media/' . $row['id'] . '/regenerate', $r->body);
    contains('Esta imagen está en uso. ¿Borrarla de todos modos?', $r->body);
    contains('name="force"', $r->body);

    $clean = $lib->store(mediaImage(300, 300, 'jpeg', 34), 'libre.jpg');
    $free = $web->get('/admin/media/' . $clean['id']);
    contains('No se usa en ningún sitio.', $free->body);
    notContains('name="force"', $free->body);

    same(404, $web->get('/admin/media/9999')->status);
    $web->get('/admin/media');
    same(404, $web->post('/admin/media/9999', ['_token' => aifecToken($web)])->status);
});

test('guardar el texto alternativo, el título y la carpeta llega a la base y se ve escapado', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $id = $lib->store(mediaImage(400, 300, 'jpeg', 35), 'alt.jpg')['id'];
    $web = adminClient($app);
    $web->get('/admin/media/' . $id);

    $r = $web->post('/admin/media/' . $id, ['_token' => aifecToken($web), 'alt' => 'Foto "A" & <b>B</b>', 'title' => 'Tom & Jerry', 'folder' => 'Cómics']);
    same(302, $r->status);
    $row = $app->db()->row('SELECT alt, title, folder FROM {media} WHERE id = ?', [$id]);
    same('Foto "A" & <b>B</b>', $row['alt'], 'en la base, tal cual');
    same('Tom & Jerry', $row['title']);
    same('Cómics', $row['folder']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'media.update' AND entity_id = ?", [(string) $id]));

    $page = $web->follow();
    contains('Guardado.', $page->body);
    contains('Foto &quot;A&quot; &amp; &lt;b&gt;B&lt;/b&gt;', $page->body);
    notContains('<b>B</b>', $page->body);
    contains('Tom &amp; Jerry', $page->body);
    contains('Foto &quot;A&quot; &amp; &lt;b&gt;B&lt;/b&gt;', $web->get('/admin/media')->body, 'la rejilla también lo escapa');
});

test('borrar: una imagen en uso pide confirmación para forzar y una libre se borra con sus ficheros', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $used = $lib->store(mediaImage(1000, 500, 'jpeg', 36), 'uso.jpg');
    $free = $lib->store(mediaImage(1000, 500, 'jpeg', 37), 'libre.jpg');
    mediaMarkInUse($app, $used['id']);
    $web = adminClient($app);
    $root = mediaRoot($app);

    $web->get('/admin/media/' . $used['id']);
    $r = $web->post('/admin/media/' . $used['id'] . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('/admin/media/' . $used['id'], $r->headers['Location']);
    contains('Esta imagen está en uso. Confirma para borrarla de todos modos.', $web->follow()->body);
    check($lib->get($used['id']) !== null, 'sigue en la base');
    check(is_file($root . '/' . $used['path']), 'y en disco');

    $r = $web->post('/admin/media/' . $used['id'] . '/delete', ['_token' => aifecToken($web), 'force' => '1']);
    same(302, $r->status);
    same(null, $lib->get($used['id']));
    check(!is_file($root . '/' . $used['path']), 'forzado: borrado');
    foreach ($used['variants'] as $variant) {
        check(!is_file($root . '/' . $variant['path']), 'y su variante ' . $variant['path']);
    }
    contains('Imagen borrada.', $web->follow()->body);

    $web->get('/admin/media/' . $free['id']);
    $r = $web->post('/admin/media/' . $free['id'] . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(null, $lib->get($free['id']));
    check(!is_file($root . '/' . $free['path']));
    same(2, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'media.delete'"));
});

test('«Rehacer los tamaños» vuelve a crear las variantes que faltan', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $row = $lib->store(mediaImage(1600, 800, 'jpeg', 38), 'tamanos.jpg');
    $card = mediaRoot($app) . '/' . $row['variants']['card']['path'];
    unlink($card);
    $web = adminClient($app);
    $web->get('/admin/media/' . $row['id']);

    $r = $web->post('/admin/media/' . $row['id'] . '/regenerate', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    check(is_file($card), 'la variante vuelve a estar');
    contains('Los tamaños se han rehecho.', $web->follow()->body);

    unlink(mediaRoot($app) . '/' . $row['path']);
    $web->get('/admin/media/' . $row['id']);
    $web->post('/admin/media/' . $row['id'] . '/regenerate', ['_token' => aifecToken($web)]);
    contains('Falta el fichero original', $web->follow()->body, 'si falla, lo dice');
    same(404, $web->post('/admin/media/9999/regenerate', ['_token' => aifecToken($web)])->status);
});

test('el campo de imagen del formulario trae lo que espera el guion y la dirección del selector', function (): void {
    $app = mediaApp();
    $lib = mediaLib($app);
    $row = $lib->store(mediaImage(600, 400, 'jpeg', 39), 'campo.jpg');
    $form = new Form($app, ['fields' => [
        'photo' => ['type' => 'image', 'label' => 'Photo'],
        'gallery' => ['type' => 'images', 'label' => 'Gallery'],
    ]]);

    $html = $form->render(['photo' => $row['id'], 'gallery' => [$row['id']]]);

    contains('data-picker="' . e($app->url()->plain('/admin/media/picker')) . '"', $html);
    contains('data-multiple="0"', $html);
    contains('data-multiple="1"', $html);
    contains('data-af-media-input', $html);
    contains('data-af-media-list', $html);
    contains('data-af-media-pick', $html);
    contains('data-af-media-remove', $html);
    contains('src="' . e($lib->url($row['id'], 'thumb')) . '"', $html, 'la miniatura sale del servicio «media»');
    check($lib->url($row['id'], 'thumb') !== $lib->url($row['id']), 'y es la variante, no el original');
    contains('value="' . $row['id'] . '"', $html);
});

test('el guion y los estilos se cargan en todo el panel y el guion no lleva ningún texto visible', function (): void {
    $app = mediaApp();
    $web = adminClient($app);
    $home = $web->get('/admin');
    contains('/media.js', $home->body);
    contains('/media.css', $home->body);
    contains('/assets/m/media/', $home->body);
    contains('defer', $home->body);

    $js = (string) file_get_contents(dirname(__DIR__) . '/assets/media.js');
    foreach (['data-af-media-pick', 'data-af-media-input', 'data-af-media-list', 'data-af-media-remove', 'data-multiple', 'data-picker', 'Aifec.post', 'Aifec.toast', 'Aifec.on'] as $needle) {
        contains($needle, $js);
    }
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
    $visible = [];
    if (preg_match_all("/'([^'\\\\\\n]*)'/", $code, $m) > 0) {
        foreach ($m[1] as $literal) {
            if ($literal !== 'use strict' && preg_match('/[A-Za-z]{3,}\s+[A-Za-z]{2,}/', $literal) === 1 && strpbrk($literal, '-[.=_') === false) {
                $visible[] = $literal;
            }
        }
    }
    same([], $visible, 'cadenas visibles escritas en el .js');

    $css = (string) file_get_contents(dirname(__DIR__) . '/assets/media.css');
    same(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) preg_replace('#/\*.*?\*/#s', '', $css)), 'ni un color fijo: solo variables');
});

test('los ajustes del módulo se guardan y rechazan lo que se sale de rango', function (): void {
    $app = mediaApp();
    $web = adminClient($app);
    $page = $web->get('/admin/settings/media');
    same(200, $page->status);
    contains('media.max_mb', $page->body);
    contains('media.webp_quality', $page->body);
    contains('Calidad WebP', $page->body);
    same(8, $app->settings()->int('media.max_mb'));
    same(82, $app->settings()->int('media.webp_quality'));

    $bad = $web->post('/admin/settings/media', ['_token' => aifecToken($web), 'f' => ['media.max_mb' => '8', 'media.webp_quality' => '99']]);
    same(422, $bad->status);
    $app->settings()->reload();
    same(82, $app->settings()->int('media.webp_quality'), 'un valor fuera de rango no se guarda');

    $web->get('/admin/settings/media');
    $ok = $web->post('/admin/settings/media', ['_token' => aifecToken($web), 'f' => ['media.max_mb' => '3', 'media.webp_quality' => '70']]);
    same(302, $ok->status);
    $app->settings()->reload();
    same(3, $app->settings()->int('media.max_mb'));
    same(70, $app->settings()->int('media.webp_quality'));

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/settings/media')->status);
});
