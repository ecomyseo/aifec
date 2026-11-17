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
 * Lo que cuestan varias unidades, calculado por la tienda y no por el modelo: «Y si pido 2 sillas baratas, ¿cuánto sale?» →
 * «2 × Silla Básica Home a 89,00 € son 178,00 €.».
 *
 * wanted() dice si el mensaje pregunta el precio de varias unidades y cuántas. quote() elige el producto entre las fichas que se
 * acaban de enseñar (el único, el que nombra o, con «barata», el más barato) y pide a la herramienta check_availability el precio
 * de esas unidades: así el importe sale de una herramienta (TextGuard lo deja pasar) y la multiplicación la hace el código.
 */
final class QtyQuote
{
    /** Las cifras escritas con letra que se entienden («dos sillas», «three chairs»). */
    private const WORDS = [
        'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10,
        'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    ];

    /** Lo que va detrás de una cifra y no es un producto. */
    private const NOT_UNITS = ['euros', 'euro', 'eur', 'dias', 'dia', 'days', 'day', 'horas', 'hours', 'semanas', 'weeks', 'meses', 'months', 'anos', 'years', 'por', 'percent', 'mas', 'more'];

    /** ¿Cuántas unidades pregunta el mensaje cuánto cuestan? 0 si no pregunta el precio de varias. */
    public static function wanted(string $message): int
    {
        $n = RuleBrain::norm($message);
        if (preg_match('/\b(?:cuanto|cuantos|precio|sale|salen|saldria|saldrian|cuesta|cuestan|costaria|costarian|vale|valen|total|how much|cost|costs|price|would be)\b/', $n) !== 1) {
            return 0;
        }
        if (preg_match_all('/\b(\d{1,2}|' . implode('|', array_keys(self::WORDS)) . ')\s+([a-z]+)/', $n, $matches, PREG_SET_ORDER) < 1) {
            return 0;
        }
        foreach ($matches as $m) {
            if (in_array($m[2], self::NOT_UNITS, true)) {
                continue;
            }
            $qty = ctype_digit($m[1]) ? (int) $m[1] : self::WORDS[$m[1]];
            if ($qty >= 2 && $qty <= 99) {
                return $qty;
            }
        }

        return 0;
    }

    /**
     * El precio de las unidades que pregunta el mensaje de uno de los productos enseñados, o null si no se sabe de cuál.
     *
     * @param array<int,array<string,mixed>> $cards las fichas que se acaban de enseñar (id, n)
     *
     * @return array{line:string,total:string,note:string,product_id:int}|null
     */
    public static function quote(ToolRunner $runner, string $message, array $cards): ?array
    {
        $qty = self::wanted($message);
        $cards = array_values(array_filter($cards, static function ($card): bool {
            return is_array($card) && isset($card['id'], $card['n']);
        }));
        if ($qty < 2 || $cards === [] || !$runner->can('check_availability')) {
            return null;
        }
        $candidates = self::named($cards, $message);
        $prices = [];
        foreach (array_slice($candidates, 0, 4) as $card) {
            $r = $runner->run('check_availability', ['product_id' => (int) $card['id'], 'qty' => $qty]);
            if (!isset($r['error']) && isset($r['total'], $r['unit_price'], $r['unit_price_cents'])) {
                $prices[] = $r;
            }
        }
        if ($prices === []) {
            return null;
        }
        if (count($prices) > 1) {
            if (!ShopTools::wantsCheapest($message)) {
                // Varios posibles y nada que diga cuál: mejor no adivinar.
                return null;
            }
            usort($prices, static function (array $a, array $b): int {
                return (int) $a['unit_price_cents'] <=> (int) $b['unit_price_cents'];
            });
        }
        $best = $prices[0];
        $name = (string) $best['name'] . ((string) $best['variant'] !== '' ? ' (' . (string) $best['variant'] . ')' : '');
        $line = t('{qty} × {name} at {unit} make {total}.', ['qty' => $qty, 'name' => $name, 'unit' => (string) $best['unit_price'], 'total' => (string) $best['total']]);

        return [
            'line' => $line,
            'total' => (string) $best['total'],
            'product_id' => (int) $best['product_id'],
            'note' => 'The shop has calculated what the units the customer asked about cost: ' . $qty . ' x ' . $name . ' at ' . (string) $best['unit_price'] . ' = ' . (string) $best['total'] . '. Say this total; never calculate one yourself.',
        ];
    }

    /**
     * Las fichas que nombra el mensaje (una palabra de peso de su nombre); si no nombra ninguna, todas.
     *
     * @param array<int,array<string,mixed>> $cards
     *
     * @return array<int,array<string,mixed>>
     */
    private static function named(array $cards, string $message): array
    {
        $words = [];
        foreach (explode(' ', RuleBrain::norm($message)) as $word) {
            if (mb_strlen($word, 'UTF-8') >= 4) {
                $words[] = mb_substr($word, 0, 5, 'UTF-8');
            }
        }
        $scores = [];
        foreach ($cards as $i => $card) {
            $score = 0;
            foreach (explode(' ', RuleBrain::norm((string) $card['n'])) as $part) {
                if (mb_strlen($part, 'UTF-8') >= 4 && in_array(mb_substr($part, 0, 5, 'UTF-8'), $words, true)) {
                    ++$score;
                }
            }
            $scores[$i] = $score;
        }
        $top = $scores === [] ? 0 : max($scores);
        if ($top === 0) {
            return $cards;
        }
        $out = [];
        foreach ($cards as $i => $card) {
            if ($scores[$i] === $top) {
                $out[] = $card;
            }
        }

        return $out;
    }
}
