<?php
/**
 * AIFEC — comprobaciones de ficheros: carpetas escribibles, datos fuera de la web, espacio en disco y tamaño del registro y de la caché.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Doctor;
use Aifec\Modules\Tools\Fs;
use Aifec\Util\Bytes;

final class FileChecks
{
    /** Tamaño del registro a partir del cual se avisa. */
    private const LOGS_WARN = 200 * 1024 * 1024;

    /** Tamaño de la caché a partir del cual se avisa. */
    private const CACHE_WARN = 500 * 1024 * 1024;

    /** @return array<int,array<string,mixed>> */
    public static function all(App $app): array
    {
        $out = [];
        foreach (['data' => t('Data folder'), 'cache' => t('Cache folder'), 'logs' => t('Log folder'), 'media' => t('Media folder')] as $key => $label) {
            $out[] = ['id' => 'files.' . $key, 'group' => 'files', 'label' => $label, 'run' => static function (App $app) use ($key): array {
                return self::writable($app, $key);
            }];
        }
        $out[] = ['id' => 'files.web', 'group' => 'files', 'label' => t('Data folder out of reach of the web'), 'run' => [self::class, 'outOfWeb']];
        $out[] = ['id' => 'files.disk', 'group' => 'files', 'label' => t('Free disk space'), 'run' => [self::class, 'disk']];
        $out[] = ['id' => 'files.logs_size', 'group' => 'files', 'label' => t('Size of the log'), 'run' => [self::class, 'logsSize']];
        $out[] = ['id' => 'files.cache_size', 'group' => 'files', 'label' => t('Size of the cache'), 'run' => [self::class, 'cacheSize']];

        return $out;
    }

    /** @return array<string,string> */
    public static function writable(App $app, string $key): array
    {
        $site = $app->site();
        $dir = $key === 'data' ? $site->dataDir : ($key === 'media' ? $site->mediaDir($app->publicDir()) : $site->path($key));

        return Fs::writable($dir) ? Doctor::result('ok', t('Writable')) : Doctor::result('bad', t('The server cannot write there'));
    }

    /** @return array<string,string> */
    public static function outOfWeb(App $app): array
    {
        $data = self::resolve($app->site()->dataDir);
        $public = self::resolve($app->publicDir());
        $inside = $public !== '' && ($data === $public || strpos($data . '/', $public . '/') === 0);
        if (!$inside) {
            return Doctor::result('ok', t('It is not inside the public folder'));
        }
        $htaccess = $app->site()->dataDir . '/.htaccess';
        $text = is_file($htaccess) ? (string) file_get_contents($htaccess) : '';
        if ($text !== '' && (stripos($text, 'denied') !== false || stripos($text, 'deny from all') !== false)) {
            return Doctor::result('warn', t('It is inside the public folder and protected only by its .htaccess'));
        }

        return Doctor::result('bad', t('The data folder is inside the public folder and anyone could download its files'));
    }

    /** @return array<string,string> */
    public static function disk(App $app): array
    {
        $free = @disk_free_space($app->site()->dataDir);
        if ($free === false) {
            return Doctor::result('warn', t('The free space could not be read'));
        }
        $detail = Bytes::format((int) $free, 0);
        if ($free < 100 * 1024 * 1024) {
            return Doctor::result('bad', $detail);
        }

        return Doctor::result($free < 500 * 1024 * 1024 ? 'warn' : 'ok', $detail);
    }

    /** @return array<string,string> */
    public static function logsSize(App $app): array
    {
        $size = Fs::size($app->site()->path('logs'));

        return self::sizeResult($size, self::LOGS_WARN, $app->url()->plain('/admin/tools/logs'));
    }

    /** @return array<string,string> */
    public static function cacheSize(App $app): array
    {
        $size = Fs::size($app->site()->path('cache'));

        return self::sizeResult($size, self::CACHE_WARN, $app->url()->plain('/admin/tools/cache'));
    }

    /**
     * @param array{bytes:int,files:int,truncated:bool} $size
     *
     * @return array<string,string>
     */
    private static function sizeResult(array $size, int $limit, string $fix): array
    {
        $detail = ($size['truncated'] ? '≥ ' : '') . Bytes::format($size['bytes'], 1) . ' · ' . tn('{n} file', '{n} files', $size['files']);

        return $size['bytes'] > $limit ? Doctor::result('warn', $detail, $fix) : Doctor::result('ok', $detail);
    }

    private static function resolve(string $path): string
    {
        $real = realpath($path);

        return Fs::norm($real !== false ? $real : $path);
    }
}
