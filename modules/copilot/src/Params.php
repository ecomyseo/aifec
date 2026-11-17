<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;
use Aifec\Validation\Validator;

/**
 * Los datos de una acción: qué tipos hay, cómo se leen (de un texto escrito, de un formulario o de lo que devuelve un modelo)
 * y cómo se enseñan.
 *
 * Tipos: int, money (céntimos), percent (centésimas de punto: 500 = 5 %), string, bool, enum y date («Y-m-d»).
 * Lo que se guarda en una propuesta ya está en su forma máquina; solo aquí se pasa de una forma a otra.
 */
final class Params
{
    public const TYPES = ['int', 'money', 'percent', 'string', 'bool', 'enum', 'date'];

    /** Un texto libre no pasa de esto: lo demás se corta. */
    private const TEXT_MAX = 500;

    /**
     * Una definición de dato con todos sus campos.
     *
     * @param array<string,mixed> $param
     *
     * @return array<string,mixed>|null null si no vale (sin nombre o de un tipo que no existe)
     */
    public static function define(array $param): ?array
    {
        $name = isset($param['name']) ? (string) $param['name'] : '';
        $type = isset($param['type']) ? (string) $param['type'] : 'string';
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || !in_array($type, self::TYPES, true)) {
            return null;
        }
        $enum = [];
        foreach (isset($param['enum']) && is_array($param['enum']) ? $param['enum'] : [] as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $enum[] = (string) $value;
            }
        }
        if ($type === 'enum' && $enum === []) {
            return null;
        }

        return [
            'name' => $name,
            'type' => $type,
            'required' => !empty($param['required']),
            'enum' => $enum,
            'labels' => isset($param['labels']) && is_array($param['labels']) ? $param['labels'] : [],
            'description' => isset($param['description']) ? (string) $param['description'] : '',
        ];
    }

    /**
     * Lee los valores que llegan (de un formulario, de un texto o de un modelo) y los deja en su forma máquina.
     * Lo vacío no cuenta. Lo desconocido se ignora.
     *
     * @param array<int,array<string,mixed>> $defs  definiciones ya completas (define())
     * @param array<string,mixed>            $input nombre => valor
     *
     * @return array{0:array<string,mixed>,1:array<string,string>} los datos y los nombres de los que no valen (valor «invalid»)
     */
    public static function read(array $defs, array $input): array
    {
        $args = [];
        $errors = [];
        foreach ($defs as $def) {
            $name = (string) $def['name'];
            if (!array_key_exists($name, $input) || $input[$name] === null || $input[$name] === '' || is_array($input[$name])) {
                continue;
            }
            $raw = $input[$name];
            if (!is_scalar($raw)) {
                continue;
            }
            $value = self::parse((string) $def['type'], is_bool($raw) ? ($raw ? '1' : '0') : (string) $raw, $def);
            if ($value === null) {
                $errors[$name] = 'invalid';
                continue;
            }
            $args[$name] = $value;
        }

        return [$args, $errors];
    }

    /**
     * Deja solo los datos conocidos y bien tipados (lo que entregan las reglas, que ya vienen en su forma máquina).
     *
     * @param array<int,array<string,mixed>> $defs
     * @param array<string,mixed>            $args
     *
     * @return array<string,mixed>
     */
    public static function clean(array $defs, array $args): array
    {
        $out = [];
        foreach ($defs as $def) {
            $name = (string) $def['name'];
            if (!array_key_exists($name, $args) || $args[$name] === null) {
                continue;
            }
            $value = $args[$name];
            switch ((string) $def['type']) {
                case 'int':
                case 'money':
                case 'percent':
                    if (is_int($value)) {
                        $out[$name] = $value;
                    }
                    break;
                case 'bool':
                    if (is_bool($value)) {
                        $out[$name] = $value;
                    }
                    break;
                case 'enum':
                    if (is_string($value) && in_array($value, $def['enum'], true)) {
                        $out[$name] = $value;
                    }
                    break;
                case 'date':
                    $date = is_string($value) ? Reader::date($value) : null;
                    if ($date !== null) {
                        $out[$name] = $date;
                    }
                    break;
                default:
                    $text = is_string($value) ? self::parse('string', $value) : null;
                    if ($text !== null) {
                        $out[$name] = $text;
                    }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $def
     *
     * @return int|string|bool|null
     */
    public static function parse(string $type, string $text, array $def = [])
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        switch ($type) {
            case 'int':
                return preg_match('/^[+-]?\d{1,9}$/', $text) === 1 ? (int) $text : null;
            case 'money':
                $cents = Validator::toCents($text);

                return $cents !== null && abs($cents) <= 2000000000 ? $cents : null;
            case 'percent':
                $number = Validator::toFloat($text);

                return $number !== null && abs($number) <= 100000 ? (int) round($number * 100) : null;
            case 'bool':
                $norm = Reader::norm($text);
                if (in_array($norm, ['1', 'true', 'yes', 'y', 'on', 'si', 'sí'], true)) {
                    return true;
                }

                return in_array($norm, ['0', 'false', 'no', 'n', 'off'], true) ? false : null;
            case 'enum':
                $norm = Reader::norm($text);
                foreach (isset($def['enum']) && is_array($def['enum']) ? $def['enum'] : [] as $code) {
                    if (Reader::norm((string) $code) === $norm) {
                        return (string) $code;
                    }
                }

                return null;
            case 'date':
                return Reader::date($text);
            default:
                $clean = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));

                return $clean === '' ? null : mb_substr($clean, 0, self::TEXT_MAX, 'UTF-8');
        }
    }

    /**
     * El valor tal como se escribe en un campo de formulario.
     *
     * @param int|string|bool|null $value
     */
    public static function toForm(string $type, $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($type === 'money') {
            return number_format(((int) $value) / 100, 2, ',', '');
        }
        if ($type === 'percent') {
            $text = number_format(((int) $value) / 100, 2, ',', '');

            return (string) preg_replace('/,?0+$/', '', $text);
        }
        if ($type === 'bool') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * El valor tal como se lo enseña a una persona (ya traducido).
     *
     * @param array<string,mixed>  $def
     * @param int|string|bool|null $value
     */
    public static function display(App $app, array $def, $value): string
    {
        if ($value === null) {
            return '';
        }
        $type = (string) $def['type'];
        if ($type === 'money') {
            return $app->i18n()->money((int) $value, $app->settings()->str('shop.currency', 'EUR'));
        }
        if ($type === 'percent') {
            return self::toForm('percent', $value) . ' %';
        }
        if ($type === 'bool') {
            return $value ? t('Yes') : t('No');
        }
        if ($type === 'enum') {
            $labels = isset($def['labels']) && is_array($def['labels']) ? $def['labels'] : [];

            return isset($labels[(string) $value]) ? t((string) $labels[(string) $value]) : (string) $value;
        }

        return (string) $value;
    }

    /**
     * Qué falta para poder preparar la acción: los obligatorios sin dato y, si hay grupos «uno de estos», los grupos sin ninguno.
     *
     * @param array<int,array<string,mixed>> $defs
     * @param array<string,mixed>            $args
     * @param array<int,string[]>            $oneOf grupos de nombres: tiene que haber al menos uno de cada grupo
     *
     * @return string[] nombres de los datos que faltan (de un grupo, todos los de la lista)
     */
    public static function missing(array $defs, array $args, array $oneOf = []): array
    {
        $missing = [];
        foreach ($defs as $def) {
            if (!empty($def['required']) && !array_key_exists((string) $def['name'], $args)) {
                $missing[] = (string) $def['name'];
            }
        }
        foreach ($oneOf as $group) {
            $given = false;
            foreach ($group as $name) {
                if (array_key_exists($name, $args)) {
                    $given = true;
                }
            }
            if (!$given) {
                foreach ($group as $name) {
                    if (!in_array($name, $missing, true)) {
                        $missing[] = $name;
                    }
                }
            }
        }

        return $missing;
    }

    /**
     * El esquema JSON de los datos de una acción para dárselo a un modelo como herramienta.
     *
     * @param array<int,array<string,mixed>> $defs
     *
     * @return array<string,mixed>
     */
    public static function schema(array $defs): array
    {
        $properties = [];
        $required = [];
        foreach ($defs as $def) {
            $name = (string) $def['name'];
            $description = (string) $def['description'];
            switch ((string) $def['type']) {
                case 'int':
                    $property = ['type' => 'integer'];
                    break;
                case 'money':
                    $property = ['type' => 'string'];
                    $description .= ' (an amount with decimals in the shop currency, like 59.90; negative to lower)';
                    break;
                case 'percent':
                    $property = ['type' => 'number'];
                    $description .= ' (5 means 5 percent; negative lowers)';
                    break;
                case 'bool':
                    $property = ['type' => 'boolean'];
                    break;
                case 'enum':
                    $property = ['type' => 'string', 'enum' => array_values($def['enum'])];
                    break;
                case 'date':
                    $property = ['type' => 'string'];
                    $description .= ' (a date like 2026-12-31)';
                    break;
                default:
                    $property = ['type' => 'string', 'maxLength' => self::TEXT_MAX];
            }
            if ($description !== '') {
                $property['description'] = trim($description);
            }
            $properties[$name] = $property;
            if (!empty($def['required'])) {
                $required[] = $name;
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
