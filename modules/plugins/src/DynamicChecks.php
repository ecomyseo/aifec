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

use Aifec\Autoload;
use Aifec\Commerce\Totals;
use Aifec\Kernel\App;
use Aifec\Kernel\Hooks;
use Aifec\Kernel\Request;

/**
 * Las comprobaciones del Contrato AIFEC que ejecutan el plugin: se hacen en un sitio de pruebas desechable (Sandbox) con
 * datos mínimos y sin red, nunca en el sitio de verdad.
 *
 *   C1   el total de un carrito de referencia con y sin el plugin solo difiere en líneas de ajuste con nombre
 *   C3   el peso y el tiempo de las páginas de referencia con y sin el plugin, y que siguen siendo cacheables
 *   C7   el JSON-LD de la ficha sigue siendo JSON válido (si hay un módulo de datos estructurados)
 *   C9   activar, desactivar y desinstalar sin conservar datos deja el mismo esquema y los mismos ajustes
 *   C10  las páginas de referencia responden 200 con el plugin, también si cada oyente suyo lanza una excepción
 *
 * Páginas de referencia: la portada y, si existen en esta instalación (responden 200 sin el plugin), la ficha, el listado
 * y el carrito.
 */
final class DynamicChecks
{
    private const GROWTH_PERCENT = 15;
    private const GROWTH_MIN_BYTES = 1024;
    private const GROWTH_MS = 50;

    /** @var Plugins */
    private $plugins;

    /** @var string */
    private $root;

    /** @var string */
    private $id;

    /** @var array<string,mixed> */
    private $manifest;

    /** @var array<int,array{rule:string,level:string,message:string,file:string,line:int}> */
    private $findings = [];

    /** @var array<string,mixed> */
    private $measured = [];

    /**
     * @param array<string,mixed> $manifest
     */
    public function __construct(Plugins $plugins, string $root, string $id, array $manifest)
    {
        $this->plugins = $plugins;
        $this->root = $root;
        $this->id = $id;
        $this->manifest = $manifest;
    }

    /**
     * @return array{findings:array<int,array{rule:string,level:string,message:string,file:string,line:int}>,measured:array<string,mixed>,rules:string[]}
     */
    public function run(): array
    {
        $app = $this->plugins->app();
        $previous = App::hasCurrent() ? App::current() : null;
        $prefixes = new \ReflectionProperty(Autoload::class, 'prefixes');
        $prefixes->setAccessible(true);
        $savedPrefixes = $prefixes->getValue();
        $sandbox = null;
        try {
            $sandbox = Sandbox::create($this->plugins, $this->root, $this->id, $app->clock()->now());
            $this->measure($sandbox);
        } catch (\Throwable $e) {
            $this->add('C10', 'error', $app->i18n()->t('The dynamic checks could not run: {error}', ['error' => $e->getMessage()]), '', 0);
        } finally {
            // El sitio de pruebas registró en el cargador de clases las carpetas de su copia: se devuelve lo que había.
            $prefixes->setValue(null, $savedPrefixes);
            // Primero se suelta la aplicación del sitio de pruebas (sigue guardada como «la actual»): mientras viva, su
            // base de datos está abierta y en Windows no se podría borrar.
            if ($previous !== null) {
                $previous->makeCurrent();
            } else {
                App::clearCurrent();
            }
            if ($sandbox !== null) {
                $sandbox->dispose();
            }
        }

        return ['findings' => $this->findings, 'measured' => $this->measured, 'rules' => ['C7', 'C10']];
    }

    private function measure(Sandbox $sandbox): void
    {
        $i18n = $this->plugins->app()->i18n();
        $id = $this->id;

        // 1. El sitio sin el plugin.
        $app0 = $sandbox->app();
        $app0->makeCurrent();
        $app0->boot();
        $product = $this->seedProduct($app0);
        $pages = [];
        foreach ($this->candidatePages($app0, $product) as $name => $path) {
            $result = $this->fetch($app0, $path);
            if ($result['status'] === 200) {
                $pages[$name] = ['path' => $path, 'base' => $result];
            }
        }
        $cart0 = $product !== null ? $this->cart($app0, $product) : null;
        $ldBase = $app0->modules()->isLoaded('semantic') && isset($pages['product']) ? $this->jsonLd($pages['product']['base']['body']) : null;
        $before = $this->snapshot($app0);
        $this->measured['pages_checked'] = array_keys($pages);

        // 2. Se activa en el sitio de pruebas, con las capacidades que pide concedidas: así se ve lo que PUEDE hacer.
        $svc0 = new Plugins($app0);
        $svc0->useTempBase($this->plugins->tempBase());
        $activated = $svc0->activate($id);
        if (!$activated['ok']) {
            $this->add('C10', 'error', $i18n->t('The plugin cannot be activated: {error}', ['error' => (string) $activated['message']]), '', 0);

            return;
        }
        $contract = $this->plugins->contractOf($this->manifest);
        foreach ($contract['capabilities'] as $capability) {
            $svc0->grant($id, $capability, true);
        }
        $afterActivate = $this->snapshot($app0);
        foreach (array_keys($afterActivate['tables']) as $table) {
            if (!isset($before['tables'][$table]) && !in_array((string) $table, $contract['tables'], true)) {
                $this->add('C9', 'error', $i18n->t('The table «{table}» appears when the plugin is activated and is not declared in contract.tables', ['table' => (string) $table]), '', 0);
            }
        }

        // 3. El sitio con el plugin.
        $app1 = $sandbox->app();
        $app1->makeCurrent();
        $app1->boot();
        $failed = $app1->modules()->failed();
        if (isset($failed[$id])) {
            $this->add('C10', 'error', $i18n->t('The plugin fails when it loads: {error}', ['error' => (string) $failed[$id]]), '', 0);
        }
        $stats = [];
        foreach ($pages as $name => $page) {
            $with = $this->fetch($app1, (string) $page['path']);
            $base = $page['base'];
            $stats[$name] = ['status' => $with['status'], 'bytes' => $base['bytes'], 'bytes_with' => $with['bytes'], 'ms' => $base['ms'], 'ms_with' => $with['ms'], 'cacheable' => $base['cacheable'], 'cacheable_with' => $with['cacheable']];
            if ($with['status'] !== 200) {
                $this->add('C10', 'error', $i18n->t('The page «{page}» answers {status} with the plugin active', ['page' => (string) $name, 'status' => $with['status']]), '', 0);

                continue;
            }
            $growth = $with['bytes'] - $base['bytes'];
            if ($base['bytes'] > 0 && $growth > self::GROWTH_MIN_BYTES && ($growth * 100 / $base['bytes']) > self::GROWTH_PERCENT) {
                $this->add('C3', 'warning', $i18n->t('The page «{page}» grows {percent} % with the plugin', ['page' => (string) $name, 'percent' => (int) round($growth * 100 / $base['bytes'])]), '', 0);
            }
            if ($with['ms'] - $base['ms'] > self::GROWTH_MS) {
                $this->add('C3', 'warning', $i18n->t('The page «{page}» takes {ms} ms more with the plugin', ['page' => (string) $name, 'ms' => (int) round($with['ms'] - $base['ms'])]), '', 0);
            }
            if ($base['cacheable'] && !$with['cacheable']) {
                $this->add('C3', 'error', $i18n->t('The page «{page}» stops being cacheable with the plugin', ['page' => (string) $name]), '', 0);
            }
            if ($name === 'product' && $ldBase !== null) {
                $ld = $this->jsonLd($with['body']);
                if ($ld['invalid'] > 0 || $ld['count'] < $ldBase['count']) {
                    $this->add('C7', 'error', $i18n->t('The structured data (JSON-LD) of the product page is no longer valid with the plugin'), '', 0);
                }
            }
        }
        // Un gancho que lanza no tumba la página (el sistema lo recoge), pero es un fallo del plugin y se avisa.
        $this->measured['listeners_failed'] = 0;
        foreach ($app1->hooks()->errors() as $error) {
            if ($error['module'] === $id) {
                ++$this->measured['listeners_failed'];
                $this->add('C10', 'warning', $i18n->t('A hook of the plugin fails: {hook}: {error}', ['hook' => $error['hook'], 'error' => $error['error']]), '', 0);
            }
        }
        $this->measured['pages'] = $stats;
        $this->measured['structured_data'] = $ldBase === null ? 'not applicable' : 'checked';

        // C1 medido: el total de un carrito de referencia.
        if ($product !== null && $cart0 !== null) {
            $cart1 = $this->cart($app1, $product);
            $this->measured['cart'] = ['without' => $cart0, 'with' => $cart1];
            if ($cart1 === null) {
                $this->add('C1', 'error', $i18n->t('The reference cart cannot be calculated with the plugin active'), '', 0);
            } else {
                if ($cart1['lines_total'] !== $cart0['lines_total']) {
                    $this->add('C1', 'error', $i18n->t('The reference cart changes by {delta} cents without a named adjustment line', ['delta' => $cart1['lines_total'] - $cart0['lines_total']]), '', 0);
                }
                foreach ($cart1['adjustments'] as $adjustment) {
                    if (!is_array($adjustment) || !isset($adjustment['label']) || !is_string($adjustment['label']) || trim($adjustment['label']) === '' || !isset($adjustment['amount']) || !is_int($adjustment['amount'])) {
                        $this->add('C1', 'error', $i18n->t('The plugin adds an adjustment line without a name or without an amount in cents'), '', 0);
                        break;
                    }
                }
            }
        } else {
            $this->measured['cart'] = 'not applicable';
        }

        // C10 forzado: cada oyente del plugin lanza una excepción y las páginas tienen que seguir respondiendo.
        $forced = $this->forceFailures($app1);
        $this->measured['listeners_forced'] = $forced;
        foreach ($pages as $name => $page) {
            $with = $this->fetch($app1, (string) $page['path']);
            if ($with['status'] !== 200) {
                $this->add('C10', 'error', $i18n->t('The page «{page}» answers {status} when the hooks of the plugin fail', ['page' => (string) $name, 'status' => $with['status']]), '', 0);
            }
        }

        // C9: el ciclo entero y la comparación del esquema y los ajustes.
        $svc1 = new Plugins($app1);
        $svc1->useTempBase($this->plugins->tempBase());
        foreach ([['disable', true], ['activate', true], ['disable', true]] as $step) {
            $r = $step[0] === 'disable' ? $svc1->disable($id) : $svc1->activate($id);
            if (!$r['ok']) {
                $this->add('C9', 'error', $i18n->t('The plugin cannot go through its life cycle: {error}', ['error' => (string) $r['message']]), '', 0);

                return;
            }
        }
        $removed = $svc1->uninstall($id, false);
        if (!$removed['ok']) {
            $this->add('C9', 'error', $i18n->t('The plugin cannot be uninstalled: {error}', ['error' => (string) $removed['message']]), '', 0);

            return;
        }
        $this->compare($before, $this->snapshot($app1));
    }

    /**
     * @param array{tables:array<string,string[]>,settings:array<string,string>} $before
     * @param array{tables:array<string,string[]>,settings:array<string,string>} $after
     */
    private function compare(array $before, array $after): void
    {
        $i18n = $this->plugins->app()->i18n();
        foreach ($after['tables'] as $table => $columns) {
            if (!isset($before['tables'][$table])) {
                $this->add('C9', 'error', $i18n->t('Leaves the table «{table}» behind after uninstalling', ['table' => (string) $table]), '', 0);
            } elseif ($before['tables'][$table] !== $columns) {
                $this->add('C9', 'error', $i18n->t('Changes the columns of the table «{table}»', ['table' => (string) $table]), '', 0);
            }
        }
        foreach ($before['tables'] as $table => $columns) {
            if (!isset($after['tables'][$table])) {
                $this->add('C9', 'error', $i18n->t('Removes the table «{table}», which is not its own', ['table' => (string) $table]), '', 0);
            }
        }
        foreach ($after['settings'] as $key => $value) {
            if (!array_key_exists($key, $before['settings'])) {
                $this->add('C9', 'error', $i18n->t('Leaves the setting «{key}» behind after uninstalling', ['key' => (string) $key]), '', 0);
            } elseif ($before['settings'][$key] !== $value) {
                $this->add('C9', 'error', $i18n->t('Changes the setting «{key}»', ['key' => (string) $key]), '', 0);
            }
        }
        foreach ($before['settings'] as $key => $value) {
            if (!array_key_exists($key, $after['settings'])) {
                $this->add('C9', 'error', $i18n->t('Removes the setting «{key}», which is not its own', ['key' => (string) $key]), '', 0);
            }
        }
        $this->measured['schema'] = ['tables' => count($after['tables']), 'settings' => count($after['settings'])];
    }

    /** Sustituye cada oyente del plugin por uno que lanza. Devuelve cuántos. */
    private function forceFailures(App $app): int
    {
        $hooks = $app->hooks();
        $property = new \ReflectionProperty(Hooks::class, 'listeners');
        $property->setAccessible(true);
        $all = $property->getValue($hooks);
        $count = 0;
        foreach ($all as $name => $priorities) {
            foreach ($priorities as $priority => $entries) {
                foreach ($entries as $index => $entry) {
                    if ($entry[1] === $this->id) {
                        $all[$name][$priority][$index][0] = static function () {
                            throw new \RuntimeException('Forced failure from the contract check');
                        };
                        ++$count;
                    }
                }
            }
        }
        $property->setValue($hooks, $all);

        return $count;
    }

    /**
     * Páginas candidatas a ser de referencia: nombre => ruta.
     *
     * @return array<string,string>
     */
    private function candidatePages(App $app, ?int $product): array
    {
        $pages = ['home' => '/'];
        if ($product !== null) {
            $path = parse_url($app->url()->entity('product', $product), PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $pages['product'] = $path;
            }
        }
        $pages['listing'] = '/productos';
        $pages['cart'] = '/carrito';

        return $pages;
    }

    /** Un producto de referencia, si el catálogo está en el sitio de pruebas. */
    private function seedProduct(App $app): ?int
    {
        if (!$app->modules()->isLoaded('catalog')) {
            return null;
        }
        try {
            $db = $app->db();
            $now = $app->clock()->sql();
            $lang = $app->defaultLang();
            $id = $db->insert('products', [
                'kind' => 'physical', 'has_variants' => 0, 'price' => 1000, 'manage_stock' => 1, 'stock' => 10, 'status' => 'published',
                'visibility' => 'everywhere', 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('product_i18n', ['product_id' => $id, 'lang' => $lang, 'name' => 'Reference product', 'summary' => '', 'description' => '']);
            $app->slugs()->set('product', $id, $lang, 'producto/reference-product');

            return $id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * El carrito de referencia: dos unidades del producto de referencia, con el precio que da el catálogo.
     *
     * @return array{lines_total:int,total:int,adjustments:array<int,mixed>}|null
     */
    private function cart(App $app, int $product): ?array
    {
        try {
            $data = $app->service('catalog')->product($product);
            if (!is_array($data) || !isset($data['price']['amount'])) {
                return null;
            }
            $price = $data['price'];
            $adjustments = isset($price['adjustments']) && is_array($price['adjustments']) ? array_values($price['adjustments']) : [];
            $lines = [['key' => 'p' . $product, 'unit_price' => (int) $price['amount'], 'qty' => 2, 'tax_rate' => 0]];
            $base = Totals::calculate(['prices_include_tax' => true, 'lines' => $lines]);
            $clean = [];
            foreach ($adjustments as $adjustment) {
                if (is_array($adjustment) && isset($adjustment['label']) && is_string($adjustment['label']) && isset($adjustment['amount']) && is_int($adjustment['amount'])) {
                    $clean[] = $adjustment + ['tax_rate' => 0, 'tax_name' => '', 'source' => ''];
                }
            }
            $withAdjustments = Totals::calculate(['prices_include_tax' => true, 'lines' => $lines, 'adjustments' => $clean]);

            return ['lines_total' => (int) $base['total'], 'total' => (int) $withAdjustments['total'], 'adjustments' => $adjustments];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pide una página como un navegador. El tiempo es el mejor de tres peticiones.
     *
     * @return array{status:int,bytes:int,ms:float,cacheable:bool,body:string}
     */
    private function fetch(App $app, string $path): array
    {
        $best = null;
        $status = 500;
        $bytes = 0;
        $cacheable = false;
        $body = '';
        for ($i = 0; $i < 3; $i++) {
            $request = Request::create('GET', $path, [], [], ['sec-fetch-site' => 'same-origin', 'user-agent' => 'AifecContract/1.0']);
            $request->host = 'tienda.test';
            $request->secure = true;
            $t0 = microtime(true);
            try {
                $response = $app->handle($request);
            } catch (\Throwable $e) {
                return ['status' => 500, 'bytes' => 0, 'ms' => 0.0, 'cacheable' => false, 'body' => ''];
            }
            $ms = (microtime(true) - $t0) * 1000;
            if ($i === 0) {
                $status = $response->status;
                $bytes = strlen($response->body);
                $cacheable = $response->cacheTtl > 0;
                $body = $response->body;
            }
            $best = $best === null ? $ms : min($best, $ms);
        }

        return ['status' => $status, 'bytes' => $bytes, 'ms' => (float) $best, 'cacheable' => $cacheable, 'body' => $body];
    }

    /**
     * @return array{count:int,invalid:int}
     */
    private function jsonLd(string $html): array
    {
        $count = 0;
        $invalid = 0;
        if (preg_match_all('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $matches) > 0) {
            foreach ($matches[1] as $json) {
                ++$count;
                $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (!is_array($decoded)) {
                    ++$invalid;
                }
            }
        }

        return ['count' => $count, 'invalid' => $invalid];
    }

    /**
     * Las tablas (con sus columnas) y los ajustes del sitio de pruebas, sin lo que el propio gestor cambia al activar.
     *
     * @return array{tables:array<string,string[]>,settings:array<string,string>}
     */
    private function snapshot(App $app): array
    {
        $db = $app->db();
        $prefix = $db->prefix();
        $names = $db->driver() === 'sqlite'
            ? $db->column("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite%'")
            : $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $tables = [];
        foreach ($names as $real) {
            $real = (string) $real;
            if ($prefix !== '' && strpos($real, $prefix) !== 0) {
                continue;
            }
            $name = substr($real, strlen($prefix));
            $tables[$name] = preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1 ? $db->columns($name) : [];
        }
        ksort($tables);
        $settings = [];
        foreach ($db->select('SELECT k, v FROM {settings}') as $row) {
            $key = (string) $row['k'];
            if (!in_array($key, ['modules.active', 'plugins.reports', 'plugins.granted'], true)) {
                $settings[$key] = (string) $row['v'];
            }
        }
        ksort($settings);

        return ['tables' => $tables, 'settings' => $settings];
    }

    private function add(string $rule, string $level, string $message, string $file, int $line): void
    {
        $this->findings[] = ['rule' => $rule, 'level' => $level, 'message' => $message, 'file' => $file, 'line' => $line];
    }
}
