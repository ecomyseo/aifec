<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Util;

/**
 * JSON con los errores como excepciones y una variante segura para incrustar en HTML.
 */
final class Json
{
    /** UTF-8 sin escapar y barras sin escapar; lanza \JsonException si algo no se puede codificar. */
    public static function encode($value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($value, $flags);
    }

    /** Lanza \JsonException si el texto no es JSON válido. */
    public static function decode(string $json, bool $assoc = true)
    {
        return json_decode($json, $assoc, 512, JSON_THROW_ON_ERROR);
    }

    /** No lanza: si el texto no es JSON válido, devuelve $default. */
    public static function tryDecode(string $json, $default = null)
    {
        $value = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $value : $default;
    }

    /**
     * Para dentro de un <script type="application/ld+json">: los caracteres <, >, &, ' y " salen
     * como \uXXXX, así que ningún «</script>» puede cerrar la etiqueta.
     */
    public static function forHtml($value): string
    {
        return json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
