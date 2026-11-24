<?php
/** Catálogo ACP con una fila por variante y pago en la tienda. */
declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Semantic\FeedEligibility;
use Aifec\Modules\Semantic\Semantic;

final class AcpFeed
{
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** La elegibilidad de OpenAI depende de la activación del comercio y de las políticas publicadas. */
    public static function checkoutPolicies(App $app): array
    {
        if (!AcpController::enabled($app) || !$app->settings()->bool('semantic.openai.enable_checkout', false)
            || !$app->settings()->bool('semantic.openai.enable_search', true) || !$app->router()->has('checkout')) {
            return [];
        }
        $links = array_column(Ucp::links($app), 'url', 'type');
        if (!isset($links['terms_of_service'], $links['privacy_policy'])) {
            return [];
        }
        return ['seller_tos' => $links['terms_of_service'], 'seller_privacy_policy' => $links['privacy_policy']];
    }

    public function serve(Request $request, array $params): Response
    {
        $app = $this->app;
        require_once $app->root() . '/modules/semantic/src/Semantic.php';
        require_once $app->root() . '/modules/semantic/src/FeedEligibility.php';
        $semantic = new Semantic($app);
        $catalog = $app->service('catalog');
        $policies = self::checkoutPolicies($app);
        $search = $app->settings()->bool('semantic.openai.enable_search', true);
        return Response::stream(static function (callable $write) use ($app, $semantic, $catalog, $policies, $search): void {
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
                        $row = [
                            'item_id' => (string) $product['id'] . ($id ? '-' . $id : ''),
                            'title' => $semantic->plain($title . ($id && !empty($variant['label']) ? ' - ' . $variant['label'] : ''), 150),
                            'description' => $semantic->plain((string) ($product['description'] ?: $title), 5000) ?: $title,
                            'url' => (string) $product['url_abs'],
                            'image_url' => $images[0],
                            'additional_image_urls' => array_slice($images, 1),
                            'price' => $semantic->decimal($regular !== null && $regular > $amount ? $regular : $amount) . ' ' . $semantic->currency(),
                            'sale_price' => $regular !== null && $regular > $amount ? $semantic->decimal($amount) . ' ' . $semantic->currency() : '',
                            'availability' => empty($offer['available']) ? 'out_of_stock' : (isset($offer['stock']) && $offer['stock'] <= 0 ? 'backorder' : 'in_stock'),
                            'seller_name' => $app->settings()->str('store.name', $app->request()->host),
                            'brand' => (string) ($product['brand']['name'] ?? ''),
                            'gtin' => (string) ($variant['gtin'] ?? $product['gtin'] ?? ''),
                            'mpn' => (string) ($product['mpn'] ?? ''),
                            'condition' => (string) ($product['condition'] ?? ''),
                            'product_category' => $semantic->categoryPath($product),
                            'group_id' => $id ? (string) $product['id'] : '',
                            'store_country' => $semantic->country(),
                            'target_countries' => [$semantic->country()],
                            'is_eligible_search' => $search,
                            'is_eligible_checkout' => $policies !== [] && !empty($offer['available']),
                        ] + $policies;
                        $write(json_encode(array_filter($row, static function ($value) { return $value !== '' && $value !== []; }), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    }
                }
            } while ($page++ < (int) ($listing['pages'] ?? 1));
        }, ['Content-Type' => 'application/x-ndjson; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
