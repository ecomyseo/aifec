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

/** Una ruta fija: métodos, patrón, quién la atiende, nombre e intermediarios. */
final class Route
{
    /** @var string[] */
    public $methods;

    /** @var string «/admin/products/{id:\d+}» */
    public $pattern;

    /** @var callable|array{0:string,1:string} [Clase, 'método'] o una función */
    public $handler;

    /** @var string */
    public $name = '';

    /** @var string[] */
    public $middleware = [];

    /** @var string módulo que la registró */
    public $module = 'core';

    /** @var string|null expresión regular (solo si el patrón tiene parámetros) */
    public $regex;

    /**
     * @param string[]                           $methods
     * @param callable|array{0:string,1:string} $handler
     */
    public function __construct(array $methods, string $pattern, $handler)
    {
        $this->methods = $methods;
        $this->pattern = $pattern === '/' ? '/' : rtrim($pattern, '/');
        $this->handler = $handler;
        if (strpos($pattern, '{') !== false) {
            $regex = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^{}]+(?:\{[^{}]*\}[^{}]*)*))?\}/',
                static function (array $m): string {
                    return '(?P<' . $m[1] . '>' . (isset($m[2]) && $m[2] !== '' ? $m[2] : '[^/]+') . ')';
                },
                $this->pattern
            );
            $this->regex = '#^' . $regex . '$#u';
        }
    }

    /**
     * ¿Se puede guardar en la caché de arranque? Solo si quien la atiende es [Clase, 'método']: una función anónima
     * no se puede escribir en un fichero, y la ruta se vuelve a registrar en cada petición ejecutando su routes.php.
     */
    public function compilable(): bool
    {
        return is_array($this->handler) && count($this->handler) === 2
            && isset($this->handler[0], $this->handler[1]) && is_string($this->handler[0]) && is_string($this->handler[1]);
    }

    /**
     * La ruta como datos, para la caché de arranque (solo si compilable()).
     *
     * @return array{0:string[],1:string,2:array{0:string,1:string},3:string,4:string[],5:string,6:string|null}
     */
    public function export(): array
    {
        if (!$this->compilable()) {
            throw new \LogicException('A route with a closure handler cannot be compiled: ' . $this->pattern);
        }

        return [$this->methods, $this->pattern, [(string) $this->handler[0], (string) $this->handler[1]], $this->name, $this->middleware, $this->module, $this->regex];
    }

    /**
     * La ruta a partir de lo que dio export(), sin volver a construir su expresión regular.
     *
     * @param array<int,mixed> $data
     */
    public static function import(array $data): self
    {
        $route = new self([], '/', null);
        $route->methods = (array) $data[0];
        $route->pattern = (string) $data[1];
        $route->handler = (array) $data[2];
        $route->name = (string) $data[3];
        $route->middleware = (array) $data[4];
        $route->module = (string) $data[5];
        $route->regex = $data[6] !== null ? (string) $data[6] : null;

        return $route;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function middleware(string ...$names): self
    {
        foreach ($names as $name) {
            if (!in_array($name, $this->middleware, true)) {
                $this->middleware[] = $name;
            }
        }

        return $this;
    }
}
