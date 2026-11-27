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
use Aifec\Search\Tokenizer;

/**
 * Las palabras que el buscador reconoce («menos de», «barato», «en oferta»…). No están en el código: cada lista es una cadena del
 * catálogo de idioma (lang/<idioma>.php), con las palabras o frases separadas por comas, de modo que entender otro idioma es
 * traducir esas cadenas. Sin traducción sale la lista en inglés. Todo se compara sin mayúsculas y sin tildes.
 */
final class Words
{
    /** @var App */
    private $app;

    /** @var array<string,array<string,array<int,string[]>>> idioma => lista => frases (cada una, sus palabras) */
    private $lists = [];

    /** @var array<string,array<string,string[]>> idioma => unidad => formas de escribirla */
    private $units = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Las frases de una lista, de la más larga a la más corta. Cada frase es la lista de sus palabras ya normalizadas.
     *
     * @return array<int,string[]>
     */
    public function phrases(string $list, string $lang): array
    {
        if (!isset($this->lists[$lang])) {
            $this->lists[$lang] = [];
        }
        if (!isset($this->lists[$lang][$list])) {
            $this->lists[$lang][$list] = self::parse($this->raw($list, $lang));
        }

        return $this->lists[$lang][$list];
    }

    /** ¿Es esta palabra (ya normalizada) alguna de las de la lista? Solo palabras sueltas. */
    public function has(string $list, string $lang, string $word): bool
    {
        foreach ($this->phrases($list, $lang) as $phrase) {
            if (count($phrase) === 1 && $phrase[0] === $word) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las unidades de medida: unidad normalizada => las formas con que se escribe («m» => m, metro, metros).
     *
     * @return array<string,string[]>
     */
    public function units(string $lang): array
    {
        if (isset($this->units[$lang])) {
            return $this->units[$lang];
        }
        $out = [];
        foreach (explode(';', $this->raw('units', $lang)) as $group) {
            $pair = explode('=', $group, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $unit = self::fold($pair[0]);
            $forms = [];
            foreach (explode(',', $pair[1]) as $form) {
                $form = self::fold($form);
                if ($form !== '') {
                    $forms[$form] = $form;
                }
            }
            if ($unit !== '' && $forms !== []) {
                $out[$unit] = array_values($forms + [$unit => $unit]);
            }
        }
        $this->units[$lang] = $out;

        return $out;
    }

    /**
     * Los destinos que la tienda sabe nombrar en una pregunta de envío: frase normalizada (sus palabras) => zona.
     *
     * @return array<string,string> «canarias» => «canarias»; «islas canarias» => «canarias»
     */
    public function places(string $lang): array
    {
        $out = [];
        foreach (explode(',', $this->raw('places', $lang)) as $item) {
            $pair = explode('=', $item, 2);
            if (count($pair) === 2 && self::fold($pair[0]) !== '' && self::fold($pair[1]) !== '') {
                $out[self::fold($pair[0])] = self::fold($pair[1]);
            }
        }

        return $out;
    }

    /** Minúsculas y sin tildes. */
    public static function fold(string $text): string
    {
        return trim(Tokenizer::stripAccents(mb_strtolower($text, 'UTF-8')));
    }

    /**
     * @return array<int,string[]>
     */
    private static function parse(string $raw): array
    {
        $phrases = [];
        foreach (explode(',', $raw) as $item) {
            $words = preg_split('/\s+/u', self::fold($item), -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($words) && $words !== []) {
                $phrases[implode(' ', $words)] = $words;
            }
        }
        $list = array_values($phrases);
        usort($list, static function (array $a, array $b): int {
            return count($b) <=> count($a);
        });

        return $list;
    }

    private function raw(string $list, string $lang): string
    {
        $i18n = $this->app->i18n();
        switch ($list) {
            case 'max':
                return $i18n->t('less than, under, below, up to, at most, no more than, cheaper than, maximum', [], $lang);
            case 'min':
                return $i18n->t('more than, over, above, from, at least, minimum, starting at', [], $lang);
            case 'approximate':
                return $i18n->t('about, around, approximately, roughly', [], $lang);
            case 'between':
                return $i18n->t('between', [], $lang);
            case 'and':
                return $i18n->t('and', [], $lang);
            case 'currency':
                return $i18n->t('euros, euro, eur, €', [], $lang);
            case 'cheap':
                return $i18n->t('cheap, cheapest, the cheapest, the most affordable, affordable, inexpensive', [], $lang);
            case 'sale':
                return $i18n->t('on sale, discounted, reduced, sale, offers', [], $lang);
            case 'stock':
                return $i18n->t('in stock, available now, available, with stock', [], $lang);
            case 'new':
                return $i18n->t('new arrivals, newest, latest, what is new', [], $lang);
            case 'best':
                return $i18n->t('best sellers, best selling, most popular, top sellers', [], $lang);
            case 'rated':
                return $i18n->t('best rated, top rated, highest rated, best reviewed', [], $lang);
            case 'without':
                return $i18n->t('without, not, except, but not, that is not, that are not, that has no, that does not have', [], $lang);
            case 'units':
                return $i18n->t('m=m,meter,meters,metre,metres;cm=cm,centimeter,centimeters;mm=mm,millimeter,millimeters;kg=kg,kilo,kilos,kilogram,kilograms;g=g,gr,gram,grams;l=l,lt,liter,liters,litre,litres;ml=ml,milliliter,milliliters;w=w,watt,watts;v=v,volt,volts', [], $lang);
            case 'topic_shipping':
                return $i18n->t('shipping, ship, deliver, delivery, deliveries, postage', [], $lang);
            case 'topic_returns':
                return $i18n->t('returns, return, refund, refunds, send back', [], $lang);
            case 'topic_payment':
                return $i18n->t('payment, payments, pay, payment methods, bank transfer, cash on delivery', [], $lang);
            case 'topic_contact':
                return $i18n->t('contact, phone, telephone, email, address, opening hours', [], $lang);
            case 'glue':
                return $i18n->t('do, does, you, we, can, i, how, much, many, when, where, what, is, are, the, a, an, to, of, in, for, your, our, ways, way, methods, method, policy, costs, cost, cheap, time, long, takes', [], $lang);
            case 'places':
                return $i18n->t('canary islands=canarias, balearic islands=baleares, ceuta=ceuta, melilla=melilla, mainland spain=peninsula', [], $lang);
        }

        return '';
    }
}
