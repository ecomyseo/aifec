<?php
/**
 * AIFEC — pruebas del gestor de plugins: listado, requisitos, activar, desactivar, desinstalar y conceder capacidades.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Plugins\Plugins;

require_once __DIR__ . '/PluginsKit.php';

pluginsTest('el listado trae los módulos y los plugins con sus datos y su declaración', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    pluginsMake($root, 'plug-uno', ['manifest' => ['name' => 'Plug uno', 'requires' => ['modules' => ['catalog'], 'php' => '>=7.4'], 'contract' => ['hosts' => ['api.proveedor.test'], 'personal_data' => ['email'], 'capabilities' => ['pricing']]]]);
    $app = pluginsApp($root);
    $rows = pluginsSvc($app)->all();
    $by = pluginsById($rows);
    same('module', $by['catalog']['kind']);
    same('plugin', $by['plug-uno']['kind']);
    same('module', $rows[0]['kind']);
    same('plugin', $rows[count($rows) - 1]['kind']);
    same(true, $by['catalog']['active']);
    same(false, $by['plug-uno']['active']);
    same(true, $by['jobs']['essential']);
    same('Plug uno', $by['plug-uno']['name']);
    same('1.0.0', $by['plug-uno']['version']);
    same(['catalog'], $by['plug-uno']['requires']['modules']);
    same('>=7.4', $by['plug-uno']['requires']['php']);
    same(['api.proveedor.test'], $by['plug-uno']['contract']['hosts']);
    same(['pricing'], $by['plug-uno']['contract']['capabilities']);
    same(null, $by['plug-uno']['report']);
    same('', $by['plug-uno']['error']);
    same(true, $by['plug-uno']['valid']);
});

pluginsTest('un plugin con el manifiesto roto sale como no válido y no tumba el listado', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'roto', ['raw_manifest' => '{esto no es json']);
    pluginsMake($root, 'sano');
    $by = pluginsById(pluginsSvc(pluginsApp($root))->all());
    same(false, $by['roto']['valid']);
    same('plugin', $by['roto']['kind']);
    check($by['roto']['error'] !== '', 'dice por qué');
    same(true, $by['sano']['valid']);
    same(false, $by['roto']['active']);
});

pluginsTest('activar un plugin con requisitos que faltan da un error claro y no cambia nada', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'pide-mucho', ['manifest' => ['requires' => ['modules' => ['no-existe']]]]);
    $app = pluginsApp($root);
    $r = pluginsSvc($app)->enable('pide-mucho');
    same(false, $r['ok']);
    contains('no-existe', (string) $r['message']);
    same(null, $app->settings()->get('modules.active'));
    $r = pluginsSvc($app)->enable('no-hay-tal');
    same(false, $r['ok']);
    contains('no-hay-tal', (string) $r['message']);
});

pluginsTest('un módulo necesario que está instalado pero apagado también impide activar', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    pluginsMake($root, 'pide-catalogo', ['manifest' => ['requires' => ['modules' => ['catalog']]]]);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same(true, $svc->disable('catalog')['ok']);
    $r = $svc->enable('pide-catalogo');
    same(false, $r['ok']);
    contains('catalog', (string) $r['message']);
});

pluginsTest('los requisitos de versión de PHP y de AIFEC se comprueban', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'php-futuro', ['manifest' => ['requires' => ['php' => '>=99.0']]]);
    pluginsMake($root, 'aifec-futuro', ['manifest' => ['requires' => ['aifec' => '>=9.0.0']]]);
    $svc = pluginsSvc(pluginsApp($root));
    $r = $svc->enable('php-futuro');
    same(false, $r['ok']);
    contains('99.0', (string) $r['message']);
    $r = $svc->enable('aifec-futuro');
    same(false, $r['ok']);
    contains('9.0.0', (string) $r['message']);
    check(Plugins::versionOk('8.3.1', '>=7.4'), '8.3.1 cumple >=7.4');
    check(!Plugins::versionOk('7.4.0', '>=8.0'), '7.4.0 no cumple >=8.0');
    check(Plugins::versionOk('1.2.0', '>=1.0.0,<2'), '1.2.0 cumple el intervalo');
    check(!Plugins::versionOk('2.0.0', '>=1.0.0,<2'), '2.0.0 no cumple el intervalo');
    check(!Plugins::versionOk('1.0.0', 'cualquier cosa'), 'una restricción rara no se da por buena');
});

pluginsTest('no se desactiva un módulo esencial', function (): void {
    $app = pluginsApp(pluginsRoot());
    $r = pluginsSvc($app)->disable('jobs');
    same(false, $r['ok']);
    contains('esencial', (string) $r['message']);
});

pluginsTest('no se desactiva un plugin del que depende otro activo, y lo dice con nombres', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'base-a', ['manifest' => ['name' => 'Base A']]);
    pluginsMake($root, 'dep-b', ['manifest' => ['name' => 'Dep B', 'requires' => ['modules' => ['base-a']]]]);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same(true, $svc->activate('base-a')['ok']);
    same(true, $svc->activate('dep-b')['ok']);
    $r = $svc->disable('base-a');
    same(false, $r['ok']);
    contains('Dep B', (string) $r['message']);
    same(['Dep B'], $r['needed_by']);
    same(true, $svc->disable('dep-b')['ok']);
    same(true, $svc->disable('base-a')['ok']);
});

pluginsTest('activar un plugin aplica sus migraciones, lo apunta como activo y guarda su informe con fecha y versión', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'migra', ['manifest' => ['contract' => ['tables' => ['migra_items']]], 'files' => ['migrations/0001_items.php' => pluginsMigration('migra_items')]]);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    check(!$app->db()->tableExists('migra_items'), 'todavía no existe');
    $r = $svc->enable('migra');
    same(true, $r['ok'], (string) json_encode($r));
    check($app->db()->tableExists('migra_items'), 'la migración se ha aplicado');
    check(in_array('migra', $app->settings()->arr('modules.active'), true), 'está en modules.active');
    check(in_array('jobs', $app->settings()->arr('modules.active'), true), 'y los esenciales siguen');
    $report = $app->settings()->arr('plugins.reports')['migra'];
    same('1.0.0', $report['version']);
    same('2026-11-12 12:00:00', $report['date']);
    same(true, $report['ok']);
    same(true, $report['dynamic']);
    same(true, $svc->find('migra')['active']);
    $again = $svc->enable('migra');
    same(true, $again['ok']);
    contains('ya está activo', (string) $again['message']);
});

pluginsTest('un plugin con un fallo que bloquea no se activa y modules.active no cambia', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'malo', ['files' => ['hooks.php' => pluginsHooks("    shell_exec('ls');")]]);
    $app = pluginsApp($root);
    $r = pluginsSvc($app)->enable('malo');
    same(false, $r['ok']);
    check(isset($r['errors']) && count($r['errors']) >= 1, 'enseña los fallos');
    contains('P1', implode("\n", $r['errors']));
    same(null, $app->settings()->get('modules.active'));
    same(false, pluginsSvc($app)->find('malo')['active']);
});

pluginsTest('un plugin que lanza en su hooks.php queda como fallido y la tienda sigue respondiendo', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'tropieza', ['files' => ['hooks.php' => pluginsHooks("    throw new \\RuntimeException('boom al cargar');")]]);
    $app = pluginsApp($root);
    same(true, pluginsSvc($app)->activate('tropieza')['ok']);
    $next = pluginsReboot($app, $root);
    check(isset($next->modules()->failed()['tropieza']), 'queda como fallido');
    check(!$next->modules()->isLoaded('tropieza'), 'no se carga');
    $row = pluginsSvc($next)->find('tropieza');
    contains('boom al cargar', $row['error']);
    same(true, $row['active']);
    $web = new TestClient($next);
    same(200, $web->get('/')->status);
    // Y se puede desactivar aunque haya fallado.
    same(true, pluginsSvc($next)->disable('tropieza')['ok']);
});

pluginsTest('desactivar quita el plugin de modules.active y volver a desactivar no falla', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'enciende');
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same(true, $svc->enable('enciende')['ok']);
    $r = $svc->disable('enciende');
    same(true, $r['ok']);
    check(!in_array('enciende', $app->settings()->arr('modules.active'), true), 'ya no está');
    $again = $svc->disable('enciende');
    same(true, $again['ok']);
    contains('ya está apagado', (string) $again['message']);
});

pluginsTest('desinstalar sin conservar los datos borra sus tablas declaradas, sus ajustes y sus migraciones, y deja lo que no declaró', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'borra-me', ['manifest' => ['contract' => ['tables' => ['borra_data']]], 'files' => ['migrations/0001_data.php' => pluginsMigration('borra_data', 'borra_extra')]]);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same(true, $svc->activate('borra-me')['ok']);
    $app->settings()->set('borra-me.opcion', 1);
    $app->settings()->set('borra_me.otra', 2);
    $app->settings()->set('otro.ajeno', 3);
    $app->db()->insert('borra_data', ['name' => 'x']);
    same(true, $svc->disable('borra-me')['ok']);
    $r = $svc->uninstall('borra-me', false);
    same(true, $r['ok'], (string) json_encode($r));
    same(['borra_data'], $r['dropped_tables']);
    check(!$app->db()->tableExists('borra_data'), 'la tabla declarada se borra');
    check($app->db()->tableExists('borra_extra'), 'la que no declaró se queda');
    same(false, $app->settings()->has('borra-me.opcion'));
    same(false, $app->settings()->has('borra_me.otra'));
    same(3, $app->settings()->get('otro.ajeno'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {migrations} WHERE scope = ?', ['borra-me']));
    check(!is_dir($root . '/plugins/borra-me'), 'su carpeta se borra');
    check(!isset($app->settings()->arr('plugins.reports')['borra-me']), 'y su informe');
});

pluginsTest('desinstalar conservando los datos no borra ni tablas ni ajustes ni migraciones', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'guarda-me', ['manifest' => ['contract' => ['tables' => ['guarda_data']]], 'files' => ['migrations/0001_data.php' => pluginsMigration('guarda_data')]]);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same(true, $svc->activate('guarda-me')['ok']);
    $app->settings()->set('guarda-me.opcion', 1);
    $app->db()->insert('guarda_data', ['name' => 'x']);
    same(true, $svc->disable('guarda-me')['ok']);
    $r = $svc->uninstall('guarda-me', true);
    same(true, $r['ok']);
    same([], $r['dropped_tables']);
    check($app->db()->tableExists('guarda_data'), 'la tabla sigue');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {guarda_data}'));
    same(1, $app->settings()->get('guarda-me.opcion'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {migrations} WHERE scope = ?', ['guarda-me']));
    check(!is_dir($root . '/plugins/guarda-me'), 'la carpeta sí se borra');
});

pluginsTest('no se desinstala un plugin activo ni un módulo, y nunca se borra una tabla del sistema aunque el plugin la declare', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    pluginsMake($root, 'ladron', ['manifest' => ['contract' => ['tables' => ['settings', 'users', 'products']]]]);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same(true, $svc->activate('ladron')['ok']);
    $r = $svc->uninstall('ladron', false);
    same(false, $r['ok']);
    check(is_dir($root . '/plugins/ladron'), 'sigue ahí');
    same(false, $svc->uninstall('catalog', false)['ok']);
    same(true, $svc->disable('ladron')['ok']);
    $r = $svc->uninstall('ladron', false);
    same(true, $r['ok']);
    same([], $r['dropped_tables']);
    check($app->db()->tableExists('settings') && $app->db()->tableExists('users') && $app->db()->tableExists('products'), 'las tablas del sistema siguen');
});

pluginsTest('un plugin no puede llamarse como una zona de ajustes del sistema, así que desinstalarlo nunca borra ajustes ajenos', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'shop', ['manifest' => ['name' => 'Falso shop']]);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    check($svc->isReservedId('shop'), 'shop está reservado');
    check($svc->isReservedId('mi-tienda') === false, 'mi-tienda no');
    $app->settings()->set('shop.name', 'Mi tienda');
    same(true, $svc->activate('shop')['ok']);
    same(true, $svc->disable('shop')['ok']);
    same(true, $svc->uninstall('shop', false)['ok']);
    same('Mi tienda', $app->settings()->get('shop.name'));
});

pluginsTest('conceder y retirar «pricing» cambia plugins.granted y solo vale para lo que el plugin declaró', function (): void {
    $root = pluginsRoot();
    pluginsMake($root, 'caro', ['manifest' => ['contract' => ['capabilities' => ['pricing']]]]);
    pluginsMake($root, 'barato');
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    same([], $app->settings()->arr('plugins.granted'));
    same(true, $svc->grant('caro', 'pricing', true)['ok']);
    same(['caro:pricing'], $app->settings()->arr('plugins.granted'));
    same(['pricing'], $svc->find('caro')['granted']);
    same(true, $svc->grant('caro', 'pricing', true)['ok']);
    same(['caro:pricing'], $app->settings()->arr('plugins.granted'));
    same(true, $svc->grant('caro', 'pricing', false)['ok']);
    same([], $app->settings()->arr('plugins.granted'));
    same([], $svc->find('caro')['granted']);
    same(false, $svc->grant('caro', 'otra', true)['ok']);
    same(false, $svc->grant('barato', 'pricing', true)['ok']);
    same(false, $svc->grant('catalog', 'pricing', true)['ok']);
    same(false, $svc->grant('no-hay', 'pricing', true)['ok']);
});
