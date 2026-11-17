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

use Aifec\Ai\AiTool;
use Aifec\Util\Str;

/**
 * Los botones de respuesta rápida que siguen a una respuesta. Salen de lo que se acaba de enseñar, nunca son los mismos dos de
 * siempre:
 *
 *  - tras añadir al carrito: «Ir a la caja», «Ver mi carrito» y «Seguir mirando» (los datos personales no se piden hasta que el cliente
 *    quiere pagar);
 *  - tras hablar de un producto (o enseñar uno solo): «Ver tallas» u «Ver opciones» si las tiene, «Parecidos» y «Más barato» si los
 *    hay, y «Gastos de envío»;
 *  - tras una lista: los de afinar, sacados de lo enseñado: las categorías que la separan («Para hombre», «Zapatos») y un tope de
 *    precio («Hasta 60,00 €»). Cada botón de afinar o de «Parecidos» lleva los productos a los que lleva (se guardan en el estado,
 *    clave «chips»): pulsarlo nunca da cero productos.
 *
 * El modelo puede proponer los suyos con la herramienta «suggest_replies»; la tienda los valida (check) y descarta los que no pasan:
 * más de 28 caracteres, marcas o direcciones, un correo, un teléfono, un importe que no salió de una herramienta (un tope «hasta …»
 * vale si algún producto de la respuesta cuesta eso o menos) o una cifra que no salió de una herramienta. Como mucho cuatro.
 */
final class Replies
{
    /** Botones que se enseñan, como mucho. */
    public const MAX = 4;

    /** Caracteres de un botón, como mucho. */
    public const MAX_CHARS = 28;

    /** Productos que lleva guardados un botón, como mucho. */
    private const MAX_IDS = 6;

    /** Topes de precio redondos (céntimos) para el botón «Hasta …». */
    private const NICE = [500, 1000, 1500, 2000, 2500, 3000, 4000, 5000, 6000, 7500, 10000, 15000, 20000, 25000, 30000, 40000, 50000, 75000,
        100000, 150000, 200000, 300000, 500000, 750000, 1000000];

    /** La herramienta «suggest_replies» tal como se le ofrece al modelo. */
    public static function tool(): AiTool
    {
        return new AiTool(
            'suggest_replies',
            'Propose up to ' . self::MAX . ' short replies the customer can tap after your answer (each at most ' . self::MAX_CHARS . ' characters, plain text, in the customer\'s language): ways to narrow the need that exist in the results ("For men", "Up to 60 €", a category), "Similar ones", "Cheaper", "See sizes". Never personal data, never a price or a number that did not come out of a tool. The shop discards the ones that do not fit.',
            self::schema()
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                // Se aceptan largos de hasta 80 para poder descartar uno a uno: con el tope en el esquema se perdería la llamada entera.
                'replies' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 8, 'items' => ['type' => 'string', 'maxLength' => 80]],
            ],
            'required' => ['replies'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Un botón propuesto, limpio, o null si no pasa.
     *
     * @param int[]    $amounts importes (céntimos) que han salido de las herramientas o que escribió el cliente
     * @param string[] $numbers cifras sueltas que han salido de las herramientas («42», «39.5»)
     * @param int[]    $prices  precios (céntimos) de los productos de esta respuesta
     */
    public static function check(string $chip, array $amounts, array $numbers, array $prices): ?string
    {
        // Las comillas se quitan con Str::trim: trim() va byte a byte, y con «»“” en su lista se comía medio «¿» o medio «…» del
        // principio (comparten bytes) y dejaba el botón con un carácter roto.
        $chip = Str::trim((string) preg_replace('/\s+/u', ' ', $chip), " \t\n\r\0\x0B\"'«»“”");
        if ($chip === '' || mb_strlen($chip, 'UTF-8') > self::MAX_CHARS) {
            return null;
        }
        if (preg_match('/:$|^¿\s*ver\b|\b(\w+)\s+\1\b|#\d+|\bids?\s*\d+/iu', $chip) === 1) {
            // Lo que no es un botón (llega del asistente de PrestaShop, 0.13.0): un rótulo que acaba en «:», un «¿Ver …?», una palabra
            // repetida, un número de producto interno.
            return null;
        }
        if (preg_match('/^(?:none|null|nil|undefined|n\/a|[\p{P}\p{S}\s]+)$/iu', $chip) === 1) {
            // El hueco de un modelo que no tenía nada que proponer («None», «null», «-»): no es un botón (13/11/2026, en vivo).
            return null;
        }
        if (preg_match('/[<>{}\[\]*_`#|\\\\]|https?:|www\.|\w\*{3}|\*{3}\w|@/u', $chip) === 1) {
            return null;
        }
        if (preg_match_all('/\d/', $chip) >= 6) {
            // Un teléfono, una cuenta, una tarjeta o un código postal: datos de alguien, no un botón.
            return null;
        }
        $found = TextGuard::amounts($chip);
        if ($found !== []) {
            $budget = preg_match('/\b(?:hasta|menos de|por menos de|maximo|max|bajo|under|up to|below|less than)\b/', RuleBrain::norm($chip)) === 1;
            $ok = array_fill_keys(array_map('intval', $amounts), true);
            foreach ($found as $cents) {
                if ($budget) {
                    // Un tope de precio vale si lleva a algo: algún producto de la respuesta cuesta eso o menos.
                    $reach = false;
                    foreach ($prices as $price) {
                        $reach = $reach || ((int) $price > 0 && (int) $price <= $cents);
                    }
                    if (!$reach) {
                        return null;
                    }
                } elseif (!isset($ok[$cents])) {
                    return null;
                }
            }
        }
        $known = array_fill_keys(array_map('strval', $numbers), true);
        if (preg_match_all('/\d+(?:[.,]\d+)?/', TextGuard::stripAmounts($chip), $digits) > 0) {
            foreach ($digits[0] as $digit) {
                if (!isset($known[str_replace(',', '.', $digit)])) {
                    return null;
                }
            }
        }

        return $chip;
    }

    /**
     * Los botones de después de añadir al carrito.
     *
     * @return string[]
     */
    public static function afterAdd(): array
    {
        return [t('Go to checkout'), t('Show my cart'), t('Keep browsing')];
    }

    /** ¿Es el botón «Ir a la caja»? (en cualquiera de los dos idiomas de las cadenas). */
    public static function isCheckoutChip(string $message): bool
    {
        $n = RuleBrain::norm($message);

        return $n !== '' && ($n === RuleBrain::norm(t('Go to checkout')) || $n === 'go to checkout');
    }

    /**
     * Los botones de después de hablar de un producto: opciones si las tiene, parecidos y más baratos si los hay en su categoría,
     * y los gastos de envío. Con los productos a los que lleva cada uno.
     *
     * @param array<string,mixed> $p       lo que devolvió get_product
     * @param array<string,mixed> $context el de las herramientas
     *
     * @return array{0:string[],1:array<string,array<string,mixed>>}
     */
    public static function forProduct(array $p, Tools $tools, array $context): array
    {
        $chips = [];
        $meta = [];
        $id = (int) (isset($p['id']) ? $p['id'] : 0);
        $options = isset($p['options']) && is_array($p['options']) ? $p['options'] : [];
        if (!empty($p['has_variants']) && $options !== []) {
            $sizes = false;
            foreach ($options as $option) {
                $sizes = $sizes || (is_array($option) && preg_match('/\b(?:talla|tallas|numero|size|sizes|medida)\b/', RuleBrain::norm((string) (isset($option['name']) ? $option['name'] : ''))) === 1);
            }
            $label = $sizes ? t('See sizes') : t('See options');
            $chips[] = $label;
            $meta[$label] = ['about' => $id];
        }
        $category = 0;
        foreach (isset($p['category_ids']) && is_array($p['category_ids']) ? $p['category_ids'] : [] as $cat) {
            if ((int) $cat > 0) {
                $category = (int) $cat;
                break;
            }
        }
        if ($category > 0 && $id > 0) {
            $found = $tools->call('search_products', ['category_id' => $category, 'limit' => 8], $context);
            $similar = [];
            $cheaper = [];
            $price = (int) (isset($p['price_cents']) ? $p['price_cents'] : 0);
            foreach (isset($found['items']) && is_array($found['items']) ? $found['items'] : [] as $item) {
                if (!is_array($item) || !isset($item['id']) || (int) $item['id'] === $id) {
                    continue;
                }
                $similar[] = (int) $item['id'];
                if (!empty($item['available']) && $price > 0 && (int) (isset($item['price_cents']) ? $item['price_cents'] : 0) < $price) {
                    $cheaper[] = (int) $item['id'];
                }
            }
            if ($similar !== []) {
                $label = t('Similar ones');
                $chips[] = $label;
                $meta[$label] = ['ids' => array_slice($similar, 0, self::MAX_IDS), 'cat' => $category];
            }
            if ($cheaper !== []) {
                $label = t('Something cheaper');
                $chips[] = $label;
                $meta[$label] = ['ids' => array_slice($cheaper, 0, self::MAX_IDS), 'max' => max(1, $price - 1)];
            }
        }
        $chips[] = t('Shipping costs');

        return [array_slice($chips, 0, self::MAX), $meta];
    }

    /**
     * Los botones de afinar de una lista: las categorías que separan las fichas (las de persona primero: «Para hombre») y un tope de
     * precio redondo que deja fuera a alguna. Cada uno con las fichas a las que lleva (nunca ninguna).
     *
     * @param array<int,array<string,mixed>>                   $cards las fichas de la respuesta (Answer::$cards)
     * @param array<int,array<int,array{id:int,name:string}>> $cats  las categorías de cada producto, por id
     * @param callable                                         $money (int céntimos): string, el importe con su formato
     *
     * @return array{0:string[],1:array<string,array<string,mixed>>}
     */
    public static function refine(array $cards, array $cats, callable $money): array
    {
        $chips = [];
        $meta = [];
        $total = count($cards);
        if ($total < 2) {
            return [[], []];
        }
        $byCat = [];
        $names = [];
        foreach ($cards as $card) {
            $id = (int) $card['id'];
            foreach (isset($cats[$id]) ? $cats[$id] : [] as $cat) {
                $byCat[(int) $cat['id']][$id] = true;
                $names[(int) $cat['id']] = (string) $cat['name'];
            }
        }
        $candidates = [];
        foreach ($byCat as $catId => $ids) {
            $count = count($ids);
            $name = trim($names[$catId]);
            if ($count >= $total || $name === '') {
                continue;
            }
            $person = Need::group($name) !== '';
            $label = $person ? t('For {who}', ['who' => mb_strtolower($name, 'UTF-8')]) : $name;
            if (mb_strlen($label, 'UTF-8') > self::MAX_CHARS) {
                continue;
            }
            $candidates[] = ['label' => $label, 'person' => $person ? 1 : 0, 'count' => $count, 'cat' => $catId, 'ids' => array_keys($ids)];
        }
        usort($candidates, static function (array $a, array $b): int {
            return [$b['person'], $b['count'], $a['label']] <=> [$a['person'], $a['count'], $b['label']];
        });
        foreach (array_slice($candidates, 0, 2) as $candidate) {
            if (isset($meta[$candidate['label']])) {
                continue;
            }
            $chips[] = $candidate['label'];
            $meta[$candidate['label']] = ['cat' => $candidate['cat'], 'ids' => array_slice($candidate['ids'], 0, self::MAX_IDS)];
        }
        $cap = self::priceCap($cards);
        if ($cap !== null) {
            $label = t('Up to {amount}', ['amount' => $money($cap['max'])]);
            if (mb_strlen($label, 'UTF-8') <= self::MAX_CHARS) {
                $chips[] = $label;
                $meta[$label] = ['max' => $cap['max'], 'ids' => array_slice($cap['ids'], 0, self::MAX_IDS)];
            }
        }

        return [$chips, $meta];
    }

    /**
     * El botón pulsado, si el mensaje es uno de los que se ofrecieron con productos detrás (estado «chips»): label, ids, cat, max,
     * about. null si no.
     *
     * @param array<string,mixed> $state
     *
     * @return array<string,mixed>|null
     */
    public static function clicked(string $message, array $state): ?array
    {
        $n = RuleBrain::norm($message);
        if ($n === '') {
            return null;
        }
        foreach (isset($state['chips']) && is_array($state['chips']) ? $state['chips'] : [] as $label => $meta) {
            if (!is_string($label) || !is_array($meta) || RuleBrain::norm($label) !== $n) {
                continue;
            }
            $out = ['label' => $label, 'ids' => []];
            foreach (isset($meta['ids']) && is_array($meta['ids']) ? $meta['ids'] : [] as $id) {
                if (is_numeric($id) && (int) $id > 0) {
                    $out['ids'][] = (int) $id;
                }
            }
            $out['ids'] = array_slice($out['ids'], 0, self::MAX_IDS);
            foreach (['cat', 'max', 'about'] as $key) {
                if (isset($meta[$key]) && is_numeric($meta[$key]) && (int) $meta[$key] > 0) {
                    $out[$key] = (int) $meta[$key];
                }
            }

            return $out['ids'] !== [] || isset($out['about']) ? $out : null;
        }

        return null;
    }

    /**
     * El tope de precio que parte la lista: el redondo más cercano por encima del precio de en medio, si deja fuera a alguna ficha.
     *
     * @param array<int,array<string,mixed>> $cards
     *
     * @return array{max:int,ids:int[]}|null
     */
    private static function priceCap(array $cards): ?array
    {
        $prices = [];
        foreach ($cards as $card) {
            $price = (int) (isset($card['price_cents']) ? $card['price_cents'] : 0);
            if ($price > 0) {
                $prices[(int) $card['id']] = $price;
            }
        }
        if (count(array_unique($prices)) < 2) {
            return null;
        }
        $sorted = array_values($prices);
        sort($sorted);
        $middle = $sorted[intdiv(count($sorted) - 1, 2)];
        $cap = 0;
        foreach (self::NICE as $nice) {
            if ($nice >= $middle) {
                $cap = $nice;
                break;
            }
        }
        if ($cap === 0) {
            $cap = (int) (ceil($middle / 100000) * 100000);
        }
        $ids = [];
        foreach ($prices as $id => $price) {
            if ($price <= $cap) {
                $ids[] = $id;
            }
        }
        if ($ids === [] || count($ids) >= count($prices)) {
            return null;
        }

        return ['max' => $cap, 'ids' => $ids];
    }
}
