<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Checkout;

use Aifec\Assistant\CustomerData;
use Aifec\Geo\Countries;
use Aifec\Geo\Spain;

/**
 * Entiende lo que alguien escribe de cualquier manera («Ana Ruiz, calle Mayor 1 3ºB, 28013 Madrid, 999000001,
 * ana@correo.test») y lo convierte en los campos de la caja. Sin estado: no guarda nada y no inventa nada.
 *
 * Se apoya en CustomerData (nombre, correo, teléfono, NIF y dirección) y añade lo que la caja necesita: el piso aparte
 * de la calle, la provincia (siempre, a partir del código postal) y una segunda pasada para los textos escritos sin
 * comas. Lo que no está en el texto no se rellena: la ciudad solo sale si el texto la trae.
 *
 * Cada duda lleva su motivo: «guessed» (se ha deducido y puede estar mal), «invalid» (el texto trae algo con esa forma
 * pero no vale) o «ambiguous» (hay más de uno y se ha tomado el primero).
 *
 * Antes de leer, en castellano y en inglés: quita la frase de arranque que no es un dato («Yes, let's go to checkout.»,
 * «Sí, vamos.», «Mis datos:»), usa las etiquetas que escribe la persona, con dos puntos o sin ellos (name/nombre,
 * address/dirección, phone/teléfono, email/correo, company/empresa, VAT/NIF/CIF, postcode/CP, city/ciudad), entiende
 * «Invoice to company X» y «Factura a nombre de X» (la empresa) y aparta las frases de conversación que no son datos
 * («I want cash on delivery»). El NIF con etiqueta se guarda como se dio, sin espacios, puntos ni guiones.
 */
final class TextReader
{
    private const TEXT_MAX = 2000;

    /** Palabras de una frase de arranque que no trae ningún dato («Yes, let's go to checkout.», «Sí, vamos a la caja.»). */
    private const OPENING_WORDS = [
        'yes', 'yeah', 'yep', 'si', 'ok', 'okay', 'vale', 'venga', 'vamos', 'let', 'lets', 's', 'go', 'to', 'the', 'a', 'la', 'al', 'el',
        'checkout', 'caja', 'ir', 'pagar', 'pay', 'perfecto', 'perfecta', 'perfect', 'genial', 'great', 'good', 'bien', 'muy', 'sure',
        'claro', 'de', 'acuerdo', 'hola', 'hi', 'hello', 'buenas', 'buenos', 'dias', 'tardes', 'thanks', 'thank', 'you', 'gracias',
        'please', 'por', 'favor', 'porfa', 'pues', 'bueno', 'well', 'so', 'and', 'y', 'now', 'ahora', 'ya', 'here', 'aqui', 'are', 'is',
        'son', 'es', 'tienes', 'my', 'mi', 'mis', 'details', 'data', 'datos', 'for', 'para', 'order', 'pedido', 'envio', 'shipping',
        'delivery', 'entrega', 'quiero', 'want', 'i', 'comprar', 'buy', 'continue', 'continuar', 'seguimos', 'sigamos', 'proceed',
        'finalizar', 'terminar', 'compra', 'purchase', 'it', 'lo', 'eso', 'that', 'this', 'esto', 'then', 'entonces', 'adelante', 'done',
        'listo', 'ready', 'these', 'estos', 'our', 'nuestros',
    ];

    /** Palabras de conversación: un trozo sin cifras ni correo que las lleva no es un dato («I want cash on delivery»). */
    private const RE_CHATTER = '/\b(?:quiero|quisiera|want|would|like|envio|shipping|delivery|pago|payment|pay|pagar|reembolso|cash|urgente|urgent|estandar|standard|transferencia|transfer|resumen|summary|confirm\w*|ensename|show|total|gracias|thanks|please|favor|porfa|pedido|order|checkout|caja|compra|comprar|tarjeta|card)\b/u';

    /** Quien se presenta en el trozo («me llamo…», «my name is…»): ese trozo no se aparta aunque hable de otras cosas. */
    private const RE_STATES_NAME = '/\b(?:me llamo|mi nombre|soy|my name|name is|i am|nombre)\b/u';

    /** Lo que va detrás del valor de una etiqueta: el fin del trozo o de la frase. */
    private const VALUE_END = '(?=\s*(?:[,;\n]|\.(?:\s|$)|$))';

    /** Quien entrega a otra persona («entregar a Lucía Navarro Sanz porfa»): lo que va detrás es un nombre. */
    private const RE_DELIVER_TO = '/\b(?:entregar|entr[eé]ga(?:lo|la|melo|mela)?|deliver(?:\s+it)?)\s+(?:a|to)\s+(?:nombre\s+de\s+)?([\p{L}][\p{L}\'\- ]{2,80}?)(?=\s*(?:[,;\n]|\.(?:\s|$)|\d|@|$))/iu';

    /** Lo que no empieza un nombre de persona («Las Palmas», «the office»). */
    private const NAME_START_NOT = ['la', 'las', 'los', 'el', 'the', 'de', 'del', 'my', 'mi', 'su', 'a', 'an'];

    /** Lo que se dice por cortesía al final de un nombre («Lucía Navarro porfa»): no es parte de él. */
    private const RE_COURTESY_TAIL = '/(?:\s+(?:porfa|por favor|please|gracias|thanks|thank you|y|and|vale|ok))+$/iu';

    /** Palabras que abren una vía; antes de ellas se corta cuando el texto no trae comas. */
    private const STREET_WORDS = 'c\/|c\.|calle|carrer|cl\.|avda\.?|avenida|avinguda|plaza|pla[cç]a|paseo|passeig|camino|carretera|ctra\.?|ronda|traves[ií]a|urbanizaci[oó]n|pol[ií]gono|pasaje|glorieta|rambla';

    /** Una pieza del piso o la puerta: «3º A», «2n», «1a», «bajo», «esc. B», «izq.». */
    private const FLOOR_UNIT = '(?:\d{1,2}\s*[ºª°]\.?(?:\s*[A-Za-z]\b\.?)?|\d{1,2}\s*(?:n|r|t|er)\b\.?|\d{1,2}\s*[A-Za-z]\b\.?|(?:piso|planta|puerta|portal|escalera|esc|bloque|local|nave)\.?\s*[A-Za-z0-9]{1,3}\b\.?|(?:bajo|bajos|entresuelo|principal|[aá]tico|s[oó]tano|interior|exterior)\b\.?|(?:izq|izda|izquierda|dcha|der|derecha|centro)\b\.?)';

    /** Palabras de una corrección («uy la dirección está mal, es…»): lo que va delante de la calle y las lleva no es un nombre. */
    private const RE_CORRECTION = '/\b(?:direccion|domicilio|address|mal|error|equivocad[oa]|incorrect[oa]|wrong|cambi\w*|corrig\w*|modific\w*|actualiz\w*|change|correct|es|sera|pon\w*)\b/u';

    /** Quien se presenta («me llamo Ana y vivo en…») no está corrigiendo una dirección. */
    private const RE_INTRODUCTION = '/\b(?:me llamo|mi nombre|soy|my name|name is|i am)\b/u';

    /**
     * Palabras que no están en ningún nombre de persona: las de una corrección, las de una dirección, las de asentimiento o
     * cortesía («yes», «ok», «hola») y las etiquetas («address», «name»). Un nombre que lleve una se rechaza.
     */
    private const NOT_NAME_WORDS = [
        'direccion', 'domicilio', 'mal', 'error', 'equivocada', 'equivocado', 'incorrecta', 'calle', 'plaza', 'avenida', 'avda', 'paseo',
        'piso', 'cambia', 'cambiala', 'cambiar', 'corrige', 'corregir', 'es', 'son', 'esta', 'estan', 'era', 'pon', 'ponla', 'quiero', 'uy',
        'vaya', 'perdona', 'perdon', 'address', 'wrong', 'street', 'change', 'please', 'thanks', 'gracias', 'hola', 'vale', 'numero',
        'envio', 'pedido', 'carrito', 'correo', 'email', 'telefono', 'movil', 'phone', 'mi', 'tu', 'su', 'si', 'no',
        'yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'hello', 'hi', 'hey', 'bye', 'adios', 'porfa', 'perfecto', 'buenas', 'name', 'nombre',
        'details', 'datos', 'company', 'empresa', 'invoice', 'factura', 'city', 'ciudad', 'vat', 'nif', 'cif', 'carrer', 'avinguda',
        'placa', 'passeig', 'checkout', 'caja', 'my', 'your', 'the', 'let', 'lets',
    ];

    /** El piso, la puerta o la escalera que va detrás del número de la calle. */
    private const RE_FLOOR = '/^(.*?\b[0-9]+[A-Za-z]?)[\s,]+((?:[0-9]+\s*[ºª°]\s*[A-Za-z]?|[0-9]+\s*[A-Za-z]\b|(?:piso|planta|puerta|bajo|bajos|entresuelo|escalera|esc\.?|portal|[aá]tico)\b).*)$/iu';

    /**
     * @param string               $text          lo que escribió la persona
     * @param array<string,string> $known         lo que ya hay en el borrador, en campos planos (email, first_name, line1…)
     * @param bool                 $needsShipping false = todo es digital: no hace falta calle ni código postal
     *
     * @return array{fields:array<string,string>,doubts:array<string,string>,missing:string[]}
     */
    public static function read(string $text, array $known, bool $needsShipping): array
    {
        $text = trim(mb_scrub(mb_substr($text, 0, self::TEXT_MAX, 'UTF-8'), 'UTF-8'));
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        // «3.º A» → «3º A», «Valencia (España)» → «Valencia»; el piso suelto («, 3º A,») se aparta para ponerlo en la segunda línea; y lo que
        // va delante de la calle en una corrección («la dirección está mal, es Plaza Inventada 7») no es un nombre.
        $text = self::normalize($text);
        // «I'm from the UK.», «Soy de Chile.»: de dónde es quien escribe no es un dato de la entrega (ni su país ni un nombre).
        $text = trim((string) preg_replace('/(?:^|(?<=[.!?\n]))\s*(?:i\'?m|i\s+am|we\'?re|we\s+are|soy|somos|vengo|venimos|je\s+suis|nous\s+sommes)\s+(?:from|de|desde|d\')\s*[\p{L}\' ]{2,40}[.!]+/iu', '', $text));
        // «vale pues lucas martin perez», «lucas, martin perez»: el mensaje es solo el nombre.
        $alone = self::nameAlone($text);
        if ($alone !== null) {
            $merged = $alone + $known;

            return ['fields' => $alone, 'doubts' => [], 'missing' => self::missing($merged, isset($merged['country']) && is_string($merged['country']) && $merged['country'] !== '' ? $merged['country'] : 'ES', $needsShipping)];
        }
        // «Yes, let's go to checkout. My details: …»: la frase de arranque no es un nombre.
        $text = self::dropOpening($text);
        $floor = '';
        $text = self::takeFloor($text, $floor);
        // Lo que va con etiqueta (address, NIF, company, city…) se asigna directamente y se quita del texto.
        $labeled = [];
        $doubtful = [];
        $text = self::takeLabels($text, $labeled, $doubtful);
        $text = self::dropChatter($text);
        $text = self::dropPreamble($text);
        $found = trim($text, " ,;.\n\t") === '' ? [] : CustomerData::extract($text);
        if (trim($text, " ,;.\n\t") !== '' && (!isset($found['address']) || !isset($found['city']))) {
            // Sin comas ni saltos de línea no hay trozos: se vuelve a leer con los datos separados.
            foreach (CustomerData::extract(self::separate($text)) as $key => $value) {
                if (!isset($found[$key])) {
                    $found[$key] = $value;
                }
            }
        }
        if (isset($labeled['first_name'])) {
            // Un nombre con etiqueta («Name: …», «entregar a …») manda sobre uno deducido.
            unset($found['first_name'], $found['last_name']);
            $found['name_stated'] = true;
        }

        $fields = self::overlay(self::fields($found), $labeled);
        $fields = self::withFloor($fields, $floor);
        $doubts = self::doubts($text, $found, $fields) + $doubtful;

        $merged = $fields + $known;
        $country = isset($merged['country']) && is_string($merged['country']) && $merged['country'] !== '' ? $merged['country'] : 'ES';

        return ['fields' => $fields, 'doubts' => $doubts, 'missing' => self::missing($merged, $country, $needsShipping)];
    }

    /**
     * ¿Puede ser el nombre de una persona? Sin cifras, sin palabras de dirección o de corrección, ni de asentimiento o cortesía
     * («yes», «ok», «vale», «hola», «thanks») ni etiquetas («address», «name»). Lo usa también el asistente con los campos que le
     * llegan ya separados.
     */
    public static function personName(string $name): bool
    {
        return trim($name) !== '' && self::plausibleName($name);
    }

    /** Un NIF o un número de IVA tal como se dio: sin espacios, puntos ni guiones y en mayúsculas («ESB00000000» sigue así). */
    public static function taxId(string $raw): string
    {
        return strtoupper((string) preg_replace('/[\s.\-]+/u', '', trim($raw)));
    }

    /**
     * Los campos de la caja a partir de lo que halló CustomerData, limpios y en un orden fijo.
     *
     * @param array<string,mixed> $found
     *
     * @return array<string,string>
     */
    private static function fields(array $found): array
    {
        if ($found === []) {
            return [];
        }
        if (isset($found['first_name']) && !self::plausibleName(trim((string) $found['first_name'] . ' ' . (isset($found['last_name']) ? (string) $found['last_name'] : '')))) {
            // «uy la dirección está mal» no es un nombre: se deja el que había.
            unset($found['first_name'], $found['last_name'], $found['name_stated']);
        }
        $clean = CustomerData::clean($found + ['country' => 'ES']);
        $out = [];
        $put = static function (string $key, string $value) use (&$out): void {
            if ($value !== '') {
                $out[$key] = $value;
            }
        };
        if (isset($found['email'])) {
            $put('email', (string) $clean['email']);
        }
        if (isset($found['first_name'])) {
            $put('first_name', (string) $clean['first_name']);
        }
        if (isset($found['last_name'])) {
            $put('last_name', (string) $clean['last_name']);
        }
        if (isset($found['phone'])) {
            $put('phone', (string) $clean['phone']);
        }
        if (isset($found['address'])) {
            $address = self::splitAddress((string) $found['address']);
            $put('line1', $address[0]);
            $put('line2', $address[1]);
        }
        if (isset($found['postcode'])) {
            $put('postcode', (string) $clean['postcode']);
        }
        if (isset($found['city'])) {
            $put('city', (string) $clean['city']);
        }
        if (isset($out['postcode'])) {
            $province = Spain::provinceByPostcode($out['postcode']);
            if ($province !== null) {
                $put('region', (string) $province['prefix']);
            }
        }
        if (isset($found['tax_id'])) {
            $put('vat_id', (string) $clean['tax_id']);
        }

        return $out;
    }

    /**
     * «calle Mayor 1 3ºB» → [«Calle Mayor 1», «3ºB»]. Sin piso, el segundo es una cadena vacía.
     *
     * @return array{0:string,1:string}
     */
    private static function splitAddress(string $address): array
    {
        $address = trim($address);
        $line2 = '';
        if (preg_match(self::RE_FLOOR, $address, $m) === 1) {
            $address = trim($m[1]);
            $line2 = trim($m[2], " ,.\t");
        }
        if ($address !== '') {
            $address = mb_strtoupper(mb_substr($address, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($address, 1, null, 'UTF-8');
        }

        return [$address, $line2];
    }

    /**
     * El texto con las formas que el lector de datos no entiende ya escritas de otra manera: «3.º» → «3º» y el país entre
     * paréntesis detrás de la ciudad («28013 Madrid (España)») fuera.
     */
    private static function normalize(string $text): string
    {
        // «Daniel+Soler,+Calle+…»: el lector de un asistente que no descodifica el «+» de la dirección lo deja tal cual, y sin
        // un solo espacio no se separaba nada (16/11/2026, Claude: «no he encontrado ningún dato personal ahí»).
        if (strpos($text, '+') !== false && preg_match('/\s/u', $text) !== 1) {
            $text = str_replace('+', ' ', $text);
        }
        $out = preg_replace('/(?<=\d)\.(?=\s*[ºª°])/u', '', $text);
        $text = is_string($out) ? $out : $text;
        $out = preg_replace('/\s*\(\s*(?:espa[nñ]a|spain|espanya)\s*\)/iu', '', $text);
        $text = is_string($out) ? $out : $text;
        // «50001 Zaragoza (Zaragoza)», «28013 Madrid (Madrid)»: la provincia entre paréntesis detrás de la ciudad no es parte de
        // ella, y con ella dentro la ciudad se perdía.
        $out = preg_replace('/(\b\d{5}\s+\p{L}[\p{L}\' -]{1,40}?)\s*\(\s*\p{L}[\p{L}\' -]{1,40}\)/u', '$1', $text);
        $text = is_string($out) ? $out : $text;
        // Lo que se escribe después de la ciudad, en otra frase («46001 Valencia. Quiero el envío urgente»), no es parte de ella.
        $out = preg_replace('/(\b\d{5}\s+\p{L}[\p{L}\' -]{1,40}?)\s*\.\s+(?=[\p{Lu}¿¡])/u', "$1\n", $text);
        $text = is_string($out) ? $out : $text;

        return rtrim($text, " .\t");
    }

    /**
     * Quita del principio las frases que no traen ningún dato, solo asentimiento o ganas de seguir («Yes, let's go to checkout.»,
     * «Sí, vamos a la caja.», «Ok.», «My details:»). Se para en la primera frase que tenga una palabra de otra clase.
     */
    private static function dropOpening(string $text): string
    {
        while (preg_match('/^([^.!?:\n]{1,120}?)[.!?:]+(?:\s+|$)/u', $text, $m) === 1) {
            $words = preg_split('/[^a-z0-9@]+/', self::fold($m[1]), -1, PREG_SPLIT_NO_EMPTY);
            $words = is_array($words) ? $words : [];
            if ($words === []) {
                break;
            }
            foreach ($words as $word) {
                if (!in_array($word, self::OPENING_WORDS, true)) {
                    return $text;
                }
            }
            $text = ltrim(substr($text, strlen($m[0])));
        }

        return $text;
    }

    /**
     * Saca lo que llega con etiqueta, en castellano o en inglés y con dos puntos o sin ellos, y lo quita del texto: la empresa
     * («Invoice to company X», «Factura a nombre de X», «Empresa: X»), el NIF («VAT ID ESB00000000», «NIF B12345674»: tal como se
     * dio, sin espacios, puntos ni guiones), el nombre («Name: …», «entregar a …»), la calle («address …», «dirección: …»), el
     * código postal («CP 28013», «zip 08001») y la ciudad («City: Barcelona»). Las etiquetas del correo y del teléfono solo se
     * quitan: el valor lo lee después el lector de siempre.
     *
     * @param array<string,string> $labeled lo sacado, con los nombres de los campos de la caja
     * @param array<string,string> $doubts  lo que tiene forma de dato pero no vale (campo => invalid)
     */
    private static function takeLabels(string $text, array &$labeled, array &$doubts): string
    {
        $labeled = [];
        $doubts = [];
        $start = '(?:^|(?<=[,;\n.:]))\s*';
        $replace = static function (string $pattern, callable $take, string $subject): string {
            $out = preg_replace_callback($pattern, $take, $subject);

            return is_string($out) ? $out : $subject;
        };

        // «My details:», «Mis datos para el envío:»: la etiqueta de todo el bloque.
        $text = $replace('/' . $start . '(?:(?:here\s+are|these\s+are|estos\s+son|aqu[ií]\s+tienes)\s+)?(?:my|mis|mi|our|nuestros)\s+(?:details|data|datos)(?:\s+(?:for|para|de)\s+(?:the\s+|el\s+|la\s+)?(?:order|delivery|shipping|invoice|pedido|env[ií]o|entrega|factura))?\s*(?::|\b(?:are|son)\b)\s*/iu', static function (array $m): string {
            return "\n";
        }, $text);

        // La empresa: «Invoice to company Berg Studio SL», «Factura a nombre de Berg Studio SL», «Empresa: Berg Studio SL».
        $company = static function (array $m) use (&$labeled): string {
            $value = self::cleanValue((string) $m['v']);
            if (isset($labeled['company']) || preg_match('/^[\p{Lu}\d]/u', $value) !== 1 || mb_strlen($value, 'UTF-8') < 2 || mb_strlen($value, 'UTF-8') > 160) {
                return $m[0];
            }
            $labeled['company'] = $value;

            return "\n";
        };
        $value = '(?<v>[^,;\n]+?)(?=\s*(?:[,;\n]|\.(?:\s|$)|\s(?:vat|nif|cif|tax|with|con|and|y\s+(?:con|el|su))\b|$))';
        $text = $replace('/\b(?:invoice|bill)(?:\s+it)?\s+(?:to|for|in\s+the\s+name\s+of)\s+(?:the\s+|my\s+|our\s+)?(?:company\s*:?\s*)?' . $value . '/iu', $company, $text);
        $text = $replace('/\bfactur\w*\s+(?:a\s+nombre\s+de|para|a)\s+(?:la\s+|mi\s+|nuestra\s+)?(?:empresa\s*:?\s*)?' . $value . '/iu', $company, $text);
        $text = $replace('/' . $start . '(?:company(?:\s+name)?|empresa|raz[oó]n\s+social)\s*(?::|\b(?:is|es)\b)?\s*' . $value . '/iu', $company, $text);

        // El NIF o el IVA intracomunitario, como se dio.
        // El pasaporte o el documento de otro país valen como documento («pasaporte X1234567», «passport no. 123456789»).
        $text = $replace('/\b(?:vat(?:\s*(?:id|number|no\.?|n[º°o]\.?))?|nif|cif|nie|dni|tax\s*id|n[uú]mero\s+fiscal|id\s+fiscal|pasaporte|passport|passeport|documento(?:\s+de\s+identidad)?)\b\.?\s*(?:(?:no|n[º°o]|number|n[uú]mero)\b\.?\s*)?\s*(?:\b(?:is|es)\b)?\s*[:#]?\s*(?<v>[A-Za-z0-9][A-Za-z0-9.\-]{4,24})/iu', static function (array $m) use (&$labeled, &$doubts): string {
            $vat = self::taxId(rtrim((string) $m['v'], '.'));
            if (isset($labeled['vat_id']) || preg_match_all('/\d/', $vat) < 5 || strlen($vat) > 20) {
                return $m[0];
            }
            // Un NIF español (con «ES» delante o sin él) tiene que cuadrar; uno de otro país se guarda tal cual.
            $spanish = preg_match('/^(?:ES)?([0-9]{8}[A-Z]|[XYZ][0-9]{7}[A-Z]|[A-HJNPQRSUVW][0-9]{7}[0-9A-J])$/D', $vat, $nif) === 1;
            if ($spanish && !Spain::validTaxId($nif[1])) {
                $doubts['vat_id'] = 'invalid';

                return "\n";
            }
            $labeled['vat_id'] = $vat;

            return "\n";
        }, $text);

        // El nombre con etiqueta: «Name: Hanna Berg», «nombre: Ana Ruiz», «my name is …».
        $text = $replace('/' . $start . '(?:(?:my|mi)\s+)?(?:full\s+)?(?:name|nombre(?:\s+completo)?|nombre\s+y\s+apellidos?)\s*(?::|-|\b(?:is|es)\b)?\s*(?<v>\p{L}[\p{L}\'\- ]{1,80}?)(?=\s*(?:[,;\n]|\.(?:\s|$)|\d|@|$))/iu', static function (array $m) use (&$labeled): string {
            return self::takeName((string) $m['v'], $labeled, 1) ? "\n" : $m[0];
        }, $text);
        // «entregar a Lucía Navarro Sanz porfa», «deliver to Hanna Berg».
        $text = $replace(self::RE_DELIVER_TO, static function (array $m) use (&$labeled): string {
            return self::takeName((string) $m[1], $labeled, 2) ? "\n" : $m[0];
        }, $text);

        // La calle: «address Carrer de l'Exemple 9», «dirección: Calle Mayor 1», «la dirección es Avenida del Ejemplo 12». Solo
        // vale si trae el número: «la dirección está mal» no es una calle.
        $text = $replace('/(?:^|(?<=[,;\n.:])|\s)\s*(?:(?:my|mi|the|la|tu)\s+)?(?:delivery\s+|shipping\s+)?(?:address|direcci[oó]n(?:\s+de\s+(?:entrega|env[ií]o))?|domicilio|street)\s*(?::|\b(?:is|es)\b)?\s*(?<v>[^,;\n@]+?)' . self::VALUE_END . '/iu', static function (array $m) use (&$labeled): string {
            $value = self::cleanValue((string) $m['v']);
            if (isset($labeled['address']) || preg_match('/\d/', $value) !== 1 || preg_match('/\p{L}{2,}/u', $value) !== 1 || preg_match('/^\d{4,5}\b/', $value) === 1) {
                return $m[0];
            }
            $labeled['address'] = $value;

            return "\n";
        }, $text);

        // El código postal y la ciudad con etiqueta.
        $text = $replace('/\b(?:post\s*code|postal\s+code|zip(?:\s*code)?|c[oó]digo\s+postal|c\.\s?p\.?|cp)\b\.?\s*(?::|\b(?:is|es)\b)?\s*(?<v>\d{4,5})\b/iu', static function (array $m) use (&$labeled): string {
            $labeled['postcode'] = (string) $m['v'];

            return (string) $m['v'];
        }, $text);
        $text = $replace('/' . $start . '(?:(?:la|the|my|mi)\s+)?(?:city|town|ciudad|poblaci[oó]n|localidad|municipio)\s*(?::|\b(?:is|es)\b)\s*(?<v>\p{L}[\p{L}\'\- ]{1,40}?)(?=\s*(?:[,;\n]|\.(?:\s|$)|\d|$)|\s+(?:no|not|ni|pas|tlf|tel|tfno|m[oó]vil|phone)\b)/iu', static function (array $m) use (&$labeled): string {
            $value = self::cleanValue((string) $m['v']);
            if (!self::plausiblePlace($value)) {
                return $m[0];
            }
            $labeled['city'] = $value;

            return "\n";
        }, $text);

        // Las etiquetas del correo y del teléfono: se quitan y el valor queda para el lector.
        $text = $replace('/\b(?:e-?mail|correo(?:\s+electr[oó]nico)?|mail)\b\s*(?::|\b(?:is|es)\b)?\s*(?=[^\s@,;]+@)/iu', static function (array $m): string {
            return ' ';
        }, $text);
        $text = $replace('/\b(?:phone(?:\s+number)?|tel[eé]fono(?:\s+m[oó]vil)?|tel[fé]?|tlf|m[oó]vil|mobile|cell)\b\.?\s*(?::|\b(?:is|es)\b)?\s*(?=\+?\d)/iu', static function (array $m): string {
            return ' ';
        }, $text);

        return $text;
    }

    /**
     * Un nombre con etiqueta: sin la cortesía del final («porfa»), de 1 a 6 palabras y que pueda ser de una persona.
     *
     * @param array<string,string> $labeled
     * @param int                  $minWords cuántas palabras tiene que tener como poco
     */
    private static function takeName(string $raw, array &$labeled, int $minWords): bool
    {
        $value = trim((string) preg_replace(self::RE_COURTESY_TAIL, '', self::cleanValue($raw)));
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];
        if (isset($labeled['first_name']) || count($words) < $minWords || count($words) > 6 || !self::plausibleName($value)
            || preg_match('/^(?:' . self::STREET_WORDS . ')$/iu', $words[0]) === 1 || in_array(self::fold($words[0]), self::NAME_START_NOT, true)) {
            return false;
        }
        $split = CustomerData::splitName($words);
        $clean = CustomerData::clean(['first_name' => $split['first'], 'last_name' => $split['last']]);
        $labeled['first_name'] = (string) $clean['first_name'];
        if ((string) $clean['last_name'] !== '') {
            $labeled['last_name'] = (string) $clean['last_name'];
        }

        return true;
    }

    /**
     * Un mensaje que es solo un nombre de persona, con lo que se dice por delante («vale pues», «ok», «bueno») y quizá una coma
     * entre el nombre y los apellidos: de dos a cinco palabras, solo letras. Si es otra cosa, null.
     *
     * @return array{first_name:string,last_name:string}|null
     */
    private static function nameAlone(string $text): ?array
    {
        $text = trim($text, " \t\n.!");
        if ($text === '' || preg_match('/^[\p{L}\'\- ,]+$/uD', $text) !== 1 || substr_count($text, ',') > 1) {
            return null;
        }
        $words = preg_split('/[\s,]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];
        $lead = ['vale', 'pues', 'ok', 'okay', 'bueno', 'venga', 'si', 'yes', 'well', 'so', 'entonces', 'claro', 'sure', 'oui', 'alors', 'bon'];
        while ($words !== [] && in_array(self::fold($words[0]), $lead, true)) {
            array_shift($words);
        }
        if (count($words) < 2 || count($words) > 5 || in_array(self::fold($words[0]), self::NAME_START_NOT, true)) {
            return null;
        }
        $name = implode(' ', $words);
        if (!self::plausibleName($name) || preg_match(self::RE_CHATTER, self::fold($name)) === 1) {
            return null;
        }
        $split = CustomerData::splitName($words);
        $clean = CustomerData::clean(['first_name' => $split['first'], 'last_name' => $split['last']]);

        return ['first_name' => (string) $clean['first_name'], 'last_name' => (string) $clean['last_name']];
    }

    /** El valor de una etiqueta sin espacios ni signos sueltos alrededor. */
    private static function cleanValue(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value), " \t\n,;:.-");
    }

    /** ¿Puede ser el nombre de una población? Solo letras, de una a cuatro palabras y sin palabras de conversación. */
    private static function plausiblePlace(string $value): bool
    {
        $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) && count($words) >= 1 && count($words) <= 4 && preg_match('/^[\p{L}\'\- ]+$/uD', $value) === 1
            && self::plausibleName($value);
    }

    /**
     * Aparta los trozos que son conversación y no datos («I want cash on delivery», «Para el pedido: contra reembolso»): sin
     * cifras ni correo y con palabras de la compra. Un trozo donde alguien se presenta («me llamo…») se queda.
     */
    private static function dropChatter(string $text): string
    {
        $parts = preg_split('/(\s*[,;\n]\s*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $text;
        }
        $kept = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $segment = trim($parts[$i], " .\t");
            $folded = self::fold($segment);
            if ($segment !== '' && preg_match('/[\d@]/', $segment) !== 1 && preg_match(self::RE_STATES_NAME, $folded) !== 1) {
                $words = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY);
                $words = is_array($words) ? $words : [];
                if (preg_match(self::RE_CHATTER, $folded) === 1 || ($words !== [] && array_diff($words, self::OPENING_WORDS) === [])) {
                    // Conversación («I want cash on delivery») o solo asentimiento («Yes», «Vale»): no es un dato.
                    continue;
                }
            }
            $kept[] = $parts[$i];
        }

        return implode(', ', array_filter(array_map('trim', $kept), static function (string $part): bool {
            return trim($part, " .\t") !== '';
        }));
    }

    /**
     * Lo entendido por el lector de siempre con lo que llegó con etiqueta encima (manda la etiqueta), en el orden de siempre.
     *
     * @param array<string,string> $fields
     * @param array<string,string> $labeled
     *
     * @return array<string,string>
     */
    private static function overlay(array $fields, array $labeled): array
    {
        if (isset($labeled['address'])) {
            $address = self::splitAddress($labeled['address']);
            $labeled['line1'] = $address[0];
            if ($address[1] !== '') {
                $labeled['line2'] = $address[1];
            } else {
                unset($fields['line2']);
            }
            unset($labeled['address']);
        }
        if (isset($labeled['city'])) {
            $labeled['city'] = (string) CustomerData::clean(['city' => $labeled['city']])['city'];
        }
        if (isset($labeled['postcode'])) {
            $province = Spain::provinceByPostcode($labeled['postcode']);
            if ($province !== null && Spain::validPostcode($labeled['postcode'])) {
                $labeled['region'] = (string) $province['prefix'];
            } else {
                unset($labeled['postcode']);
            }
        }
        $merged = array_merge($fields, $labeled);
        $out = [];
        foreach (['email', 'first_name', 'last_name', 'phone', 'line1', 'line2', 'postcode', 'city', 'region', 'company', 'vat_id'] as $key) {
            if (isset($merged[$key]) && $merged[$key] !== '') {
                $out[$key] = $merged[$key];
            }
        }

        return $out;
    }

    /**
     * Aparta el piso y la puerta que vienen solos entre comas («…Muestra 5, 3º A, 46001 Valencia»): la dirección que reparte el
     * lector de datos los deja fuera. Solo se aparta un trozo que es del todo un piso (nada más) y que va justo detrás de uno
     * que acaba en el número de la calle.
     *
     * @param string $floor lo apartado, o una cadena vacía
     */
    private static function takeFloor(string $text, string &$floor): string
    {
        $floor = '';
        // Los trozos van entre comas, puntos y comas, saltos de línea o un punto de fin de frase («…9, 2n 1a. Postcode 08001»).
        $parts = preg_split('/(\s*[,;\n]\s*|(?<!\besc)(?<!\bc)(?<!\bav)(?<!\bavda)(?<!\bpza)(?<!\bctra)\.\s+(?=\p{Lu}))/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) < 3) {
            return $text;
        }
        for ($i = 2; $i < count($parts); $i += 2) {
            $segment = trim($parts[$i]);
            $before = trim($parts[$i - 2]);
            if ($segment === '' || preg_match('/^' . self::FLOOR_UNIT . '(?:\s+' . self::FLOOR_UNIT . ')*$/iu', $segment) !== 1) {
                continue;
            }
            if (preg_match('/\b\d{1,4}[A-Za-z]?$/u', $before) !== 1 || preg_match('/^\d{5}$/D', $before) === 1) {
                continue;
            }
            $floor = trim($segment, " ,.\t");
            array_splice($parts, $i - 1, 2);

            return implode('', $parts);
        }

        return $text;
    }

    /**
     * «uy la dirección está mal, es Plaza Inventada 7, 1º, 29001 Málaga»: lo de antes de la calle es una corrección, no un nombre.
     * Solo se quita si lo que va delante habla de la dirección o de cambiarla y no trae ni cifras ni correo ni «me llamo…».
     */
    private static function dropPreamble(string $text): string
    {
        if (preg_match('/\b(?:' . self::STREET_WORDS . ')\s/iu', $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $text;
        }
        $pos = (int) $m[0][1];
        $pre = substr($text, 0, $pos);
        if ($pos < 2 || preg_match('/[@\d]/', $pre) === 1) {
            return $text;
        }
        $n = self::fold($pre);
        if (preg_match(self::RE_INTRODUCTION, $n) === 1 || preg_match(self::RE_CORRECTION, $n) !== 1) {
            return $text;
        }

        return ltrim(substr($text, $pos));
    }

    /**
     * Pone el piso apartado en la segunda línea, a continuación de la calle (delante de lo que ya hubiera en ella).
     *
     * @param array<string,string> $fields
     *
     * @return array<string,string>
     */
    private static function withFloor(array $fields, string $floor): array
    {
        if ($floor === '' || !isset($fields['line1'])) {
            return $fields;
        }
        $out = [];
        foreach ($fields as $key => $value) {
            if ($key === 'line2') {
                continue;
            }
            $out[$key] = $value;
            if ($key === 'line1') {
                $out['line2'] = isset($fields['line2']) && $fields['line2'] !== '' ? $floor . ' ' . $fields['line2'] : $floor;
            }
        }

        return $out;
    }

    /** Minúsculas y sin tildes, para comparar palabras. */
    private static function fold(string $text): string
    {
        $s = mb_strtolower($text, 'UTF-8');

        return strtr($s, ['á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ò' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
    }

    /** ¿Puede ser un nombre de persona? Sin cifras y sin palabras de dirección, de corrección o de conversación. */
    private static function plausibleName(string $name): bool
    {
        if (preg_match('/[\d@]/', $name) === 1) {
            return false;
        }
        $words = preg_split('/[^\p{L}\']+/u', self::fold($name), -1, PREG_SPLIT_NO_EMPTY);
        foreach (is_array($words) ? $words : [] as $word) {
            if (in_array($word, self::NOT_NAME_WORDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lo dudoso: lo deducido, lo que tiene la forma de un dato pero no vale y lo que viene repetido.
     *
     * @param array<string,mixed>  $found
     * @param array<string,string> $fields
     *
     * @return array<string,string> campo => motivo
     */
    private static function doubts(string $text, array $found, array $fields): array
    {
        $doubts = [];
        if (isset($fields['first_name']) && empty($found['name_stated'])) {
            $words = preg_split('/\s+/u', trim($fields['first_name'] . ' ' . (isset($fields['last_name']) ? $fields['last_name'] : '')), -1, PREG_SPLIT_NO_EMPTY);
            if (!isset($fields['last_name']) || (is_array($words) && count($words) > 3)) {
                $doubts['first_name'] = 'guessed';
                if (isset($fields['last_name'])) {
                    $doubts['last_name'] = 'guessed';
                }
            }
        }
        if (!isset($fields['email']) && strpos($text, '@') !== false) {
            $doubts['email'] = 'invalid';
        }
        if (!isset($fields['vat_id']) && preg_match('/\b(?:[0-9]{8}[A-Za-z]|[XYZxyz][0-9]{7}[A-Za-z]|[A-HJNPQRSUVWa-hjnpqrsuvw][0-9]{7}[0-9A-Ja-j])\b/', $text) === 1) {
            $doubts['vat_id'] = 'invalid';
        }
        if (!isset($fields['postcode']) && preg_match('/(?<![0-9+])\b[0-9]{5}\b(?![0-9])/', $text) === 1) {
            $doubts['postcode'] = 'invalid';
        }
        if (!isset($fields['phone']) && preg_match('/(?<![0-9])[0-9]{9}(?![0-9])/', (string) preg_replace('/(?<=[0-9])[\s.-](?=[0-9])/', '', $text)) === 1) {
            $doubts['phone'] = 'invalid';
        }
        $emails = preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $text, $m1);
        if ($emails !== false && $emails > 1 && count(array_unique(array_map('strtolower', $m1[0]))) > 1) {
            $doubts['email'] = 'ambiguous';
        }
        $phones = preg_match_all('/(?:\+34[\s.-]?)?\b[6789](?:[\s.-]?[0-9]){8}\b/', $text, $m2);
        if ($phones !== false && $phones > 1) {
            $normalized = [];
            foreach ($m2[0] as $phone) {
                $normalized[Spain::normalizePhone($phone)] = true;
            }
            if (count($normalized) > 1) {
                $doubts['phone'] = 'ambiguous';
            }
        }

        return $doubts;
    }

    /**
     * Lo que sigue faltando una vez juntado lo que ya había y lo entendido, en el orden en que se pide.
     *
     * @param array<string,mixed> $merged
     *
     * @return string[]
     */
    private static function missing(array $merged, string $country, bool $needsShipping): array
    {
        $text = static function (string $key) use ($merged): string {
            return isset($merged[$key]) && is_scalar($merged[$key]) ? trim((string) $merged[$key]) : '';
        };
        $missing = [];
        if (preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/D', $text('email')) !== 1) {
            $missing[] = 'email';
        }
        if ($text('first_name') === '' && $text('last_name') === '') {
            $missing[] = 'first_name';
        }
        if ($needsShipping) {
            if ($text('line1') === '') {
                $missing[] = 'line1';
            }
            if ($text('postcode') === '' || !Countries::validPostcode($country, $text('postcode'))) {
                $missing[] = 'postcode';
            }
            if ($text('city') === '') {
                $missing[] = 'city';
            }
        }

        return $missing;
    }

    /**
     * El mismo texto con comas entre los datos: antes y después del correo, del teléfono y del código postal, y antes de
     * cada vía. Así CustomerData puede repartirlo en trozos.
     */
    private static function separate(string $text): string
    {
        $steps = [
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => ' , $0 , ',
            '/(?:\+34[\s.-]?)?\b[6789](?:[\s.-]?[0-9]){8}\b/' => ' , $0 , ',
            '/(?<=\S)\s+(?=(?:' . self::STREET_WORDS . ')\s)/iu' => ' , ',
            '/(?<=\S)\s+(?=[0-9]{5}\b)/' => ' , ',
        ];
        foreach ($steps as $pattern => $by) {
            $out = preg_replace($pattern, $by, $text);
            $text = is_string($out) ? $out : $text;
        }

        return $text;
    }
}
