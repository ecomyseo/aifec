<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

/**
 * Elegir una opción dentro de la conversación («¿en qué color?», «en negro»). Cuando se pide añadir un producto con variantes sin
 * decir cuál, la herramienta cart_add no falla: devuelve «need: options» con las opciones, y el asistente guarda aquí lo que
 * hace falta para entender la respuesta del cliente (el botón o el texto) sin volver a preguntárselo a nadie.
 *
 * Lo que se guarda en el estado de la conversación (clave «pick»), corto a propósito:
 *   id (producto), n (nombre), q (unidades), v (las variantes que se pueden comprar: id, l = etiqueta, o = los valores que la
 *   forman) y s (los valores agotados, para decir que ese ya no hay).
 *
 * Todo son funciones puras: el estado y el texto dentro, la decisión fuera.
 */
final class VariantPick
{
    /** Variantes que se guardan como mucho. */
    private const MAX_VARIANTS = 20;

    /** Botones que se ofrecen como mucho. */
    public const MAX_CHIPS = 8;

    /**
     * El estado a guardar a partir de lo que devolvió cart_add con «need: options».
     *
     * @param array<string,mixed> $need
     *
     * @return array<string,mixed>|null null si no hay nada que elegir
     */
    public static function fromNeed(array $need, int $qty): ?array
    {
        $variants = [];
        foreach (isset($need['variants']) && is_array($need['variants']) ? $need['variants'] : [] as $variant) {
            if (!is_array($variant) || empty($variant['available']) || (int) (isset($variant['id']) ? $variant['id'] : 0) < 1) {
                continue;
            }
            $labels = [];
            foreach (isset($variant['value_labels']) && is_array($variant['value_labels']) ? $variant['value_labels'] : [] as $label) {
                if (is_scalar($label) && trim((string) $label) !== '') {
                    $labels[] = mb_substr(trim((string) $label), 0, 40, 'UTF-8');
                }
            }
            $variants[] = ['id' => (int) $variant['id'], 'l' => mb_substr((string) (isset($variant['label']) ? $variant['label'] : ''), 0, 60, 'UTF-8'), 'o' => $labels];
            if (count($variants) >= self::MAX_VARIANTS) {
                break;
            }
        }
        if ($variants === []) {
            return null;
        }
        $sold = [];
        $choices = [];
        foreach (isset($need['options']) && is_array($need['options']) ? $need['options'] : [] as $option) {
            foreach (is_array($option) && isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && !empty($value['label']) && !empty($option['name'])) {
                    $choices[RuleBrain::norm($option['name'])][] = mb_substr((string) $value['label'], 0, 40, 'UTF-8');
                }
                if (is_array($value) && isset($value['label']) && empty($value['available'])) {
                    $sold[] = mb_substr((string) $value['label'], 0, 40, 'UTF-8');
                }
            }
        }

        return [
            'id' => (int) (isset($need['product_id']) ? $need['product_id'] : 0),
            'n' => mb_substr((string) (isset($need['name']) ? $need['name'] : ''), 0, 80, 'UTF-8'),
            'q' => max(1, min(99, $qty)),
            'v' => $variants,
            's' => array_slice(array_values(array_unique($sold)), 0, 20),
            'choices' => $choices,
        ];
    }

    /**
     * Lo que se ofrece como botones: el valor de la opción si solo hay una (los colores) y, si hay varias, la variante entera.
     *
     * @param array<string,mixed> $pick
     *
     * @return string[]
     */
    public static function chips(array $pick): array
    {
        $variants = isset($pick['v']) && is_array($pick['v']) ? $pick['v'] : [];
        $single = $variants !== [];
        foreach ($variants as $variant) {
            if (count(is_array($variant['o']) ? $variant['o'] : []) !== 1) {
                $single = false;
                break;
            }
        }
        $chips = [];
        foreach ($variants as $variant) {
            $label = $single ? (string) $variant['o'][0] : (string) $variant['l'];
            if ($label !== '') {
                $chips[$label] = $label;
            }
        }

        return array_slice(array_values($chips), 0, self::MAX_CHIPS);
    }

    /**
     * Qué quiere decir el cliente con su mensaje: una variante («one»), varias que todavía hay que distinguir («many», con el
     * estado ya reducido a ellas), un valor agotado («sold_out») o ninguna («none», no hablaba de esto).
     *
     * @param array<string,mixed> $pick
     *
     * @return array{status:string,variant:int,pick:array<string,mixed>}
     */
    public static function resolve(array $pick, string $message): array
    {
        $normal = RuleBrain::norm($message);
        foreach ($pick['choices'] ?? [] as $dimension => $values) {
            if (!preg_match('/(?:^| )' . preg_quote($dimension, '/') . ' ([a-z0-9]{2,})$/D', $normal, $tail)) { continue; }
            $matches = [];
            foreach ($values as $value) {
                $label = RuleBrain::norm($value);
                if (strpos($label, $tail[1]) === 0) { $matches[$label] = true; }
            }
            if (count($matches) === 1) { $normal = substr($normal, 0, -strlen($tail[1])) . key($matches); }
        }
        $n = ' ' . $normal . ' ';
        $variants = isset($pick['v']) && is_array($pick['v']) ? $pick['v'] : [];
        $best = 0;
        $scores = [];
        $distinct = self::distinctive($variants);
        foreach ($variants as $i => $variant) {
            $score = 0;
            foreach (is_array($variant['o']) ? $variant['o'] : [] as $label) {
                if (self::has($n, (string) $label)) {
                    ++$score;
                }
            }
            if ($score === 0 && (string) $variant['l'] !== '' && self::has($n, (string) $variant['l'])) {
                $score = 1;
            }
            if ($score === 0 && $variant['o'] === []) {
                // Una variante sin valores sueltos: se entiende por lo que la distingue de las demás de su etiqueta («Talla L» → «L»).
                foreach (isset($distinct[$i]) ? $distinct[$i] : [] as $word) {
                    if (strpos($n, ' ' . $word . ' ') !== false) {
                        ++$score;
                    }
                }
            }
            $scores[$i] = $score;
            $best = max($best, $score);
        }
        if ($best > 0) {
            $kept = [];
            foreach ($variants as $i => $variant) {
                if ($scores[$i] === $best) {
                    $kept[] = $variant;
                }
            }
            if (count($kept) === 1) {
                return ['status' => 'one', 'variant' => (int) $kept[0]['id'], 'pick' => $pick];
            }
            $narrow = $pick;
            $narrow['v'] = $kept;

            return ['status' => 'many', 'variant' => 0, 'pick' => $narrow];
        }
        foreach (isset($pick['s']) && is_array($pick['s']) ? $pick['s'] : [] as $label) {
            if (self::has($n, (string) $label)) {
                return ['status' => 'sold_out', 'variant' => 0, 'pick' => $pick];
            }
        }

        return ['status' => 'none', 'variant' => 0, 'pick' => $pick];
    }

    /**
     * Las palabras de la etiqueta de cada variante que no tienen todas las demás: lo que la distingue.
     *
     * @param array<int,array<string,mixed>> $variants
     *
     * @return array<int,string[]>
     */
    private static function distinctive(array $variants): array
    {
        $words = [];
        foreach ($variants as $i => $variant) {
            $words[$i] = array_values(array_filter(explode(' ', RuleBrain::norm((string) $variant['l'])), static function (string $word): bool {
                return $word !== '';
            }));
        }
        if (count($words) < 2) {
            return $words;
        }
        $common = array_intersect(...array_values($words));
        $out = [];
        foreach ($words as $i => $list) {
            $out[$i] = array_values(array_diff($list, $common));
        }

        return $out;
    }

    /** ¿Aparece la etiqueta, como palabra o frase entera, en el texto ya normalizado (con un espacio a cada lado)? */
    private static function has(string $normalized, string $label): bool
    {
        $needle = RuleBrain::norm($label);
        if ($needle === '') {
            return false;
        }
        if (strpos($normalized, ' ' . $needle . ' ') !== false) {
            return true;
        }
        // «negro» también es «negra», «negros» y «negras»: una palabra de cuatro letras o más que acaba en o/a se acepta en su otro género.
        if (preg_match('/^([a-z]{3,})[oa]$/', $needle, $m) === 1) {
            return preg_match('/ ' . preg_quote($m[1], '/') . '[oa]s? /', $normalized) === 1;
        }

        return false;
    }
}
