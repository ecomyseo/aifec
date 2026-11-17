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

/**
 * Un sitio: una tienda independiente dentro de la instalación, con su carpeta de datos, su base de
 * datos, su tema y sus plugins. Una instalación sirve varios sitios por dominio o por prefijo de ruta.
 *
 * La carpeta de datos de un sitio contiene: config.php, db.sqlite (si usa SQLite), cache/, logs/,
 * private/ (descargas de pago, facturas, exportaciones) y tmp/.
 */
final class Site
{
    /** @var string identificador: letras minúsculas, cifras y guiones */
    public $id;

    /** @var string carpeta de datos del sitio, sin barra final */
    public $dataDir;

    /** @var array<string,mixed> contenido de config.php */
    public $config;

    /** @var string prefijo de ruta, «» o «/demos/moda» (sin barra final) */
    public $basePath;

    /** @var string dominio con el que se resolvió («» si se cargó por identificador) */
    public $host;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(string $id, string $dataDir, array $config, string $basePath = '', string $host = '')
    {
        $this->id = $id;
        $this->dataDir = rtrim(str_replace('\\', '/', $dataDir), '/');
        $this->config = $config;
        $this->basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
        $this->host = $host;
    }

    /**
     * Valor de config.php por ruta con puntos: get('db.driver').
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $value = $this->config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /** Ruta dentro de la carpeta de datos del sitio; crea la carpeta si se pide. */
    public function path(string $sub = '', bool $create = false): string
    {
        $path = $this->dataDir . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
        if ($create && !is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }

    /** Sufijo de los nombres de cookie: dos sitios del mismo dominio no comparten sesión ni carrito. */
    public function cookieSuffix(): string
    {
        return substr(md5($this->id), 0, 6);
    }

    /** Dirección pública del sitio sin barra final: «https://aifec.gmartos.es/demos/moda». */
    public function url(): string
    {
        $url = (string) $this->get('url', '');
        if ($url !== '') {
            return rtrim($url, '/');
        }

        return ($this->host !== '' ? 'http://' . $this->host : 'http://localhost') . $this->basePath;
    }

    public function isDemo(): bool
    {
        return (bool) $this->get('demo', false);
    }

    public function debug(): bool
    {
        return (bool) $this->get('debug', false);
    }

    /** Clave secreta del sitio, de la que se derivan firmas y cifrados. */
    public function secret(): string
    {
        return (string) $this->get('secret', '');
    }

    public function locale(): string
    {
        return (string) $this->get('locale', 'es');
    }

    public function timezone(): string
    {
        return (string) $this->get('timezone', 'Europe/Madrid');
    }

    /** Carpeta de los medios públicos de este sitio (dentro de la raíz web). */
    public function mediaDir(string $publicDir): string
    {
        $dir = (string) $this->get('media_dir', '');

        return $dir !== '' ? rtrim(str_replace('\\', '/', $dir), '/') : rtrim($publicDir, '/') . '/media/' . $this->id;
    }
}
