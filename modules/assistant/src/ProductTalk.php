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
 * Hablar de un producto cuando se pregunta por él («háblame de…», «cuéntame más», «qué es», «características», «y este qué tal»,
 * «háblame de él» después de enseñar uno).
 *
 *  - asks(): ¿pregunta por un producto?
 *  - target(): ¿por cuál? Un pronombre («él», «eso», «este») o nada más que la pregunta es el último producto enseñado, nunca el
 *    asistente; un nombre se busca entre lo enseñado y, si no, en el buscador (solo vale un acierto exacto).
 *  - describe(): lo que dice su ficha, en pocas líneas: qué es y para quién (las frases del resumen y de la descripción, con sus
 *    palabras), hasta cinco características, las opciones que quedan, el precio (y el anterior si está rebajado) y si hay existencias.
 *    Sin descripción lo dice («la ficha solo dice…») y ofrece comparar o ver parecidos. Nunca «aquí tienes los detalles» sin detalles.
 *  - covers(): ¿habla de verdad del producto el texto del modelo? Si no (un «aquí tienes los detalles», una presentación del asistente),
 *    la tienda pone el suyo.
 */
final class ProductTalk
{
    /** Las palabras con las que se pregunta por un producto (texto ya normalizado). */
    private const RE_ASK = '/\b(?:habl(?:ame|anos|as|es|a|ar|arme|e)|cuentame|cuentanos|me cuentas|explicame|explicanos|informacion|info|detalles|caracteristicas|especificaciones|ficha tecnica|que es|que son|que tal es|que tal|para que sirve|para que vale|como es|como son|ver tallas|ver opciones|ver colores|ver medidas|tell me about|tell me more|more about|what is|what are|details|features|specs|see sizes|see options)\b/';

    /** Lo que se quita de la pregunta para ver si nombra un producto. */
    private const ASK_WORDS = ['me', 'te', 'nos', 'hablame', 'hablanos', 'hablas', 'hables', 'habla', 'hablar', 'hablarme', 'hable', 'sobre', 'de',
        'del', 'acerca', 'quiero', 'que', 'cuentame', 'cuentanos', 'cuentas', 'mas', 'informacion', 'info', 'detalles', 'caracteristicas',
        'especificaciones', 'ficha', 'tecnica', 'explicame', 'explicanos', 'un', 'una', 'y', 'por', 'favor', 'tal', 'es', 'son', 'sirve', 'vale',
        'para', 'como', 'ver', 'tallas', 'opciones', 'colores', 'medidas', 'puedes', 'podrias', 'dime', 'dame', 'quisiera', 'saber', 'conocer',
        'algo', 'poco', 'tell', 'about', 'more', 'what', 'is', 'are', 'the', 'a', 'an', 'details', 'features', 'specs', 'please', 'of', 'how',
        'see', 'sizes', 'options', 'can', 'you', 'could', 'mi', 'tu', 'lo', 'la', 'los', 'las', 'el', 'he'];

    /** Lo que señala el producto que se acaba de enseñar. */
    private const POINTERS = ['el', 'ella', 'ello', 'eso', 'esto', 'este', 'esta', 'ese', 'esa', 'aquel', 'aquella', 'lo', 'la', 'it', 'this',
        'that', 'one', 'producto', 'articulo', 'modelo', 'him', 'her'];

    /** Palabras que no dicen nada de un producto en concreto (para saber si el texto del modelo habla de él). */
    private const COMMON = ['producto', 'productos', 'tienda', 'precio', 'precios', 'nuestro', 'nuestra', 'puede', 'pueden', 'tiene', 'tienen',
        'estos', 'estas', 'entre', 'sobre', 'todos', 'todas', 'desde', 'hasta', 'donde', 'cuando', 'porque', 'tambien', 'ademas', 'muchos',
        'muchas', 'cualquier', 'gracias', 'about', 'there', 'these', 'those', 'their', 'which', 'would', 'could', 'should', 'other', 'every'];

    /** Características que se cuentan, como mucho. */
    public const MAX_FACTS = 5;

    /** Frases de la ficha que se cuentan, como mucho. */
    private const MAX_SENTENCES = 4;

    /** Palabras de esas frases, como mucho. */
    private const MAX_SENTENCE_WORDS = 70;

    /** ¿Pregunta el mensaje (ya normalizado) por un producto? */
    public static function asks(string $n): bool
    {
        return $n !== '' && preg_match(self::RE_ASK, $n) === 1;
    }

    /**
     * Las palabras de la pregunta que pueden ser el nombre de un producto (las de pregunta y los pronombres fuera), con las del cliente.
     *
     * @return string[]
     */
    public static function nameWords(string $message): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}\'-]+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $n = RuleBrain::norm($word);
            if ($n === '' || in_array($n, self::ASK_WORDS, true) || in_array($n, self::POINTERS, true)) {
                continue;
            }
            $out[] = $word;
        }

        return $out;
    }

    /**
     * El producto del que pregunta: 0 si no se sabe.
     *
     * @param array<string,mixed> $state  el estado de la conversación (cards: los de la última respuesta; shown: los de la conversación)
     * @param callable            $search (string $consulta): array, lo que devuelve search_products. No pasa por el ejecutor de la
     *                                    respuesta: buscar el producto por el que se pregunta no pone fichas ni cuenta como búsqueda
     */
    public static function target(string $message, array $state, callable $search): int
    {
        $cards = [];
        foreach (isset($state['cards']) && is_array($state['cards']) ? $state['cards'] : [] as $card) {
            if (is_array($card) && isset($card['id'], $card['n'])) {
                $cards[] = ['id' => (int) $card['id'], 'n' => (string) $card['n']];
            }
        }
        $shown = $cards;
        foreach (isset($state['shown']) && is_array($state['shown']) ? array_reverse($state['shown']) : [] as $row) {
            if (is_array($row) && isset($row['id'], $row['n'])) {
                $shown[] = ['id' => (int) $row['id'], 'n' => (string) $row['n']];
            }
        }
        $words = self::nameWords($message);
        if ($words === []) {
            // «Háblame de él», «cuéntame más», «y este qué tal»: el producto que se acaba de enseñar.
            return $cards !== [] ? $cards[0]['id'] : ($shown !== [] ? $shown[0]['id'] : 0);
        }
        $n = RuleBrain::norm($message);
        if ($cards !== [] && preg_match('/\b(?:segund[oa]|second)\b/', $n) === 1 && isset($cards[1]) && count($words) <= 1) {
            return $cards[1]['id'];
        }
        // Un nombre: primero entre lo ya enseñado (todas sus palabras de peso en el nombre).
        $stems = [];
        foreach ($words as $word) {
            $w = RuleBrain::norm($word);
            if (mb_strlen($w, 'UTF-8') >= 3) {
                $stems[] = mb_substr($w, 0, 5, 'UTF-8');
            }
        }
        if ($stems !== []) {
            foreach ($shown as $row) {
                $name = ' ' . RuleBrain::norm($row['n']);
                $all = true;
                foreach ($stems as $stem) {
                    if (strpos($name, ' ' . $stem) === false) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    return $row['id'];
                }
            }
        }
        // Y si no, en el buscador de la tienda: solo un acierto exacto.
        $result = call_user_func($search, mb_substr(implode(' ', $words), 0, 120, 'UTF-8'));
        if (is_array($result) && isset($result['answer_kind'], $result['items'][0]['id']) && $result['answer_kind'] === 'exact') {
            return (int) $result['items'][0]['id'];
        }

        return 0;
    }

    /**
     * Lo que dice la ficha, para el cliente (las cadenas fijas, ya traducidas; lo demás, con las palabras de la ficha).
     *
     * @param array<string,mixed> $p lo que devolvió get_product
     */
    public static function describe(array $p): string
    {
        $name = (string) (isset($p['name']) ? $p['name'] : '');
        $lines = [];
        $sentences = self::sentences($p);
        $facts = self::facts($p);
        if ($sentences !== []) {
            $lines[] = '**' . $name . '**: ' . implode(' ', $sentences);
        } elseif ($facts !== []) {
            $lines[] = t('The product page of «{name}» has no description; it only says:', ['name' => $name]);
        } else {
            $lines[] = t('The product page of «{name}» has no description: it only says its price and whether it is in stock.', ['name' => $name]);
        }
        foreach ($facts as $fact) {
            $lines[] = '- ' . $fact;
        }
        foreach (self::options($p) as $option) {
            $lines[] = $option;
        }
        $lines[] = self::priceLine($p);
        if ($sentences === []) {
            $lines[] = t('I can compare it with another product or show you similar ones.');
        }

        return implode("\n", array_values(array_filter($lines, static function (string $line): bool {
            return trim($line) !== '';
        })));
    }

    /**
     * ¿Habla el texto de este producto con datos de su ficha? Alguna palabra de peso de su resumen, su descripción, sus características
     * o sus opciones que no sea de su nombre; con una ficha sin datos, que diga que no los tiene. Un «aquí tienes los detalles» o un
     * «soy un asistente…» nunca cuentan.
     *
     * @param array<string,mixed> $p
     */
    public static function covers(string $text, array $p): bool
    {
        $t = ' ' . RuleBrain::norm($text) . ' ';
        if (trim($t) === '' || preg_match('/\b(?:soy (?:un |el |tu )?asistente|soy una (?:ia|inteligencia)|i am (?:an? |the |your )?(?:ai|assistant)|aqui tienes los detalles|here are the details)\b/', $t) === 1) {
            return false;
        }
        $keys = self::keywords($p);
        if ($keys === []) {
            return preg_match('/\b(?:solo dice|no tiene descripcion|sin descripcion|no trae descripcion|only says|no description)\b/', $t) === 1;
        }
        foreach ($keys as $key) {
            if (strpos($t, ' ' . $key) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hasta cinco características: las de la ficha y los atributos, sin repetir.
     *
     * @param array<string,mixed> $p
     *
     * @return string[] «Material: Tela»
     */
    public static function facts(array $p): array
    {
        $out = [];
        $labels = [];
        foreach (['facts', 'attributes'] as $key) {
            foreach (isset($p[$key]) && is_array($p[$key]) ? $p[$key] : [] as $line) {
                $line = trim((string) $line);
                $label = RuleBrain::norm((string) strstr($line . ':', ':', true));
                if ($line === '' || isset($labels[$label])) {
                    continue;
                }
                $labels[$label] = true;
                $out[] = $line;
                if (count($out) >= self::MAX_FACTS) {
                    return $out;
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ interior

    /**
     * Las frases de la ficha (resumen y descripción) que dicen qué es: hasta cuatro, sin repetir y sin pasarse de palabras.
     *
     * @param array<string,mixed> $p
     *
     * @return string[]
     */
    private static function sentences(array $p): array
    {
        $out = [];
        $seen = [];
        $words = 0;
        foreach (['summary', 'description'] as $key) {
            $text = trim((string) (isset($p[$key]) ? $p[$key] : ''));
            if ($text === '') {
                continue;
            }
            foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $text) ?: [] as $sentence) {
                $sentence = trim((string) preg_replace('/\s+/u', ' ', $sentence));
                $norm = RuleBrain::norm($sentence);
                if ($sentence === '' || $norm === '' || isset($seen[$norm])) {
                    continue;
                }
                $count = count(explode(' ', $norm));
                if ($out !== [] && $words + $count > self::MAX_SENTENCE_WORDS) {
                    break 2;
                }
                $seen[$norm] = true;
                if (preg_match('/[.!?…]$/u', $sentence) !== 1) {
                    $sentence .= '.';
                }
                $out[] = $sentence;
                $words += $count;
                if (count($out) >= self::MAX_SENTENCES) {
                    break 2;
                }
            }
        }

        return $out;
    }

    /**
     * Las opciones con los valores que quedan («Talla: 39, 40 (agotadas: 41)»).
     *
     * @param array<string,mixed> $p
     *
     * @return string[]
     */
    private static function options(array $p): array
    {
        $out = [];
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            if (!is_array($option) || !isset($option['name'])) {
                continue;
            }
            $values = isset($option['values']) && is_array($option['values']) ? array_map('strval', $option['values']) : [];
            $sold = isset($option['sold_out']) && is_array($option['sold_out']) ? array_map('strval', $option['sold_out']) : [];
            if ($values === [] && $sold === []) {
                continue;
            }
            $line = (string) $option['name'] . ': ' . ($values !== [] ? implode(', ', array_slice($values, 0, 12)) : t('none left'));
            if ($sold !== []) {
                $line .= ' (' . t('sold out: {list}', ['list' => implode(', ', array_slice($sold, 0, 8))]) . ')';
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * El precio (con el anterior si está rebajado) y si hay existencias.
     *
     * @param array<string,mixed> $p
     */
    private static function priceLine(array $p): string
    {
        $price = (string) (isset($p['price']) ? $p['price'] : '');
        if ($price === '') {
            return '';
        }
        if (!empty($p['from'])) {
            $price = t('from {price}', ['price' => $price]);
        }
        $compare = isset($p['compare_price']) && is_string($p['compare_price']) && $p['compare_price'] !== '' ? $p['compare_price'] : '';
        $line = $compare !== '' ? t('Price: {price} (before {before}).', ['price' => $price, 'before' => $compare]) : t('Price: {price}.', ['price' => $price]);
        $availability = isset($p['availability']) && is_array($p['availability']) ? $p['availability'] : [];
        $status = (string) (isset($availability['status']) ? $availability['status'] : '');
        if ($status === 'out_of_stock') {
            return $line . ' ' . t('It is sold out right now.');
        }
        $label = trim((string) (isset($availability['label']) ? $availability['label'] : ''));

        return $label !== '' ? $line . ' ' . $label . '.' : $line;
    }

    /**
     * Las palabras de peso de la ficha (sin las del nombre): de su resumen, su descripción, sus características y sus opciones.
     *
     * @param array<string,mixed> $p
     *
     * @return string[]
     */
    private static function keywords(array $p): array
    {
        $name = ' ' . RuleBrain::norm((string) (isset($p['name']) ? $p['name'] : '')) . ' ';
        $text = (string) (isset($p['summary']) ? $p['summary'] : '') . ' ' . (string) (isset($p['description']) ? $p['description'] : '');
        foreach (self::facts($p) as $fact) {
            $text .= ' ' . $fact;
        }
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            if (is_array($option) && isset($option['values']) && is_array($option['values'])) {
                $text .= ' ' . implode(' ', array_map('strval', $option['values']));
            }
        }
        $out = [];
        foreach (explode(' ', RuleBrain::norm($text)) as $word) {
            if (mb_strlen($word, 'UTF-8') < 5 || in_array($word, self::COMMON, true) || strpos($name, ' ' . $word . ' ') !== false) {
                continue;
            }
            // Por su raíz de cinco letras: «macizo» habla de «maciza».
            $out[mb_substr($word, 0, 5, 'UTF-8')] = true;
            if (count($out) >= 40) {
                break;
            }
        }

        return array_keys($out);
    }
}
