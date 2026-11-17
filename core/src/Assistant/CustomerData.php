<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Assistant;

use Aifec\Geo\Countries;
use Aifec\Geo\Spain;

/**
 * Datos de un cliente que escribe de cualquier manera («ana ruiz , ana@cliente.test, 12345678Z,
 * calle mayor 5, 28013 madrid»): los saca del texto, dice cuáles faltan, los limpia y los
 * fusiona con lo que ya se sabía.
 *
 * Claves de un cliente: email, tax_id, phone, postcode, address, city, first_name, last_name,
 * name_stated (verdadero si el nombre se dijo expresamente), company, province, province_name
 * y country.
 */
final class CustomerData
{
    private const RE_EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    private const RE_EMAIL_STRICT = '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/D';

    private const RE_TAX_ID = '/\b([0-9]{8}[A-Z]|[XYZ][0-9]{7}[A-Z]|[ABCDEFGHJNPQRSUVW][0-9]{7}[0-9A-J])\b/';

    private const RE_PHONE = '/(?:\+34[\s.-]?)?\b[6789](?:[\s.-]?[0-9]){8}\b/';

    private const RE_ONLY_PHONE = '/^\s*(?:(?:mi\s+)?(?:tel[eé]fono|m[oó]vil|tlf\.?|tel\.?)\s*(?:es|:)?\s*)?(?:\+34[\s.-]?)?[6789](?:[\s.-]?[0-9]){8}\s*\.?\s*$/iuD';

    /** «me llamo…», «mi nombre es…», «soy…» seguido de hasta seis palabras. */
    private const RE_STATED = '/(?<!\p{L})(?:me llamo|mi nombre es|soy)\s+([\p{L}\'-]+(?:\s+[\p{L}\'-]+){0,5})/iu';

    private const RE_STREET_START = '/^(c\/|c\.|calle|cl\.?|avda\.?|av\.?|avenida|plaza|pza\.?|pl\.?|paseo|pº|camino|cmno\.?|carretera|ctra\.?|ronda|traves[ií]a|urb\.?|urbanizaci[oó]n|pol\.?|pol[ií]gono|partida|pasaje|glorieta|rambla|v[ií]a|barrio|apartado)(\s|$)/iu';

    private const RE_STREET_INNER = '/(?:^|\s)(c\/|calle|avda\.?|avenida|plaza|paseo|camino|carretera|ctra\.?|ronda|traves[ií]a|urbanizaci[oó]n|pol[ií]gono|partida|pasaje|glorieta|rambla)(\s|$)/iu';

    /** Un trozo con estas palabras es una frase de compra, no una calle («talla 42»). */
    private const RE_NOT_STREET = '/\b(?:talla|n[uú]mero|num|cantidad|unidad(?:es)?|uds?|ref|referencia|modelo|quiero|tengo|dame|pon|a[ñn]ade|a[ñn]adir|busco|necesito|precio|pedido|hay|tienes|teneis|ten[eé]is)\b/iu';

    /** Palabras que nunca forman parte de un nombre (ya sin tildes y en minúsculas). */
    private const NOT_NAME = 'de del la las los el y e o mi mis tu su es soy me llamo nombre apellido apellidos email correo mail e-mail '
        . 'nif dni nie cif telefono movil empresa factura quiero necesito quisiera pagar comprar gracias hola vale si no para con mis datos son '
        . 'perdona perdone disculpa disculpe buenas buenos';

    private const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y', 'i', 'da', 'van', 'von'];

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /**
     * Caché del conjunto de palabras que no son nombre (se construye una vez por proceso).
     *
     * @var array<string,bool>|null
     */
    private static ?array $notName = null;

    /**
     * Saca del texto lo que encuentre. Solo devuelve las claves que halla: email, tax_id, phone,
     * postcode, address, city, first_name, last_name y name_stated (este último solo si hay
     * nombre: verdadero si se dijo con «me llamo / soy / mi nombre es», falso si se dedujo del
     * trozo que sobraba).
     *
     * @return array<string,mixed>
     */
    public static function extract(string $text): array
    {
        $t = mb_scrub($text, 'UTF-8');
        $r = [];
        $email = null;
        $taxId = null;

        if (preg_match(self::RE_EMAIL, $t, $m) === 1) {
            $email = $m[0];
            $r['email'] = strtolower($email);
        }
        if (preg_match(self::RE_TAX_ID, strtoupper($t), $m) === 1 && Spain::validTaxId($m[1])) {
            $taxId = $m[1];
            $r['tax_id'] = $taxId;
        }

        $name = null;
        $stated = false;
        if (preg_match(self::RE_STATED, $t, $m) === 1) {
            // «me llamo Pedro Ruiz y mi correo…»: el nombre acaba donde empieza una palabra de relleno.
            $cut = [];
            foreach (self::words($m[1]) as $w) {
                if ($cut !== [] && self::isNotName($w) && !self::isParticle($w)) {
                    break;
                }
                $cut[] = $w;
            }
            while (count($cut) > 1 && self::isParticle($cut[count($cut) - 1])) {
                array_pop($cut);
            }
            $name = self::nameOf(implode(' ', $cut));
            $stated = $name !== null;
        }

        // Teléfono y código postal: solo cuentan en un mensaje que trae datos (no en «quiero 2 litros»).
        $rest = $t;
        if ($email !== null) {
            $rest = self::replaceFirst($rest, $email, ',', false);
        }
        if ($taxId !== null) {
            $rest = self::replaceFirst($rest, $taxId, ',', true);
        }
        $phone = preg_match(self::RE_PHONE, $rest, $m) === 1 ? $m[0] : null;
        $chunks = self::chunks($rest);

        // Un código postal solo cuenta si va solo o con su ciudad en su trozo («46800 Xàtiva»), no dentro de una frase.
        $postChunk = '';
        foreach ($chunks as $chunk) {
            if (preg_match('/\b[0-9]{5}\b/', $chunk) === 1) {
                $postChunk = $chunk;
                break;
            }
        }
        $loosePostcode = preg_match('/\b[0-9]{5}\b/', $postChunk, $m) === 1 ? $m[0] : null;
        $afterPostcode = $loosePostcode !== null ? trim(self::replaceFirst($postChunk, $loosePostcode, ' ', false)) : '';
        $trimmedChunk = trim($postChunk);
        $postcodeAtEdge = $loosePostcode !== null
            && (str_starts_with($trimmedChunk, $loosePostcode) || str_ends_with(rtrim($trimmedChunk, " .\t"), $loosePostcode));
        $postcodeInPlace = $postcodeAtEdge && Spain::validPostcode((string) $loosePostcode)
            && ($afterPostcode === '' || self::looksLikePlace($afterPostcode));
        $onlyPhone = preg_match(self::RE_ONLY_PHONE, $rest) === 1;
        $hasStreet = false;
        foreach ($chunks as $chunk) {
            if (self::probableStreet($chunk)) {
                $hasStreet = true;
                break;
            }
        }
        $isDataMessage = $email !== null || $taxId !== null || $postcodeInPlace || $onlyPhone
            || ($phone !== null && $hasStreet);

        if ($phone !== null && $isDataMessage) {
            $normalized = Spain::normalizePhone($phone);
            if ($normalized !== '') {
                $r['phone'] = $normalized;
                $rest = self::replaceFirst($rest, $phone, ',', false);
            }
        }

        if ($isDataMessage) {
            // «ana ruiz , Calle Mayor 5, 28013 Madrid, ana@cliente.test, 12345678Z»: un dato por trozo.
            foreach (self::chunks($rest) as $raw) {
                $chunk = trim($raw);
                if ($chunk === '') {
                    continue;
                }
                if (!isset($r['postcode']) && preg_match('/\b[0-9]{5}\b/', $chunk, $c) === 1 && Spain::validPostcode($c[0])) {
                    $r['postcode'] = $c[0];
                    $place = trim((string) preg_replace('/\s+/u', ' ', self::replaceFirst($chunk, $c[0], ' ', false)));
                    if ($place !== '' && self::looksLikePlace($place)) {
                        $r['city'] = $place;
                    }
                    continue;
                }
                if (self::probableStreet($chunk)) {
                    $street = self::trimStreet($chunk);
                    $r['address'] = isset($r['address']) ? $r['address'] . ', ' . $street : $street;
                    continue;
                }
                if ($name === null) {
                    $name = self::nameOf($chunk);
                    if ($name !== null) {
                        continue;
                    }
                }
                $firstWord = self::words($chunk)[0] ?? '';
                if (!isset($r['city']) && self::looksLikePlace($chunk) && !self::isNotName($firstWord)) {
                    $r['city'] = $chunk;
                }
            }
        }

        if ($name !== null) {
            $r['first_name'] = $name['first'];
            if ($name['last'] !== '') {
                $r['last_name'] = $name['last'];
            }
            $r['name_stated'] = $stated;
        }

        return $r;
    }

    /**
     * Los campos de `$required` que faltan o no son válidos, en el orden en que se piden. Un
     * código postal inexistente cuenta como que falta.
     *
     * @param array<string,mixed> $customer
     * @param string[]            $required
     *
     * @return string[]
     */
    public static function missing(array $customer, array $required = ['email', 'first_name', 'last_name', 'address', 'postcode', 'city']): array
    {
        $country = self::countryOf($customer);
        $missing = [];
        foreach ($required as $field) {
            $value = self::str($customer[$field] ?? null);
            switch ($field) {
                case 'email':
                    $ok = self::validEmail($value);
                    break;
                case 'postcode':
                    $ok = Countries::validPostcode($country, $value);
                    break;
                case 'tax_id':
                    $ok = Spain::validTaxId($value);
                    break;
                case 'phone':
                    $ok = Spain::normalizePhone($value) !== '';
                    break;
                default:
                    $ok = trim($value) !== '';
            }
            if (!$ok) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * El cliente con todos los campos recortados y validados: correo en minúsculas, nombre,
     * apellidos y ciudad con mayúsculas de nombre propio, NIF inválido vaciado, país `ES` por
     * defecto y `province` (código de WooCommerce) y `province_name` deducidas del código postal.
     *
     * @param array<string,mixed> $customer
     *
     * @return array<string,mixed>
     */
    public static function clean(array $customer): array
    {
        $country = self::countryOf($customer);
        $taxId = Spain::normalizeTaxId(self::str($customer['tax_id'] ?? null));
        if ($country === 'ES') {
            $taxId = Spain::validTaxId($taxId) ? $taxId : '';
        } elseif (preg_match('/^[A-Z0-9]{4,20}$/D', $taxId) !== 1) {
            $taxId = '';
        }

        $postcode = strtoupper(self::short($customer['postcode'] ?? null, 10));
        $postcode = Countries::validPostcode($country, $postcode) ? $postcode : '';
        $province = $country === 'ES' ? Spain::provinceByPostcode($postcode) : null;
        $rawProvince = self::short($customer['province'] ?? null, 40);

        $phone = Spain::normalizePhone(self::str($customer['phone'] ?? null));
        if ($phone === '') {
            $phone = self::short(preg_replace('/[^0-9+ ]/', '', self::str($customer['phone'] ?? null)), 20);
        }

        $email = self::str($customer['email'] ?? null);

        return [
            'email' => self::validEmail($email) ? strtolower(trim($email)) : '',
            'first_name' => self::upperFirst(self::properName(self::short($customer['first_name'] ?? null, 60))),
            'last_name' => self::upperFirst(self::properName(self::short($customer['last_name'] ?? null, 80))),
            'tax_id' => $taxId,
            'company' => self::short($customer['company'] ?? null, 100),
            'address' => self::short($customer['address'] ?? null, 150),
            'postcode' => $postcode,
            'city' => self::properName(self::short($customer['city'] ?? null, 80)),
            'province' => $province !== null ? $province['code'] : $rawProvince,
            'province_name' => $province !== null ? $province['name'] : $rawProvince,
            'phone' => $phone,
            'country' => $country,
        ];
    }

    /**
     * Lo nuevo solo pisa lo conocido si trae valor. Un nombre deducido no pisa a uno dicho
     * expresamente.
     *
     * @param array<string,mixed> $known
     * @param array<string,mixed> $new
     *
     * @return array<string,mixed>
     */
    public static function merge(array $known, array $new): array
    {
        $result = $known;
        $knownStated = !empty($known['name_stated']);
        $newStated = !empty($new['name_stated']);
        $nameChanged = false;

        foreach ($new as $key => $value) {
            if ($key === 'name_stated' || !self::hasValue($value)) {
                continue;
            }
            $isName = $key === 'first_name' || $key === 'last_name';
            if ($isName && $knownStated && !$newStated && self::hasValue($known[$key] ?? null)) {
                continue;
            }
            $result[$key] = $value;
            if ($isName) {
                $nameChanged = true;
            }
        }
        if ($nameChanged) {
            $result['name_stated'] = $newStated || $knownStated;
        }

        return $result;
    }

    /**
     * Nombre y apellidos de un trozo: de 1 a 6 palabras de letras y ninguna palabra de relleno.
     *
     * @return array{first:string,last:string}|null
     */
    private static function nameOf(string $chunk): ?array
    {
        $chunk = trim((string) preg_replace('/[.:]+$/', '', trim($chunk)));
        $words = self::words($chunk);
        if ($words === [] || count($words) > 6) {
            return null;
        }
        foreach ($words as $w) {
            if (preg_match('/^[\p{L}\'-]+$/uD', $w) !== 1) {
                return null;
            }
        }
        if (self::isNotName($words[0])) {
            return null;
        }
        foreach ($words as $w) {
            if (self::isNotName($w) && !self::isParticle($w)) {
                return null;
            }
        }
        $pretty = self::capitalize($words);

        return self::splitName($pretty);
    }

    /** Nombres de pila compuestos corrientes (sin tildes, en minúsculas): «María José Vidal» es María José + Vidal. */
    private const COMPOUND_FIRST = [
        'maria jose', 'jose maria', 'jose luis', 'juan carlos', 'ana maria', 'maria carmen', 'juan jose', 'francisco javier',
        'maria angeles', 'luis miguel', 'jose antonio', 'maria teresa', 'juan antonio', 'miguel angel', 'jose manuel',
        'maria dolores', 'maria pilar', 'juan manuel', 'jose ramon', 'maria isabel', 'maria luisa', 'maria elena', 'maria jesus',
        'jose ignacio', 'juan pablo', 'maria victoria', 'maria rosa', 'ana belen', 'jose carlos', 'juan ramon', 'maria mercedes',
        'maria cristina', 'jose angel', 'maria eugenia', 'maria paz', 'juan francisco', 'jose javier', 'maria antonia', 'jose francisco',
    ];

    /**
     * Nombre y apellidos de una lista de palabras: la primera es el nombre, salvo un nombre compuesto corriente («María José»,
     * «José Luis», «Juan Carlos»…) seguido de apellidos, que va entero al nombre (16/11/2026: «María José Vidal» se guardaba
     * como nombre «María» y apellidos «José Vidal»).
     *
     * @param string[] $words
     *
     * @return array{first:string,last:string}
     */
    public static function splitName(array $words): array
    {
        $words = array_values($words);
        if (count($words) >= 3) {
            $pair = strtolower(\Aifec\Search\Tokenizer::stripAccents(mb_strtolower($words[0] . ' ' . $words[1], 'UTF-8')));
            if (in_array($pair, self::COMPOUND_FIRST, true)) {
                return ['first' => $words[0] . ' ' . $words[1], 'last' => implode(' ', array_slice($words, 2))];
            }
        }

        return ['first' => isset($words[0]) ? (string) $words[0] : '', 'last' => implode(' ', array_slice($words, 1))];
    }

    /**
     * @param string[] $words
     *
     * @return string[]
     */
    private static function capitalize(array $words): array
    {
        $out = [];
        foreach (array_values($words) as $i => $w) {
            $lower = mb_strtolower($w, 'UTF-8');
            if ($i > 0 && self::isParticle($w)) {
                $out[] = $lower;
            } elseif ($w !== $lower && $w !== mb_strtoupper($w, 'UTF-8')) {
                $out[] = $w; // ya trae mayúsculas internas («McDonald», «O'Brien»): se respeta
            } else {
                $out[] = implode('-', array_map([self::class, 'upperFirst'], explode('-', $lower)));
            }
        }

        return $out;
    }

    /** «ana ruiz» pasa a «Ana Ruiz»; lo que ya trae alguna mayúscula no se toca. */
    private static function properName(string $s): string
    {
        $t = trim($s);
        if ($t === '' || $t !== mb_strtolower($t, 'UTF-8')) {
            return $t;
        }

        return implode(' ', self::capitalize(self::words($t)));
    }

    private static function upperFirst(string $s): string
    {
        if ($s === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    }

    private static function isParticle(string $w): bool
    {
        return in_array(mb_strtolower($w, 'UTF-8'), self::PARTICLES, true);
    }

    private static function isNotName(string $w): bool
    {
        if (self::$notName === null) {
            self::$notName = [];
            foreach (explode(' ', self::NOT_NAME) as $word) {
                self::$notName[self::fold($word)] = true;
            }
        }

        return isset(self::$notName[self::fold($w)]);
    }

    /** Minúsculas y sin tildes. */
    private static function fold(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), self::ACCENTS);
    }

    /** @return string[] */
    private static function words(string $s): array
    {
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    /** @return string[] los trozos separados por coma, punto y coma, salto de línea o barra vertical */
    private static function chunks(string $s): array
    {
        $chunks = preg_split('/[,;\n|]+/', $s);

        return is_array($chunks) ? $chunks : [];
    }

    /** Sustituye solo la primera aparición literal. */
    private static function replaceFirst(string $subject, string $search, string $by, bool $ignoreCase): string
    {
        $out = preg_replace('/' . preg_quote($search, '/') . '/u' . ($ignoreCase ? 'i' : ''), $by, $subject, 1);

        return is_string($out) ? $out : $subject;
    }

    private static function looksLikePlace(string $chunk): bool
    {
        $t = trim($chunk);
        $length = mb_strlen($t, 'UTF-8');

        return $length >= 2 && $length <= 60
            && preg_match('/^[\p{L}\'’.\- ]+$/uD', $t) === 1
            && count(self::words($t)) <= 5;
    }

    private static function looksLikeStreet(string $chunk): bool
    {
        $t = trim($chunk);

        return preg_match(self::RE_STREET_START, $t) === 1
            || (preg_match('/[0-9]/', $t) === 1 && preg_match('/\p{L}{3,}/u', $t) === 1 && preg_match('/^[0-9]{5}\b/', $t) !== 1);
    }

    /** Un trozo que parece una calle y no una frase de compra («talla 42», «quiero 3 cajas»). */
    private static function probableStreet(string $chunk): bool
    {
        $t = trim($chunk);

        return $t !== '' && self::looksLikeStreet($t) && preg_match(self::RE_NOT_STREET, $t) !== 1;
    }

    /** «mi dirección es calle mayor 5» pasa a «calle mayor 5»: quita el relleno de delante de la vía. */
    private static function trimStreet(string $chunk): string
    {
        $t = trim($chunk);
        if (preg_match(self::RE_STREET_INNER, $t, $m, PREG_OFFSET_CAPTURE) === 1 && $m[1][1] > 0) {
            return trim(substr($t, $m[1][1]));
        }

        return $t;
    }

    private static function validEmail(string $email): bool
    {
        return preg_match(self::RE_EMAIL_STRICT, trim($email)) === 1;
    }

    /** @param mixed $value */
    private static function str($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** Texto con los espacios repetidos reducidos, recortado y cortado a `$max` caracteres. */
    private static function short($value, int $max): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', self::str($value)));

        return mb_substr($t, 0, $max, 'UTF-8');
    }

    /** @param array<string,mixed> $customer */
    private static function countryOf(array $customer): string
    {
        $country = strtoupper(trim(self::str($customer['country'] ?? null)));

        return preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : 'ES';
    }

    /** @param mixed $value */
    private static function hasValue($value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
