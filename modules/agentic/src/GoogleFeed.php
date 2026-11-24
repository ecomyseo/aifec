<?php
/**
 * El catálogo en el formato de Google Merchant Center (texto separado por tabuladores). También lo leen Microsoft
 * Merchant Center y Perplexity. Una variante, una línea (item_group_id junta las de un mismo producto).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */
declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Semantic\FeedEligibility;
use Aifec\Modules\Semantic\Semantic;

final class GoogleFeed
{
    public const COLUMNS = ['id', 'title', 'description', 'link', 'image_link', 'additional_image_link', 'availability', 'price', 'sale_price',
        'brand', 'gtin', 'mpn', 'condition', 'identifier_exists', 'item_group_id', 'product_type'];

    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Una celda: sin tabuladores ni saltos de línea, que rompen el formato. */
    public static function cell(string $text, int $limit = 0): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return $limit > 0 && mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }

    /** «in_stock» → «in stock», como lo pide Google. */
    public static function availability(string $state): string
    {
        $map = ['in_stock' => 'in stock', 'backorder' => 'backorder', 'preorder' => 'preorder'];

        return $map[$state] ?? 'out of stock';
    }

    public static function price(string $amount, string $currency): string
    {
        return trim($amount . ' ' . strtoupper($currency));
    }

    /** @param array<string,mixed> $row */
    public static function line(array $row): string
    {
        $out = [];
        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? '';
            $out[] = self::cell(is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value);
        }

        return implode("\t", $out) . "\n";
    }

    public function serve(Request $request, array $params): Response
    {
        $app = $this->app;
        require_once $app->root() . '/modules/semantic/src/Semantic.php';
        require_once $app->root() . '/modules/semantic/src/FeedEligibility.php';
        $semantic = new Semantic($app);
        $catalog = $app->service('catalog');

        return Response::stream(static function (callable $write) use ($app, $semantic, $catalog): void {
            $write(implode("\t", self::COLUMNS) . "\n");
            $page = 1;
            do {
                $listing = $catalog->listing(['sort' => 'newest', 'page' => $page, 'per_page' => 96]);
                $ids = array_map('intval', array_column($listing['items'], 'id'));
                foreach ($catalog->products($ids) as $product) {
                    $images = $semantic->images($product, 11);
                    if (!$images || empty($product['availability']['show_price'])) {
                        continue;
                    }
                    $variants = !empty($product['variants']) ? $product['variants'] : [['id' => 0, 'label' => '']];
                    $variantImages = array_column($product['images'], 'url', 'id');
                    foreach ($variants as $variant) {
                        $id = (int) $variant['id'];
                        $offer = $catalog->purchasable((int) $product['id'], $id);
                        if ($offer === null) {
                            continue;
                        }
                        $price = $catalog->price($offer);
                        $amount = $semantic->gross($price);
                        if (!FeedEligibility::priceAllowed($app, $amount)) {
                            continue;
                        }
                        $title = (string) $product['name'];
                        $regular = $semantic->grossCompare($price);
                        $url = (string) $product['url_abs'];
                        $pictures = $images;
                        if ($id) {
                            $url .= (strpos($url, '?') === false ? '?' : '&') . 'variante=' . $id;
                            $variantImage = $variantImages[$variant['image_id'] ?? 0] ?? '';
                            if ($variantImage !== '') {
                                $owned = $semantic->abs($variantImage);
                                $pictures = array_merge([$owned], array_values(array_diff($images, [$owned])));
                            }
                        }
                        $gtin = (string) ($variant['gtin'] ?? $product['gtin'] ?? '');
                        $mpn = $semantic->mpn($product, $id ? $variant : null);
                        $condition = (string) ($product['condition'] ?? '');
                        $currency = $semantic->currency();
                        $write(self::line([
                            'id' => (string) $product['id'] . ($id ? '-' . $id : ''),
                            'title' => $semantic->plain($title . ($id && !empty($variant['label']) ? ' - ' . $variant['label'] : ''), 150),
                            'description' => $semantic->plain((string) ($product['description'] ?: $title), 5000) ?: $title,
                            'link' => $url,
                            'image_link' => $pictures[0],
                            'additional_image_link' => array_slice($pictures, 1, 10),
                            'availability' => self::availability(empty($offer['available']) ? 'out_of_stock' : (isset($offer['stock']) && $offer['stock'] <= 0 ? 'backorder' : 'in_stock')),
                            'price' => self::price($semantic->decimal($regular !== null && $regular > $amount ? $regular : $amount), $currency),
                            'sale_price' => $regular !== null && $regular > $amount ? self::price($semantic->decimal($amount), $currency) : '',
                            'brand' => $semantic->brand($product),
                            'gtin' => $gtin,
                            'mpn' => $mpn,
                            'condition' => in_array($condition, ['new', 'used', 'refurbished'], true) ? $condition : 'new',
                            'identifier_exists' => ($gtin !== '' || $mpn !== '') ? '' : 'no',
                            'item_group_id' => $id ? (string) $product['id'] : '',
                            'product_type' => $semantic->categoryPath($product),
                        ]));
                    }
                }
            } while ($page++ < (int) ($listing['pages'] ?? 1));
        }, ['Content-Type' => 'text/tab-separated-values; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
