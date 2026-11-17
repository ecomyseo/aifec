<?php
/**
 * AIFEC — apoyo de las pruebas del módulo de temas: una raíz de mentira (núcleo sin código, el módulo de temas y los temas)
 * donde escribir, temas hijo de prueba y lecturas de la base de datos.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once. Nunca se escribe en themes/ del
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
use Aifec\Modules\Themes\Files;
use Aifec\Modules\Themes\Themes;

if (!function_exists('themesTest')) {
    /**
     * Una prueba que, al acabar, devuelve el cargador de clases a como estaba (una aplicación sobre una raíz de mentira
     * registra en él las carpetas de esa raíz).
     */
    function themesTest(string $name, callable $fn): void
    {
        test($name, function () use ($fn): void {
            $property = new \ReflectionProperty(Autoload::class, 'prefixes');
            $property->setAccessible(true);
            $saved = $property->getValue();
            try {
                $fn();
            } finally {
                $property->setValue(null, $saved);
                if (class_exists('Aifec\\Kernel\\App', false)) {
                    App::clearCurrent();
                }
            }
        });
    }

    /** Un PNG de 1 × 1 para las vistas previas de mentira. */
    function themesPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    /**
     * Raíz de mentira de referencia (una por pasada): el núcleo sin código ni recursos, el módulo de temas y los temas, con una
     * vista previa en el tema base. Cada prueba parte de una copia.
     */
    function themesMaster(): string
    {
        static $dir = '';
        if ($dir === '' || !is_dir($dir)) {
            $real = Autoload::root();
            $dir = tmpDir('themes-master');
            foreach (Files::files($real . '/core') as $rel) {
                if (strpos($rel, 'src/') === 0 || strpos($rel, 'assets/') === 0) {
                    continue;
                }
                themesCopyFile($real . '/core/' . $rel, $dir . '/core/' . $rel);
            }
            foreach (Files::files($real . '/modules/themes') as $rel) {
                if (strpos($rel, 'tests/') === 0) {
                    continue;
                }
                themesCopyFile($real . '/modules/themes/' . $rel, $dir . '/modules/themes/' . $rel);
            }
            foreach (Files::files($real . '/themes') as $rel) {
                if (strpos($rel, 'base/') !== 0) {
                    continue; // solo el tema base: los temas de sector no cambian lo que estas pruebas cuentan
                }
                themesCopyFile($real . '/themes/' . $rel, $dir . '/themes/' . $rel);
            }
            file_put_contents($dir . '/themes/base/preview.png', themesPng());
        }

        return $dir;
    }

    function themesCopyFile(string $from, string $to): void
    {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        copy($from, $to);
    }

    /** Una raíz de mentira nueva para una prueba. */
    function themesRoot(): string
    {
        $root = tmpDir('themes-root');
        Files::copyTree(themesMaster(), $root);

        return $root;
    }

    /**
     * Aplicación de prueba sobre una raíz de mentira, arrancada.
     *
     * @param array<string,mixed> $settings ajustes iniciales
     */
    function themesApp(string $root, array $settings = []): App
    {
        $options = ['root' => $root];
        if ($settings !== []) {
            $options['settings'] = $settings;
        }
        $app = testApp($options);
        $app->boot();

        return $app;
    }

    /** Otra aplicación sobre el mismo sitio (misma base de datos), como la siguiente petición de un visitante. */
    function themesReboot(App $app, string $root): App
    {
        $next = new App($app->site(), $root, ['clock' => new Clock(testNow()), 'http' => new FakeHttpClient(), 'log_files' => false, 'public_dir' => $app->publicDir()]);
        $next->makeCurrent();
        $next->boot();

        return $next;
    }

    /** El servicio de temas de una aplicación. */
    function themesSvc(App $app): Themes
    {
        /** @var Themes $themes */
        $themes = $app->service('themes');

        return $themes;
    }

    /**
     * Escribe un tema hijo de prueba en themes/<slug> de una raíz de mentira.
     *
     * @param array<string,string|null> $files ruta relativa => contenido (null borra el fichero)
     * @param array<string,mixed>       $json  se funde con el theme.json de serie
     */
    function themesChild(string $root, string $slug, array $files = [], array $json = []): string
    {
        $dir = $root . '/themes/' . $slug;
        $manifest = array_replace_recursive(['name' => ucfirst($slug), 'version' => '1.0.0', 'parent' => 'base'], $json);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/theme.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach ($files as $rel => $content) {
            $path = $dir . '/' . $rel;
            if ($content === null) {
                @unlink($path);
                continue;
            }
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $content);
        }

        return $dir;
    }

    /** Cambia un trozo de un fichero de un tema de la raíz y comprueba que había algo que cambiar. */
    function themesPatch(string $root, string $relative, string $from, string $to): void
    {
        $file = $root . '/' . $relative;
        $text = (string) file_get_contents($file);
        check(strpos($text, $from) !== false, 'el fichero ' . $relative . ' contiene «' . $from . '»');
        file_put_contents($file, str_replace($from, $to, $text));
    }

    /** Ajuste guardado en la base de datos (descodificado), o el valor por defecto si no existe. */
    function themesSetting(App $app, string $key, $default = null)
    {
        $json = $app->db()->value('SELECT v FROM {settings} WHERE k = ?', [$key]);

        return is_string($json) ? json_decode($json, true) : $default;
    }

    /**
     * Ids de las comprobaciones de un informe que fallan en un nivel.
     *
     * @param array<string,mixed> $report
     *
     * @return string[]
     */
    function themesFailed(array $report, string $level = 'error'): array
    {
        $ids = [];
        foreach ($report['checks'] as $check) {
            if (!$check['ok'] && $check['level'] === $level) {
                $ids[$check['id']] = true;
            }
        }
        $names = array_keys($ids);
        sort($names);

        return $names;
    }

    /** Todos los ficheros de una raíz bajo themes/ (para comprobar que un rechazo no escribe nada). */
    function themesListed(string $root): array
    {
        return Files::files($root . '/themes');
    }

    /** Publica un servicio «ai» de mentira que contesta lo que se le diga y guarda la última petición. */
    function themesFakeAi(App $app, ?array $json, bool $enabled = true, bool $throws = false): \stdClass
    {
        $state = new \stdClass();
        $state->calls = [];
        $app->bind('ai', static function () use ($state, $json, $enabled, $throws): object {
            return new class($state, $json, $enabled, $throws) {
                /** @var \stdClass */
                private $state;
                /** @var array<string,mixed>|null */
                private $json;
                /** @var bool */
                private $enabled;
                /** @var bool */
                private $throws;

                public function __construct(\stdClass $state, ?array $json, bool $enabled, bool $throws)
                {
                    $this->state = $state;
                    $this->json = $json;
                    $this->enabled = $enabled;
                    $this->throws = $throws;
                }

                public function enabled(): bool
                {
                    return $this->enabled;
                }

                public function ask(string $task, array $request, string $tier = 'economy'): \Aifec\Ai\AiResult
                {
                    $this->state->calls[] = ['task' => $task, 'request' => $request, 'tier' => $tier];
                    if ($this->throws) {
                        throw new \Aifec\Ai\AiException('The provider failed');
                    }

                    return new \Aifec\Ai\AiResult('', [], $this->json, 10, 10, 'STOP', 'fake');
                }
            };
        });

        return $state;
    }
}
