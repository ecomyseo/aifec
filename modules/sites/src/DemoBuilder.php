<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Http\HttpClient;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\SiteResolver;
use Aifec\Modules\Themes\ThemeException;

/**
 * Carga un paquete de demostración EN UN SITIO (arranca la `App` de ese sitio; nunca la del sitio maestro).
 *
 * Orden: módulos, ajustes, tema y opciones, medios, impuestos y envíos, atributos, marcas, categorías, productos con
 * variantes e imágenes, páginas (una de portada), entradas, menús, cupones y valoraciones; después reindexa el buscador
 * y genera los feeds si el sitio los tiene. Repetirlo sobre un sitio ya construido no duplica nada.
 *
 * En un sitio de demostración, además: correo retenido, solo el pago simulado, «noindex» en todo el sitio y la persona
 * del panel con el papel «demo» (ver DemoGuard).
 *
 * Mientras construye, la aplicación va en modo silencioso: no sale ni se guarda ningún correo.
 */
final class DemoBuilder
{
    /** @var string */
    private $root;

    /** @var string */
    private $dataRoot;

    /** @var string */
    private $publicDir;

    /** @var string */
    private $packagesDir;

    /** @var Clock|null */
    private $clock;

    /** @var HttpClient|null */
    private $http;

    public function __construct(string $root, string $dataRoot, string $publicDir, ?string $packagesDir = null, ?Clock $clock = null, ?HttpClient $http = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');
        $this->publicDir = rtrim(str_replace('\\', '/', $publicDir), '/');
        $this->packagesDir = rtrim(str_replace('\\', '/', $packagesDir !== null ? $packagesDir : $this->root . '/demos'), '/');
        $this->clock = $clock;
        $this->http = $http;
    }

    /** El constructor de demos de una instalación, con los datos de la aplicación del sitio maestro. */
    public static function for(App $master, ?string $packagesDir = null): self
    {
        return new self($master->root(), dirname($master->site()->dataDir), $master->publicDir(), $packagesDir, $master->clock(), $master->http());
    }

    /**
     * Carga un paquete en un sitio.
     *
     * @param array<string,mixed> $options demo_password (contraseña de la persona de demostración), name (nombre de la tienda)
     *
     * @throws DemoPackageException      si el paquete no vale (no se ha tocado el sitio)
     * @throws \InvalidArgumentException si el sitio no existe o es el maestro
     * @throws \Throwable                lo que falle al cargar (el sitio puede haber quedado a medias: quien lo creó lo borra)
     *
     * @return array<string,mixed> site, package, counts (lo que tiene el sitio), created (lo que ha creado esta vez), demo, warnings
     */
    public function build(string $siteId, string $package, array $options = []): array
    {
        $pkg = Package::load($this->packagesDir, $package, $this->root);
        SiteResolver::forget();
        $site = SiteResolver::load($this->dataRoot, $siteId);
        if ($site === null) {
            throw new \InvalidArgumentException('Unknown site');
        }
        if ($site->get('master', false)) {
            throw new \InvalidArgumentException('A demo is never built on the master site');
        }

        $previous = App::hasCurrent() ? App::current() : null;
        $appOptions = ['public_dir' => $this->publicDir];
        if ($this->clock !== null) {
            $appOptions['clock'] = $this->clock;
        }
        if ($this->http !== null) {
            $appOptions['http'] = $this->http;
        }
        $app = new App($site, $this->root, $appOptions);
        $app->makeCurrent();
        try {
            return $this->run($app, $pkg, $options);
        } finally {
            if ($previous !== null) {
                $previous->makeCurrent();
            } else {
                App::clearCurrent();
            }
            $app = null;
            gc_collect_cycles();
        }
    }

    /**
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    private function run(App $app, Package $pkg, array $options): array
    {
        $warnings = [];
        $silent = $app->silence(true);
        try {
            // 1. Módulos y 2. ajustes: antes de arrancar, para que el sitio arranque ya con lo elegido.
            $app->migrate(false);
            $app->settings()->set('modules.active', $pkg->modules($this->root));
            if ($app->site()->isDemo() && $app->site()->get('public_admin_demo', false)) {
                $active = $app->settings()->arr('modules.active');
                // Los ajustes de cobro se pueden evaluar; Payments mantiene el pago externo cerrado en todas las demos.
                foreach (['subscriptions', 'payments-paypal', 'payments-redsys', 'payments-transfer', 'payments-cod'] as $module) {
                    if (is_file($this->root . '/modules/' . $module . '/module.json')) {
                        $active[] = $module;
                    }
                }
                if (in_array('search', $active, true) && in_array('ai', $active, true)
                    && is_file($this->root . '/modules/search-learning/module.json')) {
                    $active[] = 'search-learning';
                }
                $app->settings()->set('modules.active', array_values(array_unique($active)));
            }
            $this->settings($app, $pkg, $options);
            $demo = null;
            if ($app->site()->isDemo()) {
                $password = isset($options['demo_password']) && is_string($options['demo_password']) && $options['demo_password'] !== ''
                    ? $options['demo_password'] : DemoGuard::DEFAULT_PASSWORD;
                $demo = DemoGuard::apply($app, $password) + ['password' => $password];
                if ($app->site()->get('public_admin_demo', false)) {
                    DemoAdministration::apply($app, $password);
                }
            }
            // La IA del maestro (proveedores, modelos y claves), si la demostración lleva el módulo: se rehace cada noche y sin esto
            // se quedaba sin IA. El gasto lo limita DemoGuard (topes fijos de demostración).
            $ai = isset($options['ai']) && is_array($options['ai']) ? $options['ai'] : [];
            if (!$app->site()->get('public_admin_demo', false) && $ai !== [] && in_array('ai', (array) $app->settings()->get('modules.active', []), true)) {
                foreach (isset($ai['settings']) && is_array($ai['settings']) ? $ai['settings'] : [] as $key => $value) {
                    $app->settings()->set((string) $key, $value);
                }
                foreach (isset($ai['secrets']) && is_array($ai['secrets']) ? $ai['secrets'] : [] as $key => $plain) {
                    $app->setSecret((string) $key, (string) $plain);
                }
            }
            $app->boot();

            // 3. Tema y opciones. Si el tema no se deja activar todavía (la tienda está vacía), se reintenta al final.
            $retryTheme = $this->theme($app, $pkg, $warnings);

            // 4 a 11. Medios, envíos, catálogo, contenidos, cupones y valoraciones.
            $mediaIds = [];
            $media = function (string $file, string $alt) use ($app, $pkg, &$mediaIds): int {
                if (!isset($mediaIds[$file])) {
                    $path = $pkg->mediaPath($file);
                    if ($path === null) {
                        throw new \RuntimeException('The image «' . $file . '» does not exist in the package media folder');
                    }
                    $row = $app->service('media')->store($path, $file, ['alt' => $alt]);
                    $mediaIds[$file] = (int) $row['id'];
                }

                return $mediaIds[$file];
            };
            $catalog = new CatalogLoader($app, $pkg, $media);
            $content = new ContentLoader($app, $pkg, $catalog, $media);
            $commerce = new CommerceLoader($app, $pkg, $catalog);
            $commerce->loadShipping();
            $catalog->load();
            $content->load();
            $commerce->loadMarketing();
            $warnings = array_merge($warnings, $commerce->warnings());
            if ($app->site()->isDemo() && ($app->site()->get('public_admin_demo', false) || $app->settings()->bool('cms.demo_legal_pages', false)) && $app->has('cms')) {
                $legal = new \Aifec\Modules\Cms\Legal($app);
                $legal->create();
                $legal->refresh(['legal-notice', 'privacy']);
                foreach ($legal->status() as $page) {
                    if ($page['id'] > 0 && $page['status'] !== 'published') {
                        $app->db()->update('pages', ['status' => 'published', 'updated_at' => $app->clock()->sql()], ['id' => $page['id']]);
                        $app->hooks()->fire('content.saved', 'page', $page['id']);
                    }
                }
                $app->invalidate(['cms', 'pages']);
            }
            if ($app->site()->isDemo() && $app->site()->get('public_admin_demo', false) && $app->has('subscriptions')) {
                $app->settings()->set('subscriptions.enabled', true);
                $app->settings()->set('subscriptions.provider', 'simulated');
                $subscriptions = $app->service('subscriptions');
                if ($subscriptions->plans(true) === []) {
                    $subscriptions->savePlan([
                        'name' => t('Example monthly service'), 'amount' => 1000, 'active' => 1,
                        'description' => t('Fictitious plan for testing monthly payments. No real service is provided and no money is charged.'),
                    ]);
                }
                $menus = $app->service('cms')->menus();
                $items = $menus->editable('main');
                $path = $app->url()->plain('/suscripciones/planes');
                $present = false;
                foreach ($items as $item) {
                    $present = $present || ($item['type'] === 'url' && $item['url'] === $path);
                }
                if (!$present) {
                    $items[] = ['type' => 'url', 'url' => $path, 'labels' => ['es' => 'Servicios mensuales', 'en' => 'Monthly services']];
                    $menus->save('main', t('Main menu'), $items);
                }
            }

            // Lo que va detrás: buscador, feeds, y el tema que quedó pendiente.
            $indexed = $this->reindex($app);
            $warnings = array_merge($warnings, $this->feeds($app));
            if ($retryTheme) {
                $this->activateTheme($app, (string) $pkg->meta()['theme']);
            }
            $app->cache()->flush();

            return [
                'site' => $app->site()->id,
                'package' => $pkg->name(),
                'counts' => $this->counts($app, count($mediaIds)),
                'created' => $catalog->created() + $content->created() + $commerce->created(),
                'indexed' => $indexed,
                'demo' => $demo,
                'warnings' => $warnings,
            ];
        } finally {
            $app->silence($silent);
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    private function settings(App $app, Package $pkg, array $options): void
    {
        $settings = $app->settings();
        $meta = $pkg->meta();
        $store = isset($meta['store']) && is_array($meta['store']) ? $meta['store'] : [];
        $map = [
            'name' => 'store.name', 'email' => 'store.email', 'phone' => 'store.phone', 'address' => 'store.address', 'legal_name' => 'store.legal_name',
            'currency' => 'shop.currency', 'currency_symbol' => 'shop.currency_symbol', 'country' => 'tax.base_country',
            'prices_include_tax' => 'tax.prices_include_tax', 'display_with_tax' => 'tax.display_with_tax',
        ];
        foreach ($map as $from => $key) {
            if (array_key_exists($from, $store) && $store[$from] !== null) {
                $settings->set($key, $store[$from]);
            }
        }
        if (isset($options['name']) && is_string($options['name']) && trim($options['name']) !== '') {
            $settings->set('store.name', trim($options['name']));
        }
        if (!$settings->has('i18n.languages')) {
            $settings->set('i18n.languages', [$pkg->locale()]);
        }
        foreach (isset($meta['settings']) && is_array($meta['settings']) ? $meta['settings'] : [] as $key => $value) {
            $settings->set((string) $key, $value);
        }
    }

    /**
     * @param string[] $warnings
     *
     * @return bool true si la activación del tema ha de repetirse al final
     */
    private function theme(App $app, Package $pkg, array &$warnings): bool
    {
        $meta = $pkg->meta();
        $slug = (string) $meta['theme'];
        $options = isset($meta['theme_options']) && is_array($meta['theme_options']) ? $meta['theme_options'] : [];
        if (!$app->has('themes')) {
            $app->settings()->set('theme.active', $slug);

            return false;
        }
        $retry = false;
        try {
            $app->service('themes')->activate($slug);
        } catch (ThemeException $e) {
            $retry = true;
        }
        if ($options !== []) {
            try {
                foreach ($app->service('themes')->saveOptions($slug, $options) as $key => $error) {
                    $warnings[] = 'The theme option «' . $key . '» was not saved: ' . $error;
                }
            } catch (ThemeException $e) {
                $warnings[] = 'The theme options were not saved: ' . $e->getMessage();
            }
        }

        return $retry;
    }

    private function activateTheme(App $app, string $slug): void
    {
        $app->service('themes')->activate($slug);
    }

    private function reindex(App $app): int
    {
        if (!$app->has('search')) {
            return 0;
        }

        return (int) $app->service('search')->reindexNow();
    }

    /**
     * @return string[] avisos
     */
    private function feeds(App $app): array
    {
        if (!$app->has('semantic') || !$app->has('jobs')) {
            return [];
        }
        try {
            $app->service('jobs')->runTask('semantic.feeds');
        } catch (\Throwable $e) {
            return ['The product feeds were not generated: ' . $e->getMessage()];
        }

        return [];
    }

    /**
     * Lo que tiene el sitio al terminar (cuentas de la base de datos, no de lo que se ha tocado).
     *
     * @return array<string,int>
     */
    private function counts(App $app, int $media): array
    {
        $db = $app->db();
        $out = [];
        $tables = ['products' => 'products', 'variants' => 'variants', 'categories' => 'categories', 'brands' => 'brands', 'attributes' => 'attributes',
            'pages' => 'pages', 'posts' => 'posts', 'menus' => 'menus', 'carriers' => 'carriers', 'coupons' => 'coupons', 'orders' => 'orders'];
        foreach ($tables as $key => $table) {
            if ($db->tableExists($table)) {
                $out[$key] = (int) $db->value('SELECT COUNT(*) FROM {' . $table . '}');
            }
        }
        $out['media_used'] = $media;

        return $out;
    }
}
