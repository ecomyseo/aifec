<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tax;

/**
 * Lista de códigos postales de un tipo de impuesto.
 *
 * Es texto separado por comas. Cada elemento es:
 *   - un prefijo: «35» casa con 35001 y con 35600;
 *   - un rango de códigos completos: «51000-51999» (los dos extremos con los mismos dígitos y el primero no mayor).
 * Letras y números, sin distinguir mayúsculas; los espacios y los guiones del código que llega se ignoran
 * (el «1000-001» portugués se escribe como prefijo «1000»). Un rango solo casa con códigos de solo dígitos y de su longitud.
 */
final class Postcodes
{
    /**
     * Separa y valida una lista. Devuelve los elementos como [desde, hasta] (un prefijo es [prefijo, '']) o null si algo no vale.
     *
     * @return array<int,array{0:string,1:string}>|null
     */
    public static function parse(string $list): ?array
    {
        $items = [];
        foreach (explode(',', $list) as $raw) {
            $item = strtoupper((string) preg_replace('/\s+/', '', $raw));
            if ($item === '') {
                continue;
            }
            if (preg_match('/^[A-Z0-9]{1,10}$/D', $item) === 1) {
                $items[] = [$item, ''];
                continue;
            }
            if (preg_match('/^([0-9]{1,10})-([0-9]{1,10})$/D', $item, $m) === 1 && strlen($m[1]) === strlen($m[2]) && strcmp($m[1], $m[2]) <= 0) {
                $items[] = [$m[1], $m[2]];
                continue;
            }

            return null;
        }

        return $items;
    }

    /**
     * La lista en su forma de guardar («35, 38» → «35,38»), o null si no es válida. Vacía da ''.
     */
    public static function normalize(string $list): ?string
    {
        $items = self::parse($list);
        if ($items === null) {
            return null;
        }
        $out = [];
        foreach ($items as $item) {
            $out[] = $item[1] === '' ? $item[0] : $item[0] . '-' . $item[1];
        }

        return implode(',', $out);
    }

    /** El código tal como se compara: mayúsculas y sin espacios ni guiones. */
    public static function clean(string $postcode): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $postcode));
    }

    /** ¿El código postal cae en alguno de los elementos de la lista? Una lista vacía o inválida no casa con nada. */
    public static function matches(string $list, string $postcode): bool
    {
        $pc = self::clean($postcode);
        if ($pc === '') {
            return false;
        }
        $items = self::parse($list);
        foreach (is_array($items) ? $items : [] as $item) {
            if ($item[1] === '') {
                if (strpos($pc, $item[0]) === 0) {
                    return true;
                }
                continue;
            }
            if (ctype_digit($pc) && strlen($pc) === strlen($item[0]) && strcmp($pc, $item[0]) >= 0 && strcmp($pc, $item[1]) <= 0) {
                return true;
            }
        }

        return false;
    }
}
