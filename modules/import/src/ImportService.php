<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Kernel\App;

/**
 * El servicio «import»: reúne las piezas de la migración (registro de lectores y escritores, conexiones, motor,
 * informe y equivalencias) y crea borradores. Lo usan el panel, la consola y el trabajo en segundo plano.
 */
final class ImportService
{
    /** @var App */
    private $app;

    /** @var Registry|null */
    private $registry;

    /** @var Runs|null */
    private $runs;

    /** @var Connections|null */
    private $connections;

    /** @var Runner|null */
    private $runner;

    /** @var Report|null */
    private $report;

    /** @var Equivalences|null */
    private $equivalences;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function registry(): Registry
    {
        if ($this->registry === null) {
            $this->registry = new Registry($this->app);
        }

        return $this->registry;
    }

    public function runs(): Runs
    {
        if ($this->runs === null) {
            $this->runs = new Runs($this->app);
        }

        return $this->runs;
    }

    public function connections(): Connections
    {
        if ($this->connections === null) {
            $this->connections = new Connections($this->app, $this->registry());
        }

        return $this->connections;
    }

    public function runner(): Runner
    {
        if ($this->runner === null) {
            $this->runner = new Runner($this->app, $this->runs(), $this->registry(), $this->connections());
        }

        return $this->runner;
    }

    public function report(): Report
    {
        if ($this->report === null) {
            $this->report = new Report($this->app, $this->runs());
        }

        return $this->report;
    }

    public function equivalences(): Equivalences
    {
        if ($this->equivalences === null) {
            $this->equivalences = new Equivalences($this->app);
        }

        return $this->equivalences;
    }

    /**
     * Crea una migración en borrador con sus datos de conexión (cifrados aparte) y sus opciones.
     *
     * @param array<string,mixed> $config    campos de la ficha del lector, con los secretos en claro
     * @param array<string,mixed> $db        datos de la base de datos (modo «db»)
     * @param array<string,mixed> $options   types, keep_urls, overwrite_local, import_images, shop, since…
     * @param array<string,mixed> $extra     label, dry_run, equivalences, user_id, source_key
     *
     * @throws \InvalidArgumentException si no hay lector para ese origen y modo
     */
    public function createDraft(string $source, string $mode, array $config, array $db, array $options, array $extra = []): int
    {
        $card = $this->registry()->reader($source, $mode);
        if ($card === null) {
            throw new \InvalidArgumentException('Unknown source');
        }
        $key = isset($extra['source_key']) ? (string) $extra['source_key'] : $this->sourceKey($mode, $config, $db);
        $id = $this->runs()->create([
            'source' => $source, 'mode' => $mode, 'source_key' => $key,
            'label' => isset($extra['label']) ? $extra['label'] : null,
            'options' => $options + ['keep_urls' => $this->app->settings()->bool('import.keep_urls', true),
                'overwrite_local' => $this->app->settings()->bool('import.overwrite_local', false),
                'import_images' => $this->app->settings()->bool('import.import_images', true)],
            'equivalences' => isset($extra['equivalences']) && is_array($extra['equivalences']) ? $extra['equivalences'] : [],
            'dry_run' => !empty($extra['dry_run']),
            'user_id' => isset($extra['user_id']) ? $extra['user_id'] : null,
        ]);
        $this->connections()->save($id, $config, $db);

        return $id;
    }

    /**
     * Identifica la tienda de origen sin credenciales: su dominio (API) o la base de datos y su prefijo (copia).
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $db
     */
    public function sourceKey(string $mode, array $config, array $db): string
    {
        if ($mode === 'db') {
            $key = (isset($db['name']) ? (string) $db['name'] : '') . '/' . (isset($config['prefix']) ? (string) $config['prefix'] : '');
        } else {
            $host = isset($config['url']) ? parse_url((string) $config['url'], PHP_URL_HOST) : null;
            $key = is_string($host) ? strtolower($host) : '';
        }

        return substr($key, 0, 60);
    }
}
