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

use Aifec\Assistant\CustomerData;

/**
 * Enmascara datos personales: lo que se guarda de una conversación (mensajes, registro de herramientas) y lo que ve el
 * modelo de IA de la compra nunca lleva un correo, un teléfono, un NIF, un nombre ni una calle enteros.
 *
 * Un correo queda «a***@dominio.test», un teléfono «***001» (solo las tres últimas cifras), un NIF «***78Z» y un nombre o
 * una calle, la inicial de cada palabra seguida de «***». El código postal y la ciudad no se enmascaran: sin la calle
 * no identifican a nadie y el asistente los necesita para calcular el envío.
 */
final class Masker
{
    private const RE_EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    private const RE_PHONE = '/(?:\+34[\s.-]?)?\b[6789](?:[\s.-]?[0-9]){8}\b/';

    private const RE_TAX_ID = '/\b(?:[0-9]{8}[A-Za-z]|[XYZxyz][0-9]{7}[A-Za-z])\b/';

    private const RE_STREET = '/(?<![\p{L}])(c\/|calle|avda\.?|avenida|plaza|paseo|camino|carretera|ctra\.?|ronda|traves[ií]a|urbanizaci[oó]n|pol[ií]gono|pasaje|glorieta|rambla)\s+[^,\n;]*[^\s,\n;]/iu';

    /** «ana@correo.test» → «a***@correo.test». */
    public static function email(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }

        return mb_substr($email, 0, 1, 'UTF-8') . '***' . substr($email, $at);
    }

    /** «999 000 001» → «***001». */
    public static function phone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);

        return $digits === '' ? '***' : '***' . substr($digits, -3);
    }

    /** Un NIF o un identificador: solo se ven los tres últimos caracteres. */
    public static function id(string $id): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9]/', '', $id);

        return $clean === '' ? '***' : '***' . substr($clean, -3);
    }

    /** «Ana Ruiz» → «A*** R***»: la inicial de cada palabra. */
    public static function words(string $text): string
    {
        $parts = preg_split('/(\s+)/u', trim($text), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach (is_array($parts) ? $parts : [] as $part) {
            $out .= trim($part) === '' ? ' ' : mb_substr($part, 0, 1, 'UTF-8') . '***';
        }

        return $out;
    }

    /**
     * Una dirección sin lo que identifica: nombre, calle y teléfono enmascarados; código postal, ciudad y país tal cual.
     *
     * @param array<string,mixed> $address first_name, last_name, company, phone, line1, line2, postcode, city, region, country
     *
     * @return array<string,string>
     */
    public static function address(array $address): array
    {
        $get = static function (string $key) use ($address): string {
            return isset($address[$key]) && is_scalar($address[$key]) ? trim((string) $address[$key]) : '';
        };
        $name = trim($get('first_name') . ' ' . $get('last_name'));

        return [
            'name' => $name === '' ? '' : self::words($name),
            'line1' => $get('line1') === '' ? '' : self::words($get('line1')),
            'line2' => $get('line2') === '' ? '' : self::words($get('line2')),
            'postcode' => $get('postcode'),
            'city' => $get('city'),
            'country' => $get('country'),
            'phone' => $get('phone') === '' ? '' : self::phone($get('phone')),
        ];
    }

    /**
     * El texto sin datos personales: primero lo que se sabe que lo es ($known: nombres, calles, correos…), después lo que
     * tiene la forma de un correo, un teléfono o un NIF y, si se pide, las calles.
     *
     * @param string[] $known valores personales conocidos (se enmascaran allí donde aparezcan como palabra entera)
     */
    public static function text(string $text, array $known = [], bool $streets = false): string
    {
        // Primero lo que tiene forma de dato (correo, teléfono, NIF): así un nombre conocido no se come la «ana» de «ana@correo.test».
        $out = (string) preg_replace_callback(self::RE_EMAIL, static function (array $m): string {
            return self::email($m[0]);
        }, $text);
        $out = (string) preg_replace_callback(self::RE_PHONE, static function (array $m): string {
            return self::phone($m[0]);
        }, $out);
        $out = (string) preg_replace_callback(self::RE_TAX_ID, static function (array $m): string {
            return self::id($m[0]);
        }, $out);

        $values = [];
        foreach ($known as $value) {
            $value = is_scalar($value) ? trim((string) $value) : '';
            if (mb_strlen($value, 'UTF-8') >= 3 && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }
        usort($values, static function (string $a, string $b): int {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });
        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $mask = self::email($value);
            } elseif (preg_match('/^[+0-9\s.-]{6,}$/D', $value) === 1) {
                $mask = self::phone($value);
            } else {
                $mask = self::words($value);
            }
            $replaced = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu', str_replace(['\\', '$'], ['\\\\', '\\$'], $mask), $out);
            $out = is_string($replaced) ? $replaced : $out;
        }
        if ($streets) {
            $out = (string) preg_replace(self::RE_STREET, '$1 ***', $out);
        }

        return $out;
    }

    /**
     * Un mensaje del cliente tal como se guarda. Si trae datos de contacto (correo, teléfono, NIF, código postal o calle) se
     * enmascaran también su nombre y su calle; un mensaje normal («busco una silla roja») se guarda sin tocar.
     */
    public static function forStorage(string $message): string
    {
        $found = CustomerData::extract($message);
        $personal = isset($found['email']) || isset($found['phone']) || isset($found['tax_id']) || isset($found['postcode']) || isset($found['address']);
        $known = [];
        if ($personal) {
            foreach (['first_name', 'last_name', 'address'] as $key) {
                if (isset($found[$key]) && is_scalar($found[$key])) {
                    $known[] = (string) $found[$key];
                }
            }
            // Un texto escrito sin comas («ana@correo.test Ana Ruiz calle Mayor 1 28013 Madrid») se reparte en trozos con el lector
            // de la caja, si está: así el nombre y la calle también se enmascaran.
            $reader = 'Aifec\\Modules\\Orders\\Checkout\\TextReader';
            if (class_exists($reader)) {
                try {
                    $read = $reader::read($message, [], true);
                    foreach (['first_name', 'last_name', 'line1', 'line2'] as $key) {
                        if (isset($read['fields'][$key]) && is_scalar($read['fields'][$key])) {
                            $known[] = (string) $read['fields'][$key];
                        }
                    }
                } catch (\Throwable $e) {
                    // Sin el lector se enmascara con lo que ya se sabe.
                }
            }
        }

        return self::text($message, $known, $personal);
    }

    /** Un posible número de tarjeta: de 14 a 19 cifras, seguidas o en grupos separados por un espacio o un guion. */
    private const RE_CARD = '/(?<![\d-])\d(?:[ -]?\d){13,18}(?![\d-])/';

    /** Un posible IBAN: dos letras, dos cifras y el resto, seguido o en grupos separados por un espacio. */
    private const RE_IBAN = '/(?<![A-Za-z0-9])[A-Za-z]{2}\d{2}(?: ?[A-Za-z0-9]){11,30}/';

    /** El código de seguridad de una tarjeta cuando se dice que lo es («cvv 123», «código de seguridad: 1234»). */
    private const RE_CVV = '/\b(cvv2?|cvc2?|c[oó]digo\s+de\s+seguridad|c[oó]digo\s+secreto|security\s+code)\b([^\d\n]{0,12})\d{3,4}\b/iu';

    /** Una caducidad («12/27», «03-2028»): solo se tapa en un mensaje que traía una tarjeta. */
    private const RE_EXPIRY = '/(?<![\d\/-])(?:0[1-9]|1[0-2])\s?[\/-]\s?(?:20\d{2}|\d{2})(?![\d\/-])/';

    /** Lo que mide el IBAN de cada país (ISO 13616). Los demás no se miran: sin su largo no se puede comprobar. */
    private const IBAN_LENGTHS = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'EE' => 20,
        'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IS' => 26,
        'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28,
        'PT' => 25, 'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
    ];

    /**
     * El texto sin datos de pago: números de tarjeta, IBAN, códigos de seguridad y, si había tarjeta, su caducidad. Es lo
     * primero que se hace con lo que escribe el cliente: lo que sale de aquí es lo único que ven la conversación guardada, las
     * herramientas, el registro y el modelo. La tienda nunca cobra por el chat, así que esos datos no le hacen falta a nadie.
     *
     * Solo se tapa lo que se puede comprobar (la cifra de control de la tarjeta y su marca; el largo y el resto del IBAN):
     * un código de barras o una referencia larga se quedan como están.
     *
     * @param bool|null $found sale a true si se tapó algo
     */
    public static function secrets(string $text, ?bool &$found = null): string
    {
        $found = false;
        // Primero el IBAN: sus cifras, sueltas, podrían pasar por una tarjeta.
        $iban = false;
        $out = $text;
        $masked = preg_replace_callback(self::RE_IBAN, static function (array $m) use (&$iban): string {
            $plain = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $m[0]));
            $country = substr($plain, 0, 2);
            if (!isset(self::IBAN_LENGTHS[$country])) {
                return $m[0];
            }
            $length = self::IBAN_LENGTHS[$country];
            if (strlen($plain) < $length || !self::isIban(substr($plain, 0, $length))) {
                return $m[0];
            }
            $iban = true;

            return '***' . substr($plain, $length - 4, 4) . self::after($m[0], $length, '/[A-Za-z0-9]/');
        }, $out);
        $out = is_string($masked) ? $masked : $out;

        $card = false;
        $masked = preg_replace_callback(self::RE_CARD, static function (array $m) use (&$card): string {
            $digits = (string) preg_replace('/\D/', '', $m[0]);
            // La tarjeta es el principio de lo encontrado: detrás puede venir pegada la caducidad o el código.
            foreach ([16, 15, 14, 19, 18, 17] as $length) {
                if (strlen($digits) >= $length && self::isCard(substr($digits, 0, $length))) {
                    $card = true;

                    return '***' . substr($digits, $length - 4, 4) . self::after($m[0], $length, '/\d/');
                }
            }

            return $m[0];
        }, $out);
        $out = is_string($masked) ? $masked : $out;

        $cvv = false;
        $masked = preg_replace_callback(self::RE_CVV, static function (array $m) use (&$cvv): string {
            $cvv = true;

            return $m[1] . $m[2] . '***';
        }, $out);
        $out = is_string($masked) ? $masked : $out;

        if ($card) {
            $masked = preg_replace(self::RE_EXPIRY, '**/**', $out);
            $out = is_string($masked) ? $masked : $out;
        }
        $found = $card || $iban || $cvv;

        return $out;
    }

    /** Lo que queda de $match detrás de sus primeros $count caracteres que casan con $class (cifras, o cifras y letras). */
    private static function after(string $match, int $count, string $class): string
    {
        $seen = 0;
        $length = strlen($match);
        for ($i = 0; $i < $length; ++$i) {
            if (preg_match($class, $match[$i]) === 1 && ++$seen === $count) {
                return substr($match, $i + 1);
            }
        }

        return '';
    }

    /** ¿Es un número de tarjeta? La cifra de control (Luhn) cuadra y empieza como empieza una tarjeta de ese largo. */
    private static function isCard(string $digits): bool
    {
        $length = strlen($digits);
        $two = (int) substr($digits, 0, 2);
        $three = (int) substr($digits, 0, 3);
        $four = (int) substr($digits, 0, 4);
        $maestro = $two === 50 || ($two >= 56 && $two <= 69);
        if ($length === 14) {
            $brand = $two === 36 || $two === 38 || ($three >= 300 && $three <= 305);
        } elseif ($length === 15) {
            $brand = $two === 34 || $two === 37;
        } elseif ($length === 16) {
            $brand = $digits[0] === '4' || ($two >= 51 && $two <= 55) || ($four >= 2221 && $four <= 2720) || $two === 35 || $maestro;
        } else {
            $brand = ($length === 19 && $digits[0] === '4') || $maestro;
        }
        if (!$brand) {
            return false;
        }
        $sum = 0;
        $double = false;
        for ($i = $length - 1; $i >= 0; --$i) {
            $n = (int) $digits[$i];
            if ($double) {
                $n *= 2;
                $n = $n > 9 ? $n - 9 : $n;
            }
            $sum += $n;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    /** ¿Es un IBAN? Sus cuatro primeros caracteres al final, las letras como números (A = 10…) y el resto de dividir por 97 es 1. */
    private static function isIban(string $iban): bool
    {
        $moved = substr($iban, 4) . substr($iban, 0, 4);
        $rest = 0;
        $length = strlen($moved);
        for ($i = 0; $i < $length; ++$i) {
            $char = $moved[$i];
            $value = ctype_digit($char) ? $char : (string) (ord($char) - 55);
            $rest = (int) (($rest . $value) % 97);
        }

        return $rest === 1;
    }
}
