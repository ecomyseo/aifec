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

use Aifec\Db\Db;
use Aifec\Db\Migrator;
use Aifec\Http\CurlHttpClient;
use Aifec\Http\HttpClient;
use Aifec\View\View;

/**
 * La aplicación de UN sitio: reúne los servicios, carga módulos y tema y convierte una petición en respuesta.
 *
 *   $app = new App($site, $root);
 *   $response = $app->handle($request);     // todo el sistema pasa por aquí, también en las pruebas
 *
 * Servicios propios: db(), settings(), cache(), log(), hooks(), router(), i18n(), view(), modules(),
 * slugs(), url(), auth(), clock(), http(). Los módulos añaden los suyos con bind('nombre', fábrica)
 * y los piden con service('nombre').
 */
final class App
{
    public const VERSION = '1.2.5';

    /** @var string|null la ruta propia del panel ya leída (null = sin leer) */
    private $adminAlias = null;

    /** @var App|null la aplicación que atiende la petición en curso (para las funciones de plantilla) */
    private static $current;

    /** @var Site */
    private $site;

    /** @var string raíz de la instalación, sin barra final */
    private $root;

    /** @var string carpeta pública (raíz web) */
    private $publicDir;

    /** @var string ruta web de la carpeta pública: «» o «/tienda» */
    private $publicBase;

    /** @var array<string,mixed> */
    private $options;

    /** @var array<string,object> servicios ya creados */
    private $services = [];

    /** @var array<string,callable> fábricas de servicios */
    private $factories = [];

    /** @var array<string,callable> */
    private $middleware = [];

    /** @var array<string,callable|array{0:string,1:string}> tipo de entidad => quien la pinta */
    private $entities = [];

    /** @var array<string,Session> */
    private $sessions = [];

    /** @var array<string,array{value:string,options:array<string,mixed>}> cookies que los servicios piden poner en esta respuesta */
    private $cookies = [];

    /** @var Request */
    private $request;

    /** @var bool */
    private $booted = false;

    /** @var array<string,float> milisegundos de cada tramo de la petición en curso */
    private $marks = [];

    /** @var float instante de la última marca */
    private $markAt = 0.0;

    /** @var string[] migraciones que aplicó el arranque de esta petición (casi siempre, ninguna) */
    private $bootApplied = [];

    /** @var string */
    private $lang = '';

    /** @var string[]|null */
    private $languages;

    /** @var array<string,mixed>|null tema activo (theme.json fundido con los de sus padres) */
    private $theme;

    /** @var array<string,array<string,mixed>>|null */
    private $admin;

    /** @var string|null */
    private $assetBuild;

    /**
     * @param array<string,mixed> $options clock (Clock), http (HttpClient), public_dir, public_base, log_files (bool),
     *                                     modules (string[]: solo existen esos módulos; para las pruebas),
     *                                     sapi (cómo se ejecuta PHP, para probar lo que solo pasa en una petición web)
     */
    public function __construct(Site $site, string $root, array $options = [])
    {
        $this->site = $site;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->options = $options;
        $this->publicDir = isset($options['public_dir']) ? rtrim(str_replace('\\', '/', (string) $options['public_dir']), '/') : $this->root . '/public';
        $this->publicBase = isset($options['public_base']) ? rtrim((string) $options['public_base'], '/') : '';
        $this->request = new Request('GET', '/');
        date_default_timezone_set('UTC');
    }

    public static function current(): self
    {
        if (self::$current === null) {
            throw new \LogicException('No application is handling a request');
        }

        return self::$current;
    }

    public static function hasCurrent(): bool
    {
        return self::$current !== null;
    }

    public static function clearCurrent(): void
    {
        self::$current = null;
    }

    /** Hace de esta la aplicación en curso (consola y pruebas que no pasan por handle()). */
    public function makeCurrent(): self
    {
        self::$current = $this;

        return $this;
    }

    // ------------------------------------------------------------------ datos básicos

    public function site(): Site
    {
        return $this->site;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function publicDir(): string
    {
        return $this->publicDir;
    }

    public function publicBase(): string
    {
        return $this->publicBase;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function debug(): bool
    {
        return $this->site->debug();
    }

    // ------------------------------------------------------------------ servicios

    /** Registra la fábrica de un servicio. Se crea la primera vez que alguien lo pide. */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->services[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || isset($this->factories[$id]);
    }

    /**
     * @return object
     */
    public function service(string $id)
    {
        if (!isset($this->services[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \LogicException('Unknown service: ' . $id);
            }
            $service = ($this->factories[$id])($this);
            if (!is_object($service)) {
                throw new \LogicException('Service factory must return an object: ' . $id);
            }
            $this->services[$id] = $service;
        }

        return $this->services[$id];
    }

    public function clock(): Clock
    {
        if (!isset($this->services['clock'])) {
            $this->services['clock'] = isset($this->options['clock']) && $this->options['clock'] instanceof Clock ? $this->options['clock'] : new Clock();
        }

        return $this->services['clock'];
    }

    public function db(): Db
    {
        if (!isset($this->services['db'])) {
            $config = (array) $this->site->get('db', []);
            if (!isset($config['driver']) || $config['driver'] === 'sqlite') {
                $config['driver'] = 'sqlite';
                if (!isset($config['path']) || $config['path'] === '') {
                    $config['path'] = $this->site->path('', true) . '/db.sqlite';
                }
            }
            $this->services['db'] = Db::connect($config);
        }

        return $this->services['db'];
    }

    public function settings(): Settings
    {
        if (!isset($this->services['settings'])) {
            // La base de datos se abre cuando hace falta de verdad: una página de la tienda lee los ajustes de su copia
            // en fichero; el panel, la consola y el cron leen la tabla (y reponen la copia si no coincide).
            $this->services['settings'] = new Settings(function (): Db {
                return $this->db();
            }, $this->site->path('cache') . '/settings.php', !$this->schemaCheckDue(true));
        }

        return $this->services['settings'];
    }

    /** Si esta petición ha abierto la base de datos (una página que sale de las cachés del arranque, no). */
    public function dbConnected(): bool
    {
        return isset($this->services['db']);
    }

    /** Valor de un ajuste secreto (clave de API, contraseña del correo), descifrado. Vacío si no está puesto. */
    public function secret(string $key): string
    {
        $stored = $this->settings()->str($key);
        if (strpos($stored, 'enc:') !== 0) {
            return $stored;
        }
        $plain = (new \Aifec\Security\Crypto($this->site->secret()))->decrypt(substr($stored, 4));

        return $plain !== null ? $plain : '';
    }

    /** Guarda un ajuste secreto cifrado con la clave del sitio. Una cadena vacía lo borra. */
    public function setSecret(string $key, string $plain): void
    {
        if ($plain === '') {
            $this->settings()->delete($key);

            return;
        }
        $this->settings()->set($key, 'enc:' . (new \Aifec\Security\Crypto($this->site->secret()))->encrypt($plain));
    }

    public function cache(): Cache
    {
        if (!isset($this->services['cache'])) {
            $this->services['cache'] = new Cache($this->site->path('cache'), $this->clock());
        }

        return $this->services['cache'];
    }

    public function log(): Logger
    {
        if (!isset($this->services['log'])) {
            $files = !isset($this->options['log_files']) || (bool) $this->options['log_files'];
            $this->services['log'] = new Logger($this->site->path('logs'), $this->clock(), $files);
        }

        return $this->services['log'];
    }

    public function hooks(): Hooks
    {
        if (!isset($this->services['hooks'])) {
            $hooks = new Hooks();
            $hooks->onError(function (string $module, string $hook, \Throwable $e): void {
                $this->log()->exception($e, 'hook ' . $hook . ' (' . $module . ')');
            });
            $this->services['hooks'] = $hooks;
        }

        return $this->services['hooks'];
    }

    public function router(): Router
    {
        if (!isset($this->services['router'])) {
            $this->services['router'] = new Router();
        }

        return $this->services['router'];
    }

    public function i18n(): I18n
    {
        if (!isset($this->services['i18n'])) {
            $i18n = new I18n($this->site->locale());
            $i18n->addDir($this->root . '/core/lang');
            $this->services['i18n'] = $i18n;
        }

        return $this->services['i18n'];
    }

    public function view(): View
    {
        if (!isset($this->services['view'])) {
            $view = new View();
            $view->addNamespace('core', $this->root . '/core/views');
            $this->services['view'] = $view;
        }

        return $this->services['view'];
    }

    public function modules(): ModuleManager
    {
        if (!isset($this->services['modules'])) {
            $this->services['modules'] = new ModuleManager($this->root);
            // «modules» (pruebas) o «modules_only» en la configuración del sitio (desarrollo): solo existen esos módulos.
            $only = isset($this->options['modules']) ? $this->options['modules'] : $this->site->get('modules_only');
            if (is_array($only)) {
                $this->services['modules']->only($only);
            }
        }

        return $this->services['modules'];
    }

    public function slugs(): Slugs
    {
        if (!isset($this->services['slugs'])) {
            $this->services['slugs'] = new Slugs($this->db());
        }

        return $this->services['slugs'];
    }

    public function url(): Urls
    {
        if (!isset($this->services['url'])) {
            $this->services['url'] = new Urls($this);
        }

        return $this->services['url'];
    }

    public function auth(): Auth
    {
        if (!isset($this->services['auth'])) {
            $this->services['auth'] = new Auth($this);
        }

        return $this->services['auth'];
    }

    public function http(): HttpClient
    {
        if (!isset($this->services['http'])) {
            $this->services['http'] = isset($this->options['http']) && $this->options['http'] instanceof HttpClient
                ? $this->options['http']
                : new CurlHttpClient('AIFEC/' . self::VERSION);
        }

        return $this->services['http'];
    }

    /** Sesión del panel («admin») o de la tienda («customer»). No se crea en la base hasta que se escribe en ella. */
    public function session(string $guard = 'customer'): Session
    {
        if (!isset($this->sessions[$guard])) {
            $name = 'af_' . ($guard === 'admin' ? 'a' : 'c') . '_' . $this->site->cookieSuffix();
            $ttl = $guard === 'admin' ? 43200 : 2592000;
            $session = new Session($this->db(), $this->clock(), $guard, $name, $ttl);
            $session->load($this->request->cookie($name));
            $this->sessions[$guard] = $session;
        }

        return $this->sessions[$guard];
    }

    /** @var bool mientras está puesto, el sistema no avisa a nadie (ver silence()) */
    private $silent = false;

    /**
     * Modo silencioso: mientras está puesto, el sistema no avisa a nadie (el correo ni se compone ni se guarda).
     * Lo usan las migraciones: lo importado es histórico y no puede provocar un solo mensaje.
     * Devuelve el valor anterior, para reponerlo en un «finally»:
     *
     *     $before = $app->silence(true);
     *     try { … } finally { $app->silence($before); }
     */
    public function silence(bool $on): bool
    {
        $previous = $this->silent;
        $this->silent = $on;

        return $previous;
    }

    public function silenced(): bool
    {
        return $this->silent;
    }

    /**
     * Pide poner una cookie del sitio en la respuesta de esta petición (la del carrito, la de una preferencia).
     * Un servicio no tiene la respuesta en la mano: la aplicación la pone al final. $days = 0 → hasta cerrar el navegador.
     * Una respuesta que pone cookies nunca se guarda en la caché de página.
     */
    public function setCookie(string $name, string $value, int $days = 30, bool $readable = false): void
    {
        $options = ['expires' => $days > 0 ? $this->clock()->now() + $days * 86400 : 0];
        if ($readable) {
            // Una MARCA para el JavaScript del tema (¿hay carrito?, ¿hay cuenta?). Nunca lleva un secreto: lo que identifica
            // a alguien va siempre en una cookie que el navegador no deja leer.
            $options['httponly'] = false;
        }
        $this->cookies[$name] = ['value' => $value, 'options' => $options];
    }

    /** Pide borrar una cookie del sitio. */
    public function forgetCookie(string $name): void
    {
        $this->cookies[$name] = ['value' => '', 'options' => ['expires' => 1]];
    }

    /** Nombre de una cookie del sitio: cookieName('k') → «af_k_3fa9c1». Cada sitio tiene las suyas. */
    public function cookieName(string $short): string
    {
        return 'af_' . $short . '_' . $this->site->cookieSuffix();
    }

    /** Ruta de las cookies de la tienda de este sitio. */
    public function cookiePath(): string
    {
        return $this->site->basePath !== '' ? $this->site->basePath . '/' : '/';
    }

    // ------------------------------------------------------------------ idiomas y tema

    /**
     * Idiomas activos de la tienda; el primero es el principal (sin prefijo en la dirección).
     *
     * @return string[]
     */
    public function languages(): array
    {
        if ($this->languages === null) {
            $langs = [];
            foreach ($this->settings()->arr('i18n.languages') as $lang) {
                if (is_string($lang) && preg_match('/^[a-z]{2}$/', $lang) === 1) {
                    $langs[] = $lang;
                }
            }
            $default = strtolower(substr($this->site->locale(), 0, 2));
            $this->languages = $langs !== [] ? array_values(array_unique($langs)) : [$default];
        }

        return $this->languages;
    }

    public function defaultLang(): string
    {
        return $this->languages()[0];
    }

    /** Idioma de la petición en curso. */
    public function lang(): string
    {
        return $this->lang !== '' ? $this->lang : $this->defaultLang();
    }

    public function setLang(string $lang): void
    {
        $this->lang = in_array($lang, $this->languages(), true) ? $lang : $this->defaultLang();
        $this->i18n()->setLocale($this->lang);
    }

    /**
     * Tema activo: su theme.json fundido con el de los temas de los que hereda.
     * Claves: slug, name, parent, chain (slugs, del activo al base), settings, menus, image_sizes…
     *
     * @return array<string,mixed>
     */
    public function theme(): array
    {
        if ($this->theme !== null) {
            return $this->theme;
        }
        $slug = $this->settings()->str('theme.active', 'base');
        $chain = [];
        $merged = [];
        $guard = 0;
        while ($slug !== '' && $guard++ < 6 && preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1) {
            $file = $this->root . '/themes/' . $slug . '/theme.json';
            if (!is_file($file) || in_array($slug, $chain, true)) {
                break;
            }
            $data = json_decode((string) file_get_contents($file), true);
            $data = is_array($data) ? $data : [];
            $chain[] = $slug;
            // Lo del hijo manda; lo que no declara, lo hereda.
            $merged = array_replace_recursive($data, $merged);
            $slug = isset($data['parent']) ? (string) $data['parent'] : ($slug === 'base' ? '' : 'base');
        }
        if ($chain === []) {
            $chain = ['base'];
        }
        $merged['slug'] = $chain[0];
        $merged['chain'] = $chain;
        $this->theme = $merged;

        return $this->theme;
    }

    /** Identificador de la versión de los recursos estáticos; cambia cuando cambia cualquier fichero publicado. */
    public function assetBuild(): string
    {
        if ($this->assetBuild === null) {
            $file = $this->publicDir . '/assets/build.txt';
            $build = is_file($file) ? trim((string) file_get_contents($file)) : '';
            $this->assetBuild = preg_match('/^[a-z0-9]{4,16}$/', $build) === 1 ? $build : 'dev';
        }

        return $this->assetBuild;
    }

    // ------------------------------------------------------------------ ampliación

    /**
     * Registra un intermediario con nombre: function (Request $r, App $app, callable $next): Response.
     */
    public function middleware(string $name, callable $fn): void
    {
        $this->middleware[$name] = $fn;
    }

    /**
     * Dice quién pinta un tipo de entidad con dirección amigable.
     * El manejador recibe (Request $request, array $params) con $params['id'] = identificador.
     *
     * @param callable|array{0:string,1:string} $handler
     */
    public function entity(string $type, $handler): void
    {
        $this->entities[$type] = $handler;
    }

    /**
     * Definiciones del panel de todos los módulos cargados (menú, permisos, recursos, piezas del inicio).
     * Solo se leen cuando alguien entra al panel.
     *
     * @return array<string,array<string,mixed>> módulo => contenido de su admin.php
     */
    public function adminDefinitions(): array
    {
        if ($this->admin === null) {
            $definitions = $this->modules()->definitions('admin');
            // Un módulo puede retocar el panel de otro: añadir una columna, un campo o una entrada de menú.
            $filtered = $this->hooks()->apply('admin.definitions', $definitions, $this);
            $this->admin = is_array($filtered) ? $filtered : $definitions;
        }

        return $this->admin;
    }

    /**
     * Invalida lo guardado en caché (datos y páginas) que lleve alguna de estas etiquetas.
     *
     * @param string[] $tags
     */
    public function invalidate(array $tags): void
    {
        $this->cache()->flushTags($tags);
    }

    // ------------------------------------------------------------------ arranque

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        self::$current = $this;

        $modules = $this->modules();
        $this->mark('manifests');
        $this->bootApplied = $this->migrate(false);
        $this->mark('schema');

        // Caché de arranque (<cache>/boot.php): lo que antes se sacaba del disco en cada visita (manifiestos, módulos
        // activos, ficheros de cada uno, valores por defecto, tema, rutas compiladas) sale de un solo require.
        $settings = $this->settings();
        $enabled = $settings->get('modules.active');
        $signature = $this->bootSignature();
        $bootFile = $this->site->path('cache') . '/boot.php';
        $cached = $this->readBootCache($bootFile, $signature, $this->schemaCheckDue(true));
        $this->mark('settings');

        if ($cached !== null) {
            $modules->seed($cached['manifests'], $cached['files']);
            $this->theme = $cached['theme'];
            $ids = (array) $cached['ids'];
            $themeFiles = (array) $cached['theme_files'];
        } else {
            $ids = $modules->activeIds(is_array($enabled) ? $enabled : null);
            $this->theme = null;
            $themeFiles = [];
            foreach ((array) $this->theme()['chain'] as $slug) {
                $base = $this->root . '/themes/' . $slug;
                $themeFiles[(string) $slug] = ['lang' => is_dir($base . '/lang'), 'functions' => is_file($base . '/functions.php')];
            }
        }
        $theme = $this->theme();
        $dirs = [];
        foreach ((array) $theme['chain'] as $slug) {
            $dirs[] = $this->root . '/themes/' . $slug . '/templates';
        }
        $this->view()->setThemeDirs($dirs);
        $this->mark('theme');

        if ($cached !== null) {
            $modules->bootCompiled($this, $cached);
            $build = (string) $cached['build'];
        } else {
            $compiled = $modules->boot($this, $ids);
            $build = '';
            // Una vista previa (ajustes cambiados solo en memoria) o un módulo que no cargó no se guardan.
            if ($compiled['ok'] && !$settings->overridden()) {
                $fingerprint = $modules->fingerprint();
                $build = md5($fingerprint . '|' . $signature);
                $this->writeBootCache($bootFile, [
                    'v' => self::BOOT_FORMAT,
                    'sig' => $signature,
                    'fp' => $fingerprint,
                    'build' => $build,
                    'manifests' => $modules->all(),
                    'files' => $modules->fileMap(),
                    'ids' => $ids,
                    'theme' => $theme,
                    'theme_files' => $themeFiles,
                    'core' => $compiled['core'],
                    'modules' => $compiled['modules'],
                    'defaults' => $compiled['defaults'],
                ]);
            }
        }
        $this->mark('modules');

        // El tema carga lo último: sus traducciones y su código mandan sobre los de los módulos.
        foreach (array_reverse((array) $theme['chain']) as $slug) {
            $base = $this->root . '/themes/' . $slug;
            $has = isset($themeFiles[$slug]) && is_array($themeFiles[$slug]) ? $themeFiles[$slug] : ['lang' => false, 'functions' => false];
            if (!empty($has['lang'])) {
                $this->i18n()->addDir($base . '/lang');
            }
            if (!empty($has['functions'])) {
                $this->hooks()->setCurrentModule('theme:' . $slug);
                try {
                    $fn = require $base . '/functions.php';
                    if (is_callable($fn)) {
                        $fn($this);
                    }
                } catch (\Throwable $e) {
                    $this->log()->exception($e, 'theme:' . $slug);
                }
                $this->hooks()->setCurrentModule('core');
            }
        }

        // Catálogos de idioma fundidos: t() hace un solo require por idioma en vez de abrir el de cada módulo.
        if ($build !== '') {
            $this->i18n()->useCompiled($this->site->path('cache'), $build, !$settings->overridden());
        }
        $this->i18n()->setLocale($this->lang());
        $this->hooks()->fire('app.boot', $this);
        $this->mark('ready');
    }

    /** Versión del formato de la caché de arranque: cambiarla deja inservibles las ya escritas. */
    private const BOOT_FORMAT = 1;

    /**
     * Firma de lo que la caché de arranque toma de los ajustes y de la configuración: módulos activos, permisos de los
     * plugins, tema, direcciones (las rutas compiladas las usan) y la lista recortada de módulos. Si cambia cualquiera
     * (activar un módulo, cambiar de tema, guardar «urls.cart»), la caché ya no vale y se rehace sola.
     */
    private function bootSignature(): string
    {
        $settings = $this->settings();
        $only = $this->modules()->onlyIds();

        return md5((string) json_encode([
            self::VERSION,
            self::BOOT_FORMAT,
            $only,
            $settings->get('modules.active'),
            $settings->get('plugins.granted'),
            $settings->get('theme.active'),
            $settings->all('urls.'),
            // La configuración del sitio (config.php): un módulo puede dar de alta rutas según lo que diga (el de sitios solo
            // publica /demos en el sitio maestro). Va su huella, no su contenido: lleva la clave secreta del sitio.
            md5((string) json_encode($this->site->config, JSON_PARTIAL_OUTPUT_ON_ERROR)),
            $this->site->basePath,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * La caché de arranque si existe, está entera y vale para estos ajustes. Con $verify (panel, consola, cron) además se
     * recalcula la huella del disco: si alguien ha subido, quitado o cambiado un módulo, un plugin o un tema, no vale.
     *
     * @return array<string,mixed>|null
     */
    private function readBootCache(string $file, string $signature, bool $verify): ?array
    {
        $data = Cache::import($file);
        if (!is_array($data) || !isset($data['v'], $data['sig'], $data['fp'], $data['build'], $data['manifests'], $data['files'], $data['ids'], $data['theme'], $data['theme_files'], $data['core'], $data['modules'])
            || $data['v'] !== self::BOOT_FORMAT || $data['sig'] !== $signature
            || !is_array($data['manifests']) || !is_array($data['files']) || !is_array($data['ids']) || !is_array($data['theme']) || !is_array($data['modules'])) {
            return null;
        }
        if ($verify && $this->modules()->fingerprint() !== $data['fp']) {
            return null;
        }

        return $data;
    }

    /**
     * Guarda la caché de arranque (solo si todo se puede escribir como datos: un valor por defecto que fuera un objeto
     * no volvería igual del fichero).
     *
     * @param array<string,mixed> $data
     */
    private function writeBootCache(string $file, array $data): void
    {
        if (!self::exportable($data)) {
            return;
        }
        $this->site->path('cache', true);
        Cache::export($file, $data);
    }

    /**
     * @param mixed $value
     */
    private static function exportable($value, int $depth = 0): bool
    {
        if ($value === null || is_scalar($value)) {
            return !is_float($value) || is_finite($value);
        }
        if (!is_array($value) || $depth > 40) {
            return false;
        }
        foreach ($value as $item) {
            if (!self::exportable($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /** Borra la caché de arranque y los catálogos fundidos de este sitio (se rehacen en la petición siguiente). */
    public function forgetBootCache(): void
    {
        $dir = $this->site->path('cache');
        foreach (array_merge([$dir . '/boot.php', $dir . '/settings.php'], (array) glob($dir . '/lang-*.php')) as $file) {
            if (is_string($file) && is_file($file)) {
                @unlink($file);
                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($file, true);
                }
            }
        }
    }

    /**
     * Apunta cuánto ha durado el tramo que acaba aquí (milisegundos desde la marca anterior). Sale en la cabecera
     * Server-Timing de la respuesta: es la forma de saber en qué se va el tiempo de una página sin herramientas.
     */
    private function mark(string $name): void
    {
        $now = microtime(true);
        if ($this->markAt > 0) {
            $this->marks[$name] = (isset($this->marks[$name]) ? $this->marks[$name] : 0.0) + ($now - $this->markAt) * 1000;
        }
        $this->markAt = $now;
    }

    /**
     * Las migraciones que aplicó el arranque de esta misma petición. La orden de consola `migrate` las cuenta como
     * suyas: si no, tras subir código nuevo diría «nada que migrar» justo después de haberlas aplicado al arrancar.
     *
     * @return string[]
     */
    public function bootMigrations(): array
    {
        return $this->bootApplied;
    }

    /**
     * Aplica las migraciones pendientes del núcleo, de los módulos incluidos y de los plugins activos.
     * La comprobación normal es una comparación de huellas guardada en la caché: no toca la base de datos.
     *
     * @return string[] migraciones aplicadas («ámbito/nombre»)
     */
    public function migrate(bool $force): array
    {
        $stamp = $this->site->path('cache') . '/schema.txt';
        if (!$force && !$this->schemaCheckDue(is_file($stamp))) {
            return [];
        }
        $modules = $this->modules();
        $scopes = ['core' => $this->root . '/core/migrations'];
        $ids = [];
        foreach ($modules->all() as $id => $manifest) {
            if ($manifest['kind'] === 'module') {
                $ids[] = (string) $id;
            }
        }
        $scopes += $modules->migrationDirs($ids);
        $fingerprint = Migrator::fingerprint($scopes) . ':' . self::VERSION;
        if (!$force && is_file($stamp) && trim((string) file_get_contents($stamp)) === $fingerprint) {
            return [];
        }
        $migrator = new Migrator($this->db());
        $applied = [];
        foreach ($scopes as $scope => $dir) {
            foreach ($migrator->run((string) $scope, $dir, $this->clock()->sql()) as $name) {
                $applied[] = $scope . '/' . $name;
            }
        }
        // Los plugins activos, después de que exista la tabla de ajustes.
        $enabled = $this->settings()->get('modules.active');
        if (is_array($enabled)) {
            $plugins = [];
            foreach ($enabled as $id) {
                $manifest = $modules->manifest((string) $id);
                if ($manifest !== null && $manifest['kind'] === 'plugin') {
                    $plugins[] = (string) $id;
                }
            }
            foreach ($modules->migrationDirs($plugins) as $scope => $dir) {
                foreach ($migrator->run((string) $scope, $dir, $this->clock()->sql()) as $name) {
                    $applied[] = $scope . '/' . $name;
                }
            }
        }
        $this->site->path('cache', true);
        @file_put_contents($stamp, $fingerprint, LOCK_EX);
        if ($applied !== []) {
            $this->settings()->reload();
        }

        return $applied;
    }

    /**
     * La ruta propia del panel (ajuste security.admin_path) o '' si el panel sigue en «/admin». Solo vale con la forma segura:
     * un valor mal escrito o reservado se ignora, así que un ajuste roto nunca deja el panel sin dirección.
     */
    public function adminAlias(): string
    {
        if ($this->adminAlias !== null) {
            return $this->adminAlias;
        }
        $value = '';
        try {
            $value = strtolower(trim($this->settings()->str('security.admin_path', '')));
        } catch (\Throwable $e) {
            // Los ajustes aún no están disponibles (arranque, instalación): no se da por buena la ausencia.
            return '';
        }
        $reserved = ['admin', 'api', 'assets', 'media', 'uploads', 'mcp', 'webmcp', 'cron', 'login', 'logout', 'carrito', 'cart', 'checkout', 'static', 'well-known'];
        $this->adminAlias = preg_match('/^[a-z0-9][a-z0-9-]{4,38}[a-z0-9]$/', $value) === 1 && !in_array($value, $reserved, true) ? $value : '';

        return $this->adminAlias;
    }

    /**
     * ¿Toca mirar si hay migraciones pendientes en este arranque?
     *
     * Mirarlo es recorrer la carpeta de migraciones de cada módulo: en el servidor costaba 10 ms por visita. Una
     * página de la tienda no lo hace si el esquema ya se comprobó alguna vez (existe la marca): lo comprueban el
     * panel, la consola y el cron de cada minuto, que es por donde entra cualquier cambio de código o de módulos.
     *
     * @param bool $stamped si existe la marca de «esquema al día» de este sitio
     */
    public function schemaCheckDue(bool $stamped): bool
    {
        if (!$stamped) {
            return true;
        }
        $sapi = isset($this->options['sapi']) && is_string($this->options['sapi']) ? $this->options['sapi'] : PHP_SAPI;
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            return true;
        }
        $path = $this->request->path;

        return $path === '/admin' || strpos($path, '/admin/') === 0;
    }

    // ------------------------------------------------------------------ petición → respuesta

    public function handle(Request $request): Response
    {
        $t0 = microtime(true);
        self::$current = $this;
        $this->request = $request;
        $this->sessions = [];
        $this->cookies = [];
        $this->lang = '';
        $this->adminAlias = null;
        // Una misma aplicación puede atender varias peticiones seguidas (pruebas, tareas): nada de la anterior se hereda.
        unset($this->services['auth']);
        if (isset($this->services['view'])) {
            $this->services['view']->resetStacks();
        }

        $this->marks = [];
        $this->markAt = $t0;
        try {
            $this->boot();
            $response = $this->dispatch($request);
            $this->mark('action');
        } catch (HttpError $e) {
            $response = $this->errorResponse($e->status(), $e->getMessage());
        } catch (\Throwable $e) {
            try {
                $this->log()->exception($e, $request->method . ' ' . $request->path);
            } catch (\Throwable $ignored) {
                // Si ni siquiera se puede apuntar, se sigue con la página de error.
            }
            $response = $this->errorResponse(500, $this->debug() ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : '');
        }

        return $this->finish($request, $response, $t0);
    }

    private function dispatch(Request $request): Response
    {
        // Panel con ruta propia (ajuste security.admin_path): «/<ruta>/…» se atiende como «/admin/…» y el «/admin» de siempre
        // deja de existir. Todos los enlaces del panel salen ya con la ruta nueva (Urls::to).
        $alias = $this->adminAlias();
        if ($alias !== '') {
            $own = '/' . $alias;
            if ($request->path === $own || strpos($request->path, $own . '/') === 0) {
                $request->path = '/admin' . substr($request->path, strlen($own));
            } elseif ($request->path === '/admin' || strpos($request->path, '/admin/') === 0) {
                throw HttpError::notFound();
            }
        }

        // Idioma: el principal no lleva prefijo; los demás van como primer tramo de la ruta.
        $path = $request->path;
        $this->lang = $this->defaultLang();
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $path, $m) === 1 && $m[1] !== $this->defaultLang() && in_array($m[1], $this->languages(), true)) {
            $this->lang = $m[1];
            $path = isset($m[2]) && $m[2] !== '' ? $m[2] : '/';
            $request->path = $path;
        }
        $request->lang = $this->lang;
        $this->i18n()->setLocale($this->lang);

        // Detrás de un proxy de confianza (ajuste security.trusted_proxies) la dirección de la petición pasa a ser la del
        // visitante que dice el proxy, antes de que nadie la lea (límites de intentos, registro, asistente). De serie, vacío.
        $trusted = Request::parseProxyList($this->settings()->str('security.trusted_proxies', ''));
        if ($trusted !== []) {
            $request->trustProxies($trusted, $this->settings()->str('security.proxy_header', 'x-forwarded-for'));
        }

        // Una dirección de la tienda con mayúsculas («/Categoria/Hogar») es la misma página que en minúsculas: se redirige con 301
        // para no tener dos direcciones de la misma página (lo vio la visita de SEO del 13/11/2026). No se tocan el panel, la API,
        // los ficheros ni nada con un punto en el último tramo (un fichero puede llevar mayúsculas).
        // Solo las direcciones amigables de una entidad (categoría, producto, página…): un número de pedido o una clave pueden llevar
        // mayúsculas de verdad («/mi-cuenta/pedidos/T-001»).
        if (($request->method === 'GET' || $request->method === 'HEAD') && preg_match('/[A-Z]/', $request->rawPath) === 1
            && preg_match('#^/(?:admin|api|assets|media|uploads|mcp|webmcp|cron|\.well-known)(?:/|$)#i', $path) !== 1
            && strpos((string) basename($request->rawPath), '.') === false
            && ($lowerClean = Slugs::clean(strtolower($path))) !== ''
            && $this->slugs()->resolve($this->lang, $lowerClean) !== null) {
            $lower = $this->site->basePath . strtolower($request->rawPath);
            $qs = $request->query !== [] ? '?' . http_build_query($request->query) : '';

            return Response::redirect($lower . $qs, 301);
        }

        $early = $this->hooks()->apply('request.start', null, $request, $this);
        if ($early instanceof Response) {
            return $early;
        }

        $match = $this->router()->match($request->method, $path);
        $this->mark('route');
        if ($match !== null) {
            $route = $match['route'];
            $request->setAttr('route', $route->name);
            $request->setAttr('module', $route->module);
            $params = $match['params'];
            $core = function (Request $r) use ($route, $params): Response {
                return $this->call($route->handler, $r, $params);
            };
            foreach (array_reverse($route->middleware) as $name) {
                if (!isset($this->middleware[$name])) {
                    throw new \LogicException('Unknown middleware: ' . $name);
                }
                $mw = $this->middleware[$name];
                $next = $core;
                $core = function (Request $r) use ($mw, $next): Response {
                    return $mw($r, $this, $next);
                };
            }

            return $core($request);
        }

        // Dirección amigable de una entidad.
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            $clean = Slugs::clean($path);
            $hit = $clean !== '' ? $this->slugs()->resolve($this->lang, $clean) : null;
            if ($hit !== null && isset($this->entities[$hit['type']])) {
                $wantsSlash = $this->url()->trailingSlash() && strpos(basename($hit['path']), '.') === false;
                $hasSlash = substr($path, -1) === '/';
                if (!$hit['canonical'] || $clean !== $hit['path'] || $wantsSlash !== $hasSlash) {
                    $target = $this->url()->entity($hit['type'], $hit['entity_id'], $this->lang);
                    $qs = $request->query !== [] ? '?' . http_build_query($request->query) : '';

                    return Response::redirect($target . $qs, 301);
                }
                $request->setAttr('entity', $hit['type']);

                return $this->call($this->entities[$hit['type']], $request, ['id' => $hit['entity_id'], 'type' => $hit['type']]);
            }
            // En otro idioma, la dirección del idioma principal de algo que aún no tiene la suya: se sirve en el idioma de la
            // visita (lo que no esté traducido sale en el principal). Si ya tiene la suya, se lleva a ella.
            if ($hit === null && $clean !== '' && $this->lang !== $this->defaultLang()) {
                $main = $this->slugs()->resolve($this->defaultLang(), $clean);
                if ($main !== null && isset($this->entities[$main['type']])) {
                    $own = $this->slugs()->path($main['type'], (int) $main['entity_id'], $this->lang);
                    if ($own !== null || !$main['canonical'] || $clean !== $main['path']) {
                        $qs = $request->query !== [] ? '?' . http_build_query($request->query) : '';

                        return Response::redirect($this->url()->entity($main['type'], (int) $main['entity_id'], $this->lang) . $qs, 301);
                    }
                    $request->setAttr('entity', $main['type']);

                    return $this->call($this->entities[$main['type']], $request, ['id' => $main['entity_id'], 'type' => $main['type']]);
                }
            }
        }

        // Última oportunidad para los módulos (redirecciones manuales, direcciones antiguas).
        $fallback = $this->hooks()->apply('request.not_found', null, $request, $this);
        if ($fallback instanceof Response) {
            return $fallback;
        }

        throw HttpError::notFound();
    }

    /**
     * @param callable|array{0:string,1:string} $handler
     * @param array<string,mixed>               $params
     */
    private function call($handler, Request $request, array $params): Response
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $class = $handler[0];
            $controller = new $class($this);
            $result = $controller->{$handler[1]}($request, $params);
        } else {
            $result = $handler($request, $this, $params);
        }
        if ($result instanceof Response) {
            return $result;
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        throw new \LogicException('A controller must return a Response');
    }

    /**
     * Pinta una plantilla de la tienda y la devuelve como respuesta.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view()->render($template, $data), $status);
    }

    /** Página de error: JSON si la petición lo espera, plantilla del tema si existe, y si no, texto sencillo. */
    public function errorResponse(int $status, string $message = ''): Response
    {
        $titles = [400 => 'Bad request', 403 => 'Forbidden', 404 => 'Page not found', 405 => 'Method not allowed', 429 => 'Too many requests', 500 => 'Server error', 503 => 'Service unavailable'];
        $title = isset($titles[$status]) ? $titles[$status] : 'Error';
        if ($this->request->wantsJson() || strpos($this->request->path, '/api/') === 0) {
            return Response::json(['error' => ['status' => $status, 'message' => $message !== '' ? $message : $title]], $status);
        }
        $isAdmin = strpos($this->request->path, '/admin') === 0;
        foreach ($isAdmin ? ['core::admin/error', 'core::error'] : ['error', 'core::error'] as $template) {
            try {
                if ($this->view()->exists($template)) {
                    $html = $this->view()->render($template, ['status' => $status, 'title' => $title, 'message' => $message]);

                    return Response::html($html, $status);
                }
            } catch (\Throwable $e) {
                // La página de error no puede fallar: se cae a la siguiente opción.
                continue;
            }
        }
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return Response::html('<!doctype html><meta charset="utf-8"><title>' . $status . ' ' . $title . '</title><h1>' . $status . ' ' . $title . '</h1><p>' . $safe . '</p>', $status);
    }

    /** Segundos que vive la marca «nc» si nadie consume lo pendiente (el visitante se fue a otra página). */
    private const NO_CACHE_TTL = 600;

    /**
     * Apunta que la sesión de cliente guarda, en esta clave, algo que la PÁGINA SIGUIENTE tiene que pintar en el servidor
     * (los errores de un formulario enviado sin JavaScript). Mientras la clave exista, el visitante lleva la marca «nc»
     * y no recibe la copia de la caché de página; quien la lee la borra de la sesión y la marca se quita sola.
     * Los avisos de un solo uso (Session::flash) ya cuentan sin apuntarlos.
     */
    public function pendingForNextPage(string $key): void
    {
        $session = $this->session('customer');
        $keys = $session->get('_next', []);
        $keys = is_array($keys) ? $keys : [];
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $session->set('_next', $keys);
        }
    }

    /** ¿Guarda la sesión algo para la página siguiente? (avisos de un solo uso o claves apuntadas con pendingForNextPage()) */
    private static function pendingInSession(Session $session): bool
    {
        $flash = $session->get('_flash', []);
        if (is_array($flash) && $flash !== []) {
            return true;
        }
        $keys = $session->get('_next', []);
        if (!is_array($keys) || $keys === []) {
            return false;
        }
        $left = [];
        foreach ($keys as $key) {
            if (is_string($key) && $session->get($key) !== null) {
                $left[] = $key;
            }
        }
        if ($left !== $keys) {
            if ($left === []) {
                $session->forget('_next');
            } else {
                $session->set('_next', $left);
            }
        }

        return $left !== [];
    }

    private function finish(Request $request, Response $response, float $t0): Response
    {
        try {
            $secure = $request->secure;
            foreach ($this->sessions as $guard => $session) {
                $path = $guard === 'admin' ? $this->site->basePath . '/' . ($this->adminAlias() !== '' ? $this->adminAlias() : 'admin') : $this->cookiePath();
                // Lo pendiente se mira antes de guardar: al mirarlo se limpia la lista de claves ya consumidas.
                $pending = $guard === 'customer' && $session->isStarted() && self::pendingInSession($session);
                $session->commit($response, $path, $secure, $guard === 'admin' ? 'Strict' : 'Lax');
                if ($guard === 'customer') {
                    // La marca de «hay una cuenta abierta» que el tema puede leer (la cookie de sesión no se deja leer).
                    $mark = $this->cookieName('u');
                    $logged = $session->userId() !== null;
                    $has = $request->cookie($mark) !== null;
                    if ($logged && !$has) {
                        $this->setCookie($mark, '1', 30, true);
                    } elseif (!$logged && $has) {
                        $this->forgetCookie($mark);
                    }
                    // La marca «nc» (sin caché): mientras la sesión guarde algo que la página siguiente pinta en el servidor,
                    // ese visitante no recibe la copia de la caché de página. Se quita en cuanto alguien lo consume.
                    $noCache = $this->cookieName('nc');
                    $hasNc = $request->cookie($noCache) !== null;
                    if ($pending && !$hasNc) {
                        $this->cookies[$noCache] = ['value' => '1', 'options' => ['expires' => $this->clock()->now() + self::NO_CACHE_TTL, 'httponly' => false]];
                    } elseif (!$pending && $hasNc) {
                        $this->forgetCookie($noCache);
                    }
                }
            }
            foreach ($this->cookies as $name => $cookie) {
                $response->cookie($name, $cookie['value'], $cookie['options'] + ['path' => $this->cookiePath(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
            }
            if (strpos($response->contentType(), 'text/html') === 0) {
                $response->headers += [
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Frame-Options' => 'SAMEORIGIN',
                    'Referrer-Policy' => 'strict-origin-when-cross-origin',
                    'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
                ];
                if ($secure) {
                    $response->headers += ['Strict-Transport-Security' => 'max-age=31536000'];
                }
                if ($this->booted && !isset($response->headers['Content-Security-Policy'])) {
                    // La política de contenido: qué puede cargar y ejecutar la página. Se guarda con la página en la caché.
                    $policy = Csp::build($this, $request);
                    if ($policy !== '') {
                        $response->headers['Content-Security-Policy'] = $policy;
                    }
                }
            }
            if ($this->booted) {
                $filtered = $this->hooks()->apply('response', $response, $request, $this);
                if ($filtered instanceof Response) {
                    $response = $filtered;
                }
                $this->hooks()->fire('request.end', $request, $response, $this);
                $started = false;
                foreach ($this->sessions as $session) {
                    $started = $started || $session->isStarted();
                }
                if ($response->cacheTtl > 0) {
                    $response->headers['X-Cache'] = PageCache::store($this, $request, $response, $started) ? 'MISS' : 'BYPASS';
                }
            }
        } catch (\Throwable $e) {
            try {
                $this->log()->exception($e, 'finish');
            } catch (\Throwable $ignored) {
                // Nada más que hacer.
            }
        }
        $this->mark('finish');
        $ms = (microtime(true) - $t0) * 1000;
        $queries = isset($this->services['db']) ? $this->db()->queries : 0;
        $timing = 'app;dur=' . number_format($ms, 1, '.', '') . ', db;desc="' . $queries . ' q"';
        foreach ($this->marks as $name => $spent) {
            $timing .= ', ' . $name . ';dur=' . number_format($spent, 1, '.', '');
        }
        $response->headers['Server-Timing'] = $timing;

        return $response;
    }
}
