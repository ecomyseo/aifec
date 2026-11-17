<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Decide qué sitio atiende una petición.
 *
 * La carpeta de datos tiene un sites.php que devuelve la lista:
 *
 *   return ['sites' => [
 *       ['id' => 'main',      'host' => '*',                'path' => ''],
 *       ['id' => 'demo-moda', 'host' => 'aifec.gmartos.es', 'path' => '/demos/moda'],
 *   ]];
 *
 * Gana el dominio exacto sobre «*» y, dentro de eso, el prefijo de ruta más largo.
 */
final class SiteResolver
{
    /** @var array<string,array<int,array<string,string>>> lista leída, por carpeta de datos */
    private static $cache = [];

    /**
     * @return array<int,array{id:string,host:string,path:string}>
     */
    public static function all(string $dataRoot): array
    {
        $dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');
        if (isset(self::$cache[$dataRoot])) {
            return self::$cache[$dataRoot];
        }
        $file = $dataRoot . '/sites.php';
        $list = [];
        if (is_file($file)) {
            $data = require $file;
            $raw = is_array($data) && isset($data['sites']) && is_array($data['sites']) ? $data['sites'] : [];
            foreach ($raw as $row) {
                if (!is_array($row) || !isset($row['id']) || !self::validId((string) $row['id'])) {
                    continue;
                }
                $path = isset($row['path']) ? '/' . trim((string) $row['path'], '/') : '';
                $list[] = [
                    'id' => (string) $row['id'],
                    'host' => isset($row['host']) && $row['host'] !== '' ? strtolower((string) $row['host']) : '*',
                    'path' => $path === '/' ? '' : $path,
                ];
            }
        }
        self::$cache[$dataRoot] = $list;

        return $list;
    }

    public static function forget(): void
    {
        self::$cache = [];
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) === 1;
    }

    /** Sitio que corresponde a un dominio y una ruta (la ruta completa de la petición). */
    public static function resolve(string $dataRoot, string $host, string $path): ?Site
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));
        $path = '/' . ltrim($path, '/');
        $best = null;
        $bestScore = -1;
        foreach (self::all($dataRoot) as $row) {
            if ($row['host'] !== '*' && $row['host'] !== $host) {
                continue;
            }
            $prefix = $row['path'];
            if ($prefix !== '' && $path !== $prefix && strpos($path, $prefix . '/') !== 0) {
                continue;
            }
            $score = strlen($prefix) * 2 + ($row['host'] !== '*' ? 1 : 0);
            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }
        if ($best === null) {
            return null;
        }

        return self::load($dataRoot, $best['id'], $best['path'], $host);
    }

    /** Carga un sitio por su identificador (consola, pruebas, tareas). */
    public static function load(string $dataRoot, string $id, ?string $basePath = null, string $host = ''): ?Site
    {
        if (!self::validId($id)) {
            return null;
        }
        $dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');
        $dir = $dataRoot . '/' . $id;
        $file = $dir . '/config.php';
        if (!is_file($file)) {
            return null;
        }
        $config = require $file;
        if (!is_array($config)) {
            return null;
        }
        if ($basePath === null) {
            $basePath = '';
            foreach (self::all($dataRoot) as $row) {
                if ($row['id'] === $id) {
                    $basePath = $row['path'];
                    break;
                }
            }
        }

        return new Site($id, $dir, $config, $basePath, $host);
    }

    /**
     * Escribe sites.php de forma atómica.
     *
     * @param array<int,array<string,string>> $sites
     */
    public static function save(string $dataRoot, array $sites): void
    {
        $dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');
        if (!is_dir($dataRoot)) {
            mkdir($dataRoot, 0775, true);
        }
        $code = "<?php\n// AIFEC: lista de sitios de esta instalación. Lo escribe el sistema.\nreturn " . var_export(['sites' => array_values($sites)], true) . ";\n";
        $tmp = $dataRoot . '/sites.php.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $code, LOCK_EX);
        rename($tmp, $dataRoot . '/sites.php');
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dataRoot . '/sites.php', true);
        }
        self::forget();
    }
}
