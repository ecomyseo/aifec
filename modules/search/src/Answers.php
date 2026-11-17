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

use Aifec\Geo\Countries;
use Aifec\Geo\Spain;
use Aifec\Kernel\App;

/**
 * La respuesta directa que va encima de los resultados. Se compone por plantilla, nunca por IA:
 *
 *   count()   qué se ha entendido y cuántos hay («12 resultados para silla de oficina negra por menos de 200 €»);
 *   shop()    si la frase es una pregunta sobre la tienda («¿enviáis a Canarias?», «devoluciones», «formas de pago»), la
 *             respuesta sale de los ajustes de la tienda, de sus servicios y de sus páginas, con el enlace a la página;
 *   Los datos son los de la tienda: nada de lo que dice se inventa, y si la tienda no tiene el dato no hay respuesta.
 */
final class Answers
{
    private const TOPICS = ['shipping', 'returns', 'payment', 'contact'];

    /** Un código postal de ejemplo de cada zona, para preguntarle al servicio de envíos si llega. */
    private const ZONE_POSTCODE = ['canarias' => '35001', 'baleares' => '07001', 'ceuta' => '51001', 'melilla' => '52001', 'peninsula' => '28001'];

    /** @var App */
    private $app;

    /** @var Words */
    private $words;

    public function __construct(App $app, Words $words)
    {
        $this->app = $app;
        $this->words = $words;
    }

    // ------------------------------------------------------------------ cuántos hay

    /**
     * @param array<string,mixed> $plan
     */
    public function count(array $plan, int $total, string $lang, array $fixes = []): string
    {
        $i18n = $this->app->i18n();
        $what = $this->describe($plan, $lang);
        // Con una errata corregida, la frase dice lo que se ha buscado de verdad, como el título (13/11/2026: «Resultados para
        // zapatilla» encima de «9 resultados para zapatila»). $fixes: palabra escrita => palabra corregida.
        foreach ($fixes as $typed => $shown) {
            if ($what !== '' && (string) $typed !== '' && (string) $shown !== '') {
                $what = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote((string) $typed, '/') . '(?![\p{L}\p{N}])/iu', (string) $shown, $what);
            }
        }
        if ($what === '') {
            return $i18n->tn('{n} product', '{n} products', $total, ['n' => $total], $lang);
        }

        return $i18n->tn('{n} result for {what}', '{n} results for {what}', $total, ['n' => $total, 'what' => $what], $lang);
    }

    /**
     * Sin resultados exactos pero con una propuesta: dice qué se buscaba, qué filtro sobra y cuántos hay sin él.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $relaxed ['label', 'total', …] el de Ask::run()
     */
    public function relaxed(array $plan, array $relaxed, string $lang): string
    {
        $what = $this->describe($plan, $lang);
        if ($what === '') {
            $what = (string) $plan['query'];
        }
        $n = (int) $relaxed['total'];

        return $this->app->i18n()->tn(
            'There are no results for {what}. Without «{filter}» there is {n}.',
            'There are no results for {what}. Without «{filter}» there are {n}.',
            $n,
            ['what' => $what, 'filter' => (string) $relaxed['label'], 'n' => $n],
            $lang
        );
    }

    /** La frase que va delante de los productos que salen por significado y no por las palabras escritas. */
    public function related(string $lang): string
    {
        return $this->app->i18n()->t('We have nothing with those words. This may help you:', [], $lang);
    }

    /** Lo que se buscaba, dicho en pocas palabras: «sillas de oficina negras por menos de 200 €». Vacío si la frase no dice nada. */
    public function describe(array $plan, string $lang): string
    {
        $i18n = $this->app->i18n();
        $parts = [];
        $subject = isset($plan['subject']) ? trim((string) $plan['subject']) : '';
        if (!empty($plan['dropped'])) {
            // El texto no encontró nada y se buscó solo con los filtros: se habla de lo que sí se filtró.
            $subject = '';
            $attributes = [];
            foreach ($plan['filters'] as $filter) {
                if ($filter['type'] === 'category') {
                    $parts[] = mb_strtolower((string) $filter['name'], 'UTF-8');
                } elseif ($filter['type'] === 'attribute') {
                    // La ficha dice «Color: Negro»: «con color negro».
                    $pair = explode(': ', (string) $filter['label'], 2);
                    $attributes[] = count($pair) === 2
                        ? $i18n->t('with {name} {value}', ['name' => mb_strtolower($pair[0], 'UTF-8'), 'value' => mb_strtolower($pair[1], 'UTF-8')], $lang)
                        : mb_strtolower((string) $filter['label'], 'UTF-8');
                }
            }
            $parts = array_merge($parts, $attributes);
        }
        if ($subject !== '') {
            $parts[] = $subject;
        }
        $price = null;
        foreach ($plan['filters'] as $filter) {
            $type = (string) $filter['type'];
            if ($type === 'price') {
                $price = $filter;
            } elseif ($type === 'number') {
                $parts[] = mb_strtolower((string) $filter['label'], 'UTF-8');
            } elseif ($type === 'brand') {
                $parts[] = $i18n->t('by {brand}', ['brand' => $filter['name']], $lang);
            } elseif ($type === 'stock') {
                $parts[] = $i18n->t('in stock', [], $lang);
            } elseif ($type === 'sale') {
                $parts[] = $i18n->t('on sale', [], $lang);
            } elseif ($type === 'without') {
                $parts[] = $i18n->t('without {word}', ['word' => $filter['word']], $lang);
            }
        }
        if ($price !== null) {
            $parts[] = $this->pricePhrase($price, $lang);
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $price
     */
    private function pricePhrase(array $price, string $lang): string
    {
        $i18n = $this->app->i18n();
        $money = function (int $cents) use ($i18n, $lang): string {
            return (string) preg_replace('/[.,]00(?=\D|$)/u', '', $i18n->money($cents, $this->app->settings()->str('shop.currency', 'EUR'), $lang));
        };
        if ($price['min'] !== null && $price['max'] !== null) {
            return $i18n->t('between {min} and {max}', ['min' => $money((int) $price['min']), 'max' => $money((int) $price['max'])], $lang);
        }
        if ($price['max'] !== null) {
            return $i18n->t('for less than {price}', ['price' => $money((int) $price['max'])], $lang);
        }

        return $i18n->t('for more than {price}', ['price' => $money((int) $price['min'])], $lang);
    }

    // ------------------------------------------------------------------ preguntas sobre la tienda

    /**
     * Si la frase es una pregunta sobre la tienda, su respuesta: ['topic' => shipping|returns|payment|contact, 'text', 'url' (o '')].
     * Una frase con productos («sillas con envío gratis») no es una pregunta de la tienda: solo lo es la que, quitadas las palabras
     * del tema y las de relleno, no deja nada que buscar (o casi nada, si lleva signo de interrogación).
     *
     * @return array{topic:string,text:string,url:string}|null
     */
    public function shop(string $query, string $lang): ?array
    {
        $tokens = [];
        if (preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $query, $m) !== false) {
            foreach ($m[0] as $word) {
                $tokens[] = Words::fold((string) $word);
            }
        }
        if ($tokens === []) {
            return null;
        }
        $places = $this->words->places($lang);
        $topic = '';
        $covered = [];
        foreach (self::TOPICS as $candidate) {
            foreach ($this->words->phrases('topic_' . $candidate, $lang) as $phrase) {
                $at = self::find($tokens, $phrase);
                if ($at !== null) {
                    $topic = $topic === '' ? $candidate : $topic;
                    if ($topic === $candidate) {
                        for ($i = $at; $i < $at + count($phrase); ++$i) {
                            $covered[$i] = true;
                        }
                    }
                }
            }
            if ($topic !== '') {
                break;
            }
        }
        if ($topic === '') {
            return null;
        }
        $place = $this->place($tokens, $places, $lang, $covered);
        $left = 0;
        foreach ($tokens as $i => $word) {
            if (!isset($covered[$i]) && !$this->words->has('glue', $lang, $word)) {
                ++$left;
            }
        }
        $asked = strpos($query, '?') !== false || strpos($query, '¿') !== false;
        if ($left > ($asked ? 1 : 0)) {
            return null;
        }
        $page = $this->page($topic, $lang);
        $text = $this->text($topic, $place, $lang);
        if ($text === '' && $page === null) {
            return null;
        }
        $url = $page !== null ? $page : '';
        if ($text === '') {
            $text = $this->app->i18n()->t('You will find the information on this page.', [], $lang);
        }

        return ['topic' => $topic, 'text' => $text, 'url' => $url];
    }

    /**
     * @param string[] $tokens
     * @param string[] $phrase
     */
    private static function find(array $tokens, array $phrase): ?int
    {
        $len = count($phrase);
        for ($i = 0; $i + $len <= count($tokens); ++$i) {
            if (array_slice($tokens, $i, $len) === $phrase) {
                return $i;
            }
        }

        return null;
    }

    /**
     * El destino por el que se pregunta: una zona de España, un país o un código postal. Marca sus palabras como cubiertas.
     *
     * @param string[]            $tokens
     * @param array<string,string> $places
     * @param array<int,bool>     $covered
     *
     * @return array{name:string,destination:array<string,mixed>}|null
     */
    private function place(array $tokens, array $places, string $lang, array &$covered): ?array
    {
        foreach ($places as $phrase => $zone) {
            $words = explode(' ', $phrase);
            $at = self::find($tokens, $words);
            if ($at !== null) {
                for ($i = $at; $i < $at + count($words); ++$i) {
                    $covered[$i] = true;
                }

                return ['name' => $this->zoneName($zone, $phrase), 'destination' => ['country' => 'ES', 'region' => '', 'postcode' => self::ZONE_POSTCODE[$zone] ?? '', 'city' => '']];
            }
        }
        foreach ($tokens as $i => $word) {
            if (preg_match('/^\d{5}$/', $word) === 1 && Spain::validPostcode($word)) {
                $covered[$i] = true;
                $province = Spain::provinceByPostcode($word);

                return ['name' => $province !== null ? (string) $province['name'] : $word, 'destination' => ['country' => 'ES', 'region' => $province !== null ? (string) $province['prefix'] : '', 'postcode' => $word, 'city' => '']];
            }
        }
        foreach (Countries::all($lang) as $iso => $name) {
            $words = explode(' ', Words::fold((string) $name));
            $at = self::find($tokens, $words);
            if ($at !== null) {
                for ($i = $at; $i < $at + count($words); ++$i) {
                    $covered[$i] = true;
                }

                return ['name' => (string) $name, 'destination' => ['country' => (string) $iso, 'region' => '', 'postcode' => '', 'city' => '']];
            }
        }

        return null;
    }

    private function zoneName(string $zone, string $phrase): string
    {
        $names = ['canarias' => 'Canarias', 'baleares' => 'Baleares', 'ceuta' => 'Ceuta', 'melilla' => 'Melilla', 'peninsula' => 'Península'];

        return isset($names[$zone]) && $phrase === $zone ? $names[$zone] : mb_convert_case($phrase, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param array{name:string,destination:array<string,mixed>}|null $place
     */
    private function text(string $topic, ?array $place, string $lang): string
    {
        $i18n = $this->app->i18n();
        $settings = $this->app->settings();
        if ($topic === 'shipping') {
            return $this->shipping($place, $lang);
        }
        if ($topic === 'returns') {
            // El módulo de devoluciones, si está activo, manda: su plazo es el que se aplica de verdad.
            $own = $this->app->has('returns') ? $settings->int('returns.days') : 0;
            if ($own > 0) {
                $own = min(365, $own);

                return $i18n->tn('You can return a product within {n} day.', 'You can return a product within {n} days.', $own, ['n' => $own], $lang);
            }
            $semantic = $this->app->has('semantic') ? $this->app->service('semantic') : null;
            $returns = is_object($semantic) && is_callable([$semantic, 'returns']) ? $semantic->returns() : null;
            if (is_array($returns) && isset($returns['days'])) {
                $days = (int) $returns['days'];

                return $i18n->tn('You can return a product within {n} day.', 'You can return a product within {n} days.', $days, ['n' => $days], $lang)
                    . ' ' . (isset($returns['payer']) && $returns['payer'] === 'merchant'
                        ? $i18n->t('The shop pays the return shipping.', [], $lang)
                        : $i18n->t('The customer pays the return shipping.', [], $lang));
            }

            return trim($settings->str('assistant.returns_text'));
        }
        if ($topic === 'payment') {
            $titles = $this->paymentTitles($lang);

            return $titles === [] ? '' : $i18n->t('You can pay with: {methods}.', ['methods' => implode(', ', $titles)], $lang);
        }
        $lines = [];
        foreach (['store.email' => 'Email', 'store.phone' => 'Phone', 'store.address' => 'Address'] as $key => $label) {
            $value = trim($settings->str($key));
            if ($value !== '') {
                $lines[] = $i18n->t($label, [], $lang) . ': ' . $value;
            }
        }
        $hours = trim($settings->str('assistant.hours'));
        if ($hours !== '') {
            $lines[] = $i18n->t('Opening hours', [], $lang) . ': ' . $hours;
        }

        return implode('. ', $lines);
    }

    /**
     * @param array{name:string,destination:array<string,mixed>}|null $place
     */
    private function shipping(?array $place, string $lang): string
    {
        if (!$this->app->has('shipping')) {
            return '';
        }
        $i18n = $this->app->i18n();
        $destination = $place !== null ? $place['destination'] : ['country' => strtoupper(trim($this->app->settings()->str('shipping.default_country', 'ES'))), 'region' => '', 'postcode' => '', 'city' => ''];
        try {
            $shipping = $this->app->service('shipping');
            // Un paquete mínimo: lo que sale es el «desde» de cada transportista (su tarifa más baja), sin envío gratis por importe.
            $options = $shipping->options(['weight' => 1, 'items_total' => 0, 'qty' => 1, 'needs_shipping' => true], $destination);
        } catch (\Throwable $e) {
            return '';
        }
        $options = is_array($options) ? $options : [];
        if ($options === []) {
            return $place !== null ? $i18n->t('We do not ship to {place} at the moment.', ['place' => $place['name']], $lang) : '';
        }
        $currency = $this->app->settings()->str('shop.currency', 'EUR');
        $lines = [];
        foreach (array_slice($options, 0, 6) as $option) {
            $name = isset($option['name']) ? trim((string) $option['name']) : '';
            if ($name === '') {
                continue;
            }
            $parts = [];
            $delay = $this->delay($option, $lang);
            if ($delay !== '') {
                $parts[] = $delay;
            }
            $price = (int) (isset($option['price']) ? $option['price'] : 0);
            $parts[] = $price === 0 ? $i18n->t('free', [], $lang) : $i18n->t('from {price}', ['price' => $this->plainMoney($price, $currency, $lang)], $lang);
            $free = $this->freeFrom($shipping, $option);
            if ($free > 0) {
                $parts[] = $i18n->t('free from {price}', ['price' => $this->plainMoney($free, $currency, $lang)], $lang);
            }
            $lines[] = $name . ': ' . implode(', ', $parts);
        }
        $head = $place !== null ? $i18n->t('Yes, we ship to {place}.', ['place' => $place['name']], $lang) : $i18n->t('Yes, we ship.', [], $lang);

        return $lines === [] ? $head : $head . ' ' . implode('; ', $lines) . '.';
    }

    /** Un importe tal como se dice en voz alta: «60 €», pero «4,90 €» (sin los decimales que sobran). */
    private function plainMoney(int $cents, string $currency, string $lang): string
    {
        return (string) preg_replace('/[.,]00(?=\D|$)/u', '', $this->app->i18n()->money($cents, $currency, $lang));
    }

    /**
     * El plazo de un transportista tal como lo escribió la tienda («24-48 h») o, si no, el de sus días mínimo y máximo.
     *
     * @param array<string,mixed> $option
     */
    private function delay(array $option, string $lang): string
    {
        $text = isset($option['delay']) ? trim((string) $option['delay']) : '';
        if ($text !== '') {
            return $text;
        }
        $min = isset($option['min_days']) && is_numeric($option['min_days']) ? (int) $option['min_days'] : 0;
        $max = isset($option['max_days']) && is_numeric($option['max_days']) ? (int) $option['max_days'] : 0;
        $i18n = $this->app->i18n();
        if ($min > 0 && $max > $min) {
            return $i18n->t('{min}-{max} days', ['min' => $min, 'max' => $max], $lang);
        }
        $days = max($min, $max);

        return $days > 0 ? $i18n->tn('{n} day', '{n} days', $days, ['n' => $days], $lang) : '';
    }

    /**
     * El importe de artículos a partir del cual el transportista sale gratis, en céntimos (0 si nunca).
     *
     * @param array<string,mixed> $option
     */
    private function freeFrom(object $shipping, array $option): int
    {
        if (!isset($option['carrier_id']) || !is_callable([$shipping, 'carrier'])) {
            return 0;
        }
        $carrier = $shipping->carrier((int) $option['carrier_id']);

        return is_array($carrier) && isset($carrier['free_from']) && is_numeric($carrier['free_from']) ? max(0, (int) $carrier['free_from']) : 0;
    }

    /**
     * @return string[]
     */
    private function paymentTitles(string $lang): array
    {
        if (!$this->app->has('payments')) {
            return [];
        }
        $titles = [];
        try {
            $methods = $this->app->service('payments')->available([
                'total' => 0, 'currency' => $this->app->settings()->str('shop.currency', 'EUR'),
                'country' => strtoupper(trim($this->app->settings()->str('shipping.default_country', 'ES'))), 'needs_shipping' => true,
                'customer_id' => null, 'lang' => $lang,
            ]);
            foreach (is_array($methods) ? $methods : [] as $method) {
                $info = is_object($method) && is_callable([$method, 'info']) ? $method->info() : [];
                if (isset($info['title']) && trim((string) $info['title']) !== '') {
                    $titles[] = trim((string) $info['title']);
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $titles;
    }

    /**
     * La página publicada de la tienda que habla de ese tema (por su título), con su dirección; null si no hay.
     */
    private function page(string $topic, string $lang): ?string
    {
        $db = $this->app->db();
        if (!$db->tableExists('pages') || !$db->tableExists('page_i18n')) {
            return null;
        }
        $keywords = [];
        foreach ($this->words->phrases('topic_' . $topic, $lang) as $phrase) {
            if (count($phrase) === 1 && mb_strlen($phrase[0], 'UTF-8') >= 5) {
                $keywords[] = $phrase[0];
            }
        }
        $rows = $db->select(
            "SELECT p.id, i.title FROM {pages} p JOIN {page_i18n} i ON i.page_id = p.id AND i.lang = ? WHERE p.status = 'published' AND (p.published_at IS NULL OR p.published_at <= ?) ORDER BY p.id",
            [$lang, $this->app->clock()->sql()]
        );
        foreach ($keywords === [] ? [] : $rows as $row) {
            $title = Words::fold((string) $row['title']);
            foreach ($keywords as $keyword) {
                if (strpos($title, substr($keyword, 0, max(4, strlen($keyword) - 2))) !== false) {
                    return $this->app->url()->entity('page', (int) $row['id'], $lang);
                }
            }
        }
        // Sin una página con el tema en el título, la legal de «Envíos y devoluciones» (la que crea el módulo de contenidos), si está publicada.
        if ($topic === 'shipping' || $topic === 'returns') {
            $legal = $this->app->settings()->arr('cms.legal');
            $id = isset($legal['shipping-returns']) ? (int) $legal['shipping-returns'] : 0;
            foreach ($id > 0 ? $rows : [] as $row) {
                if ((int) $row['id'] === $id) {
                    return $this->app->url()->entity('page', $id, $lang);
                }
            }
        }

        return null;
    }
}
