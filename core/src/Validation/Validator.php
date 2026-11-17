<?php
/**
 * AIFEC — validador de datos de entrada. Lo usan los formularios del panel, los de la tienda y la API.
 *
 * Reglas, separadas por «|»: required, nullable, string (por defecto: recorta espacios), raw (no recorta),
 * int, number (admite coma decimal y millares), decimal (solo punto: lo que manda un <input type=number>), money (→ céntimos), bool, email, url, slug, phone, color, date, datetime, time, array, json,
 * min:n, max:n, between:a,b, len:n, in:a,b,c, regex:/…/, same:campo, unique:tabla,columna[,idQueSeIgnora[,columnaId]],
 * exists:tabla,columna.
 *
 * min/max miden la longitud en los textos, la cantidad de elementos en las listas y el valor en los números.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Validation;

use Aifec\Db\Db;

final class Validator
{
    /** @var Db|null */
    private $db;

    public function __construct(?Db $db = null)
    {
        $this->db = $db;
    }

    /**
     * @param array<string,mixed>                  $input
     * @param array<string,string|array<int,string>> $rules campo => reglas
     *
     * @return array{0:array<string,mixed>,1:array<string,string>} [valores limpios, errores por campo]
     */
    public function validate(array $input, array $rules): array
    {
        $values = [];
        $errors = [];
        foreach ($rules as $field => $spec) {
            $list = is_array($spec) ? $spec : ($spec === '' ? [] : self::split((string) $spec));
            $value = isset($input[$field]) ? $input[$field] : null;
            $error = null;
            $values[$field] = $this->field($value, $list, $input, $error);
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return [$values, $errors];
    }

    /**
     * Valida un solo valor. Devuelve el valor limpio y deja el error (o null) en $error.
     *
     * @param mixed                $value
     * @param string[]             $rules
     * @param array<string,mixed>  $input el resto de campos (para same:)
     *
     * @return mixed
     */
    public function field($value, array $rules, array $input, ?string &$error)
    {
        $error = null;
        $names = [];
        foreach ($rules as $rule) {
            $names[] = strpos($rule, ':') !== false ? substr($rule, 0, (int) strpos($rule, ':')) : $rule;
        }
        $isArray = in_array('array', $names, true);
        if (is_string($value) && !in_array('raw', $names, true)) {
            $value = trim($value);
        }
        if ($isArray) {
            $value = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        } elseif (is_array($value)) {
            $error = t('This value is not valid');

            return null;
        }
        $empty = $value === null || $value === '' || $value === [];
        if ($empty) {
            if (in_array('required', $names, true)) {
                $error = t('This field is required');
            }
            if (in_array('bool', $names, true)) {
                return 0;
            }
            if ($isArray) {
                return [];
            }

            return in_array('nullable', $names, true) || in_array('int', $names, true) || in_array('number', $names, true) || in_array('decimal', $names, true)
                || in_array('money', $names, true) || in_array('date', $names, true) || in_array('datetime', $names, true) ? null : '';
        }

        foreach ($rules as $rule) {
            $arg = '';
            $name = $rule;
            $pos = strpos($rule, ':');
            if ($pos !== false) {
                $name = substr($rule, 0, $pos);
                $arg = substr($rule, $pos + 1);
            }
            $problem = $this->apply($name, $arg, $value, $input);
            if ($problem !== null) {
                $error = $problem;

                return $value;
            }
        }

        return $value;
    }

    /**
     * Aplica una regla. Puede transformar el valor (int, money, bool…). Devuelve el mensaje de error o null.
     *
     * @param mixed               $value
     * @param array<string,mixed> $input
     */
    private function apply(string $name, string $arg, &$value, array $input): ?string
    {
        switch ($name) {
            case 'required':
            case 'nullable':
            case 'string':
            case 'raw':
            case 'array':
                return null;
            case 'int':
                if (!is_int($value) && preg_match('/^-?\d{1,18}$/', (string) $value) !== 1) {
                    return t('Enter a whole number');
                }
                $value = (int) $value;

                return null;
            case 'number':
                $number = self::toFloat((string) $value);
                if ($number === null) {
                    return t('Enter a number');
                }
                $value = $number;

                return null;
            case 'decimal':
                if (!is_int($value) && !is_float($value) && preg_match('/^-?\d{1,15}(\.\d{1,10})?$/', (string) $value) !== 1) {
                    return t('Enter a number');
                }
                $value = (float) $value;

                return null;
            case 'money':
                $cents = self::toCents((string) $value);
                if ($cents === null) {
                    return t('Enter an amount, for example 12,50');
                }
                $value = $cents;

                return null;
            case 'bool':
                $value = in_array($value, [1, '1', true, 'on', 'true', 'yes'], true) ? 1 : 0;

                return null;
            case 'email':
                $value = strtolower((string) $value);

                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 191 ? null : t('Enter a valid email address');
            case 'url':
                return preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', (string) $value) === 1 ? null : t('Enter a full web address, starting with https://');
            case 'slug':
                return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) === 1 ? null : t('Use only lowercase letters, numbers and hyphens');
            case 'code':
                // Un código interno (de un atributo, de un tipo): como un slug, pero también con guiones bajos, que es como llegan
                // los de una importación («alto_tacon»); sin esto, un atributo importado no se podía volver a guardar.
                return preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', (string) $value) === 1 ? null : t('Use only lowercase letters, numbers, hyphens and underscores');
            case 'phone':
                $digits = preg_replace('/[\s().-]+/', '', (string) $value);

                return is_string($digits) && preg_match('/^\+?\d{6,15}$/', $digits) === 1 ? null : t('Enter a valid phone number');
            case 'color':
                return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) === 1 ? null : t('Enter a colour like #1c3a2f');
            case 'date':
                $d = \DateTime::createFromFormat('!Y-m-d', (string) $value);

                return $d !== false && $d->format('Y-m-d') === (string) $value ? null : t('Enter a valid date');
            case 'datetime':
                $text = str_replace('T', ' ', (string) $value);
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $text) === 1) {
                    $text .= ':00';
                }
                $d = \DateTime::createFromFormat('!Y-m-d H:i:s', $text);
                if ($d === false || $d->format('Y-m-d H:i:s') !== $text) {
                    return t('Enter a valid date and time');
                }
                $value = $text;

                return null;
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value) === 1 ? null : t('Enter a time like 09:30');
            case 'json':
                if (is_array($value)) {
                    return null;
                }
                $decoded = json_decode((string) $value, true);
                if (!is_array($decoded)) {
                    return t('This is not valid JSON');
                }
                $value = $decoded;

                return null;
            case 'min':
            case 'max':
                $limit = (float) $arg;
                $size = is_array($value) ? count($value) : (is_int($value) || is_float($value) ? $value : mb_strlen((string) $value));
                if ($name === 'min' && $size < $limit) {
                    return is_array($value) ? t('Choose at least {n}', ['n' => $arg]) : (is_string($value) ? t('Use at least {n} characters', ['n' => $arg]) : t('The minimum is {n}', ['n' => $arg]));
                }
                if ($name === 'max' && $size > $limit) {
                    return is_array($value) ? t('Choose {n} at most', ['n' => $arg]) : (is_string($value) ? t('Use {n} characters at most', ['n' => $arg]) : t('The maximum is {n}', ['n' => $arg]));
                }

                return null;
            case 'between':
                $parts = explode(',', $arg);
                $number = is_int($value) || is_float($value) ? $value : self::toFloat((string) $value);
                if ($number === null || count($parts) !== 2 || $number < (float) $parts[0] || $number > (float) $parts[1]) {
                    return t('Enter a value between {a} and {b}', ['a' => isset($parts[0]) ? $parts[0] : '', 'b' => isset($parts[1]) ? $parts[1] : '']);
                }

                return null;
            case 'len':
                return mb_strlen((string) $value) === (int) $arg ? null : t('It must have exactly {n} characters', ['n' => $arg]);
            case 'in':
                $allowed = explode(',', $arg);
                foreach (is_array($value) ? $value : [$value] as $one) {
                    if (!is_scalar($one) || !in_array((string) $one, $allowed, true)) {
                        return t('Choose one of the options');
                    }
                }

                return null;
            case 'regex':
                return @preg_match($arg, (string) $value) === 1 ? null : t('The format is not valid');
            case 'same':
                $other = isset($input[$arg]) && is_string($input[$arg]) ? trim($input[$arg]) : null;

                return $other === (string) $value ? null : t('The two values do not match');
            case 'unique':
            case 'exists':
                if ($this->db === null) {
                    throw new \LogicException('The "' . $name . '" rule needs a database');
                }
                $parts = explode(',', $arg);
                if (count($parts) < 2) {
                    throw new \LogicException('Use ' . $name . ':table,column');
                }
                $sql = 'SELECT COUNT(*) FROM ' . $this->db->table($parts[0]) . ' WHERE ' . $this->db->ident($parts[1]) . ' = ?';
                $params = [$value];
                if ($name === 'unique' && isset($parts[2]) && $parts[2] !== '') {
                    $sql .= ' AND ' . $this->db->ident(isset($parts[3]) ? $parts[3] : 'id') . ' <> ?';
                    $params[] = $parts[2];
                }
                $count = (int) $this->db->value($sql, $params);
                if ($name === 'unique') {
                    return $count === 0 ? null : t('This value is already in use');
                }

                return $count > 0 ? null : t('Choose one of the options');
        }

        throw new \LogicException('Unknown validation rule: ' . $name);
    }

    /**
     * «1.234,56», «1234.56», «12,5», «12» → 123456, 123456, 1250, 1200. Null si no es un importe.
     * Con un solo separador y tres cifras detrás («1.234») se entiende separador de millares.
     */
    public static function toCents(string $text): ?int
    {
        $number = self::toFloat($text);
        if ($number === null || abs($number) > 90000000000) {
            return null;
        }

        return (int) round($number * 100);
    }

    /** Acepta coma o punto decimal y separadores de millares. Null si no es un número. */
    public static function toFloat(string $text): ?float
    {
        $text = str_replace(["\xc2\xa0", ' ', '€', '%'], '', trim($text));
        if ($text === '' || preg_match('/^-?[\d.,]+$/', $text) !== 1 || preg_match('/\d/', $text) !== 1) {
            return null;
        }
        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');
        if ($comma !== false && $dot !== false) {
            // El último separador es el decimal; el otro, de millares.
            if ($comma > $dot) {
                $text = str_replace(',', '.', str_replace('.', '', $text));
            } else {
                $text = str_replace(',', '', $text);
            }
        } elseif ($comma !== false) {
            $text = substr_count($text, ',') > 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        } elseif ($dot !== false && (substr_count($text, '.') > 1 || preg_match('/^-?\d{1,3}\.\d{3}$/', $text) === 1)) {
            $text = str_replace('.', '', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    /**
     * Parte «required|regex:/a|b/|max:5» sin romper la expresión regular.
     *
     * @return string[]
     */
    private static function split(string $spec): array
    {
        if (strpos($spec, 'regex:') === false) {
            return explode('|', $spec);
        }
        $rules = [];
        $rest = $spec;
        while ($rest !== '') {
            if (strpos($rest, 'regex:') === 0) {
                $delimiter = substr($rest, 6, 1);
                $end = $delimiter !== '' ? strpos($rest, $delimiter . '|', 7) : false;
                // La expresión llega hasta su delimitador de cierre (con sus modificadores) o hasta el final.
                if ($end === false) {
                    $rules[] = $rest;
                    break;
                }
                $rules[] = substr($rest, 0, $end + 1);
                $rest = substr($rest, $end + 2);
                continue;
            }
            $pos = strpos($rest, '|');
            if ($pos === false) {
                $rules[] = $rest;
                break;
            }
            $rules[] = substr($rest, 0, $pos);
            $rest = substr($rest, $pos + 1);
        }

        return $rules;
    }
}
