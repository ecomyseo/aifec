<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Plugins;

use Aifec\Db\Migrator;
use Aifec\Db\Schema;
use Aifec\Kernel\App;
use Aifec\Kernel\ModuleManager;

/**
 * El gestor de módulos y plugins: lista, activa, desactiva, desinstala, concede capacidades, instala desde un ZIP y
 * valida contra el Contrato AIFEC. Se pide con $app->service('plugins').
 *
 * Todo devuelve arrays con «ok» y «message» (ya traducido): quien lo llama (el panel, la consola) solo lo enseña.
 * Un plugin se guarda en plugins/<id>/ y está activo si su id está en el ajuste «modules.active».
 */
final class Plugins
{
    /** Tablas del comercio y del sistema que ningún plugin puede tocar ni declarar como suyas. */
    private const CORE_TABLES = [
        'products', 'product_i18n', 'variants', 'variant_values', 'product_media', 'product_categories', 'categories', 'brands',
        'orders', 'order_items', 'order_history', 'order_payments', 'order_refunds', 'carts', 'tax_classes', 'tax_rates',
        'carriers', 'carrier_rates', 'shipping_zones', 'zone_locations', 'coupons', 'coupon_uses', 'customers', 'addresses',
        'customer_groups', 'settings', 'users', 'roles', 'sessions', 'migrations', 'slugs', 'audit', 'jobs', 'media',
    ];

    /** Zonas de los ajustes que son del sistema: un plugin no puede llamarse así. */
    private const RESERVED_AREAS = [
        'shop', 'store', 'mail', 'theme', 'home', 'modules', 'plugins', 'jobs', 'media', 'site', 'app', 'core', 'seo', 'catalog',
        'orders', 'customers', 'tax', 'shipping', 'coupons', 'import', 'ai', 'assistant', 'search', 'cms', 'blog', 'admin',
        'users', 'roles', 'settings', 'cache', 'security',
    ];

    /** @var App */
    private $app;

    /** @var string */
    private $tempBase = '';

    /** @var array<string,array<string,bool>> */
    private $protectedCache = [];

    /** @var array<string,string>|null */
    private $sealedCache;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Carpeta donde se trabaja con temporales (el ZIP abierto, los sitios de prueba del validador). */
    public function useTempBase(string $dir): void
    {
        $this->tempBase = rtrim(str_replace('\\', '/', $dir), '/');
    }

    public function tempBase(): string
    {
        return $this->tempBase !== '' ? $this->tempBase : rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/');
    }

    public function app(): App
    {
        return $this->app;
    }

    /** Un descubridor recién creado: lo que hay ahora en las carpetas modules/ y plugins/, sin memoria de antes. */
    public function manager(): ModuleManager
    {
        return new ModuleManager($this->app->root());
    }

    /**
     * Identificadores activos ahora mismo (los que cargaría el arranque).
     *
     * @return string[]
     */
    public function activeIds(?ModuleManager $mm = null): array
    {
        $mm = $mm !== null ? $mm : $this->manager();
        $enabled = $this->app->settings()->get('modules.active');

        return $mm->activeIds(is_array($enabled) ? $enabled : null);
    }

    // ------------------------------------------------------------------ listado

    /**
     * Módulos incluidos y plugins instalados, en este orden y por identificador.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $mm = $this->manager();
        $all = $mm->all();
        $active = $this->activeIds($mm);
        $reports = $this->reports();
        $live = $this->app->modules()->failed();
        $granted = $this->app->settings()->arr('plugins.granted');
        $i18n = $this->app->i18n();
        $rows = [];
        foreach ($all as $id => $m) {
            $id = (string) $id;
            $contract = $this->contractOf($m);
            $requires = isset($m['requires']) && is_array($m['requires']) ? $m['requires'] : [];
            $failed = isset($live[$id]) ? $live[$id] : ($mm->failed()[$id] ?? '');
            $rows[] = [
                'id' => $id,
                'kind' => (string) $m['kind'],
                'valid' => true,
                'name' => $i18n->t((string) $m['name']),
                'description' => $m['description'] !== '' ? $i18n->t((string) $m['description']) : '',
                'version' => (string) $m['version'],
                'active' => in_array($id, $active, true),
                'essential' => !empty($m['essential']),
                'default' => !empty($m['default']),
                'requires' => [
                    'modules' => $this->listOf($requires, 'modules'),
                    'php' => isset($requires['php']) && is_string($requires['php']) ? $requires['php'] : '',
                    'aifec' => isset($requires['aifec']) && is_string($requires['aifec']) ? $requires['aifec'] : '',
                ],
                'contract' => $contract,
                'granted' => array_values(array_filter($contract['capabilities'], static function (string $c) use ($id, $granted): bool {
                    return in_array($id . ':' . $c, $granted, true);
                })),
                'report' => isset($reports[$id]) ? $reports[$id] : null,
                'error' => is_string($failed) ? $failed : '',
                'has_settings' => is_file((string) $m['path'] . '/settings.php'),
                'path' => (string) $m['path'],
            ];
        }
        // Los manifiestos rotos salen aparte: ni se pueden cargar ni tumban la pantalla.
        foreach ($mm->failed() as $id => $error) {
            $id = (string) $id;
            if (isset($all[$id]) || strpos((string) $error, 'Invalid ') !== 0) {
                continue;
            }
            $rows[] = [
                'id' => $id, 'kind' => strpos((string) $error, 'module.json') !== false ? 'module' : 'plugin', 'valid' => false,
                'name' => $id, 'description' => '', 'version' => '', 'active' => false, 'essential' => false, 'default' => false,
                'requires' => ['modules' => [], 'php' => '', 'aifec' => ''],
                'contract' => ['hosts' => [], 'personal_data' => [], 'tables' => [], 'capabilities' => []],
                'granted' => [], 'report' => null, 'error' => $i18n->t('The manifest is not valid JSON'), 'has_settings' => false, 'path' => '',
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return [$a['kind'] === 'module' ? 0 : 1, $a['id']] <=> [$b['kind'] === 'module' ? 0 : 1, $b['id']];
        });

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ activar y desactivar

    /**
     * Activa un módulo o un plugin. Un plugin pasa antes el validador y no se activa si tiene un fallo que bloquea.
     *
     * @return array<string,mixed>
     */
    public function enable(string $id): array
    {
        $mm = $this->manager();
        $m = $mm->manifest($id);
        if ($m === null) {
            return $this->fail($this->app->i18n()->t('There is no module or plugin called «{id}»', ['id' => $id]));
        }
        if (in_array($id, $this->activeIds($mm), true)) {
            return $this->done($this->app->i18n()->t('«{id}» is already active', ['id' => $id]));
        }
        $problems = $this->requirements($m, $mm);
        if ($problems !== []) {
            return $this->fail($problems[0], ['errors' => $problems]);
        }
        if ($m['kind'] === 'plugin') {
            $report = $this->validate($id, true);
            if (!$report['ok']) {
                $errors = [];
                foreach ($report['checks'] as $check) {
                    if (!$check['ok'] && $check['level'] === 'error') {
                        $errors[] = $check['rule'] . ': ' . $check['message'] . ($check['file'] !== '' ? ' (' . $check['file'] . ($check['line'] > 0 ? ':' . $check['line'] : '') . ')' : '');
                    }
                }

                return $this->fail($this->app->i18n()->t('«{id}» does not pass the AIFEC contract and cannot be activated', ['id' => $id]), ['errors' => array_slice($errors, 0, 8), 'report' => $report]);
            }
        }

        return $this->activate($id);
    }

    /**
     * Activa sin pasar el validador: aplica las migraciones del plugin y lo apunta en «modules.active». Lo usa «enable»
     * y el propio validador dentro de su sitio de pruebas; en el panel y en la consola se usa «enable».
     *
     * @return array<string,mixed>
     */
    public function activate(string $id): array
    {
        $mm = $this->manager();
        $m = $mm->manifest($id);
        if ($m === null) {
            return $this->fail($this->app->i18n()->t('There is no module or plugin called «{id}»', ['id' => $id]));
        }
        $problems = $this->requirements($m, $mm);
        if ($problems !== []) {
            return $this->fail($problems[0], ['errors' => $problems]);
        }
        try {
            if ($m['kind'] === 'plugin' && is_dir((string) $m['path'] . '/migrations')) {
                (new Migrator($this->app->db()))->run($id, (string) $m['path'] . '/migrations', $this->app->clock()->sql());
            }
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'plugins:activate:' . $id);

            return $this->fail($this->app->i18n()->t('The migrations of «{id}» failed: {error}', ['id' => $id, 'error' => $e->getMessage()]));
        }
        $list = $this->activeIds($mm);
        if (!in_array($id, $list, true)) {
            $list[] = $id;
        }
        $this->app->settings()->set('modules.active', array_values(array_unique($list)));
        $this->app->cache()->flush();

        return $this->done($this->app->i18n()->t('«{id}» is active', ['id' => $id]));
    }

    /**
     * @return array<string,mixed>
     */
    public function disable(string $id): array
    {
        $mm = $this->manager();
        $m = $mm->manifest($id);
        if ($m === null) {
            return $this->fail($this->app->i18n()->t('There is no module or plugin called «{id}»', ['id' => $id]));
        }
        $active = $this->activeIds($mm);
        if (!in_array($id, $active, true)) {
            return $this->done($this->app->i18n()->t('«{id}» is already off', ['id' => $id]));
        }
        if (!empty($m['essential'])) {
            return $this->fail($this->app->i18n()->t('«{id}» is essential and cannot be turned off', ['id' => $id]));
        }
        $needed = [];
        foreach ($active as $other) {
            $om = $mm->manifest($other);
            if ($other !== $id && $om !== null && in_array($id, $this->listOf(isset($om['requires']) && is_array($om['requires']) ? $om['requires'] : [], 'modules'), true)) {
                $needed[] = (string) $om['name'];
            }
        }
        if ($needed !== []) {
            return $this->fail($this->app->i18n()->t('«{id}» cannot be turned off because these need it: {names}', ['id' => $id, 'names' => implode(', ', $needed)]), ['needed_by' => $needed]);
        }
        $this->app->settings()->set('modules.active', array_values(array_diff($active, [$id])));
        $this->app->cache()->flush();

        return $this->done($this->app->i18n()->t('«{id}» is off', ['id' => $id]));
    }

    /**
     * Quita un plugin: su carpeta y, si no se conservan los datos, sus tablas declaradas, sus ajustes y sus migraciones.
     * Solo plugins y desactivados. Nunca toca una tabla que el plugin no declaró ni una del sistema.
     *
     * @return array<string,mixed>
     */
    public function uninstall(string $id, bool $keepData): array
    {
        $mm = $this->manager();
        $m = $mm->manifest($id);
        if ($m === null || $m['kind'] !== 'plugin') {
            return $this->fail($this->app->i18n()->t('«{id}» is not an installed plugin', ['id' => $id]));
        }
        if (in_array($id, $this->activeIds($mm), true)) {
            return $this->fail($this->app->i18n()->t('Turn «{id}» off before uninstalling it', ['id' => $id]));
        }
        $dropped = [];
        $removed = 0;
        if (!$keepData) {
            $protected = $this->protectedTables($id);
            $schema = new Schema($this->app->db());
            foreach ($this->contractOf($m)['tables'] as $table) {
                if (preg_match('/^[a-z][a-z0-9_]*$/', $table) === 1 && !isset($protected[$table])) {
                    $schema->drop($table);
                    $dropped[] = $table;
                }
            }
            $removed = $this->deleteSettings($id, $mm);
            (new Migrator($this->app->db()))->forget($id);
        }
        $reports = $this->reports();
        unset($reports[$id]);
        $this->app->settings()->set('plugins.reports', $reports);
        $granted = array_values(array_filter($this->app->settings()->arr('plugins.granted'), static function ($entry) use ($id): bool {
            return !is_string($entry) || strpos($entry, $id . ':') !== 0;
        }));
        $this->app->settings()->set('plugins.granted', $granted);
        $this->removeFolder($id);
        $this->app->cache()->flush();

        return $this->done($this->app->i18n()->t('«{id}» has been uninstalled', ['id' => $id]), ['dropped_tables' => $dropped, 'removed_settings' => $removed, 'kept_data' => $keepData]);
    }

    /**
     * Concede o retira una capacidad que el plugin declaró: «pricing» (tocar precios, impuestos, disponibilidad o plazos) u «orders»
     * (decidir si una descarga de lo digital comprado se entrega).
     *
     * @return array<string,mixed>
     */
    public function grant(string $id, string $capability, bool $on): array
    {
        $m = $this->manager()->manifest($id);
        if ($m === null || $m['kind'] !== 'plugin') {
            return $this->fail($this->app->i18n()->t('«{id}» is not an installed plugin', ['id' => $id]));
        }
        if (!in_array($capability, $this->contractOf($m)['capabilities'], true)) {
            return $this->fail($this->app->i18n()->t('«{id}» does not ask for the capability «{capability}»', ['id' => $id, 'capability' => $capability]));
        }
        $entry = $id . ':' . $capability;
        $list = array_values(array_filter($this->app->settings()->arr('plugins.granted'), static function ($e) use ($entry): bool {
            return $e !== $entry;
        }));
        if ($on) {
            $list[] = $entry;
        }
        $this->app->settings()->set('plugins.granted', $list);
        $this->app->cache()->flush();

        return $this->done($on
            ? $this->app->i18n()->t('«{id}» may now use the capability «{capability}»', ['id' => $id, 'capability' => $capability])
            : $this->app->i18n()->t('«{id}» no longer has the capability «{capability}»', ['id' => $id, 'capability' => $capability]));
    }

    /**
     * Instala un plugin desde un ZIP que ha subido el administrador. No lo activa.
     *
     * @return array<string,mixed>
     */
    public function install(string $zipPath): array
    {
        return (new ZipInstaller($this))->install($zipPath);
    }

    // ------------------------------------------------------------------ validador

    /**
     * Pasa el validador del contrato a un plugin instalado y guarda el informe. Con $useStored, si ya hay un informe
     * completo de estos mismos ficheros se devuelve ese.
     *
     * @return array<string,mixed> ok, score, checks, measured, dynamic, id, version, date, fingerprint
     */
    public function validate(string $id, bool $useStored = false): array
    {
        $dir = $this->app->root() . '/plugins/' . $id;
        $mm = $this->manager();
        $m = $mm->manifest($id);
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id) !== 1 || !is_dir($dir) || ($m !== null && $m['kind'] !== 'plugin')) {
            $msg = $this->app->i18n()->t('«{id}» is not an installed plugin', ['id' => $id]);

            return ['ok' => false, 'score' => 0, 'checks' => [['rule' => 'M1', 'level' => 'error', 'ok' => false, 'message' => $msg, 'file' => '', 'line' => 0]], 'measured' => [], 'dynamic' => false, 'id' => $id, 'version' => '', 'date' => '', 'fingerprint' => ''];
        }
        $fingerprint = Fs::fingerprint($dir);
        $reports = $this->reports();
        if ($useStored && isset($reports[$id]['fingerprint'], $reports[$id]['dynamic']) && $reports[$id]['fingerprint'] === $fingerprint && $reports[$id]['dynamic'] === true) {
            return $reports[$id];
        }
        $raw = json_decode(is_file($dir . '/plugin.json') ? (string) file_get_contents($dir . '/plugin.json') : '', true);
        $manifest = is_array($raw) ? $raw : [];
        $report = Validator::run($this, $this->app->root(), $id, $manifest, true);
        $report['id'] = $id;
        $report['version'] = isset($manifest['version']) && is_string($manifest['version']) ? $manifest['version'] : '';
        $report['date'] = $this->app->clock()->sql();
        $report['fingerprint'] = $fingerprint;
        $reports[$id] = $report;
        $this->app->settings()->set('plugins.reports', $reports);

        return $report;
    }

    /**
     * Informes guardados: id => informe.
     *
     * @return array<string,array<string,mixed>>
     */
    public function reports(): array
    {
        $stored = $this->app->settings()->arr('plugins.reports');
        $out = [];
        foreach ($stored as $id => $report) {
            if (is_array($report) && isset($report['checks'])) {
                $out[(string) $id] = $report;
            }
        }

        return $out;
    }

    /**
     * Tablas que un plugin no puede tocar: las del sistema, las de todos los módulos y plugins (salvo $except) y las que
     * crean sus migraciones.
     *
     * @return array<string,bool>
     */
    public function protectedTables(string $except = ''): array
    {
        if (isset($this->protectedCache[$except])) {
            return $this->protectedCache[$except];
        }
        $set = array_fill_keys(self::CORE_TABLES, true);
        foreach ($this->manager()->all() as $id => $m) {
            if ((string) $id === $except) {
                continue;
            }
            foreach ($this->contractOf($m)['tables'] as $table) {
                $set[$table] = true;
            }
        }
        $root = $this->app->root();
        $files = array_merge(
            (array) glob($root . '/core/migrations/*.php'),
            (array) glob($root . '/modules/*/migrations/*.php')
        );
        foreach ($files as $file) {
            if (preg_match_all('/->create\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', (string) file_get_contents((string) $file), $matches) > 0) {
                foreach ($matches[1] as $table) {
                    $set[$table] = true;
                }
            }
        }
        $this->protectedCache[$except] = $set;

        return $set;
    }

    /**
     * Los ganchos sellados que declaran el núcleo y los módulos: gancho => capacidad.
     *
     * @return array<string,string>
     */
    public function sealedHooks(): array
    {
        if ($this->sealedCache !== null) {
            return $this->sealedCache;
        }
        $sealed = [
            'product.price' => 'pricing', 'product.availability' => 'pricing', 'shipping.options' => 'pricing', 'tax.rate' => 'pricing',
            'order.download.allowed' => 'orders',
        ];
        $root = $this->app->root();
        $files = array_merge((array) glob($root . '/core/hooks.php'), (array) glob($root . '/modules/*/hooks.php'));
        foreach ($files as $file) {
            if (preg_match_all('/->seal\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents((string) $file), $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $m) {
                    $sealed[$m[1]] = $m[2];
                }
            }
        }
        $this->sealedCache = $sealed;

        return $sealed;
    }

    /** ¿Es una zona de ajustes del sistema? Un plugin no puede usar su nombre. */
    public function isReservedId(string $id): bool
    {
        $area = str_replace('-', '_', $id);
        if (in_array($area, self::RESERVED_AREAS, true)) {
            return true;
        }
        $mm = $this->manager();
        $areas = [];
        foreach (array_merge(['core'], array_keys($mm->all())) as $other) {
            $kind = $other === 'core' ? 'module' : (string) ($mm->manifest((string) $other)['kind'] ?? 'module');
            if ($kind !== 'module') {
                continue;
            }
            foreach (array_keys(ModuleManager::settingDefaults($mm->definition((string) $other, 'settings'))) as $key) {
                $areas[strtok((string) $key, '.')] = true;
            }
        }

        return isset($areas[$area]);
    }

    // ------------------------------------------------------------------ requisitos

    /**
     * Lo que impide activar un módulo o plugin: módulos que necesita y no están activos, y versiones de PHP y de AIFEC.
     *
     * @param array<string,mixed> $m
     *
     * @return string[]
     */
    public function requirements(array $m, ?ModuleManager $mm = null): array
    {
        $mm = $mm !== null ? $mm : $this->manager();
        $i18n = $this->app->i18n();
        $problems = [];
        $requires = isset($m['requires']) && is_array($m['requires']) ? $m['requires'] : [];
        $active = $this->activeIds($mm);
        foreach ($this->listOf($requires, 'modules') as $need) {
            if ($mm->manifest($need) === null) {
                $problems[] = $i18n->t('It needs the module «{id}», which is not installed', ['id' => $need]);
            } elseif (!in_array($need, $active, true)) {
                $problems[] = $i18n->t('It needs the module «{id}» to be active', ['id' => $need]);
            }
        }
        if (isset($requires['php']) && is_string($requires['php']) && !self::versionOk(PHP_VERSION, $requires['php'])) {
            $problems[] = $i18n->t('It needs PHP {version} and this server has {have}', ['version' => $requires['php'], 'have' => PHP_VERSION]);
        }
        if (isset($requires['aifec']) && is_string($requires['aifec']) && !self::versionOk(App::VERSION, $requires['aifec'])) {
            $problems[] = $i18n->t('It needs AIFEC {version} and this is {have}', ['version' => $requires['aifec'], 'have' => App::VERSION]);
        }

        return $problems;
    }

    /** Comprueba una versión contra una restricción como «>=7.4» o «>=1.0.0,<2». */
    public static function versionOk(string $have, string $constraint): bool
    {
        foreach (explode(',', $constraint) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(>=|<=|>|<|==|=|!=)?\s*([0-9][0-9a-zA-Z.\-+]*)$/', $part, $m) !== 1) {
                return false;
            }
            $op = $m[1] !== '' ? $m[1] : '>=';
            if (!version_compare($have, $m[2], $op === '=' ? '==' : $op)) {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ ayudas

    /**
     * La declaración del contrato de un manifiesto, con las cuatro listas siempre presentes.
     *
     * @param array<string,mixed> $m
     *
     * @return array{hosts:string[],personal_data:string[],tables:string[],capabilities:string[]}
     */
    public function contractOf(array $m): array
    {
        $contract = isset($m['contract']) && is_array($m['contract']) ? $m['contract'] : [];

        return [
            'hosts' => $this->listOf($contract, 'hosts'),
            'personal_data' => $this->listOf($contract, 'personal_data'),
            'tables' => $this->listOf($contract, 'tables'),
            'capabilities' => $this->listOf($contract, 'capabilities'),
        ];
    }

    /**
     * @param array<string,mixed> $source
     *
     * @return string[]
     */
    private function listOf(array $source, string $key): array
    {
        $out = [];
        foreach (isset($source[$key]) && is_array($source[$key]) ? $source[$key] : [] as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** Borra los ajustes del plugin (los de su zona: «id.» o «id_con_guiones_bajos.»). Devuelve cuántos. */
    private function deleteSettings(string $id, ModuleManager $mm): int
    {
        if ($this->isReservedId($id)) {
            return 0;
        }
        $prefixes = array_unique([$id . '.', str_replace('-', '_', $id) . '.']);
        $count = 0;
        foreach ($this->app->db()->column('SELECT k FROM {settings}') as $key) {
            foreach ($prefixes as $prefix) {
                if (strpos((string) $key, $prefix) === 0) {
                    $this->app->settings()->delete((string) $key);
                    ++$count;
                    break;
                }
            }
        }

        return $count;
    }

    private function removeFolder(string $id): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $id) !== 1) {
            return;
        }
        $dir = $this->app->root() . '/plugins/' . $id;
        $real = realpath($dir);
        $base = realpath($this->app->root() . '/plugins');
        if ($real !== false && $base !== false && strpos(str_replace('\\', '/', $real), str_replace('\\', '/', $base) . '/') === 0) {
            Fs::rmTree($real);
        }
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function done(string $message, array $extra = []): array
    {
        return ['ok' => true, 'message' => $message] + $extra;
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function fail(string $message, array $extra = []): array
    {
        return ['ok' => false, 'message' => $message] + $extra;
    }
}
