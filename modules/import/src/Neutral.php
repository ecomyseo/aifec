<?php
/**
 * AIFEC — comprobador y utilidades del formato neutro de migración (ver NeutralSchema).
 *
 * Los lectores pasan cada registro por check() en sus pruebas antes de entregarlo; los escritores dan por hecho
 * que lo que reciben lo cumple. También trae las conversiones que todos los lectores necesitan (importes, fechas,
 * pesos y medidas) para que no haya dos formas de redondear.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

final class Neutral
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
    private const LANG = '/^[a-z]{2,3}(-[A-Z]{2})?$/';

    /**
     * Comprueba un registro contra su tipo.
     *
     * @param array<string,mixed> $record
     *
     * @return string[] problemas, en inglés y con la ruta del campo («products.variants[1].price: missing»); vacío = válido
     */
    public static function check(string $type, array $record): array
    {
        $types = NeutralSchema::types();
        if (!isset($types[$type])) {
            return ['unknown type ' . $type];
        }
        $problems = self::fields($types[$type], $record, $type);
        foreach (self::rules($type, $record) as $problem) {
            $problems[] = $problem;
        }

        return $problems;
    }

    /**
     * Resumen estable de un registro: no cambia con el orden de las claves ni con «updated_at». Dice si el registro
     * cambió en el origen desde la última vez.
     *
     * @param array<string,mixed> $record
     */
    public static function hash(array $record): string
    {
        unset($record['updated_at']);

        return sha1((string) json_encode(self::canonical($record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * El mismo registro sin nada que no pueda salir en un registro, un informe o una pantalla (contraseñas y claves).
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    public static function redact(string $type, array $record): array
    {
        $types = NeutralSchema::types();

        return isset($types[$type]) ? self::redactFields($types[$type], $record) : [];
    }

    // ------------------------------------------------------------------ conversiones para los lectores

    /**
     * Un importe decimal del origen («19.99», 19.99, «1234.5») a céntimos, redondeando la mitad hacia arriba (lejos de
     * cero). Sin separador de miles; la coma decimal también vale. Vacío o no numérico = 0.
     *
     * @param mixed $amount
     */
    public static function cents($amount): int
    {
        return self::scaled($amount, 2);
    }

    /**
     * Un importe decimal del origen a millonésimas enteras, sin coma flotante: «82.644628» → 82644628, «19,99» → 19990000, 12 → 12000000.
     * Mismo criterio de lectura que cents() (la coma decimal vale, sin separador de miles; vacío o no numérico = 0), con una diferencia
     * a propósito: de los decimales que pasan del sexto se **cortan**, no se redondean («0.9999999» → 999999), porque la precisión
     * del origen no tiene más que seis y un redondeo hacia arriba inventaría una millonésima que el origen no guardaba.
     *
     * @param mixed $amount
     */
    public static function micros($amount): int
    {
        if (is_int($amount)) {
            return $amount * 1000000;
        }
        $text = is_float($amount) ? number_format($amount, 6, '.', '') : str_replace(',', '.', trim((string) $amount));
        if ($text === '' || preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $m) !== 1 || ($m[2] === '' && (!isset($m[3]) || $m[3] === ''))) {
            return 0;
        }
        $whole = $m[2] === '' ? '0' : $m[2];
        $value = (int) ($whole . substr(str_pad(isset($m[3]) ? $m[3] : '', 6, '0'), 0, 6));

        return $m[1] === '-' ? -$value : $value;
    }

    /**
     * Un porcentaje del origen («21», «21.000», 5.5) a centésimas de punto (2100, 550).
     *
     * @param mixed $percent
     */
    public static function rate($percent): int
    {
        return self::scaled($percent, 2);
    }

    /**
     * Fecha y hora del origen («2026-03-01 10:30:00», en la zona que se diga) al formato neutro en UTC.
     * Vacía, «0000-00-00 00:00:00» o ilegible = null.
     */
    public static function utc(?string $value, string $timezone = 'UTC'): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || strncmp($value, '0000-00-00', 10) === 0) {
            return null;
        }
        try {
            $zone = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC');
            $date = new \DateTimeImmutable($value, $zone);
        } catch (\Throwable $e) {
            return null;
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Un peso del origen a gramos. $unit: kg, g, lb u oz (lo que diga la configuración de la tienda de origen).
     *
     * @param mixed $value
     */
    public static function grams($value, string $unit): int
    {
        $factors = ['kg' => 1000.0, 'g' => 1.0, 'lb' => 453.59237, 'lbs' => 453.59237, 'oz' => 28.349523125];
        $unit = strtolower(trim($unit));

        return self::measure($value, isset($factors[$unit]) ? $factors[$unit] : 1000.0);
    }

    /**
     * Una medida del origen a milímetros. $unit: m, cm, mm o in.
     *
     * @param mixed $value
     */
    public static function millimetres($value, string $unit): int
    {
        $factors = ['m' => 1000.0, 'cm' => 10.0, 'mm' => 1.0, 'in' => 25.4, 'yd' => 914.4];
        $unit = strtolower(trim($unit));

        return self::measure($value, isset($factors[$unit]) ? $factors[$unit] : 10.0);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,string> $schema
     * @param array<mixed,mixed>   $value
     *
     * @return string[]
     */
    private static function fields(array $schema, array $value, string $path): array
    {
        $problems = [];
        foreach ($value as $key => $unused) {
            if (!is_string($key) || !isset($schema[$key])) {
                $problems[] = $path . '.' . $key . ': unknown field';
            }
        }
        foreach ($schema as $name => $declaration) {
            list($kind, $argument, $required, $nullable) = self::parse($declaration);
            $where = $path . '.' . $name;
            if (!array_key_exists($name, $value)) {
                if ($required) {
                    $problems[] = $where . ': missing';
                }
                continue;
            }
            if ($value[$name] === null) {
                if (!$nullable) {
                    $problems[] = $where . ': null not allowed';
                }
                continue;
            }
            foreach (self::value($kind, $argument, $value[$name], $where, $required) as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @return array{0:string,1:string,2:bool,3:bool} clase, argumento, obligatorio, admite null
     */
    private static function parse(string $declaration): array
    {
        $required = false;
        $nullable = false;
        while ($declaration !== '') {
            $last = substr($declaration, -1);
            if ($last === '!') {
                $required = true;
            } elseif ($last === '?') {
                $nullable = true;
            } else {
                break;
            }
            $declaration = substr($declaration, 0, -1);
        }
        $colon = strpos($declaration, ':');

        return $colon === false ? [$declaration, '', $required, $nullable] : [substr($declaration, 0, $colon), substr($declaration, $colon + 1), $required, $nullable];
    }

    /**
     * @param mixed $v
     *
     * @return string[]
     */
    private static function value(string $kind, string $argument, $v, string $where, bool $required): array
    {
        switch ($kind) {
            case 'id':
            case 'ref':
                return is_string($v) && $v !== '' && strlen($v) <= 64 ? [] : [$where . ': expected a source id (non-empty string, 64 chars at most)'];
            case 'refs':
                if (!self::isList($v)) {
                    return [$where . ': expected a list of source ids'];
                }
                foreach ($v as $i => $item) {
                    if (!is_string($item) || $item === '' || strlen($item) > 64) {
                        return [$where . '[' . $i . ']: expected a source id'];
                    }
                }

                return [];
            case 'str':
            case 'text':
            case 'html':
            case 'secret':
                if (!is_string($v)) {
                    return [$where . ': expected a string'];
                }

                return $required && trim($v) === '' ? [$where . ': empty'] : [];
            case 'strs':
                if (!self::isList($v)) {
                    return [$where . ': expected a list of strings'];
                }
                foreach ($v as $i => $item) {
                    if (!is_string($item)) {
                        return [$where . '[' . $i . ']: expected a string'];
                    }
                }

                return [];
            case 'i18n':
            case 'i18n_html':
            case 'i18n_list':
                return self::translations($kind, $v, $where, $required);
            case 'int':
            case 'cents':
            case 'micros':
                return is_int($v) ? [] : [$where . ': expected an integer'];
            case 'int+':
                return is_int($v) && $v >= 0 ? [] : [$where . ': expected an integer of 0 or more'];
            case 'rate':
                return is_int($v) && $v >= 0 && $v <= 100000 ? [] : [$where . ': expected a rate in hundredths of a point (2100 = 21 %)'];
            case 'float':
                return is_int($v) || is_float($v) ? [] : [$where . ': expected a number'];
            case 'bool':
                return is_bool($v) ? [] : [$where . ': expected true or false'];
            case 'date':
                return is_string($v) && preg_match(self::DATE, $v) === 1 ? [] : [$where . ': expected a UTC instant like 2026-03-01T10:30:00Z'];
            case 'country':
                return is_string($v) && preg_match('/^[A-Z]{2}$/', $v) === 1 ? [] : [$where . ': expected a two-letter country code in capitals'];
            case 'lang':
                return is_string($v) && preg_match(self::LANG, $v) === 1 ? [] : [$where . ': expected a language code'];
            case 'enum':
                return is_string($v) && in_array($v, explode('|', $argument), true) ? [] : [$where . ': expected one of ' . $argument];
            case 'image':
                return self::part('image', $v, $where);
            case 'images':
                return self::parts('image', $v, $where);
            case 'part':
                return self::part($argument, $v, $where);
            case 'list':
                return self::parts($argument, $v, $where);
            case 'paths':
                if (!self::isList($v)) {
                    return [$where . ': expected a list of paths'];
                }
                foreach ($v as $i => $item) {
                    if (!is_string($item) || $item === '' || $item[0] !== '/') {
                        return [$where . '[' . $i . ']: a path has to start with /'];
                    }
                }

                return [];
            case 'map':
            case 'secrets':
                return is_array($v) && ($v === [] || !self::isList($v)) ? [] : [$where . ': expected a key => value map'];
        }

        return [$where . ': unknown kind ' . $kind];
    }

    /**
     * @param mixed $v
     *
     * @return string[]
     */
    private static function translations(string $kind, $v, string $where, bool $required): array
    {
        if (!is_array($v) || ($v !== [] && self::isList($v))) {
            return [$where . ': expected [language => ' . ($kind === 'i18n_list' ? 'list of strings' : 'text') . ']'];
        }
        $filled = false;
        foreach ($v as $lang => $text) {
            if (!is_string($lang) || preg_match(self::LANG, $lang) !== 1) {
                return [$where . ': ' . $lang . ' is not a language code'];
            }
            if ($kind === 'i18n_list') {
                if (!self::isList($text)) {
                    return [$where . '.' . $lang . ': expected a list of strings'];
                }
                foreach ($text as $item) {
                    if (!is_string($item)) {
                        return [$where . '.' . $lang . ': expected a list of strings'];
                    }
                    $filled = true;
                }
            } elseif (!is_string($text)) {
                return [$where . '.' . $lang . ': expected a string'];
            } elseif (trim($text) !== '') {
                $filled = true;
            }
        }

        return $required && !$filled ? [$where . ': empty in every language'] : [];
    }

    /**
     * @param mixed $v
     *
     * @return string[]
     */
    private static function part(string $name, $v, string $where): array
    {
        $parts = NeutralSchema::parts();
        if (!isset($parts[$name])) {
            return [$where . ': unknown part ' . $name];
        }
        if (!is_array($v) || ($v !== [] && self::isList($v))) {
            return [$where . ': expected a ' . $name];
        }
        $problems = self::fields($parts[$name], $v, $where);
        if ($name === 'image' && (!isset($v['url']) || $v['url'] === '') && (!isset($v['path']) || $v['path'] === '')) {
            $problems[] = $where . ': an image needs url or path';
        }

        return $problems;
    }

    /**
     * @param mixed $v
     *
     * @return string[]
     */
    private static function parts(string $name, $v, string $where): array
    {
        if (!self::isList($v)) {
            return [$where . ': expected a list of ' . $name];
        }
        $problems = [];
        foreach ($v as $i => $item) {
            foreach (self::part($name, $item, $where . '[' . $i . ']') as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * Reglas que no caben en la declaración de un campo.
     *
     * @param array<string,mixed> $r
     *
     * @return string[]
     */
    private static function rules(string $type, array $r): array
    {
        $problems = [];
        if ($type === 'reviews' && isset($r['rating']) && is_int($r['rating']) && ($r['rating'] < 1 || $r['rating'] > 5)) {
            $problems[] = 'reviews.rating: expected 1 to 5';
        }
        if ($type === 'redirects' && isset($r['code']) && !in_array($r['code'], [301, 302, 307, 308, 410], true)) {
            $problems[] = 'redirects.code: expected 301, 302, 307, 308 or 410';
        }
        if ($type === 'redirects' && isset($r['source']) && is_string($r['source']) && ($r['source'] === '' || $r['source'][0] !== '/')) {
            $problems[] = 'redirects.source: a path has to start with /';
        }
        if ($type === 'media' && (!isset($r['url']) || $r['url'] === '') && (!isset($r['path']) || $r['path'] === '')) {
            $problems[] = 'media: needs url or path';
        }
        if ($type === 'tax_rates' && isset($r['postcodes']) && is_string($r['postcodes']) && preg_match('/^[0-9A-Za-z,\-]*$/', $r['postcodes']) !== 1) {
            $problems[] = 'tax_rates.postcodes: only prefixes or ranges separated by commas, without spaces';
        }
        if ($type === 'products' && isset($r['variants']) && is_array($r['variants'])) {
            $images = isset($r['images']) && is_array($r['images']) ? count($r['images']) : 0;
            $seen = [];
            foreach ($r['variants'] as $i => $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                if (isset($variant['id']) && is_string($variant['id'])) {
                    if (isset($seen[$variant['id']])) {
                        $problems[] = 'products.variants[' . $i . '].id: repeated';
                    }
                    $seen[$variant['id']] = true;
                }
                if (isset($variant['values']) && $variant['values'] === []) {
                    $problems[] = 'products.variants[' . $i . '].values: a variant needs at least one attribute value';
                }
                if (isset($variant['image_index']) && is_int($variant['image_index']) && $variant['image_index'] >= $images) {
                    $problems[] = 'products.variants[' . $i . '].image_index: there is no image at that position';
                }
            }
        }
        if ($type === 'orders' && isset($r['refunds'], $r['items']) && is_array($r['refunds']) && is_array($r['items'])) {
            foreach ($r['refunds'] as $i => $refund) {
                foreach (is_array($refund) && isset($refund['lines']) && is_array($refund['lines']) ? $refund['lines'] : [] as $j => $line) {
                    if (is_array($line) && isset($line['index']) && is_int($line['index']) && $line['index'] >= count($r['items'])) {
                        $problems[] = 'orders.refunds[' . $i . '].lines[' . $j . '].index: there is no item at that position';
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * @param array<string,string> $schema
     * @param array<mixed,mixed>   $value
     *
     * @return array<mixed,mixed>
     */
    private static function redactFields(array $schema, array $value): array
    {
        $parts = NeutralSchema::parts();
        foreach ($value as $name => $v) {
            if (!is_string($name) || !isset($schema[$name]) || $v === null) {
                continue;
            }
            list($kind, $argument) = self::parse($schema[$name]);
            if ($kind === 'secret') {
                $value[$name] = $v === '' ? '' : '***';
            } elseif ($kind === 'secrets' && is_array($v)) {
                foreach ($v as $key => $unused) {
                    $value[$name][$key] = '***';
                }
            } elseif (($kind === 'part' || $kind === 'image') && is_array($v)) {
                $part = $kind === 'image' ? 'image' : $argument;
                $value[$name] = isset($parts[$part]) ? self::redactFields($parts[$part], $v) : $v;
            } elseif (($kind === 'list' || $kind === 'images') && is_array($v)) {
                $part = $kind === 'images' ? 'image' : $argument;
                foreach ($v as $i => $item) {
                    if (is_array($item) && isset($parts[$part])) {
                        $value[$name][$i] = self::redactFields($parts[$part], $item);
                    }
                }
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function canonical($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (self::isList($value)) {
            return array_map([self::class, 'canonical'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonical($item);
        }

        return $value;
    }

    /** @param mixed $value */
    private static function isList($value): bool
    {
        return is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1));
    }

    /**
     * Número decimal a entero con $decimals decimales, redondeando la mitad lejos de cero, sin errores de coma flotante.
     *
     * @param mixed $amount
     */
    private static function scaled($amount, int $decimals): int
    {
        if (is_int($amount)) {
            return $amount * (int) (10 ** $decimals);
        }
        $text = is_float($amount) ? number_format($amount, 6, '.', '') : str_replace(',', '.', trim((string) $amount));
        if ($text === '' || preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $m) !== 1 || ($m[2] === '' && (!isset($m[3]) || $m[3] === ''))) {
            return 0;
        }
        $negative = $m[1] === '-';
        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = str_pad(isset($m[3]) ? $m[3] : '', $decimals + 1, '0');
        $result = (int) ($whole . substr($fraction, 0, $decimals));
        if ((int) $fraction[$decimals] >= 5) {
            ++$result;
        }

        return $negative ? -$result : $result;
    }

    /** @param mixed $value */
    private static function measure($value, float $factor): int
    {
        $number = is_int($value) || is_float($value) ? (float) $value : (float) str_replace(',', '.', trim((string) $value));
        if ($number <= 0) {
            return 0;
        }

        return (int) round($number * $factor);
    }
}
