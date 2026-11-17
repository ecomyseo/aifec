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

/**
 * Un paquete de demostración leído y comprobado: la carpeta `demos/<sector>/` con su package.json, sus ficheros de datos
 * y su carpeta media/. El formato está documentado en demos/_esquema/LEEME.md.
 *
 * `load()` lee todo, lo comprueba entero (PackageCheck) y lanza una DemoPackageException que dice qué fichero y qué
 * elemento fallan. Un paquete que se ha cargado sin lanzar es seguro de aplicar: ningún dato apunta a algo que no existe.
 */
final class Package
{
    /** Ficheros de datos que un paquete puede traer. */
    public const FILES = [
        'categories.json', 'brands.json', 'attributes.json', 'products.json', 'pages.json', 'posts.json',
        'menus.json', 'carriers.json', 'coupons.json', 'reviews.json',
    ];

    /** Prefijo de los códigos de barras inventados: el rango 29x de EAN-13 es de uso interno y no lo tiene ningún producto real. */
    public const GTIN_PREFIX = '299';

    /** Módulos que un paquete de demostración nunca puede activar. */
    public const FORBIDDEN_MODULES = ['sites', 'plugins'];

    /** Ajustes que un paquete no puede tocar (los pone el sistema en una demostración). */
    public const FORBIDDEN_SETTINGS = ['mail.', 'plugins.', 'modules.', 'sites.', 'payments.', 'api.', 'jobs.', 'seo.noindex_site', 'theme.active', 'theme.options'];

    /** @var string */
    private $name;

    /** @var string */
    private $dir;

    /** @var array<string,mixed> */
    private $meta = [];

    /** @var array<string,array<int|string,mixed>> */
    private $data = [];

    private function __construct(string $name, string $dir)
    {
        $this->name = $name;
        $this->dir = $dir;
    }

    /**
     * @param string $packagesDir carpeta que contiene los paquetes (demos/)
     * @param string $root        raíz de la instalación (para comprobar que existen el tema y los módulos)
     *
     * @throws DemoPackageException si el paquete no existe o no vale
     */
    public static function load(string $packagesDir, string $name, string $root): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $name) !== 1) {
            throw new DemoPackageException($name, ['the package name is not valid (lowercase letters, digits and hyphens)']);
        }
        $dir = rtrim(str_replace('\\', '/', $packagesDir), '/') . '/' . $name;
        if (!is_dir($dir) || !is_file($dir . '/package.json')) {
            throw new DemoPackageException($name, ['the package does not exist: demos/' . $name . '/package.json was not found']);
        }
        $package = new self($name, $dir);
        $problems = [];
        $meta = self::readJson($dir . '/package.json', 'package.json', $problems);
        if ($meta === null || !self::isMap($meta)) {
            $problems[] = 'package.json: it must contain a JSON object';
            throw new DemoPackageException($name, $problems);
        }
        $package->meta = $meta;
        $listed = isset($meta['files']) && is_array($meta['files']) ? $meta['files'] : [];
        if (!isset($meta['files']) || !is_array($meta['files'])) {
            $problems[] = 'package.json: «files» must be the list of data files the package brings';
        }
        foreach ($listed as $file) {
            if (!is_string($file) || !in_array($file, self::FILES, true)) {
                $problems[] = 'package.json: «files» lists «' . (is_string($file) ? $file : '?') . '», which is not a known data file';
                continue;
            }
            if (!is_file($dir . '/' . $file)) {
                $problems[] = 'package.json: «files» lists ' . $file . ' but the file is missing';
                continue;
            }
            $decoded = self::readJson($dir . '/' . $file, $file, $problems);
            if ($decoded !== null) {
                $package->data[$file] = $decoded;
            }
        }
        $problems = array_merge($problems, PackageCheck::run($package, $root));
        if ($problems !== []) {
            throw new DemoPackageException($name, $problems);
        }

        return $package;
    }

    /**
     * Lista de los paquetes de una carpeta (los que empiezan por «_» no cuentan): nombre => datos de package.json, o
     * ['error' => motivo] si no valen.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function available(string $packagesDir, string $root): array
    {
        $out = [];
        $dirs = glob(rtrim(str_replace('\\', '/', $packagesDir), '/') . '/*', GLOB_ONLYDIR);
        foreach (is_array($dirs) ? $dirs : [] as $dir) {
            $name = basename($dir);
            if ($name[0] === '_' || !is_file($dir . '/package.json')) {
                continue;
            }
            try {
                $package = self::load($packagesDir, $name, $root);
                $out[$name] = $package->meta() + ['preview' => $package->previewPath() !== null];
            } catch (DemoPackageException $e) {
                $out[$name] = ['error' => $e->getMessage()];
            }
        }
        ksort($out);

        return $out;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * @return array<string,mixed> el package.json
     */
    public function meta(): array
    {
        return $this->meta;
    }

    public function has(string $file): bool
    {
        return isset($this->data[$file]);
    }

    /**
     * Lo que trae un fichero de datos (lista vacía si el paquete no lo trae).
     *
     * @return array<int|string,mixed>
     */
    public function data(string $file): array
    {
        return isset($this->data[$file]) ? $this->data[$file] : [];
    }

    /** Idioma del paquete. */
    public function locale(): string
    {
        return isset($this->meta['locale']) && is_string($this->meta['locale']) ? $this->meta['locale'] : 'es';
    }

    public function title(): string
    {
        return isset($this->meta['name']) && is_string($this->meta['name']) ? $this->meta['name'] : $this->name;
    }

    /** Ruta de una imagen del paquete, o null si no existe (solo nombres de fichero, nunca rutas). */
    public function mediaPath(string $file): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/', $file) !== 1) {
            return null;
        }
        $path = $this->dir . '/media/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Captura de portada del paquete (preview.png), si la trae. */
    public function previewPath(): ?string
    {
        $path = $this->dir . '/preview.png';

        return is_file($path) ? $path : null;
    }

    /**
     * Módulos que la demostración necesita encendidos: los del paquete, los que piden sus datos y los que una demostración
     * lleva siempre (correo y tareas, que son esenciales, y el pago simulado). Sin repetidos y en un orden estable.
     *
     * @return string[]
     */
    public function modules(string $root): array
    {
        $list = [];
        foreach (isset($this->meta['modules']) && is_array($this->meta['modules']) ? $this->meta['modules'] : [] as $slug) {
            if (is_string($slug)) {
                $list[] = $slug;
            }
        }
        $needs = [
            'categories.json' => 'catalog', 'brands.json' => 'catalog', 'attributes.json' => 'catalog', 'products.json' => 'catalog',
            'pages.json' => 'cms', 'posts.json' => 'cms', 'menus.json' => 'cms', 'carriers.json' => 'shipping', 'coupons.json' => 'coupons',
        ];
        foreach ($needs as $file => $slug) {
            if ($this->has($file)) {
                $list[] = $slug;
            }
        }
        if (is_dir($this->dir . '/media')) {
            $list[] = 'media';
        }
        $list[] = 'themes';
        $list[] = 'orders';
        $list[] = 'payments-simulated';
        $clean = [];
        foreach (array_values(array_unique($list)) as $slug) {
            if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1 && is_file(rtrim(str_replace('\\', '/', $root), '/') . '/modules/' . $slug . '/module.json')) {
                $clean[] = $slug;
            }
        }
        sort($clean);

        return $clean;
    }

    /**
     * @param string[] $problems
     *
     * @return array<int|string,mixed>|null
     */
    private static function readJson(string $file, string $label, array &$problems): ?array
    {
        $text = (string) file_get_contents($file);
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = (string) substr($text, 3);
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            $problems[] = $label . ': it is not valid JSON (' . json_last_error_msg() . ')';

            return null;
        }

        return $data;
    }

    /**
     * @param array<int|string,mixed> $value
     */
    private static function isMap(array $value): bool
    {
        return $value === [] || !array_is_list($value);
    }
}
