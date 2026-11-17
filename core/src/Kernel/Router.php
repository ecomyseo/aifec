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
 * Rutas fijas del sistema (panel, carrito, API…). Las direcciones amigables de productos, categorías,
 * páginas y entradas NO están aquí: se resuelven después en la tabla de direcciones (Slugs).
 *
 *   $router->get('/carrito', [CartController::class, 'show'])->name('cart');
 *   $router->group(['prefix' => '/admin', 'middleware' => ['admin']], function (Router $r): void { … });
 */
final class Router
{
    /** @var array<string,array<string,Route>> método => ruta exacta => Route */
    private $static = [];

    /** @var Route[] rutas con parámetros, por orden de alta */
    private $dynamic = [];

    /** @var array<string,Route> */
    private $named = [];

    /** @var string */
    private $prefix = '';

    /** @var string[] */
    private $groupMiddleware = [];

    /** @var string módulo que está registrando */
    private $module = 'core';

    /** @var Route[]|null mientras se graba (ver record()), las rutas dadas de alta, por orden */
    private $recording;

    public function setModule(string $module): void
    {
        $this->module = $module;
    }

    /**
     * @param callable|array{0:string,1:string} $handler
     */
    public function get(string $pattern, $handler): Route
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    /**
     * @param callable|array{0:string,1:string} $handler
     */
    public function post(string $pattern, $handler): Route
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    /**
     * @param callable|array{0:string,1:string} $handler
     */
    public function any(string $pattern, $handler): Route
    {
        return $this->add(['GET', 'HEAD', 'POST'], $pattern, $handler);
    }

    /**
     * @param string[]                           $methods
     * @param callable|array{0:string,1:string} $handler
     */
    public function add(array $methods, string $pattern, $handler): Route
    {
        $pattern = $this->prefix . '/' . ltrim($pattern, '/');
        $route = new Route(array_map('strtoupper', $methods), $pattern, $handler);
        $route->module = $this->module;
        $route->middleware = $this->groupMiddleware;
        $this->place($route);
        if ($this->recording !== null) {
            $this->recording[] = $route;
        }
        // El nombre se registra al vuelo cuando se pide (ver named()), porque name() se llama después de add().

        return $route;
    }

    /**
     * Empieza a grabar las rutas que se den de alta (para compilar las de un routes.php en la caché de arranque).
     */
    public function record(): void
    {
        $this->recording = [];
    }

    /**
     * Deja de grabar y devuelve las rutas dadas de alta desde record(), por orden.
     *
     * @return Route[]
     */
    public function recorded(): array
    {
        $routes = $this->recording !== null ? $this->recording : [];
        $this->recording = null;

        return $routes;
    }

    /**
     * Da de alta rutas compiladas (lo que dio Route::export()), en el mismo orden en que se grabaron: el resultado es el
     * mismo que ejecutar el routes.php que las declaró, sin ejecutarlo.
     *
     * @param array<int,array<int,mixed>> $rows
     */
    public function import(array $rows): void
    {
        foreach ($rows as $row) {
            $route = Route::import($row);
            $this->place($route);
            if ($this->recording !== null) {
                $this->recording[] = $route;
            }
        }
        $this->named = [];
    }

    private function place(Route $route): void
    {
        if ($route->regex === null) {
            foreach ($route->methods as $method) {
                $this->static[$method][$route->pattern] = $route;
            }
        } else {
            $this->dynamic[] = $route;
        }
    }

    /**
     * @param array{prefix?:string,middleware?:string[]} $attrs
     */
    public function group(array $attrs, callable $define): void
    {
        $prevPrefix = $this->prefix;
        $prevMiddleware = $this->groupMiddleware;
        if (isset($attrs['prefix'])) {
            $this->prefix .= '/' . trim($attrs['prefix'], '/');
        }
        if (isset($attrs['middleware'])) {
            $this->groupMiddleware = array_values(array_unique(array_merge($this->groupMiddleware, $attrs['middleware'])));
        }
        $define($this);
        $this->prefix = $prevPrefix;
        $this->groupMiddleware = $prevMiddleware;
    }

    /**
     * Busca la ruta de una petición.
     *
     * @return array{route:Route,params:array<string,string>}|null null si ninguna ruta tiene esa dirección
     *
     * @throws HttpError 405 si la dirección existe pero no con ese método
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = $path === '/' ? '/' : rtrim($path, '/');
        if (isset($this->static[$method][$path])) {
            return ['route' => $this->static[$method][$path], 'params' => []];
        }
        $otherMethod = false;
        foreach ($this->dynamic as $route) {
            if (preg_match((string) $route->regex, $path, $m) !== 1) {
                continue;
            }
            if (!in_array($method, $route->methods, true)) {
                $otherMethod = true;
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return ['route' => $route, 'params' => $params];
        }
        if (!$otherMethod) {
            foreach ($this->static as $routes) {
                if (isset($routes[$path])) {
                    $otherMethod = true;
                    break;
                }
            }
        }
        if ($otherMethod) {
            throw new HttpError(405, 'Method not allowed');
        }

        return null;
    }

    /**
     * Ruta (sin dominio ni prefijo de sitio) de una ruta con nombre.
     *
     * @param array<string,string|int> $params los que no estén en el patrón van como parámetros de la URL
     */
    public function path(string $name, array $params = []): string
    {
        $route = $this->named($name);
        if ($route === null) {
            throw new \InvalidArgumentException('Unknown route name: ' . $name);
        }
        $used = [];
        $path = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^{}]+(?:\{[^{}]*\}[^{}]*)*)?\}/',
            static function (array $m) use ($params, &$used, $name): string {
                if (!array_key_exists($m[1], $params)) {
                    throw new \InvalidArgumentException('Missing parameter "' . $m[1] . '" for route ' . $name);
                }
                $used[$m[1]] = true;

                return implode('/', array_map('rawurlencode', explode('/', (string) $params[$m[1]])));
            },
            $route->pattern
        );
        $extra = array_diff_key($params, $used);

        return $path . ($extra !== [] ? '?' . http_build_query($extra) : '');
    }

    public function has(string $name): bool
    {
        return $this->named($name) !== null;
    }

    /**
     * @return Route[]
     */
    public function all(): array
    {
        $all = $this->dynamic;
        foreach ($this->static as $routes) {
            foreach ($routes as $route) {
                if (!in_array($route, $all, true)) {
                    $all[] = $route;
                }
            }
        }

        return $all;
    }

    private function named(string $name): ?Route
    {
        if (isset($this->named[$name])) {
            return $this->named[$name];
        }
        foreach ($this->all() as $route) {
            if ($route->name !== '') {
                $this->named[$route->name] = $route;
            }
        }

        return isset($this->named[$name]) ? $this->named[$name] : null;
    }
}
