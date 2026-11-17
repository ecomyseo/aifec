<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;

/**
 * El glosario del comercio para un idioma: una línea por término. «carrito = panier» dice cómo se traduce un término; una línea con
 * un nombre solo («Oslo», «AIFEC») dice que ese nombre no se traduce nunca. Viaja con cada encargo a la IA y, además, se comprueba
 * por código: una traducción que pierde un nombre que no se traduce se descarta.
 *
 * Se guarda en el ajuste «translations.glossary» (idioma => texto).
 */
final class Glossary
{
    /** Largo máximo del glosario de un idioma, en caracteres. */
    public const MAX = 4000;

    /** Líneas que se leen como mucho. */
    private const MAX_LINES = 200;

    public static function get(App $app, string $lang): string
    {
        $all = $app->settings()->arr('translations.glossary');

        return isset($all[$lang]) && is_string($all[$lang]) ? $all[$lang] : '';
    }

    public static function set(App $app, string $lang, string $text): void
    {
        $all = $app->settings()->arr('translations.glossary');
        $text = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", $text)), 0, self::MAX, 'UTF-8');
        if ($text === '') {
            unset($all[$lang]);
        } else {
            $all[$lang] = $text;
        }
        $app->settings()->set('translations.glossary', $all);
    }

    /**
     * @return array{map:array<string,string>,keep:string[]}
     */
    public static function parse(string $text): array
    {
        $map = [];
        $keep = [];
        $lines = preg_split('/\R/u', $text);
        foreach (array_slice(is_array($lines) ? $lines : [], 0, self::MAX_LINES) as $line) {
            $line = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $line));
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                if (!in_array($line, $keep, true)) {
                    $keep[] = $line;
                }
                continue;
            }
            $term = trim(substr($line, 0, $pos));
            $translation = trim(substr($line, $pos + 1));
            if ($term !== '' && $translation !== '') {
                $map[$term] = $translation;
            }
        }

        return ['map' => $map, 'keep' => $keep];
    }

    /** Las líneas que viajan en el encargo, ya ordenadas; vacío si no hay glosario. */
    public static function lines(App $app, string $lang): string
    {
        $parsed = self::parse(self::get($app, $lang));
        $out = [];
        foreach ($parsed['map'] as $term => $translation) {
            $out[] = $term . ' = ' . $translation;
        }
        if ($parsed['keep'] !== []) {
            $out[] = 'Never translate these names: ' . implode(', ', $parsed['keep']);
        }

        return implode("\n", $out);
    }

    /**
     * Si una traducción pierde un nombre que no se traduce (y que el original sí lleva), ese nombre; si no, ''.
     *
     * @param string[] $keep
     */
    public static function lostName(array $keep, string $source, string $text): string
    {
        foreach ($keep as $name) {
            if ($name !== '' && mb_stripos($source, $name, 0, 'UTF-8') !== false && mb_stripos($text, $name, 0, 'UTF-8') === false) {
                return $name;
            }
        }

        return '';
    }
}
