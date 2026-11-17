<?php
/**
 * AIFEC — pruebas de las redirecciones en marcha y del registro de direcciones que no existen: ruta exacta y con comodín, contador,
 * 301 y 302, 404 apuntado con su contador, el tope de 5.000 filas, el recurso del panel y la pantalla «No encontradas».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/helpers.php';

/**
 * @param array<string,mixed> $values
 */
function cmsAddRedirect(App $app, array $values): int
{
    return $app->db()->insert('redirects', $values + ['source' => 'viejo', 'target' => '/nuevo', 'code' => 301, 'hits' => 0, 'active' => 1, 'created_at' => $app->clock()->sql()]);
}

/**
 * @param array<string,mixed> $f
 */
function cmsSaveRedirect(TestClient $web, array $f, ?int $id = null): Response
{
    $web->get('/admin/r/redirects/' . ($id !== null ? (string) $id : 'new'));

    return $web->post('/admin/r/redirects/' . ($id !== null ? $id . '/save' : 'save'), ['_token' => aifecToken($web), 'f' => $f + ['source' => 'viejo', 'target' => '/nuevo', 'code' => '301', 'active' => '1', 'note' => '', 'created_at' => '']]);
}

test('una redirección exacta responde con su destino y suma su contador; la ruta se compara sin barras', function (): void {
    $app = cmsApp();
    $id = cmsAddRedirect($app, ['source' => 'viejo/pagina', 'target' => '/nueva/pagina']);
    $web = new TestClient($app);
    $r = $web->get('/viejo/pagina');
    same(301, $r->status);
    same('/nueva/pagina', $r->headers['Location']);
    same(1, (int) $app->db()->value('SELECT hits FROM {redirects} WHERE id = ?', [$id]));
    $web->get('/viejo/pagina/');
    same(2, (int) $app->db()->value('SELECT hits FROM {redirects} WHERE id = ?', [$id]), 'con barra final es la misma');
    same(404, $web->get('/viejo')->status, 'una ruta más corta no casa');
    same(404, $web->get('/viejo/pagina/mas')->status, 'ni una más larga');
    same(404, $web->get('/Viejo/Pagina')->status, 'ni con otras mayúsculas');
    same(2, (int) $app->db()->value('SELECT hits FROM {redirects} WHERE id = ?', [$id]), 'lo que no casa no cuenta');
});

test('una redirección 302 es temporal y una a una dirección completa se respeta tal cual', function (): void {
    $app = cmsApp();
    cmsAddRedirect($app, ['source' => 'oferta', 'target' => '/temporada', 'code' => 302]);
    cmsAddRedirect($app, ['source' => 'fuera', 'target' => 'https://otra.test/x?a=1']);
    $web = new TestClient($app);
    $a = $web->get('/oferta');
    same(302, $a->status);
    same('/temporada', $a->headers['Location']);
    $b = $web->get('/fuera');
    same(301, $b->status);
    same('https://otra.test/x?a=1', $b->headers['Location']);
});

test('con un comodín final gana el prefijo más largo y un * en el destino recibe el resto', function (): void {
    $app = cmsApp();
    cmsAddRedirect($app, ['source' => 'blog-viejo/*', 'target' => '/blog/*']);
    cmsAddRedirect($app, ['source' => 'blog-viejo/especial/*', 'target' => '/destacados']);
    cmsAddRedirect($app, ['source' => 'catalogo*', 'target' => '/tienda']);
    $web = new TestClient($app);
    $r = $web->get('/blog-viejo/2020/mi-post');
    same(301, $r->status);
    same('/blog/2020/mi-post', $r->headers['Location']);
    same('/destacados', $web->get('/blog-viejo/especial/uno')->headers['Location'], 'el más largo');
    same('/tienda', $web->get('/catalogo-2019')->headers['Location'], 'sin barra antes del *');
    same('/tienda', $web->get('/catalogo')->headers['Location'], 'y la propia raíz');
    same(404, $web->get('/otro/blog-viejo/x')->status, 'el comodín solo vale como prefijo');
});

test('una redirección en pausa no se aplica y la dirección se apunta como no encontrada', function (): void {
    $app = cmsApp();
    $id = cmsAddRedirect($app, ['source' => 'pausada', 'target' => '/otra', 'active' => 0]);
    $web = new TestClient($app);
    same(404, $web->get('/pausada')->status);
    same(0, (int) $app->db()->value('SELECT hits FROM {redirects} WHERE id = ?', [$id]));
    same(1, (int) $app->db()->value("SELECT hits FROM {not_found} WHERE path = 'pausada'"));
});

test('el idioma de la dirección no impide la redirección y el destino lo conserva', function (): void {
    $app = cmsApp();
    cmsAddRedirect($app, ['source' => 'viejo', 'target' => '/nuevo']);
    $web = new TestClient($app);
    same('/en/nuevo', $web->get('/en/viejo')->headers['Location']);
    same('/nuevo', $web->get('/viejo')->headers['Location']);
});

test('una redirección a sí misma no hace un bucle', function (): void {
    $app = cmsApp();
    cmsAddRedirect($app, ['source' => 'bucle', 'target' => '/bucle']);
    cmsAddRedirect($app, ['source' => 'raiz/*', 'target' => '/raiz/*']);
    $web = new TestClient($app);
    same(404, $web->get('/bucle')->status);
    same(404, $web->get('/raiz/algo')->status);
});

test('una dirección que no existe se apunta con su contador, su última vez y su origen', function (): void {
    $app = cmsApp();
    $web = new TestClient($app);
    same(404, $web->get('/no-existe')->status);
    $row = $app->db()->row("SELECT * FROM {not_found} WHERE path = 'no-existe'");
    same(1, (int) $row['hits']);
    same('2026-11-12 12:00:00', $row['first_at']);
    same(null, $row['referer']);
    $app->clock()->advance(600);
    $web->get('/no-existe/?utm=1', ['referer' => 'https://buscador.test/?q=mesa']);
    $row = $app->db()->row("SELECT * FROM {not_found} WHERE path = 'no-existe'");
    same(2, (int) $row['hits'], 'con barra y con parámetros es la misma dirección');
    same('2026-11-12 12:00:00', $row['first_at']);
    same('2026-11-12 12:10:00', $row['last_at']);
    same('https://buscador.test/?q=mesa', $row['referer']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {not_found}'));
});

test('solo se apuntan las peticiones de lectura y las direcciones que caben', function (): void {
    $app = cmsApp();
    $web = new TestClient($app);
    $web->post('/no-existe-post', ['a' => '1']);
    $web->get('/' . str_repeat('a', 200));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {not_found}'));
    $web->get('/ñandú/ruta con espacios');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {not_found}'), 'tildes y espacios no rompen nada');
});

test('el registro de direcciones que no existen guarda como mucho 5.000 y borra las menos recientes', function (): void {
    $app = cmsApp();
    $app->boot();
    $db = $app->db();
    $db->transaction(static function () use ($db): void {
        for ($i = 1; $i <= 5000; ++$i) {
            $db->insert('not_found', ['path' => 'antigua-' . $i, 'hits' => 1, 'first_at' => '2026-11-01 00:00:00', 'last_at' => sprintf('2026-11-01 %02d:%02d:%02d', intdiv($i, 3600), intdiv($i % 3600, 60), $i % 60)]);
        }
    });
    same(5000, (int) $db->value('SELECT COUNT(*) FROM {not_found}'));
    $web = new TestClient($app);
    $web->get('/nueva-direccion');
    same(5000, (int) $db->value('SELECT COUNT(*) FROM {not_found}'), 'sigue en 5.000');
    same(1, (int) $db->value("SELECT COUNT(*) FROM {not_found} WHERE path = 'nueva-direccion'"), 'la nueva está');
    same(0, (int) $db->value("SELECT COUNT(*) FROM {not_found} WHERE path = 'antigua-1'"), 'la menos reciente se fue');
    same(1, (int) $db->value("SELECT COUNT(*) FROM {not_found} WHERE path = 'antigua-5000'"), 'la más reciente de las antiguas se queda');
    $web->get('/nueva-direccion');
    same(5000, (int) $db->value('SELECT COUNT(*) FROM {not_found}'), 'repetir una no borra nada');
});

test('el panel crea una redirección normalizando el origen y rechaza las repetidas, los bucles y los destinos peligrosos', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $r = cmsSaveRedirect($web, ['source' => 'https://tienda.test/Viejo/Pagina/?x=1', 'target' => 'nueva/pagina', 'code' => '302']);
    same(302, $r->status);
    $row = $app->db()->row('SELECT * FROM {redirects} WHERE id = 1');
    same('Viejo/Pagina', $row['source']);
    same('/nueva/pagina', $row['target'], 'el destino del sitio lleva su barra');
    same(302, (int) $row['code']);
    same('2026-11-12 12:00:00', $row['created_at']);

    same(422, cmsSaveRedirect($web, ['source' => '/Viejo/Pagina/'])->status, 'ya existe');
    same(422, cmsSaveRedirect($web, ['source' => 'a/*/b'])->status, 'el * solo al final');
    same(422, cmsSaveRedirect($web, ['source' => 'misma', 'target' => '/misma'])->status, 'bucle');
    same(422, cmsSaveRedirect($web, ['source' => 'otra', 'target' => 'javascript:alert(1)'])->status);
    same(422, cmsSaveRedirect($web, ['source' => 'otra', 'target' => '//malo.test/x'])->status, 'una dirección sin protocolo hacia otro dominio');
    same(422, cmsSaveRedirect($web, ['source' => '', 'target' => '/x'])->status);
    same(422, cmsSaveRedirect($web, ['source' => '/', 'target' => '/x'])->status, 'la portada no se redirige');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'));

    same(302, cmsSaveRedirect($web, ['source' => 'viejo/pagina', 'target' => 'https://otra.test/', 'code' => '301'], 1)->status, 'editar la propia no choca consigo misma');
    same('https://otra.test/', (string) $app->db()->value('SELECT target FROM {redirects} WHERE id = 1'));
    same('2026-11-12 12:00:00', (string) $app->db()->value('SELECT created_at FROM {redirects} WHERE id = 1'), 'la fecha de alta no cambia');
});

test('el listado de redirecciones enseña el contador y escapa lo que se escribió', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsAddRedirect($app, ['source' => 'a<b>c', 'target' => '/x?a=1&b=2', 'hits' => 7, 'note' => 'nota']);
    $list = $web->get('/admin/r/redirects')->body;
    contains('a&lt;b&gt;c', $list);
    notContains('a<b>c', $list);
    contains('/x?a=1&amp;b=2', $list);
    contains('Permanente (301)', $list);
    same(200, $web->get('/admin/r/redirects/1')->status);
});

test('desde «No encontradas» se crea una redirección con un clic y la dirección sale de la lista', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $public = new TestClient($app);
    $public->get('/rota?a=<b>1</b>', ['referer' => 'https://buscador.test/?q=<script>']);
    $public->get('/rota');
    $public->get('/otra-rota');

    $screen = $web->get('/admin/cms/not-found');
    same(200, $screen->status);
    contains('/rota', $screen->body);
    contains('/otra-rota', $screen->body);
    contains('&lt;script&gt;', $screen->body, 'el origen apuntado sale escapado');
    notContains('?q=<script>', $screen->body);
    check(strpos($screen->body, '/rota') < strpos($screen->body, '/otra-rota'), 'la más pedida primero');

    $r = $web->post('/admin/cms/not-found/redirect', ['_token' => aifecToken($web), 'path' => 'rota', 'target' => '/tienda', 'code' => '301']);
    same(302, $r->status);
    $row = $app->db()->row("SELECT * FROM {redirects} WHERE source = 'rota'");
    same('/tienda', $row['target']);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {not_found} WHERE path = 'rota'"), 'sale de la lista');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {not_found}'));
    same('/tienda', $public->get('/rota')->headers['Location'], 'y ya redirige');

    $web->get('/admin/cms/not-found');
    $web->post('/admin/cms/not-found/redirect', ['_token' => aifecToken($web), 'path' => 'otra-rota', 'target' => 'javascript:alert(1)']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'un destino peligroso no se crea');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {not_found} WHERE path = 'otra-rota'"));
    contains('Escribe una dirección completa', $web->follow()->body);
});

test('«No encontradas» permite descartar una dirección o vaciar la lista', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $public = new TestClient($app);
    foreach (['uno', 'dos', 'tres'] as $path) {
        $public->get('/' . $path);
    }
    $web->get('/admin/cms/not-found');
    $web->post('/admin/cms/not-found/clear', ['_token' => aifecToken($web), 'path' => 'dos']);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {not_found}'));
    $web->get('/admin/cms/not-found');
    $web->post('/admin/cms/not-found/clear', ['_token' => aifecToken($web)]);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {not_found}'));
    contains('No hay direcciones que falten', $web->get('/admin/cms/not-found')->body);
});

test('sin el módulo de contenidos la dirección que no existe sigue dando 404 sin tocar las tablas del núcleo', function (): void {
    $app = testApp();
    $app->boot();
    $web = new TestClient($app);
    same(404, $web->get('/lo-que-sea')->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {not_found}'));
});
