<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Search;

use Aifec\Kernel\App;

/**
 * Entiende una frase sin IA, solo con reglas: «silla de oficina negra por menos de 200 €» sale como una categoría, un color, un
 * tope de precio y el texto que queda («oficina») para el buscador de siempre.
 *
 * Reglas (las palabras de cada una están en el catálogo de idioma, ver Words):
 *   precio        «menos de 200», «hasta 50 €», «entre 50 y 100», «más de 300»
 *   atributos     los valores que existen en el catálogo (color, material…), en plural o sin tildes
 *   medidas       un número con su unidad contra un atributo numérico («2 metros», «500 g», «hasta 30 cm»)
 *   categoría y marca   por su nombre, solo si la frase trae otros filtros (un nombre de varias palabras con uno basta; uno de una
 *                       palabra necesita dos, o categoría y marca juntas): con «silla negra» se busca el texto «silla»
 *   existencias   «en stock», «disponible ya»          oferta   «rebajado», «en oferta»
 *   orden         «barato», «novedades», «más vendidos», «mejor valorados»
 *   negación      «sin cuero»
 *   sinónimos     los del ajuste «search.synonyms» (una línea por grupo: «barato = económico, chollo»)
 *
 * Cada ficha de lo entendido sabe qué palabras de la frase la produjeron: «remove_query» es la frase sin ellas, que es la
 * dirección de su botón «quitar». Un valor que no está en el catálogo nunca se convierte en filtro.
 */
final class Understand
{
    /** Largo máximo de la frase que se entiende. */
    private const MAX_QUERY = 200;

    /** @var App */
    private $app;

    /** @var Search */
    private $search;

    /** @var Words */
    private $words;

    /** @var Vocabulary */
    private $vocabulary;

    public function __construct(App $app, Search $search, Words $words, Vocabulary $vocabulary)
    {
        $this->app = $app;
        $this->search = $search;
        $this->words = $words;
        $this->vocabulary = $vocabulary;
    }

    /**
     * @return array<string,mixed> query, text, filters (con 'src'), catalog, restrict, words
     */
    public function run(string $query, string $lang, bool $ratings = true): array
    {
        $query = trim(mb_substr(Analyzer::clean($query), 0, self::MAX_QUERY, 'UTF-8'));
        $orig = self::tokenize($query);
        $st = [
            'lang' => $lang, 'query' => $query, 'orig' => $orig, 'eff' => $this->effective($orig, $lang),
            'used' => [], 'price' => ['min' => null, 'max' => null, 'src' => []], 'numbers' => [], 'attrs' => [], 'flags' => [],
            'without' => [], 'weak' => [], 'sort' => null, 'ratings' => $ratings,
        ];
        if ($orig === []) {
            return $this->finish($st, []);
        }
        $this->rangePrice($st);
        $this->measures($st);
        $this->limitPrice($st);
        $this->flags($st);
        $this->without($st);
        $this->names($st);

        return $this->finish($st, []);
    }

    // ------------------------------------------------------------------ las palabras

    /**
     * @return array<int,array{s:string,w:string,o:int,l:int,n:bool}>
     */
    private static function tokenize(string $query): array
    {
        $re = '/\p{N}+(?:[.,]\p{N}+)*|[\p{L}\p{M}]+(?:[-._][\p{L}\p{M}\p{N}]+)*|€/u';
        if ($query === '' || preg_match_all($re, $query, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $out = [];
        foreach ($m[0] as $hit) {
            $surface = (string) $hit[0];
            $out[] = [
                's' => $surface, 'w' => Words::fold($surface), 'o' => (int) $hit[1], 'l' => strlen($surface),
                'n' => preg_match('/^\p{N}+(?:[.,]\p{N}+)*$/u', $surface) === 1,
            ];
        }

        return $out;
    }

    /**
     * Las palabras con los sinónimos del comerciante ya aplicados: cada alias se cambia por su palabra principal, y esa palabra
     * recuerda de qué palabras de la frase salió.
     *
     * @param array<int,array<string,mixed>> $orig
     *
     * @return array<int,array{w:string,src:int[],n:bool}>
     */
    private function effective(array $orig, string $lang): array
    {
        $map = $this->synonymMap();
        $out = [];
        $count = count($orig);
        for ($i = 0; $i < $count; ++$i) {
            $matched = 0;
            foreach ($map as $entry) {
                $len = count($entry['alias']);
                if ($i + $len > $count) {
                    continue;
                }
                $same = true;
                foreach ($entry['alias'] as $k => $word) {
                    if ($orig[$i + $k]['w'] !== $word) {
                        $same = false;
                        break;
                    }
                }
                if ($same) {
                    $matched = $len;
                    $src = range($i, $i + $len - 1);
                    foreach ($entry['main'] as $word) {
                        $out[] = ['w' => $word, 'src' => $src, 'n' => false];
                    }
                    break;
                }
            }
            if ($matched > 0) {
                $i += $matched - 1;
                continue;
            }
            $out[] = ['w' => (string) $orig[$i]['w'], 'src' => [$i], 'n' => (bool) $orig[$i]['n']];
        }

        return $out;
    }

    /**
     * Los sinónimos del ajuste «search.synonyms»: de cada línea («principal = alias, alias» o «a, b, c»), cada alias con su palabra
     * principal. Los alias más largos van primero.
     *
     * @return array<int,array{alias:string[],main:string[]}>
     */
    private function synonymMap(): array
    {
        $out = [];
        $text = $this->app->settings()->str('search.synonyms', '');
        foreach (preg_split('/\R/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($main, $aliases) = explode('=', $line, 2);
                $items = array_merge([$main], explode(',', $aliases));
            } else {
                $items = explode(',', $line);
            }
            $phrases = [];
            foreach ($items as $item) {
                $words = preg_split('/\s+/u', Words::fold($item), -1, PREG_SPLIT_NO_EMPTY);
                if (is_array($words) && $words !== []) {
                    $phrases[] = $words;
                }
            }
            if (count($phrases) < 2) {
                continue;
            }
            $main = array_shift($phrases);
            foreach ($phrases as $alias) {
                if ($alias !== $main) {
                    $out[] = ['alias' => $alias, 'main' => $main];
                }
            }
        }
        usort($out, static function (array $a, array $b): int {
            return count($b['alias']) <=> count($a['alias']);
        });

        return $out;
    }

    // ------------------------------------------------------------------ utilidades de las reglas

    /**
     * ¿Se puede usar la palabra? (ninguna de las palabras de la frase de las que salió está ya en una ficha)
     *
     * @param array<string,mixed> $st
     */
    private function free(array $st, int $i): bool
    {
        if (!isset($st['eff'][$i])) {
            return false;
        }
        foreach ($st['eff'][$i]['src'] as $s) {
            if (isset($st['used'][$s])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cuántas palabras ocupa la frase de la lista que empieza en $i (0 si ninguna).
     *
     * @param array<string,mixed> $st
     */
    private function phraseAt(array $st, string $list, int $i): int
    {
        foreach ($this->words->phrases($list, $st['lang']) as $phrase) {
            $ok = true;
            foreach ($phrase as $k => $word) {
                if (!isset($st['eff'][$i + $k]) || $st['eff'][$i + $k]['w'] !== $word || !$this->free($st, $i + $k)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return count($phrase);
            }
        }

        return 0;
    }

    /**
     * Cuántas palabras ocupa la frase de la lista que acaba justo antes de $i.
     *
     * @param array<string,mixed> $st
     */
    private function phraseEndingBefore(array $st, string $list, int $i): int
    {
        foreach ($this->words->phrases($list, $st['lang']) as $phrase) {
            $len = count($phrase);
            $start = $i - $len;
            if ($start < 0) {
                continue;
            }
            $ok = true;
            foreach ($phrase as $k => $word) {
                if ($st['eff'][$start + $k]['w'] !== $word || !$this->free($st, $start + $k)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $len;
            }
        }

        return 0;
    }

    /**
     * Da por usadas las palabras (de la i a la i+len) y devuelve de qué palabras de la frase salieron.
     *
     * @param array<string,mixed> $st
     *
     * @return int[]
     */
    private function take(array &$st, int $from, int $len): array
    {
        $src = [];
        for ($i = $from; $i < $from + $len; ++$i) {
            foreach ($st['eff'][$i]['src'] as $s) {
                $st['used'][$s] = true;
                $src[$s] = $s;
            }
        }

        return array_values($src);
    }

    /** ¿Es la palabra $i un número libre? */
    private function numberAt(array $st, int $i): bool
    {
        return isset($st['eff'][$i]) && $st['eff'][$i]['n'] && $this->free($st, $i);
    }

    /**
     * Un importe escrito como se escribe en castellano («49,99», «1.500», «200») en céntimos.
     */
    public static function cents(string $text): ?int
    {
        $text = trim($text);
        if (preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d+)?$/', $text) === 1) {
            $text = str_replace('.', '', $text);
        }
        $text = str_replace(',', '.', $text);
        if (preg_match('/^(\d{1,9})(?:\.(\d+))?$/', $text, $m) !== 1) {
            return null;
        }
        $decimals = isset($m[2]) ? $m[2] : '';
        $cents = (int) str_pad(substr($decimals, 0, 2), 2, '0');
        if (strlen($decimals) > 2 && (int) $decimals[2] >= 5) {
            ++$cents;
        }

        return (int) $m[1] * 100 + $cents;
    }

    private static function number(string $text): ?float
    {
        $cents = self::cents($text);

        return $cents === null ? null : $cents / 100;
    }

    /** Una moneda detrás de un importe («€», «euros»): se da por usada y se devuelve cuántas palabras ocupa. */
    private function currencyAfter(array &$st, int $i): int
    {
        $len = $this->phraseAt($st, 'currency', $i);

        return $len;
    }

    // ------------------------------------------------------------------ las reglas

    /**
     * «entre 50 y 100 €».
     *
     * @param array<string,mixed> $st
     */
    private function rangePrice(array &$st): void
    {
        $count = count($st['eff']);
        for ($i = 0; $i < $count; ++$i) {
            $len = $this->phraseAt($st, 'between', $i);
            if ($len === 0 || !$this->numberAt($st, $i + $len)) {
                continue;
            }
            $a = $i + $len;
            $and = $this->phraseAt($st, 'and', $a + 1);
            if ($and === 0 || !$this->numberAt($st, $a + 1 + $and)) {
                continue;
            }
            $b = $a + 1 + $and;
            $low = self::cents($st['orig'][$st['eff'][$a]['src'][0]]['s']);
            $high = self::cents($st['orig'][$st['eff'][$b]['src'][0]]['s']);
            if ($low === null || $high === null) {
                continue;
            }
            $end = $b + 1;
            $end += $this->currencyAfter($st, $end);
            $src = $this->take($st, $i, $end - $i);
            $st['price'] = ['min' => min($low, $high), 'max' => max($low, $high), 'src' => array_merge($st['price']['src'], $src)];
            $i = $end - 1;
        }
    }

    /**
     * Un número con su unidad contra un atributo numérico del catálogo («2 metros», «hasta 30 cm», «peso 500 g»).
     *
     * @param array<string,mixed> $st
     */
    private function measures(array &$st): void
    {
        $voc = $this->vocabulary->get($st['lang']);
        if ($voc['numbers'] === []) {
            return;
        }
        $reverse = [];
        foreach ($this->words->units($st['lang']) as $unit => $forms) {
            foreach ($forms as $form) {
                $reverse[$form] = $unit;
            }
        }
        $count = count($st['eff']);
        for ($i = 0; $i < $count; ++$i) {
            if (!$this->numberAt($st, $i) || !$this->free($st, $i + 1) || !isset($st['eff'][$i + 1])) {
                continue;
            }
            $word = $st['eff'][$i + 1]['w'];
            $unit = isset($reverse[$word]) ? $reverse[$word] : $word;
            $candidates = [];
            foreach ($voc['numbers'] as $code => $attr) {
                if ($attr['unit'] === $unit) {
                    $candidates[$code] = $attr;
                }
            }
            if ($candidates === []) {
                continue;
            }
            $op = '=';
            $opLen = $this->phraseEndingBefore($st, 'max', $i);
            if ($opLen > 0) {
                $op = '<=';
            } else {
                $opLen = $this->phraseEndingBefore($st, 'min', $i);
                if ($opLen > 0) {
                    $op = '>=';
                }
            }
            $start = $i - $opLen;
            // Si hay varios atributos con esa unidad (largo y ancho, en cm), decide el nombre que va delante.
            $nameLen = 0;
            $pick = null;
            if (count($candidates) === 1) {
                $pick = reset($candidates);
            } elseif ($start > 0 && $this->free($st, $start - 1)) {
                $stems = $this->search->analyzer($st['lang'])->tokens($st['eff'][$start - 1]['w']);
                foreach ($candidates as $attr) {
                    if ($stems !== [] && $attr['stems'] === implode(' ', $stems)) {
                        $pick = $attr;
                        $nameLen = 1;
                        break;
                    }
                }
            }
            $value = self::number($st['orig'][$st['eff'][$i]['src'][0]]['s']);
            if ($pick === null || $value === null) {
                continue;
            }
            $from = $start - $nameLen;
            $src = $this->take($st, $from, $i + 2 - $from);
            $st['numbers'][] = ['code' => (string) $pick['code'], 'name' => (string) $pick['name'], 'unit' => (string) $pick['unit'], 'op' => $op, 'value' => $value, 'src' => $src];
            $i += 1;
        }
    }

    /**
     * «menos de 200 €», «más de 300», «hasta 50».
     *
     * @param array<string,mixed> $st
     */
    private function limitPrice(array &$st): void
    {
        $count = count($st['eff']);
        for ($i = 0; $i < $count; ++$i) {
            $kind = 'max';
            $len = $this->phraseAt($st, 'max', $i);
            if ($len === 0) {
                $kind = 'min';
                $len = $this->phraseAt($st, 'min', $i);
            }
            if ($len === 0 || !$this->numberAt($st, $i + $len)) {
                continue;
            }
            $cents = self::cents($st['orig'][$st['eff'][$i + $len]['src'][0]]['s']);
            if ($cents === null) {
                continue;
            }
            $end = $i + $len + 1;
            $end += $this->currencyAfter($st, $end);
            $src = $this->take($st, $i, $end - $i);
            $st['price'][$kind] = $cents;
            $st['price']['src'] = array_merge($st['price']['src'], $src);
            $i = $end - 1;
        }
    }

    /**
     * Oferta, existencias y orden.
     *
     * @param array<string,mixed> $st
     */
    private function flags(array &$st): void
    {
        $count = count($st['eff']);
        for ($i = 0; $i < $count; ++$i) {
            foreach ($st['ratings'] ? ['sale', 'stock', 'cheap', 'rated', 'best', 'new'] : ['sale', 'stock', 'cheap', 'best', 'new'] as $list) {
                $len = $this->phraseAt($st, $list, $i);
                if ($len === 0) {
                    continue;
                }
                $src = $this->take($st, $i, $len);
                if ($list === 'sale' || $list === 'stock') {
                    $st['flags'][$list] = isset($st['flags'][$list]) ? array_merge($st['flags'][$list], $src) : $src;
                } elseif ($st['sort'] === null) {
                    $st['sort'] = ['value' => ['cheap' => 'price_asc', 'rated' => 'rating', 'best' => 'best', 'new' => 'newest'][$list], 'src' => $src];
                } else {
                    $st['sort']['src'] = array_merge($st['sort']['src'], $src);
                }
                $i += $len - 1;
                break;
            }
        }
    }

    /**
     * «sin cuero»: lo que lleve esa palabra no sale.
     *
     * @param array<string,mixed> $st
     */
    private function without(array &$st): void
    {
        $count = count($st['eff']);
        for ($i = 0; $i < $count; ++$i) {
            $len = $this->phraseAt($st, 'without', $i);
            $next = $i + $len;
            if ($len === 0 || !$this->free($st, $next) || $st['eff'][$next]['n']) {
                continue;
            }
            $word = $st['eff'][$next]['w'];
            if ($this->search->analyzer($st['lang'])->tokens($word) === []) {
                continue;
            }
            $src = $this->take($st, $i, $len + 1);
            $surface = $st['orig'][$st['eff'][$next]['src'][0]]['s'];
            $st['without'][] = ['word' => $word, 'label' => mb_strtolower($surface, 'UTF-8'), 'src' => $src];
            $i = $next;
        }
    }

    /**
     * Valores de atributos, categorías y marcas por su nombre. Gana el nombre más largo; a igual largo, el que va antes y, entre
     * una categoría, una marca y un valor, en ese orden.
     *
     * @param array<string,mixed> $st
     */
    private function names(array &$st): void
    {
        $voc = $this->vocabulary->get($st['lang']);
        if ($voc['values'] === [] && $voc['categories'] === [] && $voc['brands'] === []) {
            return;
        }
        $analyzer = $this->search->analyzer($st['lang']);
        // Las raíces de las palabras libres, con la posición de cada una (las palabras vacías no cuentan).
        $stems = [];
        foreach ($st['eff'] as $i => $token) {
            if ($token['n'] || !$this->free($st, $i)) {
                continue;
            }
            foreach ($analyzer->tokens($token['w']) as $stem) {
                $stems[] = ['stem' => $stem, 'at' => $i];
            }
        }
        $total = count($stems);
        $taken = [];
        $found = [];
        for ($len = min((int) $voc['words'], $total); $len >= 1; --$len) {
            for ($p = 0; $p + $len <= $total; ++$p) {
                $overlap = false;
                for ($k = $p; $k < $p + $len; ++$k) {
                    if (isset($taken[$k])) {
                        $overlap = true;
                        break;
                    }
                }
                if ($overlap) {
                    continue;
                }
                $parts = [];
                for ($k = $p; $k < $p + $len; ++$k) {
                    $parts[] = $stems[$k]['stem'];
                }
                $key = implode(' ', $parts);
                $kind = isset($voc['categories'][$key]) ? 'category' : (isset($voc['brands'][$key]) ? 'brand' : (isset($voc['values'][$key]) ? 'value' : ''));
                if ($kind === '') {
                    continue;
                }
                // Todas las palabras de en medio (las vacías, «de») tienen que estar libres.
                $first = $stems[$p]['at'];
                $last = $stems[$p + $len - 1]['at'];
                $free = true;
                for ($j = $first; $j <= $last; ++$j) {
                    $free = $free && $this->free($st, $j) && !$st['eff'][$j]['n'];
                }
                if (!$free) {
                    continue;
                }
                for ($k = $p; $k < $p + $len; ++$k) {
                    $taken[$k] = true;
                }
                $entry = $kind === 'category' ? $voc['categories'][$key] : ($kind === 'brand' ? $voc['brands'][$key] : $voc['values'][$key]);
                $found[] = ['kind' => $kind, 'entry' => $entry, 'first' => $first, 'len' => $last - $first + 1, 'words' => $len];
            }
        }
        usort($found, static function (array $a, array $b): int {
            return $a['first'] <=> $b['first'];
        });

        // Categorías y marcas solo cuentan si la frase dice algo más (o nombra dos): «silla» a secas se busca como texto.
        $weak = [];
        $strong = 0;
        foreach ($found as $item) {
            if ($item['kind'] === 'value') {
                ++$strong;
            } else {
                $weak[] = $item;
            }
        }
        $others = $strong + count($st['numbers']) + count($st['without']) + ($st['price']['src'] !== [] ? 1 : 0);
        $kinds = [];
        foreach ($weak as $item) {
            $kinds[$item['kind']] = true;
        }
        $both = count($kinds) >= 2;
        $haveCategory = false;
        $haveBrand = false;
        foreach ($found as $item) {
            if ($item['kind'] !== 'value') {
                // Solo una categoría y una marca; las demás palabras quedan para el buscador.
                if (!($both || $others >= 2 || ($others >= 1 && $item['words'] >= 2)) || ($item['kind'] === 'category' && $haveCategory) || ($item['kind'] === 'brand' && $haveBrand)) {
                    continue;
                }
                $haveCategory = $haveCategory || $item['kind'] === 'category';
                $haveBrand = $haveBrand || $item['kind'] === 'brand';
            }
            $src = $this->take($st, $item['first'], $item['len']);
            if ($item['kind'] === 'value') {
                $code = (string) $item['entry']['attribute'];
                if (!isset($st['attrs'][$code])) {
                    $st['attrs'][$code] = ['name' => (string) $item['entry']['name'], 'values' => [], 'labels' => [], 'src' => []];
                }
                $st['attrs'][$code]['values'][] = (string) $item['entry']['value'];
                $st['attrs'][$code]['labels'][] = (string) $item['entry']['label'];
                $st['attrs'][$code]['src'] = array_merge($st['attrs'][$code]['src'], $src);
            } else {
                $st['weak'][] = ['kind' => $item['kind'], 'id' => (int) $item['entry']['id'], 'name' => (string) $item['entry']['name'], 'src' => $src];
            }
        }
    }

    // ------------------------------------------------------------------ el resultado

    /**
     * @param array<string,mixed>            $st
     * @param array<int,array<string,mixed>> $unused
     *
     * @return array<string,mixed>
     */
    private function finish(array $st, array $unused): array
    {
        $lang = $st['lang'];
        $filters = [];
        $catalog = ['filters' => [], 'category_id' => null, 'brand_id' => null, 'in_stock' => false, 'on_sale' => false, 'sort' => null, 'price_min' => null, 'price_max' => null];
        $restrict = ['numbers' => [], 'without' => []];

        if ($st['price']['src'] !== []) {
            $catalog['price_min'] = $st['price']['min'];
            $catalog['price_max'] = $st['price']['max'];
            $filters[] = ['key' => 'price', 'type' => 'price', 'label' => $this->priceLabel($st['price']['min'], $st['price']['max'], $lang),
                'min' => $st['price']['min'], 'max' => $st['price']['max'], 'src' => $st['price']['src']];
        }
        foreach ($st['numbers'] as $n) {
            $restrict['numbers'][] = ['code' => $n['code'], 'op' => $n['op'], 'value' => $n['value']];
            $filters[] = ['key' => 'number:' . $n['code'], 'type' => 'number', 'label' => $this->numberLabel($n, $lang), 'code' => $n['code'],
                'op' => $n['op'], 'value' => $n['value'], 'unit' => $n['unit'], 'src' => $n['src']];
        }
        foreach ($st['attrs'] as $code => $attr) {
            $catalog['filters'][$code] = array_values(array_unique($attr['values']));
            $filters[] = ['key' => 'attr:' . $code, 'type' => 'attribute', 'label' => $this->app->i18n()->t('{name}: {value}', ['name' => $attr['name'], 'value' => implode(', ', array_unique($attr['labels']))], $lang),
                'code' => $code, 'values' => $catalog['filters'][$code], 'src' => $attr['src']];
        }
        foreach ($st['weak'] as $w) {
            if ($w['kind'] === 'category' && $catalog['category_id'] === null) {
                $catalog['category_id'] = $w['id'];
                $filters[] = ['key' => 'category', 'type' => 'category', 'label' => $this->app->i18n()->t('Category: {name}', ['name' => $w['name']], $lang), 'id' => $w['id'], 'name' => $w['name'], 'src' => $w['src']];
            } elseif ($w['kind'] === 'brand' && $catalog['brand_id'] === null) {
                $catalog['brand_id'] = $w['id'];
                $filters[] = ['key' => 'brand', 'type' => 'brand', 'label' => $this->app->i18n()->t('Brand: {name}', ['name' => $w['name']], $lang), 'id' => $w['id'], 'name' => $w['name'], 'src' => $w['src']];
            }
        }
        if (isset($st['flags']['stock'])) {
            $catalog['in_stock'] = true;
            $filters[] = ['key' => 'stock', 'type' => 'stock', 'label' => $this->app->i18n()->t('In stock', [], $lang), 'src' => $st['flags']['stock']];
        }
        if (isset($st['flags']['sale'])) {
            $catalog['on_sale'] = true;
            $filters[] = ['key' => 'sale', 'type' => 'sale', 'label' => $this->app->i18n()->t('On sale', [], $lang), 'src' => $st['flags']['sale']];
        }
        if ($st['sort'] !== null) {
            $catalog['sort'] = $st['sort']['value'];
            $filters[] = ['key' => 'sort', 'type' => 'sort', 'label' => $this->sortLabel($st['sort']['value'], $lang), 'value' => $st['sort']['value'], 'src' => $st['sort']['src']];
        }
        foreach ($st['without'] as $w) {
            $restrict['without'][] = $w['word'];
            $filters[] = ['key' => 'without:' . $w['word'], 'type' => 'without', 'label' => $this->app->i18n()->t('Without: {word}', ['word' => $w['label']], $lang), 'word' => $w['word'], 'src' => $w['src']];
        }

        // Lo que queda de la frase, tal como se escribió (una referencia como «SIL-001» llega entera).
        $left = [];
        foreach ($st['orig'] as $i => $token) {
            if (!isset($st['used'][$i]) && $token['s'] !== '€') {
                $left[] = $token['s'];
            }
        }
        $text = implode(' ', $left);
        if ($this->search->analyzer($lang)->tokens($text) === [] && preg_match('/[\p{N}]/u', $text) !== 1) {
            $text = '';
        }
        if ($text === '' && $filters === []) {
            // Una frase que son solo palabras vacías no se entiende: se la dejamos al buscador tal cual.
            $text = $st['query'];
        }

        // De qué habla la frase (lo que no es precio, medida, orden ni existencias), tal como se escribió: sirve para la respuesta.
        $aside = [];
        foreach ($filters as $filter) {
            if (in_array($filter['type'], ['price', 'number', 'stock', 'sale', 'sort', 'without'], true)) {
                foreach ($filter['src'] as $s) {
                    $aside[$s] = true;
                }
            }
        }
        $subject = [];
        foreach ($st['orig'] as $i => $token) {
            if (!isset($aside[$i]) && $token['s'] !== '€') {
                $subject[] = mb_strtolower($token['s'], 'UTF-8');
            }
        }

        // La frase sin las palabras de cada ficha: es la dirección de su botón «quitar».
        foreach ($filters as $i => $filter) {
            $filters[$i]['remove_query'] = self::cutWords($st['query'], $st['orig'], $filter['src']);
        }

        return ['query' => $st['query'], 'text' => $text, 'subject' => implode(' ', $subject), 'filters' => $filters, 'catalog' => $catalog, 'restrict' => $restrict, 'words' => count($st['orig'])];
    }

    /**
     * La frase sin las palabras señaladas.
     *
     * @param array<int,array<string,mixed>> $orig
     * @param int[]                          $drop
     */
    private static function cutWords(string $query, array $orig, array $drop): string
    {
        $out = '';
        $cursor = 0;
        $skip = array_flip($drop);
        foreach ($orig as $i => $token) {
            if (!isset($skip[$i])) {
                continue;
            }
            $out .= substr($query, $cursor, (int) $token['o'] - $cursor);
            $cursor = (int) $token['o'] + (int) $token['l'];
        }
        $out .= substr($query, $cursor);

        return trim((string) preg_replace('/\s+/u', ' ', $out));
    }

    private function money(int $cents, string $lang): string
    {
        $text = $this->app->i18n()->money($cents, $this->app->settings()->str('shop.currency', 'EUR'), $lang);

        return (string) preg_replace('/[.,]00(?=\D|$)/u', '', $text);
    }

    private function priceLabel(?int $min, ?int $max, string $lang): string
    {
        $i18n = $this->app->i18n();
        if ($min !== null && $max !== null) {
            return $i18n->t('Price: between {min} and {max}', ['min' => $this->money($min, $lang), 'max' => $this->money($max, $lang)], $lang);
        }
        if ($max !== null) {
            return $i18n->t('Price: less than {price}', ['price' => $this->money($max, $lang)], $lang);
        }

        return $i18n->t('Price: more than {price}', ['price' => $this->money((int) $min, $lang)], $lang);
    }

    /**
     * @param array<string,mixed> $n
     */
    private function numberLabel(array $n, string $lang): string
    {
        $i18n = $this->app->i18n();
        $value = $i18n->number((float) $n['value'], (float) $n['value'] === floor((float) $n['value']) ? 0 : 2, $lang) . ' ' . $n['unit'];
        if ($n['op'] === '<=') {
            return $i18n->t('{name}: up to {value}', ['name' => $n['name'], 'value' => $value], $lang);
        }
        if ($n['op'] === '>=') {
            return $i18n->t('{name}: at least {value}', ['name' => $n['name'], 'value' => $value], $lang);
        }

        return $i18n->t('{name}: {value}', ['name' => $n['name'], 'value' => $value], $lang);
    }

    private function sortLabel(string $sort, string $lang): string
    {
        $i18n = $this->app->i18n();
        $names = [
            'price_asc' => $i18n->t('Price: low to high', [], $lang),
            'newest' => $i18n->t('Newest', [], $lang),
            'best' => $i18n->t('Best sellers', [], $lang),
            'rating' => $i18n->t('Best rated', [], $lang),
        ];

        return $i18n->t('Sorted by: {order}', ['order' => isset($names[$sort]) ? $names[$sort] : $sort], $lang);
    }
}
