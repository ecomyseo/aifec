<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Install;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Kernel\Site;
use Aifec\Kernel\SiteResolver;
use Aifec\Security\Passwords;
use Aifec\Security\Random;

/**
 * La única forma de crear y borrar un sitio: la usan el instalador web, la consola y, más adelante, el panel
 * de demostraciones.
 *
 * Crear es «todo o nada»: primero se valida todo y se comprueba la conexión (sin escribir nada); después se
 * escribe la carpeta del sitio, su base de datos y su persona administradora, y solo al final se apunta el
 * sitio en sites.php. Si algo falla por el camino se deshace lo escrito: ni carpeta, ni tablas nuevas, ni entrada.
 */
final class SiteFactory
{
    /** Inicio del mensaje de un fallo de conexión; el resto es el detalle del servidor. */
    public const CONNECT_ERROR = 'Could not connect to the database: ';

    public const HOST_PATTERN = '/^[A-Za-z0-9._-]{1,255}$/';

    public const DB_NAME_PATTERN = '/^[A-Za-z0-9_$-]{1,64}$/';

    public const PREFIX_PATTERN = '/^[a-z][a-z0-9_]{0,19}$/';

    private const PASSWORD_MIN = 10;

    /**
     * Crea un sitio completo.
     *
     * @param array<string,mixed> $options id ('main'), host ('*'), path (''), url, locale ('es'), timezone ('Europe/Madrid'),
     *                                     db (['driver' => 'sqlite'] o mysql con host, port, name, user, password, prefix),
     *                                     demo, debug, master (el primer sitio que no es demo lo es sin pedirlo),
     *                                     shop_name, shop_email, admin (name, email, password), modules (lista o null),
     *                                     settings (clave => valor), public_dir (carpeta pública, para proteger la de datos)
     *
     * @throws \InvalidArgumentException si algo de lo pedido no vale (no se ha escrito nada)
     * @throws \RuntimeException         si falla a mitad (se ha deshecho todo)
     *
     * @return array{id:string,url:string,admin_url:string,admin_email:string}
     */
    public static function create(string $root, string $dataRoot, array $options): array
    {
        $root = self::norm($root);
        $dataRoot = self::norm($dataRoot);
        SiteResolver::forget();
        $o = self::prepare($root, $dataRoot, $options);
        $tablesBefore = self::assertDatabase($o['db']);

        $dir = $dataRoot . '/' . $o['id'];
        $sitesFile = $dataRoot . '/sites.php';
        $undo = [
            'dir' => $dir, 'made_dir' => false,
            'data_root' => $dataRoot, 'made_data_root' => !is_dir($dataRoot), 'files' => [],
            'sites_file' => $sitesFile, 'sites_existed' => is_file($sitesFile), 'sites_before' => SiteResolver::all($dataRoot), 'registered' => false,
            'db' => $o['db'], 'tables_before' => $tablesBefore,
        ];
        $previous = App::hasCurrent() ? App::current() : null;
        $app = null;

        try {
            if (!is_dir($dataRoot) && !mkdir($dataRoot, 0775, true) && !is_dir($dataRoot)) {
                throw new \RuntimeException('Could not create the data folder');
            }
            $undo['files'] = self::protectDataRoot($dataRoot, $o['public_dir']);
            if (!mkdir($dir, 0775, true)) {
                throw new \RuntimeException('Could not create the site folder');
            }
            $undo['made_dir'] = true;
            self::writeConfig($dir, $o['config']);

            $site = new Site($o['id'], $dir, $o['config'], $o['path'], $o['host'] === '*' ? '' : $o['host']);
            $app = new App($site, $root, ['public_dir' => $o['public_dir'] !== '' ? $o['public_dir'] : $root . '/public']);
            $app->makeCurrent();
            // Primero las tablas y los ajustes, después el arranque: así los módulos que se encienden son los elegidos.
            $app->migrate(true);
            $app->settings()->set('store.name', $o['shop_name']);
            $app->settings()->set('store.email', $o['shop_email']);
            if ($o['modules'] !== null) {
                $app->settings()->set('modules.active', $o['modules']);
            }
            foreach ($o['settings'] as $key => $value) {
                $app->settings()->set((string) $key, $value);
            }
            $now = $app->clock()->sql();
            $app->db()->insert('users', [
                'email' => $o['admin']['email'], 'name' => $o['admin']['name'], 'password' => Passwords::hash($o['admin']['password']),
                'role' => 'admin', 'locale' => substr($o['locale'], 0, 2), 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $app->boot();

            $rows = $undo['sites_before'];
            $rows[] = ['id' => $o['id'], 'host' => $o['host'], 'path' => $o['path']];
            $undo['registered'] = true;
            SiteResolver::save($dataRoot, $rows);
        } catch (\Throwable $e) {
            $app = null;
            self::restoreCurrent($previous);
            self::rollback($undo);
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \RuntimeException('Could not create the site: ' . $e->getMessage(), 0, $e);
        }

        $app = null;
        self::restoreCurrent($previous);

        return ['id' => $o['id'], 'url' => $o['url'], 'admin_url' => $o['url'] . '/admin', 'admin_email' => $o['admin']['email']];
    }

    /**
     * Borra un sitio: lo quita de sites.php y borra su carpeta (con la base SQLite dentro). Con $dropTables también
     * borra sus tablas en MySQL/MariaDB (solo las de su prefijo).
     *
     * @throws \InvalidArgumentException si el sitio no existe
     * @throws \RuntimeException         si no se ha podido borrar (si se pedían las tablas, antes de tocar nada más)
     */
    public static function delete(string $dataRoot, string $id, bool $dropTables = false): void
    {
        $dataRoot = self::norm($dataRoot);
        SiteResolver::forget();
        if (!SiteResolver::validId($id)) {
            throw new \InvalidArgumentException('Invalid site id');
        }
        $found = false;
        $rest = [];
        foreach (SiteResolver::all($dataRoot) as $row) {
            if ($row['id'] === $id) {
                $found = true;
            } else {
                $rest[] = $row;
            }
        }
        $dir = $dataRoot . '/' . $id;
        if (!$found && !is_dir($dir)) {
            throw new \InvalidArgumentException('Unknown site');
        }
        if ($dropTables) {
            self::dropTables($dataRoot, $id, $rest);
        }
        if (App::hasCurrent() && App::current()->site()->id === $id && App::current()->site()->dataDir === $dir) {
            App::clearCurrent();
        }
        if ($found) {
            SiteResolver::save($dataRoot, $rest);
        }
        self::removeTree($dir, $dataRoot);
        if (is_dir($dir)) {
            throw new \RuntimeException('Could not remove the site folder');
        }
    }

    /**
     * Comprueba que se puede usar esa base de datos: que el controlador está, que conecta y, en MySQL/MariaDB,
     * que el prefijo no tiene ya tablas de AIFEC. No escribe nada.
     *
     * @param array<string,mixed> $db driver (sqlite|mysql) y, para mysql, host, port, name, user, password, prefix
     *
     * @throws \InvalidArgumentException
     */
    public static function checkDatabase(array $db): void
    {
        self::assertDatabase($db);
    }

    /**
     * Protege la carpeta de datos si cuelga de la pública: .htaccess que lo niega todo e index.html vacío.
     * No pisa lo que ya haya.
     *
     * @return string[] ficheros que se han creado
     */
    public static function protectDataRoot(string $dataRoot, string $publicDir): array
    {
        $dataRoot = self::norm($dataRoot);
        $publicDir = self::norm($publicDir);
        if ($publicDir === '' || !is_dir($dataRoot)) {
            return [];
        }
        $a = realpath($dataRoot);
        $b = realpath($publicDir);
        $a = $a !== false ? self::norm($a) : $dataRoot;
        $b = $b !== false ? self::norm($b) : $publicDir;
        if ($a !== $b && strpos($a . '/', $b . '/') !== 0) {
            return [];
        }
        $made = [];
        $files = [
            '.htaccess' => "# AIFEC: nada de esta carpeta se sirve por la web.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.html' => '',
        ];
        foreach ($files as $name => $content) {
            if (!is_file($dataRoot . '/' . $name) && file_put_contents($dataRoot . '/' . $name, $content) !== false) {
                $made[] = $dataRoot . '/' . $name;
            }
        }

        return $made;
    }

    /**
     * Identificador del sitio maestro de la instalación (su config.php lleva 'master' => true), o null si no hay.
     *
     * @param string $except un sitio que no se mira (el que se está cambiando)
     */
    public static function masterId(string $dataRoot, string $except = ''): ?string
    {
        foreach (SiteResolver::all($dataRoot) as $row) {
            $id = (string) $row['id'];
            if ($id === $except) {
                continue;
            }
            $site = SiteResolver::load($dataRoot, $id);
            if ($site !== null && $site->get('master', false)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Escribe config.php de un sitio de forma atómica.
     *
     * @param array<string,mixed> $config
     */
    public static function writeConfig(string $dir, array $config): void
    {
        $dir = self::norm($dir);
        $code = "<?php\n// AIFEC: configuración de este sitio. Lo escribe el sistema; contiene claves, no se publica.\nreturn " . var_export($config, true) . ";\n";
        $tmp = $dir . '/config.php.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $code, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write config.php');
        }
        // Un sistema de ficheros sin permisos Unix no cambia nada y no pasa nada: el resto de protecciones siguen.
        @chmod($tmp, 0640);
        if (!rename($tmp, $dir . '/config.php')) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write config.php');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($dir . '/config.php', true);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function cleanUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            throw new \InvalidArgumentException('Invalid URL: it must start with http:// or https://');
        }

        return $url;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function cleanLocale(string $locale): string
    {
        if (preg_match('/^[a-z]{2}([_-][A-Za-z]{2})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException('Invalid language code');
        }

        return $locale;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function cleanTimezone(string $timezone): string
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid time zone');
        }

        return $timezone;
    }

    // ------------------------------------------------------------------ validación

    /**
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private static function prepare(string $root, string $dataRoot, array $options): array
    {
        $id = self::text($options, 'id', 'main');
        if (!SiteResolver::validId($id)) {
            throw new \InvalidArgumentException('Invalid site id: use lowercase letters, digits and hyphens');
        }

        $host = strtolower(self::text($options, 'host', '*'));
        if ($host !== '*' && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            throw new \InvalidArgumentException('Invalid host');
        }
        $path = self::text($options, 'path', '');
        if ($path !== '' && preg_match('#^/[a-z0-9-]+(/[a-z0-9-]+)*$#', $path) !== 1) {
            throw new \InvalidArgumentException('Invalid path: it must start with a slash, have no trailing slash and use only a-z, 0-9 and hyphens');
        }

        foreach (SiteResolver::all($dataRoot) as $row) {
            if ($row['id'] === $id) {
                throw new \InvalidArgumentException('A site with that id already exists');
            }
            if ($row['host'] === $host && $row['path'] === $path) {
                throw new \InvalidArgumentException('Another site already uses that host and path');
            }
        }
        if (file_exists($dataRoot . '/' . $id)) {
            throw new \InvalidArgumentException('A folder with that site id already exists in the data folder');
        }

        $urlText = self::text($options, 'url', '');
        $url = $urlText !== '' ? self::cleanUrl($urlText) : 'http://' . ($host === '*' ? 'localhost' : $host) . $path;
        $locale = self::cleanLocale(self::text($options, 'locale', 'es'));
        $timezone = self::cleanTimezone(self::text($options, 'timezone', 'Europe/Madrid'));
        $db = self::cleanDb(isset($options['db']) && is_array($options['db']) ? $options['db'] : ['driver' => 'sqlite']);

        $shopName = self::text($options, 'shop_name', 'My store');
        if ($shopName === '' || mb_strlen($shopName) > 120) {
            throw new \InvalidArgumentException('The shop name is required (120 characters at most)');
        }
        $shopEmail = strtolower(self::text($options, 'shop_email', ''));
        if ($shopEmail !== '' && filter_var($shopEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid shop email');
        }

        $admin = isset($options['admin']) && is_array($options['admin']) ? $options['admin'] : [];
        $adminName = self::text($admin, 'name', '');
        if ($adminName === '' || mb_strlen($adminName) > 120) {
            throw new \InvalidArgumentException('The administrator name is required (120 characters at most)');
        }
        $adminEmail = strtolower(self::text($admin, 'email', ''));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false || strlen($adminEmail) > 191) {
            throw new \InvalidArgumentException('Invalid administrator email');
        }
        $password = isset($admin['password']) && is_string($admin['password']) ? $admin['password'] : '';
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            throw new \InvalidArgumentException('The administrator password needs at least ' . self::PASSWORD_MIN . ' characters');
        }
        if (strlen($password) > 4096) {
            throw new \InvalidArgumentException('The administrator password is too long');
        }

        $modules = null;
        if (isset($options['modules']) && $options['modules'] !== null) {
            if (!is_array($options['modules'])) {
                throw new \InvalidArgumentException('The modules must be a list');
            }
            $modules = [];
            foreach ($options['modules'] as $slug) {
                $slug = is_string($slug) ? trim($slug) : '';
                if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1 || (!is_file($root . '/modules/' . $slug . '/module.json') && !is_file($root . '/plugins/' . $slug . '/module.json') && !is_file($root . '/plugins/' . $slug . '/plugin.json'))) {
                    throw new \InvalidArgumentException('Unknown module: ' . (is_string($slug) ? $slug : ''));
                }
                $modules[] = $slug;
            }
            $modules = array_values(array_unique($modules));
        }

        $settings = [];
        if (isset($options['settings'])) {
            if (!is_array($options['settings'])) {
                throw new \InvalidArgumentException('The settings must be a list of key => value');
            }
            foreach ($options['settings'] as $key => $value) {
                if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,190}$/', $key) !== 1) {
                    throw new \InvalidArgumentException('Invalid setting key');
                }
                $settings[$key] = $value;
            }
        }

        $config = ['url' => $urlText !== '' ? $url : null, 'secret' => Random::token(32), 'locale' => $locale, 'timezone' => $timezone,
            'debug' => !empty($options['debug']), 'demo' => !empty($options['demo']), 'db' => $db];
        if ($config['url'] === null) {
            unset($config['url']);
        }
        // El sitio maestro es el que puede crear otros sitios y demostraciones. Lo es el primero de la instalación
        // (nunca una demostración); los demás solo si se pide y no hay ya otro.
        $current = self::masterId($dataRoot);
        if (!empty($options['master'])) {
            if ($config['demo']) {
                throw new \InvalidArgumentException('A demo site cannot be the master site');
            }
            if ($current !== null) {
                throw new \InvalidArgumentException('The master site is already "' . $current . '"');
            }
            $config['master'] = true;
        } elseif (!$config['demo'] && SiteResolver::all($dataRoot) === []) {
            $config['master'] = true;
        }

        return [
            'id' => $id, 'host' => $host, 'path' => $path, 'url' => $url, 'locale' => $locale, 'db' => $db, 'config' => $config,
            'shop_name' => $shopName, 'shop_email' => $shopEmail, 'admin' => ['name' => $adminName, 'email' => $adminEmail, 'password' => $password],
            'modules' => $modules, 'settings' => $settings,
            'public_dir' => isset($options['public_dir']) && is_string($options['public_dir']) ? self::norm($options['public_dir']) : '',
        ];
    }

    /**
     * @param array<string,mixed> $db
     *
     * @return array<string,mixed> lo que va en config.php (en SQLite, sin ruta: es siempre <sitio>/db.sqlite)
     */
    private static function cleanDb(array $db): array
    {
        $driver = self::text($db, 'driver', 'sqlite');
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'prefix' => 'af_'];
        }
        if ($driver !== 'mysql') {
            throw new \InvalidArgumentException('Unsupported database type: choose sqlite or mysql');
        }
        $host = self::text($db, 'host', 'localhost');
        $name = self::text($db, 'name', '');
        $user = self::text($db, 'user', '');
        $prefix = self::text($db, 'prefix', 'af_');
        $port = isset($db['port']) && is_numeric($db['port']) ? (int) $db['port'] : 3306;
        if (preg_match(self::HOST_PATTERN, $host) !== 1) {
            throw new \InvalidArgumentException('Invalid database server');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid database port');
        }
        if (preg_match(self::DB_NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('Invalid database name');
        }
        if ($user === '' || strlen($user) > 80) {
            throw new \InvalidArgumentException('The database user is required');
        }
        if (preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid table prefix: use lowercase letters, digits and underscores, starting with a letter');
        }

        return ['driver' => 'mysql', 'host' => $host, 'port' => $port, 'name' => $name, 'user' => $user,
            'password' => isset($db['password']) && is_string($db['password']) ? $db['password'] : '', 'prefix' => $prefix];
    }

    /**
     * @param array<string,mixed> $db ya limpia (o sin limpiar: se limpia)
     *
     * @return string[] tablas que ya había en la base (vacío en SQLite)
     */
    private static function assertDatabase(array $db): array
    {
        $db = self::cleanDb($db);
        if ($db['driver'] === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                throw new \InvalidArgumentException('The pdo_sqlite extension is not installed on this server');
            }

            return [];
        }
        $conn = self::connect($db);
        if ($conn->tableExists('settings') || $conn->tableExists('migrations')) {
            throw new \InvalidArgumentException('That database already has AIFEC tables with that prefix');
        }

        return self::tables($conn);
    }

    /**
     * @param array<string,mixed> $db
     */
    private static function connect(array $db): Db
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new \InvalidArgumentException('The pdo_mysql extension is not installed on this server');
        }
        try {
            return Db::connect($db);
        } catch (\Throwable $e) {
            $password = isset($db['password']) ? (string) $db['password'] : '';
            $message = $password !== '' ? str_replace($password, '********', $e->getMessage()) : $e->getMessage();

            throw new \InvalidArgumentException(self::CONNECT_ERROR . $message);
        }
    }

    /**
     * @return string[]
     */
    private static function tables(Db $db): array
    {
        $names = [];
        foreach ($db->select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row) {
            $row = array_change_key_case($row, CASE_UPPER);
            $names[] = (string) $row['TABLE_NAME'];
        }

        return $names;
    }

    // ------------------------------------------------------------------ deshacer y borrar

    /**
     * @param array<string,mixed> $undo
     */
    private static function rollback(array $undo): void
    {
        gc_collect_cycles();
        if ($undo['registered']) {
            if ($undo['sites_existed'] || $undo['sites_before'] !== []) {
                SiteResolver::save($undo['data_root'], $undo['sites_before']);
            } elseif (is_file($undo['sites_file'])) {
                @unlink($undo['sites_file']);
                SiteResolver::forget();
            }
        }
        $db = $undo['db'];
        if ($db['driver'] === 'mysql') {
            try {
                $conn = self::connect($db);
                foreach (array_diff(self::tables($conn), $undo['tables_before']) as $table) {
                    $conn->run('DROP TABLE IF EXISTS ' . $conn->ident($table));
                }
            } catch (\Throwable $e) {
                // Si ni siquiera se puede volver a conectar no hay nada más que deshacer desde aquí.
            }
        }
        if ($undo['made_dir']) {
            self::removeTree($undo['dir'], $undo['data_root']);
        }
        foreach ($undo['files'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if ($undo['made_data_root'] && is_dir($undo['data_root'])) {
            @rmdir($undo['data_root']);
        }
    }

    private static function restoreCurrent(?App $previous): void
    {
        if ($previous !== null) {
            $previous->makeCurrent();
        } else {
            App::clearCurrent();
        }
        gc_collect_cycles();
    }

    /**
     * @param array<int,array{id:string,host:string,path:string}> $others los demás sitios de la instalación
     */
    private static function dropTables(string $dataRoot, string $id, array $others): void
    {
        $site = SiteResolver::load($dataRoot, $id);
        $config = $site !== null ? $site->get('db', []) : [];
        if (!is_array($config) || !isset($config['driver']) || $config['driver'] !== 'mysql') {
            return;
        }
        $prefix = isset($config['prefix']) ? (string) $config['prefix'] : '';
        if ($prefix === '') {
            throw new \RuntimeException('The site has no table prefix: refusing to drop its tables');
        }
        $conn = self::connect($config);
        if (!$conn->tableExists('settings')) {
            return;
        }
        // Otro sitio de la misma base con un prefijo que empieza por este («af_» y «af_demo_») no pierde sus tablas.
        $longer = [];
        foreach ($others as $row) {
            $other = SiteResolver::load($dataRoot, $row['id']);
            $c = $other !== null ? $other->get('db', []) : [];
            if (is_array($c) && isset($c['driver'], $c['name']) && $c['driver'] === 'mysql' && $c['name'] === $config['name']
                && (isset($c['host']) ? $c['host'] : 'localhost') === (isset($config['host']) ? $config['host'] : 'localhost')
                && isset($c['prefix']) && strlen((string) $c['prefix']) > strlen($prefix) && strpos((string) $c['prefix'], $prefix) === 0) {
                $longer[] = (string) $c['prefix'];
            }
        }
        foreach (self::tables($conn) as $table) {
            if (strpos($table, $prefix) !== 0) {
                continue;
            }
            foreach ($longer as $other) {
                if (strpos($table, $other) === 0) {
                    continue 2;
                }
            }
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) === 1) {
                $conn->run('DROP TABLE IF EXISTS ' . $conn->ident($table));
            }
        }
    }

    /** Borra una carpeta entera; solo si cuelga de la raíz indicada. No sigue enlaces simbólicos. */
    private static function removeTree(string $dir, string $inside): void
    {
        $dir = self::norm($dir);
        if ($dir === '' || strpos($dir, self::norm($inside) . '/') !== 0 || in_array('..', explode('/', $dir), true)) {
            return;
        }
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path, $dir);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ utilidades

    private static function norm(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function text(array $source, string $key, string $default): string
    {
        if (!isset($source[$key]) || !is_scalar($source[$key])) {
            return $default;
        }
        $value = trim((string) $source[$key]);

        return $value === '' ? $default : $value;
    }
}
