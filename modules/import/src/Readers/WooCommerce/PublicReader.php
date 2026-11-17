<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\WooCommerce;

use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

/**
 * Lee una tienda de WooCommerce SIN clave, por sus API públicas (wc/store/v1 y wp/v2): solo lo publicado. Sirve para montar
 * una demo con el catálogo y los contenidos de una tienda ajena. No da existencias exactas, costes, borradores, clientes,
 * pedidos ni configuración: lo que no da se queda sin rellenar, no se inventa.
 */
final class PublicReader extends WpRestReader
{
    /** @var array<int,array<string,mixed>>|null */
    private $attributeCache;

    public static function describe(): array
    {
        return [
            'source' => 'woocommerce', 'mode' => 'public', 'label' => 'WooCommerce (public, no key)',
            'help' => 'Reads the published catalogue and contents through the public store API. No key is needed. Stock, costs, drafts, customers and orders are not available this way.',
            'fields' => [
                'url' => ['type' => 'url', 'label' => 'Shop address', 'required' => true, 'hint' => 'https://shop.example'],
                'locale' => ['type' => 'text', 'label' => 'Shop language', 'required' => false, 'hint' => 'es_ES', 'default' => 'es_ES'],
                'prices_include_tax' => [
                    'type' => 'select', 'label' => 'Prices shown include tax', 'required' => false, 'default' => 'yes',
                    'options' => ['yes' => 'Yes', 'no' => 'No'], 'hint' => 'The public API gives the prices as the shop shows them',
                ],
                'weight_unit' => ['type' => 'select', 'label' => 'Weight unit', 'required' => false, 'default' => 'kg', 'options' => ['kg' => 'kg', 'g' => 'g', 'lb' => 'lb', 'oz' => 'oz']],
                'dimension_unit' => ['type' => 'select', 'label' => 'Dimension unit', 'required' => false, 'default' => 'cm', 'options' => ['m' => 'm', 'cm' => 'cm', 'mm' => 'mm', 'in' => 'in']],
            ],
        ];
    }

    public function mode(): string
    {
        return 'public';
    }

    protected function authorization(string $path): ?string
    {
        return null;
    }

    protected function buildMapper(): Mapper
    {
        return new Mapper([
            'locale' => (string) ($this->config['locale'] ?? 'es_ES'), 'timezone' => 'UTC', 'site_url' => $this->base,
            'weight_unit' => (string) ($this->config['weight_unit'] ?? 'kg'), 'dimension_unit' => (string) ($this->config['dimension_unit'] ?? 'cm'),
            'prices_include_tax' => (string) ($this->config['prices_include_tax'] ?? 'yes') !== 'no',
        ]);
    }

    public function types(): array
    {
        return ['categories', 'attributes', 'brands', 'products', 'media', 'pages', 'post_categories', 'posts'];
    }

    public function probe(): array
    {
        $result = ['ok' => false, 'message' => '', 'version' => 'WooCommerce store API', 'shops' => [], 'languages' => [$this->mapper()->language()], 'currency' => '', 'warnings' => [], 'counts' => []];
        try {
            $response = $this->request('/wp-json/wc/store/v1/products', ['per_page' => 1]);
        } catch (SourceDenied $e) {
            $result['message'] = 'The shop does not let its public store API be read';

            return $result;
        } catch (SourceError $e) {
            $result['message'] = 'The shop could not be reached';

            return $result;
        }
        if ($response->status === 404) {
            $result['message'] = 'The shop does not offer the public store API: is WooCommerce active?';

            return $result;
        }
        $first = $this->listOf($response);
        $total = $response->header('X-WP-Total');
        $count = $total !== null && is_numeric($total) ? (int) $total : count($first);
        $result['counts']['products'] = $count;
        if (isset($first[0]['prices']['currency_code']) && is_string($first[0]['prices']['currency_code'])) {
            $result['currency'] = $first[0]['prices']['currency_code'];
        }
        if ($count < 1) {
            $result['message'] = 'The shop answered but it has no published products to read';
            $result['warnings'][] = 'Only published products can be read without a key';

            return $result;
        }
        $result['ok'] = true;
        $result['message'] = 'Connected to the shop';
        $result['warnings'][] = 'Without a key only what is published can be read: no stock, costs, drafts, customers or orders';

        return $result;
    }

    public function count(string $type): ?int
    {
        $paths = [
            'products' => '/wp-json/wc/store/v1/products', 'categories' => '/wp-json/wc/store/v1/products/categories', 'brands' => '/wp-json/wc/store/v1/products/brands',
            'media' => '/wp-json/wp/v2/media', 'pages' => '/wp-json/wp/v2/pages', 'posts' => '/wp-json/wp/v2/posts', 'post_categories' => '/wp-json/wp/v2/categories',
        ];

        return isset($paths[$type]) ? $this->total($paths[$type]) : null;
    }

    public function read(string $type, string $cursor, int $limit): array
    {
        $mapper = $this->mapper();
        switch ($type) {
            case 'categories':
                list($items, $next) = $this->page('/wp-json/wc/store/v1/products/categories', [], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $c) use ($mapper): array {
                    return $mapper->category($c, $mapper->paths($c['permalink'] ?? ''));
                });
                break;
            case 'brands':
                list($items, $next) = $this->page('/wp-json/wc/store/v1/products/brands', [], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $b) use ($mapper): array {
                    return $mapper->brand($b);
                });
                break;
            case 'attributes':
                list($items, $next) = Batch::slice($this->attributes(), $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $a) use ($mapper): array {
                    return $mapper->attribute($a['attribute'], $a['terms']);
                }, 'key');
                break;
            case 'products':
                list($items, $next) = $this->page('/wp-json/wc/store/v1/products', ['orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, function (array $p) use ($mapper): array {
                    $variations = (string) ($p['type'] ?? '') === 'variable' ? $this->all('/wp-json/wc/store/v1/products', ['type' => 'variation', 'parent' => (int) $p['id']]) : [];

                    return $mapper->storeProduct($p, array_values(array_filter($variations, 'is_array')));
                });
                break;
            case 'media':
            case 'pages':
            case 'post_categories':
            case 'posts':
                return $this->readContent($type, $cursor, $limit);
            default:
                throw new \InvalidArgumentException('This reader cannot read ' . $type);
        }

        return Batch::result($records, $next, count($items), $skipped);
    }

    /**
     * Los atributos globales con sus términos (la API pública no los pagina: son pocos).
     *
     * @return array<int,array<string,mixed>>
     */
    private function attributes(): array
    {
        if ($this->attributeCache === null) {
            $this->attributeCache = [];
            foreach ($this->listOf($this->request('/wp-json/wc/store/v1/products/attributes')) as $a) {
                if (!is_array($a) || (int) ($a['id'] ?? 0) <= 0) {
                    continue;
                }
                $terms = $this->all('/wp-json/wc/store/v1/products/attributes/' . (int) $a['id'] . '/terms');
                $this->attributeCache[] = [
                    'key' => (string) $a['id'], 'terms' => array_values(array_filter($terms, 'is_array')),
                    'attribute' => ['id' => $a['id'], 'name' => $a['name'] ?? '', 'slug' => $a['taxonomy'] ?? '', 'type' => $a['type'] ?? 'select', 'position' => count($this->attributeCache)],
                ];
            }
        }

        return $this->attributeCache;
    }

    public function checksums(): array
    {
        $sums = [];
        foreach (['products' => 'products_count', 'categories' => 'categories_count', 'pages' => 'pages_count', 'posts' => 'posts_count'] as $type => $name) {
            $count = $this->count($type);
            if ($count !== null) {
                $sums[$name] = $count;
            }
        }

        return $sums;
    }

    public function unmapped(): array
    {
        return [];
    }
}
