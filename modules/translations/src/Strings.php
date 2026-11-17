<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Cli\I18nCheck;
use Aifec\Kernel\App;
use Aifec\Kernel\Cache;

/**
 * Los textos de la interfaz: todas las cadenas que el código pasa por t() y tn() (y las de las definiciones del panel), sacadas
 * con la misma herramienta que la orden «i18n:check» del núcleo, del núcleo, de los módulos cargados y de los temas activos.
 *
 * Cada cadena va a la «tienda» o al «panel» según el fichero donde aparece por primera vez (vistas del panel, clases Admin,
 * admin.php, settings.php, cli.php… son del panel; lo demás, de la tienda). Es una regla de dedo: una cadena que salga en los
 * dos sitios cuenta donde aparece antes.
 *
 * Sacarlas lee cientos de ficheros: se guarda en la caché del sitio (y en esta caché por proceso, documentada) con la huella de
 * los módulos y temas, y se rehace sola cuando cambia algo. Quien publique el servicio «translations.strings» (las pruebas) da
 * su propia lista, como un \ArrayObject de [cadena => ['area' => 'shop'|'panel', 'origin' => '…']].
 */
final class Strings
{
    public const SHOP = 'shop';
    public const PANEL = 'panel';

    /** @var array<string,array<string,array{area:string,origin:string}>> lista ya sacada en este proceso, por firma */
    private static $memo = [];

    /**
     * @return array<string,array{area:string,origin:string}> cadena inglesa => área y origen, por orden
     */
    public static function all(App $app): array
    {
        if ($app->has('translations.strings')) {
            $given = $app->service('translations.strings');

            return self::normalise($given instanceof \ArrayObject ? $given->getArrayCopy() : []);
        }
        $targets = self::targets($app);
        $signature = md5((string) json_encode([$app->root(), $targets, $app->modules()->fingerprint()]));
        if (isset(self::$memo[$signature])) {
            return self::$memo[$signature];
        }
        $key = 'translations.strings.' . $signature;
        $cached = $app->cache()->get($key);
        if (is_array($cached)) {
            self::$memo[$signature] = self::normalise($cached);

            return self::$memo[$signature];
        }
        $out = [];
        foreach ($targets as $target) {
            try {
                // Un código de idioma que nadie trae: así «missing» devuelve TODAS las cadenas (menos las que no se traducen).
                $result = I18nCheck::run($app->root(), $target, 'zzz');
            } catch (\InvalidArgumentException $e) {
                continue;
            }
            foreach ($result['missing'] as $string => $file) {
                $string = (string) $string;
                if (!isset($out[$string])) {
                    $out[$string] = ['area' => self::area((string) $file), 'origin' => self::origin($target)];
                }
            }
        }
        ksort($out, SORT_STRING);
        $app->cache()->set($key, $out, 3600, ['translations']);
        self::$memo[$signature] = $out;

        return $out;
    }

    /**
     * Las cadenas de una sola área.
     *
     * @return array<string,array{area:string,origin:string}>
     */
    public static function inArea(App $app, string $area): array
    {
        $out = [];
        foreach (self::all($app) as $string => $info) {
            if ($info['area'] === $area) {
                $out[(string) $string] = $info;
            }
        }

        return $out;
    }

    /**
     * El catálogo del CÓDIGO en un idioma (núcleo, módulos, plugins y temas, el último manda), sin el del sitio.
     *
     * @return array<string,string>
     */
    public static function codeCatalog(App $app, string $lang): array
    {
        if (preg_match('/^[a-z]{2}$/', $lang) !== 1) {
            return [];
        }
        $out = [];
        foreach ($app->i18n()->dirs() as $dir) {
            $file = $dir . '/' . $lang . '.php';
            if (!is_file($file)) {
                continue;
            }
            $data = Cache::import($file);
            foreach (is_array($data) ? $data : [] as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $out[(string) $key] = $value;
                }
            }
        }

        return $out;
    }

    /** El área de un fichero: «panel» o «shop». */
    public static function area(string $file): string
    {
        $path = '/' . ltrim(str_replace('\\', '/', $file), '/');
        $base = basename($path);
        if (strpos($path, '/views/admin/') !== false || strpos($path, '/src/Admin/') !== false || strpos($path, '/src/Cli/') !== false
            || strpos($path, '/views/install/') !== false || strpos($path, '/src/Install/') !== false
            || in_array($base, ['admin.php', 'settings.php', 'cli.php', 'jobs.php', 'blocks.php', 'module.json', 'plugin.json'], true)
            || preg_match('/(Admin|Panel)[A-Za-z]*\.php$/', $base) === 1) {
            return self::PANEL;
        }

        return self::SHOP;
    }

    /** El nombre corto de una carpeta: «modules/mail» → «mail», «core» → «core». */
    public static function origin(string $target): string
    {
        $pos = strrpos($target, '/');

        return $pos === false ? $target : substr($target, $pos + 1);
    }

    /**
     * Las carpetas de las que se sacan cadenas: el núcleo, los módulos y plugins cargados y la cadena del tema.
     *
     * @return string[]
     */
    public static function targets(App $app): array
    {
        $root = rtrim(str_replace('\\', '/', $app->root()), '/');
        $targets = ['core'];
        foreach ($app->modules()->loaded() as $id) {
            $path = $app->modules()->path((string) $id);
            if ($path === null) {
                continue;
            }
            $path = rtrim(str_replace('\\', '/', $path), '/');
            if (strpos($path, $root . '/') === 0) {
                $targets[] = substr($path, strlen($root) + 1);
            }
        }
        $theme = $app->theme();
        foreach (isset($theme['chain']) && is_array($theme['chain']) ? $theme['chain'] : [] as $slug) {
            if (is_string($slug) && is_dir($root . '/themes/' . $slug)) {
                $targets[] = 'themes/' . $slug;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * @param array<mixed,mixed> $list
     *
     * @return array<string,array{area:string,origin:string}>
     */
    private static function normalise(array $list): array
    {
        $out = [];
        foreach ($list as $string => $info) {
            $string = (string) $string;
            if ($string === '') {
                continue;
            }
            $area = is_array($info) && isset($info['area']) && $info['area'] === self::PANEL ? self::PANEL : self::SHOP;
            $origin = is_array($info) && isset($info['origin']) && is_string($info['origin']) ? $info['origin'] : '';
            $out[$string] = ['area' => $area, 'origin' => $origin];
        }
        ksort($out, SORT_STRING);

        return $out;
    }
}
