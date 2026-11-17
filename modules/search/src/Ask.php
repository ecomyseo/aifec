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
use Aifec\Modules\Catalog\Texts;
use Aifec\Util\Str;

/**
 * El buscador que entiende: de una frase saca filtros (Understand, o la IA si está encendida y hace falta), busca con el
 * buscador de siempre lo que queda de texto, pide el listado al catálogo con esos filtros y compone la respuesta.
 *
 * Lo usan la página de resultados, /buscar.json, /buscar.md y el asistente (Search::ask()): los cuatro llaman a plan() y a run(),
 * así que dan siempre lo mismo. La página y las versiones para máquinas lo hacen con $live = false: la IA solo aporta lo que ya
 * tenga guardado y nunca se la espera; la que puede llamarla es /buscar/significado.json (o la página con «significado=1»).
 *
 * Las medidas, las exclusiones («sin cuero») y el orden por valoración no existen en el listado del catálogo: se resuelven aquí
 * con una lista de identificadores (como mucho MAX_IDS) que se le pasa al listado, que es quien filtra, ordena y pagina.
 */
final class Ask
{
    /** Identificadores que se le pasan al listado como mucho. */
    public const MAX_IDS = 400;

    /** ask(..., ['ai' => Ask::AI_CACHED]): reglas y las respuestas de la IA ya guardadas, sin llamarla. */
    public const AI_CACHED = 'cached';

    /** Productos que se enseñan como alternativa cuando no hay resultados. */
    private const SIMILAR = 4;

    /** Filtros que se prueban a quitar, como mucho, para proponer el que más estorba. */
    private const MAX_RELAX = 5;

    /** @var App */
    private $app;

    /** @var Search */
    private $search;

    /** @var Words */
    private $words;

    /** @var Vocabulary */
    private $vocabulary;

    /** @var Understand */
    private $understand;

    /** @var AiUnderstand */
    private $ai;

    /** @var Answers */
    private $answers;

    /** @var Meaning */
    private $meaning;

    /** @var array<int,bool> */
    private $ratings = [];

    public function __construct(App $app, Search $search)
    {
        $this->app = $app;
        $this->search = $search;
        $this->words = new Words($app);
        $this->vocabulary = new Vocabulary($app, $search);
        $this->understand = new Understand($app, $search, $this->words, $this->vocabulary);
        $this->ai = new AiUnderstand($app);
        $this->answers = new Answers($app, $this->words);
        $this->meaning = new Meaning($app, $search, $this->ai, $this->vocabulary);
    }

    public function meaning(): Meaning
    {
        return $this->meaning;
    }

    public function vocabulary(): Vocabulary
    {
        return $this->vocabulary;
    }

    public function answers(): Answers
    {
        return $this->answers;
    }

    public function ai(): AiUnderstand
    {
        return $this->ai;
    }

    // ------------------------------------------------------------------ entender

    /**
     * Lo que se entiende de una frase: filtros, texto que queda y lo que hay que pedirle al catálogo. La IA solo entra si las
     * reglas no han bastado (cinco palabras o más y algo sin entender) y está encendida. Con $live = false solo vale su respuesta
     * guardada de otra vez (la página de resultados no espera nunca a la IA).
     *
     * @return array<string,mixed>
     */
    public function plan(string $query, string $lang, bool $allowAi = true, bool $live = true): array
    {
        $plan = $this->understand->run($query, $lang, $this->hasRatings());
        $plan['lang'] = $lang;
        $plan['ai'] = false;
        if ($allowAi && $plan['words'] >= 5 && $plan['text'] !== '') {
            $plan = $this->withAi($plan, $live);
        }

        // La memoria solo amplía el texto residual: precio, medidas, exclusiones y demás condiciones siguen siendo los originales.
        $learned = $this->app->hooks()->apply('search.plan.text', (string) $plan['text'], $plan);
        if (is_string($learned) && preg_match('//u', $learned) === 1) {
            $learned = trim(Analyzer::clean($learned));
            if ($learned !== '' && mb_strlen($learned, 'UTF-8') <= 200) {
                $plan['text'] = $learned;
            }
        }

        return $plan;
    }

    /**
     * Suma a lo que sacaron las reglas lo que la IA ve en la frase (solo lo que las reglas no habían puesto).
     *
     * @param array<string,mixed> $plan
     *
     * @return array<string,mixed>
     */
    public function withAi(array $plan, bool $live = true): array
    {
        $voc = $this->vocabulary->get($plan['lang']);
        $found = $this->ai->filters((string) $plan['query'], (string) $plan['lang'], $voc, $live);
        if ($found === null) {
            return $plan;
        }
        $i18n = $this->app->i18n();
        $lang = (string) $plan['lang'];
        $added = [];
        $c = $plan['catalog'];
        $money = function (int $cents) use ($i18n, $lang): string {
            return (string) preg_replace('/[.,]00(?=\D|$)/u', '', $i18n->money($cents, $this->app->settings()->str('shop.currency', 'EUR'), $lang));
        };
        if ($c['price_min'] === null && $c['price_max'] === null && ($found['price_min'] !== null || $found['price_max'] !== null)) {
            $c['price_min'] = $found['price_min'];
            $c['price_max'] = $found['price_max'];
            if ($found['price_min'] !== null && $found['price_max'] !== null) {
                $label = $i18n->t('Price: between {min} and {max}', ['min' => $money((int) $found['price_min']), 'max' => $money((int) $found['price_max'])], $lang);
            } elseif ($found['price_max'] !== null) {
                $label = $i18n->t('Price: less than {price}', ['price' => $money((int) $found['price_max'])], $lang);
            } else {
                $label = $i18n->t('Price: more than {price}', ['price' => $money((int) $found['price_min'])], $lang);
            }
            $added[] = ['key' => 'price', 'type' => 'price', 'label' => $label, 'min' => $found['price_min'], 'max' => $found['price_max']];
        }
        foreach ($found['attrs'] as $code => $values) {
            if (isset($c['filters'][$code])) {
                continue;
            }
            $labels = [];
            $name = $code;
            foreach ($voc['values'] as $entry) {
                if ($entry['attribute'] === $code && in_array($entry['value'], $values, true)) {
                    $labels[] = $entry['label'];
                    $name = $entry['name'];
                }
            }
            $c['filters'][$code] = $values;
            $added[] = ['key' => 'attr:' . $code, 'type' => 'attribute', 'label' => $i18n->t('{name}: {value}', ['name' => $name, 'value' => implode(', ', $labels)], $lang), 'code' => $code, 'values' => $values];
        }
        if ($c['category_id'] === null && $found['category_id'] !== null) {
            $c['category_id'] = $found['category_id'];
            foreach ($voc['categories'] as $entry) {
                if ((int) $entry['id'] === (int) $found['category_id']) {
                    $added[] = ['key' => 'category', 'type' => 'category', 'label' => $i18n->t('Category: {name}', ['name' => $entry['name']], $lang), 'id' => $entry['id'], 'name' => $entry['name']];
                }
            }
        }
        if ($c['brand_id'] === null && $found['brand_id'] !== null) {
            $c['brand_id'] = $found['brand_id'];
            foreach ($voc['brands'] as $entry) {
                if ((int) $entry['id'] === (int) $found['brand_id']) {
                    $added[] = ['key' => 'brand', 'type' => 'brand', 'label' => $i18n->t('Brand: {name}', ['name' => $entry['name']], $lang), 'id' => $entry['id'], 'name' => $entry['name']];
                }
            }
        }
        if (!$c['in_stock'] && $found['in_stock']) {
            $c['in_stock'] = true;
            $added[] = ['key' => 'stock', 'type' => 'stock', 'label' => $i18n->t('In stock', [], $lang)];
        }
        if (!$c['on_sale'] && $found['on_sale']) {
            $c['on_sale'] = true;
            $added[] = ['key' => 'sale', 'type' => 'sale', 'label' => $i18n->t('On sale', [], $lang)];
        }
        if ($c['sort'] === null && $found['sort'] !== null && ($found['sort'] !== 'rating' || $this->hasRatings())) {
            $c['sort'] = $found['sort'];
            $names = ['price_asc' => $i18n->t('Price: low to high', [], $lang), 'newest' => $i18n->t('Newest', [], $lang), 'best' => $i18n->t('Best sellers', [], $lang), 'rating' => $i18n->t('Best rated', [], $lang)];
            $added[] = ['key' => 'sort', 'type' => 'sort', 'label' => $i18n->t('Sorted by: {order}', ['order' => $names[$found['sort']]], $lang), 'value' => $found['sort']];
        }
        // Lo que no quiere («que no sea blanca»): se excluye por la palabra del valor, igual que «sin cuero» de las reglas.
        foreach (isset($found['exclude']) && is_array($found['exclude']) ? $found['exclude'] : [] as $pair) {
            $word = Words::fold((string) $pair['label']);
            if ($word === '' || $this->alreadyWithout($plan, $word, $lang)) {
                continue;
            }
            $plan['restrict']['without'][] = $word;
            $added[] = ['key' => 'without:' . $word, 'type' => 'without', 'label' => $i18n->t('Without: {word}', ['word' => mb_strtolower((string) $pair['label'], 'UTF-8')], $lang), 'word' => $word];
        }
        if ($added === []) {
            return $plan;
        }
        foreach ($added as $i => $filter) {
            $added[$i]['ai'] = true;
            $added[$i]['src'] = [];
            $added[$i]['remove_query'] = (string) $plan['query'];
        }
        $plan['catalog'] = $c;
        $plan['filters'] = array_merge($plan['filters'], $added);
        $plan['text'] = $found['text'];
        $plan['ai'] = true;

        return $plan;
    }

    /**
     * ¿Ya se excluye esa palabra (o una con la misma raíz, «blanca» y «blanco»)?
     *
     * @param array<string,mixed> $plan
     */
    private function alreadyWithout(array $plan, string $word, string $lang): bool
    {
        $analyzer = $this->search->analyzer($lang);
        $stems = $analyzer->tokens($word);
        foreach ($plan['restrict']['without'] as $have) {
            if ((string) $have === $word || ($stems !== [] && $analyzer->tokens((string) $have) === $stems)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Hay alguna valoración en la tienda? («mejor valorados» solo se entiende si sí.) */
    private function hasRatings(): bool
    {
        $key = $this->app->cache()->tagVersion('catalog');
        if (!isset($this->ratings[$key])) {
            $cached = $this->app->cache()->get('search:ratings');
            if (!is_array($cached) || !isset($cached['on'])) {
                $cached = ['on' => (int) $this->app->db()->value('SELECT COUNT(*) FROM {products} WHERE rating_count > 0') > 0];
                $this->app->cache()->set('search:ratings', $cached, 600, ['search', 'catalog']);
            }
            $this->ratings = [$key => (bool) $cached['on']];
        }

        return $this->ratings[$key];
    }

    // ------------------------------------------------------------------ pedirle al catálogo

    /**
     * Lo que se le pide a catalog->listing(): lo entendido más lo que eligió el visitante con los filtros de la página (que manda).
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $visitor lo de ListingQuery::toCatalog() (o per_page y page)
     *
     * @return array<string,mixed>
     */
    public function catalogQuery(array $plan, array $visitor = []): array
    {
        $c = $plan['catalog'];
        $q = ['lang' => $plan['lang']];

        $filters = $c['filters'];
        foreach (isset($visitor['filters']) && is_array($visitor['filters']) ? $visitor['filters'] : [] as $code => $values) {
            $filters[$code] = $values;
        }
        $q['filters'] = $filters;
        $brand = isset($visitor['brand_id']) ? (int) $visitor['brand_id'] : 0;
        $brand = $brand > 0 ? $brand : (int) $c['brand_id'];
        if ($brand > 0) {
            $q['brand_id'] = $brand;
        }
        if ($c['category_id'] !== null) {
            $q['category_id'] = (int) $c['category_id'];
        }
        if ($c['in_stock'] || !empty($visitor['in_stock'])) {
            $q['in_stock'] = true;
        }
        if ($c['on_sale'] || !empty($visitor['on_sale'])) {
            $q['on_sale'] = true;
        }
        if (isset($visitor['price_min']) || isset($visitor['price_max'])) {
            foreach (['price_min', 'price_max'] as $key) {
                if (isset($visitor[$key])) {
                    $q[$key] = (int) $visitor[$key];
                }
            }
        } else {
            if ($c['price_min'] !== null) {
                $q['price_min'] = $this->written((int) $c['price_min']);
            }
            if ($c['price_max'] !== null) {
                $q['price_max'] = $this->written((int) $c['price_max']);
            }
        }
        $sort = isset($visitor['sort']) && is_string($visitor['sort']) ? $visitor['sort'] : 'relevance';
        if ($sort === 'relevance' && $c['sort'] !== null) {
            $sort = $c['sort'] === 'rating' ? 'relevance' : $c['sort'];
        }
        $q['sort'] = $sort;
        if (isset($visitor['page'])) {
            $q['page'] = (int) $visitor['page'];
        }
        if (isset($visitor['per_page'])) {
            $q['per_page'] = (int) $visitor['per_page'];
        }
        if ($this->ratingSort($plan, $visitor)) {
            $q['per_page'] = 96;
            $q['page'] = 1;
        }
        if ($plan['text'] !== '') {
            $q['q'] = $plan['text'];
        }
        $ids = $this->restrict($plan);
        if ($ids !== null) {
            $q['ids'] = $ids;
        }

        return $q;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $visitor
     */
    private function ratingSort(array $plan, array $visitor): bool
    {
        $sort = isset($visitor['sort']) && is_string($visitor['sort']) ? $visitor['sort'] : 'relevance';

        return $sort === 'relevance' && $plan['catalog']['sort'] === 'rating';
    }

    /**
     * Un precio tal como lo ve el comprador, en céntimos, convertido al modo en que la tienda guarda los precios (con o sin
     * impuestos). El listado compara contra lo guardado. Se usa el tipo de la clase de impuestos por defecto: con varios tipos el
     * corte puede variar unos céntimos en los productos de otra clase.
     */
    public function written(int $shown): int
    {
        try {
            $tax = $this->app->service('tax');
            if (!is_callable([$tax, 'pricesIncludeTax']) || !is_callable([$tax, 'displayWithTax'])) {
                return $shown;
            }
            $included = (bool) $tax->pricesIncludeTax();
            $withTax = (bool) $tax->displayWithTax();
            if ($included === $withTax) {
                return $shown;
            }
            $rate = $tax->rate($tax->defaultClassId(), $tax->baseDestination());
            $value = (int) (isset($rate['rate']) ? $rate['rate'] : 0);
            if ($included) {
                // Escrito con impuestos y enseñado sin ellos: lo escrito es la base más la cuota.
                $parts = \Aifec\Commerce\Money::add($shown, $value);

                return (int) $parts[1];
            }
            // Escrito sin impuestos y enseñado con ellos: lo escrito es la base.
            $parts = \Aifec\Commerce\Money::split($shown, $value);

            return (int) $parts[0];
        } catch (\Throwable $e) {
            return $shown;
        }
    }

    /**
     * La lista de productos que cumplen lo que el listado no sabe filtrar (medidas y exclusiones), o null si no hay nada de eso.
     *
     * @param array<string,mixed> $plan
     *
     * @return int[]|null
     */
    private function restrict(array $plan): ?array
    {
        $numbers = $plan['restrict']['numbers'];
        $without = $plan['restrict']['without'];
        if ($numbers === [] && $without === []) {
            return null;
        }
        $db = $this->app->db();
        $ids = null;
        foreach ($numbers as $n) {
            $op = in_array($n['op'], ['<=', '>=', '='], true) ? $n['op'] : '=';
            $rows = $db->column(
                'SELECT DISTINCT pa.product_id FROM {product_attributes} pa JOIN {attributes} a ON a.id = pa.attribute_id WHERE a.code = ? AND pa.value_number ' . $op . ' ? ORDER BY pa.product_id LIMIT ' . self::MAX_IDS,
                [(string) $n['code'], (float) $n['value']]
            );
            $set = array_map('intval', $rows);
            $ids = $ids === null ? $set : array_values(array_intersect($ids, $set));
        }
        if ($without !== []) {
            if ($ids === null) {
                if ($plan['text'] !== '') {
                    $found = $this->search->query($plan['text'], ['lang' => $plan['lang'], 'limit' => 200]);
                    $ids = array_map('intval', $found['ids']);
                } else {
                    $ids = array_map('intval', $db->column("SELECT id FROM {products} WHERE status = 'published' ORDER BY id LIMIT " . self::MAX_IDS));
                }
            }
            $out = [];
            foreach ($without as $word) {
                $hit = $this->search->query((string) $word, ['lang' => $plan['lang'], 'limit' => 500]);
                foreach ($hit['ids'] as $id) {
                    $out[(int) $id] = true;
                }
            }
            $ids = array_values(array_filter($ids, static function (int $id) use ($out): bool {
                return !isset($out[$id]);
            }));
        }

        return $ids === null ? null : array_slice($ids, 0, self::MAX_IDS);
    }

    // ------------------------------------------------------------------ buscar

    /**
     * Hace la búsqueda: el texto con el buscador y el resto con el listado del catálogo. Si no sale nada y la IA está encendida,
     * se la prueba una vez; si sigue sin salir, se propone quitar el filtro que más estorba. Con $live = false la IA solo
     * contesta con lo que ya tenga guardado (ver plan()).
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $visitor
     *
     * @return array{plan:array<string,mixed>,listing:?array<string,mixed>,text:?array<string,mixed>,found:bool,relaxed:?array<string,mixed>,related:?array<string,mixed>,dropped:string}
     */
    public function run(array $plan, array $visitor = [], bool $allowAi = true, bool $live = true): array
    {
        $result = $this->execute($plan, $visitor);
        if (!$result['found'] && $allowAi && !$plan['ai'] && $plan['query'] !== '') {
            $withAi = $this->withAi($plan, $live);
            if ($withAi['ai']) {
                $plan = $withAi;
                $result = $this->execute($plan, $visitor);
            }
        }
        if (!$result['found'] && $plan['text'] !== '' && $this->hasSubject($plan)) {
            // Las palabras encuentran algo, pero nada dentro de lo entendido («jamón para regalar por menos de 200 €»: «regalar»
            // solo sale en uno de 400 €). Antes de proponer quitar un filtro se prueba con los filtros solos: la categoría, el
            // color o el precio dicen más de lo que se busca que una palabra suelta.
            $alone = $plan;
            $alone['text'] = '';
            $only = $this->execute($alone, $visitor, false);
            if ($only['found']) {
                $only['dropped'] = (string) $plan['text'];
                $only['text'] = $result['text'];
                $result = $only;
            }
        }
        if ($result['dropped'] !== '') {
            // El texto no encontró nada y se listó solo con los filtros: la frase que queda es la de los filtros.
            $plan['dropped'] = true;
            $plan['text'] = '';
        }
        $result['plan'] = $plan;
        $result['relaxed'] = null;
        $result['related'] = null;
        if (!$result['found']) {
            $result['relaxed'] = $this->relax($plan, $visitor);
            if ($result['relaxed'] === null && $allowAi) {
                $result['related'] = $this->related($plan, $live);
            }
        }

        return $result;
    }

    /**
     * Lo que se ofrece por significado cuando nada encaja y no hay propuesta que hacer: productos aparte, nunca mezclados con los
     * exactos. Los filtros que se entendieron (categoría, precio, exclusiones…) se siguen respetando.
     *
     * @param array<string,mixed> $plan
     *
     * @return array{items:array<int,array<string,mixed>>,via:string}|null
     */
    private function related(array $plan, bool $live): ?array
    {
        if ((string) $plan['query'] === '') {
            return null;
        }
        $found = $this->meaning->find((string) $plan['query'], (string) $plan['lang'], $live);
        if ($found['ids'] === []) {
            return null;
        }
        $bare = $plan;
        $bare['text'] = '';
        $query = $this->catalogQuery($bare, ['per_page' => Meaning::MAX_RELATED, 'page' => 1]);
        $ids = $found['ids'];
        if (isset($query['ids'])) {
            $ids = array_values(array_intersect($ids, array_map('intval', $query['ids'])));
        }
        if ($ids === []) {
            return null;
        }
        $query['ids'] = $ids;
        $query['sort'] = 'relevance';
        $query['per_page'] = Meaning::MAX_RELATED;
        $query['page'] = 1;
        $listing = $this->app->service('catalog')->listing($query);
        // El listado no conserva el orden de los identificadores: se vuelve a poner el de parecido.
        $position = array_flip($ids);
        $items = $listing['items'];
        usort($items, static function (array $a, array $b) use ($position): int {
            return $position[(int) $a['id']] <=> $position[(int) $b['id']];
        });
        if ($items === []) {
            return null;
        }

        return ['items' => array_slice($items, 0, Meaning::MAX_RELATED), 'via' => $found['via']];
    }

    /**
     * Cómo acabó una búsqueda, para el registro: exact, relaxed, related, none o shop (una pregunta sobre la tienda).
     *
     * @param array<string,mixed>      $run  lo que devuelve run()
     * @param array<string,mixed>|null $shop la respuesta de la tienda, si la frase era una pregunta
     */
    public function outcome(array $run, ?array $shop): string
    {
        if ($shop !== null) {
            return 'shop';
        }
        if ($run['found']) {
            return 'exact';
        }
        if ($run['relaxed'] !== null) {
            return 'relaxed';
        }

        return $run['related'] !== null ? 'related' : 'none';
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $visitor
     *
     * @return array{plan:array<string,mixed>,listing:?array<string,mixed>,text:?array<string,mixed>,found:bool,relaxed:?array<string,mixed>,related:?array<string,mixed>,dropped:string}
     */
    private function execute(array $plan, array $visitor, bool $dropText = true): array
    {
        $out = ['plan' => $plan, 'listing' => null, 'text' => null, 'found' => false, 'relaxed' => null, 'related' => null, 'dropped' => ''];
        $dropped = '';
        if ($plan['text'] !== '') {
            $text = $this->search->query($plan['text'], ['lang' => $plan['lang'], 'limit' => 200]);
            $out['text'] = $text;
            // Si la frase traía dos palabras con significado y el buscador solo casó quitando una («limpiar la plata» → cosas que
            // «limpian» o cosas «plata»), eso no contesta a lo que se pidió: cuenta como no encontrado, y lo que venga después
            // (los filtros solos, la IA, lo relacionado) lo dirá como lo que es.
            $half = !empty($text['relaxed']) && isset($text['terms']) && (int) $text['terms'] <= 2;
            if (!$text['confident'] || (int) $text['total'] < 1 || $half) {
                // Las palabras no dan nada fiable: si lo demás de la frase sí dice de qué se habla (categoría, color, medida…), se
                // lista solo con eso, en vez de dar un «no hay nada» que no es verdad.
                if (!$dropText || !$this->hasSubject($plan)) {
                    return $out;
                }
                $dropped = (string) $plan['text'];
                $plan['text'] = '';
            }
        }
        $catalog = $this->app->service('catalog');
        $query = $this->catalogQuery($plan, $visitor);
        $listing = $catalog->listing($query);
        if ($this->ratingSort($plan, $visitor)) {
            $items = $listing['items'];
            usort($items, static function (array $a, array $b): int {
                $x = isset($a['rating']['average']) ? (float) $a['rating']['average'] : -1.0;
                $y = isset($b['rating']['average']) ? (float) $b['rating']['average'] : -1.0;

                return $y <=> $x;
            });
            $per = isset($visitor['per_page']) && (int) $visitor['per_page'] > 0 ? (int) $visitor['per_page'] : $this->app->settings()->int('catalog.per_page', 24);
            $listing['items'] = array_slice($items, 0, max(1, min(96, $per)));
            $listing['pages'] = 1;
            $listing['page'] = 1;
        }
        $out['listing'] = $listing;
        $out['found'] = (int) $listing['total'] > 0;
        if ($out['found']) {
            $out['dropped'] = $dropped;
        }

        return $out;
    }

    /**
     * ¿La frase entendida dice de qué se habla (una categoría, una marca, un atributo o una medida)? Solo un precio, un orden o una
     * exclusión no bastan para listar sin el texto: saldría el catálogo entero.
     *
     * @param array<string,mixed> $plan
     */
    private function hasSubject(array $plan): bool
    {
        $c = $plan['catalog'];

        return $c['category_id'] !== null || $c['brand_id'] !== null || $c['filters'] !== [] || $plan['restrict']['numbers'] !== [];
    }

    /**
     * Sin resultados: el filtro de los entendidos que, quitado, deja más productos, y los más parecidos que salen sin él.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $visitor
     *
     * @return array<string,mixed>|null ['key','label','remove_query','total','items']
     */
    private function relax(array $plan, array $visitor): ?array
    {
        $best = null;
        $tried = 0;
        foreach ($plan['filters'] as $filter) {
            if ($filter['type'] === 'sort' || !empty($filter['ai']) || $filter['remove_query'] === '' || $tried >= self::MAX_RELAX) {
                continue;
            }
            ++$tried;
            $other = $this->understand->run((string) $filter['remove_query'], (string) $plan['lang'], $this->hasRatings());
            $other['lang'] = $plan['lang'];
            $other['ai'] = false;
            // Sin «dejar el texto»: quitar un filtro no es dar por buena una frase cuyas palabras no aparecen en ningún sitio.
            $result = $this->execute($other, ['per_page' => self::SIMILAR], false);
            if ($result['found'] && ($best === null || (int) $result['listing']['total'] > $best['total'])) {
                $best = [
                    'key' => (string) $filter['key'], 'label' => (string) $filter['label'], 'remove_query' => (string) $filter['remove_query'],
                    'total' => (int) $result['listing']['total'], 'items' => array_slice($result['listing']['items'], 0, self::SIMILAR),
                ];
            }
        }

        return $best;
    }

    // ------------------------------------------------------------------ para el asistente y las máquinas

    /**
     * Search::ask(): la frase entendida, los productos, la respuesta y el total. $options: lang, limit (el listado por defecto
     * del catálogo; como mucho 48), ai (false = solo reglas; 'cached' = reglas y las respuestas de la IA ya guardadas, sin
     * llamarla; true o sin poner = puede llamarla), log (true = apunta la búsqueda en el registro).
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function ask(string $query, array $options = []): array
    {
        $lang = $this->search->lang(isset($options['lang']) && is_string($options['lang']) ? $options['lang'] : null);
        $default = max(1, $this->app->settings()->int('catalog.per_page', 24));
        $limit = isset($options['limit']) ? max(1, min(48, (int) $options['limit'])) : min(48, $default);
        $mode = array_key_exists('ai', $options) ? $options['ai'] : true;
        $allowAi = $mode !== false;
        $live = $mode !== self::AI_CACHED;
        $query = trim($query);

        $shop = $query !== '' ? $this->answers->shop($query, $lang) : null;
        // Una pregunta sobre la tienda («¿cuánto tarda el envío?») se contesta con los datos de la tienda: no se gasta IA en ella.
        $allowAi = $allowAi && $shop === null;
        $plan = $this->plan($query, $lang, $allowAi, $live);
        $run = $query === ''
            ? ['plan' => $plan, 'listing' => null, 'text' => null, 'found' => false, 'relaxed' => null, 'related' => null, 'dropped' => '']
            : $this->run($plan, ['per_page' => $limit, 'page' => 1], $allowAi, $live);
        $plan = $run['plan'];
        $listing = $run['listing'];
        $total = $run['found'] ? (int) $listing['total'] : 0;
        $items = $run['found'] ? $listing['items'] : [];
        $outcome = $this->outcome($run, $shop);
        if (!empty($options['log']) && $query !== '') {
            $this->search->log()->record($query, $lang, $total, !$run['found'], $outcome);
            $this->search->log()->recordFilters($lang, $this->kinds($plan));
        }

        $answer = '';
        $kind = 'none';
        if ($shop !== null) {
            $answer = $shop['text'];
            $kind = 'shop';
        } elseif ($query !== '') {
            if ($run['found']) {
                $answer = $this->answers->count($plan, $total, $lang);
                $kind = 'exact';
            } elseif ($run['relaxed'] !== null) {
                $answer = $this->answers->relaxed($plan, $run['relaxed'], $lang);
                $kind = 'relaxed';
            } elseif ($run['related'] !== null) {
                $answer = $this->answers->related($lang);
                $kind = 'related';
            } else {
                $answer = $this->app->i18n()->t('We have not found anything that matches {q}', ['q' => '«' . $query . '»'], $lang);
            }
        }

        return [
            'query' => $query,
            'lang' => $lang,
            'understood' => [
                'filters' => self::publicFilters($plan['filters']),
                'text' => (string) $plan['text'],
                'corrected' => $run['text'] !== null && $run['text']['corrected'] !== null ? (string) $run['text']['corrected'] : null,
                'ai' => (bool) $plan['ai'],
            ],
            'products' => $this->products($items, $plan, $lang),
            'answer' => $answer,
            'shop' => $shop,
            'total' => $total,
            'relaxed' => $run['relaxed'] !== null ? [
                'key' => $run['relaxed']['key'], 'label' => $run['relaxed']['label'], 'remove_query' => $run['relaxed']['remove_query'],
                'total' => $run['relaxed']['total'], 'products' => $this->products($run['relaxed']['items'], $plan, $lang),
            ] : null,
            // Lo que sale por significado va aparte: nunca entra en «products» ni en «total».
            'related' => $run['related'] !== null ? $this->products($run['related']['items'], $plan, $lang) : [],
            'answer_kind' => $kind,
        ];
    }

    /**
     * Los tipos de filtro entendidos en una frase (para el panel): sin repetirse, y «ai» si la IA intervino.
     *
     * @param array<string,mixed> $plan
     *
     * @return string[]
     */
    public function kinds(array $plan): array
    {
        $kinds = [];
        foreach ($plan['filters'] as $filter) {
            $kinds[(string) $filter['type']] = (string) $filter['type'];
        }
        if (!empty($plan['ai'])) {
            $kinds['ai'] = 'ai';
        }

        return array_values($kinds);
    }

    /**
     * @param array<int,array<string,mixed>> $filters
     *
     * @return array<int,array<string,mixed>>
     */
    public static function publicFilters(array $filters): array
    {
        $out = [];
        foreach ($filters as $filter) {
            unset($filter['src']);
            $out[] = $filter;
        }

        return $out;
    }

    /**
     * La tarjeta reducida de cada producto: id, nombre, precio (céntimos y con formato), disponibilidad, dirección completa, imagen
     * completa (o null), un resumen de hasta 200 caracteres y los datos clave que casan con lo pedido (el color que se pidió, la
     * medida…).
     *
     * @param array<int,array<string,mixed>> $cards
     * @param array<string,mixed>            $plan
     *
     * @return array<int,array<string,mixed>>
     */
    public function products(array $cards, array $plan, string $lang): array
    {
        if ($cards === []) {
            return [];
        }
        $wanted = [];
        foreach ($plan['filters'] as $filter) {
            if ($filter['type'] === 'attribute' || $filter['type'] === 'number') {
                $wanted[(string) $filter['code']] = true;
            }
        }
        $ids = [];
        foreach ($cards as $card) {
            $ids[] = (int) $card['id'];
        }
        // La ficha entera da el resumen y la dirección completa; las tarjetas del listado no los traen.
        $full = $this->app->service('catalog')->products($ids, $lang);
        $full = is_array($full) ? $full : [];
        $i18n = $this->app->i18n();
        $origin = $this->app->url()->origin();
        $out = [];
        foreach ($cards as $card) {
            $id = (int) $card['id'];
            $price = $card['price'];
            $matches = [];
            if ($wanted !== [] && isset($full[$id]['attributes'])) {
                foreach ($full[$id]['attributes'] as $attribute) {
                    if (isset($wanted[(string) $attribute['code']])) {
                        $matches[] = ['name' => (string) $attribute['name'], 'value' => (string) $attribute['value']];
                    }
                }
            }
            $status = (string) $card['availability']['status'];
            $out[] = [
                'id' => $id,
                'name' => (string) $card['name'],
                'price' => (int) $price['amount'],
                'price_formatted' => $i18n->money((int) $price['amount'], (string) $price['currency'], $lang),
                'currency' => (string) $price['currency'],
                'on_sale' => !empty($price['on_sale']),
                'available' => $status !== 'out_of_stock',
                'availability' => ['status' => $status, 'label' => (string) $card['availability']['label']],
                'url' => isset($full[$id]['url_abs']) ? (string) $full[$id]['url_abs'] : self::absolute($origin, (string) $card['url']),
                'image' => isset($card['image']['card']) ? self::absolute($origin, (string) $card['image']['card'])
                    : (isset($card['image']['url']) ? self::absolute($origin, (string) $card['image']['url']) : null),
                'summary' => isset($full[$id]) ? self::summary($full[$id]) : '',
                'matches' => $matches,
            ];
        }

        return $out;
    }

    /** Una dirección con protocolo y dominio: la que ya los lleva se deja y la que empieza por «/» se completa. */
    private static function absolute(string $origin, string $url): string
    {
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1) {
            return $url;
        }

        return $origin . ($url[0] === '/' ? '' : '/') . $url;
    }

    /**
     * El resumen del producto (o, si no tiene, el principio de su descripción) en texto llano y de 200 caracteres como mucho.
     *
     * @param array<string,mixed> $product
     */
    private static function summary(array $product): string
    {
        $text = Texts::plain(isset($product['summary']) ? (string) $product['summary'] : '');
        if ($text === '') {
            $text = Texts::plain(isset($product['description']) ? (string) $product['description'] : '');
        }

        return Str::limit($text, 199);
    }
}
