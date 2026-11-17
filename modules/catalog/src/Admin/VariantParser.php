<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

/**
 * Entiende las combinaciones escritas a mano: «negro y gris, tallas S a L», «rojo/azul; 38, 39, 40», «black and grey; sizes S to XL».
 * Es una librería pura (sin base de datos, sin pantalla): recibe el texto y los atributos con sus valores, y devuelve qué valores
 * de cada atributo se han pedido y qué trozos no ha entendido.
 *
 * Reglas, por orden:
 *  1. Los trozos se separan por «;» o salto de línea; si no hay, por comas. Cada trozo se resuelve por su cuenta y los resultados
 *     de un mismo atributo se unen, así «38, 39, 40» da lo mismo con comas que con «;».
 *  2. Un trozo puede empezar por el nombre de un atributo (en singular o plural, en cualquier idioma de la tienda): «tallas S a L».
 *     Los que vienen sin nombre se buscan entre los valores: primero en el último atributo nombrado, luego en todos.
 *  3. Dentro de un trozo, los valores se separan por «/», «+», «|», «&», comas, « y », « and » y « e ».
 *  4. Un rango se escribe con «a», «to», «hasta», «-» o «–»: de números (36-44, entre los valores numéricos del atributo) o de
 *     letras (S a XL, con la escalera XXS…5XL, o con el orden del propio atributo si no son tallas conocidas).
 *
 * Los textos se comparan sin mayúsculas ni tildes.
 */
final class VariantParser
{
    /** Escalera de tallas de letras, de menor a mayor. */
    private const LADDER = ['3xs', 'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', '3xl', '4xl', '5xl'];

    /** Otras formas de escribir una talla de la escalera. */
    private const LADDER_ALIASES = ['2xs' => 'xxs', '2xl' => 'xxl', 'xxxl' => '3xl', 'xxxs' => '3xs', 'xxxxl' => '4xl'];

    /**
     * @param array<int,array<string,mixed>> $attributes id => ['id','name','code','values' => [id => ['label','code']], 'aliases' => string[]]
     *
     * @return array{chosen:array<int,int[]>,unknown:string[]} lo elegido (atributo => ids de valores) y lo que no se ha entendido
     */
    public static function parse(string $text, array $attributes): array
    {
        $index = self::index($attributes);
        $chosen = [];
        $unknown = [];
        $context = null;

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $segments = preg_split(strpos($text, ';') !== false || strpos($text, "\n") !== false ? '/[;\n]/u' : '/,/u', $text);
        foreach (is_array($segments) ? $segments : [] as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $folded = self::fold($segment);
            $rest = $folded;
            $attributeId = null;
            // ¿Empieza por el nombre de un atributo (de hasta tres palabras)? «tallas s a l» → atributo talla, resto «s a l».
            $words = preg_split('/[\s:]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY);
            $words = is_array($words) ? $words : [];
            for ($n = min(3, count($words)); $n >= 1; $n--) {
                $named = self::attributeByName($index, implode(' ', array_slice($words, 0, $n)));
                if ($named !== null) {
                    $attributeId = $named;
                    $rest = trim(implode(' ', array_slice($words, $n)));
                    $context = $named;
                    break;
                }
            }
            if ($rest === '') {
                continue;
            }
            $items = preg_split('/\s*(?:[\/,+|&]|\s(?:y|and|e)\s)\s*/u', $rest);
            foreach (is_array($items) ? $items : [] as $item) {
                $item = trim($item, " \t.");
                if ($item === '') {
                    continue;
                }
                $start = $attributeId !== null ? $attributeId : $context;
                $found = [];
                $one = self::resolve($item, $index, $start);
                if ($one !== null) {
                    $found[] = $one;
                } elseif (strpos($item, '-') !== false) {
                    // «rojo-azul» no es un rango: son dos valores unidos con un guion.
                    foreach (preg_split('/\s*-\s*/', $item) ?: [] as $part) {
                        $sub = $part === '' ? null : self::resolve($part, $index, $start);
                        if ($sub === null) {
                            $found = [];
                            break;
                        }
                        $found[] = $sub;
                    }
                }
                if ($found === []) {
                    $unknown[] = self::original($segment, $item);
                    continue;
                }
                foreach ($found as $hit) {
                    foreach ($hit['missing'] as $missing) {
                        $unknown[] = strtoupper($missing);
                    }
                    foreach ($hit['ids'] as $valueId) {
                        if (!isset($chosen[$hit['attribute']]) || !in_array($valueId, $chosen[$hit['attribute']], true)) {
                            $chosen[$hit['attribute']][] = $valueId;
                        }
                    }
                }
            }
        }

        return ['chosen' => $chosen, 'unknown' => array_values(array_unique($unknown))];
    }

    /** Sin mayúsculas, sin tildes, con un solo tipo de guion y los espacios recogidos. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', '–' => '-', '—' => '-', '−' => '-', '‐' => '-',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Lo que hace falta para buscar: nombre de cada atributo y valores, todo plegado.
     *
     * @param array<int,array<string,mixed>> $attributes
     *
     * @return array<int,array{names:string[],values:array<int,string[]>,order:int[]}>
     */
    private static function index(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $id => $attr) {
            $names = [self::fold((string) (isset($attr['name']) ? $attr['name'] : '')), self::fold((string) (isset($attr['code']) ? $attr['code'] : ''))];
            foreach (isset($attr['aliases']) && is_array($attr['aliases']) ? $attr['aliases'] : [] as $alias) {
                $names[] = self::fold((string) $alias);
            }
            $values = [];
            $order = [];
            foreach (isset($attr['values']) && is_array($attr['values']) ? $attr['values'] : [] as $valueId => $value) {
                $keys = [self::fold((string) (isset($value['label']) ? $value['label'] : '')), self::fold((string) (isset($value['code']) ? $value['code'] : ''))];
                foreach (isset($value['aliases']) && is_array($value['aliases']) ? $value['aliases'] : [] as $alias) {
                    $keys[] = self::fold((string) $alias);
                }
                $values[(int) $valueId] = array_values(array_unique(array_filter($keys, 'strlen')));
                $order[] = (int) $valueId;
            }
            $out[(int) $id] = ['names' => array_values(array_unique(array_filter($names, 'strlen'))), 'values' => $values, 'order' => $order];
        }

        return $out;
    }

    /**
     * El atributo que nombra una palabra, en singular o plural («talla», «tallas», «colores», «colour»).
     *
     * @param array<int,array{names:string[],values:array<int,string[]>,order:int[]}> $index
     */
    private static function attributeByName(array $index, string $word): ?int
    {
        $forms = [$word];
        foreach (['es', 's'] as $plural) {
            if (strlen($word) > strlen($plural) + 2 && substr($word, -strlen($plural)) === $plural) {
                $forms[] = substr($word, 0, -strlen($plural));
            }
        }
        foreach ($index as $id => $attr) {
            foreach ($attr['names'] as $name) {
                if (in_array($name, $forms, true)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * Resuelve un valor o un rango. $context = el atributo del que se viene hablando.
     *
     * @param array<int,array{names:string[],values:array<int,string[]>,order:int[]}> $index
     *
     * @return array{attribute:int,ids:int[],missing:string[]}|null
     */
    private static function resolve(string $item, array $index, ?int $context): ?array
    {
        $order = array_keys($index);
        if ($context !== null && isset($index[$context])) {
            $order = array_merge([$context], array_values(array_diff($order, [$context])));
        }
        // Primero un valor suelto (así «azul-marino» no se toma por un rango).
        foreach ($order as $attributeId) {
            $valueId = self::valueId($index[$attributeId], $item);
            if ($valueId !== null) {
                return ['attribute' => $attributeId, 'ids' => [$valueId], 'missing' => []];
            }
        }
        // Luego un rango.
        if (preg_match('/^(?:de |del |desde |from )?(.+?)\s*(?:-|\s(?:a|al|to|hasta|through)\s)\s*(.+)$/u', $item, $m) !== 1) {
            return null;
        }
        $from = trim($m[1]);
        $to = trim($m[2]);
        // El orden del atributo solo sirve de rango si se escribió con una palabra («rojo a verde»), no con un guion.
        $word = preg_match('/\s(?:a|al|to|hasta|through)\s/u', $item) === 1;
        foreach ($order as $attributeId) {
            $range = self::range($index[$attributeId], $from, $to, $word);
            if ($range !== null) {
                return ['attribute' => $attributeId] + $range;
            }
        }

        return null;
    }

    /**
     * @param array{names:string[],values:array<int,string[]>,order:int[]} $attr
     */
    private static function valueId(array $attr, string $text): ?int
    {
        $wanted = self::ladder($text);
        foreach ($attr['values'] as $valueId => $keys) {
            foreach ($keys as $key) {
                if ($key === $text || (self::ladderKey($key) !== null && $wanted !== null && self::ladderKey($key) === $wanted)) {
                    return $valueId;
                }
            }
        }

        return null;
    }

    /**
     * Un rango dentro de un atributo: números entre dos números, tallas de letras por la escalera, o el orden del atributo.
     *
     * @param array{names:string[],values:array<int,string[]>,order:int[]} $attr
     *
     * @return array{ids:int[],missing:string[]}|null null si los extremos no son de este atributo
     */
    private static function range(array $attr, string $from, string $to, bool $word): ?array
    {
        // Números: los valores numéricos del atributo que caen entre los dos.
        if (self::number($from) !== null && self::number($to) !== null) {
            $lo = min(self::number($from), self::number($to));
            $hi = max(self::number($from), self::number($to));
            $ids = [];
            foreach ($attr['order'] as $valueId) {
                foreach ($attr['values'][$valueId] as $key) {
                    $n = self::number($key);
                    if ($n !== null && $n >= $lo && $n <= $hi) {
                        $ids[] = $valueId;
                        break;
                    }
                }
            }

            return $ids !== [] ? ['ids' => $ids, 'missing' => []] : null;
        }

        // Letras: la escalera de tallas. Lo que el atributo no tiene se dice.
        $a = self::ladder($from);
        $b = self::ladder($to);
        if ($a !== null && $b !== null) {
            $i = array_search($a, self::LADDER, true);
            $j = array_search($b, self::LADDER, true);
            $slice = array_slice(self::LADDER, (int) min($i, $j), (int) abs($i - $j) + 1);
            $ids = [];
            $missing = [];
            foreach ($slice as $size) {
                $valueId = self::valueId($attr, $size);
                if ($valueId === null) {
                    $missing[] = $size;
                } else {
                    $ids[] = $valueId;
                }
            }
            // Si ni los extremos son de este atributo, el rango es de otro.
            if (self::valueId($attr, $from) === null && self::valueId($attr, $to) === null) {
                return null;
            }

            return $ids !== [] ? ['ids' => $ids, 'missing' => $missing] : null;
        }

        // Otros valores: del uno al otro por el orden del atributo.
        $start = self::valueId($attr, $from);
        $end = self::valueId($attr, $to);
        if (!$word || $start === null || $end === null) {
            return null;
        }
        $i = array_search($start, $attr['order'], true);
        $j = array_search($end, $attr['order'], true);

        return ['ids' => array_slice($attr['order'], (int) min($i, $j), (int) abs($i - $j) + 1), 'missing' => []];
    }

    /** La talla de la escalera que es este texto («xxl», «2xl»), o null. */
    private static function ladder(string $text): ?string
    {
        $text = str_replace(' ', '', $text);
        if (isset(self::LADDER_ALIASES[$text])) {
            return self::LADDER_ALIASES[$text];
        }

        return in_array($text, self::LADDER, true) ? $text : null;
    }

    private static function ladderKey(string $key): ?string
    {
        return self::ladder($key);
    }

    /** Un número escrito con coma o con punto, o null si el texto no es solo un número. */
    private static function number(string $text): ?float
    {
        $text = trim($text);
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $text) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $text);
    }

    /** El trozo tal como lo escribió la persona (con sus mayúsculas), para decir qué no se entendió. */
    private static function original(string $segment, string $foldedItem): string
    {
        $pos = mb_stripos($segment, $foldedItem, 0, 'UTF-8');

        return $pos !== false ? mb_substr($segment, $pos, mb_strlen($foldedItem, 'UTF-8'), 'UTF-8') : $foldedItem;
    }
}
