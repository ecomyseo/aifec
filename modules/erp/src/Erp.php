<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;

/**
 * El servicio «erp»: da las piezas del módulo (conexiones, avisos, perfiles, exportación e importación) con una sola instancia cada una.
 */
final class Erp
{
    /** @var App */
    private $app;

    /** @var array<string,object> */
    private $parts = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function connections(): Connections
    {
        return $this->part('connections', Connections::class);
    }

    public function webhooks(): Webhooks
    {
        return $this->part('webhooks', Webhooks::class);
    }

    public function idempotency(): Idempotency
    {
        return $this->part('idempotency', Idempotency::class);
    }

    public function sources(): Sources
    {
        return $this->part('sources', Sources::class);
    }

    public function profiles(): Profiles
    {
        return $this->part('profiles', Profiles::class);
    }

    public function exporter(): Exporter
    {
        return $this->part('exporter', Exporter::class);
    }

    public function importer(): Importer
    {
        return $this->part('importer', Importer::class);
    }

    public function destinations(): Destinations
    {
        return $this->part('destinations', Destinations::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function part(string $key, string $class)
    {
        if (!isset($this->parts[$key])) {
            $this->parts[$key] = new $class($this->app);
        }

        return $this->parts[$key];
    }
}
