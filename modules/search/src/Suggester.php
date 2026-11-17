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
 * Las sugerencias que salen mientras se escribe: por prefijo (de cada palabra) sobre los nombres de los productos y de las
 * categorías, y sobre las consultas que más se repiten y encontraron algo. Hasta 8, en este orden: consultas (como mucho 2),
 * categorías (como mucho 2) y el resto, productos (los que hay en existencias y más se venden, primero).
 *
 * Todo sale del índice y de la caché del catálogo: no hay LIKE sobre los textos de los productos y no se llama a ninguna IA.
 */
final class Suggester
{
    public const MAX = 8;

    /** Menos letras que esto no sugieren nada. */
    private const MIN_CHARS = 2;

    /** Palabras de lo escrito que se tienen en cuenta. */
    private const MAX_WORDS = 4;

    /** @var App */
    private $app;

    /** @var Search */
    private $search;

    public function __construct(App $app, Search $search)
    {
        $this->app = $app;
        $this->search = $search;
    }

    /**
     * @return array<int,array<string,mixed>> ['type' => product|category|query, 'label', 'url', 'image', 'price', 'group']
     */
    public function suggest(string $q, string $lang): array
    {
        $normal = $this->search->analyzer($lang)->normalize(mb_substr(Analyzer::clean($q), 0, 60, 'UTF-8'));
        if (mb_strlen($normal, 'UTF-8') < self::MIN_CHARS) {
            return [];
        }
        $words = array_slice(explode(' ', $normal), 0, self::MAX_WORDS);

        $items = [];
        $seen = [];
        foreach ($this->queries($lang, $normal) as $item) {
            if (isset($seen[$item['label']])) {
                continue;
            }
            $seen[$item['label']] = true;
            $items[] = $item;
        }
        foreach ($this->categories($lang, $words) as $item) {
            $items[] = $item;
        }
        $room = self::MAX - count($items);
        if ($room > 0) {
            foreach ($this->products($lang, $words, $normal, $room) as $item) {
                $items[] = $item;
            }
        }

        return array_slice($items, 0, self::MAX);
    }

    /**
     * Las consultas frecuentes con resultado que empiezan por lo escrito (y no son lo escrito).
     *
     * @return array<int,array<string,mixed>>
     */
    private function queries(string $lang, string $normal): array
    {
        $out = [];
        foreach ($this->search->log()->popular($lang, $normal, 3) as $phrase) {
            if ($phrase === $normal) {
                continue;
            }
            $out[] = [
                'type' => 'query', 'label' => $phrase, 'url' => $this->app->url()->route('search', ['q' => $phrase], $lang),
                'image' => null, 'price' => null, 'group' => t('Search'),
            ];
            if (count($out) >= 2) {
                break;
            }
        }

        return $out;
    }

    /**
     * Las categorías (hasta 2) en las que cada palabra de lo escrito es el comienzo de alguna palabra del nombre.
     *
     * @param string[] $words
     *
     * @return array<int,array<string,mixed>>
     */
    private function categories(string $lang, array $words): array
    {
        if (!$this->app->has('catalog')) {
            return [];
        }
        $analyzer = $this->search->analyzer($lang);
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out, $analyzer, $words): void {
            foreach ($nodes as $node) {
                if (count($out) >= 2) {
                    return;
                }
                $name = isset($node['name']) ? (string) $node['name'] : '';
                if ($name !== '' && self::startsWords($analyzer->normalize($name), $words)) {
                    $out[] = ['type' => 'category', 'label' => $name, 'url' => (string) $node['url'], 'image' => null, 'price' => null, 'group' => t('Category')];
                }
                if (isset($node['children']) && is_array($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $tree = $this->app->service('catalog')->tree($lang);
        $walk(is_array($tree) ? $tree : []);

        return $out;
    }

    /**
     * @param string[] $words
     *
     * @return array<int,array<string,mixed>>
     */
    private function products(string $lang, array $words, string $normal, int $room): array
    {
        if (!$this->app->has('catalog')) {
            return [];
        }
        // Lo escrito ya está normalizado (solo letras, números y espacios): no lleva comodines de LIKE.
        $where = [];
        $params = [$lang, 'product'];
        foreach ($words as $word) {
            $where[] = '(name_norm LIKE ? OR name_norm LIKE ?)';
            $params[] = $word . '%';
            $params[] = '% ' . $word . '%';
        }
        $params[] = $room * 3;
        $rows = $this->app->db()->select(
            'SELECT entity_id, name_norm FROM {search_docs} WHERE lang = ? AND type = ? AND ' . implode(' AND ', $where)
            . ' ORDER BY in_stock DESC, sales DESC, entity_id ASC LIMIT ?',
            $params
        );
        // Los que empiezan por lo escrito, antes que los que lo llevan en medio (sin perder el orden de existencias y ventas).
        $first = [];
        $later = [];
        foreach ($rows as $row) {
            if (strpos((string) $row['name_norm'], $normal) === 0) {
                $first[] = (int) $row['entity_id'];
            } else {
                $later[] = (int) $row['entity_id'];
            }
        }
        $ids = array_slice(array_merge($first, $later), 0, $room);
        if ($ids === []) {
            return [];
        }
        $cards = $this->app->service('catalog')->cards($ids, $lang);
        $out = [];
        foreach ($ids as $id) {
            if (!isset($cards[$id])) {
                continue;
            }
            $card = $cards[$id];
            $image = null;
            if (isset($card['image']) && is_array($card['image'])) {
                $image = (string) ($card['image']['thumb'] !== '' ? $card['image']['thumb'] : $card['image']['url']);
            }
            $price = null;
            if (isset($card['price']['amount'])) {
                $price = money((int) $card['price']['amount'], isset($card['price']['currency']) && is_string($card['price']['currency']) && $card['price']['currency'] !== '' ? $card['price']['currency'] : null);
            }
            $out[] = ['type' => 'product', 'label' => (string) $card['name'], 'url' => (string) $card['url'], 'image' => $image, 'price' => $price, 'group' => ''];
        }

        return $out;
    }

    /**
     * ¿Cada palabra escrita es el comienzo de alguna palabra del nombre (ya normalizado)?
     *
     * @param string[] $words
     */
    private static function startsWords(string $normalName, array $words): bool
    {
        $name = ' ' . $normalName;
        foreach ($words as $word) {
            if ($word === '' || strpos($name, ' ' . $word) === false) {
                return false;
            }
        }

        return $words !== [];
    }
}
