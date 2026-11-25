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
use Aifec\Kernel\Router;

/**
 * LA tabla de rutas de la API (/api/v1): de ella salen las rutas que se dan de alta, lo que exige cada una (clave, ficha de
 * carrito, ámbito) y el documento OpenAPI. Lo documentado y lo que existe no pueden diferir porque es la misma lista.
 *
 * Cada fila: name, method, path (relativo a /api/v1), kind (read, cart o checkout), handler, summary, needs (servicio que tiene
 * que estar cargado), query (nombre => descripción), body (nombre => [tipo, descripción]), cache (la respuesta puede guardarse
 * un minuto), scope (ámbito de la clave que exige, o null), cart (exige la ficha X-Cart-Token) y tool (la herramienta del
 * servicio «tools» que atiende la ruta, para la documentación).
 */
final class Routes
{
    public const PREFIX = '/api/v1';

    /**
     * Las rutas. Por defecto, las que se pueden atender ahora (las del carrito solo si el módulo de pedidos está cargado);
     * $everything = true da todas: así se dan de alta, porque al registrar las rutas puede que otros módulos aún no se hayan
     * cargado, y el intermediario contesta 404 a las de un módulo que falta.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function table(App $app, bool $everything = false): array
    {
        $rows = [
            self::row('api.store', 'GET', '/store', 'read', [ReadController::class, 'store'], 'The shop: name, currency, languages, contact, returns, payment methods and where to find everything else', '', [], [],
                ['cache' => true, 'tool' => 'store_info']),
            self::row('api.categories', 'GET', '/categories', 'read', [ReadController::class, 'categories'], 'The product categories with their sub-categories', '', [], [],
                ['cache' => true, 'tool' => 'list_categories']),
            self::row('api.products.list', 'GET', '/products', 'read', [ReadController::class, 'products'], 'List the products, with search, category, stock filter, order and pages', 'catalog', [
                'q' => 'Words to look for', 'category' => 'Only products of this category id', 'page' => 'Page number, from 1',
                'per_page' => 'Products per page, 1 to 48 (24 by default)', 'sort' => 'relevance, newest, price_asc, price_desc, name or best', 'in_stock' => 'true: only products in stock',
            ], [], ['cache' => true]),
            self::row('api.products.show', 'GET', '/products/{id:\d+}', 'read', [ReadController::class, 'product'], 'Everything the shop says about one product', 'catalog', [], [],
                ['cache' => true, 'tool' => 'get_product']),
            self::row('api.products.availability', 'GET', '/products/{id:\d+}/availability', 'read', [ReadController::class, 'availability'], 'Whether a product or variant can be bought in a quantity right now', 'catalog', [
                'variant_id' => 'The variant, for a product with variants', 'qty' => 'Units, 1 to 999 (1 by default)',
            ], [], ['tool' => 'check_availability']),
            self::row('api.search', 'GET', '/search', 'read', [ReadController::class, 'search'], 'Search the catalogue: up to 8 products with the facts that match', 'catalog', [
                'q' => 'What the customer is looking for, in their own words (required)', 'limit' => 'Products, 1 to 8 (5 by default)', 'category' => 'Only products of this category id',
                'max_price_cents' => 'Highest price in cents', 'in_stock' => 'true: only products in stock',
            ], [], ['cache' => true, 'tool' => 'search_products']),
            self::row('api.shipping.quote', 'GET', '/shipping/quote', 'read', [ReadController::class, 'shippingQuote'], 'Delivery options, prices and times for a product to a destination', 'shipping', [
                'country' => 'ISO 3166-1 alpha-2 code', 'postcode' => 'Postcode of the destination', 'product_id' => 'The product to quote (required)',
                'variant_id' => 'The variant', 'qty' => 'Units, 1 to 999 (1 by default)',
            ], [], ['tool' => 'shipping_quote']),

            self::row('api.carts.create', 'POST', '/carts', 'cart', [CartController::class, 'create'], 'Create an empty cart and get its token (the token travels afterwards in the X-Cart-Token header)', 'cart', [], [],
                ['tool' => 'cart_view']),
            self::row('api.carts.show', 'GET', '/carts/current', 'cart', [CartController::class, 'show'], 'The cart with its lines and totals as the shop calculates them', 'cart', [], [],
                ['cart' => true, 'tool' => 'cart_view']),
            self::row('api.carts.items.add', 'POST', '/carts/current/items', 'cart', [CartController::class, 'add'], 'Add units of a product to the cart (the price is always the shop\'s own)', 'cart', [], [
                'product_id' => ['integer', 'The product (required)'], 'variant_id' => ['integer', 'The variant, for a product with variants'], 'qty' => ['integer', 'Units, 1 to 99 (1 by default)'],
            ], ['cart' => true, 'tool' => 'cart_add']),
            self::row('api.carts.items.update', 'PATCH', '/carts/current/items/{key:[A-Za-z0-9_-]{1,80}}', 'cart', [CartController::class, 'update'], 'Change the quantity of a cart line (0 removes it)', 'cart', [], [
                'qty' => ['integer', 'The new quantity, 0 to 99 (required)'],
            ], ['cart' => true, 'tool' => 'cart_update']),
            self::row('api.carts.items.remove', 'DELETE', '/carts/current/items/{key:[A-Za-z0-9_-]{1,80}}', 'cart', [CartController::class, 'remove'], 'Remove a line from the cart', 'cart', [], [],
                ['cart' => true, 'tool' => 'cart_update']),
            self::row('api.carts.checkout_link', 'GET', '/carts/current/checkout-link', 'cart', [CartController::class, 'link'], 'The address that opens this cart in the customer\'s browser and takes them to the checkout', 'cart', [], [],
                ['cart' => true, 'tool' => 'checkout_link']),

            self::row('api.checkout.status', 'POST', '/carts/checkout/status', 'checkout', [CheckoutController::class, 'step'], 'Where the purchase is: what is missing, the cart and what is saved (personal data come masked)', 'checkout', [], [],
                ['cart' => true, 'tool' => 'checkout_status']),
            self::row('api.checkout.customer', 'POST', '/carts/checkout/customer', 'checkout', [CheckoutController::class, 'step'], 'Save the customer\'s contact and address: their own words in text, or separate fields', 'checkout', [], [
                'text' => ['string', 'What the customer wrote about themselves, in any order'], 'email' => ['string', 'Email'], 'first_name' => ['string', 'First name'], 'last_name' => ['string', 'Last name'],
                'company' => ['string', 'Company'], 'vat_id' => ['string', 'Tax ID'], 'phone' => ['string', 'Phone'], 'line1' => ['string', 'Street and number'], 'line2' => ['string', 'Floor, door'],
                'postcode' => ['string', 'Postcode'], 'city' => ['string', 'City'], 'region' => ['string', 'Region code'], 'country' => ['string', 'ISO 3166-1 alpha-2 code'],
                'address_id' => ['integer', 'A saved address of a customer with an account'], 'note' => ['string', 'A note for the order'],
            ], ['cart' => true, 'tool' => 'checkout_set_customer']),
            self::row('api.checkout.shipping_options', 'POST', '/carts/checkout/shipping-options', 'checkout', [CheckoutController::class, 'step'], 'The delivery options of the order with price and delay', 'checkout', [], [],
                ['cart' => true, 'tool' => 'checkout_shipping_options']),
            self::row('api.checkout.shipping', 'POST', '/carts/checkout/shipping', 'checkout', [CheckoutController::class, 'step'], 'Choose the delivery option', 'checkout', [], [
                'carrier_id' => ['integer', 'The id from the shipping options (required)'],
            ], ['cart' => true, 'tool' => 'checkout_set_shipping']),
            self::row('api.checkout.payment_options', 'POST', '/carts/checkout/payment-options', 'checkout', [CheckoutController::class, 'step'], 'The payment methods offered for the order', 'checkout', [], [],
                ['cart' => true, 'tool' => 'checkout_payment_options']),
            self::row('api.checkout.payment', 'POST', '/carts/checkout/payment', 'checkout', [CheckoutController::class, 'step'], 'Choose the payment method', 'checkout', [], [
                'method' => ['string', 'The id from the payment options (required)'],
            ], ['cart' => true, 'tool' => 'checkout_set_payment']),
            self::row('api.checkout.review', 'POST', '/carts/checkout/review', 'checkout', [CheckoutController::class, 'review'], 'The exact order that would be placed, with its total and the confirm_token to place it', 'checkout', [], [],
                ['cart' => true, 'tool' => 'checkout_review']),
            self::row('api.checkout.place', 'POST', '/carts/checkout/place', 'checkout', [CheckoutController::class, 'place'], 'Place the order. Needs the confirm_token of the review, buyer_confirmed true and the code emailed to the buyer (buyer_code) or a key with the orders:create scope', 'checkout', [], [
                'confirm_token' => ['string', 'The confirm_token of the last review (required)'],
                'buyer_confirmed' => ['boolean', 'true: the person has seen the total and said yes (required)'],
                'buyer_code' => ['string', 'The 6-digit code the shop emailed to the buyer after the review. Not needed with a key that has the orders:create scope'],
            ], ['cart' => true, 'optional_scope' => 'orders:create', 'tool' => 'checkout_place']),
            // Comprar con enlaces: los mismos pasos como direcciones GET, para los asistentes que solo saben leer páginas.
            self::row('api.go.guide', 'GET', '/go', 'links', [BrowseController::class, 'guide'], 'Buying with links: how an assistant that can only open addresses buys here, and where it starts', 'checkout', [], []),
            self::row('api.go.step', 'GET', '/go/{step:[a-z-]{3,20}}', 'links', [BrowseController::class, 'go'], 'One step of buying with links: start, add, cart, customer, shipping-options, shipping, payment-options, payment, status, review, code or place. Every answer has the next addresses ready', 'checkout', [
                'cart' => 'The cart, as the step start gives it (every step but start)', 'product_id' => 'add: the product', 'variant_id' => 'add: the variant, for a product with variants', 'qty' => 'add: units',
                'text' => 'customer: what the person wrote about themselves, in any order', 'carrier_id' => 'shipping: the id from shipping-options', 'method' => 'payment: the id from payment-options',
                // Y los campos separados del paso customer, cada uno con su tipo, si es obligatorio y un ejemplo inventado.
                'email' => LinkFields::query()['email'], 'first_name' => LinkFields::query()['first_name'], 'last_name' => LinkFields::query()['last_name'],
                'company' => LinkFields::query()['company'], 'vat_id' => LinkFields::query()['vat_id'], 'phone' => LinkFields::query()['phone'],
                'line1' => LinkFields::query()['line1'], 'line2' => LinkFields::query()['line2'], 'postcode' => LinkFields::query()['postcode'],
                'city' => LinkFields::query()['city'], 'region' => LinkFields::query()['region'], 'country' => LinkFields::query()['country'], 'note' => LinkFields::query()['note'],
                'confirm_token' => 'place: the confirm_token of the review', 'buyer_confirmed' => 'place: true, the person has seen the total and said yes', 'buyer_code' => 'place: the 6-digit code the shop sent to the buyer',
                'digits' => 'code: the digits of that code entered so far, for tools that only open addresses written in a page',
            ], []),
            // Las mismas dos, fuera de /api/v1: hay lectores de páginas que no abren direcciones con /api/ o /v1/.
            self::row('api.links.guide', 'GET', '/buy-with-links', 'links', [BrowseController::class, 'guide'], 'Buying with links: how an assistant that can only open addresses buys here, and where it starts', 'checkout', [], [], ['root' => true]),
            self::row('api.links.path1', 'GET', '/buy-with-links/{step:[a-z-]{3,20}}/{cart:[a-f0-9]{48}}', 'links', [BrowseController::class, 'go'], 'A step of buying with links written as a clean path, with the cart in it', 'checkout', [], [], ['root' => true]),
            self::row('api.links.path2', 'GET', '/buy-with-links/{step:[a-z-]{3,20}}/{cart:[a-f0-9]{48}}/{a:[A-Za-z0-9_.-]{1,80}}', 'links', [BrowseController::class, 'go'], 'A step of buying with links written as a clean path, with the cart and one value', 'checkout', [], [], ['root' => true]),
            self::row('api.links.path3', 'GET', '/buy-with-links/{step:[a-z-]{3,20}}/{cart:[a-f0-9]{48}}/{a:[A-Za-z0-9_.-]{1,80}}/{b:[A-Za-z0-9_.-]{1,80}}', 'links', [BrowseController::class, 'go'], 'A step of buying with links written as a clean path, with the cart and two values', 'checkout', [], [], ['root' => true]),
            // Lo mismo que «add?product_id=…» sin un solo «?»: el lector de Claude pierde a veces los parámetros (17/11/2026).
            self::row('api.links.new', 'GET', '/buy-with-links/new/{a:[0-9]{1,9}}', 'links', [BrowseController::class, 'addNew'], 'Buying with links: puts one unit of this product in a new cart, as a clean path', 'checkout', [], [], ['root' => true]),
            self::row('api.links.new.variant', 'GET', '/buy-with-links/new/{a:[0-9]{1,9}}/{b:[0-9]{1,9}}', 'links', [BrowseController::class, 'addNew'], 'Buying with links: puts one unit of this option of the product in a new cart, as a clean path', 'checkout', [], [], ['root' => true]),
            // Con un trozo al azar al final: la dirección de «volver a comprar» de un carrito viejo no puede ser una que el lector
            // del asistente ya tenga guardada (17/11/2026: la caché de Claude devolvía otra vez el carrito viejo).
            self::row('api.links.new.fresh', 'GET', '/buy-with-links/new/{a:[0-9]{1,9}}/{b:[0-9]{1,9}}/{n:[a-z0-9]{6,20}}', 'links', [BrowseController::class, 'addNew'], 'Buying with links: puts one unit of this product (or option, 0 for none) in a new cart; the last part only makes the address new', 'checkout', [], [], ['root' => true]),
            // Los datos de quien compra se mandan como formulario (POST): en el cuerpo y no en la dirección, que se guarda en historiales y registros.
            self::row('api.links.customer.post', 'POST', '/buy-with-links/{step:customer}', 'links', [BrowseController::class, 'go'], 'Buying with links: saves who buys, sent as a form (POST) so the details never travel in the address', 'checkout', [], [], ['root' => true]),
            self::row('api.links.customer.post.path', 'POST', '/buy-with-links/{step:customer}/{cart:[a-f0-9]{48}}', 'links', [BrowseController::class, 'go'], 'Buying with links: saves who buys in this cart, sent as a form (POST)', 'checkout', [], [], ['root' => true]),
            self::row('api.links.step', 'GET', '/buy-with-links/{step:[a-z-]{3,20}}', 'links', [BrowseController::class, 'go'], 'One step of buying with links: start, add, cart, customer, shipping-options, shipping, payment-options, payment, status, review, code or place. Every answer has the next addresses ready', 'checkout', [], [], ['root' => true]),
        ];

        return array_values(array_filter($rows, static function (array $row) use ($app, $everything): bool {
            return $everything || $row['needs'] === '' || $app->has((string) $row['needs']);
        }));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(App $app, string $name): ?array
    {
        foreach (self::table($app, true) as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        return null;
    }

    /**
     * La fila que atiende una ruta relativa a /api/v1 (por ejemplo «/products/12»), sin mirar el método. Sirve a las peticiones
     * OPTIONS, que no tienen ruta propia.
     *
     * @return array<string,mixed>|null
     */
    public static function match(App $app, string $path): ?array
    {
        $path = rtrim($path, '/');
        foreach (self::table($app, true) as $row) {
            if (preg_match(self::regex((string) $row['path']), $path) === 1) {
                return $row;
            }
        }

        return null;
    }

    /** Da de alta todas las rutas de la tabla. */
    public static function register(Router $router, App $app): void
    {
        foreach (self::table($app, true) as $row) {
            $methods = $row['method'] === 'GET' ? ['GET', 'HEAD'] : [(string) $row['method']];
            $router->add($methods, ($row['root'] ? '' : self::PREFIX) . (string) $row['path'], $row['handler'])->name((string) $row['name'])->middleware('api.gate');
        }
    }

    /** «/carts/current/items/{key:[A-Za-z0-9_-]{1,80}}» → «/carts/current/items/{key}» (la forma de OpenAPI). */
    public static function openapiPath(string $path): string
    {
        return (string) preg_replace('/\{([a-z_]+):(?:[^{}]|\{[^{}]*\})+\}/', '{$1}', $path);
    }

    /**
     * La expresión regular de una ruta de la tabla.
     */
    private static function regex(string $path): string
    {
        $out = '';
        $pos = 0;
        if (preg_match_all('/\{([a-z_]+)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', $path, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($found as $set) {
                $out .= preg_quote(substr($path, $pos, (int) $set[0][1] - $pos), '#');
                $out .= '(?:' . (isset($set[2]) && $set[2][0] !== '' ? $set[2][0] : '[^/]+') . ')';
                $pos = (int) $set[0][1] + strlen($set[0][0]);
            }
        }

        return '#^' . $out . preg_quote(substr($path, $pos), '#') . '$#';
    }

    /**
     * @param array<string,string>                   $query
     * @param array<string,array{0:string,1:string}> $body
     * @param array{0:string,1:string}               $handler
     * @param array<string,mixed>                    $extra
     *
     * @return array<string,mixed>
     */
    private static function row(string $name, string $method, string $path, string $kind, array $handler, string $summary, string $needs, array $query, array $body, array $extra = []): array
    {
        return [
            'name' => $name, 'method' => $method, 'path' => $path, 'kind' => $kind, 'handler' => $handler, 'summary' => $summary, 'needs' => $needs,
            'query' => $query, 'body' => $body, 'cache' => !empty($extra['cache']), 'scope' => isset($extra['scope']) ? (string) $extra['scope'] : null,
            'cart' => !empty($extra['cart']), 'tool' => isset($extra['tool']) ? (string) $extra['tool'] : '',
            // Un ámbito que la ruta admite pero no exige (hacer el pedido: con la clave no hace falta el código del comprador).
            'optional_scope' => isset($extra['optional_scope']) ? (string) $extra['optional_scope'] : null,
            // La ruta cuelga de la raíz del sitio y no de /api/v1 (y no sale en el documento OpenAPI, que es de /api/v1).
            'root' => !empty($extra['root']),
        ];
    }
}
