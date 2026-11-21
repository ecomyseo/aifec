<?php
/**
 * AIFEC — catálogo UCP sobre los precios y existencias vigentes de la tienda.
 * @author Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);
namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class UcpCatalog
{
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function search(Request $request, array $params): Response
    {
        $body = $this->body($request);
        $query = isset($body['query']) && is_string($body['query']) ? mb_substr(trim($body['query']), 0, 160) : '';
        $limit = min(24, max(1, (int) ($body['pagination']['limit'] ?? 10)));
        $page = 1;
        if (isset($body['pagination']['cursor'])) {
            $cursor = base64_decode((string) $body['pagination']['cursor'], true);
            if ($cursor === false || preg_match('/^page:(\d{1,5})$/D', $cursor, $m) !== 1) {
                throw new HttpError(400, 'Invalid cursor');
            }
            $page = max(1, (int) $m[1]);
        }
        $filters = ['per_page' => $limit, 'page' => $page];
        foreach (['min', 'max'] as $bound) {
            $value = $body['filters']['price'][$bound] ?? null;
            if (is_int($value) && $value >= 0) {
                $filters['price_' . $bound] = $value;
            }
        }
        $listingQuery = $filters + ['q' => $query, 'lang' => $this->app->lang()];
        if ($query === '' && !isset($filters['price_min']) && !isset($filters['price_max'])) {
            return Response::json(['ucp' => $this->envelope('search'), 'products' => [],
                'pagination' => ['has_next_page' => false, 'total_count' => 0]]);
        }
        if ($query !== '' && $this->app->has('search')) {
            $search = $this->app->service('search');
            if (is_callable([$search, 'asker'])) {
                $asker = $search->asker();
                // Las mismas reglas del buscador público, sin inferencia ni efectos de IA de pago.
                $plan = $asker->plan($query, $this->app->lang(), false, false);
                $listingQuery = $asker->catalogQuery($plan, $filters);
            }
        }
        // UCP no distingue un resultado exacto de una alternativa en la respuesta de catálogo.
        $listingQuery['require_all_terms'] = true;
        $listing = $this->app->service('catalog')->listing($listingQuery);
        $products = [];
        foreach ($listing['items'] as $card) {
            $product = $this->product((int) $card['id']);
            if ($product !== null) {
                $products[] = $product;
            }
        }
        $hasNext = $page < (int) $listing['pages'];
        $pagination = ['has_next_page' => $hasNext, 'total_count' => (int) $listing['total']];
        if ($hasNext) {
            $pagination['next_cursor'] = base64_encode('page:' . ($page + 1));
        }

        return Response::json(['ucp' => $this->envelope('search'), 'products' => $products, 'pagination' => $pagination]);
    }

    public function lookup(Request $request, array $params): Response
    {
        $body = $this->body($request);
        $ids = $body['ids'] ?? (isset($body['id']) ? [$body['id']] : []);
        if (!is_array($ids) || $ids === [] || count($ids) > 50) {
            throw new HttpError(400, 'Provide between 1 and 50 product identifiers');
        }
        $products = [];
        foreach ($ids as $input) {
            if (!is_string($input) || preg_match('/^(\d{1,12})(?:-(\d{1,12}))?$/D', $input, $m) !== 1) {
                continue;
            }
            $id = (int) $m[1];
            $product = $this->product($id);
            if ($product === null) {
                continue;
            }
            $previous = $products[(string) $id]['variants'] ?? [];
            $found = array_column($previous, null, 'id');
            foreach ($product['variants'] as $variant) {
                if (isset($m[2]) && $variant['id'] !== $input) {
                    continue;
                }
                $variant['inputs'] = $found[$variant['id']]['inputs'] ?? [];
                $variant['inputs'][] = ['id' => $input, 'match' => $variant['id'] === $input ? 'exact' : 'featured'];
                $found[$variant['id']] = $variant;
            }
            if ($found !== []) {
                $product['variants'] = array_values($found);
                $products[(string) $id] = $product;
            }
        }
        if (substr($request->path, -8) === '/product') {
            $product = $products !== [] ? reset($products) : null;
            if ($product === null) {
                throw new HttpError(404, 'Product not found');
            }

            return Response::json(['ucp' => $this->envelope('lookup'), 'product' => $product]);
        }

        return Response::json(['ucp' => $this->envelope('lookup'), 'products' => array_values($products)]);
    }

    private function product(int $id): ?array
    {
        $catalog = $this->app->service('catalog');
        $p = $catalog->product($id);
        if ($p === null || empty($p['availability']['show_price'])) {
            return null;
        }
        $description = ['plain' => trim(strip_tags((string) $p['description']))];
        $variants = [];
        $prices = [];
        $source = !empty($p['variants']) && is_array($p['variants']) ? $p['variants'] : [['id' => 0, 'label' => $p['name']]];
        foreach ($source as $v) {
            $variantId = (int) $v['id'];
            $buyable = $catalog->purchasable($id, $variantId);
            if ($buyable === null) {
                continue;
            }
            $price = $catalog->price($buyable);
            $amount = (int) ($price['amount'] ?? $buyable['unit_price']);
            $currency = (string) ($price['currency'] ?? $this->app->settings()->str('shop.currency', 'EUR'));
            $prices[] = $amount;
            $available = !empty($buyable['available']);
            $stock = $buyable['stock'] ?? null;
            $availability = ['available' => $available, 'status' => $available
                ? ($stock !== null && (int) $stock <= 0 ? 'backorder' : 'in_stock')
                : ($stock !== null && (int) $stock <= 0 ? 'out_of_stock' : 'unavailable')];
            $variants[] = ['id' => (string) $id . ($variantId > 0 ? '-' . $variantId : ''), 'title' => (string) ($v['label'] ?: $p['name']),
                'description' => $description, 'price' => ['amount' => $amount, 'currency' => $currency], 'availability' => $availability, 'url' => (string) $p['url_abs']];
        }
        if ($variants === []) {
            return null;
        }
        $currency = $variants[0]['price']['currency'];

        return ['id' => (string) $id, 'title' => (string) $p['name'], 'description' => $description, 'url' => (string) $p['url_abs'],
            'price_range' => ['min' => ['amount' => min($prices), 'currency' => $currency], 'max' => ['amount' => max($prices), 'currency' => $currency]], 'variants' => $variants];
    }

    private function envelope(string $operation): array
    {
        return ['version' => Ucp::version($this->app), 'status' => 'success', 'capabilities' => ['dev.ucp.shopping.catalog.' . $operation => [['version' => Ucp::version($this->app)]]]];
    }

    private function body(Request $request): array
    {
        $body = json_decode($request->body, true);
        if (!is_array($body)) {
            throw new HttpError(400, 'The body must be a JSON object');
        }

        return $body;
    }
}
