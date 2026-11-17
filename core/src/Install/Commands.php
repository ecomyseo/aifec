<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Install;

use Aifec\Cli\Console;
use Aifec\Kernel\SiteResolver;
use Aifec\Security\Random;

/**
 * Órdenes de consola GLOBALES: no pertenecen a un sitio (crean, borran o configuran sitios), así que
 * `Console::run` las busca aquí antes de resolver ninguno y les pasa dónde están el código y los datos.
 *
 *   php aifec site:create <id> --url=https://… [--path=/demos/moda] [--host=tienda.es] [--name="Mi tienda"] …
 *   php aifec site:delete <id> --yes [--drop-tables]
 *   php aifec site:config <id> clave=valor …
 */
final class Commands
{
    /** Claves de config.php que site:config puede cambiar. */
    private const CONFIG_KEYS = ['url', 'locale', 'timezone', 'debug', 'demo', 'master', 'modules_only'];

    /**
     * @return array<string,array{help:string,run:callable}>
     */
    public static function all(): array
    {
        return [
            'site:create' => [
                'help' => 'Creates a site: site:create <id> --url=https://… [--path=/demos/moda] [--host=] [--name=] [--email=] [--admin-email= --admin-name= --admin-password=] [--lang=es] [--timezone=] [--db=sqlite|mysql --db-host= --db-port= --db-name= --db-user= --db-pass= --db-prefix=] [--demo] [--master] [--modules=a,b]',
                'run' => static function (array $context, array $args, Console $io): int {
                    return self::create($context, $args, $io);
                },
            ],
            'site:delete' => [
                'help' => 'Deletes a site and its folder: site:delete <id> --yes [--drop-tables]',
                'run' => static function (array $context, array $args, Console $io): int {
                    return self::delete($context, $args, $io);
                },
            ],
            'site:config' => [
                'help' => 'Changes keys of a site config: site:config <id> url=… locale=… timezone=… debug=0|1 demo=0|1 master=0|1 modules_only=a,b',
                'run' => static function (array $context, array $args, Console $io): int {
                    return self::config($context, $args, $io);
                },
            ],
        ];
    }

    /**
     * @param array<string,string>     $context root y data
     * @param array<int|string,mixed>  $args
     */
    private static function create(array $context, array $args, Console $io): int
    {
        $id = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        $url = self::opt($args, 'url');
        if ($id === '' || $url === '') {
            $io->error('Usage: site:create <id> --url=https://… [--path=/demos/moda] [--host=tienda.es] [--name="My shop"] [--admin-email=…] [--demo]');

            return 2;
        }
        $adminEmail = self::opt($args, 'admin-email') !== '' ? self::opt($args, 'admin-email') : self::opt($args, 'email');
        if ($adminEmail === '') {
            $io->error('The administrator email is required: --admin-email=…');

            return 2;
        }
        $given = self::opt($args, 'admin-password');
        $password = $given !== '' ? $given : Random::pick('abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', 16);

        $db = ['driver' => self::opt($args, 'db', 'sqlite')];
        if ($db['driver'] === 'mysql') {
            $db += [
                'host' => self::opt($args, 'db-host', 'localhost'), 'port' => self::opt($args, 'db-port', '3306'), 'name' => self::opt($args, 'db-name'),
                'user' => self::opt($args, 'db-user'), 'password' => self::opt($args, 'db-pass'), 'prefix' => self::opt($args, 'db-prefix', 'af_'),
            ];
        }
        $options = [
            'id' => $id, 'url' => $url, 'host' => self::opt($args, 'host', '*'), 'path' => self::opt($args, 'path'),
            'locale' => self::opt($args, 'lang', 'es'), 'timezone' => self::opt($args, 'timezone', 'Europe/Madrid'),
            'db' => $db, 'demo' => self::flag($args, 'demo'), 'debug' => self::flag($args, 'debug'), 'master' => self::flag($args, 'master'),
            'shop_name' => self::opt($args, 'name', 'My store'), 'shop_email' => self::opt($args, 'email', $adminEmail),
            'admin' => ['name' => self::opt($args, 'admin-name', 'Administrator'), 'email' => $adminEmail, 'password' => $password],
            'public_dir' => $context['root'] . '/public',
        ];
        $modules = self::opt($args, 'modules');
        if ($modules !== '') {
            $options['modules'] = array_values(array_filter(array_map('trim', explode(',', $modules)), static function (string $m): bool {
                return $m !== '';
            }));
        }

        try {
            $result = SiteFactory::create($context['root'], $context['data'], $options);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 2;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }

        $io->line('Site created: ' . $result['id']);
        $io->line('URL: ' . $result['url']);
        $io->line('Panel: ' . $result['admin_url']);
        $io->line('Email: ' . $result['admin_email']);
        if ($given === '') {
            $io->line('Password: ' . $password . ' (shown only once: save it now)');
        }

        return 0;
    }

    /**
     * @param array<string,string>     $context
     * @param array<int|string,mixed>  $args
     */
    private static function delete(array $context, array $args, Console $io): int
    {
        $id = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        if ($id === '') {
            $io->error('Usage: site:delete <id> --yes [--drop-tables]');

            return 2;
        }
        if (!self::flag($args, 'yes')) {
            $io->line('Nothing was deleted. This removes the site "' . $id . '" and its folder for good: add --yes to confirm.');

            return 1;
        }
        try {
            SiteFactory::delete($context['data'], $id, self::flag($args, 'drop-tables'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 2;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $io->line('Site deleted: ' . $id);

        return 0;
    }

    /**
     * @param array<string,string>     $context
     * @param array<int|string,mixed>  $args
     */
    private static function config(array $context, array $args, Console $io): int
    {
        $id = isset($args[0]) && is_string($args[0]) ? $args[0] : '';
        $pairs = [];
        foreach ($args as $key => $value) {
            if (is_int($key) && $key > 0 && is_string($value)) {
                $pairs[] = $value;
            }
        }
        if ($id === '' || $pairs === []) {
            $io->error('Usage: site:config <id> key=value … (keys: ' . implode(', ', self::CONFIG_KEYS) . ')');

            return 2;
        }
        $site = SiteResolver::load($context['data'], $id);
        if ($site === null) {
            $io->error('Unknown site: ' . $id);

            return 2;
        }
        $config = $site->config;
        $report = [];
        try {
            foreach ($pairs as $pair) {
                $pos = strpos($pair, '=');
                if ($pos === false || $pos === 0) {
                    throw new \InvalidArgumentException('Use key=value: ' . $pair);
                }
                $key = substr($pair, 0, $pos);
                $value = (string) substr($pair, $pos + 1);
                if ($key === 'secret' || $key === 'db') {
                    throw new \InvalidArgumentException('The key "' . $key . '" cannot be changed with this command');
                }
                if (!in_array($key, self::CONFIG_KEYS, true)) {
                    throw new \InvalidArgumentException('Unknown key "' . $key . '" (allowed: ' . implode(', ', self::CONFIG_KEYS) . ')');
                }
                if ($key === 'modules_only') {
                    $list = array_values(array_filter(array_map('trim', explode(',', $value)), static function (string $m): bool {
                        return $m !== '';
                    }));
                    if ($list === []) {
                        unset($config[$key]);
                        $report[] = $key . ' removed';
                    } else {
                        $config[$key] = $list;
                        $report[] = $key . ' = ' . implode(',', $list);
                    }
                    continue;
                }
                if ($key === 'debug' || $key === 'demo') {
                    $config[$key] = self::bool($value, $key);
                    $report[] = $key . ' = ' . ($config[$key] ? 'true' : 'false');
                    continue;
                }
                if ($key === 'master') {
                    // El sitio maestro es el que crea otros sitios y demostraciones: solo uno, y nunca una demo.
                    if (self::bool($value, $key)) {
                        $config[$key] = true;
                    } else {
                        unset($config[$key]);
                    }
                    $report[] = $key . ' = ' . (isset($config[$key]) ? 'true' : 'false');
                    continue;
                }
                if ($key === 'url') {
                    $config[$key] = SiteFactory::cleanUrl($value);
                } elseif ($key === 'locale') {
                    $config[$key] = SiteFactory::cleanLocale($value);
                } else {
                    $config[$key] = SiteFactory::cleanTimezone($value);
                }
                $report[] = $key . ' = ' . $config[$key];
            }
            if (!empty($config['master'])) {
                if (!empty($config['demo'])) {
                    throw new \InvalidArgumentException('A demo site cannot be the master site (use master=0 or demo=0)');
                }
                $other = SiteFactory::masterId($context['data'], $id);
                if ($other !== null) {
                    throw new \InvalidArgumentException('The master site is already "' . $other . '": unmark it first (site:config ' . $other . ' master=0)');
                }
            }
            SiteFactory::writeConfig($site->dataDir, $config);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return 2;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        foreach ($report as $line) {
            $io->line('Updated: ' . $line);
        }

        return 0;
    }

    /**
     * @param array<int|string,mixed> $args
     */
    private static function opt(array $args, string $name, string $default = ''): string
    {
        return isset($args[$name]) && is_string($args[$name]) && $args[$name] !== '' ? $args[$name] : $default;
    }

    /**
     * Opción sin valor (--demo) o con uno afirmativo (--demo=1).
     *
     * @param array<int|string,mixed> $args
     */
    private static function flag(array $args, string $name): bool
    {
        if (!isset($args[$name])) {
            return false;
        }

        return $args[$name] === true || (is_string($args[$name]) && in_array(strtolower($args[$name]), ['1', 'true', 'yes', 'on'], true));
    }

    private static function bool(string $value, string $key): bool
    {
        $value = strtolower($value);
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off', ''], true)) {
            return false;
        }

        throw new \InvalidArgumentException('The value of "' . $key . '" must be 0 or 1');
    }
}
