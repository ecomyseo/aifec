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
 * Ganchos: la forma de ampliar el sistema sin tocarlo.
 *
 *   on()/fire()       acciones: «ha pasado esto» (order.paid). No devuelven nada.
 *   filter()/apply()  filtros: «¿quieres cambiar este valor?» (listing.query). Devuelven el valor.
 *   slot()/render()   huecos de plantilla: trozos de HTML en un punto de la página (product.summary.after).
 *
 * Cada oyente queda atribuido al módulo que lo registró. Si un oyente lanza, se anota y se sigue:
 * un plugin que falla no tumba la tienda. Se mide el tiempo de cada módulo para el diagnóstico.
 *
 * Los ganchos «sellados» (seal) son los que protegen la única verdad —precio, impuestos, existencias,
 * plazos—: solo pueden engancharse los módulos con la capacidad declarada y concedida.
 */
final class Hooks
{
    /** @var array<string,array<int,array<int,array{0:callable,1:string}>>> nombre => prioridad => oyentes */
    private $listeners = [];

    /** @var array<string,bool> nombre => ya ordenado */
    private $sorted = [];

    /** @var string módulo que está registrando ahora mismo */
    private $currentModule = 'core';

    /** @var array<string,string> gancho sellado => capacidad que hace falta */
    private $sealed = [];

    /** @var array<string,array<string,bool>> módulo => capacidades concedidas */
    private $granted = [];

    /** @var array<string,float> módulo => segundos acumulados en sus oyentes */
    private $timings = [];

    /** @var array<int,array{module:string,hook:string,error:string}> */
    private $errors = [];

    /** @var callable|null recibe (módulo, gancho, excepción) */
    private $onError;

    public function setCurrentModule(string $module): void
    {
        $this->currentModule = $module;
    }

    public function currentModule(): string
    {
        return $this->currentModule;
    }

    public function onError(callable $handler): void
    {
        $this->onError = $handler;
    }

    /** Sella un gancho: solo los módulos con esa capacidad concedida pueden engancharse. */
    public function seal(string $hook, string $capability): void
    {
        $this->sealed[$hook] = $capability;
    }

    public function grant(string $module, string $capability): void
    {
        $this->granted[$module][$capability] = true;
    }

    public function on(string $hook, callable $listener, int $priority = 10): void
    {
        $this->add($hook, $listener, $priority);
    }

    public function filter(string $hook, callable $listener, int $priority = 10): void
    {
        $this->add($hook, $listener, $priority);
    }

    public function slot(string $hook, callable $listener, int $priority = 10): void
    {
        $this->add($hook, $listener, $priority);
    }

    /**
     * Dispara una acción. Los oyentes reciben los argumentos tal cual.
     *
     * @param mixed ...$args
     */
    public function fire(string $hook, ...$args): void
    {
        foreach ($this->ordered($hook) as $entry) {
            $t0 = microtime(true);
            try {
                $entry[0](...$args);
            } catch (\Throwable $e) {
                $this->failed($entry[1], $hook, $e);
            }
            $this->time($entry[1], $t0);
        }
    }

    /**
     * Pasa un valor por los filtros. Si un filtro lanza, se conserva el valor que había.
     *
     * @param mixed $value
     * @param mixed ...$args
     *
     * @return mixed
     */
    public function apply(string $hook, $value, ...$args)
    {
        foreach ($this->ordered($hook) as $entry) {
            $t0 = microtime(true);
            try {
                $value = $entry[0]($value, ...$args);
            } catch (\Throwable $e) {
                $this->failed($entry[1], $hook, $e);
            }
            $this->time($entry[1], $t0);
        }

        return $value;
    }

    /**
     * Pinta un hueco de plantilla: une lo que devuelve cada oyente (cada uno, ya escapado).
     *
     * @param mixed ...$args
     */
    public function render(string $hook, ...$args): string
    {
        $html = '';
        foreach ($this->ordered($hook) as $entry) {
            $t0 = microtime(true);
            try {
                $piece = $entry[0](...$args);
                if (is_string($piece)) {
                    $html .= $piece;
                }
            } catch (\Throwable $e) {
                $this->failed($entry[1], $hook, $e);
            }
            $this->time($entry[1], $t0);
        }

        return $html;
    }

    public function has(string $hook): bool
    {
        return isset($this->listeners[$hook]);
    }

    /**
     * Módulos enganchados a un gancho (para el diagnóstico y el validador del contrato).
     *
     * @return string[]
     */
    public function modulesOn(string $hook): array
    {
        $modules = [];
        foreach ($this->ordered($hook) as $entry) {
            $modules[$entry[1]] = true;
        }

        return array_keys($modules);
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * @return array<string,float> módulo => segundos
     */
    public function timings(): array
    {
        return $this->timings;
    }

    /**
     * @return array<int,array{module:string,hook:string,error:string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    private function add(string $hook, callable $listener, int $priority): void
    {
        if (isset($this->sealed[$hook]) && $this->currentModule !== 'core') {
            $capability = $this->sealed[$hook];
            if (empty($this->granted[$this->currentModule][$capability])) {
                $this->errors[] = [
                    'module' => $this->currentModule,
                    'hook' => $hook,
                    'error' => 'Sealed hook: capability "' . $capability . '" not granted',
                ];

                return;
            }
        }
        $this->listeners[$hook][$priority][] = [$listener, $this->currentModule];
        unset($this->sorted[$hook]);
    }

    /**
     * @return array<int,array{0:callable,1:string}>
     */
    private function ordered(string $hook): array
    {
        if (!isset($this->listeners[$hook])) {
            return [];
        }
        if (!isset($this->sorted[$hook])) {
            ksort($this->listeners[$hook]);
            $this->sorted[$hook] = true;
        }
        $flat = [];
        foreach ($this->listeners[$hook] as $group) {
            foreach ($group as $entry) {
                $flat[] = $entry;
            }
        }

        return $flat;
    }

    private function failed(string $module, string $hook, \Throwable $e): void
    {
        $this->errors[] = ['module' => $module, 'hook' => $hook, 'error' => get_class($e) . ': ' . $e->getMessage()];
        if ($this->onError !== null) {
            try {
                ($this->onError)($module, $hook, $e);
            } catch (\Throwable $ignored) {
                // El aviso de un fallo no puede provocar otro.
            }
        }
    }

    private function time(string $module, float $t0): void
    {
        $this->timings[$module] = (isset($this->timings[$module]) ? $this->timings[$module] : 0.0) + (microtime(true) - $t0);
    }
}
