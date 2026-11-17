<?php
/**
 * AIFEC — comprobaciones del servidor: requisitos, zona horaria, límites de PHP y OPcache.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools\Checks;

use Aifec\Install\Requirements;
use Aifec\Kernel\App;
use Aifec\Modules\Tools\ClientIp;
use Aifec\Modules\Tools\Doctor;
use Aifec\Util\Bytes;

final class ServerChecks
{
    /** @return array<int,array<string,mixed>> */
    public static function all(App $app): array
    {
        return [
            ['id' => 'server.requirements', 'group' => 'server', 'label' => t('Server requirements'), 'run' => [self::class, 'requirements']],
            ['id' => 'server.client_ip', 'group' => 'server', 'label' => t('IP the system sees'), 'run' => [self::class, 'clientIp']],
            ['id' => 'server.timezone', 'group' => 'server', 'label' => t('Time zone'), 'run' => [self::class, 'timezone']],
            ['id' => 'server.limits', 'group' => 'server', 'label' => t('Memory and upload limits'), 'run' => [self::class, 'limits']],
            ['id' => 'server.opcache', 'group' => 'server', 'label' => t('OPcache'), 'run' => [self::class, 'opcache']],
        ];
    }

    /** @return array<string,string> */
    public static function requirements(App $app): array
    {
        $bad = [];
        $warn = [];
        foreach (Requirements::check($app->site()->dataDir, $app->publicDir()) as $row) {
            if ($row['ok']) {
                continue;
            }
            // Cada falta dice qué deja de funcionar: «Extensión zip (no se pueden instalar plugins…)».
            $text = t($row['label']) . ' (' . t($row['impact']) . ')';
            if ($row['required']) {
                $bad[] = $text;
            } else {
                $warn[] = $text;
            }
        }
        if ($bad !== []) {
            return Doctor::result('bad', t('Missing: {list}', ['list' => implode(', ', $bad)]));
        }
        if ($warn !== []) {
            return Doctor::result('warn', t('Recommended and missing: {list}', ['list' => implode(', ', $warn)]));
        }

        return Doctor::result('ok', 'PHP ' . PHP_VERSION);
    }

    /**
     * La dirección que el sistema da a quien hace la petición. Si llega una cabecera de proxy y no hay proxies de confianza, el
     * sistema la ignora y todos los visitantes parecen venir de la misma dirección: se avisa.
     *
     * @return array<string,string>
     */
    public static function clientIp(App $app): array
    {
        $request = $app->request();
        $detail = $request->ip !== $request->remoteAddr
            ? t('{ip} (the connection comes from {remote}, a trusted proxy)', ['ip' => $request->ip, 'remote' => $request->remoteAddr])
            : $request->ip;
        if (ClientIp::headerIgnored($request, $app)) {
            return Doctor::result(
                'warn',
                t('{ip}. A proxy header arrives but there are no trusted proxies configured: there may be a proxy in front, and then every visitor looks like the same address.', ['ip' => $detail]),
                $app->url()->plain('/admin/settings')
            );
        }

        return Doctor::result('ok', $detail);
    }

    /** @return array<string,string> */
    public static function timezone(App $app): array
    {
        $zone = $app->site()->timezone();
        if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            return Doctor::result('bad', t('The time zone «{zone}» does not exist', ['zone' => $zone]), $app->url()->plain('/admin/settings'));
        }
        $local = (new \DateTimeImmutable('@' . $app->clock()->now()))->setTimezone(new \DateTimeZone($zone));

        return Doctor::result('ok', $zone . ' · ' . $local->format('Y-m-d H:i'));
    }

    /** @return array<string,string> */
    public static function limits(App $app): array
    {
        $memory = self::bytes((string) ini_get('memory_limit'));
        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $post = self::bytes((string) ini_get('post_max_size'));
        $parts = [
            t('memory {size}', ['size' => self::show($memory)]),
            t('uploads {size}', ['size' => self::show($upload)]),
            t('forms {size}', ['size' => self::show($post)]),
        ];
        $problems = [];
        if ($memory > 0 && $memory < 128 * 1024 * 1024) {
            $problems[] = t('less than 128 MB of memory');
        }
        if ($upload > 0 && $upload < 8 * 1024 * 1024) {
            $problems[] = t('uploads under 8 MB');
        }
        if ($post > 0 && $upload > 0 && $post < $upload) {
            $problems[] = t('forms smaller than uploads');
        }
        $detail = implode(' · ', $parts);
        if ($problems !== []) {
            return Doctor::result('warn', $detail . ' — ' . implode(', ', $problems));
        }

        return Doctor::result('ok', $detail);
    }

    /** @return array<string,string> */
    public static function opcache(App $app): array
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return Doctor::result('ok', t('Not checked from the console'));
        }
        $on = function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);

        return $on ? Doctor::result('ok', t('On')) : Doctor::result('warn', t('OPcache is off: the site is slower than it could be'));
    }

    /** Bytes de un valor de php.ini; -1 = sin límite; 0 = no se entiende. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        try {
            return Bytes::parse($value);
        } catch (\InvalidArgumentException $e) {
            return 0;
        }
    }

    private static function show(int $bytes): string
    {
        if ($bytes < 0) {
            return t('unlimited');
        }

        return $bytes === 0 ? '?' : Bytes::format($bytes, 0);
    }
}
