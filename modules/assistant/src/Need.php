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

use Aifec\Ai\Prompt;

/**
 * La necesidad en curso de una conversación: lo que busca el cliente (con sus palabras), para quién, su presupuesto, lo que ha ido
 * afinando («en negro», «talla 42», «más formal»), lo ya enseñado y lo descartado. Vive en el estado de la conversación (clave
 * «need») y se le pasa al modelo en cada vuelta, en la nota de la tienda (nunca en la instrucción del sistema, que es la misma para
 * todos): así «soy hombre» después de «para ir a una boda elegante» es «boda elegante para hombre», y no una búsqueda de las
 * palabras «soy hombre».
 *
 * kind() decide qué es cada mensaje:
 *  - about: pregunta por un producto («háblame de…», «qué es», «características», «y este qué tal», «háblame de él»);
 *  - reset: «otra cosa»: la necesidad se olvida;
 *  - browse: «seguir mirando»;
 *  - refine: un mensaje corto que afina («soy hombre», «más barato», «en negro», «talla 42», «algo más formal», «otro»);
 *  - vague: una necesidad vaga, una ocasión o un regalo («para una boda elegante», «un regalo para mi padre», «qué me recomiendas»);
 *  - new: una búsqueda nueva («busco zapatillas»), que sustituye a la anterior;
 *  - other: lo demás (el carrito, el envío, la compra, un pedido, un saludo), que no toca la necesidad.
 *
 * Funciones puras: texto y estado dentro, datos fuera.
 */
final class Need
{
    /** Lo más largo que se guarda de las palabras de la necesidad. */
    private const MAX_WORDS_CHARS = 80;

    /** Afinados que se recuerdan. */
    private const MAX_MORE = 4;

    /** Productos enseñados y descartados que se recuerdan de la necesidad. */
    private const MAX_IDS = 12;

    /** Palabras de las personas para las que se busca, con su grupo: m (hombre), f (mujer) y k (niños). */
    private const WHO = [
        'hombre' => 'm', 'hombres' => 'm', 'caballero' => 'm', 'caballeros' => 'm', 'chico' => 'm', 'chicos' => 'm', 'senor' => 'm',
        'senores' => 'm', 'padre' => 'm', 'papa' => 'm', 'marido' => 'm', 'novio' => 'm', 'hijo' => 'm', 'abuelo' => 'm',
        'hermano' => 'm', 'men' => 'm', 'man' => 'm', 'mens' => 'm', 'boy' => 'k', 'boys' => 'k', 'him' => 'm', 'husband' => 'm',
        'father' => 'm', 'dad' => 'm', 'masculino' => 'm',
        'mujer' => 'f', 'mujeres' => 'f', 'senora' => 'f', 'senoras' => 'f', 'dama' => 'f', 'damas' => 'f', 'chica' => 'f',
        'chicas' => 'f', 'madre' => 'f', 'mama' => 'f', 'novia' => 'f', 'hija' => 'f', 'abuela' => 'f', 'hermana' => 'f',
        'esposa' => 'f', 'women' => 'f', 'woman' => 'f', 'womens' => 'f', 'her' => 'f', 'wife' => 'f', 'mother' => 'f', 'mum' => 'f',
        'girl' => 'f', 'girls' => 'f', 'femenino' => 'f',
        'nino' => 'k', 'ninos' => 'k', 'nina' => 'k', 'ninas' => 'k', 'bebe' => 'k', 'bebes' => 'k', 'infantil' => 'k', 'kids' => 'k',
        'kid' => 'k', 'child' => 'k', 'children' => 'k', 'junior' => 'k', 'baby' => 'k',
    ];

    /** Una ocasión, un regalo o pedir consejo: una necesidad vaga, sin un tipo de producto. */
    private const RE_VAGUE = '/\b(?:(?:para|for)\s+(?:ir\s+a\s+|asistir\s+a\s+)?(?:una?|mi|el|la|a|an|my)?\s*(?:boda|bodas|bautizo|comunion|fiesta|cena|cocktail|coctel|evento|gala|graduacion|entrevista|viaje|cumpleanos|aniversario|san valentin|navidad|wedding|party|dinner|interview|trip|birthday|christmas)'
        . '|regalo|regalar|un detalle para|que me recomiendas|que me recomiendan|que recomiendas|que me aconsejas|recomiendame|aconsejame|no se que (?:comprar|regalar|elegir)'
        . '|ideas? para|algo para (?:correr|andar|caminar|el|la|mi|ir|regalar|hacer|dormir|trabajar|viajar|salir)|gift|present for|what do you recommend|recommend me|something for)\b/';

    /** «Otra cosa»: lo que se buscaba ya no vale. */
    private const RE_RESET = '/\b(?:otra cosa|otras cosas|algo distinto|algo diferente|cambiando de tema|olvidalo|olvida eso|something else|anything else|forget it|never mind)\b/';

    /** «Seguir mirando». */
    private const RE_BROWSE = '/^(?:(?:quiero|voy a|prefiero)\s+)?(?:seguir|sigo)\s+(?:mirando|comprando|viendo)\b|^keep\s+(?:browsing|shopping|looking)\b/';

    /** «Más barato». */
    private const RE_CHEAPER = '/\b(?:mas barat[oa]s?|mas economic[oa]s?|algo barato|mas asequibles?|que cueste menos|cheaper|less expensive|lower price)\b/';

    /** «Algo más formal», «menos llamativo». */
    private const RE_STYLE = '/\b(?:mas|menos|more|less)\s+(formal(?:es)?|elegantes?|informal(?:es)?|casual(?:es)?|sencill[oa]s?|deportiv[oa]s?|clasic[oa]s?|modern[oa]s?|grandes?|pequen[oa]s?|liger[oa]s?|calid[oa]s?|fresc[oa]s?|comod[oa]s?|larg[oa]s?|cort[oa]s?|discret[oa]s?|llamativ[oa]s?|juvenil(?:es)?|seri[oa]s?|bonit[oa]s?|smart|elegant|comfortable|warm|light|small|big)\b/';

    /** «Otro», «ese no», «no me gusta»: lo último enseñado se descarta. */
    private const RE_OTHER = '/^(?:(?:y|pues|vale|no|mejor)\s+)*(?:otr[oa]s?|algun otr[oa]|ensename otr[oa]s?|muestrame otr[oa]s?|ese no|esa no|esos no|esas no|no me gusta\w*|another(?: one)?|other ones|a different one|not that one)\b/';

    /** Lo que se le quita a un afinado para ver si dice algo más. */
    private const FILLER = ['soy', 'somos', 'es', 'son', 'sea', 'para', 'un', 'una', 'uno', 'unos', 'unas', 'el', 'la', 'los', 'las', 'lo', 'algo',
        'mas', 'menos', 'que', 'mejor', 'me', 'mi', 'quiero', 'prefiero', 'busco', 'pues', 'vale', 'si', 'y', 'en', 'de', 'del', 'con', 'color',
        'talla', 'numero', 'por', 'favor', 'gracias', 'ok', 'the', 'a', 'an', 'i', 'am', 'im', 'm', 'for', 'something', 'more', 'less', 'it', 'is',
        'its', 'please', 'one', 'ones', 'in', 'tambien', 'also', 'igual', 'tipo', 'estilo', 'mejor', 'no', 'ni', 'muy', 'bastante', 'hasta',
        'euros', 'euro', 'eur', 'my', 'of', 'size', 'colour', 'ella', 'ese', 'esa', 'esos', 'esas', 'este', 'esta', 'gusta', 'gustan', 'otro',
        'otra', 'otros', 'otras', 'algun', 'ensename', 'muestrame', 'another', 'other', 'different', 'not', 'that', 'barato', 'barata',
        'baratos', 'baratas', 'economico', 'economica', 'cheaper', 'cueste', 'precio'];

    /** Lo que no dice qué tipo de producto se busca («algo», «un regalo», «ropa»). */
    private const GENERIC = ['algo', 'cosa', 'cosas', 'regalo', 'regalos', 'detalle', 'detalles', 'idea', 'ideas', 'ropa', 'conjunto', 'look',
        'something', 'anything', 'gift', 'gifts', 'present', 'outfit', 'que', 'recomiendas', 'recomiendan', 'aconsejas', 'recomiendame',
        'aconsejame', 'regalar', 'comprar', 'ponerme', 'llevar', 'vestir'];

    /** Lo que no es una búsqueda: un sí, un no, una cortesía. */
    private const CHAT = ['si', 'no', 'vale', 'ok', 'okay', 'perfecto', 'genial', 'bien', 'claro', 'acuerdo', 'de', 'yes', 'sure', 'great', 'fine',
        'good', 'nice', 'gracias', 'thanks', 'muchas', 'thank', 'you', 'estupendo', 'guay', 'entendido', 'bueno', 'pues', 'ya', 'vale'];

    /**
     * Qué es este mensaje para la necesidad en curso.
     *
     * @param array<string,mixed> $state el estado de la conversación (need, cards)
     */
    public static function kind(string $message, array $state): string
    {
        $n = RuleBrain::norm($message);
        if ($n === '' || preg_match('/^[\d\s.,]+$/', $n) === 1) {
            return 'other';
        }
        $words = explode(' ', $n);
        if (count($words) <= 4 && preg_match(self::RE_RESET, $n) === 1) {
            return 'reset';
        }
        if (preg_match(self::RE_BROWSE, $n) === 1) {
            return 'browse';
        }
        if (preg_match('/^(?:(?:ver|ensename|muestrame|quiero|y)\s+)?(?:otros\s+)?(?:parecidos|similares|algo parecido|similar ones|similar)$/', $n) === 1) {
            // «Parecidos» (un botón sin productos guardados): de lo que se está viendo, sin cambiar lo que se busca.
            return 'other';
        }
        if (self::vague($n)) {
            return 'vague';
        }
        if (RuleBrain::shopTopic($message) || self::chat($words)) {
            return 'other';
        }
        if (ProductTalk::asks($n)) {
            return 'about';
        }
        if (count($words) <= 8 && self::refineTerms($message) !== []) {
            return 'refine';
        }
        $looking = RuleBrain::searchQuery($n);
        if ($looking[2] || ($looking[0] !== '' && count($words) <= 6)) {
            return 'new';
        }

        return 'other';
    }

    /**
     * ¿Es una ocasión, un regalo o una petición de consejo, sin tipo de producto? «Busco un jamón ibérico para regalar» ya dice qué
     * busca (es una búsqueda); «busco un regalo para mi padre» o «quiero algo para correr», no.
     */
    public static function vague(string $n): bool
    {
        if (preg_match(self::RE_VAGUE, $n) !== 1) {
            return false;
        }
        $looking = RuleBrain::searchQuery($n);
        if (!$looking[2]) {
            return true;
        }
        $before = trim((string) preg_replace('/\b(?:para|for)\b.*$/', '', $looking[0]));
        foreach (explode(' ', $before) as $word) {
            if ($word !== '' && mb_strlen($word, 'UTF-8') >= 3 && !in_array($word, self::GENERIC, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lo que afina un mensaje corto. Vacío si no afina nada (o si dice además otra cosa: entonces es una búsqueda nueva).
     *
     * @return array{for?:string,group?:string,max?:int,cheaper?:bool,more?:string[],other?:bool}
     */
    public static function refineTerms(string $message): array
    {
        $n = RuleBrain::norm($message);
        if ($n === '') {
            return [];
        }
        $out = [];
        $rest = ' ' . $n . ' ';
        $who = implode('|', array_keys(self::WHO));
        if (preg_match('/\b(?:soy|somos|es para|son para|para|for|i am|i m|im|de)\s+(?:(?:un|una|el|la|mi|a|an|my)\s+)?(' . $who . ')\b/', $n, $m) === 1
            || preg_match('/^(?:(?:y|pues|vale)\s+)?(' . $who . ')$/', $n, $m) === 1) {
            $out['for'] = $m[1];
            $out['group'] = self::WHO[$m[1]];
            $rest = str_replace(' ' . $m[1] . ' ', ' ', $rest);
        }
        $max = RuleBrain::searchQuery($n)[1];
        if ($max !== null) {
            $out['max'] = $max;
            $rest = (string) preg_replace('/\d+(?:[.,]\d+)?|€/u', ' ', $rest);
        }
        if (preg_match(self::RE_CHEAPER, $n) === 1) {
            $out['cheaper'] = true;
        }
        $more = [];
        foreach (OptionAsk::wanted($message) as $ask) {
            if ($ask['value'] !== '') {
                $more[] = $ask['text'];
                $rest = str_replace(' ' . RuleBrain::norm($ask['value']) . ' ', ' ', $rest);
            }
        }
        if (preg_match_all(self::RE_STYLE, $n, $styles, PREG_SET_ORDER) > 0) {
            foreach ($styles as $style) {
                $more[] = $style[0];
                $rest = str_replace(' ' . $style[1] . ' ', ' ', $rest);
            }
        }
        if ($more !== []) {
            $out['more'] = array_values(array_unique($more));
        }
        if (preg_match(self::RE_OTHER, $n) === 1) {
            $out['other'] = true;
        }
        if ($out === []) {
            return [];
        }
        // Si queda algo con peso aparte de lo que afina («busco zapatos negros», «flexo más barato»), no es afinar: es buscar otra cosa.
        foreach (explode(' ', trim($rest)) as $word) {
            if ($word !== '' && !in_array($word, self::FILLER, true) && preg_match('/^\d+$/', $word) !== 1) {
                return [];
            }
        }

        return $out;
    }

    /** El grupo de una palabra de persona («hombre» → m, «Caballero» → m, «Niñas» → k); vacío si no es ninguna. */
    public static function group(string $word): string
    {
        $w = RuleBrain::norm($word);

        return isset(self::WHO[$w]) ? self::WHO[$w] : '';
    }

    /**
     * La necesidad después de este mensaje.
     *
     * @param array<string,mixed> $need    la de antes (clave «need» del estado)
     * @param int[]               $lastIds los productos de la última respuesta (para «otro»: se descartan)
     *
     * @return array<string,mixed> q (sus palabras), s (lo que se busca, normalizado), for, group, max, cheaper, more, shown, dropped, vague
     */
    public static function apply(array $need, string $message, string $kind, array $lastIds = []): array
    {
        $need = self::clean($need);
        if ($kind === 'reset') {
            return [];
        }
        if ($kind === 'new' || $kind === 'vague') {
            $n = RuleBrain::norm($message);
            $s = $kind === 'new' ? RuleBrain::searchQuery($n)[0] : trim((string) preg_replace('/\s+/', ' ', str_replace('€', ' ', $n)));
            $fresh = [
                'q' => self::cut(trim((string) preg_replace('/\s+/u', ' ', $message))),
                's' => self::cut($s !== '' ? $s : $n),
            ];
            if ($kind === 'vague') {
                $fresh['vague'] = 1;
            }
            // Lo que ya venía dicho en la misma frase («regalo para mi padre de menos de 50 €»).
            $terms = self::termsIn($message);

            return self::merge($fresh, $terms, []);
        }
        if ($kind === 'refine') {
            return self::merge($need, self::refineTerms($message), $lastIds);
        }

        return $need;
    }

    /**
     * Lo que se busca para la necesidad, en una línea: las palabras de lo que busca, para quién y lo afinado («zapatos hombre negro»).
     *
     * @param array<string,mixed> $need
     */
    public static function query(array $need): string
    {
        $need = self::clean($need);
        $parts = [];
        if (isset($need['s']) && $need['s'] !== '') {
            $parts[] = $need['s'];
        }
        if (isset($need['for']) && $need['for'] !== '') {
            $parts[] = $need['for'];
        }
        foreach (isset($need['more']) ? $need['more'] : [] as $more) {
            $parts[] = RuleBrain::norm((string) $more);
        }

        return self::cut(trim(implode(' ', $parts)));
    }

    /**
     * Lo que se le cuenta al modelo de la necesidad en curso y de lo que pide este mensaje (en inglés: lo lee el modelo). Las palabras
     * del cliente van cercadas como datos.
     *
     * @param array<string,mixed>          $need
     * @param array<int,array<string,mixed>> $cats   las categorías de la tienda (id, name), si ya se conocen
     * @param array<int,string>            $names  nombres de los productos por id (para lo enseñado y lo descartado)
     */
    public static function note(array $need, string $kind, array $cats = [], array $names = []): string
    {
        $need = self::clean($need);
        $lines = [];
        if ($kind === 'reset') {
            $lines[] = 'The customer wants something else: forget the previous need and ask in one short sentence what they are looking for.';
        } elseif ($kind === 'browse') {
            $lines[] = 'The customer wants to keep browsing: ask what else they are looking for, or suggest a few categories of the shop with suggest_replies.';
        }
        if (isset($need['q']) && $need['q'] !== '') {
            $data = ['looking_for' => $need['q']];
            if (isset($need['for'])) {
                $data['for'] = $need['for'];
            }
            if (isset($need['max'])) {
                $data['budget_max_cents'] = $need['max'];
            }
            if (!empty($need['cheaper'])) {
                $data['cheaper_than_shown'] = true;
            }
            if (isset($need['more'])) {
                $data['also'] = $need['more'];
            }
            foreach (['shown' => 'already_shown', 'dropped' => 'discarded_by_customer'] as $key => $label) {
                if (isset($need[$key]) && $need[$key] !== []) {
                    $rows = [];
                    foreach ($need[$key] as $id) {
                        $rows[] = '#' . $id . (isset($names[$id]) ? ' ' . $names[$id] : '');
                    }
                    $data[$label] = $rows;
                }
            }
            $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $lines[] = 'The current shopping need of this conversation (it carries over between messages until the customer asks for something new):' . "\n" . Prompt::fence('current_need', $json);
        }
        if ($kind === 'refine') {
            $lines[] = isset($need['q']) && $need['q'] !== ''
                ? 'This message narrows the current need: search again for the need plus what they just said (product types that fit, for example "dress shoes men" or "suit men" for a smart wedding and a man), never with their words alone, and show 3 to 6 options that fit. Do not show the discarded ones again.'
                : 'The customer is narrowing something, but there is no current need yet: ask what they are looking for, with suggest_replies offering a few categories.';
        } elseif ($kind === 'vague') {
            $lines[] = 'This is a vague need: advise like a shop assistant. Search with up to 3 queries of your own (product types and synonyms that fit the occasion or the person), all in the same round; show 3 to 6 varied options (different types and prices) with show_products; say in a few words why each group fits; and ask ONE question to narrow it down (for whom, budget, style), with suggest_replies offering choices that exist in the results.';
        } elseif ($kind === 'new') {
            $lines[] = 'This is a new need: it replaces the previous one; do not talk about the products of the previous need.';
        }
        if ($cats !== [] && in_array($kind, ['vague', 'refine', 'browse', 'reset', 'retry'], true)) {
            $list = [];
            foreach (array_slice($cats, 0, 30) as $cat) {
                if (isset($cat['id'], $cat['name'])) {
                    $list[] = ['id' => (int) $cat['id'], 'name' => (string) $cat['name']];
                }
            }
            $json = (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $lines[] = 'What the shop sells (its categories; search_products accepts category_id):' . "\n" . Prompt::fence('shop_categories', $json);
        }

        return implode("\n", $lines);
    }

    /**
     * Apunta los productos enseñados para la necesidad (los de esta respuesta), sin repetir y con un tope.
     *
     * @param array<string,mixed> $need
     * @param int[]               $ids
     *
     * @return array<string,mixed>
     */
    public static function showed(array $need, array $ids): array
    {
        $need = self::clean($need);
        if ($need === [] || $ids === []) {
            return $need;
        }
        $shown = isset($need['shown']) ? $need['shown'] : [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $shown, true)) {
                $shown[] = $id;
            }
        }
        $need['shown'] = array_slice($shown, -self::MAX_IDS);

        return $need;
    }

    /**
     * La necesidad tal como viene del estado, con cada cosa de su tipo (lo guardado lo pudo tocar cualquiera).
     *
     * @param mixed $raw
     *
     * @return array<string,mixed>
     */
    public static function clean($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (['q', 's', 'for', 'group'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $out[$key] = self::cut(trim($raw[$key]));
            }
        }
        if (isset($raw['max']) && is_numeric($raw['max']) && (int) $raw['max'] > 0) {
            $out['max'] = (int) $raw['max'];
        }
        if (!empty($raw['cheaper'])) {
            $out['cheaper'] = 1;
        }
        if (!empty($raw['vague'])) {
            $out['vague'] = 1;
        }
        if (isset($raw['more']) && is_array($raw['more'])) {
            $more = [];
            foreach ($raw['more'] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $more[] = mb_substr(trim($item), 0, 30, 'UTF-8');
                }
            }
            if ($more !== []) {
                $out['more'] = array_slice(array_values(array_unique($more)), -self::MAX_MORE);
            }
        }
        foreach (['shown', 'dropped'] as $key) {
            if (isset($raw[$key]) && is_array($raw[$key])) {
                $ids = [];
                foreach ($raw[$key] as $id) {
                    if (is_numeric($id) && (int) $id > 0) {
                        $ids[] = (int) $id;
                    }
                }
                if ($ids !== []) {
                    $out[$key] = array_slice(array_values(array_unique($ids)), -self::MAX_IDS);
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ interior

    /**
     * Lo que afina dentro de una frase más larga (la de una necesidad nueva): para quién y el presupuesto.
     *
     * @return array<string,mixed>
     */
    private static function termsIn(string $message): array
    {
        $n = RuleBrain::norm($message);
        $out = [];
        $who = implode('|', array_keys(self::WHO));
        if (preg_match('/\b(?:para|for)\s+(?:(?:un|una|el|la|mi|a|an|my)\s+)?(' . $who . ')\b/', $n, $m) === 1) {
            $out['for'] = $m[1];
            $out['group'] = self::WHO[$m[1]];
        }
        $max = RuleBrain::searchQuery($n)[1];
        if ($max !== null) {
            $out['max'] = $max;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $need
     * @param array<string,mixed> $terms   lo que afina (refineTerms)
     * @param int[]               $lastIds
     *
     * @return array<string,mixed>
     */
    private static function merge(array $need, array $terms, array $lastIds): array
    {
        if (isset($terms['for'])) {
            $need['for'] = $terms['for'];
            $need['group'] = $terms['group'];
        }
        if (isset($terms['max'])) {
            $need['max'] = $terms['max'];
        }
        if (!empty($terms['cheaper'])) {
            $need['cheaper'] = 1;
        }
        if (isset($terms['more'])) {
            $more = isset($need['more']) && is_array($need['more']) ? $need['more'] : [];
            foreach ($terms['more'] as $item) {
                $more[] = $item;
            }
            $need['more'] = $more;
        }
        if (!empty($terms['other']) && $lastIds !== []) {
            $dropped = isset($need['dropped']) && is_array($need['dropped']) ? $need['dropped'] : [];
            $need['dropped'] = array_merge($dropped, array_map('intval', $lastIds));
        }

        return self::clean($need);
    }

    /**
     * ¿Es todo el mensaje un sí, un no o una cortesía?
     *
     * @param string[] $words
     */
    private static function chat(array $words): bool
    {
        foreach ($words as $word) {
            if (!in_array($word, self::CHAT, true)) {
                return false;
            }
        }

        return true;
    }

    private static function cut(string $text): string
    {
        return mb_substr($text, 0, self::MAX_WORDS_CHARS, 'UTF-8');
    }
}
