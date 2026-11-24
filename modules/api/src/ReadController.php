<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La API de lectura, sin clave: la tienda, las categorías, los productos, la búsqueda, la disponibilidad y el coste de envío.
 * Cada ruta llama a la herramienta que corresponde del servicio «tools» (las mismas que usan el asistente y el MCP) y devuelve su
 * resultado; el listado de productos, que necesita páginas, usa el mismo catálogo y el mismo buscador que la herramienta.
 */
final class ReadController
{
    private const SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'name', 'best'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $app = $this->app;
        $info = Agent::tool($app, 'store_info', ['topic' => 'all']);
        // La nota es una instrucción para el asistente, en inglés: la API contesta en el idioma del sitio y ya lleva returns_payer.
        unset($info['returns_payer_note']);
        $url = $app->url();
        $links = ['home' => $url->abs('/'), 'cart' => $app->router()->has('cart') ? Present::abs($app, $url->route('cart')) : null,
            'checkout' => $app->router()->has('checkout') ? Present::abs($app, $url->route('checkout')) : null, 'openapi' => $url->abs('/api/openapi.json', [], ''),
            'docs' => $url->abs('/api', [], ''), 'mcp' => $app->modules()->isLoaded('mcp') ? $url->abs('/mcp', [], '') : null,
            'llms' => $app->modules()->isLoaded('semantic') ? $url->abs('/llms.txt', [], '') : null];

        return Agent::json($info + [
            'currency' => Present::currency($app),
            'languages' => array_values($app->languages()),
            'default_language' => $app->defaultLang(),
            'links' => $links,
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function categories(Request $request, array $params): Response
    {
        $result = Agent::tool($this->app, 'list_categories');

        return Agent::json(isset($result['categories']) ? $result['categories'] : []);
    }

    /**
     * El listado de productos con páginas. Con «q», primero pregunta al buscador si lo encontrado convence: si no, lista vacía.
     *
     * @param array<string,string> $params
     */
    public function products(Request $request, array $params): Response
    {
        $app = $this->app;
        $sort = Agent::textParam($request, 'sort', 20);
        if ($sort !== '' && !in_array($sort, self::SORTS, true)) {
            throw HttpError::badRequest('The parameter sort must be one of: ' . implode(', ', self::SORTS));
        }
        $page = Agent::intParam($request, 'page', 1, 1, 100000);
        $perPage = Agent::intParam($request, 'per_page', 24, 1, 48);
        // «category_id» vale igual: así la llama la herramienta search_products del MCP (14/11/2026: un agente la usó en la API y
        // recibió el catálogo entero sin filtrar).
        $category = Agent::intParam($request, 'category', 0, 0, 2147483647) ?: Agent::intParam($request, 'category_id', 0, 0, 2147483647);
        $query = ['lang' => $app->lang(), 'page' => $page, 'per_page' => $perPage];
        if ($sort !== '') {
            $query['sort'] = $sort;
        }
        if ($category > 0) {
            $query['category_id'] = $category;
        }
        if (Agent::flag($request, 'in_stock')) {
            $query['in_stock'] = true;
        }

        $q = Agent::textParam($request, 'q', 120);
        $corrected = '';
        $empty = false;
        if ($q !== '') {
            $shown = $q;
            if ($app->has('search')) {
                // El buscador dice si lo encontrado convence: si no, es mejor decir «nada» que enseñar ruido.
                $found = $app->service('search')->query($q, ['lang' => $app->lang(), 'limit' => 200]);
                if (!is_array($found) || empty($found['confident']) || empty($found['ids'])) {
                    $empty = true;
                } elseif (!empty($found['corrected'])) {
                    $corrected = (string) $found['corrected'];
                    $shown = $corrected;
                }
            }
            $query['q'] = $shown;
        }

        $items = [];
        // per_page por encima de 48 se recorta (no es un error, como sí lo es un orden que no existe): el máximo va en la respuesta.
        $meta = ['page' => $page, 'per_page' => $perPage, 'per_page_max' => 48, 'total' => 0, 'pages' => 0, 'sort' => $sort !== '' ? $sort : 'relevance'];
        if (!$empty) {
            $listing = $app->service('catalog')->listing($query);
            foreach (isset($listing['items']) && is_array($listing['items']) ? $listing['items'] : [] as $card) {
                if (is_array($card) && isset($card['id'])) {
                    $items[] = Present::card($app, $card);
                }
            }
            $meta['total'] = isset($listing['total']) ? (int) $listing['total'] : count($items);
            $meta['pages'] = isset($listing['pages']) ? (int) $listing['pages'] : ($meta['total'] > 0 ? (int) ceil($meta['total'] / $perPage) : 0);
            if (isset($listing['sort']) && is_string($listing['sort']) && $listing['sort'] !== '') {
                $meta['sort'] = $listing['sort'];
            }
        }
        if ($corrected !== '') {
            $meta['corrected'] = $corrected;
        }

        return Agent::json($items, 200, $meta);
    }

    /**
     * @param array<string,string> $params id
     */
    public function product(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $p = $this->published($id);
        $tool = Agent::tool($this->app, 'get_product', ['id' => $id]);

        return Agent::json(Present::product($this->app, $tool, $p));
    }

    /**
     * @param array<string,string> $params id
     */
    public function availability(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $this->published($id);
        $input = ['product_id' => $id, 'qty' => Agent::intParam($request, 'qty', 1, 1, 999)];
        $variant = Agent::intParam($request, 'variant_id', 0, 0, 2147483647);
        if ($variant > 0) {
            $input['variant_id'] = $variant;
        }

        return Agent::json(Agent::tool($this->app, 'check_availability', $input));
    }

    /**
     * @param array<string,string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $q = Agent::textParam($request, 'q', 120);
        if ($q === '') {
            throw HttpError::badRequest('The parameter q is required: what the customer is looking for');
        }
        if ($q === 'WORDS') {
            // El ejemplo de la guía abierto TAL CUAL: lo hace el lector que solo abre direcciones escritas (17/11/2026, Claude-User),
            // que no puede buscar. Su camino es el catálogo por enlaces, y no se gasta una búsqueda (ni la IA) en «WORDS».
            return Agent::json([], 200, ['query' => $q, 'total' => 0, 'answer_kind' => 'none',
                'note' => 'This is the example address, opened as it is, so nothing was searched. The catalogue is also reachable by links: '
                    . $this->app->url()->abs('/llms.txt', [], '') . ' has every category, and each category page lists its products, each with the address that puts it in a cart, already written.']);
        }
        $input = ['query' => $q, 'limit' => Agent::intParam($request, 'limit', 5, 1, 8)];
        // «category_id» vale igual: así la llama la herramienta search_products del MCP (14/11/2026: un agente la usó en la API y
        // recibió el catálogo entero sin filtrar).
        $category = Agent::intParam($request, 'category', 0, 0, 2147483647) ?: Agent::intParam($request, 'category_id', 0, 0, 2147483647);
        if ($category > 0) {
            $input['category_id'] = $category;
        }
        $max = Agent::intParam($request, 'max_price_cents', 0, 0, 100000000);
        if ($max > 0) {
            $input['max_price_cents'] = $max;
        }
        if (Agent::flag($request, 'in_stock')) {
            $input['in_stock_only'] = true;
        }
        $result = Agent::tool($this->app, 'search_products', $input);
        $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
        unset($result['items']);
        // Con dos palabras o más que solo casan por la descripción larga (ninguna por el nombre, la marca, la categoría…), el resultado no es
        // una respuesta exacta: es lo más parecido (verificación del 24/11/2026: «pasarela de pago» salía «exact» con una ficha que lo dice
        // a mitad de su descripción).
        if (($result['answer_kind'] ?? '') === 'exact' && $items !== [] && $this->app->has('search')) {
            $found = $this->app->service('search')->query($q, ['lang' => $this->app->lang(), 'limit' => 200]);
            if (is_array($found) && !empty($found['weak']) && isset($found['terms']) && (int) $found['terms'] >= 2) {
                $result['answer_kind'] = 'related';
                $result['note'] = 'These are the closest products: none of them has the words of the search in its name, brand or category, only in its long description.';
                foreach ($items as $i => $one) {
                    if (is_array($one)) {
                        $items[$i]['answer_kind'] = 'related';
                    }
                }
            }
        }
        // «total» cuenta todo lo que encaja; «data» lleva como mucho «limit» (cinco de serie, ocho como máximo). Se dice, para que
        // nadie crea que faltan resultados: con «has_more» se pide más con «limit» (hasta 8) o se afina la búsqueda.
        $total = isset($result['total']) ? (int) $result['total'] : count($items);
        $result['limit'] = $input['limit'];
        $result['limit_max'] = 8;
        $result['returned'] = count($items);
        $result['has_more'] = $total > count($items);
        // Las notas salen en el idioma de la tienda (verificación del 24/11/2026: salían en inglés).
        if (isset($result['note']) && is_string($result['note'])) {
            $result['note'] = t($result['note']);
        }
        if ($result['has_more']) {
            $result['note'] = (isset($result['note']) && is_string($result['note']) ? $result['note'] . ' ' : '')
                . t('The total counts everything that matches; data holds at most limit products (limit goes up to 8). Refine the search or ask with a bigger limit to see the rest.');
        }

        return Agent::json($items, 200, $result);
    }

    /**
     * @param array<string,string> $params
     */
    public function shippingQuote(Request $request, array $params): Response
    {
        $product = Agent::intParam($request, 'product_id', 0, 0, 2147483647);
        if ($product < 1) {
            throw HttpError::badRequest('The parameter product_id is required');
        }
        $this->published($product);
        $postcode = Agent::textParam($request, 'postcode', 12);
        $country = Agent::textParam($request, 'country', 2);
        if ($postcode === '' && $country === '') {
            throw HttpError::badRequest('Give a postcode or a country to quote the shipping');
        }
        $input = ['product_id' => $product, 'qty' => Agent::intParam($request, 'qty', 1, 1, 999)];
        if ($postcode !== '') {
            $input['postcode'] = $postcode;
        }
        if ($country !== '') {
            $input['country'] = $country;
        }
        $variant = Agent::intParam($request, 'variant_id', 0, 0, 2147483647);
        if ($variant > 0) {
            $input['variant_id'] = $variant;
        }

        return Agent::json(Agent::tool($this->app, 'shipping_quote', $input));
    }

    /**
     * El producto publicado, o un 404 (un borrador no existe para quien consulta).
     *
     * @return array<string,mixed>
     */
    private function published(int $id): array
    {
        $p = $this->app->service('catalog')->product($id, $this->app->lang());
        if (!is_array($p)) {
            throw HttpError::notFound('Product not found');
        }

        return $p;
    }
}
