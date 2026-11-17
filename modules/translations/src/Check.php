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

/**
 * Lo que tiene que cumplir una traducción para guardarse, la escriba una persona, llegue en un CSV o la proponga la IA:
 *
 *  - no está vacía, no es enorme y no lleva caracteres de control;
 *  - lleva exactamente los mismos marcadores entre llaves que el original ({name}, {n}): ni pierde ninguno ni se inventa otro;
 *  - si el original tiene formas de plural separadas por «|» («{n} artículo|{n} artículos»), trae las mismas, ninguna vacía;
 *  - no mete HTML donde el original no lo tenía, ni etiquetas que el original no usa.
 *
 * Devuelve el problema con un código (y sus datos) para que cada pantalla lo cuente en su idioma con message().
 */
final class Check
{
    /** Largo máximo de una traducción, en caracteres. */
    public const MAX_LENGTH = 20000;

    /**
     * El problema de una traducción, o null si vale.
     *
     * @param string $format text, html o plural
     *
     * @return array{code:string,vars:array<string,string>}|null
     */
    public static function problem(string $source, string $text, string $format = 'text'): ?array
    {
        if (trim($text) === '') {
            return ['code' => 'empty', 'vars' => []];
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            return ['code' => 'long', 'vars' => []];
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            return ['code' => 'control', 'vars' => []];
        }
        $wanted = self::markers($source);
        $got = self::markers($text);
        foreach ($wanted as $marker) {
            if (!in_array($marker, $got, true)) {
                return ['code' => 'lost', 'vars' => ['marker' => '{' . $marker . '}']];
            }
        }
        foreach ($got as $marker) {
            if (!in_array($marker, $wanted, true)) {
                return ['code' => 'invented', 'vars' => ['marker' => '{' . $marker . '}']];
            }
        }
        if ($format === 'plural' || strpos($source, '|') !== false) {
            $forms = count(explode('|', $source));
            $parts = explode('|', $text);
            $empty = false;
            foreach ($parts as $part) {
                if (trim($part) === '') {
                    $empty = true;
                }
            }
            if (count($parts) !== $forms || $empty) {
                return ['code' => 'forms', 'vars' => ['n' => (string) $forms]];
            }
        }
        $sourceTags = self::tags($source);
        $textTags = self::tags($text);
        if ($sourceTags === [] && $textTags !== []) {
            return ['code' => 'html', 'vars' => []];
        }
        $extra = array_values(array_diff($textTags, $sourceTags));
        if ($extra !== []) {
            return ['code' => 'tags', 'vars' => ['tags' => '<' . implode('>, <', $extra) . '>']];
        }

        return null;
    }

    /** El problema contado a una persona, en su idioma. */
    public static function message(array $problem): string
    {
        $vars = isset($problem['vars']) && is_array($problem['vars']) ? $problem['vars'] : [];
        switch (isset($problem['code']) ? (string) $problem['code'] : '') {
            case 'empty':
                return t('The translation is empty');
            case 'long':
                return t('The translation is too long');
            case 'control':
                return t('The translation has control characters');
            case 'lost':
                return t('The marker {marker} is missing', $vars);
            case 'invented':
                return t('The marker {marker} is not in the original text', $vars);
            case 'forms':
                return t('It needs {n} forms separated by a vertical bar (|)', $vars);
            case 'html':
                return t('It has HTML and the original text has none');
            case 'tags':
                return t('It has HTML tags that the original text does not have: {tags}', $vars);
            case 'keep':
                return t('The name {name} must stay as it is', $vars);
        }

        return t('The translation is not valid');
    }

    /** Lo que se guarda de lo escrito: saltos de línea de un solo tipo y sin espacios en los bordes. */
    public static function clean(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Los marcadores distintos de un texto, sin llaves y en el orden en que salen: «Hola {name}, {n}» → name, n.
     *
     * @return string[]
     */
    public static function markers(string $text): array
    {
        $names = [];
        if (preg_match_all('/\{([A-Za-z0-9_.-]+)\}/', $text, $found) > 0) {
            foreach ($found[1] as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Los nombres distintos de las etiquetas HTML de un texto, en minúsculas.
     *
     * @return string[]
     */
    public static function tags(string $text): array
    {
        $names = [];
        if (preg_match_all('#</?([a-zA-Z][a-zA-Z0-9]*)\b[^<>]*>#', $text, $found) > 0) {
            foreach ($found[1] as $name) {
                $name = strtolower($name);
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }
}
