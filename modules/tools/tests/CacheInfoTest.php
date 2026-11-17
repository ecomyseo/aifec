<?php
/**
 * AIFEC — pruebas de las cachés (cuánto ocupan y cómo se vacían), de los recursos estáticos y de la información del sistema.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Tools\CacheTools;
use Aifec\Modules\Tools\SystemInfo;

require_once __DIR__ . '/helpers.php';

test('la caché separa los datos de las páginas, cuenta lo que ocupa cada una y vacía solo lo que se pide', function (): void {
    $app = toolsApp(['tools']);
    $cache = $app->cache();
    $before = CacheTools::stats($app);
    $cache->set('datos:uno', ['a' => 1], 600, ['catalog']);
    $cache->set('datos:dos', ['b' => str_repeat('x', 5000)], 600);
    $cache->set('page:uno', ['b' => '<html>uno</html>', 'h' => []], 600, ['pages']);
    $cache->set('page:dos', ['b' => '<html>dos</html>', 'h' => []], 600, ['pages', 'catalog']);

    $stats = CacheTools::stats($app);
    same($before['data']['files'] + 2, $stats['data']['files']);
    same($before['pages']['files'] + 2, $stats['pages']['files']);
    check($stats['data']['bytes'] > $before['data']['bytes'] + 5000, 'los bytes de los datos suben');
    check($stats['pages']['bytes'] > $before['pages']['bytes'], 'los bytes de las páginas suben');

    $done = CacheTools::clear($app, 'pages');
    same(2, $done['files']);
    check($done['bytes'] > 0);
    $after = CacheTools::stats($app);
    same(0, $after['pages']['files']);
    same($stats['data']['files'], $after['data']['files'], 'los datos no se tocan');
    same(['a' => 1], $cache->get('datos:uno'));
    same(null, $cache->get('page:uno'), 'las páginas ya no están');

    $done = CacheTools::clear($app, 'data');
    same($stats['data']['files'], $done['files']);
    same(0, CacheTools::stats($app)['data']['files']);
    same(null, $cache->get('datos:uno'));
    same(null, $cache->get('datos:dos'));
    same(['files' => 0, 'bytes' => 0], CacheTools::clear($app, 'data'), 'vaciar lo vacío no falla');
});

test('la pantalla de caché enseña lo que ocupa y los botones vacían, con permiso, un aviso y auditoría', function (): void {
    $app = toolsApp(['tools']);
    $app->cache()->set('datos:uno', ['a' => 1], 600);
    $app->cache()->set('page:uno', ['b' => 'x', 'h' => []], 600, ['pages']);
    $web = adminClient($app);

    $r = $web->get('/admin/tools/cache');
    same(200, $r->status);
    contains('Caché de datos', $r->body);
    contains('Caché de páginas', $r->body);
    contains('1 página', $r->body);
    contains('Publicar los recursos estáticos', $r->body);

    $r = $web->post('/admin/tools/cache/clear-pages', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('Caché de páginas vaciada: 1 ficheros', $web->follow()->body);
    same(0, CacheTools::stats($app)['pages']['files']);

    $r = $web->post('/admin/tools/cache/clear-data', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('Caché de datos vaciada', $web->follow()->body);
    same(0, CacheTools::stats($app)['data']['files']);
    same(2, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.cache_clear'"));
});

test('publicar los recursos estáticos copia los de los módulos a la carpeta pública y anota la versión', function (): void {
    $app = toolsApp(['tools']);
    $web = adminClient($app);
    $build = rtrim(str_replace('\\', '/', $app->publicDir()), '/') . '/assets/build.txt';
    check(!is_file($build), 'antes no hay nada publicado');

    $r = $web->post('/admin/tools/cache/publish-assets', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    $page = $web->follow();
    contains('Recursos estáticos publicados', $page->body);
    check(is_file($build), 'se anota la versión');
    check(preg_match('/^[a-z0-9]{4,16}$/', trim((string) file_get_contents($build))) === 1);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.publish_assets'"));
});

test('la información del sistema trae versiones, módulos, tema y tamaño de datos, y ningún secreto', function (): void {
    $app = toolsApp(['tools', 'jobs']);
    $info = SystemInfo::collect($app);
    same(\Aifec\Kernel\App::VERSION, $info['aifec']);
    same(PHP_VERSION, $info['php']);
    same('SQLite', $info['database']['driver']);
    check($info['database']['version'] !== '');
    check(in_array('json', $info['extensions'], true) && in_array('pdo_sqlite', $info['extensions'], true));
    $ids = array_column($info['modules'], 'id');
    check(in_array('tools', $ids, true) && in_array('jobs', $ids, true));
    foreach ($info['modules'] as $module) {
        if ($module['id'] === 'tools') {
            same('Tools', $module['name']);
            same('1.0.0', $module['version']);
            same(true, $module['loaded']);
        }
    }
    check($info['data']['bytes'] > 0);
    same('test', $info['site']);
    foreach (['secret', 'password', 'db'] as $forbidden) {
        check(!array_key_exists($forbidden, $info), 'la información no lleva «' . $forbidden . '»');
    }
    notContains(str_repeat('k', 48), (string) json_encode($info));
});

test('la pantalla de información no enseña la clave del sitio ni valores de configuración, y pide permiso', function (): void {
    $app = toolsApp(['tools'], ['mail.smtp_host' => 'smtp.example.com', 'store.vat_id' => '12345678Z']);
    $app->setSecret('tools.prueba', 'clave-de-mentira-9876');
    $web = adminClient($app);
    $r = $web->get('/admin/tools/info');
    same(200, $r->status);
    contains(PHP_VERSION, $r->body);
    contains('Información del sistema', $r->body);
    contains('Tools', $r->body);
    contains('1.0.0', $r->body);
    contains('SQLite', $r->body);
    notContains(str_repeat('k', 48), $r->body, 'la clave del sitio no sale');
    notContains('clave-de-mentira-9876', $r->body);
    notContains('smtp.example.com', $r->body);
    notContains('12345678Z', $r->body);
    notContains('clave-larga-1234', $r->body);

    same(403, adminClient($app, 'support')->get('/admin/tools/info')->status);
});
