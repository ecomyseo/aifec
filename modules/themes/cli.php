<?php
/**
 * AIFEC — órdenes de consola del módulo de temas.
 *
 *   php aifec theme:create <slug> --name=… [--parent=base] [--accent=#…] [--bg=#…] [--font=…] [--header=classic|centered]
 *                                  [--cards=square|portrait] [--columns=3|4]
 *   php aifec theme:check <slug>        pasa el validador y enseña el informe (sale con 1 si hay errores)
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Themes\ThemeException;
use Aifec\Modules\Themes\Themes;

return [
    'theme:create' => ['help' => 'Creates a child theme in themes/<slug>: theme:create <slug> [--name=…] [--parent=base] [--accent=#…] [--bg=#…] [--font=…]', 'run' => static function (App $app, array $args, Console $io): int {
        $slug = isset($args[0]) ? (string) $args[0] : '';
        if ($slug === '') {
            $io->error('Usage: theme:create <slug> [--name=…] [--parent=base] [--accent=#…] [--bg=#…] [--font=…] [--header=…] [--cards=…] [--columns=…]');

            return 2;
        }
        $spec = ['slug' => $slug];
        foreach (['name', 'parent', 'accent', 'bg', 'font', 'header', 'cards', 'columns'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $spec[$key] = $args[$key];
            }
        }
        /** @var Themes $themes */
        $themes = $app->service('themes');
        try {
            $created = $themes->generate($spec);
        } catch (ThemeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $io->line('Created themes/' . $created);
        $report = $themes->validate($created, false);
        $io->line('Validator: ' . ($report['ok'] ? 'OK' : 'ERRORS') . ' · score ' . $report['score']);

        return $report['ok'] ? 0 : 1;
    }],
    'theme:check' => ['help' => 'Runs the theme validator: theme:check <slug>', 'run' => static function (App $app, array $args, Console $io): int {
        $slug = isset($args[0]) ? (string) $args[0] : '';
        /** @var Themes $themes */
        $themes = $app->service('themes');
        if ($slug === '' || !$themes->exists($slug)) {
            $io->error('Usage: theme:check <slug> (an installed theme)');

            return 2;
        }
        $report = $themes->validate($slug, true);
        foreach ($report['checks'] as $check) {
            $state = $check['ok'] ? ' OK ' : ($check['level'] === 'error' ? 'FAIL' : 'WARN');
            $io->line('[' . $state . '] ' . $check['id'] . ($check['file'] !== '' ? ' (' . $check['file'] . ')' : '') . ' ' . $check['message']);
        }
        $io->line('Score ' . $report['score'] . ' · errors ' . $report['errors'] . ' · warnings ' . $report['warnings']);

        return $report['ok'] ? 0 : 1;
    }],
    'theme:activate' => ['help' => 'Makes a theme the active one, if it passes the validator: theme:activate <slug>', 'run' => static function (App $app, array $args, Console $io): int {
        $slug = isset($args[0]) ? (string) $args[0] : '';
        /** @var Themes $themes */
        $themes = $app->service('themes');
        if ($slug === '' || !$themes->exists($slug)) {
            $io->error('Usage: theme:activate <slug> (an installed theme)');

            return 2;
        }
        if ($themes->activeSlug() === $slug) {
            $io->line('The theme «' . $slug . '» is already the active one');

            return 0;
        }
        try {
            // El mismo camino que el botón del panel: no se activa un tema con errores que bloquean.
            $themes->activate($slug);
        } catch (ThemeException $e) {
            $io->error($e->getMessage() . ' (theme:check ' . $slug . ')');

            return 1;
        }
        $io->line('Active theme: ' . $slug);

        return 0;
    }],
];
