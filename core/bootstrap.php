<?php
/**
 * AIFEC — AI-First E-Commerce. Arranque: cargador de clases, sin Composer.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec;

require_once __DIR__ . '/polyfill.php';

/**
 * Cargador de clases del sistema.
 *
 *   Aifec\Foo\Bar                  -> core/src/Foo/Bar.php
 *   Aifec\Modules\CmsPages\Foo     -> modules/cms-pages/src/Foo.php   (StudlyCase -> kebab-case)
 *   prefijos añadidos (plugins)    -> la carpeta que declaren con addPrefix()
 */
final class Autoload
{
    /** @var array<string,string> prefijo de espacio de nombres => carpeta (con barra final) */
    private static array $prefixes = [];

    private static string $root = '';

    public static function register(string $root): void
    {
        self::$root = rtrim(str_replace('\\', '/', $root), '/');
        spl_autoload_register([self::class, 'load']);
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function addPrefix(string $prefix, string $dir): void
    {
        self::$prefixes[trim($prefix, '\\') . '\\'] = rtrim(str_replace('\\', '/', $dir), '/') . '/';
        // El prefijo más largo gana: se ordenan una vez al añadir.
        uksort(self::$prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                self::requireIfExists($dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');

                return;
            }
        }

        if (str_starts_with($class, 'Aifec\\Modules\\')) {
            $rest = substr($class, 14);
            $pos = strpos($rest, '\\');
            if ($pos !== false) {
                $slug = self::kebab(substr($rest, 0, $pos));
                $path = str_replace('\\', '/', substr($rest, $pos + 1));
                self::requireIfExists(self::$root . '/modules/' . $slug . '/src/' . $path . '.php');
            }

            return;
        }

        if (str_starts_with($class, 'Aifec\\')) {
            self::requireIfExists(self::$root . '/core/src/' . str_replace('\\', '/', substr($class, 6)) . '.php');
        }
    }

    /** «CmsPages» -> «cms-pages». Los acrónimos se escriben como palabra: «Seo», «Ai», «Api». */
    public static function kebab(string $studly): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $studly));
    }

    private static function requireIfExists(string $file): void
    {
        if (is_file($file)) {
            require $file;
        }
    }
}

Autoload::register(dirname(__DIR__));

require_once __DIR__ . '/helpers.php';
