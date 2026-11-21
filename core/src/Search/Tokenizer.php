<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Search;

/**
 * Normaliza texto y lo trocea en raíces de palabra, sin ninguna IA.
 *
 * Idiomas con palabras vacías y extractor de raíces: `es` y `en`. Cualquier otro se trata
 * solo con normalización (sin palabras vacías ni raíces).
 *
 * El extractor es deliberadamente ligero: lo que importa es que la MISMA función se aplique
 * a la consulta y a los documentos, de modo que «pedidos» y «pedido» den la misma raíz.
 * En castellano el género se pliega hacia la «o» (blanca → blanco) y, por eso, la raíz que
 * devuelve `tokens()` no siempre es una palabra real: sirve de clave de índice, no para enseñarla
 * (para enseñar palabras está `matching()`).
 */
final class Tokenizer
{
    /** Palabra: letras, marcas y números, con guiones, puntos o guiones bajos en medio («SE-01», «v2.5»). */
    private const WORD_RE = '/[\p{L}\p{M}\p{N}]+(?:[-._][\p{L}\p{M}\p{N}]+)*/u';

    /** Letras con tilde o signo y su equivalente sin él (solo minúsculas). */
    private const ACCENT_GROUPS = [
        'a' => 'àáâãäåāăąǎ',
        'c' => 'çćĉċč',
        'd' => 'ď',
        'e' => 'èéêëēĕėęě',
        'g' => 'ĝğġģ',
        'h' => 'ĥħ',
        'i' => 'ìíîïĩīĭįǐ',
        'j' => 'ĵ',
        'k' => 'ķ',
        'l' => 'ĺļľŀ',
        'n' => 'ñńņň',
        'o' => 'òóôõöōŏőǒ',
        'r' => 'ŕŗř',
        's' => 'śŝşš',
        't' => 'ţťŧ',
        'u' => 'ùúûüũūŭůűųǔ',
        'w' => 'ŵ',
        'y' => 'ýÿŷ',
        'z' => 'źżž',
    ];

    /** Letras que Unicode no descompone (NFD no las toca) y que hay que sustituir a mano. */
    private const SPECIAL = [
        'ø' => 'o',
        'ł' => 'l',
        'đ' => 'd',
        'ð' => 'd',
        'þ' => 'th',
        'ß' => 'ss',
        'æ' => 'ae',
        'œ' => 'oe',
        'ı' => 'i',
    ];

    /** Palabras vacías en castellano (ya sin tildes). */
    private const STOP_ES = 'a al algo algun alguna algunas alguno algunos ante antes aqui asi aun aunque bajo bien cada '
        . 'casi como con contra cual cuales cuando cuanto de del desde donde durante e el ella ellas ello ellos en entre '
        . 'era eran eres es esa esas ese eso esos esta estaba estan estar estas este esto estos estoy fue fueron ha '
        . 'habia han has hasta hay haya he hemos hace hacer la las le les lo los mas me mi mis mientras mismo misma '
        . 'mucho mucha muchos muchas muy nada ni no nos nosotros nuestro nuestra nuestros nuestras o os otra otras otro '
        . 'otros para pero poco pocos por porque pues que quien quienes se sea segun ser si sido siempre sin sobre solo '
        . 'son soy su sus tal tambien tan tanto te tenemos tener tengo tiene tienen todo toda todas todos tras tu tus un '
        . 'una unas uno unos usted ustedes vosotros vuestro vuestra y ya yo necesito necesitas quiero quieres quisiera '
        . 'gustaria busco buscas puedo puede pueden podria hola gracias favor cualquier alguien';

    /** Palabras vacías en inglés. */
    private const STOP_EN = 'a about above after again all also am an and any are as at be because been before being below '
        . 'between both but by can could did do does doing down during each few for from further had has have having he '
        . 'her here hers him his how i if in into is it its just me more most my no nor not of off on once only or other '
        . 'our ours out over own same she should so some such than that the their theirs them then there these they this '
        . 'those through to too under until up very was we were what when where which while who whom why will with would '
        . 'you your yours want need looking get got please hi hello';

    /** Palabras en -ito/-ita que no son diminutivos (para no recortarlas). */
    private const NOT_DIMINUTIVES = 'bonito bonita cabrito cabrita mosquito mosquita favorito favorita distrito delito '
        . 'exito credito habito deposito apetito circuito escrito orbita visita visito limita habita solicita '
        . 'facilita invita agita necesita';

    /** @var array<string,array<string,bool>> caché de las listas de palabras vacías, por idioma */
    private static array $wordSets = [];

    /** @var array<string,string>|null caché de la tabla de sustitución de tildes */
    private static ?array $accentMap = null;

    private string $lang;

    /** @var array<string,bool> */
    private array $stop;

    public function __construct(string $lang = 'es')
    {
        $this->lang = strtolower(substr(trim($lang), 0, 2));
        if ($this->lang === 'es') {
            $this->stop = self::wordSet('stop-es', self::STOP_ES);
        } elseif ($this->lang === 'en') {
            $this->stop = self::wordSet('stop-en', self::STOP_EN);
        } else {
            $this->stop = [];
        }
    }

    /**
     * Minúsculas, sin tildes ni diéresis (ñ → n), sin signos y con los espacios colapsados.
     */
    public function normalize(string $text): string
    {
        $s = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', self::fold($text));

        return is_string($s) ? trim($s) : '';
    }

    /** @return string[]|null Términos de una petición coloquial en castellano, con el producto primero. */
    public function conversationalProductTerms(string $text): ?array
    {
        if ($this->lang !== 'es' || preg_match('/\p{N}/u', $text)) {
            return null;
        }
        $words = array_slice($this->chunks(mb_strtolower(self::cleanUtf8($text), 'UTF-8')), 0, 12);
        if (count($words) < 3) {
            return null;
        }
        $markers = array_fill_keys(array_map([self::class, 'fold'], ['tenéis', 'tienen', 'tienes', 'hay',
            'alguna', 'algún', 'algunas', 'algunos', 'sencilla', 'sencillo', 'sencillas', 'sencillos',
            'recomiendas', 'podrías', 'quiero', 'busco']), true);
        $terms = [];
        $natural = false;
        foreach ($words as $word) {
            if (isset($markers[self::fold($word)])) {
                $natural = true;
            } elseif ($this->tokens($word) !== []) {
                $terms[] = $word;
            }
        }

        return $natural && $terms !== [] ? $terms : null;
    }

    /**
     * Palabras normalizadas, sin palabras vacías y reducidas a su raíz.
     *
     * Los números se conservan; un número pegado a su unidad se separa y se conserva también
     * unido («130kg» → 130, kg, 130kg); una referencia con guiones o puntos se conserva entera
     * y troceada («SE-01» → se-01, se, 01).
     *
     * @return string[]
     */
    public function tokens(string $text): array
    {
        $out = [];
        foreach ($this->chunks(self::fold($text)) as $chunk) {
            if (preg_match('/[-._]/', $chunk) === 1) {
                $parts = preg_split('/[-._]+/', $chunk, -1, PREG_SPLIT_NO_EMPTY);
                // Si la referencia lleva cifras, sus trozos no pasan por la lista de palabras vacías.
                $isReference = preg_match('/[0-9]/', $chunk) === 1;
                $out[] = $chunk;
                foreach (is_array($parts) ? $parts : [] as $part) {
                    $this->pushWord($part, $out, $isReference);
                }
            } else {
                $this->pushWord($chunk, $out, false);
            }
        }

        return $out;
    }

    /**
     * Palabras normalizadas sin quitar las vacías ni reducir a la raíz (para resaltar y para frases).
     *
     * @return string[]
     */
    public function raw(string $text): array
    {
        $words = preg_split('/\s+/', $this->normalize($text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    /**
     * Palabras de `$text`, tal como están escritas en él, cuya raíz coincide con alguna de la consulta.
     *
     * @return string[]
     */
    public function matching(string $query, string $text): array
    {
        $wanted = array_flip($this->tokens($query));
        if ($wanted === []) {
            return [];
        }

        $found = preg_match_all(self::WORD_RE, self::cleanUtf8($text), $m);
        if ($found === false || $found === 0) {
            return [];
        }

        $seen = [];
        $out = [];
        foreach ($m[0] as $surface) {
            $key = self::fold($surface);
            if (isset($seen[$key])) {
                continue;
            }
            foreach ($this->tokens($surface) as $token) {
                if (isset($wanted[$token])) {
                    $seen[$key] = true;
                    $out[] = $surface;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Raíz de UNA palabra ya normalizada (minúsculas y sin tildes).
     */
    public function stem(string $word): string
    {
        if (preg_match('/^[a-z]+$/', $word) !== 1) {
            return $word;
        }
        if ($this->lang === 'es') {
            return $this->stemEs($word);
        }
        if ($this->lang === 'en') {
            return $this->stemEn($word);
        }

        return $word;
    }

    /**
     * Quita tildes y diéresis de un texto en minúsculas. Usa `Normalizer` (intl) si existe y, si no
     * (o si `$useIntl` es false), una tabla propia; las dos vías dan el mismo resultado.
     */
    public static function stripAccents(string $text, bool $useIntl = true): string
    {
        if ($text === '') {
            return '';
        }
        $marks = '/[' . mb_chr(0x300, 'UTF-8') . '-' . mb_chr(0x36F, 'UTF-8') . ']+/u';

        if ($useIntl && class_exists('Normalizer')) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $clean = preg_replace($marks, '', $decomposed);
                if (is_string($clean)) {
                    return strtr($clean, self::SPECIAL);
                }
            }
        }

        $clean = strtr($text, self::accentMap());
        $clean2 = preg_replace($marks, '', $clean);

        return is_string($clean2) ? $clean2 : $clean;
    }

    /** @return array<string,string> */
    private static function accentMap(): array
    {
        if (self::$accentMap === null) {
            $map = self::SPECIAL;
            foreach (self::ACCENT_GROUPS as $base => $chars) {
                foreach (mb_str_split($chars, 1, 'UTF-8') as $char) {
                    $map[$char] = (string) $base;
                }
            }
            self::$accentMap = $map;
        }

        return self::$accentMap;
    }

    /** @return array<string,bool> */
    private static function wordSet(string $name, string $list): array
    {
        if (!isset(self::$wordSets[$name])) {
            $set = [];
            foreach (explode(' ', $list) as $word) {
                if ($word !== '') {
                    $set[$word] = true;
                }
            }
            self::$wordSets[$name] = $set;
        }

        return self::$wordSets[$name];
    }

    private static function cleanUtf8(string $text): string
    {
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return $text;
    }

    /** Minúsculas y sin tildes, conservando los signos. */
    private static function fold(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return self::stripAccents(mb_strtolower(self::cleanUtf8($text), 'UTF-8'));
    }

    /** @return string[] */
    private function chunks(string $folded): array
    {
        if (preg_match_all(self::WORD_RE, $folded, $m) === false) {
            return [];
        }

        return $m[0];
    }

    /**
     * @param string[] $out
     */
    private function pushWord(string $word, array &$out, bool $skipStop): void
    {
        if ($word === '') {
            return;
        }
        if (ctype_digit($word)) {
            $out[] = $word;

            return;
        }
        // Número pegado a su unidad: «130kg» → 130, kg, 130kg (la unidad puede ser de una letra: «12v»).
        if (preg_match('/^([0-9]+)(\p{L}+)$/u', $word, $m) === 1) {
            $out[] = $m[1];
            $out[] = $this->stem($m[2]);
            $out[] = $word;

            return;
        }
        if (mb_strlen($word, 'UTF-8') < 2) {
            return;
        }
        if (!$skipStop && isset($this->stop[$word])) {
            return;
        }
        $out[] = $this->stem($word);
    }

    private function stemEs(string $w): string
    {
        $n = strlen($w);
        if ($n < 4) {
            return $w;
        }

        // Plural. «-ces» → «z» tras vocal (luces → luz); «-es» solo cuando el singular acaba en
        // consonante (motores → motor) para no cortar «nombres», «verdes» ni «cables».
        if ($n > 4 && substr($w, -3) === 'ces' && strpos('aeiou', $w[$n - 4]) !== false) {
            $w = substr($w, 0, -3) . 'z';
        } elseif ($n > 4 && substr($w, -2) === 'es'
            && strpos('lnrdjsy', $w[$n - 3]) !== false && strpos('aeiou', $w[$n - 4]) !== false) {
            $w = substr($w, 0, -2);
        } elseif (substr($w, -1) === 's') {
            $w = substr($w, 0, -1);
        }

        // Diminutivos corrientes: gatito → gato, mesita → mesa.
        $m = strlen($w);
        if ($m >= 6 && (substr($w, -3) === 'ito' || substr($w, -3) === 'ita')
            && strpos(' ' . self::NOT_DIMINUTIVES . ' ', ' ' . $w . ' ') === false) {
            $w = substr($w, 0, -3) . $w[$m - 1];
        }

        // Género: la «a» final pasa a «o» cuando la raíz tiene 4 letras o más (blanca → blanco).
        if (strlen($w) >= 5 && substr($w, -1) === 'a') {
            $w = substr($w, 0, -1) . 'o';
        }

        return $w;
    }

    private function stemEn(string $w): string
    {
        $n = strlen($w);

        // Plural.
        if ($n > 4 && substr($w, -3) === 'ies') {
            $w = substr($w, 0, -3) . 'i';
        } elseif ($n > 4 && preg_match('/(?:sses|shes|ches|xes|zes)$/', $w) === 1) {
            $w = substr($w, 0, -2);
        } elseif ($n > 3 && substr($w, -1) === 's' && preg_match('/(?:ss|us|is)$/', $w) !== 1) {
            $w = substr($w, 0, -1);
        }

        // -ing y -ed sencillos.
        $n = strlen($w);
        if ($n > 5 && substr($w, -3) === 'ing' && preg_match('/[aeiou]/', substr($w, 0, -3)) === 1) {
            $w = self::undouble(substr($w, 0, -3));
        } elseif ($n > 4 && substr($w, -3) === 'ied') {
            $w = substr($w, 0, -3) . 'i';
        } elseif ($n > 4 && substr($w, -2) === 'ed' && preg_match('/[aeiou]/', substr($w, 0, -2)) === 1) {
            $w = self::undouble(substr($w, 0, -2));
        }

        // «e» muda final y «y» final tras consonante, para que make/making y battery/batteries coincidan.
        $n = strlen($w);
        if ($n >= 4 && substr($w, -1) === 'e' && substr($w, -2) !== 'ee') {
            $w = substr($w, 0, -1);
        } elseif ($n >= 3 && substr($w, -1) === 'y' && strpos('aeiou', $w[$n - 2]) === false) {
            $w = substr($w, 0, -1) . 'i';
        }

        return $w;
    }

    /** running → runn → run (sin tocar ll, ss, zz). */
    private static function undouble(string $base): string
    {
        $n = strlen($base);
        if ($n >= 4 && $base[$n - 1] === $base[$n - 2] && strpos('aeioulsz', $base[$n - 1]) === false) {
            return substr($base, 0, -1);
        }

        return $base;
    }
}
