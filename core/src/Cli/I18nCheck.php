<?php
/**
 * AIFEC — comprueba que todos los textos de una carpeta (el núcleo, un módulo, un plugin o un tema) tienen traducción.
 *
 * Saca las cadenas de:
 *   - las llamadas t('…'), tn('…', '…') y helpText('…');
 *   - las claves de definición que el panel traduce al pintar: label, hint, title, singular, description,
 *     placeholder, text, confirm, add, action_label, empty, suffix, help;
 *   - los textos de las listas options, caps, tabs y map (salvo que al lado ponga 'translate' => false,
 *     'raw' => true u 'options_from');
 *   - el nombre y la descripción de module.json y plugin.json, y las etiquetas, menús y opciones de theme.json.
 * Las claves de definición solo se miran en los ficheros de definición (admin.php, settings.php, blocks.php) y en el
 * código que usa el panel (el que nombra «Aifec\Admin»): así no se cuelan los arrays de las librerías.
 *
 * Una cadena vale si está en el catálogo de la carpeta (lang/<idioma>.php) o en el del núcleo. Lo que no deba
 * traducirse se lista en lang/i18n-ignore.php (return ['cadena', …];).
 *
 * No necesita la aplicación ni la base de datos: solo lee ficheros. La usan la orden «i18n:check» y las fábricas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Cli;

final class I18nCheck
{
    private const KEYS = ['label', 'hint', 'title', 'singular', 'description', 'placeholder', 'text', 'confirm', 'add', 'action_label', 'empty', 'suffix', 'help'];
    private const LISTS = ['options', 'caps', 'tabs', 'map'];

    /**
     * @param string $root   raíz de la instalación
     * @param string $target carpeta relativa a la raíz: «core», «modules/<slug>», «plugins/<slug>» o «themes/<slug>»
     *
     * @return array{ok:bool,strings:int,missing:array<string,string>,unused:string[]} missing: cadena => fichero donde
     *                                                                              aparece; unused: claves del catálogo
     *                                                                              propio que ya no usa nadie
     *
     * @throws \InvalidArgumentException si la carpeta no existe o se sale de la raíz
     */
    public static function run(string $root, string $target, string $lang = 'es'): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $target = trim(str_replace('\\', '/', $target), '/');
        if ($target === '' || strpos($target, '..') !== false || !is_dir($root . '/' . $target)) {
            throw new \InvalidArgumentException('Unknown folder: ' . $target);
        }
        if (preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $lang) !== 1) {
            throw new \InvalidArgumentException('Invalid language code: ' . $lang);
        }

        $strings = [];
        foreach (self::phpFiles($root . '/' . $target) as $file) {
            foreach (self::extractFile($file) as $string => $unused) {
                if (!isset($strings[$string])) {
                    $strings[$string] = substr($file, strlen($root) + 1);
                }
            }
        }
        foreach (['theme.json', 'module.json', 'plugin.json'] as $manifest) {
            $file = $root . '/' . $target . '/' . $manifest;
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            foreach (self::manifestStrings(is_array($data) ? $data : [], '', $manifest === 'theme.json') as $string) {
                if (!isset($strings[$string])) {
                    $strings[$string] = $target . '/' . $manifest;
                }
            }
        }

        $ownFile = $root . '/' . $target . '/lang/' . $lang . '.php';
        $own = is_file($ownFile) ? self::load($ownFile) : [];
        $coreFile = $root . '/core/lang/' . $lang . '.php';
        $catalog = $own + (is_file($coreFile) ? self::load($coreFile) : []);

        $ignore = [];
        foreach ([$root . '/core/lang/i18n-ignore.php', $root . '/' . $target . '/lang/i18n-ignore.php'] as $file) {
            if (is_file($file)) {
                foreach (self::load($file) as $item) {
                    if (is_string($item)) {
                        $ignore[$item] = true;
                    }
                }
            }
        }

        $missing = [];
        foreach ($strings as $string => $where) {
            if (isset($ignore[$string])) {
                continue;
            }
            if (!isset($catalog[$string]) || $catalog[$string] === '') {
                $missing[$string] = $where;
            }
        }
        ksort($missing, SORT_STRING);
        $unused = [];
        foreach ($own as $key => $unusedValue) {
            if (is_string($key) && !isset($strings[$key])) {
                $unused[] = $key;
            }
        }

        // Una clave escrita dos veces en el catálogo: PHP no avisa y se queda con la última.
        $duplicates = is_file($ownFile) ? self::duplicates($ownFile) : [];

        return ['ok' => $missing === [] && $duplicates === [], 'strings' => count($strings), 'missing' => $missing, 'unused' => $unused, 'duplicates' => $duplicates];
    }

    /**
     * Las cadenas que faltan, listas para pegar en lang/<idioma>.php.
     *
     * @param array<string,string> $missing
     */
    /**
     * Claves que aparecen más de una vez en el primer nivel de un catálogo de textos.
     *
     * @return array<string,int[]> clave => líneas en las que está
     */
    public static function duplicates(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $count = count($tokens);
        $depth = 0;
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '[') {
                ++$depth;
                continue;
            }
            if ($token === ']') {
                --$depth;
                continue;
            }
            if ($depth !== 1 || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                ++$j;
            }
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                $lines[self::literal($token[1])][] = (int) $token[2];
            }
        }
        $out = [];
        foreach ($lines as $key => $where) {
            if (count($where) > 1) {
                $out[(string) $key] = $where;
            }
        }

        return $out;
    }

    public static function snippet(array $missing): string
    {
        $out = '';
        foreach ($missing as $string => $where) {
            $out .= '    ' . var_export((string) $string, true) . " => '',   // " . $where . "\n";
        }

        return $out;
    }

    /**
     * @return array<mixed,mixed>
     */
    private static function load(string $file): array
    {
        $data = require $file;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<mixed,mixed> $node
     *
     * @return string[]
     */
    private static function manifestStrings(array $node, string $key, bool $isTheme): array
    {
        $found = [];
        $wanted = $isTheme ? ['label', 'description'] : ['name', 'description'];
        foreach ($node as $k => $v) {
            if (is_string($v)) {
                $listed = in_array($key, ['menus', 'options'], true);
                if (($listed || in_array((string) $k, $wanted, true)) && preg_match('/\pL/u', $v) === 1) {
                    $found[] = $v;
                }
            } elseif (is_array($v)) {
                foreach (self::manifestStrings($v, (string) $k, $isTheme) as $string) {
                    $found[] = $string;
                }
            }
        }

        return $found;
    }

    /** El valor de un literal de cadena de PHP. */
    private static function literal(string $token): string
    {
        $body = substr($token, 1, -1);
        if ($token[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return stripcslashes($body);
    }

    /**
     * @return array<string,bool> las cadenas traducibles de un fichero
     */
    private static function extractFile(string $file): array
    {
        $found = [];
        $source = (string) file_get_contents($file);
        $definitions = in_array(basename($file), ['admin.php', 'settings.php', 'blocks.php'], true) || strpos($source, 'Aifec\\Admin') !== false;
        $tokens = array_values(array_filter(token_get_all($source), static function ($t): bool {
            return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        $n = count($tokens);
        $text = static function ($t): string {
            return is_array($t) ? $t[1] : $t;
        };
        $isString = static function ($t): bool {
            return is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING;
        };
        $add = static function (string $s) use (&$found): void {
            if ($s !== '' && preg_match('/\pL/u', $s) === 1) {
                $found[$s] = true;
            }
        };

        // Pila de corchetes abiertos: por cada uno, las claves vistas a su nivel y dónde empieza su valor.
        $stack = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $s = $text($t);

            // Llamadas t(), tn() y helpText().
            if (is_array($t) && $t[0] === T_STRING && isset($tokens[$i + 1]) && $text($tokens[$i + 1]) === '(') {
                $name = $t[1];
                if (($name === 't' || $name === 'helpText') && isset($tokens[$i + 2]) && $isString($tokens[$i + 2])) {
                    $value = self::literal($tokens[$i + 2][1]);
                    if ($name === 'helpText') {
                        foreach (explode("\n", $value) as $line) {
                            $add(trim($line));
                        }
                    } else {
                        $add($value);
                    }
                }
                if ($name === 'tn' && isset($tokens[$i + 4]) && $isString($tokens[$i + 2]) && $text($tokens[$i + 3]) === ',' && $isString($tokens[$i + 4])) {
                    $add(self::literal($tokens[$i + 2][1]) . '|' . self::literal($tokens[$i + 4][1]));
                }
            }

            if ($s === '[') {
                $stack[] = ['start' => $i, 'keys' => []];
                continue;
            }
            if ($s === ']') {
                $frame = array_pop($stack);
                if ($frame === null || !$definitions) {
                    continue;
                }
                $keys = $frame['keys'];
                if (isset($keys['value']) && (isset($keys['table']) || isset($keys['where']))) {
                    // Es un «options_from»: su «label» es una columna, no un texto.
                    continue;
                }
                $plain = isset($keys['translate']) && strtolower($text($tokens[$keys['translate']])) === 'false';
                $plain = $plain || isset($keys['options_from']) || (isset($keys['raw']) && strtolower($text($tokens[$keys['raw']])) === 'true');
                foreach ($keys as $key => $valueAt) {
                    $key = (string) $key;
                    if (in_array($key, self::KEYS, true) && $isString($tokens[$valueAt]) && $text($tokens[$valueAt + 1]) !== '.') {
                        $value = self::literal($tokens[$valueAt][1]);
                        if ($key === 'help') {
                            foreach (explode("\n", $value) as $line) {
                                $add(trim($line));
                            }
                        } elseif ($key !== 'suffix' || strlen($value) > 2) {
                            $add($value);
                        }
                    }
                    if (!in_array($key, self::LISTS, true) || $text($tokens[$valueAt]) !== '[') {
                        continue;
                    }
                    // Un campo de formulario que se llama «caps» u «options» no es una lista de textos.
                    $isField = false;
                    $d = 0;
                    for ($j = $valueAt; $j < $i; $j++) {
                        $sj = $text($tokens[$j]);
                        $d += $sj === '[' ? 1 : ($sj === ']' ? -1 : 0);
                        if ($d === 0) {
                            break;
                        }
                        if ($d === 1 && $isString($tokens[$j]) && self::literal($tokens[$j][1]) === 'type' && is_array($tokens[$j + 1]) && $tokens[$j + 1][0] === T_DOUBLE_ARROW) {
                            $isField = true;
                        }
                    }
                    if ($plain || $isField) {
                        continue;
                    }
                    // Recorre la lista: cada «=> 'texto'» de primer nivel, o el primer texto de un «=> ['texto', …]».
                    $depth = 0;
                    for ($j = $valueAt; $j < $i; $j++) {
                        $sj = $text($tokens[$j]);
                        if ($sj === '[') {
                            $depth++;
                            if ($depth === 2 && $key === 'map' && $isString($tokens[$j + 1])) {
                                $add(self::literal($tokens[$j + 1][1]));
                            }
                        } elseif ($sj === ']') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                        } elseif ($depth === 1 && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW && $isString($tokens[$j + 1]) && $text($tokens[$j + 2]) !== '.') {
                            $add(self::literal($tokens[$j + 1][1]));
                        } elseif ($depth === 2 && $key === 'tabs' && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW && $isString($tokens[$j - 1]) && self::literal($tokens[$j - 1][1]) === 'label' && $isString($tokens[$j + 1])) {
                            $add(self::literal($tokens[$j + 1][1]));
                        }
                    }
                }
                continue;
            }
            // Clave de array al nivel del corchete abierto más cercano: 'clave' => valor
            if ($isString($t) && isset($tokens[$i + 1]) && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_ARROW && $stack !== []) {
                $stack[count($stack) - 1]['keys'][self::literal($t[1])] = $i + 2;
            }
        }

        return $found;
    }

    /**
     * @return string[]
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (substr($path, -4) === '.php' && strpos($path, '/tests/') === false && strpos($path, '/lang/') === false && strpos($path, '/migrations/') === false) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }
}
