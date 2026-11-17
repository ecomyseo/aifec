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
use Aifec\Modules\Catalog\Attributes;

/**
 * Lo que existe en el catálogo y se puede nombrar en una consulta: los valores de los atributos de lista (color, material…), los
 * atributos numéricos con su unidad (largo en cm, peso en kg), las categorías y las marcas. Cada nombre se guarda por sus raíces
 * (las mismas que usa el índice), así «negras», «negro» y «Negro» son la misma clave.
 *
 * Se construye una vez por idioma y se guarda en la caché con las etiquetas «search» y «catalog»: cuando cambia el catálogo se
 * invalida sola y la siguiente consulta lo rehace. Una consulta normal lo lee de la caché sin tocar la base de datos.
 */
final class Vocabulary
{
    /** Palabras como mucho de un nombre de categoría, marca o valor (más largo no se busca en una frase). */
    public const MAX_WORDS = 5;

    /** @var App */
    private $app;

    /** @var Search */
    private $search;

    /** @var array<string,array<string,mixed>> */
    private $memo = [];

    /** @var string */
    private $memoVersion = '';

    public function __construct(App $app, Search $search)
    {
        $this->app = $app;
        $this->search = $search;
    }

    /**
     * ['values' => clave => ['attribute','value','label','name'], 'numbers' => código => ['code','name','unit','stems'],
     *  'categories' => clave => ['id','name'], 'brands' => clave => ['id','name'], 'words' => palabras de la clave más larga]
     *
     * @return array<string,mixed>
     */
    public function get(string $lang): array
    {
        $version = $this->app->cache()->tagVersion('search') . ':' . $this->app->cache()->tagVersion('catalog');
        if ($version !== $this->memoVersion) {
            $this->memo = [];
            $this->memoVersion = $version;
        }
        if (isset($this->memo[$lang])) {
            return $this->memo[$lang];
        }
        $key = 'search:vocab:' . $lang;
        $hit = $this->app->cache()->get($key);
        if (is_array($hit) && isset($hit['values'], $hit['numbers'], $hit['categories'], $hit['brands'])) {
            $this->memo[$lang] = $hit;

            return $hit;
        }
        $vocabulary = $this->build($lang);
        $this->app->cache()->set($key, $vocabulary, 3600, ['search', 'catalog']);
        $this->memo[$lang] = $vocabulary;

        return $vocabulary;
    }

    /** La clave de un nombre: sus raíces separadas por espacios («Sillas de oficina» → «sillo oficina»). */
    public function key(string $text, string $lang): string
    {
        return implode(' ', $this->search->analyzer($lang)->tokens($text));
    }

    /**
     * @return array<string,mixed>
     */
    private function build(string $lang): array
    {
        $values = [];
        $numbers = [];
        $categories = [];
        $brands = [];
        $max = 1;

        $attributes = new Attributes($this->app);
        foreach ($attributes->all($lang) as $attr) {
            $type = (string) $attr['type'];
            if ($type === 'number') {
                $unit = Words::fold((string) $attr['unit']);
                if ($unit !== '') {
                    $numbers[(string) $attr['code']] = [
                        'code' => (string) $attr['code'], 'name' => (string) $attr['name'], 'unit' => $unit,
                        'stems' => $this->key((string) $attr['name'], $lang),
                    ];
                }
                continue;
            }
            if (!in_array($type, ['select', 'multiselect', 'color'], true)) {
                continue;
            }
            foreach ($attr['values'] as $value) {
                $key = $this->key((string) $value['label'], $lang);
                if ($key === '' || isset($values[$key])) {
                    continue;
                }
                $values[$key] = ['attribute' => (string) $attr['code'], 'value' => (string) $value['code'], 'label' => (string) $value['label'], 'name' => (string) $attr['name']];
                $max = max($max, count(explode(' ', $key)));
            }
        }

        $catalog = $this->app->service('catalog');
        $tree = is_callable([$catalog, 'tree']) ? $catalog->tree($lang) : [];
        $walk = function (array $nodes) use (&$walk, &$categories, &$max, $lang): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (isset($node['id'], $node['name'])) {
                    $key = $this->key((string) $node['name'], $lang);
                    if ($key !== '' && !isset($categories[$key])) {
                        $categories[$key] = ['id' => (int) $node['id'], 'name' => (string) $node['name']];
                        $max = max($max, count(explode(' ', $key)));
                    }
                }
                if (isset($node['children']) && is_array($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk(is_array($tree) ? $tree : []);

        $list = is_callable([$catalog, 'brands']) ? $catalog->brands() : [];
        foreach (is_array($list) ? $list : [] as $brand) {
            if (!is_array($brand) || !isset($brand['id'], $brand['name'])) {
                continue;
            }
            $key = $this->key((string) $brand['name'], $lang);
            if ($key !== '' && !isset($brands[$key])) {
                $brands[$key] = ['id' => (int) $brand['id'], 'name' => (string) $brand['name']];
                $max = max($max, count(explode(' ', $key)));
            }
        }

        return ['values' => $values, 'numbers' => $numbers, 'categories' => $categories, 'brands' => $brands, 'words' => min(self::MAX_WORDS, $max)];
    }
}
