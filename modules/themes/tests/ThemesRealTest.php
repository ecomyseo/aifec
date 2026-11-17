<?php
/**
 * AIFEC — el módulo de temas sobre el código REAL del proyecto (sin raíz de mentira): el tema base que se entrega pasa el
 * validador, las pantallas abren y las órdenes de consola están registradas. Solo lee: no escribe nada en themes/.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;

require_once __DIR__ . '/ThemesKit.php';

themesTest('sobre la instalación real: el tema base que se entrega pasa el validador, también en vivo', function (): void {
    $app = testApp(['modules' => ['themes']]);
    $app->boot();
    $report = themesSvc($app)->validate('base', true);
    same(true, $report['ok'], json_encode(themesFailed($report)));
    same([], array_diff(themesFailed($report, 'warning'), ['escaping']));
    same('base', themesSvc($app)->activeSlug());
    $rows = [];
    foreach (themesSvc($app)->all() as $row) {
        $rows[$row['slug']] = $row;
    }
    check(isset($rows['base']), 'el tema base está en la lista');
    same(true, $rows['base']['validation']['ok']);
});

themesTest('sobre la instalación real: el panel abre sus pantallas y las órdenes de consola están registradas', function (): void {
    $app = testApp(['modules' => ['themes']]);
    $web = adminClient($app);
    foreach (['/admin/themes', '/admin/themes/home', '/admin/themes/brand', '/admin/themes/new', '/admin/themes/customise/base', '/admin/themes/base/report'] as $path) {
        same(200, $web->get($path)->status, $path);
    }
    $menu = $web->get('/admin')->body;
    contains('/admin/themes', $menu);
    contains('Temas', $menu);
    $commands = (new Console(false))->commands($app);
    check(isset($commands['theme:create'], $commands['theme:check']), 'theme:create y theme:check');
    $caps = \Aifec\Admin\Admin::capabilities($app);
    check(isset($caps['appearance.view'], $caps['appearance.edit']), 'los permisos se declaran');
});
