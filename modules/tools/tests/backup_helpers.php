<?php
/**
 * AIFEC — ayudas de las pruebas de copias completas y restauración. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   bkfSeed(App $app, int $extra): array        datos de ejemplo: ajustes, intentos de acceso, fotos, ficheros privados y ruido que no debe entrar
 *   bkfState(App $app): array                   foto del sitio: filas por tabla y suma de cada fichero de las dos carpetas
 *   bkfZipNames(string $path): string[]         nombres de las entradas de un zip
 *   bkfZipText(string $path): string            todo el contenido de un zip, junto (para buscar en él un secreto)
 *   bkfTamper(string $path, callable $fn): void abre un zip, deja que la función lo cambie y lo cierra
 *   bkfManifest(string $path): array            el manifiesto de un zip
 *   bkfSetManifest(string $path, callable $fn)  cambia el manifiesto de un zip (sin tocar las sumas de las partes)
 *   bkfLeftovers(App $app): string[]            carpetas temporales o apartadas que haya quedado
 *   bkfFakeMysqlZip(string $dir, array $opt): string  un zip como el que haría un sitio MySQL, hecho a mano (solo se comprueba, no se restaura)
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Tools\BackupAreas;

require_once __DIR__ . '/helpers.php';

if (!function_exists('bkfSeed')) {
    /**
     * @return array{media:string[],private:string[],noise:string[]} rutas (relativas a su carpeta) que SÍ entran, y rutas absolutas que NO deben entrar
     */
    function bkfSeed(App $app, int $extra = 0): array
    {
        $app->settings()->set('demo.clave', 'bkf-marca-1');
        for ($i = 1; $i <= 30; ++$i) {
            $app->db()->insert('login_attempts', ['guard' => 'admin', 'identifier' => 'persona' . $i . '@cliente.test', 'ip' => '203.0.113.' . $i, 'ok' => 0, 'created_at' => $app->clock()->sql()]);
        }
        $dirs = BackupAreas::dirs($app);
        $files = [
            'media' => ['product/silla.jpg' => "JPG silla \xFF\xD8", 'product/mesa.png' => "PNG mesa \x89PNG", 'brand/logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'ñandú/árbol 1.txt' => 'nombre con tilde y espacio'],
            'private' => ['invoices/F-2026-0001.pdf' => '%PDF-1.4 factura de ejemplo', 'returns/adjunto.txt' => 'adjunto de una devolución', 'downloads/guia.zip' => 'PK no es un zip de verdad'],
        ];
        for ($i = 1; $i <= $extra; ++$i) {
            $files['media']['product/foto-' . $i . '.jpg'] = 'foto ' . $i . ' ' . str_repeat('x', 200 + $i);
        }
        $in = ['media' => [], 'private' => [], 'noise' => []];
        foreach ($files as $part => $list) {
            foreach ($list as $rel => $bytes) {
                $abs = $dirs[$part] . '/' . $rel;
                @mkdir(dirname($abs), 0775, true);
                file_put_contents($abs, $bytes);
                $in[$part][] = $rel;
            }
        }
        // Lo que no debe entrar: cachés, registros, temporales, copias anteriores y la configuración con la clave secreta.
        $noise = [
            $dirs['media'] . '/cache/miniatura.jpg' => 'miniatura',
            $dirs['private'] . '/cache/c.bin' => 'cache privada',
            $dirs['private'] . '/logs/l.txt' => 'registro',
            $dirs['private'] . '/tmp/t.txt' => 'temporal',
            $dirs['private'] . '/backups/copia-ajena.txt' => 'otra copia',
            $dirs['private'] . '/invoices/.tmp-trabajo' => 'temporal suelto',
            $app->site()->path('config.php') => "<?php\nreturn ['secret' => '" . $app->site()->secret() . "', 'db' => ['password' => 'clave-de-mentira']];\n",
        ];
        foreach ($noise as $abs => $bytes) {
            @mkdir(dirname($abs), 0775, true);
            file_put_contents($abs, $bytes);
            $in['noise'][] = $abs;
        }
        sort($in['media']);
        sort($in['private']);

        return $in;
    }

    /**
     * @return array<string,mixed>
     */
    function bkfState(App $app): array
    {
        $volatile = ['af_settings', 'af_sessions', 'af_rate_limits', 'af_jobs', 'af_audit'];
        $tables = [];
        $rows = $app->db()->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'af\\_%' ESCAPE '\\' ORDER BY name");
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (!in_array($name, $volatile, true)) {
                $tables[$name] = (int) $app->db()->value('SELECT COUNT(*) FROM "' . $name . '"');
            }
        }
        $trees = [];
        foreach (BackupAreas::dirs($app) as $part => $dir) {
            $trees[$part] = [];
            foreach (BackupAreas::files($dir, $part) as $rel) {
                $trees[$part][$rel] = (string) hash_file('sha256', $dir . '/' . $rel);
            }
        }

        return ['tables' => $tables, 'files' => $trees, 'demo' => $app->settings()->str('demo.clave')];
    }

    /**
     * @return string[]
     */
    function bkfZipNames(string $path): array
    {
        $zip = new ZipArchive();
        check($zip->open($path) === true, 'se abre el zip ' . basename($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);

        return $names;
    }

    function bkfZipText(string $path): string
    {
        $zip = new ZipArchive();
        check($zip->open($path) === true, 'se abre el zip ' . basename($path));
        $all = '';
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $all .= (string) $zip->getNameIndex($i) . "\n" . (string) $zip->getFromIndex($i) . "\n";
        }
        $zip->close();

        return $all;
    }

    function bkfTamper(string $path, callable $fn): void
    {
        $zip = new ZipArchive();
        check($zip->open($path) === true, 'se abre el zip para estropearlo');
        $fn($zip);
        check($zip->close(), 'se cierra el zip estropeado');
        clearstatcache();
    }

    /**
     * @return array<string,mixed>
     */
    function bkfManifest(string $path): array
    {
        $zip = new ZipArchive();
        check($zip->open($path) === true);
        $json = (string) $zip->getFromName('manifest.json');
        $zip->close();
        $data = json_decode($json, true);
        check(is_array($data), 'el manifiesto es JSON');

        return is_array($data) ? $data : [];
    }

    function bkfSetManifest(string $path, callable $fn): void
    {
        $manifest = bkfManifest($path);
        $manifest = $fn($manifest);
        bkfTamper($path, static function (ZipArchive $zip) use ($manifest): void {
            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_UNESCAPED_SLASHES));
        });
    }

    /**
     * @return string[]
     */
    function bkfLeftovers(App $app): array
    {
        $out = [];
        foreach (BackupAreas::dirs($app) as $live) {
            foreach ((array) glob($live . '.restore-*') as $found) {
                $out[] = $found;
            }
            foreach ((array) glob($live . '.before-restore-*') as $found) {
                $out[] = $found;
            }
        }
        foreach ((array) glob($app->site()->path('tmp') . '/restore-*') as $found) {
            $out[] = $found;
        }

        return $out;
    }

    /**
     * Un zip como el de un sitio MySQL, escrito a mano, con las sumas bien puestas: sirve para probar la lectura del volcado SQL
     * (que sí es texto) sin tener un MySQL.
     *
     * @param array<string,mixed> $opt cut (true = el volcado sin su final), extra (entrada de más), prefix
     */
    function bkfFakeMysqlZip(string $dir, array $opt = []): string
    {
        $prefix = isset($opt['prefix']) ? (string) $opt['prefix'] : 'af_';
        $sql = "-- AIFEC backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n"
            . 'DROP TABLE IF EXISTS `' . $prefix . "demo`;\nCREATE TABLE `" . $prefix . "demo` (\n  `id` int NOT NULL,\n  `txt` text,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB;\n\n"
            . 'INSERT INTO `' . $prefix . "demo` (`id`, `txt`) VALUES\n(1, 'a;b'),\n(2, 'it\\'s; ñ ; \"q\" `x`'),\n(3, NULL);\n";
        if (empty($opt['cut'])) {
            $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        }
        $path = $dir . '/backup-20261112-120000-01.zip';
        $manifest = [
            'format' => 1, 'app' => 'AIFEC', 'version' => '1.0.0', 'created_at' => '2026-11-12 12:00:00', 'mode' => 'db',
            'site' => ['id' => 'otro', 'url' => 'https://otra.test'],
            'database' => ['engine' => 'mysql', 'prefix' => $prefix, 'tables' => 1, 'rows' => 3, 'rows_by_table' => [$prefix . 'demo' => 3]],
            'parts' => ['db' => ['entry' => 'db/dump.sql', 'bytes' => strlen($sql), 'sha256' => hash('sha256', $sql)]],
        ];
        $zip = new ZipArchive();
        check($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('db/dump.sql', $sql);
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        if (!empty($opt['extra'])) {
            $zip->addFromString((string) $opt['extra'], 'x');
        }
        check($zip->close());

        return $path;
    }
}
