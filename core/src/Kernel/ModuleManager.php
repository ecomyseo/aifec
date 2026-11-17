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

use Aifec\Autoload;

/**
 * Descubre, ordena y carga los módulos incluidos (modules/) y los plugins (plugins/).
 * Los dos tienen la misma forma y la misma API; solo cambia el nombre del manifiesto
 * (module.json o plugin.json) y que un plugin declara su propio espacio de nombres.
 *
 * Ficheros que el sistema recoge por convenio en la carpeta de cada uno (todos opcionales):
 *
 *   hooks.php      return function (App $app): void { … }           ganchos, servicios, proveedores
 *   routes.php     return function (Router $r, App $app): void { … } rutas fijas
 *   settings.php   return [ … ]                                      ajustes con sus pestañas y valores por defecto
 *   admin.php      return [ … ]                                      menú, permisos, recursos y piezas del panel
 *   jobs.php       return [ 'tipo' => callable, … ]                  tareas en segundo plano
 *   cli.php        return [ 'orden' => callable, … ]                 órdenes de consola
 *   blocks.php     return [ 'tipo' => callable, … ]                  bloques de contenido dinámicos
 *   migrations/    NNNN_nombre.php                                   tablas propias
 *   views/, lang/, assets/, mail/, tests/
 *
 * Un fallo al cargar un plugin no tumba el sitio: se anota, el plugin queda sin cargar en esa
 * petición y, si se repite, se desactiva solo.
 */
final class ModuleManager
{
    /** @var string raíz de la instalación */
    private $root;

    /** @var array<string,array<string,mixed>>|null id => manifiesto (con 'path' y 'kind') */
    private $all;

    /** @var string[]|null si no es null, los únicos módulos que existen para esta instalación */
    private $only;

    /** @var string[] ids cargados en esta petición, por orden */
    private $loaded = [];

    /** @var array<string,string> id => error al cargar */
    private $failed = [];

    /** @var array<string,array<string,mixed>> caché de ficheros de definición ya leídos */
    private $files = [];

    /** @var array<string,array<string,bool>>|null de la caché de arranque: id => [fichero de definición => existe] */
    private $known;

    /** @var int veces que se han recorrido las carpetas de módulos y plugins (una página servida con la caché: 0) */
    private $scans = 0;

    /** @var array<string,float> milisegundos que tardó el hooks.php de cada módulo en el arranque con la caché */
    private $timings = [];

    /** Ficheros de definición que el sistema recoge por convenio (ver la cabecera de la clase). */
    private const DEFINITIONS = ['hooks', 'routes', 'settings', 'admin', 'jobs', 'cli', 'blocks'];

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * Todos los módulos y plugins instalados (activos o no).
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        ++$this->scans;
        $this->all = [];
        foreach (['modules' => 'module.json', 'plugins' => 'plugin.json'] as $base => $manifestName) {
            $dirs = glob($this->root . '/' . $base . '/*', GLOB_ONLYDIR);
            foreach (is_array($dirs) ? $dirs : [] as $dir) {
                $dir = str_replace('\\', '/', $dir);
                $id = basename($dir);
                $file = $dir . '/' . $manifestName;
                if (!is_file($file) || preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id) !== 1) {
                    continue;
                }
                $manifest = json_decode((string) file_get_contents($file), true);
                if (!is_array($manifest)) {
                    $this->failed[$id] = 'Invalid ' . $manifestName;
                    continue;
                }
                $manifest['id'] = $id;
                $manifest['path'] = $dir;
                $manifest['kind'] = $base === 'modules' ? 'module' : 'plugin';
                $manifest += ['name' => $id, 'version' => '0.0.0', 'description' => '', 'essential' => false, 'default' => false, 'requires' => []];
                // Si un plugin y un módulo se llaman igual, manda el módulo incluido.
                if (!isset($this->all[$id])) {
                    $this->all[$id] = $manifest;
                }
            }
        }
        ksort($this->all);
        if ($this->only !== null) {
            // Instalación recortada (pruebas): solo existen los módulos pedidos y los que estos necesitan.
            $keep = [];
            $pending = $this->only;
            while ($pending !== []) {
                $id = (string) array_pop($pending);
                if (isset($keep[$id]) || !isset($this->all[$id])) {
                    continue;
                }
                $keep[$id] = true;
                $needs = isset($this->all[$id]['requires']['modules']) && is_array($this->all[$id]['requires']['modules']) ? $this->all[$id]['requires']['modules'] : [];
                foreach ($needs as $need) {
                    $pending[] = (string) $need;
                }
            }
            $this->all = array_intersect_key($this->all, $keep);
        }

        return $this->all;
    }

    /**
     * Deja la instalación como si solo existieran estos módulos (y los que necesitan). null = todos.
     * Sirve para que las pruebas de un módulo no dependan de los demás.
     *
     * @param string[]|null $ids
     */
    public function only(?array $ids): void
    {
        $this->only = $ids;
        $this->all = null;
        $this->known = null;
    }

    /**
     * Lista de módulos recortada (pruebas, «modules_only»), o null si existen todos.
     *
     * @return string[]|null
     */
    public function onlyIds(): ?array
    {
        return $this->only;
    }

    /** Veces que se han recorrido las carpetas en esta instancia (una página servida con la caché de arranque: 0). */
    public function scans(): int
    {
        return $this->scans;
    }

    /**
     * Lo que tardó el hooks.php de cada módulo en el último arranque con la caché (para perfil.php).
     *
     * @return array<string,float> id => milisegundos
     */
    public function timings(): array
    {
        return $this->timings;
    }

    /**
     * Carga lo que la caché de arranque ya sabe (manifiestos y qué ficheros de definición tiene cada uno), para no volver
     * a recorrer las carpetas ni a preguntar al disco por cada fichero.
     *
     * @param array<string,array<string,mixed>> $manifests id => manifiesto, como los dio all()
     * @param array<string,array<string,bool>>  $files     id => [nombre => existe]
     */
    public function seed(array $manifests, array $files): void
    {
        $this->all = $manifests;
        $this->known = $files;
    }

    /**
     * Qué ficheros de definición tiene cada módulo y plugin (y el núcleo): id => [nombre => existe]. Para la caché.
     *
     * @return array<string,array<string,bool>>
     */
    public function fileMap(): array
    {
        $map = [];
        foreach (array_merge(['core' => ['path' => $this->root . '/core']], $this->all()) as $id => $manifest) {
            $path = (string) $manifest['path'];
            foreach (self::DEFINITIONS as $name) {
                $map[(string) $id][$name] = is_file($path . '/' . $name . '.php');
            }
            $map[(string) $id]['lang'] = is_dir($path . '/lang');
            $map[(string) $id]['views'] = is_dir($path . '/views');
        }

        return $map;
    }

    /**
     * Huella de todo lo que la caché de arranque copia del disco: carpetas de módulos, plugins y temas, sus manifiestos,
     * sus ficheros de definición y sus catálogos de idioma (fecha y tamaño de cada uno). Si cambia algo, cambia la huella.
     * Recorrer todo esto cuesta unos milisegundos: solo lo hacen el panel, la consola y el cron (ver App::schemaCheckDue()).
     */
    public function fingerprint(): string
    {
        clearstatcache();
        $parts = [App::VERSION, implode(',', $this->only !== null ? $this->only : ['*'])];
        $names = [];
        foreach (self::DEFINITIONS as $name) {
            $names[] = $name . '.php';
        }
        $parts[] = 'core' . self::stamp($this->root . '/core', $names, ['lang', 'views']);
        foreach (['modules' => 'module.json', 'plugins' => 'plugin.json', 'themes' => 'theme.json'] as $base => $manifest) {
            $dirs = glob($this->root . '/' . $base . '/*', GLOB_ONLYDIR);
            $dirs = is_array($dirs) ? $dirs : [];
            sort($dirs);
            foreach ($dirs as $dir) {
                $dir = str_replace('\\', '/', $dir);
                $files = $base === 'themes' ? [$manifest, 'functions.php'] : array_merge([$manifest], $names);
                $parts[] = $base . '/' . basename($dir) . self::stamp($dir, $files, $base === 'themes' ? ['lang', 'templates'] : ['lang', 'views']);
            }
        }

        return md5(implode("\n", $parts));
    }

    /**
     * @param string[] $files
     * @param string[] $dirs
     */
    private static function stamp(string $dir, array $files, array $dirs): string
    {
        $out = '';
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                $out .= '|' . $file . ':-';
            } elseif (in_array($file, ['module.json', 'plugin.json', 'theme.json', 'routes.php', 'settings.php'], true)) {
                // Lo que la caché copia (manifiestos, rutas compiladas, valores por defecto) va por contenido: un cambio
                // del mismo tamaño en el mismo segundo también cuenta.
                $out .= '|' . $file . ':' . (string) md5_file($path);
            } else {
                $out .= '|' . $file . ':' . (int) filemtime($path) . ':' . (int) filesize($path);
            }
        }
        foreach ($dirs as $sub) {
            $path = $dir . '/' . $sub;
            if (!is_dir($path)) {
                $out .= '|' . $sub . ':-';
                continue;
            }
            $out .= '|' . $sub . ':d';
            if ($sub === 'lang') {
                $langs = glob($path . '/*.php');
                $langs = is_array($langs) ? $langs : [];
                sort($langs);
                foreach ($langs as $lang) {
                    $out .= ',' . basename($lang) . ':' . (int) filemtime($lang) . ':' . (int) filesize($lang);
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function manifest(string $id): ?array
    {
        $all = $this->all();

        return isset($all[$id]) ? $all[$id] : null;
    }

    public function path(string $id): ?string
    {
        if ($id === 'core') {
            return $this->root . '/core';
        }
        $manifest = $this->manifest($id);

        return $manifest !== null ? (string) $manifest['path'] : null;
    }

    /**
     * Ids activos para un sitio, ordenados para que cada uno cargue después de los que necesita.
     *
     * @param string[]|null $enabled lo guardado en el ajuste «modules.active»; null = sitio nuevo (los de serie)
     *
     * @return string[]
     */
    public function activeIds(?array $enabled): array
    {
        $active = [];
        foreach ($this->all() as $id => $manifest) {
            if (!empty($manifest['essential'])) {
                $active[$id] = true;
            } elseif ($enabled === null ? !empty($manifest['default']) : in_array($id, $enabled, true)) {
                $active[$id] = true;
            }
        }
        // Lo que un activo necesita se activa con él; lo que no existe lo deja fuera.
        $ordered = [];
        $visiting = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$visiting, &$active): bool {
            if (isset($ordered[$id])) {
                return true;
            }
            $manifest = $this->manifest($id);
            if ($manifest === null || isset($visiting[$id])) {
                return false;
            }
            $visiting[$id] = true;
            $needs = isset($manifest['requires']['modules']) && is_array($manifest['requires']['modules']) ? $manifest['requires']['modules'] : [];
            foreach ($needs as $need) {
                if (!$visit((string) $need)) {
                    unset($visiting[$id]);
                    $this->failed[$id] = 'Requires missing module: ' . $need;

                    return false;
                }
            }
            unset($visiting[$id]);
            $ordered[$id] = true;

            return true;
        };
        foreach (array_keys($active) as $id) {
            $visit((string) $id);
        }

        return array_keys($ordered);
    }

    /**
     * Carga los módulos activos: catálogos, vistas, ajustes, ganchos y rutas (recorriendo el disco).
     *
     * Devuelve lo necesario para la caché de arranque: lo que hizo con cada módulo (espacio de nombres, permisos
     * concedidos, carpetas de idioma y vistas, si tiene hooks.php, y sus rutas ya compiladas), los valores por defecto
     * de los ajustes ya fundidos y si todo cargó bien ('ok' = false si algún módulo falló: así no se guarda y se vuelve
     * a intentar en la petición siguiente).
     *
     * @param string[] $ids
     *
     * @return array{ok:bool,core:array<string,mixed>,modules:array<string,array<string,mixed>>,defaults:array<string,mixed>}
     */
    public function boot(App $app, array $ids): array
    {
        $defaults = [];
        $ok = true;
        // El núcleo se declara como un módulo más (ajustes, ganchos, rutas, panel, consola) y va el primero.
        $core = $this->root . '/core';
        $app->hooks()->setCurrentModule('core');
        $app->router()->setModule('core');
        $coreSettings = $this->definition('core', 'settings');
        if ($coreSettings !== []) {
            $defaults = self::settingDefaults($coreSettings) + $defaults;
            $app->settings()->defaults(self::settingDefaults($coreSettings));
        }
        $coreInfo = ['hooks' => is_file($core . '/hooks.php'), 'routes' => null];
        if ($coreInfo['hooks']) {
            $callable = require $core . '/hooks.php';
            if (is_callable($callable)) {
                $callable($app);
            }
        }
        if (is_file($core . '/routes.php')) {
            $coreInfo['routes'] = $this->runRoutes($app, $core . '/routes.php');
        }

        $info = [];
        foreach ($ids as $id) {
            $manifest = $this->manifest($id);
            if ($manifest === null) {
                continue;
            }
            $path = (string) $manifest['path'];
            $prefix = null;
            if ($manifest['kind'] === 'module') {
                // «cms-pages» → Aifec\Modules\CmsPages
                $prefix = ['Aifec\\Modules\\' . str_replace(' ', '', ucwords(str_replace('-', ' ', $id))), $path . '/src'];
            } elseif (isset($manifest['namespace']) && is_string($manifest['namespace']) && strpos($manifest['namespace'], 'Aifec\\') !== 0) {
                $prefix = [$manifest['namespace'], $path . '/src'];
            }
            if ($prefix !== null) {
                Autoload::addPrefix($prefix[0], $prefix[1]);
            }
            $app->hooks()->setCurrentModule($id);
            $app->router()->setModule($id);
            $granted = [];
            foreach ((array) (isset($manifest['contract']['capabilities']) ? $manifest['contract']['capabilities'] : []) as $capability) {
                // Los módulos incluidos tienen concedido lo que declaran; a un plugin se lo concede el comerciante.
                if ($manifest['kind'] === 'module' || in_array($id . ':' . $capability, $app->settings()->arr('plugins.granted'), true)) {
                    $app->hooks()->grant($id, (string) $capability);
                    $granted[] = (string) $capability;
                }
            }
            $entry = ['prefix' => $prefix, 'caps' => $granted, 'lang' => false, 'views' => false, 'hooks' => false, 'routes' => null];
            try {
                if (is_dir($path . '/lang')) {
                    $app->i18n()->addDir($path . '/lang');
                    $entry['lang'] = true;
                }
                if (is_dir($path . '/views')) {
                    $app->view()->addNamespace($id, $path . '/views');
                    $entry['views'] = true;
                }
                $settings = $this->definition($id, 'settings');
                if ($settings !== []) {
                    $defaults = self::settingDefaults($settings) + $defaults;
                    $app->settings()->defaults(self::settingDefaults($settings));
                }
                if (is_file($path . '/hooks.php')) {
                    $entry['hooks'] = true;
                    $callable = require $path . '/hooks.php';
                    if (is_callable($callable)) {
                        $callable($app);
                    }
                }
                if (is_file($path . '/routes.php')) {
                    $entry['routes'] = $this->runRoutes($app, $path . '/routes.php');
                }
                $this->loaded[] = $id;
                $info[$id] = $entry;
            } catch (\Throwable $e) {
                $ok = false;
                $this->failed[$id] = get_class($e) . ': ' . $e->getMessage();
                $app->log()->exception($e, 'module:' . $id);
                if (!empty($manifest['essential'])) {
                    throw $e;
                }
            }
        }
        $app->hooks()->setCurrentModule('core');
        $app->router()->setModule('core');

        return ['ok' => $ok, 'core' => $coreInfo, 'modules' => $info, 'defaults' => $defaults];
    }

    /**
     * Carga los módulos con lo que guardó la caché de arranque: sin recorrer carpetas, sin preguntar al disco si existe
     * cada fichero, sin leer ningún settings.php y sin ejecutar los routes.php compilados. Los hooks.php sí se ejecutan
     * (registran servicios y oyentes), uno a uno y en el mismo orden que la primera vez.
     *
     * @param array<string,mixed> $boot lo que guardó App en la caché (core, modules, defaults, ids)
     */
    public function bootCompiled(App $app, array $boot): void
    {
        $core = $this->root . '/core';
        $app->hooks()->setCurrentModule('core');
        $app->router()->setModule('core');
        if (isset($boot['defaults']) && is_array($boot['defaults']) && $boot['defaults'] !== []) {
            $app->settings()->defaults($boot['defaults']);
        }
        $coreInfo = isset($boot['core']) && is_array($boot['core']) ? $boot['core'] : [];
        $this->timings = [];
        if (!empty($coreInfo['hooks'])) {
            $t0 = microtime(true);
            $callable = require $core . '/hooks.php';
            if (is_callable($callable)) {
                $callable($app);
            }
            $this->timings['core'] = (microtime(true) - $t0) * 1000;
        }
        $this->applyRoutes($app, $core . '/routes.php', isset($coreInfo['routes']) ? $coreInfo['routes'] : null);

        $modules = isset($boot['modules']) && is_array($boot['modules']) ? $boot['modules'] : [];
        foreach (isset($boot['ids']) && is_array($boot['ids']) ? $boot['ids'] : [] as $id) {
            $id = (string) $id;
            if (!isset($modules[$id], $this->all[$id])) {
                continue;
            }
            $entry = $modules[$id];
            $manifest = $this->all[$id];
            $path = (string) $manifest['path'];
            if (is_array($entry['prefix'])) {
                Autoload::addPrefix((string) $entry['prefix'][0], (string) $entry['prefix'][1]);
            }
            $app->hooks()->setCurrentModule($id);
            $app->router()->setModule($id);
            foreach ((array) $entry['caps'] as $capability) {
                $app->hooks()->grant($id, (string) $capability);
            }
            try {
                if (!empty($entry['lang'])) {
                    $app->i18n()->addDir($path . '/lang');
                }
                if (!empty($entry['views'])) {
                    $app->view()->addNamespace($id, $path . '/views');
                }
                if (!empty($entry['hooks'])) {
                    $t0 = microtime(true);
                    $callable = require $path . '/hooks.php';
                    if (is_callable($callable)) {
                        $callable($app);
                    }
                    $this->timings[$id] = (microtime(true) - $t0) * 1000;
                }
                $this->applyRoutes($app, $path . '/routes.php', $entry['routes']);
                $this->loaded[] = $id;
            } catch (\Throwable $e) {
                $this->failed[$id] = get_class($e) . ': ' . $e->getMessage();
                $app->log()->exception($e, 'module:' . $id);
                if (!empty($manifest['essential'])) {
                    throw $e;
                }
            }
        }
        $app->hooks()->setCurrentModule('core');
        $app->router()->setModule('core');
    }

    /**
     * Ejecuta un routes.php grabando lo que da de alta. Devuelve sus rutas compiladas, o 'live' si alguna tiene una
     * función anónima como manejador (entonces ese routes.php se vuelve a ejecutar en cada petición).
     *
     * @return array<int,array<int,mixed>>|string
     */
    private function runRoutes(App $app, string $file)
    {
        $router = $app->router();
        $router->record();
        try {
            $callable = require $file;
            if (is_callable($callable)) {
                $callable($router, $app);
            }
        } finally {
            $routes = $router->recorded();
        }
        $rows = [];
        foreach ($routes as $route) {
            if (!$route->compilable()) {
                return 'live';
            }
            $rows[] = $route->export();
        }

        return $rows;
    }

    /**
     * Da de alta las rutas de un módulo con lo guardado: las compiladas sin ejecutar nada; las 'live', ejecutando su routes.php.
     *
     * @param mixed $routes lo que dio runRoutes() (o null si el módulo no tiene routes.php)
     */
    private function applyRoutes(App $app, string $file, $routes): void
    {
        if (is_array($routes)) {
            $app->router()->import($routes);
        } elseif ($routes === 'live') {
            $callable = require $file;
            if (is_callable($callable)) {
                $callable($app->router(), $app);
            }
        }
    }

    /**
     * Contenido de un fichero de definición (settings, admin, jobs, cli, blocks) de un módulo.
     *
     * @return array<string,mixed>
     */
    public function definition(string $id, string $name): array
    {
        $key = $id . '/' . $name;
        if (isset($this->files[$key])) {
            return $this->files[$key];
        }
        // Con la caché de arranque ya se sabe si el fichero existe: no se pregunta al disco.
        if ($this->known !== null && isset($this->known[$id]) && array_key_exists($name, $this->known[$id]) && !$this->known[$id][$name]) {
            $this->files[$key] = [];

            return [];
        }
        $path = $this->path($id);
        $file = $path !== null ? $path . '/' . $name . '.php' : '';
        $data = [];
        if ($file !== '' && is_file($file)) {
            $loaded = require $file;
            $data = is_array($loaded) ? $loaded : [];
        }
        $this->files[$key] = $data;

        return $data;
    }

    /**
     * Une un fichero de definición de todos los módulos cargados: id => contenido.
     *
     * @return array<string,array<string,mixed>>
     */
    public function definitions(string $name): array
    {
        $out = [];
        foreach (array_merge(['core'], $this->loaded) as $id) {
            $data = $this->definition($id, $name);
            if ($data !== []) {
                $out[$id] = $data;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    public function isLoaded(string $id): bool
    {
        return in_array($id, $this->loaded, true);
    }

    /**
     * @return array<string,string>
     */
    public function failed(): array
    {
        return $this->failed;
    }

    /**
     * Carpetas de migraciones de los módulos indicados: id => carpeta.
     *
     * @param string[] $ids
     *
     * @return array<string,string>
     */
    public function migrationDirs(array $ids): array
    {
        $dirs = [];
        foreach ($ids as $id) {
            $path = $this->path($id);
            if ($path !== null && is_dir($path . '/migrations')) {
                $dirs[$id] = $path . '/migrations';
            }
        }

        return $dirs;
    }

    /**
     * Saca los valores por defecto de la definición de ajustes de un módulo.
     *
     * La definición es: ['tabs' => ['clave' => ['label' => …, 'fields' => ['area.clave' => ['type' => …, 'default' => …]]]]]
     *
     * @param array<string,mixed> $definition
     *
     * @return array<string,mixed>
     */
    public static function settingDefaults(array $definition): array
    {
        $defaults = [];
        $tabs = isset($definition['tabs']) && is_array($definition['tabs']) ? $definition['tabs'] : [];
        foreach ($tabs as $tab) {
            $fields = is_array($tab) && isset($tab['fields']) && is_array($tab['fields']) ? $tab['fields'] : [];
            foreach ($fields as $key => $field) {
                if (is_array($field) && array_key_exists('default', $field)) {
                    $defaults[(string) $key] = $field['default'];
                }
            }
        }

        return $defaults;
    }
}
