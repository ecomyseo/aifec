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

use Aifec\Db\Db;
use Aifec\Install\SiteFactory;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Kernel\Site;
use Aifec\Kernel\SiteResolver;

/**
 * Las demostraciones de un sitio maestro: crearlas en una subcarpeta (`/demos/<nombre>`), restablecerlas desde su paquete y
 * borrarlas. Cada demostración es un sitio más (SiteFactory), con su base de datos, sus medios y su persona del panel.
 *
 * El maestro apunta en su ajuste `sites.demos` qué paquete usa cada demostración, cómo está (en cola, en curso, hecha,
 * fallida con su motivo) y cuándo se restableció por última vez. Crear y restablecer nunca lanzan por un fallo de
 * funcionamiento: devuelven ['ok' => false, 'error' => …] y dejan el sistema limpio (un create que falla borra lo que
 * hubiera creado; un restablecimiento con un paquete roto no toca la demostración que funcionaba).
 */
final class Demos
{
    /** Ajuste del maestro con el registro de demostraciones. */
    public const SETTING = 'sites.demos';

    /** Ajuste del maestro que apaga el restablecimiento de cada noche. */
    public const NIGHTLY = 'sites.reset_nightly';

    /**
     * Ajuste del maestro: enseñar en la página pública de demostraciones el botón «Entrar al panel» y los datos de acceso.
     * De serie apagado: el panel de cada demostración sigue en su dirección (<demo>/admin), pero la página no lo anuncia.
     */
    public const SHOW_PANEL = 'sites.demos_show_panel';

    /** Minutos que una demostración puede estar «en curso» antes de darla por abandonada. */
    public const STALE_MINUTES = 30;

    /** @var App */
    private $app;

    /** @var string */
    private $packagesDir;

    /**
     * @param string|null $packagesDir carpeta de los paquetes; si no se dice, el ajuste «sites.packages_dir» del maestro
     *                                 (para quien guarda sus paquetes fuera de la instalación) o, por defecto, demos/
     */
    public function __construct(App $app, ?string $packagesDir = null)
    {
        $this->app = $app;
        $root = rtrim(str_replace('\\', '/', $app->root()), '/');
        if ($packagesDir === null) {
            $configured = trim($app->settings()->str('sites.packages_dir'));
            $packagesDir = $configured !== '' ? $configured : $root . '/demos';
        }
        $this->packagesDir = rtrim(str_replace('\\', '/', $packagesDir), '/');
    }

    /** ¿Es este el sitio maestro? (su config.php lleva 'master' => true) */
    public static function isMaster(App $app): bool
    {
        return (bool) $app->site()->get('master', false);
    }

    public function app(): App
    {
        return $this->app;
    }

    public function dataRoot(): string
    {
        return dirname($this->app->site()->dataDir);
    }

    /**
     * Los paquetes disponibles en demos/: nombre => package.json (más «preview»: si trae captura) o ['error' => motivo].
     *
     * @return array<string,array<string,mixed>>
     */
    public function packages(): array
    {
        return Package::available($this->packagesDir, $this->app->root());
    }

    public function packagesDir(): string
    {
        return $this->packagesDir;
    }

    // ------------------------------------------------------------------ registro

    /**
     * @return array<string,array<string,mixed>> identificador del sitio => ficha
     */
    public function registry(): array
    {
        $out = [];
        foreach ($this->app->settings()->arr(self::SETTING) as $id => $entry) {
            if (is_string($id) && is_array($entry) && SiteResolver::validId($id)) {
                $out[$id] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function entry(string $id): ?array
    {
        $registry = $this->registry();

        return isset($registry[$id]) ? $registry[$id] : null;
    }

    /**
     * @param array<string,mixed> $changes
     */
    private function touch(string $id, array $changes): void
    {
        $registry = $this->registry();
        $entry = isset($registry[$id]) ? $registry[$id] : [];
        $registry[$id] = $changes + ['updated_at' => $this->app->clock()->sql()] + $entry;
        $this->app->settings()->set(self::SETTING, $registry);
        $this->app->invalidate(['sites']);
    }

    private function forget(string $id): void
    {
        $registry = $this->registry();
        unset($registry[$id]);
        $this->app->settings()->set(self::SETTING, $registry);
        $this->app->invalidate(['sites']);
    }

    // ------------------------------------------------------------------ crear

    /**
     * Crea una demostración ahora mismo (la consola y el trabajo en segundo plano la usan).
     *
     * @param array<string,mixed> $spec package (nombre del paquete), slug (subcarpeta: /demos/<slug>), name (nombre de la tienda,
     *                                  opcional) y password (contraseña de demostración, opcional)
     *
     * @return array<string,mixed> ['ok' => true, 'id', 'url', 'admin_url', 'email', 'password', 'summary'] o ['ok' => false, 'error']
     */
    public function create(array $spec): array
    {
        try {
            $entry = $this->prepare($spec);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->touch($entry['id'], $entry + ['state' => 'running', 'action' => 'create', 'error' => '', 'created_at' => $this->app->clock()->sql(), 'last_reset_at' => null, 'job' => null]);

        return $this->execute($entry['id']);
    }

    /**
     * Lo mismo, pero en segundo plano: valida, apunta la demostración como «en cola» y encola el trabajo «sites.build».
     * Sin el módulo de tareas la hace ahora.
     *
     * @param array<string,mixed> $spec
     *
     * @return array<string,mixed> ['ok' => true, 'id', 'job'] o ['ok' => false, 'error']
     */
    public function queueCreate(array $spec): array
    {
        try {
            $entry = $this->prepare($spec);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->touch($entry['id'], $entry + ['state' => 'queued', 'action' => 'create', 'error' => '', 'created_at' => $this->app->clock()->sql(), 'last_reset_at' => null, 'job' => null]);

        return $this->enqueue($entry['id']);
    }

    /**
     * Valida lo pedido y devuelve la ficha de la demostración (nada escrito todavía).
     *
     * @param array<string,mixed> $spec
     *
     * @throws \InvalidArgumentException con el motivo
     *
     * @return array<string,mixed>
     */
    private function prepare(array $spec): array
    {
        if (!self::isMaster($this->app)) {
            throw new \InvalidArgumentException('Demos can only be created from the master site');
        }
        $slug = isset($spec['slug']) && is_string($spec['slug']) ? trim($spec['slug']) : '';
        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,28}[a-z0-9])?$/', $slug) !== 1) {
            throw new \InvalidArgumentException('Invalid folder name: use lowercase letters, digits and hyphens (30 characters at most)');
        }
        $package = isset($spec['package']) && is_string($spec['package']) ? trim($spec['package']) : '';
        $pkg = Package::load($this->packagesDir, $package, $this->app->root());
        $name = isset($spec['name']) && is_string($spec['name']) ? trim($spec['name']) : '';
        if ($name === '') {
            $store = $pkg->meta()['store'] ?? [];
            $name = is_array($store) && isset($store['name']) && is_string($store['name']) && trim($store['name']) !== '' ? trim($store['name']) : $pkg->title();
        }
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('The shop name is too long (120 characters at most)');
        }
        $password = isset($spec['password']) && is_string($spec['password']) && $spec['password'] !== '' ? $spec['password'] : DemoGuard::DEFAULT_PASSWORD;
        if (mb_strlen($password) < 10 || strlen($password) > 200) {
            throw new \InvalidArgumentException('The demo password needs at least 10 characters');
        }
        $id = 'demo-' . $slug;
        SiteResolver::forget();
        $path = '/demos/' . $slug;
        foreach (SiteResolver::all($this->dataRoot()) as $row) {
            if ($row['id'] === $id || $row['path'] === $path) {
                throw new \InvalidArgumentException('A site already uses that id or that folder');
            }
        }
        if ($this->entry($id) !== null && ($this->entry($id)['state'] ?? '') !== 'failed') {
            throw new \InvalidArgumentException('A demo with that folder already exists');
        }

        return ['id' => $id, 'slug' => $slug, 'package' => $package, 'name' => $name, 'password' => $password, 'public_admin' => !empty($spec['public_admin'])];
    }

    // ------------------------------------------------------------------ restablecer y borrar

    /**
     * Apunta en el registro una tienda de muestra que ya existe y que no salió de un paquete (por ejemplo, una montada a mano
     * con un catálogo que no puede viajar en un paquete). Sale en la página pública de demostraciones con su enlace a la
     * tienda y sin acceso público a su panel; el restablecimiento de cada noche no la toca (no hay paquete del que rehacerla).
     *
     * @param array<string,mixed> $spec id (el sitio), name, sector y description (opcionales)
     *
     * @return array<string,mixed> ['ok' => true, 'id', 'url'] o ['ok' => false, 'error']
     */
    public function adopt(array $spec): array
    {
        $id = isset($spec['id']) && is_string($spec['id']) ? trim($spec['id']) : '';
        if (!self::isMaster($this->app)) {
            return ['ok' => false, 'error' => 'Only the master site lists demos'];
        }
        if (!SiteResolver::validId($id) || $id === $this->app->site()->id) {
            return ['ok' => false, 'error' => 'That site is not a demo'];
        }
        SiteResolver::forget();
        $site = null;
        foreach (SiteResolver::all($this->dataRoot()) as $row) {
            if ($row['id'] === $id) {
                $site = SiteResolver::load($this->dataRoot(), $id, $row['path'], $row['host'] === '*' ? '' : $row['host']);
            }
        }
        if ($site === null || !$site->isDemo() || $site->get('master', false)) {
            return ['ok' => false, 'error' => 'That site is not a demo'];
        }
        $existing = $this->entry($id);
        if ($existing !== null && empty($existing['adopted'])) {
            return ['ok' => false, 'error' => 'That demo comes from a package and is already listed'];
        }
        $text = static function (string $key, int $max) use ($spec): string {
            return isset($spec[$key]) && is_scalar($spec[$key]) ? mb_substr(trim((string) $spec[$key]), 0, $max, 'UTF-8') : '';
        };
        $url = rtrim($site->url(), '/');
        $name = $text('name', 80);
        $this->touch($id, [
            'adopted' => true, 'state' => 'done', 'action' => 'adopt', 'error' => '', 'package' => '', 'password' => '',
            'slug' => basename((string) parse_url($url, PHP_URL_PATH)), 'url' => $url,
            'name' => $name !== '' ? $name : $id, 'sector' => $text('sector', 60), 'description' => $text('description', 240),
        ]);
        (new Owners($this->app))->syncSite($id);

        return ['ok' => true, 'id' => $id, 'url' => $url];
    }

    /**
     * Restablece una demostración: borra el sitio (su base y sus medios, y nada más) y lo vuelve a crear con el mismo
     * paquete, la misma subcarpeta y la misma contraseña. Si el paquete ya no vale, no se toca nada.
     *
     * @return array<string,mixed> como create()
     */
    public function reset(string $siteId): array
    {
        try {
            $this->assertDemo($siteId);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->touch($siteId, ['state' => 'running', 'action' => 'reset', 'error' => '']);

        return $this->execute($siteId);
    }

    /**
     * Lo mismo en segundo plano.
     *
     * @return array<string,mixed> ['ok' => true, 'id', 'job'] o ['ok' => false, 'error']
     */
    public function queueReset(string $siteId): array
    {
        try {
            $this->assertDemo($siteId);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->touch($siteId, ['state' => 'queued', 'action' => 'reset', 'error' => '']);

        return $this->enqueue($siteId);
    }

    /**
     * Borra una demostración (su sitio, sus medios y su ficha). Nunca el maestro ni un sitio que no sea una demostración.
     *
     * @return array<string,mixed> ['ok' => true, 'id'] o ['ok' => false, 'error']
     */
    public function delete(string $siteId): array
    {
        try {
            $this->assertDemo($siteId);
            $this->wipe($siteId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->forget($siteId);

        return ['ok' => true, 'id' => $siteId];
    }

    /**
     * Restablece todas las demostraciones, de una en una. Si una falla, sigue con las demás y anota el motivo de cada una.
     *
     * @return array{done:string[],failed:array<string,string>}
     */
    public function resetAll(): array
    {
        $this->sweep();
        $out = ['done' => [], 'failed' => []];
        $registry = $this->registry();
        $ids = array_keys($registry);
        sort($ids);
        foreach ($ids as $id) {
            if (!empty($registry[$id]['adopted'])) {
                // Una tienda apuntada a mano no tiene paquete del que rehacerla: no se toca.
                continue;
            }
            try {
                $result = $this->reset($id);
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'error' => $e->getMessage()];
            }
            if (!empty($result['ok'])) {
                $out['done'][] = $id;
            } else {
                $out['failed'][$id] = isset($result['error']) ? (string) $result['error'] : 'Unknown error';
            }
        }

        return $out;
    }

    /**
     * Cierra lo que se quedó «en curso» o «en cola» sin nadie detrás (un proceso que murió): pasa a «fallida» con su motivo.
     *
     * @return int cuántas ha cerrado
     */
    public function sweep(): int
    {
        $closed = 0;
        $limit = $this->app->clock()->now() - self::STALE_MINUTES * 60;
        foreach ($this->registry() as $id => $entry) {
            $state = isset($entry['state']) ? (string) $entry['state'] : '';
            if (($state === 'running' || $state === 'queued') && Clock::parse(isset($entry['updated_at']) ? (string) $entry['updated_at'] : '') < $limit) {
                $this->touch($id, ['state' => 'failed', 'error' => 'Interrupted: nothing finished it in ' . self::STALE_MINUTES . ' minutes']);
                ++$closed;
            }
        }

        return $closed;
    }

    // ------------------------------------------------------------------ el trabajo

    /**
     * Hace lo que dice la ficha de la demostración («create» o «reset») y la deja en «hecha» o «fallida» con su motivo.
     * Es lo que ejecuta el trabajo «sites.build».
     *
     * @return array<string,mixed> como create()
     */
    public function execute(string $id): array
    {
        $entry = $this->entry($id);
        if ($entry === null) {
            return ['ok' => false, 'error' => 'Unknown demo'];
        }
        $reset = isset($entry['action']) && $entry['action'] === 'reset';
        $this->touch($id, ['state' => 'running', 'error' => '']);
        $this->app->makeCurrent();
        try {
            // El paquete se comprueba ANTES de tocar nada: un paquete roto no destruye una demostración que funcionaba.
            Package::load($this->packagesDir, (string) $entry['package'], $this->app->root());
            if ($reset) {
                $this->assertDemo($id);
                $this->wipe($id);
            }
            $result = $this->materialize($entry);
        } catch (\Throwable $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            if ($e instanceof \Aifec\Modules\Themes\ThemeException) {
                $details = [];
                foreach ($e->report()['checks'] ?? [] as $check) {
                    if (empty($check['ok']) && ($check['level'] ?? '') === 'error') {
                        $details[] = (string) ($check['id'] ?? '') . ': ' . (string) ($check['message'] ?? '');
                    }
                }
                $message .= $details !== [] ? ': ' . implode('; ', array_slice($details, 0, 4)) : '';
            }
            $keepSite = $reset && $e instanceof DemoPackageException && $this->siteExists($id);
            // La excepción guarda en su traza las aplicaciones del sitio a medio crear: se suelta antes de borrar, o en Windows
            // la base de datos sigue abierta y la carpeta no se puede quitar.
            $e = null;
            gc_collect_cycles();
            // Un restablecimiento que falla ANTES de borrar deja la demostración como estaba; en cualquier otro fallo, nada a medias.
            if (!$keepSite) {
                $this->safeWipe($id);
            }
            $this->touch($id, ['state' => 'failed', 'error' => $message]);
            $result = ['ok' => false, 'error' => $message];
        } finally {
            // La aplicación en curso vuelve a ser la del maestro, que es la dueña de este objeto (y no una demostración).
            $this->app->makeCurrent();
        }
        if (!empty($result['ok'])) {
            $now = $this->app->clock()->sql();
            $this->touch($id, ['state' => 'done', 'error' => '', 'url' => $result['url'], 'last_reset_at' => $reset ? $now : (isset($entry['last_reset_at']) ? $entry['last_reset_at'] : null)]);
        }

        return $result;
    }

    /**
     * Crea el sitio de la demostración y le carga el paquete.
     *
     * @param array<string,mixed> $entry
     *
     * @return array<string,mixed>
     */
    private function materialize(array $entry): array
    {
        $root = $this->app->root();
        $pkg = Package::load($this->packagesDir, (string) $entry['package'], $root);
        $id = (string) $entry['id'];
        $slug = (string) $entry['slug'];
        $url = rtrim($this->app->site()->url(), '/') . '/demos/' . $slug;
        $password = (string) $entry['password'];
        $meta = $pkg->meta();
        $store = isset($meta['store']) && is_array($meta['store']) ? $meta['store'] : [];
        $created = SiteFactory::create($root, $this->dataRoot(), [
            'id' => $id, 'host' => $this->masterHost(), 'path' => '/demos/' . $slug, 'url' => $url,
            'locale' => $pkg->locale(), 'timezone' => $this->app->site()->timezone(),
            'db' => ['driver' => 'sqlite'], 'demo' => true, 'debug' => false,
            'shop_name' => (string) $entry['name'],
            'shop_email' => isset($store['email']) && is_string($store['email']) ? $store['email'] : '',
            'admin' => ['name' => 'Demo', 'email' => DemoGuard::email($url), 'password' => $password],
            'modules' => $pkg->modules($root),
            'public_dir' => $this->app->publicDir(),
        ]);
        $publicAdmin = !empty($entry['public_admin']);
        if ($publicAdmin) {
            SiteResolver::forget();
            $demoSite = SiteResolver::load($this->dataRoot(), $id);
            if ($demoSite === null) {
                throw new \RuntimeException('The new administration demo could not be loaded');
            }
            SiteFactory::writeConfig($demoSite->dataDir, array_replace($demoSite->config, ['public_admin_demo' => true]));
            SiteResolver::forget();
        }
        $summary = DemoBuilder::for($this->app, $this->packagesDir)->build($id, (string) $entry['package'], [
            'demo_password' => $password, 'name' => (string) $entry['name'], 'ai' => $publicAdmin ? [] : self::aiSetup($this->app),
        ]);
        $this->app->makeCurrent();
        // Los administradores del maestro entran en la demostración recién hecha con su misma cuenta (ver Owners).
        (new Owners($this->app))->syncSite($id);

        return [
            'ok' => true, 'id' => $id, 'url' => $created['url'], 'admin_url' => $created['admin_url'],
            'email' => DemoGuard::email($url), 'password' => $password, 'summary' => $summary,
        ];
    }

    /**
     * Borra el sitio de una demostración y sus medios. Solo un identificador «demo-…» que no sea el del maestro.
     *
     * @throws \InvalidArgumentException si no es una demostración
     * @throws \RuntimeException         si no se ha podido borrar
     */
    private function wipe(string $id): void
    {
        if (strpos($id, 'demo-') !== 0 || !SiteResolver::validId($id) || $id === $this->app->site()->id) {
            throw new \InvalidArgumentException('That site is not a demo');
        }
        // Nadie puede tener abierta la base de ese sitio: la aplicación en curso pasa a ser la del maestro y se recoge lo suelto
        // (en Windows, un fichero abierto no se borra).
        $this->app->makeCurrent();
        SiteResolver::forget();
        gc_collect_cycles();
        if ($this->siteExists($id)) {
            SiteFactory::delete($this->dataRoot(), $id);
        }
        $this->app->makeCurrent();
        $media = rtrim(str_replace('\\', '/', $this->app->publicDir()), '/') . '/media';
        if (!Tree::remove($media . '/' . $id, $media)) {
            throw new \RuntimeException('Could not remove the media folder of the demo');
        }
    }

    /** Borra sin lanzar (la limpieza de un fallo no puede ocultar el fallo). */
    private function safeWipe(string $id): void
    {
        try {
            $this->wipe($id);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'sites.wipe');
        }
    }

    /**
     * La doble comprobación de que un identificador es de una demostración y no el maestro: está en el registro del maestro,
     * y, si el sitio existe, su config.php dice que es demostración y no maestro.
     *
     * @throws \InvalidArgumentException
     */
    private function assertDemo(string $id): void
    {
        if ($id === $this->app->site()->id || !SiteResolver::validId($id) || strpos($id, 'demo-') !== 0) {
            throw new \InvalidArgumentException('That site is not a demo');
        }
        if (!self::isMaster($this->app) || $this->entry($id) === null) {
            throw new \InvalidArgumentException('That site is not a demo created here');
        }
        SiteResolver::forget();
        $site = SiteResolver::load($this->dataRoot(), $id);
        if ($site !== null && (!$site->isDemo() || $site->get('master', false))) {
            throw new \InvalidArgumentException('That site is not a demo');
        }
    }

    private function siteExists(string $id): bool
    {
        SiteResolver::forget();
        foreach (SiteResolver::all($this->dataRoot()) as $row) {
            if ($row['id'] === $id) {
                return true;
            }
        }

        return is_dir($this->dataRoot() . '/' . $id);
    }

    /** El dominio con el que está registrado el sitio maestro en sites.php («*» si responde a cualquiera). */
    private function masterHost(): string
    {
        foreach (SiteResolver::all($this->dataRoot()) as $row) {
            if ($row['id'] === $this->app->site()->id) {
                return $row['host'];
            }
        }

        return '*';
    }

    /**
     * @return array<string,mixed>
     */
    private function enqueue(string $id): array
    {
        if (!$this->app->has('jobs')) {
            return $this->execute($id) + ['job' => 0];
        }
        $job = (int) $this->app->service('jobs')->push('sites.build', ['id' => $id], ['unique' => 'sites.build:' . $id, 'max_attempts' => 1]);
        $this->touch($id, ['job' => $job]);

        return ['ok' => true, 'id' => $id, 'job' => $job];
    }

    // ------------------------------------------------------------------ lectura para el panel

    /**
     * Todos los sitios de la instalación (y las demostraciones que aún no tienen sitio) con lo que enseña el panel.
     *
     * @return array<int,array<string,mixed>>
     */
    public function sites(): array
    {
        $this->sweep();
        $registry = $this->registry();
        SiteResolver::forget();
        $rows = [];
        $seen = [];
        $since = $this->startOfToday();
        foreach (SiteResolver::all($this->dataRoot()) as $row) {
            $id = $row['id'];
            $site = SiteResolver::load($this->dataRoot(), $id, $row['path'], $row['host'] === '*' ? '' : $row['host']);
            if ($site === null) {
                continue;
            }
            $entry = isset($registry[$id]) ? $registry[$id] : null;
            $isMaster = $id === $this->app->site()->id;
            $url = $isMaster && $this->app->site()->get('url', '') === '' ? $this->app->site()->url() : $site->url();
            $seen[$id] = true;
            $rows[] = [
                'id' => $id, 'url' => $url, 'path' => $row['path'] === '' ? '/' : $row['path'],
                'master' => $isMaster, 'demo' => $site->isDemo(), 'name' => $entry !== null ? (string) ($entry['name'] ?? $id) : $id,
                'package' => $entry !== null ? (string) ($entry['package'] ?? '') : '',
                'state' => $entry !== null ? (string) ($entry['state'] ?? '') : '',
                'error' => $entry !== null ? (string) ($entry['error'] ?? '') : '',
                'last_reset_at' => $entry !== null && isset($entry['last_reset_at']) ? (string) $entry['last_reset_at'] : '',
                'orders_today' => $this->ordersToday($site, $isMaster, $since),
                'shop_url' => $url . '/', 'admin_url' => $url . '/admin',
                'exists' => true,
            ];
        }
        foreach ($registry as $id => $entry) {
            if (isset($seen[$id])) {
                continue;
            }
            $rows[] = [
                'id' => $id, 'url' => '', 'path' => '/demos/' . (string) ($entry['slug'] ?? ''), 'master' => false, 'demo' => true,
                'name' => (string) ($entry['name'] ?? $id), 'package' => (string) ($entry['package'] ?? ''),
                'state' => (string) ($entry['state'] ?? ''), 'error' => (string) ($entry['error'] ?? ''),
                'last_reset_at' => isset($entry['last_reset_at']) ? (string) $entry['last_reset_at'] : '',
                'orders_today' => null, 'shop_url' => '', 'admin_url' => '', 'exists' => false,
            ];
        }

        return $rows;
    }

    /**
     * Pedidos de hoy de un sitio, o null si no tiene la tabla de pedidos. Abre la base de ese sitio solo para contar.
     */
    private function ordersToday(Site $site, bool $isMaster, string $since): ?int
    {
        try {
            if ($isMaster) {
                $db = $this->app->db();
                if (!$db->tableExists('orders')) {
                    return null;
                }

                return (int) $db->value('SELECT COUNT(*) FROM {orders} WHERE created_at >= ?', [$since]);
            }
            $config = $site->get('db', []);
            if (!is_array($config) || !isset($config['driver'])) {
                return null;
            }
            if ($config['driver'] === 'sqlite') {
                $file = $site->path('db.sqlite');
                if (!is_file($file)) {
                    return null;
                }
                $config['path'] = $file;
            }
            $db = Db::connect($config);
            $count = $db->tableExists('orders') ? (int) $db->value('SELECT COUNT(*) FROM {orders} WHERE created_at >= ?', [$since]) : null;
            $db = null;
            gc_collect_cycles();

            return $count;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Medianoche de hoy (en la zona horaria del maestro) como fecha de base de datos, en UTC. */
    private function startOfToday(): string
    {
        $now = new \DateTimeImmutable('@' . $this->app->clock()->now());
        $local = $now->setTimezone(new \DateTimeZone($this->app->site()->timezone()))->setTime(0, 0, 0);

        return $this->app->clock()->sql($local->getTimestamp());
    }

    /** Los ajustes de IA que una demostración hereda del maestro al construirse (así no se queda sin IA cada noche). */
    public const AI_KEYS = ['ai.enabled', 'ai.economy.provider', 'ai.economy.model', 'ai.standard.provider', 'ai.standard.model', 'ai.advanced.provider', 'ai.advanced.model'];

    /** Las claves de los proveedores: van cifradas con la clave de cada sitio, así que se copian descifradas. */
    public const AI_SECRETS = ['ai.economy.key', 'ai.standard.key', 'ai.advanced.key'];

    /**
     * La configuración de IA del maestro, para dársela a una demostración que se construye: ajustes y claves. Vacío si el maestro
     * no tiene la IA encendida.
     *
     * @return array{settings:array<string,mixed>,secrets:array<string,string>}|array<empty>
     */
    public static function aiSetup(App $master): array
    {
        $settings = $master->settings();
        if (!$settings->bool('ai.enabled', false)) {
            return [];
        }
        $out = ['settings' => [], 'secrets' => []];
        foreach (self::AI_KEYS as $key) {
            $value = $settings->get($key);
            if ($value !== null) {
                $out['settings'][$key] = $value;
            }
        }
        foreach (self::AI_SECRETS as $key) {
            $plain = $master->secret($key);
            if ($plain !== '') {
                $out['secrets'][$key] = $plain;
            }
        }

        return $out;
    }
}
