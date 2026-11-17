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
 * Valida y normaliza la entrada de una herramienta contra su esquema JSON.
 *
 * Entiende el trozo de JSON Schema que usan las herramientas: type (object, string, integer, number, boolean, array),
 * properties, required, additionalProperties (false rechaza lo que no esté declarado), default, enum, minimum, maximum,
 * minLength, maxLength, minItems, maxItems e items. Los modelos mandan «5» donde se espera 5, así que un entero o un
 * número que llega como texto numérico se convierte; lo que no se puede convertir es un error. Los textos se recortan
 * de espacios. Los mensajes de error van en inglés: son para el modelo y para quien programa, no para el cliente.
 */
final class SchemaCheck
{
    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     *
     * @return array{ok:bool,value:mixed,errors:string[]} value = la entrada ya normalizada y con sus valores por defecto
     */
    public static function check(array $schema, $value): array
    {
        $errors = [];
        $clean = self::walk($schema, $value, 'input', $errors);

        return ['ok' => $errors === [], 'value' => $clean, 'errors' => $errors];
    }

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     * @param string[]            $errors
     *
     * @return mixed
     */
    private static function walk(array $schema, $value, string $path, array &$errors)
    {
        $type = isset($schema['type']) && is_string($schema['type']) ? $schema['type'] : '';
        switch ($type) {
            case 'object':
                return self::asObject($schema, $value, $path, $errors);
            case 'array':
                return self::asList($schema, $value, $path, $errors);
            case 'integer':
                $value = self::asInteger($value, $path, $errors);
                if (is_int($value)) {
                    self::range($schema, $value, $path, $errors);
                }
                break;
            case 'number':
                $value = self::asNumber($value, $path, $errors);
                if (is_int($value) || is_float($value)) {
                    self::range($schema, $value, $path, $errors);
                }
                break;
            case 'boolean':
                $value = self::asBoolean($value, $path, $errors);
                break;
            case 'string':
                $value = self::asString($schema, $value, $path, $errors);
                break;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = $path . ' must be one of: ' . implode(', ', array_map('strval', $schema['enum']));
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     * @param string[]            $errors
     *
     * @return mixed
     */
    private static function asObject(array $schema, $value, string $path, array &$errors)
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = $path . ' must be an object';

            return [];
        }
        $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        $out = [];
        foreach ($props as $name => $sub) {
            $name = (string) $name;
            $sub = is_array($sub) ? $sub : [];
            if (array_key_exists($name, $value) && $value[$name] !== null) {
                $out[$name] = self::walk($sub, $value[$name], $path . '.' . $name, $errors);
            } elseif (array_key_exists('default', $sub)) {
                $out[$name] = $sub['default'];
            } elseif (in_array($name, $required, true)) {
                $errors[] = $path . '.' . $name . ' is required';
            }
        }
        foreach ($value as $key => $unused) {
            if (isset($props[$key])) {
                continue;
            }
            if (isset($schema['additionalProperties']) && $schema['additionalProperties'] === false) {
                $errors[] = $path . '.' . $key . ' is not accepted';
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     * @param string[]            $errors
     *
     * @return mixed
     */
    private static function asList(array $schema, $value, string $path, array &$errors)
    {
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            $errors[] = $path . ' must be a list';

            return [];
        }
        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            $errors[] = $path . ' needs at least ' . (int) $schema['minItems'] . ' items';
        }
        if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            $errors[] = $path . ' accepts at most ' . (int) $schema['maxItems'] . ' items';
        }
        $items = isset($schema['items']) && is_array($schema['items']) ? $schema['items'] : [];
        $out = [];
        foreach ($value as $i => $item) {
            $out[] = $items === [] ? $item : self::walk($items, $item, $path . '[' . $i . ']', $errors);
        }

        return $out;
    }

    /**
     * @param mixed    $value
     * @param string[] $errors
     *
     * @return mixed
     */
    private static function asInteger($value, string $path, array &$errors)
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1.0E+15) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\s*-?\d{1,15}\s*$/D', $value) === 1) {
            return (int) trim($value);
        }
        $errors[] = $path . ' must be an integer';

        return $value;
    }

    /**
     * @param mixed    $value
     * @param string[] $errors
     *
     * @return mixed
     */
    private static function asNumber($value, string $path, array &$errors)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        $errors[] = $path . ' must be a number';

        return $value;
    }

    /**
     * @param mixed    $value
     * @param string[] $errors
     *
     * @return mixed
     */
    private static function asBoolean($value, string $path, array &$errors)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true', 'TRUE', 'True'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false', 'FALSE', 'False'], true)) {
            return false;
        }
        $errors[] = $path . ' must be true or false';

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     * @param string[]            $errors
     *
     * @return mixed
     */
    private static function asString(array $schema, $value, string $path, array &$errors)
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            $errors[] = $path . ' must be a text';

            return $value;
        }
        $value = trim($value);
        $length = mb_strlen($value, 'UTF-8');
        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $errors[] = $path . ' needs at least ' . (int) $schema['minLength'] . ' characters';
        }
        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $errors[] = $path . ' accepts at most ' . (int) $schema['maxLength'] . ' characters';
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param int|float           $value
     * @param string[]            $errors
     */
    private static function range(array $schema, $value, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = $path . ' must be at least ' . $schema['minimum'];
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = $path . ' must be at most ' . $schema['maximum'];
        }
    }
}
