<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;

/**
 * El registro de acciones del copiloto: todo lo que se le puede pedir. Se arma con el filtro «copilot.actions» (ver el README
 * del módulo): las de serie entran por él igual que las de otro módulo o plugin.
 *
 * Una acción es un array:
 *   id           «orders.ship» (área.acción, en minúsculas)
 *   label        qué hace, en inglés y en pocas palabras (se traduce al enseñarla)
 *   description  una frase más larga (en inglés); es lo que lee un modelo para elegirla
 *   permission   el permiso que exige («orders.edit») o una función (App, array $args): string cuando depende de los datos
 *   params       [['name', 'type' (int|money|percent|string|bool|enum|date), 'required', 'enum', 'description'], …]
 *   one_of       opcional: [['price', 'delta', 'percent']] = de cada grupo hay que dar al menos un dato
 *   preview      function (App, array $args): array → ['summary', 'changes' => [['label', 'from', 'to'], …], 'count',
 *                'warnings' => [], 'errors' => [], 'choices' => [['param', 'value', 'label']], 'links' => [['label', 'url']]]
 *   apply        function (App, array $args): array → ['ok', 'message', 'undo' => datos o null]
 *   undo         opcional: function (App, array $undo): array → ['ok', 'message']
 *   examples     opcional: frases de ejemplo en inglés (se enseñan traducidas)
 *   understand   opcional: function (App, string $text, string $norm): ?array → ['args' => [...], 'score' => 0-100]
 *   readonly     opcional: true = solo informa (no se aplica)
 *   module       opcional: el módulo al que pertenece (solo se ofrece si está activo)
 */
final class Actions
{
    public const FILTER = 'copilot.actions';

    /** @var App */
    private $app;

    /** @var array<string,array<string,mixed>>|null */
    private $all;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Vuelve a armar el registro la próxima vez (por si un oyente nuevo entra después). */
    public function reset(): void
    {
        $this->all = null;
    }

    /**
     * @return array<string,array<string,mixed>> identificador => definición completa
     */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        $listed = $this->app->hooks()->apply(self::FILTER, [], $this->app);
        $all = [];
        foreach (is_array($listed) ? $listed : [] as $raw) {
            $def = is_array($raw) ? self::define($raw) : null;
            if ($def === null) {
                $this->app->log()->warning('Copilot: an action definition was ignored because it is not valid', ['id' => is_array($raw) && isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : '']);
                continue;
            }
            if (isset($all[$def['id']])) {
                $this->app->log()->warning('Copilot: the action «' . $def['id'] . '» is already registered; the later one was ignored');
                continue;
            }
            $all[$def['id']] = $def;
        }
        $this->all = $all;

        return $all;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $all = $this->all();

        return isset($all[$id]) ? $all[$id] : null;
    }

    /**
     * Una definición con todos sus campos, o null si no vale.
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,mixed>|null
     */
    public static function define(array $raw): ?array
    {
        $id = isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : '';
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $id) !== 1) {
            return null;
        }
        $label = isset($raw['label']) && is_string($raw['label']) ? trim($raw['label']) : '';
        $permission = isset($raw['permission']) ? $raw['permission'] : null;
        if ($label === '' || !((is_string($permission) && $permission !== '') || is_callable($permission))) {
            return null;
        }
        if (!isset($raw['preview'], $raw['apply']) || !is_callable($raw['preview']) || !is_callable($raw['apply'])) {
            return null;
        }
        $params = [];
        foreach (isset($raw['params']) && is_array($raw['params']) ? $raw['params'] : [] as $param) {
            $def = is_array($param) ? Params::define($param) : null;
            if ($def === null) {
                return null;
            }
            $params[] = $def;
        }
        $names = array_map(static function (array $p): string {
            return (string) $p['name'];
        }, $params);
        $oneOf = [];
        foreach (isset($raw['one_of']) && is_array($raw['one_of']) ? $raw['one_of'] : [] as $group) {
            $list = array_values(array_filter(is_array($group) ? $group : [], static function ($name) use ($names): bool {
                return is_string($name) && in_array($name, $names, true);
            }));
            if ($list !== []) {
                $oneOf[] = $list;
            }
        }
        $examples = [];
        foreach (isset($raw['examples']) && is_array($raw['examples']) ? $raw['examples'] : [] as $example) {
            if (is_string($example) && $example !== '') {
                $examples[] = $example;
            }
        }

        return [
            'id' => $id,
            'label' => $label,
            'description' => isset($raw['description']) && is_string($raw['description']) ? $raw['description'] : '',
            'permission' => $permission,
            'params' => $params,
            'one_of' => $oneOf,
            'preview' => $raw['preview'],
            'apply' => $raw['apply'],
            'undo' => isset($raw['undo']) && is_callable($raw['undo']) ? $raw['undo'] : null,
            'examples' => $examples,
            'understand' => isset($raw['understand']) && is_callable($raw['understand']) ? $raw['understand'] : null,
            'readonly' => !empty($raw['readonly']),
            'module' => isset($raw['module']) && is_string($raw['module']) ? $raw['module'] : '',
        ];
    }

    /**
     * El permiso que exige esta acción con estos datos.
     *
     * @param array<string,mixed> $def
     * @param array<string,mixed> $args
     */
    public function permission(array $def, array $args): string
    {
        $permission = $def['permission'];
        if (is_string($permission)) {
            return $permission;
        }
        try {
            $cap = call_user_func($permission, $this->app, $args);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'copilot permission ' . $def['id']);
            $cap = '';
        }

        return is_string($cap) && $cap !== '' ? $cap : 'copilot.use';
    }

    /**
     * ¿Puede quien ha entrado hacer esto?
     *
     * @param array<string,mixed> $def
     * @param array<string,mixed> $args
     */
    public function allowed(array $def, array $args): bool
    {
        return $this->app->auth()->can($this->permission($def, $args));
    }

    /**
     * Llama a la función de una acción (preview, apply o undo). Si lanza, la excepción sube: quien llama (el planificador o el
     * copiloto) la recoge, la apunta en el registro y la convierte en un aviso para la persona.
     *
     * @param array<string,mixed> $def
     * @param array<int,mixed>    $arguments
     *
     * @return array<string,mixed>|null
     */
    public function call(array $def, string $function, array $arguments)
    {
        if (!isset($def[$function]) || !is_callable($def[$function])) {
            return null;
        }
        $result = call_user_func_array($def[$function], array_merge([$this->app], $arguments));

        return is_array($result) ? $result : null;
    }
}
