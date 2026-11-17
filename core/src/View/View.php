<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\View;

/**
 * Busca y pinta plantillas.
 *
 * Nombres de plantilla:
 *   «product», «partials/product-card»   plantilla de la tienda: se busca en el tema activo, después en
 *                                        los temas de los que hereda y por último en el tema base.
 *   «catalog::admin/products»            plantilla de un módulo o plugin (modules/catalog/views/admin/products.php).
 *                                        Un tema puede sustituirla en templates/modules/catalog/admin/products.php.
 *   «core::admin/layout»                 plantilla del núcleo (core/views/admin/layout.php).
 */
final class View
{
    /** @var string[] carpetas templates/ del tema activo y de sus padres, por orden de búsqueda */
    private $themeDirs = [];

    /** @var array<string,string> espacio de nombres => carpeta views/ */
    private $namespaces = [];

    /** @var array<string,mixed> datos disponibles en todas las plantillas */
    private $shared = [];

    /** @var array<string,string[]> */
    private $stacks = [];

    /** @var array<string,string|false> nombre => fichero resuelto */
    private $resolved = [];

    /**
     * @param string[] $dirs carpetas templates/, de la más específica a la más general
     */
    public function setThemeDirs(array $dirs): void
    {
        $this->themeDirs = array_map(static function (string $d): string {
            return rtrim(str_replace('\\', '/', $d), '/');
        }, $dirs);
        $this->resolved = [];
    }

    /**
     * @return string[]
     */
    public function themeDirs(): array
    {
        return $this->themeDirs;
    }

    public function addNamespace(string $namespace, string $dir): void
    {
        $this->namespaces[$namespace] = rtrim(str_replace('\\', '/', $dir), '/');
        $this->resolved = [];
    }

    /**
     * @param mixed $value
     */
    public function share(string $key, $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @return mixed
     */
    public function shared(string $key)
    {
        return isset($this->shared[$key]) ? $this->shared[$key] : null;
    }

    public function exists(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        return $this->renderWith(new Template($this), $name, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function renderWith(Template $template, string $name, array $data): string
    {
        $file = $this->find($name);
        if ($file === null) {
            throw new \RuntimeException('Template not found: ' . $name);
        }

        return $template->run($file, $data + $this->shared);
    }

    public function push(string $stack, string $html): void
    {
        if (!isset($this->stacks[$stack]) || !in_array($html, $this->stacks[$stack], true)) {
            $this->stacks[$stack][] = $html;
        }
    }

    public function stack(string $name): string
    {
        return isset($this->stacks[$name]) ? implode("\n", $this->stacks[$name]) : '';
    }

    public function resetStacks(): void
    {
        $this->stacks = [];
    }

    public function find(string $name): ?string
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name] === false ? null : $this->resolved[$name];
        }
        $file = $this->locate($name);
        $this->resolved[$name] = $file === null ? false : $file;

        return $file;
    }

    private function locate(string $name): ?string
    {
        // Nombres limpios: letras, cifras, guiones y barras. Nada de «..» ni rutas absolutas.
        if (preg_match('#^(?:([a-z0-9-]+)::)?([a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*)$#', $name, $m) !== 1) {
            return null;
        }
        $namespace = $m[1];
        $path = $m[2];
        if ($namespace !== '') {
            foreach ($this->themeDirs as $dir) {
                $file = $dir . '/modules/' . $namespace . '/' . $path . '.php';
                if (is_file($file)) {
                    return $file;
                }
            }
            if (isset($this->namespaces[$namespace])) {
                $file = $this->namespaces[$namespace] . '/' . $path . '.php';
                if (is_file($file)) {
                    return $file;
                }
            }

            return null;
        }
        foreach ($this->themeDirs as $dir) {
            $file = $dir . '/' . $path . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
