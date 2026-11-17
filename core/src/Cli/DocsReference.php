<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Cli;

use Aifec\Db\Db;
use Aifec\Kernel\App;

/**
 * Referencia para desarrolladores generada desde lo que el propio sistema declara: órdenes de consola, ajustes, rutas,
 * permisos, ganchos y tablas. No se escribe a mano ni se queda vieja: `php aifec docs:reference`.
 *
 * Un método estático por tabla; cada uno devuelve filas (datos) y no escribe nada. Después markdown() las convierte en
 * una tabla y write() deja los ficheros. Ninguna salida lleva fecha ni hora: dos pasadas sobre el mismo código dan
 * ficheros idénticos byte a byte.
 */
final class DocsReference
{
    /** Carpetas del código que se analizan para los ganchos. */
    public const DEFAULT_FOLDERS = ['core', 'modules', 'plugins', 'themes'];

    /** Nombre de un gancho: «area.algo», con puntos (las demás cadenas que pasan por `on`, `render`… no son ganchos). */
    private const HOOK_NAME = '/^[a-z][a-z0-9_-]*(\.[a-z0-9_*-]+)+$/i';

    /** Carpetas que nunca se recorren. */
    private const SKIP_DIRS = ['tests', 'storage', 'sites', 'node_modules', 'vendor'];

    /** Quien dispara un gancho (y qué clase de gancho es). */
    private const DISPATCH = ['fire' => 'action', 'apply' => 'filter', 'render' => 'slot'];

    /** Quien se engancha a uno. */
    private const LISTEN = ['on', 'filter', 'slot'];

    /** Tipos de campo cuyo valor por defecto no se enseña nunca. */
    private const SECRET_TYPES = ['secret', 'password'];

    /** Tipos de campo que no guardan nada. */
    private const NO_VALUE_TYPES = ['heading', 'html'];

    /** Órdenes que Console atiende por su cuenta (no salen de commands()). */
    private const BUILTIN_COMMANDS = [
        'assets:publish' => 'Copies static assets to the public folder',
        'help' => 'Lists the available commands',
        'sites' => 'Lists the sites of this installation',
        'version' => 'Shows the version',
    ];

    // ------------------------------------------------------------------ consola

    /**
     * Una fila por orden de consola: name, help y module (quién la declara).
     *
     * @return array<int,array{name:string,help:string,module:string}>
     */
    public static function commands(App $app): array
    {
        $owners = [];
        foreach ($app->modules()->definitions('cli') as $id => $definition) {
            foreach (is_array($definition) ? array_keys($definition) : [] as $name) {
                $owners[(string) $name] = (string) $id;
            }
        }
        $found = [];
        foreach (self::BUILTIN_COMMANDS as $name => $help) {
            $found[$name] = ['help' => $help, 'module' => 'core'];
        }
        $global = class_exists('Aifec\\Install\\Commands') ? \Aifec\Install\Commands::all() : [];
        foreach ((new Console(false))->commands($app) + $global as $name => $command) {
            $found[(string) $name] = [
                'help' => is_array($command) && isset($command['help']) ? (string) $command['help'] : '',
                // Lo que no viene del cli.php de un módulo es del núcleo: las de Console y las del instalador.
                'module' => isset($owners[(string) $name]) ? $owners[(string) $name] : 'core',
            ];
        }
        ksort($found, SORT_STRING);
        $rows = [];
        foreach ($found as $name => $info) {
            $rows[] = ['name' => (string) $name, 'help' => $info['help'], 'module' => $info['module']];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ ajustes

    /**
     * Una fila por ajuste declarado en los settings.php de lo cargado.
     *
     * @return array<int,array{key:string,module:string,tab:string,type:string,default:string,label:string}>
     */
    public static function settings(App $app): array
    {
        return self::settingRows($app->modules()->definitions('settings'));
    }

    /**
     * Las filas de ajustes de unas definiciones (módulo => contenido de su settings.php).
     *
     * @param array<string,mixed> $definitions
     *
     * @return array<int,array{key:string,module:string,tab:string,type:string,default:string,label:string}>
     */
    public static function settingRows(array $definitions): array
    {
        $rows = [];
        foreach ($definitions as $module => $definition) {
            $tabs = is_array($definition) && isset($definition['tabs']) && is_array($definition['tabs']) ? $definition['tabs'] : [];
            foreach ($tabs as $tabKey => $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $tabLabel = isset($tab['label']) ? (string) $tab['label'] : (string) $tabKey;
                foreach (isset($tab['fields']) && is_array($tab['fields']) ? $tab['fields'] : [] as $key => $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $type = isset($field['type']) ? (string) $field['type'] : 'text';
                    if (in_array($type, self::NO_VALUE_TYPES, true) || (array_key_exists('store', $field) && $field['store'] === false)) {
                        continue;
                    }
                    $rows[] = [
                        'key' => (string) $key,
                        'module' => (string) $module,
                        'tab' => $tabLabel,
                        'type' => $type,
                        'default' => self::shortDefault($type, $field),
                        'label' => isset($field['label']) ? (string) $field['label'] : '',
                    ];
                }
            }
        }
        usort($rows, static function (array $a, array $b): int {
            $c = strcmp($a['module'], $b['module']);

            return $c !== 0 ? $c : strcmp($a['key'], $b['key']);
        });

        return $rows;
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function shortDefault(string $type, array $field): string
    {
        // Un secreto no enseña nada, ni siquiera el valor de fábrica.
        if (in_array($type, self::SECRET_TYPES, true)) {
            return '(secret)';
        }
        if (!array_key_exists('default', $field)) {
            return '';
        }
        $json = json_encode($field['default'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }

        return mb_strlen($json) > 60 ? mb_substr($json, 0, 57) . '…' : $json;
    }

    // ------------------------------------------------------------------ rutas

    /**
     * Una fila por ruta registrada en el enrutador ya montado.
     *
     * @return array<int,array{methods:string[],path:string,name:string,middleware:string[],area:string}>
     */
    public static function routes(App $app): array
    {
        $rows = [];
        foreach ($app->router()->all() as $route) {
            $path = (string) $route->pattern;
            $area = 'front';
            if ($path === '/admin' || strncmp($path, '/admin/', 7) === 0) {
                $area = 'admin';
            } elseif ($path === '/api' || strncmp($path, '/api/', 5) === 0) {
                $area = 'api';
            }
            $rows[] = [
                'methods' => array_values(array_map('strval', $route->methods)),
                'path' => $path,
                'name' => (string) $route->name,
                'middleware' => array_values(array_map('strval', $route->middleware)),
                'area' => $area,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            foreach (['area', 'path'] as $key) {
                $c = strcmp($a[$key], $b[$key]);
                if ($c !== 0) {
                    return $c;
                }
            }
            $c = strcmp(implode(',', $a['methods']), implode(',', $b['methods']));

            return $c !== 0 ? $c : strcmp($a['name'], $b['name']);
        });

        return $rows;
    }

    // ------------------------------------------------------------------ permisos

    /**
     * Los permisos que existen y dónde se piden: los de los recursos, pantallas de ajustes, menús y paneles declarados en
     * los admin.php y settings.php, más los de los papeles sembrados.
     *
     * @return array<int,array{capability:string,module:string,used_by:string[]}>
     */
    public static function capabilities(App $app): array
    {
        $uses = [];
        $add = static function (string $cap, string $by) use (&$uses): void {
            if (!isset($uses[$cap])) {
                $uses[$cap] = [];
            }
            $uses[$cap][$by] = true;
        };

        foreach ($app->adminDefinitions() as $module => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $module = (string) $module;
            foreach (isset($definition['caps']) && is_array($definition['caps']) ? array_keys($definition['caps']) : [] as $cap) {
                if (self::isCap((string) $cap)) {
                    $add((string) $cap, 'declared in ' . $module);
                }
            }
            foreach (isset($definition['resources']) && is_array($definition['resources']) ? $definition['resources'] : [] as $name => $resource) {
                if (!is_array($resource)) {
                    continue;
                }
                $by = 'resource ' . $name;
                $base = isset($resource['cap']) && is_string($resource['cap']) && $resource['cap'] !== '' ? $resource['cap'] : $module;
                $overrides = isset($resource['caps']) && is_array($resource['caps']) ? $resource['caps'] : [];
                foreach (['view', 'edit'] as $ability) {
                    $cap = isset($overrides[$ability]) ? (string) $overrides[$ability] : $base . '.' . $ability;
                    if (self::isCap($cap)) {
                        $add($cap, $by);
                    }
                }
                foreach ($overrides as $cap) {
                    if (is_string($cap) && self::isCap($cap)) {
                        $add($cap, $by);
                    }
                }
                unset($resource['cap'], $resource['caps']);
                self::walkCaps($resource, $by, $add);
            }
            foreach ($definition as $section => $content) {
                if (!in_array($section, ['caps', 'resources'], true) && is_array($content)) {
                    self::walkCaps($content, $section . ' ' . $module, $add);
                }
            }
        }
        foreach ($app->modules()->definitions('settings') as $module => $definition) {
            if (is_array($definition) && isset($definition['tabs'])) {
                $cap = isset($definition['cap']) ? (string) $definition['cap'] : 'settings.edit';
                if (self::isCap($cap)) {
                    $add($cap, 'settings ' . $module);
                }
            }
        }
        // Los papeles sembrados: lo que se les da, con su comodín si lo llevan («*», «orders.*»).
        $roles = $app->db()->select('SELECT code, caps FROM {roles} WHERE ' . $app->db()->ident('system') . ' = 1 ORDER BY code');
        foreach ($roles as $role) {
            $caps = json_decode((string) $role['caps'], true);
            foreach (is_array($caps) ? $caps : [] as $cap) {
                if (is_string($cap) && ($cap === '*' || self::isCap($cap))) {
                    $add($cap, 'role ' . $role['code']);
                }
            }
        }

        ksort($uses, SORT_STRING);
        $rows = [];
        foreach ($uses as $cap => $by) {
            $list = array_keys($by);
            sort($list, SORT_STRING);
            if (count($list) > 8) {
                $more = count($list) - 7;
                $list = array_slice($list, 0, 7);
                $list[] = '… +' . $more;
            }
            $dot = strpos((string) $cap, '.');
            $rows[] = ['capability' => (string) $cap, 'module' => $dot === false ? (string) $cap : substr((string) $cap, 0, $dot), 'used_by' => $list];
        }

        return $rows;
    }

    private static function isCap(string $cap): bool
    {
        return preg_match('/^[a-z0-9_-]+\.[a-z0-9_.*-]+$/i', $cap) === 1;
    }

    /**
     * Recorre una definición y apunta cada «cap» con punto que encuentra.
     *
     * @param array<mixed> $node
     */
    private static function walkCaps(array $node, string $by, callable $add): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'cap' && is_string($value)) {
                if (self::isCap($value)) {
                    $add($value, $by);
                }
                continue;
            }
            if (is_array($value)) {
                self::walkCaps($value, $by, $add);
            }
        }
    }

    // ------------------------------------------------------------------ ganchos

    /**
     * Análisis estático de los ganchos: no ejecuta nada. Recorre los .php con token_get_all, así que un nombre dentro de
     * un comentario o de una cadena no cuenta.
     *
     *   - se dispara con fire (acción), apply (filtro) o render (hueco); se escucha con on, filter o slot; se sella con seal;
     *   - solo cuentan los nombres literales con forma de gancho («area.algo»);
     *   - un nombre que se monta con una variable o una constante va a la fila «dynamic» (solo si la llamada es sobre hooks()).
     *
     * @param string[] $folders carpetas del proyecto a recorrer (relativas a $root)
     *
     * @return array<int,array{hook:string,kind:string,fired_in:string[],listened_in:string[],sealed:string}>
     */
    public static function hooks(string $root, array $folders = self::DEFAULT_FOLDERS): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $names = [];
        $dynamicFired = [];
        $dynamicListened = [];
        $methods = array_merge(array_keys(self::DISPATCH), self::LISTEN, ['seal']);
        foreach (self::phpFiles($root, $folders) as $rel) {
            $code = file_get_contents($root . '/' . $rel);
            if (!is_string($code) || $code === '') {
                continue;
            }
            foreach (self::calls($code, $methods) as $call) {
                $where = $rel . ':' . $call['line'];
                $method = $call['method'];
                if ($call['kind'] === 'dynamic') {
                    if (!$call['hooks']) {
                        continue;
                    }
                    if (isset(self::DISPATCH[$method])) {
                        $dynamicFired[] = $where;
                    } elseif (in_array($method, self::LISTEN, true)) {
                        $dynamicListened[] = $where;
                    }
                    continue;
                }
                $name = $call['name'];
                if (preg_match(self::HOOK_NAME, $name) !== 1) {
                    continue;
                }
                if (!isset($names[$name])) {
                    $names[$name] = ['dispatch' => [], 'fired_in' => [], 'listened_in' => [], 'sealed' => ''];
                }
                if ($method === 'seal') {
                    $names[$name]['sealed'] = $call['second'];
                } elseif (isset(self::DISPATCH[$method])) {
                    $names[$name]['dispatch'][$method] = true;
                    $names[$name]['fired_in'][] = $where;
                } else {
                    $names[$name]['listened_in'][] = $where;
                }
            }
        }
        ksort($names, SORT_STRING);
        $rows = [];
        foreach ($names as $name => $info) {
            $kind = 'unknown';
            foreach (['fire', 'apply', 'render'] as $method) {
                if (isset($info['dispatch'][$method])) {
                    $kind = self::DISPATCH[$method];
                    break;
                }
            }
            $rows[] = ['hook' => (string) $name, 'kind' => $kind, 'fired_in' => $info['fired_in'], 'listened_in' => $info['listened_in'], 'sealed' => $info['sealed']];
        }
        if ($dynamicFired !== [] || $dynamicListened !== []) {
            $rows[] = ['hook' => 'dynamic', 'kind' => 'dynamic', 'fired_in' => $dynamicFired, 'listened_in' => $dynamicListened, 'sealed' => ''];
        }

        return $rows;
    }

    /**
     * Los .php de unas carpetas, como rutas relativas a la raíz y por orden. Nunca tests/, storage/ ni sites/.
     *
     * @param string[] $folders
     *
     * @return string[]
     */
    private static function phpFiles(string $root, array $folders): array
    {
        $files = [];
        foreach ($folders as $folder) {
            $folder = trim(str_replace('\\', '/', (string) $folder), '/');
            if ($folder !== '' && is_dir($root . '/' . $folder)) {
                self::collect($root, $folder, $files);
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param string[] $files
     */
    private static function collect(string $root, string $rel, array &$files): void
    {
        $items = scandir($root . '/' . $rel);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $rel . '/' . $item;
            if (is_dir($root . '/' . $path)) {
                if (!in_array($item, self::SKIP_DIRS, true) && !is_link($root . '/' . $path)) {
                    self::collect($root, $path, $files);
                }
            } elseif (substr($item, -4) === '.php') {
                $files[] = $path;
            }
        }
    }

    /**
     * Las llamadas «->método(» de un código, con token_get_all.
     *
     * kind = literal si el primer argumento es una cadena sola (sin concatenar); dynamic si es una variable, una constante
     * o una cadena que se sigue montando; cualquier otra cosa (una función, un array) no es un nombre y no sale.
     * hooks = la llamada es sobre hooks(), $hooks o ->hooks.
     *
     * @param string[] $methods
     *
     * @return array<int,array{method:string,line:int,kind:string,name:string,second:string,hooks:bool}>
     */
    private static function calls(string $code, array $methods): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            if (!is_array($tokens[$i])) {
                continue;
            }
            if ($tokens[$i][0] === T_OBJECT_OPERATOR) {
                $m = self::nextToken($tokens, $i + 1, $count);
                if ($m === null || !is_array($tokens[$m]) || $tokens[$m][0] !== T_STRING || !in_array(strtolower($tokens[$m][1]), $methods, true)) {
                    continue;
                }
                $method = strtolower($tokens[$m][1]);
                $onHooks = self::onHooks($tokens, $i);
            } elseif ($tokens[$i][0] === T_STRING && strtolower($tokens[$i][1]) === 'hook' && in_array('render', $methods, true) && self::isFunctionCall($tokens, $i)) {
                // El ayudante hook('nombre') de las plantillas pinta un hueco: es lo mismo que hooks()->render().
                $m = $i;
                $method = 'render';
                $onHooks = true;
            } else {
                continue;
            }
            $open = self::nextToken($tokens, $m + 1, $count);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            $a = self::nextToken($tokens, $open + 1, $count);
            if ($a === null) {
                continue;
            }
            $first = $tokens[$a];
            $kind = '';
            $name = '';
            $second = '';
            if (is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING) {
                $after = self::nextToken($tokens, $a + 1, $count);
                if ($after !== null && ($tokens[$after] === ',' || $tokens[$after] === ')')) {
                    $kind = 'literal';
                    $name = self::unquote($first[1]);
                    if ($tokens[$after] === ',') {
                        $b = self::nextToken($tokens, $after + 1, $count);
                        if ($b !== null && is_array($tokens[$b]) && $tokens[$b][0] === T_CONSTANT_ENCAPSED_STRING) {
                            $second = self::unquote($tokens[$b][1]);
                        }
                    }
                } else {
                    $kind = 'dynamic';
                }
            } elseif ((is_array($first) && in_array($first[0], [T_VARIABLE, T_STRING, T_NS_SEPARATOR], true)) || $first === '"') {
                $kind = 'dynamic';
            }
            if ($kind === '') {
                continue;
            }
            $out[] = [
                'method' => $method,
                'line' => (int) $tokens[$m][2],
                'kind' => $kind,
                'name' => $name,
                'second' => $second,
                'hooks' => $onHooks,
            ];
        }

        return $out;
    }

    /**
     * ¿La palabra de la posición $at es una llamada a función suelta (no un método, ni una declaración)?
     *
     * @param array<int,mixed> $tokens
     */
    private static function isFunctionCall(array $tokens, int $at): bool
    {
        $p = self::prevToken($tokens, $at - 1);
        if ($p !== null && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            return false;
        }
        $n = self::nextToken($tokens, $at + 1, count($tokens));

        return $n !== null && $tokens[$n] === '(';
    }

    /**
     * ¿El «->» de la posición $arrow cuelga de hooks(), de $hooks o de ->hooks?
     *
     * @param array<int,mixed> $tokens
     */
    private static function onHooks(array $tokens, int $arrow): bool
    {
        $p = self::prevToken($tokens, $arrow - 1);
        if ($p === null) {
            return false;
        }
        if ($tokens[$p] === ')') {
            $q = self::prevToken($tokens, $p - 1);
            $r = $q !== null && $tokens[$q] === '(' ? self::prevToken($tokens, $q - 1) : null;

            return $r !== null && is_array($tokens[$r]) && $tokens[$r][0] === T_STRING && strtolower($tokens[$r][1]) === 'hooks';
        }

        return is_array($tokens[$p]) && (($tokens[$p][0] === T_STRING && strtolower($tokens[$p][1]) === 'hooks') || ($tokens[$p][0] === T_VARIABLE && $tokens[$p][1] === '$hooks'));
    }

    /**
     * @param array<int,mixed> $tokens
     */
    private static function nextToken(array $tokens, int $from, int $count): ?int
    {
        for ($i = $from; $i < $count; ++$i) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $tokens
     */
    private static function prevToken(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; --$i) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    private static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);
        if ($literal[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }

        return stripcslashes($body);
    }

    // ------------------------------------------------------------------ tablas

    /**
     * Una fila por tabla de la base del sitio (sin el prefijo): table, module (quién la crea) y columns (del esquema real).
     *
     * @return array<int,array{table:string,module:string,columns:string[]}>
     */
    public static function tables(App $app): array
    {
        $db = $app->db();
        $owners = self::tableOwners($app);
        $rows = [];
        foreach (self::tableNames($db) as $name) {
            $rows[] = ['table' => $name, 'module' => isset($owners[$name]) ? $owners[$name] : '', 'columns' => $db->columns($name)];
        }

        return $rows;
    }

    /**
     * @return string[] nombres sin prefijo, por orden
     */
    private static function tableNames(Db $db): array
    {
        $real = $db->driver() === 'sqlite'
            ? $db->column("SELECT name FROM sqlite_master WHERE type = 'table'")
            : $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $prefix = $db->prefix();
        $names = [];
        foreach ($real as $name) {
            $name = (string) $name;
            if (strncmp($name, $prefix, strlen($prefix)) === 0 && strncmp($name, 'sqlite_', 7) !== 0) {
                $names[] = substr($name, strlen($prefix));
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Quién crea cada tabla: se recorren los migrations/ de cada ámbito buscando «->create('nombre'». La tabla migrations
     * la crea el propio Migrator del núcleo, no un fichero de migraciones.
     *
     * @return array<string,string> tabla => ámbito
     */
    private static function tableOwners(App $app): array
    {
        $owners = ['migrations' => 'core'];
        $modules = $app->modules();
        foreach (array_merge(['core'], array_keys($modules->all())) as $id) {
            $path = $modules->path((string) $id);
            $files = $path !== null ? glob($path . '/migrations/*.php') : [];
            $files = is_array($files) ? $files : [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $code = file_get_contents($file);
                foreach (is_string($code) ? self::calls($code, ['create']) : [] as $call) {
                    if ($call['kind'] === 'literal' && $call['name'] !== '' && !isset($owners[$call['name']])) {
                        $owners[$call['name']] = (string) $id;
                    }
                }
            }
        }

        return $owners;
    }

    // ------------------------------------------------------------------ Markdown y ficheros

    /**
     * Una tabla Markdown con su título, su introducción y el aviso de que está generada. Las celdas se escapan (barras
     * verticales, signos < y >, saltos de línea) y las listas se unen con <br>.
     *
     * @param array<int|string,string>        $columns clave de la fila => encabezado (o una lista de claves, que valen de encabezado)
     * @param array<int,array<string,mixed>> $rows
     */
    public static function markdown(string $title, string $intro, array $columns, array $rows): string
    {
        $keys = [];
        $heads = [];
        foreach ($columns as $key => $label) {
            $keys[] = is_int($key) ? (string) $label : (string) $key;
            $heads[] = self::cell((string) $label);
        }
        $out = '# ' . $title . "\n\n";
        if (trim($intro) !== '') {
            $out .= rtrim($intro) . "\n\n";
        }
        $out .= "Generado con `php aifec docs:reference`. No lo edites a mano.\n\n";
        $out .= '| ' . implode(' | ', $heads) . " |\n";
        $out .= '| ' . implode(' | ', array_fill(0, max(1, count($keys)), '---')) . " |\n";
        foreach ($rows as $row) {
            $cells = [];
            foreach ($keys as $key) {
                $cells[] = self::cell(is_array($row) && array_key_exists($key, $row) ? $row[$key] : '');
            }
            $out .= '| ' . implode(' | ', $cells) . " |\n";
        }

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function cell($value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::escape(self::text($item));
            }

            return implode('<br>', $parts);
        }

        return self::escape(self::text($value));
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        if (is_bool($value)) {
            return $value ? 'sí' : 'no';
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return $value === null ? '' : (string) $value;
    }

    private static function escape(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(['\\', '|', '<', '>', "\n"], ['\\\\', '\\|', '&lt;', '&gt;', '<br>'], $text);

        return $text;
    }

    /**
     * Escribe en $dir (la crea) los seis ficheros de la referencia y su índice.
     *
     * @return array{files:array<string,int>,dir:string} filas de cada fichero y la carpeta
     *
     * @throws \RuntimeException si no se puede escribir
     */
    public static function write(App $app, string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '') {
            throw new \RuntimeException('Empty output folder');
        }
        if (file_exists($dir) && !is_dir($dir)) {
            throw new \RuntimeException('Not a folder: ' . $dir);
        }
        // Se comprueba el resultado justo después: si no se puede crear, se lanza la excepción.
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the folder ' . $dir);
        }

        $routes = [];
        foreach (self::routes($app) as $row) {
            // HEAD acompaña siempre a GET: no aporta nada a quien lee la referencia.
            $methods = in_array('GET', $row['methods'], true) ? array_values(array_diff($row['methods'], ['HEAD'])) : $row['methods'];
            $routes[] = ['methods' => implode(', ', $methods)] + $row;
        }
        $tables = [];
        foreach (self::tables($app) as $row) {
            $tables[] = ['columns' => implode(', ', $row['columns'])] + $row;
        }

        $docs = [
            'consola.md' => ['Órdenes de consola', 'Todas las órdenes de `php aifec <orden>` de lo que hay cargado y el módulo que declara cada una. Las que no son del núcleo salen del `cli.php` de su módulo o plugin.',
                ['name' => 'Orden', 'help' => 'Qué hace', 'module' => 'Módulo'], self::commands($app)],
            'ajustes.md' => ['Ajustes', 'Los ajustes que declaran los `settings.php`, con su pestaña, su tipo y su valor de fábrica. Un secreto no enseña nunca su valor. Los campos que no guardan nada (`heading`, `html`, `store => false`) no salen.',
                ['key' => 'Clave', 'module' => 'Módulo', 'tab' => 'Pestaña', 'type' => 'Tipo', 'default' => 'Por defecto', 'label' => 'Etiqueta'], self::settings($app)],
            'rutas.md' => ['Rutas', 'Las rutas fijas registradas en el enrutador, por área (`admin`, `api` o `front`). Las direcciones amigables de productos, categorías y páginas no están aquí: se resuelven después en la tabla de direcciones. A `GET` le acompaña siempre `HEAD`.',
                ['area' => 'Área', 'methods' => 'Métodos', 'path' => 'Ruta', 'name' => 'Nombre', 'middleware' => 'Intermediarios'], $routes],
            'permisos.md' => ['Permisos', 'Los permisos que existen y dónde se piden: recursos, pantallas de ajustes, menús y paneles de los `admin.php` y `settings.php`, y los que se dan a los papeles sembrados. `*` es todo y `area.*` es toda un área.',
                ['capability' => 'Permiso', 'module' => 'Área', 'used_by' => 'Dónde se pide'], self::capabilities($app)],
            'ganchos.md' => ['Ganchos', 'Análisis estático del código (no ejecuta nada): `fire` dispara una acción, `apply` pasa un valor por los filtros y `render` pinta un hueco; `on`, `filter` y `slot` se enganchan a uno; `seal` lo sella con una capacidad. Solo salen los nombres literales; los que se montan con una variable van a la fila `dynamic`.',
                ['hook' => 'Gancho', 'kind' => 'Tipo', 'fired_in' => 'Se dispara en', 'listened_in' => 'Se escucha en', 'sealed' => 'Sellado con'], self::hooks($app->root())],
            'tablas.md' => ['Tablas', 'Las tablas de la base del sitio (sin el prefijo) con el módulo que las crea y sus columnas reales.',
                ['table' => 'Tabla', 'module' => 'Módulo', 'columns' => 'Columnas'], $tables],
        ];

        $files = [];
        $index = [];
        foreach ($docs as $name => $doc) {
            self::store($dir, $name, self::markdown($doc[0], $doc[1], $doc[2], $doc[3]));
            $files[$name] = count($doc[3]);
            $index[] = ['file' => '[' . $name . '](' . $name . ')', 'what' => $doc[0], 'rows' => $files[$name]];
        }
        $intro = 'Referencia para desarrolladores que sale de lo que el sistema declara: ni se escribe a mano ni se queda vieja.';
        self::store($dir, 'indice.md', self::markdown('Referencia generada', $intro, ['file' => 'Fichero', 'what' => 'Contenido', 'rows' => 'Filas'], $index));
        $files['indice.md'] = count($index);

        return ['files' => $files, 'dir' => $dir];
    }

    /**
     * Escribe a un temporal y lo renombra: nunca queda un fichero a medias.
     */
    private static function store(string $dir, string $name, string $content): void
    {
        $tmp = $dir . '/.' . $name . '.tmp';
        // Se comprueban los resultados: un fallo de escritura se convierte en excepción, no se calla.
        if (@file_put_contents($tmp, $content) === false) {
            throw new \RuntimeException('Cannot write ' . $tmp);
        }
        if (!@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . $dir . '/' . $name);
        }
    }
}
