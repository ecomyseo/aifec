<?php
/**
 * AIFEC — apoyo de las pruebas del módulo de plugins: raíces de mentira con copia de core/ y de los módulos mínimos,
 * plugins de prueba escritos en ellas, ZIP y lectura de los informes.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once. Nunca se escribe en plugins/ del
 * proyecto: todo ocurre en carpetas temporales que borra el lanzador.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Modules\Plugins\Fs;
use Aifec\Modules\Plugins\Plugins;
use Aifec\Modules\Plugins\Validator;

if (!function_exists('pluginsTest')) {
    /**
     * Una prueba que, al acabar, devuelve el cargador de clases a como estaba (una aplicación sobre una raíz de mentira
     * registra en él las carpetas de esa raíz).
     */
    function pluginsTest(string $name, callable $fn): void
    {
        test($name, function () use ($fn): void {
            $property = new \ReflectionProperty(Autoload::class, 'prefixes');
            $property->setAccessible(true);
            $saved = $property->getValue();
            try {
                $fn();
            } finally {
                $property->setValue(null, $saved);
            }
        });
    }

    /**
     * Raíz de mentira de referencia (una por conjunto de módulos y por pasada): el núcleo sin código ni recursos, el módulo
     * de plugins, los de tareas y correo y los que se pidan. Cada prueba parte de una copia.
     *
     * @param string[] $modules
     */
    function pluginsMaster(array $modules): string
    {
        static $cache = [];
        $modules = array_values(array_unique(array_merge(['jobs', 'mail', 'plugins'], $modules)));
        sort($modules);
        $key = implode(',', $modules);
        if (!isset($cache[$key]) || !is_dir($cache[$key])) {
            $real = Autoload::root();
            $dir = tmpDir('plugins-master');
            Fs::copyTree($real . '/core', $dir . '/core', static function (string $rel): bool {
                return $rel === 'src' || $rel === 'assets';
            });
            foreach ($modules as $module) {
                Fs::copyTree($real . '/modules/' . $module, $dir . '/modules/' . $module, static function (string $rel): bool {
                    return $rel === 'tests' || strpos($rel, 'tests/') === 0;
                });
            }
            Fs::copyTree($real . '/themes', $dir . '/themes');
            mkdir($dir . '/plugins', 0777, true);
            $cache[$key] = $dir;
        }

        return $cache[$key];
    }

    /**
     * Una raíz de mentira nueva para una prueba.
     *
     * @param string[] $modules módulos que lleva además de jobs, mail y plugins
     */
    function pluginsRoot(array $modules = []): string
    {
        $root = tmpDir('plugins-root');
        Fs::copyTree(pluginsMaster($modules), $root);

        return $root;
    }

    /** Aplicación de prueba sobre una raíz de mentira, arrancada. */
    function pluginsApp(string $root): App
    {
        $app = testApp(['root' => $root]);
        $app->boot();

        return $app;
    }

    /** El gestor de esa aplicación, con una carpeta de temporales que borra el lanzador. */
    function pluginsSvc(App $app): Plugins
    {
        /** @var Plugins $svc */
        $svc = $app->service('plugins');
        $svc->useTempBase(tmpDir('plugins-tmp'));

        return $svc;
    }

    /** Otra aplicación sobre el mismo sitio (misma base de datos), como la siguiente petición. */
    function pluginsReboot(App $app, string $root): App
    {
        $next = new App($app->site(), $root, ['clock' => new Clock(testNow()), 'http' => new FakeHttpClient(), 'log_files' => false, 'public_dir' => $app->publicDir()]);
        $next->makeCurrent();
        $next->boot();

        return $next;
    }

    /**
     * Los ficheros de un plugin de prueba limpio: ruta relativa => contenido. $over: manifest (se funde con el de serie),
     * raw_manifest (texto que sustituye al plugin.json), lang (cadenas extra del catálogo castellano) y files (ruta =>
     * contenido; null quita el fichero).
     *
     * @param array<string,mixed> $over
     *
     * @return array<string,string>
     */
    function pluginsFiles(string $id, array $over = []): array
    {
        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $id)));
        $manifest = array_replace_recursive([
            'name' => 'Test Plugin', 'version' => '1.0.0', 'description' => 'A plugin for tests', 'namespace' => 'Tests\\' . $studly,
            'essential' => false, 'default' => false, 'requires' => ['modules' => []],
            'contract' => ['hosts' => [], 'personal_data' => [], 'tables' => [], 'capabilities' => []],
        ], isset($over['manifest']) && is_array($over['manifest']) ? $over['manifest'] : []);
        $lang = ['Test Plugin' => 'Plugin de pruebas', 'A plugin for tests' => 'Un plugin de pruebas'];
        if (isset($manifest['name']) && is_string($manifest['name']) && !isset($lang[$manifest['name']])) {
            $lang[$manifest['name']] = $manifest['name'];
        }
        foreach (isset($over['lang']) && is_array($over['lang']) ? $over['lang'] : [] as $k => $v) {
            $lang[(string) $k] = (string) $v;
        }
        $files = [
            'plugin.json' => isset($over['raw_manifest']) ? (string) $over['raw_manifest'] : (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'hooks.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn static function (\\Aifec\\Kernel\\App \$app): void {\n};\n",
            'lang/es.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($lang, true) . ";\n",
        ];
        foreach (isset($over['files']) && is_array($over['files']) ? $over['files'] : [] as $rel => $content) {
            if ($content === null) {
                unset($files[(string) $rel]);
            } else {
                $files[(string) $rel] = (string) $content;
            }
        }

        return $files;
    }

    /**
     * Escribe un plugin de prueba en plugins/<id> de una raíz de mentira y devuelve su carpeta.
     *
     * @param array<string,mixed> $over ver pluginsFiles()
     */
    function pluginsMake(string $root, string $id, array $over = []): string
    {
        $dir = $root . '/plugins/' . $id;
        foreach (pluginsFiles($id, $over) as $rel => $content) {
            if (!is_dir(dirname($dir . '/' . $rel))) {
                mkdir(dirname($dir . '/' . $rel), 0777, true);
            }
            file_put_contents($dir . '/' . $rel, $content);
        }

        return $dir;
    }

    /** El cuerpo de un hooks.php que registra lo que se le pase dentro de la función. */
    function pluginsHooks(string $body): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn static function (\\Aifec\\Kernel\\App \$app): void {\n" . $body . "\n};\n";
    }

    /**
     * Informe solo con las comprobaciones estáticas de un plugin ya escrito en la raíz.
     *
     * @return array<string,mixed>
     */
    function pluginsStatic(App $app, string $root, string $id): array
    {
        $raw = json_decode((string) file_get_contents($root . '/plugins/' . $id . '/plugin.json'), true);

        return Validator::run($app->service('plugins'), $root, $id, is_array($raw) ? $raw : [], false);
    }

    /**
     * Reglas que fallan en un informe, sin repetir y ordenadas.
     *
     * @param array<string,mixed> $report
     *
     * @return string[]
     */
    function pluginsFailed(array $report, string $level = 'error'): array
    {
        $rules = [];
        foreach ($report['checks'] as $check) {
            if (!$check['ok'] && $check['level'] === $level) {
                $rules[$check['rule']] = true;
            }
        }
        $names = array_keys($rules);
        sort($names);

        return $names;
    }

    /**
     * Las comprobaciones de un informe que fallan en una regla.
     *
     * @param array<string,mixed> $report
     *
     * @return array<int,array<string,mixed>>
     */
    function pluginsChecks(array $report, string $rule): array
    {
        $out = [];
        foreach ($report['checks'] as $check) {
            if ($check['rule'] === $rule && !$check['ok']) {
                $out[] = $check;
            }
        }

        return $out;
    }

    /** Todos los mensajes de un informe, unidos (para buscar un texto). */
    function pluginsMessages(array $report): string
    {
        $text = '';
        foreach ($report['checks'] as $check) {
            $text .= $check['rule'] . ' ' . $check['message'] . ' ' . $check['file'] . ':' . $check['line'] . "\n";
        }

        return $text;
    }

    /**
     * Crea un ZIP en una carpeta temporal.
     *
     * @param array<string,string> $entries nombre => contenido (un nombre que acaba en «/» es una carpeta)
     */
    function pluginsZip(array $entries): string
    {
        $path = tmpDir('plugins-zip') . '/plugin.zip';
        $zip = new \ZipArchive();
        check($zip->open($path, \ZipArchive::CREATE) === true, 'se abre el ZIP de prueba');
        foreach ($entries as $name => $content) {
            if (substr((string) $name, -1) === '/') {
                $zip->addEmptyDir(rtrim((string) $name, '/'));
            } else {
                $zip->addFromString((string) $name, (string) $content);
            }
        }
        $zip->close();

        return $path;
    }

    /**
     * Un ZIP escrito a mano, byte a byte, para poder meter nombres que una biblioteca de ZIP no dejaría escribir («../»,
     * rutas absolutas) y enlaces simbólicos.
     *
     * @param array<int,array{name:string,data:string,link?:bool}> $entries
     */
    function pluginsRawZip(array $entries): string
    {
        $body = '';
        $central = '';
        foreach ($entries as $entry) {
            $name = $entry['name'];
            $data = $entry['data'];
            $crc = pack('V', crc32($data));
            $offset = strlen($body);
            $body .= pack('Vvvvvv', 0x04034b50, 20, 0, 0, 0, 0x21) . $crc . pack('VVvv', strlen($data), strlen($data), strlen($name), 0) . $name . $data;
            $link = !empty($entry['link']);
            $madeBy = ($link ? 3 : 0) * 256 + 20;
            $attr = $link ? (0120777 << 16) : 0;
            $central .= pack('Vvvvvvv', 0x02014b50, $madeBy, 20, 0, 0, 0, 0x21) . $crc
                . pack('VVvvvvvVV', strlen($data), strlen($data), strlen($name), 0, 0, 0, 0, $attr, $offset) . $name;
        }
        $count = count($entries);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
        $path = tmpDir('plugins-zip') . '/raw.zip';
        file_put_contents($path, $body . $central . $end);

        return $path;
    }

    /**
     * El ZIP de un plugin limpio de prueba: todos sus ficheros dentro de la carpeta <id>/.
     *
     * @param array<string,mixed> $over ver pluginsFiles()
     */
    function pluginsZipOf(string $id, array $over = []): string
    {
        $entries = [];
        foreach (pluginsFiles($id, $over) as $rel => $content) {
            $entries[$id . '/' . $rel] = $content;
        }

        return pluginsZip($entries);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<string,array<string,mixed>>
     */
    function pluginsById(array $rows): array
    {
        $by = [];
        foreach ($rows as $row) {
            $by[(string) $row['id']] = $row;
        }

        return $by;
    }

    /** Migración que crea una tabla declarada y otra sin declarar. */
    function pluginsMigration(string $declared, string $stray = ''): string
    {
        $body = "    \$schema->create('" . $declared . "', static function (TableDef \$t): void {\n        \$t->id();\n        \$t->string('name', 40);\n    });\n";
        if ($stray !== '') {
            $body .= "    \$schema->create('" . $stray . "', static function (TableDef \$t): void {\n        \$t->id();\n    });\n";
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nuse Aifec\\Db\\Db;\nuse Aifec\\Db\\Schema;\nuse Aifec\\Db\\TableDef;\n\nreturn static function (Schema \$schema, Db \$db): void {\n" . $body . "};\n";
    }

    /**
     * Lo que hay dentro de plugins/ de una raíz (para comprobar que un rechazo no escribe nada).
     *
     * @return string[]
     */
    function pluginsListed(string $root): array
    {
        return Fs::files($root . '/plugins');
    }
}
