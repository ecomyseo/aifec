<?php
/**
 * AIFEC — las órdenes de consola setting:get y setting:set: cambian un ajuste sin entrar al panel (despliegues),
 * entienden el tipo del valor y nunca enseñan un secreto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;

test('setting:set guarda con su tipo y setting:get lo enseña', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require $app->root() . '/core/cli.php';
    $run = static function (string $name, array $args) use ($commands, $app): array {
        $io = new Console(false);

        return [$commands[$name]['run']($app, $args, $io), $io->output];
    };

    same(0, $run('setting:set', ['seo.noindex_site', 'true'])[0]);
    same(true, $app->settings()->bool('seo.noindex_site'));
    same(true, $app->settings()->get('seo.noindex_site'), 'se guarda como booleano, no como el texto «true»');
    contains('seo.noindex_site = true', $run('setting:get', ['seo.noindex_site'])[1]);

    $run('setting:set', ['shop.items_per_page', '24']);
    same(24, $app->settings()->get('shop.items_per_page'));
    $run('setting:set', ['i18n.languages', '["es","en"]']);
    same(['es', 'en'], $app->settings()->get('i18n.languages'));
    $run('setting:set', ['store.name', 'Muebles Ñandú & Cía']);
    same('Muebles Ñandú & Cía', $app->settings()->str('store.name'));
    contains('Ñandú & Cía', $run('setting:get', ['store.name'])[1]);

    // Con --text no se interpreta: un código postal o un «true» que tienen que seguir siendo texto.
    $run('setting:set', ['store.postcode', '08001', 'text' => true]);
    same('08001', $app->settings()->get('store.postcode'));

    contains('(not set)', $run('setting:get', ['no.existe'])[1]);
});

test('setting: uso incorrecto y secretos', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require $app->root() . '/core/cli.php';
    $io = new Console(false);
    same(2, $commands['setting:set']['run']($app, ['seo.noindex_site'], $io), 'sin valor');
    same(2, $commands['setting:set']['run']($app, ['MAL ESCRITA', '1'], $io), 'clave mal escrita');
    same(2, $commands['setting:set']['run']($app, ['sinpunto', '1'], $io), 'una clave lleva su área delante');
    same(2, $commands['setting:get']['run']($app, [], $io));

    $app->setSecret('pagos.clave', 'clave-de-mentira-123');
    $io = new Console(false);
    same(0, $commands['setting:get']['run']($app, ['pagos.clave'], $io));
    contains('pagos.clave = (secret)', $io->output);
    notContains('clave-de-mentira', $io->output);
    notContains('enc:', $io->output);
});

test('setting:set avisa con «settings.saved», igual que el panel, y solo si el valor cambia', function (): void {
    $app = testApp();
    $app->boot();
    $commands = require $app->root() . '/core/cli.php';
    $heard = [];
    $app->hooks()->on('settings.saved', static function (string $module, array $keys) use (&$heard): void {
        $heard[] = $module . ':' . implode(',', $keys);
    });

    same(0, $commands['setting:set']['run']($app, ['store.name', 'Muebles Ñandú & Cía'], new Console(false)));
    same(['core:store.name'], $heard, 'el aviso lleva el módulo que declara la clave y la clave');

    $commands['setting:set']['run']($app, ['store.name', 'Muebles Ñandú & Cía'], new Console(false));
    same(1, count($heard), 'guardar el mismo valor no avisa a nadie');

    $commands['setting:set']['run']($app, ['invento.sin_dueno', '1'], new Console(false));
    same(1, count($heard), 'una clave que no declara ningún módulo se guarda, pero no hay a quién avisar');
    same(1, $app->settings()->get('invento.sin_dueno'));
});
