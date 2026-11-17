<?php
/**
 * AIFEC — información del sistema para la pantalla de «Información»: versiones, módulos, tema y tamaño de los datos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;

/**
 * No lee ni devuelve ningún secreto: ni la clave del sitio, ni contraseñas, ni claves de servicios, ni la configuración.
 */
final class SystemInfo
{
    /**
     * @return array<string,mixed>
     */
    public static function collect(App $app): array
    {
        $db = $app->db();
        $sqlite = $db->driver() === 'sqlite';
        $version = (string) $db->value($sqlite ? 'SELECT sqlite_version()' : 'SELECT VERSION()');
        $modules = [];
        foreach ($app->modules()->all() as $id => $manifest) {
            $modules[] = [
                'id' => (string) $id,
                'name' => (string) $manifest['name'],
                'version' => (string) $manifest['version'],
                'kind' => (string) $manifest['kind'],
                'loaded' => $app->modules()->isLoaded((string) $id),
            ];
        }
        $failed = [];
        foreach ($app->modules()->failed() as $id => $reason) {
            $failed[(string) $id] = mb_substr((string) $reason, 0, 200, 'UTF-8');
        }
        $theme = $app->theme();
        $extensions = get_loaded_extensions();
        natcasesort($extensions);

        return [
            'aifec' => App::VERSION,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'database' => ['driver' => $sqlite ? 'SQLite' : 'MySQL / MariaDB', 'version' => $version],
            'extensions' => array_values($extensions),
            'modules' => $modules,
            'failed' => $failed,
            'theme' => ['slug' => isset($theme['slug']) ? (string) $theme['slug'] : '', 'name' => isset($theme['name']) ? (string) $theme['name'] : ''],
            'data' => Fs::size($app->site()->dataDir, 50000),
            'site' => $app->site()->id,
            'url' => $app->site()->url(),
            'time_zone' => $app->site()->timezone(),
            'languages' => $app->languages(),
        ];
    }
}
