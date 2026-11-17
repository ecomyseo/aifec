<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Ai;

/** Adapta un esquema JSON a lo que admite cada proveedor. */
final class AiSchema
{
    /** Claves que Gemini rechaza en un esquema de herramienta o de respuesta. */
    public const GEMINI_UNSUPPORTED = ['additionalProperties', '$schema', '$id', '$comment', '$defs', 'definitions', 'examples'];

    /**
     * Quita del esquema (a cualquier profundidad) las claves de $drop y deja los objetos vacíos
     * de `properties` como `{}` y no como `[]`. Los NOMBRES de propiedad no se tocan: una
     * propiedad llamada «examples» sigue siendo una propiedad.
     *
     * @param array<mixed>  $schema
     * @param string[]      $drop
     *
     * @return array<mixed>
     */
    public static function clean(array $schema, array $drop): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array($key, $drop, true)) {
                continue;
            }
            if ($key === 'properties' && is_array($value)) {
                $props = [];
                foreach ($value as $name => $sub) {
                    $props[$name] = is_array($sub) ? self::clean($sub, $drop) : $sub;
                }
                $out[$key] = $props === [] ? new \stdClass() : $props;
                continue;
            }
            $out[$key] = is_array($value) ? self::clean($value, $drop) : $value;
        }

        return $out;
    }
}
