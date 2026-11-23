<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Cli;

use Aifec\Kernel\App;
use Aifec\Kernel\AssetServer;
use Aifec\Kernel\SiteResolver;

/**
 * Orden de consola de AIFEC:  php aifec <orden> [argumentos] [--site=id] [--opción=valor]
 *
 * Las órdenes propias del núcleo están aquí; cada módulo añade las suyas en su cli.php:
 *
 *   return [
 *       'catalog:reindex' => [
 *           'help' => 'Rebuilds the search index',
 *           'run'  => function (App $app, array $args, Console $io): int { …; return 0; },
 *       ],
 *   ];
 *
 * $args trae los argumentos sin nombre por posición (0, 1…) y las opciones por su nombre
 * (--limit=5 → 'limit' => '5'; --force → 'force' => true).
 */
final class Console
{
    /** @var string lo escrito (las pruebas lo leen aquí) */
    public $output = '';

    /** @var bool si además se escribe en la salida estándar */
    private $echo;

    public function __construct(bool $echo = true)
    {
        $this->echo = $echo;
    }

    public function line(string $text = ''): void
    {
        $this->output .= $text . "\n";
        if ($this->echo) {
            fwrite(STDOUT, $text . "\n");
        }
    }

    public function error(string $text): void
    {
        $this->output .= 'ERROR: ' . $text . "\n";
        if ($this->echo) {
            fwrite(STDERR, 'ERROR: ' . $text . "\n");
        }
    }

    /**
     * Tabla de texto alineada.
     *
     * @param string[]                 $headers
     * @param array<int,array<int,mixed>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max(isset($widths[$i]) ? $widths[$i] : 0, mb_strlen((string) $cell));
            }
        }
        $format = function (array $cells) use ($widths): string {
            $out = [];
            foreach (array_values($cells) as $i => $cell) {
                $cell = (string) $cell;
                $out[] = $cell . str_repeat(' ', max(0, $widths[$i] - mb_strlen($cell)));
            }

            return rtrim(implode('  ', $out));
        };
        $this->line($format($headers));
        $this->line($format(array_map(static function (int $w): string {
            return str_repeat('-', $w);
        }, $widths)));
        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }

    /**
     * Separa argumentos por posición y opciones con nombre.
     *
     * @param string[] $argv sin el nombre del guion ni la orden
     *
     * @return array<int|string,string|bool>
     */
    public static function parse(array $argv): array
    {
        $args = [];
        foreach ($argv as $arg) {
            if (strpos($arg, '--') === 0) {
                $pair = explode('=', substr($arg, 2), 2);
                $args[$pair[0]] = isset($pair[1]) ? $pair[1] : true;
            } else {
                $args[] = $arg;
            }
        }

        return $args;
    }

    /**
     * Ejecuta una orden. Devuelve el código de salida (0 = bien).
     *
     * @param string[] $argv argumentos tal como llegan (con el nombre del guion en la posición 0)
     */
    public function run(array $argv, string $root, string $dataRoot): int
    {
        $command = isset($argv[1]) ? $argv[1] : 'help';
        $args = self::parse(array_slice($argv, 2));

        if ($command === 'version') {
            $this->line('AIFEC ' . App::VERSION . ' · PHP ' . PHP_VERSION);

            return 0;
        }
        if ($command === 'sites') {
            $rows = [];
            foreach (SiteResolver::all($dataRoot) as $site) {
                $rows[] = [$site['id'], $site['host'], $site['path'] === '' ? '/' : $site['path']];
            }
            $this->table(['ID', 'HOST', 'PATH'], $rows);

            return 0;
        }
        if ($command === 'assets:publish') {
            $public = isset($args['public']) && is_string($args['public']) ? $args['public'] : $root . '/public';
            // Sin aplicación no hay ajustes: «--minify-js=0» y «--minify-css=0» publican el original (por defecto, minificado).
            $result = AssetServer::publish($root, $public, [
                'js' => !isset($args['minify-js']) || !in_array($args['minify-js'], ['0', 'false', 'no', 'off'], true),
                'css' => !isset($args['minify-css']) || !in_array($args['minify-css'], ['0', 'false', 'no', 'off'], true),
            ]);
            $this->line('Published ' . $result['files'] . ' files, build ' . $result['build']);

            return 0;
        }

        // Órdenes que no pertenecen a ningún sitio (crear, borrar y configurar sitios): las aporta el instalador
        // y reciben, en vez de la aplicación, dónde están el código y los datos.
        $global = class_exists('Aifec\\Install\\Commands') ? \Aifec\Install\Commands::all() : [];
        if (isset($global[$command])) {
            return (int) $global[$command]['run'](['root' => $root, 'data' => $dataRoot], $args, $this);
        }

        $sites = SiteResolver::all($dataRoot);
        $siteId = isset($args['site']) && is_string($args['site']) ? $args['site'] : (isset($sites[0]) ? $sites[0]['id'] : '');
        unset($args['site']);
        $site = $siteId !== '' ? SiteResolver::load($dataRoot, $siteId) : null;
        if ($site === null) {
            if ($command === 'help') {
                $this->help($global);

                return 0;
            }
            $this->error($siteId === '' ? 'No site installed yet.' : 'Unknown site: ' . $siteId);

            return 2;
        }

        $app = new App($site, $root, ['public_dir' => isset($args['public']) && is_string($args['public']) ? $args['public'] : $root . '/public']);
        $app->makeCurrent();
        $app->boot();
        $commands = $this->commands($app) + $global;

        if ($command === 'help') {
            $this->help($commands);

            return 0;
        }
        if (!isset($commands[$command])) {
            $this->error('Unknown command: ' . $command . ' (php aifec help)');

            return 2;
        }
        try {
            $code = $commands[$command]['run']($app, $args, $this);

            return is_int($code) ? $code : 0;
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'cli ' . $command);
            $this->error(get_class($e) . ': ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * Órdenes disponibles: las del núcleo y las de los módulos cargados.
     *
     * @return array<string,array{help:string,run:callable}>
     */
    public function commands(App $app): array
    {
        $commands = [
            'migrate' => [
                'help' => 'Applies pending database migrations',
                'run' => static function (App $app, array $args, Console $io): int {
                    // El arranque ya aplica lo pendiente: lo suyo también cuenta, o esta orden nunca enseñaría nada.
                    $applied = array_values(array_unique(array_merge($app->bootMigrations(), $app->migrate(true))));
                    $io->line($applied === [] ? 'Nothing to migrate.' : 'Applied: ' . implode(', ', $applied));

                    return 0;
                },
            ],
            'cache:clear' => [
                'help' => 'Empties the site cache (data and pages)',
                'run' => static function (App $app, array $args, Console $io): int {
                    $app->cache()->flush();
                    $io->line('Cache cleared.');

                    return 0;
                },
            ],
            'modules' => [
                'help' => 'Lists modules and plugins and whether they are loaded',
                'run' => static function (App $app, array $args, Console $io): int {
                    $rows = [];
                    $failed = $app->modules()->failed();
                    foreach ($app->modules()->all() as $id => $m) {
                        $state = $app->modules()->isLoaded((string) $id) ? 'loaded' : (isset($failed[$id]) ? 'FAILED: ' . $failed[$id] : 'off');
                        $rows[] = [$id, $m['kind'], $m['version'], $state];
                    }
                    $io->table(['ID', 'KIND', 'VERSION', 'STATE'], $rows);

                    return 0;
                },
            ],
        ];
        foreach ($app->modules()->definitions('cli') as $definition) {
            foreach ($definition as $name => $command) {
                if (is_array($command) && isset($command['run']) && is_callable($command['run'])) {
                    $commands[(string) $name] = ['help' => isset($command['help']) ? (string) $command['help'] : '', 'run' => $command['run']];
                }
            }
        }
        ksort($commands);

        return $commands;
    }

    /**
     * @param array<string,array{help:string,run:callable}> $commands
     */
    private function help(array $commands): void
    {
        $this->line('AIFEC ' . App::VERSION);
        $this->line('Usage: php aifec <command> [arguments] [--site=id] [--option=value]');
        $this->line('');
        $rows = [
            ['version', 'Shows the version'],
            ['sites', 'Lists the sites of this installation'],
            ['assets:publish', 'Copies static assets to the public folder'],
        ];
        foreach ($commands as $name => $command) {
            $rows[] = [$name, $command['help']];
        }
        $this->table(['COMMAND', 'WHAT IT DOES'], $rows);
    }
}
