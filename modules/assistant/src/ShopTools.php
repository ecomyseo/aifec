<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

use Aifec\Ai\Prompt;
use Aifec\Geo\Spain;
use Aifec\Kernel\App;

/**
 * Las herramientas de serie de la tienda. Todo lo que devuelven sale de los servicios (catálogo, buscador, envíos,
 * carrito, pedidos): ninguna recibe un precio ni lo calcula. Los importes viajan dos veces, en céntimos (para quien
 * calcula) y ya formateados (para quien enseña). Las direcciones salen completas, con el dominio de la tienda.
 *
 * Las descripciones y los mensajes de ayuda para el modelo van en inglés a propósito: no los lee el cliente.
 */
final class ShopTools
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array
    {
        $object = static function (array $props, array $required = []): array {
            $schema = ['type' => 'object', 'properties' => $props, 'additionalProperties' => false];
            if ($required !== []) {
                $schema['required'] = $required;
            }

            return $schema;
        };
        $none = $object([]);

        return [
            'search_products' => [
                'description' => 'Search the shop catalogue. Give words (query), a category, a maximum price in cents or only products in stock. Returns up to 8 products (id, name, price_formatted, available, summary, options such as colour or size) and answer_kind: exact (they match), relaxed or related (NOT exact: only the closest, say so), shop (a question about the shop, see shop_answer) or none (nothing matches: say so, never offer unrelated products).',
                'schema' => $object([
                    'query' => ['type' => 'string', 'maxLength' => 120, 'description' => 'What to look for: the customer\'s words or, for a vague need, a product type or synonym of your own that fits'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 5, 'description' => 'How many products to return, from 1 to 8'],
                    'category_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only products of this category (see list_categories)'],
                    'max_price_cents' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000000, 'description' => 'Maximum price in cents: 5000 means 50.00'],
                    'in_stock_only' => ['type' => 'boolean', 'default' => false, 'description' => 'true: only products that can be bought now'],
                ]),
                'handler' => [$this, 'searchProducts'],
                'writes' => false,
            ],
            'get_product' => [
                'description' => 'Everything the shop says about one product: description in plain text, key facts, the options (colour, size) with their available values, variants with price and availability, delivery time and frequently asked questions. Call it whenever the customer asks about a product ("tell me about it", "what is it", "features") and answer with what it says.',
                'schema' => $object(['id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The id of the product, from search_products']], ['id']),
                'handler' => [$this, 'getProduct'],
                'writes' => false,
            ],
            'compare_products' => [
                'description' => 'Compare 2 to 4 products side by side: for each one its price, availability, summary and key attributes, plus the attributes in which they differ. Use it when the customer asks to compare products or which one to choose, and state only what its fields say (price, stock and the listed differences), never adjectives of your own.',
                'schema' => $object([
                    'ids' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 4, 'items' => ['type' => 'integer', 'minimum' => 1]],
                ], ['ids']),
                'handler' => [$this, 'compareProducts'],
                'writes' => false,
            ],
            'list_categories' => [
                'description' => 'The product categories of the shop with their sub-categories (what the shop sells), and popular: the categories of the best sellers, first the one that sells most.',
                'schema' => $none,
                'handler' => [$this, 'listCategories'],
                'writes' => false,
            ],
            'check_availability' => [
                'description' => 'Whether a product (or one of its variants) can be bought in a given quantity right now, and what that quantity costs: unit_price and total (unit price × quantity, calculated by the shop). When the customer asks how much several units cost, call it with that qty and say its total: never multiply yourself.',
                'schema' => $object([
                    'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The id of the product, from search_products'],
                    'variant_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'The id of the variant (colour, size…), from get_product; 0 for a product without variants'],
                    'qty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999, 'default' => 1, 'description' => 'How many units'],
                ], ['product_id']),
                'handler' => [$this, 'checkAvailability'],
                'writes' => false,
            ],
            'shipping_quote' => [
                'description' => 'Delivery options, prices and times to a destination. Give the postcode (and the 2-letter country if it is not the shop\'s own). Quotes the product given, or the current cart when no product is given.',
                'schema' => $object([
                    'country' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 2, 'description' => 'ISO 3166-1 alpha-2 code, for example ES'],
                    'postcode' => ['type' => 'string', 'maxLength' => 12, 'description' => 'The postcode of the delivery address'],
                    'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The id of the product, from search_products'],
                    'variant_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'The id of the variant (colour, size…), from get_product; 0 for a product without variants'],
                    'qty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999, 'default' => 1, 'description' => 'How many units'],
                ]),
                'handler' => [$this, 'shippingQuote'],
                'writes' => false,
            ],
            'store_info' => [
                'description' => 'About the shop itself and its policies: what the shop is and sells in its own words (about), returns (the deadline and the text of the returns page, with its link), shipping and terms pages, accepted payment methods, how the invoice reaches the customer (invoices), contact details and opening hours. Call it for any question about the shop that is not about a product. Say you do not know only when this returns nothing for the topic.',
                'schema' => $object([
                    'topic' => ['type' => 'string', 'enum' => ['all', 'about', 'returns', 'shipping', 'terms', 'payment', 'contact', 'hours'], 'default' => 'all', 'description' => 'What to tell about the shop; all gives everything'],
                ]),
                'handler' => [$this, 'storeInfo'],
                'writes' => false,
            ],
            'cart_view' => [
                'description' => 'The customer\'s cart: lines, quantities, prices and totals, as the shop calculates them.',
                'schema' => $none,
                'handler' => [$this, 'cartView'],
                'writes' => false,
            ],
            'cart_add' => [
                'description' => 'Add units of a product to the customer\'s cart. The price is always the shop\'s own; it cannot be given. A product with variants needs its variant_id: without it you get need=options with the choices (the shop shows them as buttons): ask which one the customer wants and call again with that variant_id. If the product is already in the cart it returns already_in_cart and adds nothing (unless the customer asked for more in this message): to change how many there are, use cart_update with the exact number.',
                'schema' => $object([
                    'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The id of the product, from search_products'],
                    'variant_id' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'The id of the variant (colour, size…), from get_product; 0 for a product without variants'],
                    'qty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99, 'default' => 1, 'description' => 'How many units to add'],
                ], ['product_id']),
                'handler' => [$this, 'cartAdd'],
                'writes' => true,
            ],
            'cart_update' => [
                'description' => 'Change the quantity of a cart line (the key comes from cart_view). Quantity 0 removes the line.',
                'schema' => $object([
                    'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80, 'description' => 'The key of the cart line, from cart_view'],
                    'qty' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99, 'description' => 'The new number of units; 0 removes the line'],
                ], ['key', 'qty']),
                'handler' => [$this, 'cartUpdate'],
                'writes' => true,
            ],
            'checkout_link' => [
                'description' => 'The address of the checkout page with the customer\'s cart, where they review and pay. Payment always happens there.',
                'schema' => $none,
                'handler' => [$this, 'checkoutLink'],
                'writes' => false,
            ],
            'order_status' => [
                'description' => 'Status, shipping and tracking of an order (never its address, name or other personal data). Needs the order number and the email used in the order; if they do not match an order nothing is revealed. The personal data of an order are only shown in My account to whoever is signed in with that account: never ask for an email to show them.',
                'schema' => $object([
                    'number' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 32],
                    'email' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 191],
                ], ['number', 'email']),
                'handler' => [$this, 'orderStatus'],
                'writes' => false,
            ],
        ];
    }

    // ------------------------------------------------------------------ catálogo

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function searchProducts(array $in, array $ctx): array
    {
        $budget = (int) ($ctx['saved_budget'] ?? 0);
        if (!empty($ctx['conversation_id']) && $budget > 0) {
            $in['max_price_cents'] = !empty($in['max_price_cents']) ? min($budget, (int) $in['max_price_cents']) : $budget;
        }
        $out = $this->findProducts($in, $ctx);
        $q = isset($in['query']) ? trim((string) $in['query']) : '';
        // Lo que el buscador encuentra sin que ningún nombre lleve lo pedido lo mira la IA (FitCheck): un guiso no es «aceite de
        // oliva». Cuando el modelo del asistente lleva el turno, ya juzga él.
        $check = new FitCheck($this->app);
        if ($q === '' || !empty($ctx['by_model']) || !$check->needed($q, $out)) {
            return $out;
        }
        $limit = isset($in['limit']) ? max(1, min(8, (int) $in['limit'])) : 5;
        // Más candidatos a la vez: los de la frase entendida por la IA del buscador, solo si esa búsqueda no se hizo ya (las reglas
        // acertaron a la primera; si no, askStages ya la hizo y no se repite).
        $more = [];
        if ($out['answer_kind'] === 'exact' && empty($out['ai_used'])) {
            try {
                $wide = $this->searchWithAsk($q, $in, 8, true);
                $more = isset($wide['items']) && is_array($wide['items']) ? $wide['items'] : [];
            } catch (\Throwable $e) {
                $more = [];
            }
        }
        $checked = $check->apply($q, $out, $more);
        if (isset($checked['items']) && is_array($checked['items']) && $checked['items'] !== []) {
            $checked['items'] = array_slice(self::buyableFirst($checked['items'], self::wantsCheapest($q)), 0, $limit);
        }

        return $checked;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    private function findProducts(array $in, array $ctx): array
    {
        $app = $this->app;
        $q = isset($in['query']) ? trim((string) $in['query']) : '';
        $category = isset($in['category_id']) ? (int) $in['category_id'] : 0;
        if ($q === '' && $category <= 0) {
            return ['error' => t('Tell me what you are looking for')];
        }
        $limit = isset($in['limit']) ? max(1, min(8, (int) $in['limit'])) : 5;
        $asked = null;
        // Las referencias técnicas del título (DIN 934, por ejemplo) pueden parecer medidas
        // al analizador. La ruta canónica permite comprobar el nombre completo con una lectura indexada.
        if ($category === 0 && $q !== '' && preg_match('/\d/', $q) && str_word_count($q) >= 3 && $app->has('catalog')) {
            $slug = \Aifec\Util\Str::slug($q);
            $prefix = \Aifec\Modules\Catalog\Admin\TaxonomySlugs::prefix($app, 'product');
            $match = $app->slugs()->resolve($app->lang(), $prefix . '/' . $slug);
            if ($match !== null && $match['type'] === 'product' && $match['canonical']) {
                $cards = $this->listCards([(int) $match['entity_id']]);
                foreach ($cards as $card) {
                    if (\Aifec\Util\Str::slug((string) $card['name']) !== $slug) {
                        continue;
                    }
                    $items = $this->items([$card], [], 'exact');
                    if ($items !== [] && (empty($in['max_price_cents']) || $items[0]['price_cents'] <= (int) $in['max_price_cents'])
                        && (empty($in['in_stock_only']) || !empty($items[0]['available']))) {
                        return ['query' => $q, 'total' => 1, 'items' => $items, 'answer_kind' => 'exact'];
                    }
                }
            }
        }
        if ($q !== '' && $app->has('search') && method_exists($app->service('search'), 'ask')) {
            // Se busca como busca la tienda: Search::ask() entiende la frase, el idioma, las erratas, «barato» y «menos de 80». Primero
            // solo con reglas (rápido); si no acierta, otra vez con la IA del buscador antes de decir que no hay nada.
            // Cuando el modelo del asistente lleva el turno, la IA del buscador se puede llamar UNA vez por respuesta (la primera
            // búsqueda que no acierta: quien escribe en otro idioma o con erratas); en las demás solo vale lo que tenga guardado.
            $saved = !empty($ctx['by_model']);
            $asked = $this->askStages($q, $in, $limit, $saved);
            if (!empty($ctx['widen']) && $asked['answer_kind'] !== 'shop' && count($asked['items']) < self::NEARBY_UNDER) {
                // El modelo busca con adjetivos («zapato elegante hombre») y el buscador exige todas las palabras: salían dos
                // zapatillas de casa que dicen «elegante» y no el zapato de vestir (13/11/2026, en vivo). Con pocos resultados se le
                // dan además los que casan sin una de las palabras, marcados como candidatos: quien escribe decide si encajan.
                $near = $this->nearby($q, $in, $limit, $asked['items']);
                if ($near !== []) {
                    $asked['nearby'] = $near;
                    $asked['nearby_note'] = 'Not exact matches: products that match the search without one of its words (see "without"). They are candidates: recommend one only if it really fits what the customer needs.';
                }
            }
            if ($asked['items'] === [] && $asked['answer_kind'] !== 'shop' && (!isset($ctx['own_words']) || $ctx['own_words'] !== false)) {
                // El modelo resumió mal lo que pidió el cliente: con sus propias palabras el buscador sí encuentra. Salvo cuando el
                // mensaje no nombra ningún producto («soy hombre», «otra cosa»): eso no se busca tal cual.
                $own = isset($ctx['message']) && is_string($ctx['message']) ? trim(mb_substr($ctx['message'], 0, 200, 'UTF-8')) : '';
                if ($own !== '' && RuleBrain::norm($own) !== RuleBrain::norm($q)) {
                    $again = $this->askStages($own, $in, $limit, $saved || !empty($asked['ai_used']));
                    if ($again['items'] !== []) {
                        $again['query'] = $q;
                        if (!empty($asked['ai_used'])) {
                            $again['ai_used'] = true;
                        }

                        return $again;
                    }
                }
            }
            if ($category <= 0 || $asked['items'] !== [] || $asked['answer_kind'] === 'shop') {
                return $asked;
            }
        }
        $lang = $app->lang();
        $query = ['lang' => $lang, 'page' => 1, 'per_page' => $limit];
        if ($category > 0) {
            $query['category_id'] = $category;
        }
        if (!empty($in['max_price_cents'])) {
            $query['price_max'] = (int) $in['max_price_cents'];
        }
        if (!empty($in['in_stock_only'])) {
            $query['in_stock'] = true;
        }
        $corrected = '';
        if ($q !== '') {
            $shown = $q;
            if ($app->has('search')) {
                // El buscador dice si lo encontrado convence: si no, es mejor decir «nada» que enseñar ruido.
                $found = $app->service('search')->query($q, ['lang' => $lang, 'limit' => 200]);
                if (!is_array($found) || empty($found['confident']) || empty($found['ids'])) {
                    return ['query' => $q, 'total' => 0, 'items' => [], 'answer_kind' => 'none', 'note' => 'Nothing in the catalogue matches this search.'];
                }
                if (!empty($found['corrected'])) {
                    $corrected = (string) $found['corrected'];
                    $shown = $corrected;
                }
            }
            $query['q'] = $shown;
        }
        $catalog = $app->service('catalog');
        $listing = $catalog->listing($query);
        $cards = isset($listing['items']) && is_array($listing['items']) ? $listing['items'] : [];
        $items = self::buyableFirst($this->items($cards, [], 'exact'), $q !== '' && self::wantsCheapest($q));
        $out = ['query' => $q, 'total' => isset($listing['total']) ? (int) $listing['total'] : count($items), 'items' => $items, 'answer_kind' => $items === [] ? 'none' : 'exact'];
        if ($corrected !== '') {
            $out['corrected'] = $corrected;
        }
        if ($items === []) {
            $out['note'] = 'Nothing in the catalogue matches this search.';
        }

        return $out;
    }

    /** Con menos resultados exactos que estos, una búsqueda del modelo se ensancha. */
    private const NEARBY_UNDER = 3;

    /** Candidatos que se añaden al ensanchar, como mucho. */
    private const NEARBY_MAX = 6;

    /**
     * Los productos que casan con la consulta sin una de sus palabras (solo con reglas, sin IA), por turnos: uno de cada variante.
     * Solo para consultas de tres a cinco palabras; nunca repite los que ya salieron.
     *
     * @param array<string,mixed>            $in
     * @param array<int,array<string,mixed>> $have lo que ya encontró la búsqueda
     *
     * @return array<int,array<string,mixed>> con el formato de los resultados, answer_kind «related» y «without» (la palabra quitada)
     */
    private function nearby(string $q, array $in, int $limit, array $have): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], static function (string $word): bool {
            return mb_strlen($word, 'UTF-8') >= 3;
        }));
        if (count($words) < 3 || count($words) > 5) {
            return [];
        }
        $seen = [];
        foreach ($have as $item) {
            if (is_array($item) && isset($item['id'])) {
                $seen[(int) $item['id']] = true;
            }
        }
        $groups = [];
        foreach ($words as $i => $dropped) {
            $rest = $words;
            unset($rest[$i]);
            $found = $this->searchWithAsk(implode(' ', $rest), $in, $limit, false);
            if ($found['answer_kind'] !== 'exact') {
                continue;
            }
            $fresh = [];
            foreach ($found['items'] as $item) {
                if (!isset($seen[(int) $item['id']])) {
                    $fresh[] = $item;
                }
            }
            if ($fresh !== []) {
                $groups[] = ['without' => $dropped, 'items' => self::buyableFirst($fresh, false)];
            }
        }
        // Por turnos, uno de cada variante: si se agotara primero una sola («zapato elegante boda», cinco zapatos de mujer), la que
        // traía lo que hacía falta («zapato hombre boda») se quedaba fuera del tope (13/11/2026, en vivo).
        $out = [];
        for ($round = 0; $round < self::NEARBY_MAX && count($out) < self::NEARBY_MAX; ++$round) {
            $any = false;
            foreach ($groups as $group) {
                if (!isset($group['items'][$round])) {
                    continue;
                }
                $any = true;
                $item = $group['items'][$round];
                $id = (int) $item['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $item['answer_kind'] = 'related';
                $item['without'] = (string) $group['without'];
                $out[] = $item;
                if (count($out) >= self::NEARBY_MAX) {
                    break;
                }
            }
            if (!$any) {
                break;
            }
        }

        return $out;
    }

    /**
     * La búsqueda en dos pasos: con reglas, que es lo más rápido y acierta casi siempre, y, si no sale nada exacto, otra vez dejando que
     * el buscador use su IA para entender la frase (quien escribe en inglés, «lampara pa estudiar barata»). Gana el paso que mejor
     * contesta; con «lo más barato» se enseña primero el de menor precio.
     *
     * @param array<string,mixed> $in
     *
     * @return array<string,mixed>
     */
    private function askStages(string $q, array $in, int $limit, bool $savedOnly = false): array
    {
        $cheap = self::wantsCheapest($q);
        $fetch = $cheap || $this->featuredRanks() !== [] ? max($limit, 24) : $limit;
        $out = $this->searchWithAsk($q, $in, $fetch, false);
        if ($out['answer_kind'] !== 'exact' && $out['answer_kind'] !== 'shop' && $this->aiReady()) {
            // $savedOnly: el modelo del asistente ya entiende la frase; del buscador solo se aprovecha lo que su IA tenga guardado.
            $second = $this->searchWithAsk($q, $in, $fetch, $savedOnly ? 'cached' : true);
            if (self::rank((string) $second['answer_kind']) > self::rank((string) $out['answer_kind'])) {
                $out = $second;
            }
            if (!$savedOnly) {
                // Se le ha dado al buscador la ocasión de llamar a su IA: quien lleva el turno lo cuenta (una por respuesta).
                $out['ai_used'] = true;
            }
        }
        $out['items'] = array_slice(self::buyableFirst(array_values($out['items']), $cheap), 0, $limit);

        return $out;
    }

    /**
     * Lo que se puede comprar va delante de lo agotado (que nunca se ofrece el primero); con «lo más barato», además, por precio
     * entre lo disponible. Entre iguales se conserva el orden del buscador.
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private static function buyableFirst(array $items, bool $cheap): array
    {
        $rows = [];
        foreach (array_values($items) as $i => $item) {
            $rows[] = [$i, $item];
        }
        usort($rows, static function (array $a, array $b) use ($cheap): int {
            $stock = (int) empty($a[1]['available']) <=> (int) empty($b[1]['available']);
            if ($stock !== 0) {
                return $stock;
            }
            if ($cheap) {
                $price = (int) (isset($a[1]['price_cents']) ? $a[1]['price_cents'] : 0) <=> (int) (isset($b[1]['price_cents']) ? $b[1]['price_cents'] : 0);
                if ($price !== 0) {
                    return $price;
                }
            }

            $featured = ($a[1]['featured_rank'] ?? PHP_INT_MAX) <=> ($b[1]['featured_rank'] ?? PHP_INT_MAX);
            return $featured !== 0 ? $featured : $a[0] <=> $b[0];
        });

        return array_column($rows, 1);
    }

    /** Posiciones elegidas por la tienda para las coincidencias entre los candidatos del buscador. */
    private function featuredRanks(): array
    {
        $ranks = [];
        foreach (explode(',', $this->app->settings()->str('assistant.featured_ids')) as $value) {
            $value = trim($value);
            if (ctype_digit($value) && (int) $value > 0 && !isset($ranks[(int) $value])) {
                $ranks[(int) $value] = count($ranks);
                if (count($ranks) === 10) {
                    break;
                }
            }
        }
        return $ranks;
    }

    /** ¿Pide lo más barato? («barata», «el más barato», «cheapest»): el orden por precio manda. */
    public static function wantsCheapest(string $text): bool
    {
        return preg_match('/\b(?:barat[oa]s?|mas barat[oa]s?|economic[oa]s?|cheap|cheaper|cheapest|lowest price|menor precio|precio mas bajo)\b/', RuleBrain::norm($text)) === 1;
    }

    private static function rank(string $kind): int
    {
        $ranks = ['exact' => 3, 'relaxed' => 2, 'related' => 1];

        return isset($ranks[$kind]) ? $ranks[$kind] : 0;
    }

    /** ¿Hay una IA encendida a la que dejar entender la frase? */
    private function aiReady(): bool
    {
        if (!$this->app->has('ai')) {
            return false;
        }
        $ai = $this->app->service('ai');

        return is_object($ai) && method_exists($ai, 'enabled') && (bool) $ai->enabled();
    }

    /**
     * La búsqueda con Search::ask(): los productos de lo que acierta (exact), de lo más parecido (relaxed, related) o ninguno.
     *
     * @param array<string,mixed> $in
     * @param bool|string         $ai si el buscador puede usar su IA para entender la frase: false (solo reglas), true (puede
     *                                llamarla) o 'cached' (solo lo que ya tenga guardado, sin llamar)
     *
     * @return array<string,mixed>
     */
    private function searchWithAsk(string $q, array $in, int $limit, $ai = false): array
    {
        $app = $this->app;
        $lang = $app->lang();
        $found = $app->service('search')->ask($q, ['lang' => $lang, 'limit' => $limit, 'ai' => $ai]);
        $found = is_array($found) ? $found : [];
        $kind = isset($found['answer_kind']) ? (string) $found['answer_kind'] : 'none';
        $out = ['query' => $q, 'total' => 0, 'items' => [], 'answer_kind' => 'none'];
        if ($kind === 'shop') {
            $out['answer_kind'] = 'shop';
            $out['shop_answer'] = (string) (isset($found['answer']) ? $found['answer'] : '');
            $out['note'] = 'This is a question about the shop, not a product search: answer with shop_answer.';

            return $out;
        }
        $products = [];
        if ($kind === 'exact') {
            $products = isset($found['products']) && is_array($found['products']) ? $found['products'] : [];
        } elseif ($kind === 'relaxed' && isset($found['relaxed']['products']) && is_array($found['relaxed']['products'])) {
            $products = $found['relaxed']['products'];
            $out['relaxed'] = ['without' => (string) $found['relaxed']['label'], 'total' => (int) $found['relaxed']['total']];
        } elseif ($kind === 'related' && isset($found['related']) && is_array($found['related'])) {
            $products = $found['related'];
        }
        // Lo que el cliente pidió con palabras claras de más: un precio tope y solo lo que hay en existencias.
        $max = !empty($in['max_price_cents']) ? (int) $in['max_price_cents'] : 0;
        $stock = !empty($in['in_stock_only']);
        $products = array_values(array_filter($products, static function ($product) use ($max, $stock): bool {
            return is_array($product) && ($max <= 0 || (int) $product['price'] <= $max) && (!$stock || !empty($product['available']));
        }));
        $products = array_slice($products, 0, $limit);
        if ($products === []) {
            $out['note'] = 'Nothing in the catalogue matches this search.';

            return $out;
        }
        $ids = [];
        foreach ($products as $product) {
            $ids[] = (int) $product['id'];
        }
        $extra = [];
        foreach ($products as $product) {
            $extra[(int) $product['id']] = $product;
        }
        $cards = $this->listCards($ids);
        $items = $this->items($cards, $extra, $kind);
        if ($items === []) {
            $out['note'] = 'Nothing in the catalogue matches this search.';

            return $out;
        }
        $out['answer_kind'] = $kind;
        $out['items'] = $items;
        $out['total'] = $kind === 'exact' ? max(count($items), (int) (isset($found['total']) ? $found['total'] : 0)) : count($items);
        if (!empty($found['understood']['corrected'])) {
            $out['corrected'] = (string) $found['understood']['corrected'];
        }
        if ($kind !== 'exact') {
            $out['note'] = 'These are NOT exact matches: they are only the closest. Say so, and show them only if they can really help.';
        }

        return $out;
    }

    /**
     * Las tarjetas del listado del catálogo de esos productos, en el orden dado.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function listCards(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $listing = $this->app->service('catalog')->listing(['lang' => $this->app->lang(), 'ids' => $ids, 'per_page' => max(1, count($ids)), 'page' => 1, 'sort' => 'relevance']);
        $cards = isset($listing['items']) && is_array($listing['items']) ? $listing['items'] : [];
        $position = array_flip($ids);
        usort($cards, static function (array $a, array $b) use ($position): int {
            return (isset($position[(int) $a['id']]) ? $position[(int) $a['id']] : 0) <=> (isset($position[(int) $b['id']]) ? $position[(int) $b['id']] : 0);
        });

        return $cards;
    }

    /**
     * Los productos de una búsqueda tal como los lee quien llama: lo de siempre más id, name, price_formatted, available, summary
     * (160 caracteres como mucho), options (los nombres de sus opciones: color, talla…) y answer_kind.
     *
     * @param array<int,array<string,mixed>> $cards listado del catálogo
     * @param array<int,array<string,mixed>> $extra lo que dio Search::ask() por id (summary, matches)
     *
     * @return array<int,array<string,mixed>>
     */
    private function items(array $cards, array $extra, string $kind): array
    {
        if ($cards === []) {
            return [];
        }
        $ids = [];
        foreach ($cards as $card) {
            $ids[] = (int) $card['id'];
        }
        $products = $this->app->service('catalog')->products($ids, $this->app->lang());
        $products = is_array($products) ? $products : [];
        $featured = $this->featuredRanks();
        $items = [];
        foreach ($cards as $card) {
            $id = (int) $card['id'];
            $item = $this->card($card);
            if ($kind === 'exact' && isset($featured[$id])) {
                $item['featured_rank'] = $featured[$id];
            }
            $full = isset($products[$id]) && is_array($products[$id]) ? $products[$id] : null;
            if ($full !== null) {
                // Y sin el desglose del precio (una tarjeta de búsqueda trae solo el importe): se toma de la ficha entera.
                if ($item['price_detail'] === null && isset($full['price']) && is_array($full['price'])) {
                    $item['price_detail'] = \Aifec\Modules\Catalog\PriceDetail::of($this->app, $full['price']);
                }
                // La tarjeta de un listado puede venir sin la segunda foto y sin las opciones: se toman de la ficha entera.
                if ($item['option_values'] === []) {
                    $item['option_values'] = self::cardOptions($full);
                }
                if ($item['image2'] === '' && isset($full['images'][1]) && is_array($full['images'][1])) {
                    $second = isset($full['images'][1]['card']) ? (string) $full['images'][1]['card'] : (isset($full['images'][1]['url']) ? (string) $full['images'][1]['url'] : '');
                    $item['image2'] = $second !== '' && $this->abs($second) !== $item['image'] ? $this->abs($second) : '';
                }
            }
            $summary = isset($extra[$id]['summary']) ? (string) $extra[$id]['summary'] : self::plain((string) ($full !== null && isset($full['summary']) ? $full['summary'] : ''), 160);
            $item['price_formatted'] = $item['price'];
            $item['available'] = (string) $item['availability']['status'] !== 'out_of_stock';
            $item['summary'] = self::cut($summary, 160);
            $item['options'] = $this->optionNames($full);
            $lines = isset($extra[$id]['matches']) && is_array($extra[$id]['matches']) ? $this->matchLines($extra[$id]['matches']) : [];
            if ($lines !== []) {
                $item['matched_attributes'] = $lines;
            }
            $item['matches'] = $lines !== [] ? $lines : $this->matches($full);
            // Sus categorías: con ellas el asistente ofrece botones para afinar («Para hombre», «Zapatos»).
            $categories = [];
            foreach ($full !== null && isset($full['categories']) && is_array($full['categories']) ? $full['categories'] : [] as $category) {
                if (is_array($category) && isset($category['id'], $category['name'])) {
                    $categories[] = ['id' => (int) $category['id'], 'name' => (string) $category['name']];
                }
            }
            $item['categories'] = $categories;
            $item['answer_kind'] = $kind;
            $items[] = $item;
        }

        return $items;
    }

    /**
     * «Color: Negro» a partir de los datos que da Search::ask().
     *
     * @param array<int,array<string,mixed>> $matches
     *
     * @return string[]
     */
    private function matchLines(array $matches): array
    {
        $out = [];
        foreach ($matches as $match) {
            if (is_array($match) && isset($match['name'], $match['value'])) {
                $out[] = trim((string) $match['name']) . ': ' . trim((string) $match['value']);
            }
        }

        return array_slice($out, 0, 3);
    }

    /**
     * Los nombres de las opciones de un producto (color, talla…).
     *
     * @param array<string,mixed>|null $product
     *
     * @return string[]
     */
    private function optionNames(?array $product): array
    {
        $names = [];
        foreach ($product !== null && isset($product['options']) && is_array($product['options']) ? $product['options'] : [] as $option) {
            if (is_array($option) && isset($option['name']) && trim((string) $option['name']) !== '') {
                $names[] = trim((string) $option['name']);
            }
        }

        return $names;
    }

    /**
     * Las variantes y las opciones de un producto como las lee quien compra: cada variante con su precio, si se puede comprar y los
     * valores que la forman; cada opción con los valores que se pueden elegir (los agotados aparte).
     *
     * @param array<string,mixed> $p una ProductData
     *
     * @return array{variants:array<int,array<string,mixed>>,options:array<int,array<string,mixed>>}
     */
    private function variantParts(array $p): array
    {
        $labels = [];
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            foreach (is_array($option) && isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && isset($value['code'])) {
                    $labels[(string) $option['code']][(string) $value['code']] = (string) (isset($value['label']) ? $value['label'] : '');
                }
            }
        }
        $variants = [];
        $availableLabels = [];
        foreach (isset($p['variants']) && is_array($p['variants']) ? array_slice($p['variants'], 0, 20) : [] as $variant) {
            $price = isset($variant['price']) && is_array($variant['price']) ? $variant['price'] : [];
            $amount = isset($price['amount']) ? (int) $price['amount'] : 0;
            $availability = $this->availability(isset($variant['availability']) ? $variant['availability'] : []);
            $available = $availability['status'] !== 'out_of_stock';
            $valueLabels = [];
            foreach (isset($variant['values']) && is_array($variant['values']) ? $variant['values'] : [] as $optionCode => $valueCode) {
                $label = isset($labels[(string) $optionCode][(string) $valueCode]) ? $labels[(string) $optionCode][(string) $valueCode] : '';
                if ($label !== '') {
                    $valueLabels[] = $label;
                    if ($available) {
                        $availableLabels[(string) $optionCode][$label] = true;
                    }
                }
            }
            $variants[] = [
                'id' => (int) $variant['id'],
                'label' => (string) (isset($variant['label']) ? $variant['label'] : ''),
                'price' => $this->fmt($amount, isset($price['currency']) ? (string) $price['currency'] : ''),
                'price_cents' => $amount,
                'availability' => $availability,
                'available' => $available,
                'value_labels' => $valueLabels,
            ];
        }
        $options = [];
        foreach (isset($p['options']) && is_array($p['options']) ? $p['options'] : [] as $option) {
            if (!is_array($option)) {
                continue;
            }
            $values = [];
            $soldOut = [];
            foreach (isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                $label = (string) (isset($value['label']) ? $value['label'] : '');
                if (isset($availableLabels[(string) $option['code']][$label])) {
                    $values[] = $label;
                } else {
                    $soldOut[] = $label;
                }
            }
            $options[] = ['name' => (string) (isset($option['name']) ? $option['name'] : ''), 'values' => $values, 'sold_out' => $soldOut];
        }

        return ['variants' => $variants, 'options' => $options];
    }

    /**
     * Lo que devuelve cart_add cuando se pide un producto con variantes sin decir cuál: no es un fallo, es «hay que elegir».
     *
     * @return array<string,mixed>|null null si el producto no tiene nada que elegir
     */
    private function needOptions(int $productId, int $qty): ?array
    {
        $p = $this->app->service('catalog')->product($productId, $this->app->lang());
        if (!is_array($p) || empty($p['has_variants'])) {
            return null;
        }
        $parts = $this->variantParts($p);
        $choices = array_values(array_filter($parts['variants'], static function (array $variant): bool {
            return !empty($variant['available']);
        }));
        if ($choices === []) {
            return null;
        }
        $options = [];
        foreach ($parts['options'] as $option) {
            $values = [];
            foreach ($option['values'] as $label) {
                $values[] = ['label' => $label, 'available' => true];
            }
            foreach ($option['sold_out'] as $label) {
                $values[] = ['label' => $label, 'available' => false];
            }
            $options[] = ['name' => $option['name'], 'values' => $values];
        }

        return [
            'need' => 'options',
            'product_id' => $productId,
            'name' => (string) (isset($p['name']) ? $p['name'] : ''),
            'qty' => $qty,
            'options' => $options,
            'variants' => $choices,
            'message' => 'This product comes in options: ask the customer which one they want (the shop shows the choices as buttons) and call cart_add again with that variant_id.',
        ];
    }

    /**
     * La guarda de opciones de «añadir»: mira lo que el cliente ha pedido en su mensaje (OptionAsk) y lo que el producto ofrece.
     *
     *  - null: nada que objetar (no pidió ninguna opción, o la que pidió es la de la variante que se va a añadir);
     *  - ['use_variant' => 0]: el producto tiene esa opción pero la variante que venía no es esa: se quita para que el
     *    asistente la resuelva con el mensaje (o pregunte con botones);
     *  - un resultado con «error»: NO se añade. O el producto no tiene opciones que elegir (se vende tal cual), o no tiene (o
     *    no le queda) la que pidió; en ese caso van además las opciones que sí hay.
     *
     * @return array<string,mixed>|null
     */
    private function optionGuard(int $productId, int $variantId, int $qty, string $message): ?array
    {
        $asks = OptionAsk::wanted($message);
        if ($asks === [] || $productId <= 0 || !$this->app->has('catalog')) {
            return null;
        }
        $p = $this->app->service('catalog')->product($productId, $this->app->lang());
        if (!is_array($p)) {
            return null;
        }
        $name = (string) (isset($p['name']) ? $p['name'] : '');
        if (empty($p['has_variants'])) {
            foreach ($asks as $ask) {
                if ($ask['value'] !== '' && OptionAsk::inText($ask['value'], $name)) {
                    // Lo que pide ya es el propio producto («en azul» y se llama «… azul»).
                    continue;
                }

                return [
                    'error' => t('This product has no sizes, colours or other options to choose: it is sold as shown.'),
                    'no_options' => true, 'asked' => $ask['text'], 'product_id' => $productId, 'name' => $name,
                    'message' => 'Nothing was added. The customer asked for an option ("' . $ask['text'] . '") and this product has none to choose. Say that in one short sentence and ask whether they want it as it is. Never say it was added.',
                ];
            }

            return null;
        }
        $parts = $this->variantParts($p);
        foreach ($asks as $ask) {
            if ($ask['value'] === '') {
                continue;
            }
            $exists = false;
            $chosen = false;
            $choices = [];
            foreach ($parts['variants'] as $variant) {
                // Por los valores de sus opciones y, por si la variante solo trae su etiqueta («Talla 39»), también por ella.
                $hit = OptionAsk::same($ask['value'], (string) $variant['label']);
                foreach ($variant['value_labels'] as $label) {
                    $hit = $hit || OptionAsk::same($ask['value'], (string) $label);
                }
                $exists = $exists || ($hit && !empty($variant['available']));
                $chosen = $chosen || ($hit && (int) $variant['id'] === $variantId);
                if (!empty($variant['available'])) {
                    $choices[] = (string) $variant['label'] !== '' ? (string) $variant['label'] : implode(' / ', $variant['value_labels']);
                }
            }
            if (!$exists) {
                $need = $this->needOptions($productId, $qty);

                return [
                    'error' => t('«{option}» is not available for this product.', ['option' => $ask['text']]),
                    'unavailable' => $ask['text'], 'product_id' => $productId, 'name' => $name,
                    'choices' => array_slice($choices, 0, 20),
                    'options' => $need !== null ? $need['options'] : [],
                    // Para que la ventana ofrezca con botones las opciones que sí hay (lo quita el ejecutor antes de dárselo al modelo).
                    'pick' => $need,
                    'message' => 'Nothing was added. The customer asked for "' . $ask['text'] . '" and this product does not have it (or it is sold out). Say that in one short sentence and offer the options that exist. Never say it was added.',
                ];
            }
            if ($variantId > 0 && !$chosen) {
                return ['use_variant' => 0];
            }
        }

        return null;
    }

    /**
     * Las tarjetas de estos productos (los que ha de enseñar el asistente), con el formato de los resultados de búsqueda.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    public function cardsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));

        return $this->items($this->listCards($ids), [], 'exact');
    }

    /** Un texto de $max caracteres como mucho, con «…» si se corta. */
    private static function cut(string $text, int $max): string
    {
        $text = trim($text);

        return mb_strlen($text, 'UTF-8') > $max ? Prompt::truncate($text, $max - 1) . '…' : $text;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function getProduct(array $in, array $ctx): array
    {
        $lang = $this->app->lang();
        $p = $this->app->service('catalog')->product((int) $in['id'], $lang);
        if ($p === null) {
            return ['error' => t('Product not found')];
        }
        $out = $this->card($p);
        $out['sku'] = (string) (isset($p['sku']) ? $p['sku'] : '');
        $out['kind'] = (string) (isset($p['kind']) ? $p['kind'] : 'physical');
        $categories = [];
        $categoryIds = [];
        foreach (isset($p['categories']) && is_array($p['categories']) ? $p['categories'] : [] as $category) {
            if (isset($category['name'])) {
                $categories[] = (string) $category['name'];
                $categoryIds[] = isset($category['id']) ? (int) $category['id'] : 0;
            }
        }
        $out['categories'] = $categories;
        $out['category_ids'] = $categoryIds;
        $out['summary'] = self::plain((string) (isset($p['summary']) ? $p['summary'] : ''), 400);
        $out['description'] = self::plain((string) (isset($p['description']) ? $p['description'] : ''), 1200);
        $out['facts'] = $this->pairs(isset($p['facts']) ? $p['facts'] : [], 'label', 12);
        $out['attributes'] = $this->pairs(isset($p['attributes']) ? $p['attributes'] : [], 'name', 12);

        $days = isset($p['availability']['delivery_days']) && is_array($p['availability']['delivery_days']) ? $p['availability']['delivery_days'] : null;
        if ($days !== null && count($days) >= 2) {
            $min = (int) $days[0];
            $max = (int) $days[1];
            $out['delivery_days'] = [$min, $max];
            $out['delivery'] = $min === $max
                ? tn('Delivery in {n} day', 'Delivery in {n} days', $max)
                : t('Delivery in {min} to {max} days', ['min' => $min, 'max' => $max]);
        }

        $parts = $this->variantParts($p);
        $out['variants'] = $parts['variants'];
        $out['options'] = $parts['options'];
        $faqs = [];
        foreach (isset($p['faqs']) && is_array($p['faqs']) ? array_slice($p['faqs'], 0, 5) : [] as $faq) {
            $faqs[] = ['question' => self::plain((string) $faq['question'], 200), 'answer' => self::plain((string) $faq['answer'], 400)];
        }
        $out['faqs'] = $faqs;
        $out['rating'] = isset($p['rating']) && is_array($p['rating']) ? ['average' => (float) $p['rating']['average'], 'count' => (int) $p['rating']['count']] : null;

        return $out;
    }

    /**
     * Los productos lado a lado: lo que dice cada uno (precio, existencias, resumen, atributos) y los dos o tres datos en los que no
     * coinciden. Todo sale de la ficha del catálogo.
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function compareProducts(array $in, array $ctx): array
    {
        $ids = [];
        foreach (isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : [] as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        $found = $this->app->service('catalog')->products(array_values($ids), $this->app->lang());
        $found = is_array($found) ? $found : [];
        $rows = [];
        $facts = [];
        foreach ($ids as $id) {
            if (!isset($found[$id]) || !is_array($found[$id])) {
                continue;
            }
            $p = $found[$id];
            $card = $this->card($p);
            $data = [];
            foreach ([['attributes', 'name'], ['facts', 'label']] as $source) {
                foreach (isset($p[$source[0]]) && is_array($p[$source[0]]) ? $p[$source[0]] : [] as $row) {
                    if (is_array($row) && isset($row[$source[1]], $row['value']) && is_scalar($row['value']) && trim((string) $row['value']) !== '') {
                        $data[trim((string) $row[$source[1]])] = trim((string) $row['value']);
                    }
                }
            }
            $facts[$id] = $data;
            $unit = [];
            if (!empty($p['pack_units']) && empty($card['from']) && !empty($p['availability']['show_price'])) {
                $count = (int) $p['pack_units'];
                $amount = (int) round($card['price_cents'] / $count);
                $unit['unit_price_cents'] = $amount;
                $unit['per_unit'] = t('{price} per unit', ['price' => $this->fmt($amount, $card['currency'])]);
                $singleId = (int) $p['pack_single_id'];
                $single = $singleId && $singleId !== $id ? ($found[$singleId] ?? $this->app->service('catalog')->product($singleId, $this->app->lang())) : null;
                if ($single && empty($single['price']['from']) && !empty($single['availability']['show_price'])
                    && empty($single['has_variants']) && (int) ($single['pack_units'] ?? 1) === 1
                    && $single['price']['currency'] === $card['currency']) {
                    $saving = (int) $single['price']['amount'] * $count - $card['price_cents'];
                    if ($saving > 0) {
                        $unit['pack_saving_cents'] = $saving;
                        $unit['pack_saving'] = t('Save {amount} compared with buying {n} individually.', ['amount' => $this->fmt($saving, $card['currency']), 'n' => $count]);
                    }
                }
            }
            $rows[] = [
                'id' => $id,
                'name' => $card['name'],
                'price' => $card['price'],
                'price_cents' => $card['price_cents'],
                'available' => (string) $card['availability']['status'] !== 'out_of_stock',
                'availability' => $card['availability']['label'],
                'summary' => self::plain((string) (isset($p['summary']) ? $p['summary'] : ''), 160),
                'attributes' => array_slice($data, 0, 6, true),
            ] + $unit;
        }
        if (count($rows) < 2) {
            return ['error' => t('I need at least two products of the shop to compare')];
        }
        // Los datos en los que no coinciden: los que alguno tiene distinto de otro o que solo uno trae.
        $names = [];
        foreach ($facts as $data) {
            foreach (array_keys($data) as $name) {
                $names[$name] = true;
            }
        }
        $differences = [];
        foreach (array_keys($names) as $name) {
            $values = [];
            foreach ($rows as $row) {
                $values[] = ['id' => $row['id'], 'value' => isset($facts[$row['id']][$name]) ? $facts[$row['id']][$name] : '-'];
            }
            if (count(array_unique(array_column($values, 'value'))) > 1) {
                $differences[] = ['name' => (string) $name, 'values' => $values];
            }
            if (count($differences) >= 3) {
                break;
            }
        }
        $cheapest = $rows[0];
        foreach ($rows as $row) {
            if ($row['price_cents'] < $cheapest['price_cents']) {
                $cheapest = $row;
            }
        }

        return ['products' => $rows, 'differences' => $differences, 'cheapest_id' => $cheapest['id']];
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function listCategories(array $in, array $ctx): array
    {
        $tree = $this->app->service('catalog')->tree($this->app->lang());
        $out = [];
        foreach (is_array($tree) ? $tree : [] as $node) {
            $children = [];
            foreach (isset($node['children']) && is_array($node['children']) ? $node['children'] : [] as $child) {
                $children[] = ['id' => (int) $child['id'], 'name' => (string) $child['name']];
                if (count($children) >= 12) {
                    break;
                }
            }
            $out[] = ['id' => (int) $node['id'], 'name' => (string) $node['name'], 'url' => $this->abs((string) (isset($node['url']) ? $node['url'] : '')), 'children' => $children];
            if (count($out) >= 30) {
                break;
            }
        }

        return ['categories' => $out, 'popular' => $this->popularCategories()];
    }

    /**
     * Las categorías de lo que más se vende, de la que más a la que menos: las de los 24 productos más vendidos, cada uno pesando más
     * cuanto más arriba está. Id y nombre, ocho como mucho.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private function popularCategories(): array
    {
        $lang = $this->app->lang();
        $catalog = $this->app->service('catalog');
        $listing = $catalog->listing(['lang' => $lang, 'sort' => 'best', 'page' => 1, 'per_page' => 24]);
        $ids = [];
        foreach (isset($listing['items']) && is_array($listing['items']) ? $listing['items'] : [] as $card) {
            if (is_array($card) && isset($card['id'])) {
                $ids[] = (int) $card['id'];
            }
        }
        if ($ids === []) {
            return [];
        }
        $products = $catalog->products($ids, $lang);
        $score = [];
        $order = [];
        $names = [];
        foreach ($ids as $rank => $id) {
            $categories = isset($products[$id]['categories']) && is_array($products[$id]['categories']) ? $products[$id]['categories'] : [];
            foreach ($categories as $category) {
                if (!is_array($category) || !isset($category['id'], $category['name']) || trim((string) $category['name']) === '') {
                    continue;
                }
                $cat = (int) $category['id'];
                $score[$cat] = (isset($score[$cat]) ? $score[$cat] : 0) + count($ids) - $rank;
                $order[$cat] = isset($order[$cat]) ? $order[$cat] : count($order);
                $names[$cat] = (string) $category['name'];
            }
        }
        $cats = array_keys($score);
        usort($cats, static function (int $a, int $b) use ($score, $order): int {
            return [$score[$b], $order[$a]] <=> [$score[$a], $order[$b]];
        });
        $out = [];
        foreach (array_slice($cats, 0, 8) as $cat) {
            $out[] = ['id' => $cat, 'name' => $names[$cat]];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function checkAvailability(array $in, array $ctx): array
    {
        $lang = $this->app->lang();
        $catalog = $this->app->service('catalog');
        $productId = (int) $in['product_id'];
        $variantId = isset($in['variant_id']) ? (int) $in['variant_id'] : 0;
        $qty = isset($in['qty']) ? (int) $in['qty'] : 1;
        $p = $catalog->purchasable($productId, $variantId, $lang);
        if ($p === null) {
            return ['error' => t('Product not found')];
        }
        $stock = isset($p['stock']) && $p['stock'] !== null ? (int) $p['stock'] : null;
        $backorders = isset($p['backorders']) && (string) $p['backorders'] === 'yes';
        $buyable = !empty($p['available']);
        $enough = $buyable && ($stock === null || $stock >= $qty || $backorders);
        $min = isset($p['min_qty']) ? (int) $p['min_qty'] : 0;
        $max = isset($p['max_qty']) ? (int) $p['max_qty'] : 0;
        $within = ($min <= 0 || $qty >= $min) && ($max <= 0 || $qty <= $max);

        $out = [
            'product_id' => $productId,
            'variant_id' => (int) $p['variant_id'],
            'name' => (string) $p['name'],
            'variant' => (string) (isset($p['variant_label']) ? $p['variant_label'] : ''),
            'qty' => $qty,
            'available' => $buyable,
            'enough' => $enough && $within,
            'reason' => (string) (isset($p['reason']) ? $p['reason'] : ''),
        ];
        $unit = isset($p['unit_price']) ? (int) $p['unit_price'] : 0;
        if ($unit > 0) {
            // Lo que cuestan esas unidades, calculado por la tienda («2 sillas a 89,00 € son 178,00 €»): el modelo no multiplica.
            $out['unit_price'] = $this->fmt($unit);
            $out['unit_price_cents'] = $unit;
            $out['total'] = $this->fmt($unit * max(1, $qty));
            $out['total_cents'] = $unit * max(1, $qty);
        }
        if ($min > 1) {
            $out['min_qty'] = $min;
        }
        if ($max > 0) {
            $out['max_qty'] = $max;
        }
        $product = $catalog->product($productId, $lang);
        if ($product !== null) {
            $source = isset($product['availability']) ? $product['availability'] : [];
            foreach (isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [] as $variant) {
                if ((int) $variant['id'] === (int) $p['variant_id'] && (int) $p['variant_id'] > 0) {
                    $source = isset($variant['availability']) ? $variant['availability'] : $source;
                }
            }
            $out['availability'] = $this->availability($source);
        }

        return $out;
    }

    // ------------------------------------------------------------------ envío e información

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    /**
     * Las formas de envío a la península (o al país de la tienda) para un paquete corriente y, si la hay, desde qué importe sale gratis.
     *
     * @return array<string,mixed> destination, options [name, delay, from, from_cents], free_from; vacío si no hay envíos
     */
    private function shippingSummary(): array
    {
        $app = $this->app;
        if (!$app->has('shipping')) {
            return [];
        }
        $country = strtoupper(trim($app->settings()->str('shipping.default_country', 'ES')));
        $country = preg_match('/^[A-Z]{2}$/D', $country) === 1 ? $country : 'ES';
        $postcode = $country === 'ES' ? '28001' : '';
        $region = $country === 'ES' ? '28' : '';
        $destination = ['country' => $country, 'region' => $region, 'postcode' => $postcode, 'city' => ''];
        $package = ['weight' => 1000, 'items_total' => 2000, 'qty' => 1, 'needs_shipping' => true];
        $options = [];
        try {
            $eligible = $app->service('shipping')->options($package, $destination);
            foreach ($eligible as $option) {
                if (is_array($option) && isset($option['price'])) {
                    $options[] = ['name' => (string) (isset($option['name']) ? $option['name'] : ''), 'delay' => (string) (isset($option['delay']) ? $option['delay'] : ''),
                        'from' => $this->fmt((int) $option['price']), 'from_cents' => (int) $option['price']];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        if ($options === []) {
            return [];
        }
        $out = ['destination' => $country === 'ES' ? 'Spain (peninsula)' : $country, 'options' => $options,
            'note' => 'Prices for a usual parcel; the exact price depends on the cart and the postcode: use shipping_quote.'];
        try {
            $gap = $this->freeShippingGap($eligible, $package, $destination);
            if ($gap !== null && $gap > 0) {
                $free = $package['items_total'] + $gap;
                $out['free_from'] = $this->fmt((int) $free);
                $out['free_from_cents'] = (int) $free;
            }
        } catch (\Throwable $e) {
            // Sin el dato, no se dice nada de envío gratis.
        }

        return $out;
    }

    private function freeShippingGap(array $eligible, array $package, array $destination): ?int
    {
        $ids = [];
        foreach ($eligible as $option) {
            if (!empty($option['needs_address']) && isset($option['carrier_id'], $option['price'])) {
                if ((int) $option['price'] === 0) { return 0; }
                $ids[] = (int) $option['carrier_id'];
            }
        }
        if ($ids === []) { return null; }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $thresholds = $this->app->db()->column("SELECT DISTINCT free_from FROM {carriers} WHERE id IN ($marks) AND free_from > ? ORDER BY free_from", array_merge($ids, [$package['items_total']]));
        foreach ($thresholds as $threshold) {
            $probe = $package;
            $probe['items_total'] = (int) $threshold;
            foreach ($this->app->service('shipping')->options($probe, $destination) as $option) {
                if (!empty($option['needs_address']) && isset($option['price']) && (int) $option['price'] === 0) {
                    return (int) $threshold - (int) $package['items_total'];
                }
            }
        }
        return null;
    }

    /** Recupera los párrafos publicados para este destino y distingue el porte de las posibles tasas en destino. */
    private function destinationNotice(string $country, string $postcode): string
    {
        $zone = $country === 'ES' ? \Aifec\Geo\Spain::zone($postcode) : '';
        $terms = [\Aifec\Geo\Countries::name($country, $this->app->i18n()->lang())];
        $lines = [];
        if ($zone === 'canarias') {
            $terms = ['Canarias', 'Canary Islands', 'IGIC'];
            $lines[] = t('For the Canary Islands, check whether IGIC or clearance fees are payable at delivery.');
        } elseif ($zone === 'ceuta' || $zone === 'melilla') {
            $terms = [$zone, 'IPSI'];
            $lines[] = t('For Ceuta and Melilla, check whether IPSI or clearance fees are payable at delivery.');
        } elseif (\Aifec\Geo\Countries::isEu($this->app->settings()->str('tax.base_country', 'ES')) && !\Aifec\Geo\Countries::isEu($country)) {
            $lines[] = t('Customs or import charges may be payable separately at the destination.');
        }
        $page = $this->legalPage(['shipping-returns']);
        if ($page) {
            foreach (preg_split('/(?<=[.!?])\s+|\R/u', $page['full']) ?: [] as $sentence) {
                if (mb_strlen($sentence) > 450) { continue; }
                foreach ($terms as $term) {
                    if ($term !== '' && strpos(' ' . RuleBrain::norm($sentence) . ' ', ' ' . RuleBrain::norm($term) . ' ') !== false) {
                        $lines[] = $sentence;
                        if ($page['url'] !== '') { $lines[] = '[' . $page['title'] . '](' . $page['url'] . ')'; }
                        break 2;
                    }
                }
            }
        }
        return implode("\n", $lines);
    }

    /** Una unidad comprable que da un porte gratuito al recalcular peso, cantidad e importe. */
    private function shippingComplements(int $gap, array $package, array $destination, array $exclude): array
    {
        $catalog = $this->app->service('catalog');
        $list = $catalog->listing(['price_min' => $gap, 'sort' => 'price_asc', 'in_stock' => true, 'per_page' => 8]);
        $ids = [];
        foreach ($list['items'] as $card) {
            $id = (int) $card['id'];
            if (in_array($id, $exclude, true) || !empty($card['has_variants'])) { continue; }
            $item = $catalog->purchasable($id, 0, $this->app->lang());
            if (!$item || empty($item['available']) || !empty($item['variant_id']) || (int) $item['min_qty'] > 1 || (int) $item['unit_price'] < $gap) { continue; }
            $future = $package;
            $future['qty']++;
            $future['items_total'] += (int) $item['unit_price'];
            $future['weight'] += (int) $item['weight'];
            foreach ($this->app->service('shipping')->options($future, $destination) as $rate) {
                if (!empty($rate['needs_address']) && (int) $rate['price'] === 0) {
                    $ids[] = $id;
                    break;
                }
            }
            if (count($ids) >= 2) { break; }
        }
        return $this->cardsFor($ids);
    }

    public function shippingQuote(array $in, array $ctx): array
    {
        $app = $this->app;
        if (!$app->has('shipping')) {
            return ['error' => t('Shipping quotes are not available')];
        }
        $postcode = isset($in['postcode']) ? strtoupper(trim((string) $in['postcode'])) : '';
        $country = isset($in['country']) ? strtoupper(trim((string) $in['country'])) : '';
        if ($postcode === '' && $country === '') {
            return ['error' => t('Give me a postcode to quote the shipping')];
        }
        if ($country === '') {
            $country = strtoupper(trim($app->settings()->str('shipping.default_country', 'ES')));
            if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
                $country = 'ES';
            }
        }
        $region = '';
        if ($country === 'ES' && $postcode !== '') {
            $province = Spain::provinceByPostcode($postcode);
            $region = $province !== null ? (string) $province['prefix'] : '';
        }
        $destination = ['country' => $country, 'region' => $region, 'postcode' => $postcode, 'city' => ''];

        if (isset($in['product_id'])) {
            $qty = isset($in['qty']) ? (int) $in['qty'] : 1;
            $p = $app->service('catalog')->purchasable((int) $in['product_id'], isset($in['variant_id']) ? (int) $in['variant_id'] : 0, $app->lang());
            if ($p === null) {
                return ['error' => t('Product not found')];
            }
            $package = [
                'weight' => (int) $p['weight'] * $qty,
                'items_total' => (int) $p['unit_price'] * $qty,
                'qty' => $qty,
                'needs_shipping' => (string) $p['kind'] === 'physical',
            ];
        } else {
            $cart = $this->cart($ctx);
            $summary = $cart !== null && method_exists($cart, 'summary') ? $cart->summary() : [];
            if (!isset($summary['count']) || (int) $summary['count'] < 1) {
                return ['error' => t('Tell me which product, or add something to the cart first'), 'need' => 'product'];
            }
            $package = [
                'weight' => (int) (isset($summary['weight']) ? $summary['weight'] : 0),
                'items_total' => (int) (isset($summary['totals']['items_total']) ? $summary['totals']['items_total'] : 0),
                'qty' => (int) $summary['count'],
                'needs_shipping' => !empty($summary['needs_shipping']),
            ];
        }
        if (!$package['needs_shipping']) {
            return ['destination' => $destination, 'needs_shipping' => false, 'options' => [], 'note' => 'This does not need shipping.'];
        }

        $options = [];
        $eligible = $app->service('shipping')->options($package, $destination);
        foreach ($eligible as $option) {
            if (!is_array($option) || !isset($option['price'])) {
                continue;
            }
            $price = (int) $option['price'];
            $options[] = [
                'name' => (string) (isset($option['name']) ? $option['name'] : ''),
                'delay' => (string) (isset($option['delay']) ? $option['delay'] : ''),
                'price' => $this->fmt($price),
                'price_cents' => $price,
                'free' => !empty($option['free']) || $price === 0,
            ];
            if (!empty($ctx['conversation_id']) && !empty($option['needs_address'])
                && isset($option['min_days'], $option['max_days']) && $option['min_days'] >= 0 && $option['max_days'] >= $option['min_days']) {
                $timezone = $app->site()->timezone();
                $today = (new \DateTimeImmutable('@' . $app->clock()->now()))->setTimezone(new \DateTimeZone($timezone));
                $dates = [];
                foreach (array_unique([(int) $option['min_days'], (int) $option['max_days']]) as $days) {
                    $date = $today->modify('+' . $days . ' days');
                    $dates[] = $app->i18n()->date($date->getTimestamp(), 'medium', $timezone);
                }
                $options[count($options) - 1]['estimated_dates'] = implode(' – ', $dates);
            }
        }
        $out = ['destination' => $destination, 'needs_shipping' => true, 'options' => $options];
        if (!empty($ctx['conversation_id']) && $options) {
            $notice = $this->destinationNotice($country, $postcode);
            if ($notice !== '') { $out['destination_notice'] = $notice; }
        }
        $gap = $this->freeShippingGap($eligible, $package, $destination);
        if ($gap !== null) {
            $out['free_shipping_gap_cents'] = $gap;
            $out['free_shipping_gap'] = $this->fmt($gap);
            if ($gap > 0 && !empty($ctx['conversation_id'])) {
                $exclude = isset($summary['lines']) ? array_column($summary['lines'], 'product_id') : [(int) ($in['product_id'] ?? 0)];
                $out['complements'] = $this->shippingComplements($gap, $package, $destination, array_map('intval', $exclude));
            }
        }
        if ($options === []) {
            $out['note'] = 'The shop does not deliver to this destination.';
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function storeInfo(array $in, array $ctx): array
    {
        $app = $this->app;
        $settings = $app->settings();
        $topic = isset($in['topic']) ? (string) $in['topic'] : 'all';
        $out = ['name' => $settings->str('store.name', 'AIFEC')];
        $about = trim($settings->str('assistant.about'));
        if ($about !== '' && ($topic === 'all' || $topic === 'about')) {
            // Lo que la tienda cuenta de sí misma con sus palabras (qué vende, para quién, qué no hace): es texto del dueño, y entra
            // cercado como dato con el resto del resultado.
            $out['about'] = mb_substr($about, 0, 1500, 'UTF-8');
        }

        if ($topic === 'all' || $topic === 'contact') {
            $out['contact'] = $this->contactData();
            // «Mi cuenta»: el único sitio donde se ven los datos de un pedido (de quien ha entrado con la cuenta que lo hizo).
            $account = $this->accountUrl();
            if ($account !== '') {
                $out['account_url'] = $account;
            }
        }
        if ($topic === 'all' || $topic === 'hours') {
            $out['hours'] = trim($settings->str('assistant.hours'));
        }
        $pages = [];
        if ($topic === 'all' || $topic === 'returns') {
            $page = $this->legalPage(['shipping-returns', 'terms']);
            $out['returns'] = $this->returnsInfo($page);
            if ($page !== null) {
                $pages[] = ['topic' => 'returns'] + $page;
            }
            if ($this->returnsModule()) {
                // El plazo es el del ajuste del módulo de devoluciones y el enlace, el de su página: ninguno de los dos se inventa.
                $out['returns_days'] = $settings->int('returns.days', 30);
                $link = $this->returnsUrl();
                if ($link !== '') {
                    $out['returns_url'] = $link;
                }
                // Quién paga el envío de vuelta: el texto que escribió la tienda en su ajuste, o que no lo ha dicho.
                $payer = $this->returnsPayer();
                $out['returns_payer'] = $payer !== '' ? $payer : t('The shop has not said who pays the return shipping: ask it through the contact page.');
                if ($payer === '') {
                    $out['returns_payer_note'] = 'The shop has not said who pays the return shipping: tell the customer exactly that and to ask the shop (contact). Never guess it.';
                }
            }
            if ($out['returns'] === '') {
                // Sin módulo de devoluciones, sin página y sin texto: no se inventa nada, pero tampoco se queda en «consulta con la
                // tienda»: van los datos de contacto para decir CÓMO preguntarlo.
                $out['returns_note'] = 'The shop has not published a returns policy: say the customer should ask the shop, giving the contact details below (email, phone), and do not state any deadline or link.';
                if (!isset($out['contact'])) {
                    $out['contact'] = $this->contactData();
                }
            }
        }
        if ($topic === 'shipping' || $topic === 'all') {
            // Lo que cuesta enviar, con los transportistas de verdad (14/11/2026: el asistente decía «no se especifican los costes de
            // envío» con «Envío desde 4,90 €» en el carrito).
            $summary = $this->shippingSummary();
            if ($summary !== []) {
                $out['shipping'] = $summary;
            }
        }
        if ($topic === 'shipping' || ($topic === 'all' && !$this->hasPage($pages, 'shipping-returns'))) {
            $page = $this->legalPage(['shipping-returns']);
            if ($page !== null && !$this->hasPage($pages, (string) $page['code'])) {
                $pages[] = ['topic' => 'shipping'] + $page;
            }
        }
        if ($topic === 'terms' || $topic === 'all') {
            $page = $this->legalPage(['terms']);
            if ($page !== null && !$this->hasPage($pages, (string) $page['code'])) {
                $pages[] = ['topic' => 'terms'] + $page;
            }
        }
        if ($pages !== []) {
            $out['pages'] = $pages;
        }
        if ($topic === 'all' || $topic === 'payment') {
            $out['payment_methods'] = $this->paymentTitles($ctx);
        }
        if (($topic === 'all' || $topic === 'payment' || $topic === 'terms') && class_exists(\Aifec\Modules\Orders\InvoiceNotice::class)) {
            // Cómo llega la factura: adjunta a la confirmación, en la cuenta o, si la tienda no factura con AIFEC, por correo desde su ERP.
            $out['invoices'] = \Aifec\Modules\Orders\InvoiceNotice::text($this->app);
        }

        return $out;
    }

    // ------------------------------------------------------------------ carrito

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function cartView(array $in, array $ctx): array
    {
        $cart = $this->cart($ctx);
        if ($cart === null || !method_exists($cart, 'summary')) {
            return ['error' => t('The cart is not available')];
        }

        return $this->cartData($cart->summary());
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function cartAdd(array $in, array $ctx): array
    {
        $cart = $this->cart($ctx);
        if ($cart === null || !method_exists($cart, 'add')) {
            return ['error' => t('The cart is not available')];
        }
        $variantId = isset($in['variant_id']) ? (int) $in['variant_id'] : 0;
        $qty = isset($in['qty']) ? (int) $in['qty'] : 1;
        // Si el cliente ha pedido una opción (talla, color…) y el producto no la tiene, NO se añade: lo decide el código.
        $guard = $this->optionGuard((int) $in['product_id'], $variantId, $qty, isset($ctx['message']) && is_string($ctx['message']) ? $ctx['message'] : '');
        if ($guard !== null && !isset($guard['use_variant'])) {
            return $guard;
        }
        if ($guard !== null) {
            $variantId = (int) $guard['use_variant'];
        }
        $result = $cart->add((int) $in['product_id'], $variantId, $qty);
        if ((!is_array($result) || empty($result['ok'])) && $variantId === 0) {
            // Un producto con variantes sin decir cuál no es un error: se devuelve lo que hay que elegir.
            $need = $this->needOptions((int) $in['product_id'], $qty);
            if ($need !== null) {
                return $need;
            }
        }
        if (!is_array($result) || empty($result['ok'])) {
            return ['error' => is_array($result) && !empty($result['error']) ? (string) $result['error'] : t('The product could not be added')];
        }
        $key = (string) (isset($result['key']) ? $result['key'] : '');
        $summary = $cart->summary();
        $name = isset($summary['lines'][$key]['name']) ? (string) $summary['lines'][$key]['name'] : '';
        $out = ['ok' => true, 'key' => $key, 'name' => $name];
        $label = isset($summary['lines'][$key]['variant_label']) ? trim((string) $summary['lines'][$key]['variant_label']) : '';
        if ($label !== '') {
            $out['variant'] = $label;
        }
        $out['variant_id'] = $variantId;
        $out['product_id'] = (int) $in['product_id'];
        $out['qty'] = $qty;
        // Lo que cuestan las unidades que se acaban de añadir: el precio de la línea por las unidades, calculado aquí.
        $unit = isset($summary['lines'][$key]['unit_price']) ? (int) $summary['lines'][$key]['unit_price'] : 0;
        if ($unit > 0) {
            $currency = isset($summary['currency']) ? (string) $summary['currency'] : '';
            $out['unit_price'] = $this->fmt($unit, $currency);
            $out['unit_price_cents'] = $unit;
            $out['added_total'] = $this->fmt($unit * max(1, $qty), $currency);
            $out['added_total_cents'] = $unit * max(1, $qty);
        }

        return $out + $this->cartData($summary, true);
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function cartUpdate(array $in, array $ctx): array
    {
        $cart = $this->cart($ctx);
        if ($cart === null || !method_exists($cart, 'update')) {
            return ['error' => t('The cart is not available')];
        }
        $result = $cart->update((string) $in['key'], (int) $in['qty']);
        if (!is_array($result) || empty($result['ok'])) {
            return ['error' => is_array($result) && !empty($result['error']) ? (string) $result['error'] : t('The cart could not be changed')];
        }

        return ['ok' => true] + $this->cartData($cart->summary(), true);
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function checkoutLink(array $in, array $ctx): array
    {
        $cart = $this->cart($ctx);
        if ($cart === null || !method_exists($cart, 'summary')) {
            return ['error' => t('The cart is not available')];
        }
        $summary = $cart->summary();
        if (!isset($summary['count']) || (int) $summary['count'] < 1) {
            return ['error' => t('The cart is empty')];
        }
        try {
            $path = $this->app->url()->route('checkout');
        } catch (\Throwable $e) {
            $path = $this->app->url()->to('/' . trim($this->app->settings()->str('urls.checkout', 'finalizar-compra'), '/'));
        }
        $out = ['url' => $this->abs($path)] + $this->cartData($summary, true);
        if (!empty($summary['problems']) && is_array($summary['problems'])) {
            $out['problems'] = array_values(array_map('strval', $summary['problems']));
        }

        return $out;
    }

    // ------------------------------------------------------------------ pedidos

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function orderStatus(array $in, array $ctx): array
    {
        if (!$this->app->has('orders')) {
            return ['error' => t('Orders are not available')];
        }
        // Un número que no existe y un correo que no coincide dan la MISMA respuesta: no se confirma que un pedido exista.
        $missing = ['error' => t('I could not find an order with those details')];
        $order = $this->app->service('orders')->findByNumber((string) $in['number']);
        $email = strtolower(trim((string) $in['email']));
        if ($order === null || !hash_equals(strtolower(trim((string) $order['email'])), $email)) {
            return $missing;
        }
        $state = isset($order['state']) && is_array($order['state']) && isset($order['state']['name']) ? (string) $order['state']['name'] : (string) $order['status'];
        $out = [
            'number' => (string) $order['number'],
            'status' => (string) $order['status'],
            'status_label' => t($state),
            'payment_status' => (string) $order['payment_status'],
            'fulfillment_status' => (string) $order['fulfillment_status'],
            'placed_on' => substr((string) $order['placed_at'], 0, 10),
        ];
        if ((string) $order['carrier_title'] !== '') {
            $out['carrier'] = (string) $order['carrier_title'];
        }
        if ((string) $order['tracking_code'] !== '') {
            $out['tracking_code'] = (string) $order['tracking_code'];
        }
        if (!empty($order['shipped_at'])) {
            $out['shipped_on'] = substr((string) $order['shipped_at'], 0, 10);
        }

        return $out;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El carrito sobre el que se actúa: el del contexto y, si no lo hay, el de la sesión de esta petición.
     *
     * @param array<string,mixed> $ctx
     */
    private function cart(array $ctx): ?object
    {
        if (isset($ctx['cart']) && is_object($ctx['cart'])) {
            return $ctx['cart'];
        }
        if ($this->app->has('cart')) {
            $cart = $this->app->service('cart');

            return is_object($cart) ? $cart : null;
        }

        return null;
    }

    /**
     * El resumen del carrito en pocos datos planos.
     *
     * @param array<string,mixed> $summary
     *
     * @return array<string,mixed>
     */
    private function cartData(array $summary, bool $short = false): array
    {
        $currency = isset($summary['currency']) ? (string) $summary['currency'] : '';
        $totals = isset($summary['totals']) && is_array($summary['totals']) ? $summary['totals'] : [];
        $total = isset($totals['total']) ? (int) $totals['total'] : 0;
        $items = isset($totals['items_total']) ? (int) $totals['items_total'] : 0;
        $out = [
            'count' => isset($summary['count']) ? (int) $summary['count'] : 0,
            'total' => $this->fmt($total, $currency),
            'total_cents' => $total,
            'items_total_cents' => $items,
        ];
        if ($short) {
            return ['cart' => $out];
        }

        $lines = [];
        foreach (isset($summary['lines']) && is_array($summary['lines']) ? $summary['lines'] : [] as $line) {
            $unit = (int) (isset($line['unit_price']) ? $line['unit_price'] : 0);
            $lineTotal = (int) (isset($line['total']) ? $line['total'] : 0);
            $lines[] = [
                'key' => (string) $line['key'],
                'product_id' => (int) $line['product_id'],
                'variant_id' => (int) (isset($line['variant_id']) ? $line['variant_id'] : 0),
                'name' => (string) $line['name'],
                'variant' => (string) (isset($line['variant_label']) ? $line['variant_label'] : ''),
                'qty' => (int) $line['qty'],
                'unit_price' => $this->fmt($unit, $currency),
                'unit_price_cents' => $unit,
                'total' => $this->fmt($lineTotal, $currency),
                'total_cents' => $lineTotal,
                'url' => $this->abs((string) (isset($line['url']) ? $line['url'] : '')),
            ];
        }
        $shipping = isset($totals['shipping']) && is_array($totals['shipping']) ? (int) (isset($totals['shipping']['gross']) ? $totals['shipping']['gross'] : $totals['shipping']['price']) : null;
        $discount = isset($totals['discount_total']) ? (int) $totals['discount_total'] : 0;
        $out['items_total'] = $this->fmt(isset($totals['items_total']) ? (int) $totals['items_total'] : 0, $currency);
        $out['items_total_cents'] = isset($totals['items_total']) ? (int) $totals['items_total'] : 0;
        if ($discount > 0) {
            $out['discount'] = $this->fmt($discount, $currency);
            $out['discount_cents'] = $discount;
        }
        if ($shipping !== null) {
            $out['shipping'] = $this->fmt($shipping, $currency);
            $out['shipping_cents'] = $shipping;
        }
        $out['lines'] = $lines;
        $out['currency'] = $currency !== '' ? $currency : $this->currency();
        if (!empty($summary['problems']) && is_array($summary['problems'])) {
            $out['problems'] = array_values(array_map('strval', $summary['problems']));
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $c una ProductCard o una ProductData
     *
     * @return array<string,mixed>
     */
    private function card(array $c): array
    {
        $price = isset($c['price']) && is_array($c['price']) ? $c['price'] : [];
        $currency = isset($price['currency']) ? (string) $price['currency'] : $this->currency();
        $amount = isset($price['amount']) ? (int) $price['amount'] : 0;
        $compare = isset($price['compare']) && $price['compare'] !== null ? (int) $price['compare'] : null;
        $image = isset($c['image']) && is_array($c['image']) ? $c['image'] : [];
        $imageUrl = isset($image['card']) ? (string) $image['card'] : (isset($image['url']) ? (string) $image['url'] : '');
        $brand = isset($c['brand']) ? $c['brand'] : '';
        if (is_array($brand)) {
            $brand = isset($brand['name']) ? $brand['name'] : '';
        }
        // La segunda foto: la de la tarjeta del catálogo o, en una ficha entera, la segunda de su galería.
        $second = isset($c['image2']) && is_array($c['image2']) ? $c['image2'] : (isset($c['images'][1]) && is_array($c['images'][1]) ? $c['images'][1] : []);
        $secondUrl = isset($second['card']) ? (string) $second['card'] : (isset($second['url']) ? (string) $second['url'] : '');

        return [
            'id' => (int) $c['id'],
            'name' => (string) (isset($c['name']) ? $c['name'] : ''),
            'url' => $this->abs((string) (isset($c['url']) ? $c['url'] : '')),
            'image' => $this->abs($imageUrl),
            'image2' => $secondUrl !== '' && $secondUrl !== $imageUrl ? $this->abs($secondUrl) : '',
            // Lo que se puede elegir y comprar ahora mismo (tallas, colores…): la tarjeta lo enseña y el modelo lo sabe.
            // («options», en los resultados de una búsqueda, son solo los nombres de las opciones: por eso esta clave es otra.)
            'option_values' => self::cardOptions($c),
            'price' => $this->fmt($amount, $currency),
            'price_cents' => $amount,
            'currency' => $currency,
            'compare_price' => $compare !== null ? $this->fmt($compare, $currency) : null,
            'compare_price_cents' => $compare,
            'from' => !empty($price['from']),
            'tax_included' => !empty($price['with_tax']),
            'price_detail' => \Aifec\Modules\Catalog\PriceDetail::of($this->app, $price),
            'availability' => $this->availability(isset($c['availability']) ? $c['availability'] : []),
            'brand' => (string) $brand,
            'has_variants' => !empty($c['has_variants']),
            // Para quien solo abre direcciones: la que mete una unidad en un carrito nuevo. Un producto con opciones no la lleva (hay
            // que elegir antes) ni uno agotado.
            'buy_url' => $this->buyUrl($c),
        ];
    }

    /**
     * @param array<string,mixed> $c una ProductCard o una ProductData
     */
    private function buyUrl(array $c): ?string
    {
        if (!class_exists('Aifec\\Modules\\Api\\BrowseController') || !\Aifec\Modules\Api\BrowseController::enabled($this->app)
            || !empty($c['has_variants']) || !isset($c['id'])) {
            return null;
        }
        $availability = isset($c['availability']) && is_array($c['availability']) ? $c['availability'] : [];
        if (isset($availability['status']) && (string) $availability['status'] === 'out_of_stock') {
            return null;
        }

        return \Aifec\Modules\Api\BrowseController::addUrl($this->app, (int) $c['id']);
    }

    /**
     * Las opciones de un producto con los valores que se pueden comprar ahora («Talla: 39, 40, 42»), sacadas de lo que la
     * tarjeta del catálogo ya trae para comprar desde el listado. Vacío si no tiene opciones o no se puede comprar.
     *
     * @param array<string,mixed> $c
     *
     * @return array<int,array{name:string,values:string[]}>
     */
    private static function cardOptions(array $c): array
    {
        // Una tarjeta del catálogo las trae en «buy»; una ficha entera (ProductData), arriba, con la disponibilidad de cada variante.
        $source = isset($c['buy']) && is_array($c['buy']) ? $c['buy'] : $c;
        $options = isset($source['options']) && is_array($source['options']) ? $source['options'] : [];
        $variants = isset($source['variants']) && is_array($source['variants']) ? $source['variants'] : [];
        if ($options === [] || $variants === []) {
            return [];
        }
        $can = [];
        foreach ($variants as $variant) {
            if (!is_array($variant) || !isset($variant['values']) || !is_array($variant['values'])) {
                continue;
            }
            if (array_key_exists('purchasable', $variant)) {
                $ok = !empty($variant['purchasable']);
            } else {
                $availability = isset($variant['availability']) && is_array($variant['availability']) ? $variant['availability'] : [];
                $ok = (isset($availability['status']) ? (string) $availability['status'] : '') !== 'out_of_stock'
                    && (!array_key_exists('purchasable', $availability) || !empty($availability['purchasable']));
            }
            if ($ok) {
                foreach ($variant['values'] as $code => $value) {
                    $can[(string) $code][(string) $value] = true;
                }
            }
        }
        $out = [];
        foreach (array_slice($options, 0, 3) as $option) {
            $code = isset($option['code']) ? (string) $option['code'] : '';
            $labels = [];
            $swatches = [];
            foreach (isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && isset($value['code'], $value['label']) && isset($can[$code][(string) $value['code']])) {
                    $label = mb_substr((string) $value['label'], 0, 24, 'UTF-8');
                    $labels[] = $label;
                    if (preg_match('/^#[0-9a-f]{6}$/iD', (string) ($value['color'] ?? ''))) { $swatches[$label] = $value['color']; }
                }
            }
            if ($labels !== []) {
                $out[] = ['name' => mb_substr((string) (isset($option['name']) ? $option['name'] : ''), 0, 30, 'UTF-8'), 'values' => array_slice($labels, 0, 20)] + ($swatches ? ['swatches' => $swatches] : []);
            }
        }

        return $out;
    }

    /**
     * @param mixed $source
     *
     * @return array{status:string,label:string}
     */
    private function availability($source): array
    {
        $source = is_array($source) ? $source : [];
        $status = (string) (isset($source['status']) ? $source['status'] : '');
        $label = (string) (isset($source['label']) ? $source['label'] : '');
        if ($status !== '' && $this->app->i18n()->lang() !== $this->app->lang()) {
            // El catálogo escribe la disponibilidad en el idioma de la tienda; la conversación va en otro: se dice en el suyo.
            $labels = ['out_of_stock' => t('Sold out'), 'backorder' => t('On backorder'), 'low_stock' => t('Few left'), 'in_stock' => t('In stock')];
            $label = isset($labels[$status]) ? $labels[$status] : $label;
        }

        return ['status' => $status, 'label' => $label];
    }

    /**
     * Los datos clave de un producto, como «Material: Latón»: por qué casa con lo que se buscó.
     *
     * @param array<string,mixed>|null $product
     *
     * @return string[]
     */
    private function matches(?array $product): array
    {
        if ($product === null) {
            return [];
        }
        $out = array_merge(
            $this->pairs(isset($product['attributes']) ? $product['attributes'] : [], 'name', 3),
            $this->pairs(isset($product['facts']) ? $product['facts'] : [], 'label', 3)
        );

        return array_slice($out, 0, 3);
    }

    /**
     * Una lista de ['label' o 'name', 'value'] como textos «Etiqueta: valor».
     *
     * @param mixed $list
     *
     * @return string[]
     */
    private function pairs($list, string $labelKey, int $max): array
    {
        $out = [];
        foreach (is_array($list) ? $list : [] as $row) {
            if (!is_array($row) || !isset($row[$labelKey], $row['value']) || !is_scalar($row['value']) || trim((string) $row['value']) === '') {
                continue;
            }
            $out[] = trim((string) $row[$labelKey]) . ': ' . trim((string) $row['value']);
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /**
     * ¿Ya se ha puesto esa página legal (por su código) en la lista?
     *
     * @param array<int,array<string,mixed>> $pages
     */
    private function hasPage(array $pages, string $code): bool
    {
        foreach ($pages as $page) {
            if ((string) $page['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una de las páginas legales de la tienda (la primera de esa lista de códigos que exista y se vea): su título, su dirección
     * completa y el principio del texto, de 600 caracteres como mucho. null si no hay ninguna.
     *
     * @param string[] $codes
     *
     * @return array{code:string,title:string,url:string,text:string}|null
     */
    private function legalPage(array $codes): ?array
    {
        $app = $this->app;
        if (!$app->has('cms')) {
            return null;
        }
        $map = $app->settings()->arr('cms.legal');
        foreach ($codes as $code) {
            $id = isset($map[$code]) ? (int) $map[$code] : 0;
            $page = $id > 0 ? $app->service('cms')->page($id) : null;
            if (is_array($page) && isset($page['title'])) {
                return [
                    'code' => $code,
                    'title' => (string) $page['title'],
                    'url' => (string) (isset($page['url_abs']) ? $page['url_abs'] : ''),
                    'text' => self::plain((string) (isset($page['html']) ? $page['html'] : ''), 600),
                    // El texto entero, para sacar de él lo que importa a una pregunta (devoluciones dentro de las condiciones).
                    'full' => self::plain((string) (isset($page['html']) ? $page['html'] : ''), 40000),
                ];
            }
        }

        return null;
    }

    /**
     * Lo que se sabe de devoluciones, de 600 caracteres como mucho: la política (semántica o de los ajustes), el plazo del módulo de
     * devoluciones si está activo y, si no hay nada de eso, el texto de la página de devoluciones.
     *
     * @param array{code:string,title:string,url:string,text:string}|null $page
     */
    private function returnsInfo(?array $page): string
    {
        $app = $this->app;
        $parts = [];
        $policy = $this->returnsText();
        if ($this->returnsModule() && $app->settings()->int('returns.days', 30) > 0) {
            // Con el módulo de devoluciones, manda su plazo: es el que se aplica de verdad. Y quién paga el envío de vuelta.
            $days = $app->settings()->int('returns.days', 30);
            $parts[] = tn('You can return a product within {n} day.', 'You can return a product within {n} days.', $days);
            $payer = $this->returnsPayer();
            $parts[] = $payer !== ''
                ? t('Who pays the return shipping: {text}', ['text' => rtrim($payer, '.') . '.'])
                : t('The shop has not said who pays the return shipping: ask it through the contact page.');
        } elseif ($policy !== '') {
            $parts[] = $policy;
        }
        if ($parts === [] && $page !== null && $page['text'] !== '') {
            // Sin página de devoluciones, las condiciones generales: de ellas solo lo que habla de devolver, desistir o reembolsar, y no su
            // principio (auditoría del 24/11/2026: «returns» devolvía la presentación de las condiciones, cortada a media palabra).
            $parts[] = $page['code'] === 'terms' && !empty($page['full']) ? self::aboutReturns((string) $page['full'], $page['text']) : $page['text'];
        }

        return Prompt::truncate(trim(implode(' ', $parts)), 600);
    }

    /**
     * Las líneas de un texto largo que hablan de devoluciones, desistimiento o reembolsos (hasta 600 caracteres). Si no hay ninguna, el
     * texto de reserva.
     */
    private static function aboutReturns(string $full, string $fallback): string
    {
        $picked = [];
        foreach (preg_split('/\n+/u', $full) ?: [] as $line) {
            if (preg_match('/desisti|devoluci|devolver|reembols|return|refund|withdraw/iu', $line) === 1) {
                $picked[] = trim($line);
            }
        }

        return $picked === [] ? $fallback : implode(' ', $picked);
    }

    /** ¿Está activo el módulo de devoluciones? */
    private function returnsModule(): bool
    {
        try {
            return $this->app->modules()->isLoaded('returns');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Los datos de contacto de la tienda que estén rellenos (email, phone, address).
     *
     * @return array<string,string>
     */
    private function contactData(): array
    {
        $contact = [];
        foreach (['email' => 'store.email', 'phone' => 'store.phone', 'address' => 'store.address'] as $key => $setting) {
            $value = trim($this->app->settings()->str($setting));
            if ($value !== '') {
                $contact[$key] = $value;
            }
        }

        $number = trim($this->app->settings()->str('assistant.whatsapp_number'));
        if ($this->app->has('orders') && preg_match('/^\+[1-9][0-9]{6,14}$/D', $number) && \Aifec\Modules\Orders\Checkout\BuyerCode::whatsappOn($this->app)) {
            $contact['whatsapp'] = 'https://wa.me/' . substr($number, 1);
        }
        return $contact;
    }

    /** Los pedidos de «Mi cuenta», con su dominio, o vacío si la tienda no tiene cuentas de cliente. */
    private function accountUrl(): string
    {
        foreach (['account.orders', 'account'] as $route) {
            try {
                return $this->abs($this->app->url()->route($route));
            } catch (\Throwable $e) {
                continue;
            }
        }

        return '';
    }

    /** Lo que escribió la tienda sobre quién paga el envío de vuelta (ajuste del módulo de devoluciones), sin etiquetas, o vacío. */
    private function returnsPayer(): string
    {
        return self::plain($this->app->settings()->str('returns.return_shipping_payer'), 400);
    }

    /** La página de devoluciones de la tienda con su dominio (la del módulo de devoluciones), o vacío si no se puede calcular. */
    private function returnsUrl(): string
    {
        try {
            return $this->abs($this->app->url()->route('returns.withdrawal'));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** La política de devoluciones: la del módulo semántico si está definida; si no, el texto de los ajustes. */
    private function returnsText(): string
    {
        $app = $this->app;
        if ($app->has('semantic')) {
            $semantic = $app->service('semantic');
            $returns = is_object($semantic) && method_exists($semantic, 'returns') ? $semantic->returns() : null;
            if (is_array($returns) && isset($returns['days'])) {
                $text = tn('You can return a product within {n} day.', 'You can return a product within {n} days.', (int) $returns['days']);

                return $text . ' ' . ((isset($returns['payer']) && $returns['payer'] === 'merchant')
                    ? t('The shop pays the return shipping.')
                    : t('The customer pays the return shipping.'));
            }
        }

        return trim($app->settings()->str('assistant.returns_text'));
    }

    /**
     * @param array<string,mixed> $ctx
     *
     * @return string[]
     */
    private function paymentTitles(array $ctx): array
    {
        $app = $this->app;
        if (!$app->has('payments')) {
            return [];
        }
        $total = 0;
        $cart = $this->cart($ctx);
        if ($cart !== null && method_exists($cart, 'summary')) {
            $summary = $cart->summary();
            $total = isset($summary['totals']['total']) ? (int) $summary['totals']['total'] : 0;
        }
        $country = strtoupper(trim($app->settings()->str('shipping.default_country', 'ES')));
        $titles = [];
        try {
            $methods = $app->service('payments')->available([
                'total' => $total, 'currency' => $this->currency(), 'country' => $country, 'needs_shipping' => true,
                'customer_id' => null, 'lang' => $app->lang(),
            ]);
            foreach ($methods as $method) {
                $info = $method->info();
                if (isset($info['title']) && trim((string) $info['title']) !== '') {
                    $titles[] = trim((string) $info['title']);
                }
            }
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'assistant.payments');
        }

        return $titles;
    }

    private function currency(): string
    {
        $currency = strtoupper(trim($this->app->settings()->str('shop.currency', 'EUR')));

        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'EUR';
    }

    private function fmt(int $cents, string $currency = ''): string
    {
        return $this->app->i18n()->money($cents, $currency !== '' ? $currency : $this->currency());
    }

    /** Una dirección de la tienda con su dominio: la API y el MCP la dan a quien no está en la web. */
    private function abs(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if ($url[0] === '/' && substr($url, 0, 2) !== '//') {
            return $this->app->url()->origin() . $url;
        }

        return $url;
    }

    /** HTML a texto plano de $max caracteres como mucho: sin etiquetas, con los saltos de párrafo. */
    public static function plain(string $html, int $max): string
    {
        $html = (string) preg_replace('#<(button|script|style|nav|select)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $text = preg_replace('#<(?:br|/p|/li|/h[1-6]|/div|/tr)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text === null ? $html : $text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text === null ? '' : $text);

        return Prompt::truncate(trim($text === null ? '' : $text), $max);
    }
}
