<?php
/**
 * AIFEC — órdenes de consola del módulo de plugins.
 *
 *   php aifec contract:check <id>                       pasa el validador del Contrato AIFEC y enseña el informe (sale con 1 si hay errores)
 *   php aifec plugin:create <id> [--name=…] [--features=setting,screen,…|all] [--description=…] [--author=…] [--namespace=…]
 *   php aifec plugin:enable <id>      php aifec plugin:disable <id>
 *   php aifec plugin:uninstall <id> [--keep-data]
 *   php aifec plugin:install <fichero.zip>
 *   php aifec plugin:grant <id> <capacidad> [--off]
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Plugins\Generator;
use Aifec\Modules\Plugins\Plugins;
use Aifec\Modules\Plugins\Validator;

/**
 * @param array<string,mixed> $result
 */
$show = static function (array $result, Console $io): int {
    if ($result['ok']) {
        $io->line((string) $result['message']);
    } else {
        $io->error((string) $result['message']);
        if (isset($result['errors']) && is_array($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $io->line('  - ' . (string) $error);
            }
        }
    }

    return $result['ok'] ? 0 : 1;
};

return [
    'contract:check' => ['help' => 'Checks a plugin against the AIFEC contract: contract:check <id>', 'run' => static function (App $app, array $args, Console $io): int {
        $id = isset($args[0]) ? (string) $args[0] : '';
        /** @var Plugins $plugins */
        $plugins = $app->service('plugins');
        $row = $id !== '' ? $plugins->find($id) : null;
        if ($row === null || $row['kind'] !== 'plugin') {
            $io->error('Usage: contract:check <id> (an installed plugin)');

            return 2;
        }
        $report = $plugins->validate($id);
        $titles = Validator::titles($app->i18n());
        $errors = 0;
        $warnings = 0;
        foreach ($report['checks'] as $check) {
            if ($check['ok']) {
                $io->line('[ OK ] ' . $check['rule'] . ' ' . (isset($titles[$check['rule']]) ? $titles[$check['rule']] : ''));
                continue;
            }
            $check['level'] === 'error' ? ++$errors : ++$warnings;
            $where = $check['file'] !== '' ? ' (' . $check['file'] . ($check['line'] > 0 ? ':' . $check['line'] : '') . ')' : '';
            $io->line(($check['level'] === 'error' ? '[FAIL] ' : '[WARN] ') . $check['rule'] . ' ' . $check['message'] . $where);
        }
        $io->line('CONTRACT ' . $id . ': score ' . $report['score'] . '/100, ' . $errors . ' errors, ' . $warnings . ' warnings — ' . ($report['ok'] ? 'OK' : 'FAIL'));

        return $report['ok'] ? 0 : 1;
    }],
    'plugin:create' => ['help' => 'Creates a plugin from a template: plugin:create <id> [--name=…] [--features=setting,screen,listener,slot,block,job,command,payment,carrier|all]', 'run' => static function (App $app, array $args, Console $io) use ($show): int {
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '') {
            $io->error('Usage: plugin:create <id> [--name=…] [--features=…]');

            return 2;
        }
        $features = [];
        if (isset($args['features']) && is_string($args['features'])) {
            $features = $args['features'] === 'all' ? Generator::FEATURES : array_filter(array_map('trim', explode(',', $args['features'])), 'strlen');
        }
        $options = [];
        foreach (['description', 'author', 'license', 'namespace'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $options[$key] = $args[$key];
            }
        }
        /** @var Plugins $plugins */
        $plugins = $app->service('plugins');
        $result = (new Generator($plugins))->create($id, isset($args['name']) && is_string($args['name']) ? $args['name'] : '', $features, $options);

        return $show($result, $io);
    }],
    'plugin:enable' => ['help' => 'Turns a module or plugin on: plugin:enable <id>', 'run' => static function (App $app, array $args, Console $io) use ($show): int {
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '') {
            $io->error('Usage: plugin:enable <id>');

            return 2;
        }

        return $show($app->service('plugins')->enable($id), $io);
    }],
    'plugin:disable' => ['help' => 'Turns a module or plugin off: plugin:disable <id>', 'run' => static function (App $app, array $args, Console $io) use ($show): int {
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '') {
            $io->error('Usage: plugin:disable <id>');

            return 2;
        }

        return $show($app->service('plugins')->disable($id), $io);
    }],
    'plugin:uninstall' => ['help' => 'Uninstalls a turned-off plugin: plugin:uninstall <id> [--keep-data]', 'run' => static function (App $app, array $args, Console $io) use ($show): int {
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '') {
            $io->error('Usage: plugin:uninstall <id> [--keep-data]');

            return 2;
        }

        return $show($app->service('plugins')->uninstall($id, !empty($args['keep-data'])), $io);
    }],
    'plugin:install' => ['help' => 'Installs a plugin from a ZIP (it is not turned on): plugin:install <file.zip>', 'run' => static function (App $app, array $args, Console $io) use ($show): int {
        $file = isset($args[0]) ? (string) $args[0] : '';
        if ($file === '') {
            $io->error('Usage: plugin:install <file.zip>');

            return 2;
        }

        return $show($app->service('plugins')->install($file), $io);
    }],
    'plugin:grant' => ['help' => 'Grants a capability to a plugin: plugin:grant <id> <capability> [--off]', 'run' => static function (App $app, array $args, Console $io) use ($show): int {
        $id = isset($args[0]) ? (string) $args[0] : '';
        $capability = isset($args[1]) ? (string) $args[1] : '';
        if ($id === '' || $capability === '') {
            $io->error('Usage: plugin:grant <id> <capability> [--off]');

            return 2;
        }

        return $show($app->service('plugins')->grant($id, $capability, empty($args['off'])), $io);
    }],
];
