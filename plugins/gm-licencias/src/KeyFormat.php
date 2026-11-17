<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Gm\Licencias;

use Aifec\Security\Random;

/**
 * Las claves de licencia con dominios: cinco grupos de cinco con guiones («AAAAA-BBBBB-…»), con un alfabeto sin la I, la O, el
 * cero ni el uno (los que se confunden al dictarlos por teléfono). 125 bits al azar: no se pueden adivinar.
 */
final class KeyFormat
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $groups = [];
        for ($i = 0; $i < 5; ++$i) {
            $groups[] = Random::pick(self::ALPHABET, 5);
        }

        return implode('-', $groups);
    }

    /** La clave como se compara y se guarda: mayúsculas, sin espacios. Una clave escrita «k7q2m xr4tc…» vale igual. */
    public static function normalize(string $key): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $key));
    }

    /** ¿Tiene la forma de una clave con dominios? (No dice si existe.) */
    public static function looksValid(string $key): bool
    {
        return preg_match('/^[' . self::ALPHABET . ']{5}(-[' . self::ALPHABET . ']{5}){4}$/', $key) === 1;
    }

    /**
     * El dominio como se compara: sin esquema, sin ruta, sin puerto, en minúsculas y sin «www.». «» si no es un dominio.
     */
    public static function domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $domain);
        $domain = (string) preg_replace('~[/?#].*$~', '', $domain);
        $domain = (string) preg_replace('~:\d+$~', '', $domain);
        $domain = rtrim($domain, '.');
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }
        if ($domain === '' || strlen($domain) > 191 || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $domain) !== 1) {
            return '';
        }

        return $domain;
    }
}
