<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Consent;

/**
 * La cookie de la decisión (legible por JavaScript, sin ningún secreto): «versión.letras.fecha.identificador».
 *
 *   3.sm.1731500000.9f2c4e1ab7d30586
 *
 * Las letras son las categorías concedidas (p preferencias, s estadísticas, m marketing; la necesaria no se apunta porque
 * siempre vale); sin letras = rechazó todo. La fecha es el instante de la decisión (segundos UNIX). El identificador es un número
 * al azar que no dice quién es: sirve para el registro de la prueba. Una decisión de otra versión de la política no cuenta.
 */
final class ConsentCookie
{
    /** Letra de cada categoría opcional, en el orden en que se escriben. */
    public const LETTERS = ['preferences' => 'p', 'statistics' => 's', 'marketing' => 'm'];

    /** Días que vive la cookie (6 meses). */
    public const DAYS = 180;

    /**
     * El valor de la cookie.
     *
     * @param string[] $granted categorías concedidas (nombres, no letras)
     */
    public static function build(int $version, array $granted, int $time, string $id): string
    {
        return $version . '.' . self::letters($granted) . '.' . $time . '.' . $id;
    }

    /**
     * Las letras de una lista de categorías, sin repetir y siempre en el mismo orden.
     *
     * @param string[] $categories
     */
    public static function letters(array $categories): string
    {
        $out = '';
        foreach (self::LETTERS as $name => $letter) {
            if (in_array($name, $categories, true)) {
                $out .= $letter;
            }
        }

        return $out;
    }

    /**
     * Lee la cookie. Devuelve null si falta, está mal formada o es de otra versión de la política.
     *
     * @return array{version:int,letters:string,categories:string[],time:int,id:string}|null
     */
    public static function parse(?string $raw, int $currentVersion): ?array
    {
        if ($raw === null || $raw === '' || strlen($raw) > 120) {
            return null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 4 || preg_match('/^[0-9]{1,9}$/', $parts[0]) !== 1 || preg_match('/^[psm]{0,3}$/', $parts[1]) !== 1
            || preg_match('/^[0-9]{1,12}$/', $parts[2]) !== 1 || preg_match('/^[a-f0-9]{12,32}$/', $parts[3]) !== 1) {
            return null;
        }
        if ((int) $parts[0] !== $currentVersion) {
            return null;
        }
        $categories = [];
        foreach (self::LETTERS as $name => $letter) {
            if (strpos($parts[1], $letter) !== false) {
                $categories[] = $name;
            }
        }

        return ['version' => (int) $parts[0], 'letters' => self::letters($categories), 'categories' => $categories, 'time' => (int) $parts[2], 'id' => $parts[3]];
    }

    /**
     * ¿Permite la decisión esta categoría? La necesaria siempre; sin decisión, ninguna otra.
     *
     * @param array{version:int,letters:string,categories:string[],time:int,id:string}|null $decision
     */
    public static function allows(?array $decision, string $category): bool
    {
        if ($category === 'necessary') {
            return true;
        }

        return $decision !== null && in_array($category, $decision['categories'], true);
    }
}
