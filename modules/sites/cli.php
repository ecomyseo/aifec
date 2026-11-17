<?php
/**
 * AIFEC — órdenes de consola de sitios: demo:create, demo:reset y demo:list (en el sitio maestro: --site=<id>), y
 * demo:check, que valida un paquete sin construir nada (en cualquier sitio con el módulo).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Sites\ContentApply;
use Aifec\Modules\Sites\DemoPackageException;
use Aifec\Modules\Sites\Demos;
use Aifec\Modules\Sites\Owners;
use Aifec\Modules\Sites\Package;

$masterOnly = static function (App $app, Console $io): bool {
    if (!Demos::isMaster($app)) {
        $io->error('This command only works on the master site (its config.php has master => true). Use --site=<id>.');

        return false;
    }

    return true;
};

return [
    'demo:create' => ['help' => 'Builds a demo shop in /demos/<folder>: demo:create <package> <folder> [--name=…] [--password=…] [--administration]', 'run' => static function (App $app, array $args, Console $io) use ($masterOnly): int {
        if (!$masterOnly($app, $io)) {
            return 2;
        }
        $package = isset($args[0]) ? (string) $args[0] : '';
        $slug = isset($args[1]) ? (string) $args[1] : '';
        if ($package === '' || $slug === '') {
            $io->error('Usage: demo:create <package> <folder> [--name=…] [--password=…]');

            return 2;
        }
        $spec = ['package' => $package, 'slug' => $slug, 'public_admin' => isset($args['administration'])];
        foreach (['name', 'password'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $spec[$key] = $args[$key];
            }
        }
        $result = (new Demos($app))->create($spec);
        if (empty($result['ok'])) {
            $io->error((string) $result['error']);

            return 1;
        }
        $io->line('Demo ' . $result['id'] . ' is ready');
        $io->line('Shop:     ' . $result['url'] . '/');
        $io->line('Panel:    ' . $result['admin_url']);
        $io->line('Email:    ' . $result['email']);
        $io->line('Password: ' . $result['password']);

        return 0;
    }],
    'demo:reset' => ['help' => 'Deletes a demo and builds it again from its package: demo:reset <id>', 'run' => static function (App $app, array $args, Console $io) use ($masterOnly): int {
        if (!$masterOnly($app, $io)) {
            return 2;
        }
        $id = isset($args[0]) ? (string) $args[0] : '';
        if ($id === '') {
            $io->error('Usage: demo:reset <id> (see demo:list)');

            return 2;
        }
        $result = (new Demos($app))->reset($id);
        if (empty($result['ok'])) {
            $io->error((string) $result['error']);

            return 1;
        }
        $io->line('Demo ' . $id . ' was reset');

        return 0;
    }],
    // Solo lee: vale en cualquier sitio que tenga el módulo, no solo en el maestro (es la puerta de quien escribe un paquete).
    'demo:check' => ['help' => 'Checks a demo package against the schema without building anything: demo:check <package>', 'run' => static function (App $app, array $args, Console $io): int {
        $name = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        if ($name === '') {
            $io->error('Usage: demo:check <package>');

            return 2;
        }
        try {
            $package = Package::load((new Demos($app))->packagesDir(), $name, $app->root());
        } catch (DemoPackageException $e) {
            foreach ($e->problems() as $problem) {
                $io->line('PROBLEM: ' . $problem);
            }
            $io->line('DEMO ' . $name . ': ' . count($e->problems()) . ' problems');

            return 1;
        }
        $products = $package->has('products.json') ? count($package->data('products.json')) : 0;
        $images = 0;
        $bytes = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($package->dir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile()) {
                $bytes += (int) $file->getSize();
                if (strpos(str_replace('\\', '/', $file->getPathname()), '/media/') !== false) {
                    ++$images;
                }
            }
        }
        $io->line('Products: ' . $products);
        $io->line('Images:   ' . $images);
        $io->line('Size:     ' . number_format($bytes / 1048576, 2, '.', '') . ' MB');
        $io->line('DEMO ' . $name . ': 0 problems');

        return 0;
    }],
    // Las páginas, los menús y el tema de un paquete, sobre un sitio que ya existe (el maestro también): solo añade o actualiza, nunca borra.
    'package:apply' => ['help' => 'Applies the pages, menus and theme of a package to an existing site; it never deletes anything: package:apply <package> [--only=pages,menus,theme] [--dry-run] (the site: --site=<id>, the master by default)', 'run' => static function (App $app, array $args, Console $io): int {
        $name = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        if ($name === '') {
            $io->error('Usage: package:apply <package> [--only=pages,menus,theme] [--dry-run] [--site=<id>]');

            return 2;
        }
        $only = isset($args['only']) && is_string($args['only']) ? array_values(array_filter(array_map('trim', explode(',', $args['only'])), 'strlen')) : [];
        try {
            $package = Package::load((new Demos($app))->packagesDir(), $name, $app->root());
        } catch (DemoPackageException $e) {
            foreach ($e->problems() as $problem) {
                $io->error($problem);
            }
            $io->error('The package «' . $name . '» cannot be applied');

            return 1;
        }
        try {
            $result = (new ContentApply($app, $package))->run($only, isset($args['dry-run']));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 2;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage() . ' (nothing was written)');

            return 1;
        }
        $verbs = ['create' => 'create', 'update' => 'update', 'same' => 'no changes', 'keep' => 'kept as it is (it already exists)', 'activate' => 'activate', 'options' => 'update the options', 'skip' => 'skip'];
        $will = $result['dry'] ? 'would ' : '';
        foreach ($result['pages'] as $page) {
            $io->line('page ' . $page['path'] . ': ' . (in_array($page['action'], ['same', 'keep'], true) ? '' : $will) . $verbs[$page['action']]);
        }
        foreach ($result['menus'] as $menu) {
            $io->line('menu ' . $menu['code'] . ': ' . ($menu['action'] === 'same' ? '' : $will) . $verbs[$menu['action']] . ' (' . $menu['items'] . ' items)');
        }
        if ($result['theme'] !== null) {
            $io->line('theme ' . $result['theme']['slug'] . ': ' . ($result['theme']['action'] === 'same' ? '' : $will) . $verbs[$result['theme']['action']]);
        }
        foreach ($result['notes'] as $note) {
            $io->line('NOTE: ' . $note);
        }
        $io->line($result['line']);

        return 0;
    }],
    // Una tienda de muestra que ya existe y no salió de un paquete: pasa a salir en la página pública de demostraciones.
    'demo:adopt' => ['help' => 'Lists an existing demo site on the demos page: demo:adopt <site> [--name=…] [--sector=…] [--description=…]', 'run' => static function (App $app, array $args, Console $io) use ($masterOnly): int {
        if (!$masterOnly($app, $io)) {
            return 2;
        }
        $id = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        if ($id === '') {
            $io->error('Usage: demo:adopt <site> [--name=…] [--sector=…] [--description=…]');

            return 2;
        }
        $spec = ['id' => $id];
        foreach (['name', 'sector', 'description'] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                $spec[$key] = $args[$key];
            }
        }
        $result = (new Demos($app))->adopt($spec);
        if (empty($result['ok'])) {
            $io->error((string) $result['error']);

            return 1;
        }
        $io->line('Demo ' . $result['id'] . ' is listed: ' . $result['url'] . '/');

        return 0;
    }],
    'sites:owners' => ['help' => 'Lets the administrators of this site sign in to the panel of every demo with their own account: sites:owners [<site>]', 'run' => static function (App $app, array $args, Console $io) use ($masterOnly): int {
        if (!$masterOnly($app, $io)) {
            return 2;
        }
        $owners = new Owners($app);
        $only = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        $results = $only !== '' ? [$only => $owners->syncSite($only)] : $owners->syncAll();
        $bad = 0;
        foreach ($results as $id => $result) {
            if ($result['ok']) {
                $io->line(str_pad((string) $id, 22) . ' ' . $result['owners'] . ' owner(s)');
            } else {
                ++$bad;
                $io->line(str_pad((string) $id, 22) . ' FAILED: ' . $result['error']);
            }
        }
        $io->line('OWNERS: ' . count($results) . ' demo(s), ' . $bad . ' failed');

        return $bad > 0 ? 1 : 0;
    }],
    'demo:list' => ['help' => 'Lists the sites and the demos with their package and state', 'run' => static function (App $app, array $args, Console $io) use ($masterOnly): int {
        if (!$masterOnly($app, $io)) {
            return 2;
        }
        $rows = [];
        foreach ((new Demos($app))->sites() as $site) {
            $rows[] = [
                $site['id'], $site['path'], $site['master'] ? 'master' : ($site['demo'] ? 'demo' : 'shop'), $site['package'] !== '' ? $site['package'] : '-',
                $site['state'] !== '' ? $site['state'] : '-', $site['last_reset_at'] !== '' ? $site['last_reset_at'] : '-',
                $site['orders_today'] === null ? '-' : (string) $site['orders_today'],
            ];
        }
        $io->table(['ID', 'PATH', 'TYPE', 'PACKAGE', 'STATE', 'LAST RESET (UTC)', 'ORDERS TODAY'], $rows);

        return 0;
    }],
];
