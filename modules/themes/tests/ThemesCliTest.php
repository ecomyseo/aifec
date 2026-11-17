<?php
/**
 * AIFEC — pruebas de las órdenes de consola theme:create y theme:check.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;

require_once __DIR__ . '/ThemesKit.php';

/**
 * Ejecuta una orden de consola y devuelve [código de salida, texto].
 *
 * @param array<int|string,string|bool> $args
 *
 * @return array{0:int,1:string}
 */
function themesCli(App $app, string $command, array $args = []): array
{
    $io = new Console(false);
    $commands = $io->commands($app);
    check(isset($commands[$command]), 'existe la orden ' . $command);

    return [(int) $commands[$command]['run']($app, $args, $io), $io->output];
}

themesTest('theme:create crea un tema hijo con las opciones de la línea de órdenes y avisa de que pasa el validador', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    [$code, $out] = themesCli($app, 'theme:create', ['mi-tema', 'name' => 'Mi tema', 'parent' => 'base', 'accent' => '#0b5394', 'bg' => '#f4f6fb', 'font' => 'serif', 'header' => 'centered', 'columns' => '4']);
    same(0, $code, $out);
    contains('Created themes/mi-tema', $out);
    contains('Validator: OK', $out);
    $json = json_decode((string) file_get_contents($root . '/themes/mi-tema/theme.json'), true);
    same('Mi tema', $json['name']);
    same('base', $json['parent']);
    same('serif', $json['settings']['typography']['fields']['font']['default']);
    same('centered', $json['settings']['layout']['fields']['header_style']['default']);
    same(4, $json['settings']['structure']['fields']['columns']['default']);
    same(true, themesSvc($app)->validate('mi-tema', true)['ok']);
});

themesTest('theme:create con un identificador repetido, no válido o sin identificador sale con error y no escribe nada', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    themesCli($app, 'theme:create', ['uno']);
    $before = Aifec\Modules\Themes\Files::files($root);
    [$code, $out] = themesCli($app, 'theme:create', ['uno']);
    same(1, $code);
    contains('already exists', $out);
    [$code2, $out2] = themesCli($app, 'theme:create', ['../fuera']);
    same(1, $code2);
    contains('not valid', $out2);
    [$code3] = themesCli($app, 'theme:create', []);
    same(2, $code3, 'sin identificador es un error de uso');
    [$code4, $out4] = themesCli($app, 'theme:create', ['otro', 'accent' => 'rojo']);
    same(1, $code4);
    contains('accent colour', $out4);
    same($before, Aifec\Modules\Themes\Files::files($root));
});

themesTest('theme:check enseña cada comprobación y sale con 0 si el tema vale y con 1 si tiene errores', function (): void {
    $root = themesRoot();
    $layout = (string) file_get_contents($root . '/themes/base/templates/layout.php');
    themesChild($root, 'roto', ['templates/layout.php' => str_replace('page_head(', 'otra(', $layout)]);
    $app = themesApp($root);
    [$code, $out] = themesCli($app, 'theme:check', ['base']);
    same(0, $code, $out);
    contains('[ OK ] manifest', $out);
    contains('[ OK ] live-home', $out);
    contains('Score ', $out);
    contains('errors 0', $out);
    [$bad, $report] = themesCli($app, 'theme:check', ['roto']);
    same(1, $bad);
    contains('[FAIL] wrapper-head (templates/layout.php)', $report);
    notContains('errors 0', $report);
    [$usage, $text] = themesCli($app, 'theme:check', ['no-existe']);
    same(2, $usage);
    contains('Usage', $text);
    [$usage2] = themesCli($app, 'theme:check', []);
    same(2, $usage2);
});

themesTest('theme:activate activa un tema que vale, no activa uno con errores y dice cómo se usa', function (): void {
    $root = themesRoot();
    $layout = (string) file_get_contents($root . '/themes/base/templates/layout.php');
    themesChild($root, 'hijo');
    themesChild($root, 'roto', ['templates/layout.php' => str_replace('page_head(', 'otra(', $layout)]);
    $app = themesApp($root);
    same('base', themesSvc($app)->activeSlug());

    [$code, $out] = themesCli($app, 'theme:activate', ['hijo']);
    same(0, $code, $out);
    contains('Active theme: hijo', $out);
    same('hijo', themesSetting($app, 'theme.active'), 'queda guardado, igual que desde el panel');

    [$again, $text] = themesCli($app, 'theme:activate', ['hijo']);
    same(0, $again);
    contains('already the active one', $text);

    [$bad, $why] = themesCli($app, 'theme:activate', ['roto']);
    same(1, $bad, 'un tema con errores que bloquean no se activa');
    contains('theme:check roto', $why, 'y dice con qué orden ver por qué');
    same('hijo', themesSetting($app, 'theme.active'), 'el activo sigue siendo el de antes');

    [$usage, $help] = themesCli($app, 'theme:activate', ['no-existe']);
    same(2, $usage);
    contains('Usage', $help);
    [$usage2] = themesCli($app, 'theme:activate', []);
    same(2, $usage2);
});
