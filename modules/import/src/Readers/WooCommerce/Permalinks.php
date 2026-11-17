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

/**
 * Reconstruye la ruta del enlace permanente de cada cosa a partir de la estructura de enlaces de WordPress y de las bases de
 * WooCommerce. Solo lo usa el lector de la copia de la base de datos (por API cada recurso trae su enlace). Sin estructura
 * (enlaces «simples», ?p=12) no hay ruta que conservar: todo devuelve null.
 */
final class Permalinks
{
    /** @var string */
    private $structure;

    /** @var bool */
    private $slash;

    /** @var string */
    private $productBase;

    /** @var string */
    private $categoryBase;

    /** @var string */
    private $postCategoryBase;

    /**
     * @param string               $structure        opción permalink_structure («/%postname%/», «/%year%/%postname%/»…)
     * @param array<string,mixed>  $woo              opción woocommerce_permalinks (product_base, category_base)
     * @param string               $postCategoryBase opción category_base de WordPress («category» si va vacía)
     */
    public function __construct(string $structure, array $woo = [], string $postCategoryBase = '')
    {
        $this->structure = trim($structure);
        $this->slash = $this->structure !== '' && substr($this->structure, -1) === '/';
        $product = trim((string) ($woo['product_base'] ?? ''), '/');
        $this->productBase = $product !== '' ? $product : 'product';
        $category = trim((string) ($woo['category_base'] ?? ''), '/');
        $this->categoryBase = $category !== '' ? $category : 'product-category';
        $postCategory = trim($postCategoryBase, '/');
        $this->postCategoryBase = $postCategory !== '' ? $postCategory : 'category';
    }

    public function enabled(): bool
    {
        return $this->structure !== '';
    }

    /** Ruta de un producto; $categoryPath, la ruta jerárquica de su categoría («muebles/mesas»), si la base la lleva. */
    public function product(string $slug, string $categoryPath = ''): ?string
    {
        if (!$this->enabled() || $slug === '') {
            return null;
        }
        $base = str_replace('%product_cat%', $categoryPath !== '' ? $categoryPath : 'uncategorized', $this->productBase);

        return '/' . trim($base, '/') . '/' . $slug . $this->tail();
    }

    /** Ruta de una categoría de producto a partir de su ruta jerárquica. */
    public function productCategory(string $path): ?string
    {
        return $this->enabled() && $path !== '' ? '/' . $this->categoryBase . '/' . $path . $this->tail() : null;
    }

    /** Ruta de una categoría de entradas. */
    public function postCategory(string $path): ?string
    {
        return $this->enabled() && $path !== '' ? '/' . $this->postCategoryBase . '/' . $path . $this->tail() : null;
    }

    /** Ruta de una página a partir de su ruta jerárquica («padre/hija»). */
    public function page(string $path): ?string
    {
        return $this->enabled() && $path !== '' ? '/' . trim($path, '/') . $this->tail() : null;
    }

    /**
     * Ruta de una entrada.
     *
     * @param string $date         fecha local «AAAA-MM-DD HH:MM:SS»
     * @param string $categoryPath ruta de su primera categoría
     */
    public function post(string $slug, int $id, string $date, string $categoryPath = '', string $author = ''): ?string
    {
        if (!$this->enabled() || $slug === '') {
            return null;
        }
        $date = str_pad($date, 19, '0');
        $tags = [
            '%year%' => substr($date, 0, 4), '%monthnum%' => substr($date, 5, 2), '%day%' => substr($date, 8, 2),
            '%hour%' => substr($date, 11, 2), '%minute%' => substr($date, 14, 2), '%second%' => substr($date, 17, 2),
            '%post_id%' => (string) $id, '%postname%' => $slug, '%category%' => $categoryPath !== '' ? $categoryPath : 'uncategorized',
            '%author%' => $author,
        ];

        return '/' . trim(strtr($this->structure, $tags), '/') . $this->tail();
    }

    private function tail(): string
    {
        return $this->slash ? '/' : '';
    }
}
