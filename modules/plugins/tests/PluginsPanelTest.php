<?php
/**
 * AIFEC — pruebas del panel «Módulos y plugins» y de las órdenes de consola contract:check, plugin:create y compañía.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;

require_once __DIR__ . '/PluginsKit.php';

/**
 * Manda un formulario del panel con la ficha de la última página abierta.
 *
 * @param array<string,mixed> $data
 */
function pluginsPost(TestClient $web, string $path, array $data = []): \Aifec\Kernel\Response
{
    // La ficha sale de la última página con marco del panel; si la última respuesta fue un 403 o un 302, se abre el inicio.
    if ($web->last === null || strpos($web->last->body, 'name="af-token"') === false) {
        $web->get('/admin');
    }

    return $web->post($path, ['_token' => aifecToken($web)] + $data);
}

/**
 * Ejecuta una orden de consola y devuelve [código de salida, texto].
 *
 * @param array<int|string,string|bool> $args
 *
 * @return array{0:int,1:string}
 */
function pluginsCli(App $app, string $command, array $args = []): array
{
    $io = new Console(false);
    $commands = $io->commands($app);
    check(isset($commands[$command]), 'existe la orden ' . $command);

    return [(int) $commands[$command]['run']($app, $args, $io), $io->output];
}

pluginsTest('el panel enseña los módulos y los plugins con su contrato, y escapa lo que viene de los manifiestos', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    pluginsMake($root, 'pescado', ['manifest' => ['name' => 'Fish & <Chips>', 'contract' => ['hosts' => ['api.proveedor.test'], 'personal_data' => ['email'], 'capabilities' => ['pricing']]]]);
    $app = pluginsApp($root);
    $web = adminClient($app);
    $r = $web->get('/admin/plugins');
    same(200, $r->status);
    contains('Módulos y plugins', $r->body);
    contains('Módulos incluidos', $r->body);
    contains('data-plugin="catalog"', $r->body);
    contains('Fish &amp; &lt;Chips&gt;', $r->body);
    notContains('Fish & <Chips>', $r->body);
    contains('api.proveedor.test', $r->body);
    contains('Sin validar', $r->body);
    contains('data-plugin="pescado"', $r->body);
    contains('Conceder «pricing»', $r->body);
    contains('data-af-confirm', $r->body);
    contains('cambiar precios, impuestos o plazos', $r->body);
});

pluginsTest('sin permiso el panel da 403, con solo «ver» se ve el listado pero no se puede cambiar nada', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'visible');
    $app = pluginsApp($root);
    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/plugins')->status);
    same(403, $support->get('/admin/plugins/new')->status);
    same(403, $support->get('/admin/plugins/visible/report')->status);
    $demo = adminClient($app, 'demo');
    $page = $demo->get('/admin/plugins');
    same(200, $page->status);
    notContains('/admin/plugins/visible/enable', $page->body);
    notContains('Subir ZIP', $page->body);
    same(403, pluginsPost($demo, '/admin/plugins/visible/enable')->status);
    same(403, pluginsPost($demo, '/admin/plugins/visible/validate')->status);
    same(403, pluginsPost($demo, '/admin/plugins/upload')->status);
    same(403, pluginsPost($demo, '/admin/plugins/new', ['id' => 'x-y'])->status);
    same(null, $app->settings()->get('modules.active'));
});

pluginsTest('activar y desactivar desde el panel cambian modules.active, avisan y vuelven a la pestaña de plugins', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'enciende');
    $app = pluginsApp($root);
    $web = adminClient($app);
    $web->get('/admin/plugins');
    $r = pluginsPost($web, '/admin/plugins/enciende/enable');
    same(302, $r->status);
    contains('/admin/plugins#tab=plplugins', $r->headers['Location']);
    check(in_array('enciende', $app->settings()->arr('modules.active'), true), 'quedó activo');
    $page = $web->follow();
    contains('«enciende» está activo', $page->body);
    contains('Desactivar', $page->body);
    $r = pluginsPost($web, '/admin/plugins/enciende/disable');
    same(302, $r->status);
    check(!in_array('enciende', $app->settings()->arr('modules.active'), true), 'quedó apagado');
    contains('«enciende» está apagado', $web->follow()->body);
    same(404, pluginsPost($web, '/admin/plugins/no-hay/enable')->status);
});

pluginsTest('un plugin que no pasa el contrato no se activa desde el panel y el aviso dice por qué', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'peligro', ['files' => ['hooks.php' => pluginsHooks("    shell_exec('ls');")]]);
    $app = pluginsApp($root);
    $web = adminClient($app);
    $web->get('/admin/plugins');
    same(302, pluginsPost($web, '/admin/plugins/peligro/enable')->status);
    $page = $web->follow();
    contains('no pasa el contrato AIFEC', $page->body);
    contains('P1', $page->body);
    same(null, $app->settings()->get('modules.active'));
});

pluginsTest('un plugin que falló al cargar se ve en rojo con su error y se puede desactivar desde el panel', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'tropieza', ['files' => ['hooks.php' => pluginsHooks("    throw new \\RuntimeException('boom <al> cargar');")]]);
    $app = pluginsApp($root);
    same(true, pluginsSvc($app)->activate('tropieza')['ok']);
    $next = pluginsReboot($app, $root);
    $web = adminClient($next);
    $page = $web->get('/admin/plugins');
    same(200, $page->status);
    contains('af-alert af-alert-bad', $page->body);
    contains('boom &lt;al&gt; cargar', $page->body);
    contains('/admin/plugins/tropieza/disable', $page->body);
    same(302, pluginsPost($web, '/admin/plugins/tropieza/disable')->status);
    check(!in_array('tropieza', $next->settings()->arr('modules.active'), true), 'se desactivó');
});

pluginsTest('un plugin con el manifiesto roto sale marcado como no válido en el panel sin tumbar la pantalla', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'roto', ['raw_manifest' => '{esto no es json']);
    pluginsMake($root, 'sano');
    $web = adminClient(pluginsApp($root));
    $page = $web->get('/admin/plugins');
    same(200, $page->status);
    contains('No válido', $page->body);
    contains('El manifiesto no es un JSON válido', $page->body);
    contains('data-plugin="sano"', $page->body);
});

pluginsTest('validar desde el panel lleva al informe, con cada regla, su resultado, el fichero y la línea', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'informe', ['files' => ['hooks.php' => pluginsHooks("    mail('ana@cliente.test', 'x', 'y');")]]);
    $app = pluginsApp($root);
    $web = adminClient($app);
    $empty = $web->get('/admin/plugins/informe/report');
    same(200, $empty->status);
    contains('todavía no se ha validado', $empty->body);
    $web->get('/admin/plugins');
    $r = pluginsPost($web, '/admin/plugins/informe/validate');
    same(302, $r->status);
    contains('/admin/plugins/informe/report', $r->headers['Location']);
    $page = $web->follow();
    same(200, $page->status);
    contains('no pasa el contrato', $page->body);
    contains('Informe del contrato', $page->body);
    contains('Código prohibido', $page->body);
    contains('Envía correo con mail()', $page->body);
    contains('hooks.php:6', $page->body);
    contains('Puntuación', $page->body);
    same(404, $web->get('/admin/plugins/no-hay/report')->status);
    $list = $web->get('/admin/plugins');
    contains('/admin/plugins/informe/report', $list->body);
});

pluginsTest('subir un ZIP desde el panel instala el plugin sin activarlo y un ZIP malo avisa sin instalar nada', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $web = adminClient($app);
    $web->get('/admin/plugins');
    $upload = static function (string $zip) use ($web, $app): \Aifec\Kernel\Response {
        $request = new Request('POST', '/admin/plugins/upload', [], ['_token' => aifecToken($web)], $web->cookies, $web->headers, '', [
            'plugin_zip' => ['name' => 'plugin.zip', 'tmp_name' => $zip, 'size' => (int) filesize($zip), 'type' => 'application/zip', 'error' => 0],
        ]);
        $request->host = 'tienda.test';
        $request->secure = true;
        $web->last = $app->handle($request);

        return $web->last;
    };
    same(302, $upload(pluginsZipOf('subido'))->status);
    check(is_file($root . '/plugins/subido/plugin.json'), 'se instaló');
    contains('«subido» se ha instalado', $web->follow()->body);
    same(null, $app->settings()->get('modules.active'));
    same(302, $upload(pluginsZip(['malo/leeme.txt' => 'hola']))->status);
    contains('no tiene plugin.json', $web->follow()->body);
    check(!is_dir($root . '/plugins/malo'), 'no se instaló lo malo');
    $none = pluginsPost($web, '/admin/plugins/upload');
    same(302, $none->status);
    contains('Elige un fichero ZIP', $web->follow()->body);
});

pluginsTest('conceder una capacidad desde el panel la apunta y retirarla la quita', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'caro', ['manifest' => ['contract' => ['capabilities' => ['pricing']]]]);
    $app = pluginsApp($root);
    $web = adminClient($app);
    $web->get('/admin/plugins');
    same(302, pluginsPost($web, '/admin/plugins/caro/grant', ['capability' => 'pricing', 'on' => '1'])->status);
    same(['caro:pricing'], $app->settings()->arr('plugins.granted'));
    $page = $web->follow();
    contains('Retirar «pricing»', $page->body);
    contains('Concedida', $page->body);
    same(302, pluginsPost($web, '/admin/plugins/caro/grant', ['capability' => 'pricing', 'on' => '0'])->status);
    same([], $app->settings()->arr('plugins.granted'));
    same(302, pluginsPost($web, '/admin/plugins/caro/grant', ['capability' => 'otra', 'on' => '1'])->status);
    same([], $app->settings()->arr('plugins.granted'));
});

pluginsTest('desinstalar desde el panel conserva los datos si la casilla viaja y los borra si no', function (): void {
    $root = pluginsRoot();
    $files = static function (string $table): array {
        return ['manifest' => ['contract' => ['tables' => [$table]]], 'files' => ['migrations/0001_t.php' => pluginsMigration($table)]];
    };
    pluginsMake($root, 'conserva', $files('conserva_data'));
    pluginsMake($root, 'borra', $files('borra_data'));
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same(true, $svc->activate('conserva')['ok']);
    same(true, $svc->activate('borra')['ok']);
    same(true, $svc->disable('conserva')['ok']);
    same(true, $svc->disable('borra')['ok']);
    $web = adminClient($app);
    $web->get('/admin/plugins');
    same(302, pluginsPost($web, '/admin/plugins/conserva/uninstall', ['keep' => '1'])->status);
    check($app->db()->tableExists('conserva_data'), 'la tabla se conserva');
    check(!is_dir($root . '/plugins/conserva'), 'pero la carpeta no');
    contains('se ha desinstalado', $web->follow()->body);
    same(302, pluginsPost($web, '/admin/plugins/borra/uninstall')->status);
    check(!$app->db()->tableExists('borra_data'), 'la tabla se borra');
});

pluginsTest('el asistente del panel crea el plugin, y con un identificador malo vuelve a enseñar el formulario con el error', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $web = adminClient($app);
    $form = $web->get('/admin/plugins/new');
    same(200, $form->status);
    contains('Piezas que se incluyen', $form->body);
    contains('name="features[]"', $form->body);
    $bad = pluginsPost($web, '/admin/plugins/new', ['id' => 'MAL ID', 'name' => 'Algo & más', 'features' => ['setting']]);
    same(422, $bad->status);
    contains('letras minúsculas', $bad->body);
    contains('Algo &amp; más', $bad->body);
    check(!is_dir($root . '/plugins/MAL ID'), 'no creó nada');
    $web->get('/admin/plugins/new');
    $ok = pluginsPost($web, '/admin/plugins/new', ['id' => 'creado', 'name' => 'Creado', 'description' => 'Hecho en el panel', 'features' => ['setting', 'screen', 'no-existe']]);
    same(302, $ok->status);
    check(is_file($root . '/plugins/creado/settings.php'), 'trae el ajuste');
    check(is_file($root . '/plugins/creado/admin.php'), 'trae la pantalla');
    check(!is_file($root . '/plugins/creado/cli.php'), 'y nada más');
    contains('se ha creado', $web->follow()->body);
});

pluginsTest('la orden contract:check enseña el informe y sale con 0 si pasa, con 1 si no y con 2 si no existe', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'bueno');
    pluginsMake($root, 'malo', ['files' => ['hooks.php' => pluginsHooks("    shell_exec('ls');")]]);
    $app = pluginsApp($root);
    [$code, $out] = pluginsCli($app, 'contract:check', ['bueno']);
    same(0, $code, $out);
    contains('[ OK ] C1', $out);
    contains('CONTRACT bueno: score 100/100, 0 errors, 0 warnings — OK', $out);
    [$code, $out] = pluginsCli($app, 'contract:check', ['malo']);
    same(1, $code, $out);
    contains('[FAIL] P1', $out);
    contains('hooks.php:6', $out);
    contains('— FAIL', $out);
    [$code] = pluginsCli($app, 'contract:check', ['no-hay']);
    same(2, $code);
    [$code] = pluginsCli($app, 'contract:check', []);
    same(2, $code);
    [$code] = pluginsCli($app, 'contract:check', ['jobs']);
    same(2, $code);
});

pluginsTest('la orden plugin:create crea el plugin con sus opciones y rechaza lo que no vale', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    [$code, $out] = pluginsCli($app, 'plugin:create', ['desde-consola', 'name' => 'Desde consola', 'features' => 'setting,job,command', 'description' => 'Creado por consola', 'author' => 'Ana']);
    same(0, $code, $out);
    $dir = $root . '/plugins/desde-consola';
    foreach (['plugin.json', 'settings.php', 'jobs.php', 'src/Tasks.php', 'cli.php', 'LEEME.md'] as $file) {
        check(is_file($dir . '/' . $file), 'existe ' . $file);
    }
    check(!is_file($dir . '/admin.php'), 'no crea lo que no se pidió');
    same('Desde consola', json_decode((string) file_get_contents($dir . '/plugin.json'), true)['name']);
    [$code, $out] = pluginsCli($app, 'plugin:create', ['desde-consola']);
    same(1, $code);
    contains('ya existe', $out);
    [$code] = pluginsCli($app, 'plugin:create', []);
    same(2, $code);
    [$code] = pluginsCli($app, 'plugin:create', ['MAL']);
    same(1, $code);
    [$code, $out] = pluginsCli($app, 'plugin:create', ['con-todo', 'features' => 'all']);
    same(0, $code, $out);
    check(is_file($root . '/plugins/con-todo/src/ExamplePayment.php'), 'all incluye todas las piezas');
});

pluginsTest('las órdenes plugin:enable, plugin:disable, plugin:grant, plugin:uninstall y plugin:install recorren el ciclo', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'ciclo', ['manifest' => ['contract' => ['capabilities' => ['pricing']]]]);
    $app = pluginsApp($root);
    [$code, $out] = pluginsCli($app, 'plugin:enable', ['ciclo']);
    same(0, $code, $out);
    check(in_array('ciclo', $app->settings()->arr('modules.active'), true), 'activo');
    [$code] = pluginsCli($app, 'plugin:grant', ['ciclo', 'pricing']);
    same(0, $code);
    same(['ciclo:pricing'], $app->settings()->arr('plugins.granted'));
    [$code] = pluginsCli($app, 'plugin:grant', ['ciclo', 'pricing', 'off' => true]);
    same(0, $code);
    same([], $app->settings()->arr('plugins.granted'));
    [$code, $out] = pluginsCli($app, 'plugin:uninstall', ['ciclo']);
    same(1, $code);
    contains('Apaga', $out);
    [$code] = pluginsCli($app, 'plugin:disable', ['ciclo']);
    same(0, $code);
    [$code] = pluginsCli($app, 'plugin:uninstall', ['ciclo', 'keep-data' => true]);
    same(0, $code);
    check(!is_dir($root . '/plugins/ciclo'), 'desinstalado');
    [$code, $out] = pluginsCli($app, 'plugin:install', [pluginsZipOf('instalado')]);
    same(0, $code, $out);
    check(is_dir($root . '/plugins/instalado'), 'instalado desde el ZIP');
    [$code] = pluginsCli($app, 'plugin:install', []);
    same(2, $code);
    [$code] = pluginsCli($app, 'plugin:enable', ['no-hay']);
    same(1, $code);
});
